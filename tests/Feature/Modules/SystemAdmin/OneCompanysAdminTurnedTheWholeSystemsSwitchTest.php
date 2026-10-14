<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Services\PermissionSyncer;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * এক কোম্পানির সুপার অ্যাডমিন গোটা ব্যবস্থার সুইচ বদলাতেন — সিস্টেম-অ্যাডমিন লাইন-ধরে নিরীক্ষা, ১৪ অক্টোবর ২০২৬।
 *
 * ⛔ `scope => product` সুইচ একটাই সারি, সব কোম্পানির জন্য — যেমন `hr.shared_across_companies`, যা কোম্পানির মাঝের HR-এর
 * দেয়াল তুলে দেয়। কিন্তু [[SettingsService::mayChange()]] দেখত কেবল চলতি কোম্পানির ভূমিকা: এক কোম্পানির সুপার অ্যাডমিনই সব
 * কোম্পানির দেয়াল খুলতে পারতেন। ⭐ এখন সব কোম্পানির মালিক ([[WholeDatabaseAccess]]) — কোম্পানি বানানো আর ব্যাকআপের একই নিয়ম।
 */
final class OneCompanysAdminTurnedTheWholeSystemsSwitchTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_product_wide_switch_needs_the_owner_of_every_company(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $local = User::query()->where('email', 'accounts@abos.test')->firstOrFail();
        CompanyContext::forCompany((int) $company->id, fn () => $local->assignRole(PermissionSyncer::SUPER_ADMIN_ROLE));
        $local = $local->fresh();
        $this->assertTrue($local->roles->contains('name', PermissionSyncer::SUPER_ADMIN_ROLE), 'পূর্বশর্ত: এই কোম্পানির সুপার অ্যাডমিন নন।');

        $settings = app(SettingsService::class);

        $this->assertFalse($settings->mayChange('hr.shared_across_companies', $local),
            'এক কোম্পানির সুপার অ্যাডমিন সব কোম্পানির HR-এর দেয়াল খুলতে পারেন।');
        $this->assertTrue($settings->mayChange('hr.shared_across_companies', User::query()->where('email', 'owner@abos.test')->firstOrFail()),
            'সব কোম্পানির মালিকও গোটা ব্যবস্থার সুইচ বদলাতে পারেন না।');
    }
}
