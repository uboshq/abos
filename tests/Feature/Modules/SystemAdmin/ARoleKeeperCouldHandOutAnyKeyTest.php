<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Support\CompanyContext;
use App\Models\AuditFieldChange;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * রোল সামলানোর চাবি যাঁর, তিনি যেকোনো রোলে যেকোনো চাবি বসাতে পারতেন, আর কোনো দাগ থাকত না — পুনঃনিরীক্ষা, ৯ অক্টোবর ২০২৬।
 *
 * ── ⛔ কী ভুল ছিল ────────────────────────────────────────────────────
 * `RoleController` store/update সোজা `syncPermissions()` ডাকত, আর
 * `permissions.*` কেবল দেখত চাবিটা আছে কি না। ⚠️ অর্থাৎ
 * `system_admin.role.manage` আসলে **সব চাবি**: নিজের রোলে টাকার
 * অনুমোদন বসিয়ে নিজেই সই করা যেত, আর খাতায় কিছুই উঠত না।
 *
 * ⓘ বিপজ্জনক মানুষটা এখানে: রোল সামলাতে পারেন, কিন্তু সুপার অ্যাডমিন নন।
 */
final class ARoleKeeperCouldHandOutAnyKeyTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
    }

    public function test_a_role_keeper_cannot_give_a_key_they_do_not_hold(): void
    {
        $this->actingAs($this->roleKeeper());

        $this->post(route('system_admin.role.store'), [
            'name' => 'চুপি চুপি হিসাব',
            'permissions' => ['sales.invoice.view', 'accounts.count.approve'],
        ])->assertSessionHasErrors('permissions');

        $this->assertNull(Role::query()->where('name', 'চুপি চুপি হিসাব')->first(),
            '⛔ নিজের হাতে নেই এমন চাবিসহ রোলটা তৈরি হয়ে গেল।');
    }

    public function test_a_role_keeper_cannot_widen_their_own_role(): void
    {
        $keeper = $this->roleKeeper();
        $this->actingAs($keeper);
        $role = Role::query()->where('name', 'role-keeper')->firstOrFail();

        $this->put(route('system_admin.role.update', $role), [
            'name' => 'role-keeper',
            'permissions' => ['system_admin.role.manage', 'sales.invoice.view', 'accounts.count.approve'],
        ])->assertSessionHasErrors('permissions');

        $this->assertFalse($role->fresh()->hasPermissionTo('accounts.count.approve'));
    }

    public function test_a_role_keeper_may_give_what_they_hold_and_it_is_written_down(): void
    {
        $this->actingAs($this->roleKeeper());

        $this->post(route('system_admin.role.store'), [
            'name' => 'চালান দেখেন',
            'permissions' => ['sales.invoice.view'],
        ])->assertSessionHasNoErrors();

        $role = Role::query()->where('name', 'চালান দেখেন')->firstOrFail();
        $this->assertTrue($role->hasPermissionTo('sales.invoice.view'));

        $trail = AuditTrail::query()
            ->where('auditable_type', $role::class)
            ->where('auditable_id', $role->id)
            ->where('action', 'role_permissions_changed')
            ->first();

        $this->assertNotNull($trail, '⛔ রোলের চাবি বদল খাতায় ওঠেনি।');
        $this->assertSame((int) $this->company->id, (int) $trail->company_id);
        $this->assertNotNull($trail->user_id, '⛔ কে বদলালেন, তা লেখা নেই।');

        $this->assertSame('sales.invoice.view', AuditFieldChange::query()
            ->where('audit_trail_id', $trail->id)->where('field', 'permissions_added')->value('new_value'));
    }

    public function test_removing_a_key_is_allowed_and_written_down(): void
    {
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);

        $this->post(route('system_admin.role.store'), [
            'name' => 'দুই চাবি',
            'permissions' => ['sales.invoice.view', 'accounts.count.approve'],
        ])->assertSessionHasNoErrors();

        $role = Role::query()->where('name', 'দুই চাবি')->firstOrFail();

        $this->put(route('system_admin.role.update', $role), [
            'name' => 'দুই চাবি',
            'permissions' => ['sales.invoice.view'],
        ])->assertSessionHasNoErrors();

        $this->assertFalse($role->fresh()->hasPermissionTo('accounts.count.approve'));

        $latest = AuditTrail::query()->where('auditable_id', $role->id)
            ->where('action', 'role_permissions_changed')->latest('id')->firstOrFail();

        $this->assertSame('accounts.count.approve', AuditFieldChange::query()
            ->where('audit_trail_id', $latest->id)->where('field', 'permissions_removed')->value('old_value'));
    }

    /** ⛔ মালিকের চাবি সুপার অ্যাডমিনও কোনো রোলে বসাতে পারেন না। */
    public function test_nobody_puts_the_owners_key_on_a_role(): void
    {
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->post(route('system_admin.role.store'), [
            'name' => 'হস্তান্তরকারী',
            'permissions' => ['system_admin.ownership.transfer'],
        ])->assertSessionHasErrors('permissions');

        $this->assertNull(Role::query()->where('name', 'হস্তান্তরকারী')->first());
    }

    /** রোল সামলান, আর চালান দেখেন — এর বেশি কিছু নয়। */
    private function roleKeeper(): User
    {
        $user = User::factory()->create(['current_company_id' => $this->company->id, 'is_active' => true]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);

        CompanyContext::forCompany($this->company->id, function () use ($user): void {
            $role = Role::create(['name' => 'role-keeper', 'guard_name' => 'web']);
            $role->givePermissionTo([
                Permission::findOrCreate('system_admin.role.manage', 'web'),
                Permission::findOrCreate('sales.invoice.view', 'web'),
            ]);
            $user->assignRole($role);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }
}
