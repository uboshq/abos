<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Services\PermissionSyncer;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * "User Admin" চাবি দিয়েই মালিকের দ্বিতীয় তালা খুলে ফেলা যেত — চূড়ান্ত অডিট, ৩০ সেপ্টেম্বর ২০২৬ (⛔২)।
 *
 * ⛔ [[UserController::setTwoStep()]]-এর "বন্ধ" পথে সুপার অ্যাডমিনের পাহারা ছিল না, কোম্পানির যাচাইও না।
 * ⭐ মালিকের শর্ত (৩০ সেপ্টেম্বর: "SURIMPOWER NISCIT KORBE"): সারাই যেন মালিককে না আটকায় — তাই শেষ দাবিটা একই
 * মানুষকে সুপার অ্যাডমিন করে দরজাটা খুলে দেখায়।
 */
final class TheUserAdminCouldUnlockTheOwnersSecondDoorTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $userAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->assertTrue($this->owner->hasRole(PermissionSyncer::SUPER_ADMIN_ROLE));
        $this->owner->forceFill(['two_step_required' => true])->save();

        /* ⓘ User Admin — কেবল ব্যবহারকারী-ব্যবস্থাপনার চাবি, সুপার অ্যাডমিন নন */
        $this->userAdmin = User::query()->where('email', 'sales@abos.test')->firstOrFail();
        $this->assertFalse($this->userAdmin->hasRole(PermissionSyncer::SUPER_ADMIN_ROLE));
        CompanyContext::forCompany($this->company->id,
            fn () => $this->userAdmin->givePermissionTo(Permission::findOrCreate('system_admin.user.manage', 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->userAdmin = $this->userAdmin->fresh();
    }

    public function test_a_user_admin_cannot_switch_off_the_owners_second_door_but_a_super_admin_can(): void
    {
        $this->actingAs($this->userAdmin)
            ->put(route('system_admin.user.two_step.set', $this->owner), ['required' => 0, 'reason' => 'ফোন হারিয়েছে বলে দাবি'])
            ->assertForbidden();

        $this->assertTrue((bool) $this->owner->fresh()->two_step_required, 'User Admin মালিকের দ্বিতীয় তালা খুলে ফেলেছেন।');

        /* ⭐ একই মানুষ, এবার সুপার অ্যাডমিন — দরজা খোলে; মালিকের মতো ক্ষমতাধর কেউ আটকান না */
        CompanyContext::forCompany($this->company->id, fn () => $this->userAdmin->assignRole(
            Role::query()->where('company_id', $this->company->id)->where('name', PermissionSyncer::SUPER_ADMIN_ROLE)->firstOrFail()));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($this->userAdmin->fresh())
            ->put(route('system_admin.user.two_step.set', $this->owner), ['required' => 0, 'reason' => 'মালিকের নিজের অনুরোধে'])
            ->assertRedirect();

        $this->assertFalse((bool) $this->owner->fresh()->two_step_required);
    }

    public function test_a_user_admin_can_still_switch_off_an_ordinary_persons_second_door(): void
    {
        $clerk = User::query()->where('email', 'accounts@abos.test')->firstOrFail();
        $this->assertFalse($clerk->hasRole(PermissionSyncer::SUPER_ADMIN_ROLE));
        $clerk->forceFill(['two_step_required' => true])->save();

        $this->actingAs($this->userAdmin)
            ->put(route('system_admin.user.two_step.set', $clerk), ['required' => 0, 'reason' => 'ফোন হারিয়ে গেছে'])
            ->assertRedirect();

        $this->assertFalse((bool) $clerk->fresh()->two_step_required, 'সারাই বেশি বন্ধ করেছে — সাধারণ কর্মীর তালাও আটকে গেছে।');
    }

    public function test_nobody_reaches_a_person_of_another_company(): void
    {
        $other = Company::query()->where('id', '!=', $this->company->id)->firstOrFail();
        $stranger = User::query()->create([
            'name' => 'Stranger', 'email' => 'stranger-'.Str::random(6).'@abos.test',
            'password' => bcrypt('secret-for-a-test-9'), 'locale' => 'bn', 'is_active' => true,
        ]);
        $stranger->forceFill(['two_step_required' => true])->save();
        $stranger->companies()->attach($other->id);

        /* ⛔ মালিক (সুপার অ্যাডমিন) হলেও — অন্য কোম্পানির ঠিকানায় ঐ মানুষের অস্তিত্বই নেই */
        $this->actingAs($this->owner->fresh())
            ->put(route('system_admin.user.two_step.set', $stranger), ['required' => 0, 'reason' => 'অন্য কোম্পানির মানুষ'])
            ->assertNotFound();
        $this->actingAs($this->owner->fresh())
            ->delete(route('system_admin.user.two_step.reset', $stranger), ['reason' => 'অন্য কোম্পানির মানুষ'])
            ->assertNotFound();

        $this->assertTrue((bool) $stranger->fresh()->two_step_required);
    }
}
