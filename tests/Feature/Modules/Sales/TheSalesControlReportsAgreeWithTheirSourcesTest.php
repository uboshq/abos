<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Engines\Report\ReportResult;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\Location;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Reports\AreaSalesReports;
use App\Modules\Sales\Reports\DailySummaryReports;
use App\Modules\Sales\Reports\DueAgeingReports;
use App\Modules\Sales\Reports\MoneyInReports;
use App\Modules\Sales\Reports\ProductInsightReports;
use App\Modules\Sales\Reports\ReturnRateReports;
use App\Modules\Sales\Reports\SalesForceReports;
use App\Modules\Sales\Reports\SalespersonReports;
use App\Modules\Sales\Reports\SalesPlace;
use App\Modules\Sales\Reports\SchemeCostReports;
use App\Modules\Sales\Services\DirectSaleService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ খরচ, দাম, ফেরত, কর্মীর কাজ, বাকির বয়স আর দৈনিক সারাংশের রিপোর্ট (মালিকের তালিকার ১০–১৫, ২৩ নভেম্বর ২০২৬) নিজের উৎসের অঙ্ক বলে।
 *
 * ⓘ এই রিপোর্টগুলো নতুন কোনো সংজ্ঞা আনে না — পণ্যভিত্তিক মুনাফা, বিক্রয়কর্মী ধরে বিক্রি, গ্রাহকের বকেয়ার বয়স, এলাকা ধরে বিক্রয় আর টাকা আসার
 * একই সারির নতুন যোগ। তাই প্রতিটার মোট উৎসের মোটের সমান, আর হারের ঘর মোট থেকে গোনা।
 */
final class TheSalesControlReportsAgreeWithTheirSourcesTest extends TestCase
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

        $this->sell('8');
        $this->receive('600');
    }

    public function test_return_rate_and_price_realisation_stand_on_the_product_and_area_rows(): void
    {
        $byProduct = $this->run('sales.by_product')->totals;
        $rate = $this->run(ReturnRateReports::PRODUCT)->totals;

        $this->assertSame($this->money($byProduct['revenue']), $this->money($rate['net_sales']), '⛔ পণ্য ধরে ফেরতের হারের নিট বিক্রি পণ্যভিত্তিক মুনাফার নিট বিক্রির সমান নয়।');
        $this->assertSame($this->money($byProduct['returned']), $this->money($rate['returned']), '⛔ পণ্য ধরে ফেরতের অঙ্ক পণ্যভিত্তিক মুনাফার ফেরতের সমান নয়।');
        $this->assertRate($rate, 'return_rate', 'returned', 'sales', 'পণ্য ধরে ফেরতের হার');

        $area = $this->run(AreaSalesReports::levelKey(Location::AREA))->totals;

        foreach (SalesPlace::LEVELS as $level) {
            $totals = $this->run(ReturnRateReports::levelKey($level))->totals;
            $this->assertSame($this->money($area['sales']), $this->money($totals['sales']), "⛔ {$level} ধরে ফেরতের হারের বিক্রি এলাকা ধরে বিক্রয়ের বিক্রির সমান নয়।");
            $this->assertSame($this->money($area['returns']), $this->money($totals['returned']), "⛔ {$level} ধরে ফেরত এলাকা ধরে বিক্রয়ের ফেরতের সমান নয়।");
            $this->assertRate($totals, 'return_rate', 'returned', 'sales', "{$level} ধরে ফেরতের হার");
        }

        $price = $this->run(ProductInsightReports::PRICE);
        $this->assertNotEmpty($price->rows, '⛔ বিক্রি হয়েছে, অথচ দামের প্রাপ্তিতে কোনো পণ্য নেই।');
        $this->assertRate($price->totals, 'realisation', 'realised', 'at_list', 'দামের প্রাপ্তি');
        $this->assertSame($this->money(bcsub((string) $price->totals['at_list'], (string) $price->totals['realised'], 4)), $this->money($price->totals['lost']), '⛔ "কম এসেছে" তালিকার দাম − আসল দাম নয়।');
    }

    public function test_salesperson_productivity_carries_the_sales_and_the_money_of_its_sources(): void
    {
        $work = $this->run(SalesForceReports::KEY)->totals;

        $this->assertSame($this->money($this->run(SalespersonReports::KEY)->totals['net_sales']), $this->money($work['net_sales']),
            '⛔ কর্মীর কাজের হিসাবের নিট বিক্রি বিক্রয়কর্মী ধরে বিক্রির সর্বমোটের সমান নয়।');
        $this->assertSame($this->money($this->run(MoneyInReports::PERSON)->totals['total']), $this->money($work['collected']),
            '⛔ কর্মীর কাজের হিসাবের আদায় টাকা আসার (বিক্রয়কর্মী ধরে) মোটের সমান নয়।');
        $this->assertSame(
            Customer::query()->where('is_active', true)->count(), (int) $work['held'],
            '⛔ দায়িত্বে থাকা গ্রাহকের মোট সচল গ্রাহকের সংখ্যার সমান নয় — কেউ দুইবার গোনা হয়েছে বা বাদ পড়েছে।');
        $this->assertGreaterThanOrEqual((int) $work['idle_customers'], (int) $work['held'], '⛔ ঘুমন্ত গ্রাহক দায়িত্বে থাকা গ্রাহকের চেয়ে বেশি।');
    }

    public function test_due_ageing_by_area_is_the_customer_ageing_report_added_up(): void
    {
        $source = $this->run('customer.ageing')->totals;

        foreach (SalesPlace::LEVELS as $level) {
            $totals = $this->run(DueAgeingReports::levelKey($level))->totals;

            $this->assertSame($this->money($source['outstanding']), $this->money($totals['outstanding']), "⛔ {$level} ধরে বাকির মোট গ্রাহকের বকেয়ার বয়সের রিপোর্টের মোটের সমান নয়।");
            $this->assertSame(
                $this->money($totals['outstanding']),
                $this->money(array_sum(array_map(fn (string $b) => (float) $totals[$b], ['bucket_current', 'bucket_30', 'bucket_60', 'bucket_90']))),
                "⛔ {$level}: চার বালতির যোগ মোট বাকির সমান নয়।");
            $this->assertSame($this->money(bcadd((string) $totals['bucket_60'], (string) $totals['bucket_90'], 4)), $this->money($totals['over_60']), "⛔ {$level}: \"৬০ দিনের বেশি\" শেষ দুই বালতির যোগ নয়।");
            $this->assertRate($totals, 'over_60_share', 'over_60', 'outstanding', "{$level} ধরে ৬০ দিনের বেশির ভাগ");
        }
    }

    public function test_the_daily_summary_is_that_days_row_of_sales_and_money_in(): void
    {
        $day = $this->run(DailySummaryReports::KEY)->totals;
        $area = $this->run(AreaSalesReports::levelKey(Location::AREA))->totals;
        $money = $this->run(MoneyInReports::DAY)->totals;

        $this->assertSame($this->money($area['net_sales']), $this->money($day['net_sales']), '⛔ দৈনিক সারাংশের নিট বিক্রি সেই দিনের এলাকা ধরে বিক্রয়ের সমান নয়।');
        $this->assertSame($this->money($money['total']), $this->money($day['money_in']), '⛔ দৈনিক সারাংশের আসা টাকা সেই দিনের "টাকা আসা"র সমান নয়।');
        $this->assertSame($this->money($day['money_in']), $this->money((float) $day['cash'] + (float) $day['bank'] + (float) $day['mfs']), '⛔ নগদ + ব্যাংক + মোবাইল ব্যাংকিং মোট আসা টাকার সমান নয়।');
        $this->assertTrue(bccomp((string) $day['month_net'], (string) $day['net_sales'], 2) >= 0 || now()->day === 1, '⛔ মাসের নিট বিক্রি আজকের নিট বিক্রির চেয়ে কম।');
        $this->assertTrue(bccomp((string) $day['month_money'], (string) $day['money_in'], 2) >= 0, '⛔ মাসে আসা টাকা আজকে আসা টাকার চেয়ে কম।');
    }

    public function test_scheme_and_discount_cost_add_up(): void
    {
        $schemes = $this->run(SchemeCostReports::SCHEMES)->totals;
        $parts = (float) ($schemes['price_cut'] ?? 0) + (float) ($schemes['free_goods'] ?? 0) + (float) ($schemes['other_benefit'] ?? 0);

        $this->assertSame($this->money($schemes['cost'] ?? 0), $this->money($parts), '⛔ স্কিমের মোট খরচ তিন রকম সুবিধার যোগ নয়।');
        $this->assertSame($this->money($schemes['cost'] ?? 0), $this->money($this->run(SchemeCostReports::BILLS)->totals['worth'] ?? 0), '⛔ স্কিমের মোট খরচ অফার খাটা বিলের তালিকার যোগের সমান নয়।');

        $discounts = $this->run(SchemeCostReports::DISCOUNTS)->totals;
        $this->assertSame($this->money((float) $discounts['line_discount'] + (float) $discounts['bill_discount']), $this->money($discounts['discount_total']), '⛔ মোট ছাড় লাইনের ছাড় + মাথার ছাড় নয়।');
        $this->assertSame($this->money((float) $discounts['gross'] - (float) $discounts['discount_total']), $this->money($discounts['after_discount']), '⛔ ছাড়ের আগের দাম − ছাড় = ছাড়ের পরে নয়।');
        $this->assertSame($this->money($this->run(SalespersonReports::KEY)->totals['sales']), $this->money($discounts['after_discount']), '⛔ ছাড়ের পরের দাম বিক্রয়কর্মী ধরে বিক্রির "বিক্রি"র সমান নয়।');
    }

    /** হারের ঘর মোটে মোট ÷ মোট — হর শূন্য হলে ঘর খালি */
    private function assertRate(array $totals, string $rate, string $top, string $bottom, string $what): void
    {
        if (bccomp((string) ($totals[$bottom] ?? '0'), '0', 4) <= 0) {
            $this->assertArrayNotHasKey($rate, $totals, "⛔ {$what}: হর শূন্য, তবু হার দেখাচ্ছে।");

            return;
        }

        $this->assertSame(bcdiv(bcmul((string) $totals[$top], '100', 6), (string) $totals[$bottom], 2), bcadd((string) $totals[$rate], '0', 2), "⛔ {$what}: মোটের হার মোট ÷ মোট নয়।");
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    private function run(string $key): ReportResult
    {
        return app(ReportEngine::class)->run($key, ['from' => $this->today, 'to' => $this->today], 1, 500);
    }

    private function sell(string $qty): SalesInvoice
    {
        return app(DirectSaleService::class)->complete(
            ['customer_id' => $this->customer, 'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'), 'own_transport' => '1'],
            [['product_id' => Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->value('id'), 'qty' => $qty, 'rate' => '10', 'free_qty' => '0']],
        )['invoice']->fresh();
    }

    /** গ্রাহকের কাছ থেকে একটা রসিদ — ব্যাংকে */
    private function receive(string $amount): void
    {
        app(StandardChart::class)->install();
        $bank = Account::query()->create(['company_id' => $this->company->id, 'code' => '1102-CTL', 'name_en' => 'Control Bank', 'name_bn' => 'যাচাইয়ের ব্যাংক',
            'parent_id' => StandardChart::find(StandardChart::BANK)->id, 'type' => Account::ASSET, 'nature' => Account::DEBIT, 'money_kind' => Account::BANK]);
        $receivable = StandardChart::find(StandardChart::RECEIVABLE);
        $receivable = $receivable->is_group ? Account::query()->postable()->whereKey($receivable->selfAndDescendants()->pluck('id'))->orderBy('code')->firstOrFail() : $receivable;

        app(PostingEngine::class)->post(sourceType: 'receipt_voucher', sourceId: random_int(1, 4_000_000), trxDate: $this->today, lines: [
            ['account_id' => $bank->id, 'debit' => $amount],
            ['account_id' => $receivable->id, 'credit' => $amount, 'party_type' => 'customer', 'party_id' => $this->customer],
        ]);
    }
}
