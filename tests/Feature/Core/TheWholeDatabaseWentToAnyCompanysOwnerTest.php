<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Security\WholeDatabaseAccess;
use App\Core\Services\PermissionSyncer;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * এক কোম্পানির মালিক গোটা ডাটাবেস — সব কোম্পানির খাতা — নামিয়ে নিতে পারতেন (চূড়ান্ত অডিট, ৩০ সেপ্টেম্বর ২০২৬, ⛔১)।
 *
 * ⓘ একই মানুষ দুইবার: কেবল এক কোম্পানির super_admin → ৪০৩; সব চালু কোম্পানির super_admin → পাহারা পেরোয় (নাম না
 * মিললে তখন ৪০৪, অর্থাৎ ৪০৩ আর আসে না)। ⭐ মালিকের শর্ত ("SURIMPOWER"): সবখানে super_admin যিনি, তিনি পারেন।
 */
final class TheWholeDatabaseWentToAnyCompanysOwnerTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;

    private Company $b;

    private User $person;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $companies = Company::query()->where('is_active', true)->orderBy('id')->get();
        $this->assertGreaterThanOrEqual(2, $companies->count(), 'দুইটা চালু কোম্পানি ছাড়া এই দাবি কিছুই মাপে না।');

        /* ⓘ কেবল দুইটা চালু রাখা — বাকিগুলো বন্ধ, যাতে "সব চালু কোম্পানি" মানে এই দুইটা */
        [$this->a, $this->b] = [$companies[0], $companies[1]];
        Company::query()->whereNotIn('id', [$this->a->id, $this->b->id])->update(['is_active' => false]);

        $this->person = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->onlySuperAdminOf($this->a);

        CompanyContext::set($this->a->id, $this->a->defaultBranch()?->id);
    }

    public function test_the_owner_of_one_company_cannot_take_the_whole_database_but_the_owner_of_all_can(): void
    {
        $this->actingAs($this->person->fresh())
            ->get(route('backup.download', 'no-such-file.sql.gz'))
            ->assertForbidden();

        $this->grantSuperAdmin($this->b);

        /* ⭐ একই মানুষ, এবার দুই কোম্পানিরই মালিক — পাহারা পেরোয়; ফাইল নেই বলে ৪০৪ */
        $this->actingAs($this->person->fresh())
            ->get(route('backup.download', 'no-such-file.sql.gz'))
            ->assertNotFound();
    }

    public function test_a_closed_company_does_not_count(): void
    {
        $access = app(WholeDatabaseAccess::class);

        $this->assertFalse($access->allows($this->person->fresh()));

        /* ⓘ B বন্ধ হলে সব চালু কোম্পানি মানে কেবল A — আর তিনি A-র মালিক */
        $this->b->forceFill(['is_active' => false])->save();
        $this->assertTrue($access->allows($this->person->fresh()));

        $this->assertFalse($access->allows(null));
    }

    private function onlySuperAdminOf(Company $keep): void
    {
        $role = fn (Company $c) => Role::query()->where('company_id', $c->id)->where('name', PermissionSyncer::SUPER_ADMIN_ROLE)->first();

        foreach (Company::query()->where('id', '!=', $keep->id)->get() as $company) {
            $r = $role($company);

            if ($r !== null) {
                CompanyContext::forCompany($company->id, fn () => $this->person->unsetRelation('roles')->removeRole($r));
            }
        }

        $this->grantSuperAdmin($keep);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function grantSuperAdmin(Company $company): void
    {
        $r = Role::query()->where('company_id', $company->id)->where('name', PermissionSyncer::SUPER_ADMIN_ROLE)->firstOrFail();
        CompanyContext::forCompany($company->id, fn () => $this->person->unsetRelation('roles')->assignRole($r));
        $this->person->companies()->syncWithoutDetaching([$company->id]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
