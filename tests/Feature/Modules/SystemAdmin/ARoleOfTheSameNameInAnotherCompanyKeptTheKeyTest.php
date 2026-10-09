<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * "নিজের চাবি নিজে ফেলবেন না" পাহারা রোলটা নাম ধরে খুঁজত, কোম্পানি না দেখে — পুনঃনিরীক্ষা, ৯ অক্টোবর ২০২৬।
 *
 * ⚠️ দ্বিতীয় কোম্পানিতে একই নামের রোলে ব্যবহারকারী-প্রশাসনের চাবি থাকলে পাহারা ঐটা দেখে
 * "চাবি থাকছে" বলত, অথচ এই কোম্পানির রোলে চাবিটা নেই — প্রশাসক নিজেকে বাইরে আটকে ফেলতেন।
 * ⓘ বিপজ্জনক মানুষটা এখানে: দ্বিতীয় কোম্পানি।
 */
final class ARoleOfTheSameNameInAnotherCompanyKeptTheKeyTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_lock_out_guard_reads_this_companys_role(): void
    {
        $this->seed(DemoSeeder::class);

        $here = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $there = Company::query()->where('code', 'FMART')->firstOrFail();
        $key = Permission::findOrCreate('system_admin.user.manage', 'web');

        // ⓘ অন্য কোম্পানির রোলটা আগে বানানো, তাই তার id ছোট — নাম ধরে `first()` ওটাই ফেরাত
        CompanyContext::forCompany($there->id, fn () => Role::create(['name' => 'viewer', 'guard_name' => 'web'])->givePermissionTo($key));
        CompanyContext::forCompany($here->id, fn () => Role::create(['name' => 'viewer', 'guard_name' => 'web']));
        CompanyContext::forCompany($here->id, fn () => Role::create(['name' => 'admin-keeper', 'guard_name' => 'web'])->givePermissionTo($key));

        $admin = User::factory()->create(['current_company_id' => $here->id, 'is_active' => true]);
        $admin->companies()->attach($here->id, ['is_active' => true]);
        CompanyContext::forCompany($here->id, fn () => $admin->assignRole('admin-keeper'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        CompanyContext::set($here->id, $here->defaultBranch()?->id);
        $this->actingAs($admin->fresh());

        $this->put(route('system_admin.user.update', $admin), [
            'name' => $admin->name, 'email' => $admin->email, 'locale' => 'bn', 'is_active' => '1',
            'roles' => ['viewer'], 'companies' => [$here->id],
            'default_branch' => [$here->id => $here->defaultBranch()?->id],
        ])->assertSessionHasErrors(['roles' => __('system_admin::validation.cannot_drop_your_own_key')]);

        CompanyContext::set($here->id, $here->defaultBranch()?->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertTrue($admin->fresh()->hasRole('admin-keeper'), '⛔ প্রশাসক নিজের চাবি ফেলে দিলেন — অন্য কোম্পানির একই নামের রোল দেখে।');
    }
}
