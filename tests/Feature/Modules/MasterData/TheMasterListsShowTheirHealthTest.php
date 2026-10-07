<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\MasterData;

use App\Core\Dashboard\DashboardRegistry;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\MasterData\Dashboard\MasterDataDashboard;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * মাস্টার ডেটার "ডেটার মান" — মালিকের ড্যাশবোর্ড নকশা §১২ ([[MasterHealth]], [[DashboardRegistry::health()]])।
 *
 * ⓘ দাবি: সুইচ বন্ধে কিছু যোগ হয় না; ফোনহীন একজন গ্রাহক অসম্পূর্ণ এক বাড়ায়; একই ফোনের দুজন "একই" দুই বাড়ায়;
 * বন্ধ গ্রাহক শতাংশে গোনা হয় না; চাবি ছাড়া মানুষ কোনো তালিকার স্বাস্থ্য পান না।
 */
final class TheMasterListsShowTheirHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_blank_phone_and_a_repeated_phone_are_counted_and_an_inactive_row_is_not(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);

        config(['abos.dashboards_v2' => false]);
        $this->assertNull($this->stat(__('master_data::dashboard.quality_score')), '⛔ পুরনো ড্যাশবোর্ডেও ডেটার মান।');

        config(['abos.dashboards_v2' => true]);
        $this->assertNotNull($this->stat(__('master_data::dashboard.quality_score')), 'নতুন ড্যাশবোর্ডে ডেটার মান নেই।');
        $before = $this->customers();

        $template = Customer::query()->where('is_active', true)->whereNotNull('location_id')->firstOrFail();
        // ⓘ $over আগে — PHP-র `+` বাঁ দিকের চাবি রাখে
        $make = fn (array $over) => Customer::query()->create($over + [
            'company_id' => $company->id, 'branch_id' => $template->branch_id, 'location_id' => $template->location_id,
            'code' => 'HL-'.random_int(10000, 99999), 'name_en' => 'Health '.random_int(1, 99999), 'name_bn' => 'স্বাস্থ্য',
            'phone' => '01999'.random_int(100000, 999999), 'address_en' => 'Road 1', 'is_active' => true,
        ]);

        $make(['phone' => '']);
        $after = $this->customers();
        $this->assertSame((int) $before['missing'] + 1, (int) $after['missing'], '⛔ ফোনহীন গ্রাহক অসম্পূর্ণে ওঠেনি।');
        $this->assertSame((int) $before['active'] + 1, (int) $after['active']);

        $make(['phone' => '01888777666']);
        $make(['phone' => '01888777666']);
        $twice = $this->customers();
        $this->assertSame((int) $after['same'] + 2, (int) $twice['same'], '⛔ একই ফোনের দুজন "একই"-তে ওঠেননি।');
        $this->assertSame((int) $after['missing'], (int) $twice['missing'], '⛔ ভরা সারি অসম্পূর্ণে উঠেছে।');

        $make(['phone' => '', 'is_active' => false]);
        $off = $this->customers();
        $this->assertSame((int) $twice['missing'], (int) $off['missing'], '⛔ বন্ধ গ্রাহক অসম্পূর্ণে গোনা হয়েছে।');
        $this->assertSame((int) $twice['inactive'] + 1, (int) $off['inactive']);
        $this->assertSame(
            intdiv(100 * (int) $off['complete'], (int) $off['active']).'%',
            $this->stat(__('customer::menu.customers'))?->value,
            '⛔ পর্দার শতাংশ চালু সারি ধরে নয়।',
        );

        // ⛔ চাবি ছাড়া মানুষ — কোনো তালিকার স্বাস্থ্য নয়
        $nobody = User::factory()->create();
        $this->assertSame([], app(DashboardRegistry::class)->health($nobody));
    }

    /** @return array<string, string> */
    private function customers(): array
    {
        $row = collect(app(DashboardRegistry::class)->health(auth()->user()))
            ->firstWhere('label', __('customer::menu.customers'));
        $this->assertNotNull($row, 'গ্রাহক তালিকার স্বাস্থ্য নেই।');

        return $row->parts;
    }

    private function stat(string $label): ?\App\Core\Engines\Dashboard\Stat
    {
        return collect(MasterDataDashboard::dashboard()->stats)->firstWhere('label', $label);
    }
}
