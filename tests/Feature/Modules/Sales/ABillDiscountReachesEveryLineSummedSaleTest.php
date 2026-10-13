<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Services\RouteMetrics;
use App\Modules\Sales\Services\SalesTargetService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⛔ বিলের মাথার ছাড় সারি-যোগের বিক্রিতে পৌঁছাত না — ১৩ অক্টোবর ২০২৬, Sales লাইন-ধরা নিরীক্ষা।
 *
 * ⓘ ১০,০০০ টাকার মাল, বিলে ৫০০ ছাড়, ০.৪০ রাউন্ডিং, ভাড়া ১০০, একটা সারিতে ২০০ ভ্যাট → খাতার বিক্রি ৯,৫০০.৪০। পণ্য, ব্র্যান্ড,
 * মার্জিন, টার্গেট আর রুটের অর্জন সারি যোগ করত (`amount − tax`) আর দেখাত ১০,০০০.০০; গ্রাহক ধরে লাভে ভাড়াও ঢুকত।
 */
final class ABillDiscountReachesEveryLineSummedSaleTest extends TestCase
{
    use RefreshDatabase;

    private const NET = 9500.40;

    private const COST = 6800.0;

    private int $branch;

    private int $customer;

    private int $owner;

    private int $route;

    /** @var list<int> */
    private array $products;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->branch = (int) Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'MMS')->value('id');
        CompanyContext::set($company->id, $this->branch);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->owner = (int) $owner->id;
        $this->actingAs($owner);

        $this->customer = (int) Customer::query()->withoutGlobalScopes()->where('company_id', $company->id)->orderBy('id')->value('id');
        $this->products = Product::query()->withoutGlobalScopes()->where('company_id', $company->id)->orderBy('id')->limit(2)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->route = (int) DB::table('mdm_locations')->orderBy('id')->value('id');
        DB::table('customers')->where('id', $this->customer)->update(['location_id' => $this->route]);

        // ⓘ ৬,০০০ + ৪,০০০ (ভ্যাট ২০০ বাইরে) − ছাড় ৫০০ + রাউন্ডিং ০.৪০ + ভাড়া ১০০ = ৯,৮০০.৪০
        $invoice = DB::table('sal_invoices')->insertGetId([
            'company_id' => $company->id, 'branch_id' => $this->branch, 'document_no' => 'ZD-1', 'customer_id' => $this->customer,
            'trx_date' => '2019-02-10', 'status' => DocumentStatus::CONFIRMED, 'subtotal' => 10000, 'discount' => 0,
            'bill_discount' => 500, 'rounding_amount' => 0.40, 'tax' => 200, 'freight_charge' => 100, 'total' => 9800.40,
            'cost_of_goods' => self::COST, 'created_by' => $this->owner,
        ]);

        DB::table('sal_invoice_lines')->insert([
            ['sales_invoice_id' => $invoice, 'product_id' => $this->products[0], 'qty' => 10, 'rate' => 600, 'discount' => 0,
                'tax' => 0, 'amount' => 6000, 'unit_cost' => 400, 'line_no' => 1],
            ['sales_invoice_id' => $invoice, 'product_id' => $this->products[1], 'qty' => 4, 'rate' => 1000, 'discount' => 0,
                'tax' => 200, 'amount' => 4200, 'unit_cost' => 700, 'line_no' => 2],
        ]);
    }

    public function test_product_brand_and_customer_reports_agree_with_the_bills_net_sale(): void
    {
        $products = $this->rows('sales.by_product');
        $this->assertEqualsWithDelta(self::NET, $products->sum('revenue'), 0.01, '⛔ পণ্য ধরে বিক্রিতে বিলের ছাড় নেই');
        // ⓘ ভাগ ভ্যাটের আগের দামে: ৬,০০০ − ৩০০ + ০.২৪ আর ৪,০০০ − ২০০ + ০.১৬
        $this->assertEqualsWithDelta(5700.24, (float) $products->firstWhere('product_id', $this->products[0])['revenue'], 0.01);
        $this->assertEqualsWithDelta(3800.16, (float) $products->firstWhere('product_id', $this->products[1])['revenue'], 0.01);

        $this->assertEqualsWithDelta(self::NET, $this->rows('sales.by_brand')->sum('revenue'), 0.01, '⛔ ব্র্যান্ড ধরে বিক্রিতে বিলের ছাড় নেই');

        $profit = self::NET - self::COST;
        $this->assertEqualsWithDelta($profit, $products->sum('gross_profit'), 0.01);
        $this->assertEqualsWithDelta($profit, $this->rows('sales.by_brand')->sum('gross_profit'), 0.01);
        $this->assertEqualsWithDelta($profit, (float) $this->rows('sales.by_customer')->firstWhere('customer_id', $this->customer)['gross_profit'], 0.01,
            '⛔ গ্রাহক ধরে লাভে বিলের ভাড়া ঢুকল');
    }

    public function test_the_margin_report_measures_each_line_after_the_bill_discount(): void
    {
        $rows = $this->rows('sales.margin');

        $this->assertEqualsWithDelta(self::NET, $rows->sum('net'), 0.01, '⛔ মার্জিনের সারিতে বিলের ছাড় নেই');
    }

    public function test_the_targets_count_the_bills_net_sale(): void
    {
        $from = Carbon::parse('2019-02-01');

        $byUser = app(SalesTargetService::class)->achievedByUser($from, $from->copy()->endOfMonth());
        $this->assertEqualsWithDelta(self::NET, (float) ($byUser[$this->owner] ?? 0), 0.01, '⛔ টার্গেটের অর্জনে বিলের ছাড় নেই');

        $byRoute = app(RouteMetrics::class)->targetsFor([$this->route], '2019-02-01');
        $this->assertEqualsWithDelta(self::NET, (float) $byRoute[$this->route]['achieved'], 0.01, '⛔ রুটের অর্জনে বিলের ছাড় নেই');
    }

    /** @return \Illuminate\Support\Collection<int, array<string, mixed>> */
    private function rows(string $key): \Illuminate\Support\Collection
    {
        return collect(app(ReportEngine::class)->run($key, ['from' => '2019-02-01', 'to' => '2019-02-28', 'branch_id' => $this->branch], 1, 500)->rows)
            ->map(fn ($r) => is_array($r) ? $r : (array) $r);
    }
}
