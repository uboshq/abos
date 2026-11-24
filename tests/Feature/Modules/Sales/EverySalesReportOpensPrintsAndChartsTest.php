<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Report\ReportChart;
use App\Core\Engines\Report\ReportChoices;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Engines\Report\ReportExport;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Reports\CoreReports;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\Location;
use App\Modules\Sales\Http\Controllers\SalesReportController;
use App\Modules\Sales\Reports\AreaSalesReports;
use App\Modules\Sales\Reports\MoneyInReports;
use App\Modules\Sales\Services\DirectSaleService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ নতুন বিক্রয় রিপোর্টের প্রতিটা পাতা খোলে, প্রতিটার "প্রিন্ট ভিউ" (A4 কাগজ) আঁকা হয়, আর পাশ ফেরানো টেবিল, গ্রাফ, প্যানেলের বাছাই আর
 * টাকা আসার সারাংশ রিপোর্টের নিজের অঙ্ক বলে — মালিক, ২৩ নভেম্বর ২০২৬ (সমন্বয়কের মারফত: *"প্রতিটা রিপোর্টের ছাপার ছাঁচ … প্রিন্ট ভিউ
 * নতুন ট্যাবে … পরীক্ষা: প্রতিটা প্রিন্ট ভিউ আঁকা হয়"*)।
 *
 * ⓘ রিপোর্টের তালিকা হাতে লেখা নয় — কন্ট্রোলারের দরজার তালিকা (`SLUGS`) থেকে, এই কাজের রিপোর্টগুলো; নতুন রিপোর্ট যোগ হলে নিজেই আসে।
 */
final class EverySalesReportOpensPrintsAndChartsTest extends TestCase
{
    use RefreshDatabase;

    /** এই কাজের রিপোর্টের চাবির শুরু — দরজার তালিকা থেকে এগুলোই বাছা হয় */
    private const OURS = ['sales.money_in_', 'sales.area_sales_', 'sales.branch_sales', 'sales.target_sales_', 'sales.trend_',
        'sales.product_abc', 'sales.customer_abc', 'sales.customers_', 'sales.collection_efficiency_', 'sales.scheme_cost',
        'sales.discount_by_person', 'sales.price_realisation', 'sales.return_rate_', 'sales.salesperson_productivity', 'sales.due_ageing_',
        'sales.daily_summary'];

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        // ⓘ অন্তত একটা বিক্রি — খালি রিপোর্ট টেবিলই আঁকে না, তাই ছাপার কাগজেরও কিছু থাকে না
        app(DirectSaleService::class)->complete(
            ['customer_id' => (int) Customer::query()->where('name_en', 'Rahim Traders')->value('id'),
                'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'), 'own_transport' => '1'],
            [['product_id' => Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->value('id'), 'qty' => '6', 'rate' => '10', 'free_qty' => '0']],
        );
    }

    public function test_every_new_report_page_opens_and_its_print_view_is_an_inline_pdf(): void
    {
        $slugs = $this->ourSlugs();
        $this->assertGreaterThan(40, count($slugs), 'প্রস্তুতি: দরজার তালিকায় এই কাজের রিপোর্টগুলো পাওয়া যায়নি।');
        $today = now()->toDateString();

        foreach ($slugs as $slug) {
            $page = route('sales.report.show', ['slug' => $slug, 'from' => now()->startOfMonth()->toDateString(), 'to' => $today]);

            $this->get($page)->assertOk();

            $paper = $this->get($page.'&export=print');
            $paper->assertOk();
            $this->assertSame('application/pdf', $paper->headers->get('content-type'), "⛔ {$slug}: প্রিন্ট ভিউ PDF নয়।");
            $this->assertStringStartsWith('inline;', (string) $paper->headers->get('content-disposition'), "⛔ {$slug}: প্রিন্ট ভিউ নামানো হচ্ছে, খুলছে না।");
        }

        foreach (['inflow', 'inflow-day', 'inflow-branch', 'inflow-channel'] as $slug) {
            $this->get(route('accounts.report.show', ['slug' => $slug]))->assertOk();
            $this->get(route('accounts.report.show', ['slug' => $slug, 'export' => 'print']))->assertOk();
        }

        $this->get(route('sales.product_target.index'))->assertOk();
    }

    public function test_branch_sales_is_turned_on_its_side_on_screen_and_on_paper(): void
    {
        $result = app(ReportEngine::class)->run(AreaSalesReports::BRANCH, ['from' => now()->toDateString(), 'to' => now()->toDateString()], byBranch: true);
        $this->assertTrue($result->report->transpose);
        $this->assertNotEmpty($result->rows, 'প্রস্তুতি: শাখার কোনো সারি নেই।');

        $columns = $result->columnsFor(auth()->user());
        $table = ReportExport::transposed($result, $columns);

        // ⭐ মাথা: "হিসাব", তারপর প্রতিটা শাখা, শেষে "সব"; সারি: প্রথম কলাম বাদে প্রতিটা হিসাব
        $this->assertSame(__('core.report.figure'), $table['head'][0]);
        $this->assertSame(__('core.report.all'), end($table['head']));
        $this->assertSame(array_map(fn (array $r) => (string) $r['place'], $result->rows), array_slice($table['head'], 1, -1), '⛔ কলামের মাথায় শাখাগুলো নেই।');
        $this->assertCount(count($columns) - 1, $table['rows'], '⛔ প্রতিটা হিসাব এক সারিতে আসেনি।');

        $net = collect($table['rows'])->first(fn (array $line) => $line['__column']->key === 'net_sales');
        $this->assertNotNull($net);
        $this->assertSame((string) $result->totals['net_sales'], (string) end($net['__cells']), '⛔ "সব"-এর ঘরে নিট বিক্রির মোট নেই।');

        $page = $this->get(route('sales.report.show', ['slug' => AreaSalesReports::BRANCH_SLUG]))->assertOk();
        $page->assertSee('data-transposed', false);
    }

    public function test_the_charts_choices_and_summary_come_from_the_reports_own_figures(): void
    {
        $area = Location::query()->create(['company_id' => $this->company->id, 'branch_id' => $this->company->defaultBranch()?->id,
            'code' => 'CH-A', 'name_en' => 'Chart Area', 'level' => Location::AREA, 'is_active' => true]);
        Customer::query()->where('name_en', 'Rahim Traders')->update(['location_id' => $area->id]);
        $today = now()->toDateString();

        $result = app(ReportEngine::class)->run(AreaSalesReports::levelKey(Location::AREA), ['from' => $today, 'to' => $today], byBranch: true);
        $chart = ReportChart::for($result, $result->columnsFor(auth()->user()));

        $this->assertNotNull($chart, '⛔ এলাকা ধরে বিক্রয়ের গ্রাফ নেই।');
        $this->assertSame('pair', $chart['type']);
        $this->assertCount(min(12, count($result->rows)), $chart['labels']);
        $this->assertSame(
            array_map(fn (array $r) => bcadd((string) $r['net_sales'], '0', 4), array_slice($result->rows, 0, 12)),
            $chart['series'][0]['values'], '⛔ গ্রাফের নিট বিক্রি টেবিলের সারির অঙ্ক নয়।');

        // ⭐ প্যানেলের বাছাই — টাকার মাধ্যম, ঘোষণা থেকে
        $kinds = ReportChoices::facets(app(ReportEngine::class)->get(MoneyInReports::DAY));
        $this->assertCount(1, $kinds, '⛔ টাকা আসার রিপোর্টে "টাকার মাধ্যম"-এর বাছাই প্যানেলে নেই।');
        $this->assertSame([], ReportChoices::facets(app(ReportEngine::class)->get(AreaSalesReports::BRANCH)), '⛔ বাছাই নেই এমন রিপোর্টে প্যানেলে বাড়তি ঘর।');
        $this->assertCount(1, ReportChoices::facets(app(ReportEngine::class)->get(CoreReports::INFLOW_DAY)));

        // ⭐ সারাংশ — মোট আর তিন মাধ্যম রিপোর্টের নিজের মোট থেকে; দিনে গড় সময়ের দিন ধরে
        $summary = MoneyInReports::summaryOf(['total' => '900', 'cash' => '500', 'bank' => '300', 'mfs' => '100', 'receipts' => '4'],
            ['from' => '2026-11-01', 'to' => '2026-11-03']);
        $this->assertSame('900', $summary['value']);
        $this->assertSame(['500', '300', '100', '300.00'], array_column($summary['lines'], 'value'), '⛔ সারাংশের মাধ্যম বা দিনের গড় ভুল।');
    }

    /** @return list<string> */
    private function ourSlugs(): array
    {
        $slugs = (new \ReflectionClassConstant(SalesReportController::class, 'SLUGS'))->getValue();

        return array_values(array_keys(array_filter($slugs, fn (string $key) => collect(self::OURS)->contains(fn (string $start) => str_starts_with($key, $start)))));
    }
}
