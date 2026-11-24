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
use App\Modules\Sales\Reports\CustomerInsightReports;
use App\Modules\Sales\Reports\ProductInsightReports;
use App\Modules\Sales\Reports\SalesPlace;
use App\Modules\Sales\Reports\SalesTrendReports;
use App\Modules\Sales\Services\DirectSaleService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⛔ আন্তর্জাতিক মানের বিশ্লেষণের রিপোর্টগুলো (লক্ষ্য, ধারা, ABC, নতুন/ঘুমন্ত/হারানো, আদায়ের দক্ষতা) একই অঙ্ক বলে — মালিক, ২৩ নভেম্বর ২০২৬।
 *
 * ⓘ এগুলো একই গ্রাহক-সারি আর একই পণ্য-সারির নানা রকম যোগ; তাই এক রিপোর্টের নিট বিক্রি আরেকটার সমান না হলে কোথাও দ্বিতীয় গণনা ঢুকেছে।
 * হার আর শ্রেণির ঘর মোট থেকে গোনা হয় — সারির % যোগ বা গড় করে নয়।
 */
final class TheSalesAnalysisReportsAgreeWithEachOtherTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private int $customer;

    private string $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        $this->customer = (int) Customer::query()->where('name_en', 'Rahim Traders')->value('id');
        $this->today = now()->toDateString();
    }

    public function test_target_abc_efficiency_and_trend_carry_the_net_sales_of_the_area_report(): void
    {
        $area = Location::query()->create(['company_id' => $this->company->id, 'branch_id' => $this->company->defaultBranch()?->id,
            'code' => 'AN-A', 'name_en' => 'AN Area', 'level' => Location::AREA, 'is_active' => true]);
        Customer::query()->whereKey($this->customer)->update(['location_id' => $area->id]);
        // ⓘ মডেল দিয়ে — `public_id` আর কোম্পানি মডেল নিজেই বসায়
        \App\Modules\Sales\Models\CustomerTarget::query()->updateOrCreate(
            ['company_id' => $this->company->id, 'customer_id' => $this->customer, 'month' => now()->startOfMonth()->toDateString()],
            ['amount' => '1000', 'closes_on' => now()->endOfMonth()->toDateString()]);

        $this->sell('9');

        $net = $this->total(AreaSalesReports::levelKey(Location::AREA), 'net_sales');
        $this->assertTrue(bccomp($net, '0', 2) > 0, 'প্রস্তুতি: বিক্রিই নেই।');

        foreach ([CustomerInsightReports::targetKey(Location::AREA), CustomerInsightReports::targetKey(SalesPlace::CUSTOMER),
            CustomerInsightReports::efficiencyKey(Location::AREA), CustomerInsightReports::EFFICIENCY_BRANCH,
            CustomerInsightReports::ABC, ProductInsightReports::ABC, SalesTrendReports::MONTHLY, SalesTrendReports::QUARTERLY] as $key) {
            $this->assertSame($net, $this->total($key, 'net_sales'), "⛔ {$key}: নিট বিক্রি এলাকা ধরে বিক্রয়ের মোটের সমান নয় — দ্বিতীয় গণনা।");
        }

        // ⭐ লক্ষ্য — গ্রাহকের সারিতে তাঁর লক্ষ্য, অর্জন = নিট ÷ লক্ষ্য; মোটে মোট ÷ মোট
        $target = $this->report(CustomerInsightReports::targetKey(SalesPlace::CUSTOMER));
        $mine = collect($target->rows)->first(fn (array $r) => (int) $r['place_id'] === $this->customer);
        $this->assertNotNull($mine, '⛔ লক্ষ্য আছে এমন গ্রাহক লক্ষ্যের রিপোর্টে নেই।');
        $this->assertSame('1000.00', bcadd((string) $mine['target'], '0', 2), '⛔ গ্রাহকের সারিতে তাঁর মাসিক লক্ষ্য ওঠেনি।');
        $this->assertSame(bcdiv(bcmul((string) $mine['net_sales'], '100', 6), '1000', 2), bcadd((string) $mine['achieved'], '0', 2), '⛔ অর্জন % নিট ÷ লক্ষ্য নয়।');
        $this->assertSame(
            bcdiv(bcmul((string) $target->totals['net_sales'], '100', 6), (string) $target->totals['target'], 2),
            bcadd((string) $target->totals['achieved'], '0', 2), '⛔ মোটের অর্জন % মোট ÷ মোট নয়।');

        // ⭐ আদায়ের দক্ষতা — আদায়যোগ্য = শুরুর বাকি + নিট বিক্রি; হার = আদায় ÷ আদায়যোগ্য, মোটেও
        $eff = $this->report(CustomerInsightReports::efficiencyKey(Location::AREA))->totals;
        $this->assertSame(bcadd((string) $eff['opening'], (string) $eff['net_sales'], 2), bcadd((string) $eff['collectible'], '0', 2), '⛔ আদায়যোগ্য টাকা শুরুর বাকি + নিট বিক্রি নয়।');
        $this->assertSame($this->total(AreaSalesReports::levelKey(Location::AREA), 'due'), bcadd((string) $eff['due'], '0', 2), '⛔ শেষ বাকি এলাকা ধরে বিক্রয়ের বাকির সমান নয়।');
        if (bccomp((string) $eff['collectible'], '0', 2) > 0) {
            $this->assertSame(bcdiv(bcmul((string) $eff['collected'], '100', 6), (string) $eff['collectible'], 2), bcadd((string) $eff['efficiency'], '0', 2), '⛔ মোটের আদায়ের হার মোট ÷ মোট নয়।');
        }

        // ⭐ ABC — বড় থেকে ছোট, জমা অংশ ১০০-তে শেষ, প্রথম সারি A
        foreach ([CustomerInsightReports::ABC, ProductInsightReports::ABC] as $key) {
            $rows = collect($this->report($key)->rows);
            $this->assertSame($rows->pluck('net_sales')->map(fn ($v) => (float) $v)->sortDesc()->values()->all(), $rows->pluck('net_sales')->map(fn ($v) => (float) $v)->values()->all(), "⛔ {$key}: বড় থেকে ছোট সাজানো নয়।");
            $this->assertEqualsWithDelta(100.0, (float) $rows->last()['cumulative'], 0.01, "⛔ {$key}: জমা অংশ ১০০%-এ শেষ হয়নি।");
            $this->assertEqualsWithDelta(100.0, $rows->sum(fn (array $r) => (float) $r['share']), 0.05 * max(1, $rows->count()), "⛔ {$key}: অংশগুলোর যোগ ১০০% নয়।");
            $this->assertSame('A', $rows->first()['class'], "⛔ {$key}: সবচেয়ে বড় সারি A শ্রেণিতে নেই।");
            $this->assertEmpty($rows->pluck('class')->diff(['A', 'B', 'C'])->all());
        }
    }

    public function test_a_first_bill_makes_a_new_customer_and_an_old_last_bill_makes_a_sleeping_then_a_lost_one(): void
    {
        $fresh = (int) Customer::query()->create(['code' => 'AN-NEW', 'name_en' => 'AN New Dealer', 'is_active' => true])->id;
        $this->customer = $fresh;
        $invoice = $this->sell('3');

        $in = fn (string $key): bool => collect($this->report($key)->rows)->contains(fn (array $r) => (int) $r['source_id'] === $fresh);

        $this->assertTrue($in(CustomerInsightReports::NEW), '⛔ আজ প্রথম বিল হওয়া গ্রাহক "নতুন"-এ নেই।');
        foreach (CustomerInsightReports::IDLE as $days) {
            $this->assertFalse($in(CustomerInsightReports::idleKey($days)), "⛔ আজই কেনা গ্রাহক {$days} দিনের ঘুমন্তে।");
        }

        // শেষ বিল ৪৫ দিন আগের — ৩০-এর বেশি, ৬০-এর কম
        DB::table('sal_invoices')->where('company_id', $this->company->id)->where('id', $invoice->id)->update(['trx_date' => now()->subDays(45)->toDateString()]);
        $this->assertFalse($in(CustomerInsightReports::NEW), '⛔ ৪৫ দিন আগে প্রথম বিল — তবু এ সময়ের "নতুন"।');
        $this->assertTrue($in(CustomerInsightReports::idleKey(30)), '⛔ ৪৫ দিন কেনেননি — ৩০ দিনের বেশি তালিকায় নেই।');
        $this->assertFalse($in(CustomerInsightReports::idleKey(60)), '⛔ ৪৫ দিন কেনেননি — তবু ৬০ দিনের বেশি তালিকায়।');

        // শেষ বিল ১২০ দিন আগের — হারানো, আগের ধাপগুলোতে নয়
        DB::table('sal_invoices')->where('company_id', $this->company->id)->where('id', $invoice->id)->update(['trx_date' => now()->subDays(120)->toDateString()]);
        $this->assertTrue($in(CustomerInsightReports::idleKey(90)), '⛔ ১২০ দিন কেনেননি — হারানো গ্রাহকের তালিকায় নেই।');
        $this->assertFalse($in(CustomerInsightReports::idleKey(30)), '⛔ হারানো গ্রাহক ৩০ দিনের তালিকাতেও — এক গ্রাহক দুই ধাপে।');
        $this->assertFalse($in(CustomerInsightReports::idleKey(60)), '⛔ হারানো গ্রাহক ৬০ দিনের তালিকাতেও — এক গ্রাহক দুই ধাপে।');
    }

    public function test_the_change_against_last_year_is_counted_from_the_two_totals(): void
    {
        $first = $this->sell('4');
        $this->sell('10');
        // একটা বিল এক বছর আগে — গত বছরের একই সময়ের বিক্রি
        DB::table('sal_invoices')->where('company_id', $this->company->id)->where('id', $first->id)->update(['trx_date' => now()->subYear()->toDateString()]);

        foreach ([SalesTrendReports::MONTHLY => 'ly_net', AreaSalesReports::BRANCH => 'ly_net'] as $key => $was) {
            $totals = $this->report($key)->totals;
            $this->assertTrue(bccomp((string) $totals[$was], '0', 2) > 0, "প্রস্তুতি ({$key}): গত বছরের বিক্রিই নেই।");
            $this->assertSame(
                bcdiv(bcmul(bcsub((string) $totals['net_sales'], (string) $totals[$was], 6), '100', 6), (string) $totals[$was], 2),
                bcadd((string) $totals[$was.'_change'], '0', 2),
                "⛔ {$key}: মোটের বাড়া-কমার হার (এখন − তখন) ÷ তখন নয়।");
        }
    }

    private function report(string $key): \App\Core\Engines\Report\ReportResult
    {
        return app(ReportEngine::class)->run($key, ['from' => $this->today, 'to' => $this->today], 1, 500);
    }

    private function total(string $key, string $column): string
    {
        return bcadd((string) ($this->report($key)->totals[$column] ?? '0'), '0', 2);
    }

    private function sell(string $qty): SalesInvoice
    {
        return app(DirectSaleService::class)->complete(
            ['customer_id' => $this->customer, 'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'), 'own_transport' => '1'],
            [['product_id' => Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->value('id'), 'qty' => $qty, 'rate' => '10', 'free_qty' => '0']],
        )['invoice']->fresh();
    }
}
