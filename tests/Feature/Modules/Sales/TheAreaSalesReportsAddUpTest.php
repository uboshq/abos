<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\Location;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Reports\AreaSalesReports;
use App\Modules\Sales\Reports\SalespersonReports;
use App\Modules\Sales\Reports\SalesPlace;
use App\Modules\Sales\Services\DirectSaleService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ এলাকা ধরে বিক্রয়ের প্রতিটা স্তর আর শাখা ধরে বিক্রয় একই মোট বলে, আর বিক্রিটা ঠিক জায়গার সারিতে ওঠে — মালিক, ২৩ নভেম্বর ২০২৬।
 *
 * ⓘ এরিয়া › টেরিটরি › পয়েন্ট › গ্রাহক একই গ্রাহক-সারির ([[AreaSalesReports::rows()]]) চার রকম যোগ; শাখার রিপোর্টও তাই। এক স্তরে কিছু
 * হারালে বা দুইবার এলে মোট আলাদা হত। নিট বিক্রি বিক্রয়কর্মী ধরে বিক্রির রিপোর্টের সর্বমোটের সমান — দুই সংজ্ঞা (বিল ধরে আর লাইন ধরে) এক
 * অঙ্ক দেয়। ডেমোর আগের সারি বাদ দিতে আগে-পরের তফাত ধরা হয়।
 */
final class TheAreaSalesReportsAddUpTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private int $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        $this->customer = (int) Customer::query()->where('name_en', 'Rahim Traders')->value('id');
    }

    public function test_every_level_and_the_branch_report_say_the_same_total_and_the_sale_sits_under_its_place(): void
    {
        $branch = $this->company->defaultBranch()?->id;
        $area = Location::query()->create(['company_id' => $this->company->id, 'branch_id' => $branch, 'code' => 'AS-A', 'name_en' => 'AS Area', 'level' => Location::AREA, 'is_active' => true]);
        $territory = Location::query()->create(['company_id' => $this->company->id, 'branch_id' => $branch, 'parent_id' => $area->id, 'code' => 'AS-T', 'name_en' => 'AS Territory', 'level' => Location::TERRITORY, 'is_active' => true]);
        $point = Location::query()->create(['company_id' => $this->company->id, 'branch_id' => $branch, 'parent_id' => $territory->id, 'code' => 'AS-P', 'name_en' => 'AS Point', 'level' => Location::POINT, 'is_active' => true]);
        Customer::query()->whereKey($this->customer)->update(['location_id' => $point->id]);

        $today = now()->toDateString();
        $keys = [...array_map(AreaSalesReports::levelKey(...), SalesPlace::LEVELS), AreaSalesReports::BRANCH];
        $before = $this->totals($keys, $today);
        $beforePeople = (string) app(ReportEngine::class)->run(SalespersonReports::KEY, ['from' => $today, 'to' => $today], byBranch: true)->totals['net_sales'];

        $invoice = $this->sell('7');

        $after = $this->totals($keys, $today);
        $afterPeople = (string) app(ReportEngine::class)->run(SalespersonReports::KEY, ['from' => $today, 'to' => $today], byBranch: true)->totals['net_sales'];
        $sold = bcsub($afterPeople, $beforePeople, 2);

        $this->assertTrue(bccomp($sold, '0', 2) > 0, 'প্রস্তুতি: বিক্রিটাই হয়নি।');
        $this->assertSame((int) $this->customer, (int) $invoice->customer_id);

        foreach ($keys as $key) {
            $this->assertSame($sold, bcsub($after[$key]['net_sales'], $before[$key]['net_sales'], 2),
                "⛔ {$key}: নিট বিক্রি বিক্রয়কর্মী ধরে বিক্রির সর্বমোটের সমান বাড়েনি — এই স্তরে কিছু হারিয়েছে বা দুইবার এসেছে।");
            $this->assertSame($after[$keys[0]]['due'], $after[$key]['due'], "⛔ {$key}: বাকির মোট এরিয়ার রিপোর্টের মোটের সমান নয়।");
            $this->assertSame($after[$keys[0]]['collected'], $after[$key]['collected'], "⛔ {$key}: আদায়ের মোট এরিয়ার রিপোর্টের মোটের সমান নয়।");
        }

        // ⭐ বিক্রিটা নিজের এরিয়া, টেরিটরি আর পয়েন্টের সারিতে
        foreach ([Location::AREA => $area->id, Location::TERRITORY => $territory->id, Location::POINT => $point->id, SalesPlace::CUSTOMER => $this->customer] as $level => $place) {
            $row = collect(app(ReportEngine::class)->run(AreaSalesReports::levelKey($level), ['from' => $today, 'to' => $today], 1, 500)->rows)
                ->first(fn (array $r) => (int) ($r['place_id'] ?? 0) === (int) $place);

            $this->assertNotNull($row, "⛔ {$level}: গ্রাহকের জায়গার সারিই নেই।");
            $this->assertTrue(bccomp((string) $row['sales'], $sold, 2) >= 0, "⛔ {$level}: বিক্রিটা তার জায়গার সারিতে ওঠেনি।");
        }

        // ⭐ জায়গা বেছে নিলে কেবল তার নিচের গ্রাহক
        $under = app(ReportEngine::class)->run(AreaSalesReports::levelKey(SalesPlace::CUSTOMER), ['from' => $today, 'to' => $today, 'location_id' => $area->id], 1, 500);
        $this->assertSame([(int) $this->customer], collect($under->rows)->pluck('place_id')->map(fn ($id) => (int) $id)->all(),
            '⛔ এরিয়া বেছে নিলে তার বাইরের গ্রাহক এল, বা নিজের গ্রাহক এল না।');
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, array{net_sales: string, due: string, collected: string}>
     */
    private function totals(array $keys, string $day): array
    {
        $out = [];

        foreach ($keys as $key) {
            $totals = app(ReportEngine::class)->run($key, ['from' => $day, 'to' => $day], 1, 500)->totals;
            $out[$key] = ['net_sales' => bcadd((string) ($totals['net_sales'] ?? '0'), '0', 2), 'due' => bcadd((string) ($totals['due'] ?? '0'), '0', 2),
                'collected' => bcadd((string) ($totals['collected'] ?? '0'), '0', 2)];
        }

        return $out;
    }

    private function sell(string $qty): SalesInvoice
    {
        return app(DirectSaleService::class)->complete(
            ['customer_id' => $this->customer, 'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'), 'own_transport' => '1'],
            [['product_id' => Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->value('id'), 'qty' => $qty, 'rate' => '10', 'free_qty' => '0']],
        )['invoice']->fresh();
    }
}
