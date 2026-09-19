<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Services\PermissionSyncer;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ব্যবহারকারী-প্রশাসক মালিকের চাবি নিতে পারতেন।
 *
 * ── ⛔ নিরীক্ষার ফলাফল ৩.৪ — SystemAdmin-এ রেকর্ড-স্তরের পলিসি নেই ──────
 * ১৯ সেপ্টেম্বর ২০২৬-এ মেপে দুইটা দরজা পাওয়া গেল:
 *   ১. "User Admin" মালিকের পাতা খুলে ইমেইল আর পাসওয়ার্ড বদলাতে পারতেন —
 *      তারপর মালিক হয়ে ঢোকা
 *   ২. নিজেকে যেকোনো ভূমিকা দিতে পারতেন — module.php বলে তিনি *"ক্ষমতার
 *      ছক বানান না"*, অথচ ছকের যেকোনো ঘর নিজের নামে বসত
 *
 * ⭐ [[UserPolicy]]: মালিকের খাতা কেবল মালিক বদলান; কেউ নিজের চেয়ে বেশি
 * ক্ষমতা কাউকে দিতে পারেন না।
 */
final class TheUserAdminCouldTakeTheOwnersKeyTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Branch $branch;

    private User $owner;

    private User $admin;

    private User $clerk;

    private Role $accountant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['code' => 'UAK', 'name_en' => 'Key Co']);
        CompanyContext::set($this->company->id);

        $this->branch = Branch::query()->create([
            'company_id' => $this->company->id, 'code' => 'HQ', 'name_en' => 'Head office', 'is_active' => true,
        ]);

        foreach (['system_admin.user.manage', 'accounts.voucher.create', 'accounts.voucher.post'] as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $this->owner = $this->person('owner@uak.test');
        $this->admin = $this->person('admin@uak.test');
        $this->clerk = $this->person('clerk@uak.test');

        CompanyContext::forCompany($this->company->id, function () {
            Role::findOrCreate(PermissionSyncer::SUPER_ADMIN_ROLE)
                ->givePermissionTo(['system_admin.user.manage', 'accounts.voucher.create', 'accounts.voucher.post']);
            Role::findOrCreate('user_admin')->givePermissionTo('system_admin.user.manage');
            $this->accountant = Role::findOrCreate('accountant')
                ->givePermissionTo(['accounts.voucher.create', 'accounts.voucher.post']);
            Role::findOrCreate('nobody');

            $this->owner->assignRole(PermissionSyncer::SUPER_ADMIN_ROLE);
            $this->admin->assignRole('user_admin');
            $this->clerk->assignRole('nobody');
        });
    }

    private function person(string $email): User
    {
        $user = User::factory()->create(['email' => $email, 'is_active' => true]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);
        $user->switchCompany($this->company->id);

        return $user;
    }

    /** @param  list<string>  $roles */
    private function form(User $user, array $roles, array $overrides = []): array
    {
        return array_merge([
            'name' => $user->name ?? 'New person',
            'email' => $user->email ?? 'new@uak.test',
            'locale' => 'bn',
            'is_active' => '1',
            'roles' => $roles,
            'companies' => [$this->company->id],
            'default_branch' => [$this->company->id => $this->branch->id],
        ], $overrides);
    }

    /** ⛔ দরজা ১ — মালিকের পাতা খোলা আর তাঁর ইমেইল-পাসওয়ার্ড বদলানো */
    public function test_the_user_admin_cannot_open_or_change_the_owners_account(): void
    {
        $this->actingAs($this->admin)
            ->get(route('system_admin.user.edit', $this->owner))
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->put(route('system_admin.user.update', $this->owner), $this->form($this->owner,
                [PermissionSyncer::SUPER_ADMIN_ROLE],
                ['email' => 'mine-now@uak.test', 'password' => 'Taken-over-99']))
            ->assertForbidden();

        $this->assertSame('owner@uak.test', $this->owner->fresh()?->email,
            'মালিকের ইমেইল বদলে গেছে — পাসওয়ার্ড ফেরানোর চিঠিটা এখন অন্য কারও কাছে যাবে।');
    }

    /** ⭐ সাধারণ মানুষের খাতা — আগের মতোই */
    public function test_the_user_admin_still_looks_after_ordinary_people(): void
    {
        $this->assertTrue($this->admin->can('update', $this->clerk));
        $this->assertTrue($this->owner->can('update', $this->clerk));
        $this->assertTrue($this->owner->can('update', $this->owner));
    }

    /** ⛔ দরজা ২ — নিজের চেয়ে বেশি ক্ষমতার ভূমিকা দেওয়া, নিজেকেও */
    public function test_nobody_hands_out_more_power_than_they_hold(): void
    {
        $this->actingAs($this->admin)
            ->put(route('system_admin.user.update', $this->clerk), $this->form($this->clerk, ['accountant']))
            ->assertSessionHasErrors('roles');

        $this->assertFalse($this->clerk->fresh()?->hasRole('accountant'));

        $this->actingAs($this->admin)
            ->put(route('system_admin.user.update', $this->admin), $this->form($this->admin, ['user_admin', 'accountant']))
            ->assertSessionHasErrors('roles');

        $this->assertFalse($this->admin->fresh()?->hasRole('accountant'),
            'ব্যবহারকারী-প্রশাসক নিজেকে হিসাবরক্ষকের ক্ষমতা দিয়ে ফেলেছেন।');

        $this->actingAs($this->admin)
            ->post(route('system_admin.user.store'), $this->form(new User, ['accountant'], [
                'name' => 'Proxy', 'email' => 'proxy@uak.test', 'password' => 'Proxy-secret-99',
            ]))
            ->assertSessionHasErrors('roles');
    }

    /** ⭐ নিজের ভিতরের ক্ষমতা দেওয়া যায়; যা আগে থেকেই আছে তা রাখা যায় */
    public function test_what_is_within_reach_or_already_there_passes(): void
    {
        $userAdmin = Role::findByName('user_admin');

        $this->assertTrue($this->admin->can('grantRole', [$this->clerk, $userAdmin]));
        $this->assertFalse($this->admin->can('grantRole', [$this->clerk, $this->accountant]));

        CompanyContext::forCompany($this->company->id, fn () => $this->clerk->assignRole('accountant'));

        $this->assertTrue($this->admin->can('grantRole', [$this->clerk->fresh(), $this->accountant]),
            'যে ভূমিকা আগে থেকেই আছে, সেটা রাখাও আটকে যাচ্ছে — তাহলে প্রশাসক কারও নামটাও বদলাতে পারতেন না।');

        $this->assertTrue($this->owner->can('grantRole', [$this->clerk, $this->accountant]),
            'মালিকেরও সীমা বসে গেছে — ছকটা তিনিই বানান।');
    }
}
