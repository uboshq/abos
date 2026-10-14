<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Executive;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Executive\Reports\ExecutiveReports;
use App\Modules\Inventory\Models\Product;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⛔ ক্রেতা ধরে লাভ বিলের মাথার ছাড় আর রাউন্ডিংকে বিক্রি ধরত — ec, Executive লাইন-নিরীক্ষা, ১৪ অক্টোবর ২০২৬।
 *
 * ⓘ ১০,০০০ টাকার মাল, বিলে ৫০০ ছাড়, ০.৪০ রাউন্ডিং, ভাড়া ১০০, একটা সারিতে ২০০ ভ্যাট → খাতার বিক্রি ৯,৫০০.৪০, খরচ ৬,৮০০।
 * মালিকের রিপোর্ট আগে সারি যোগ করত (`amount − tax`) আর দেখাত ১০,০০০ — লাভ ৫০০ বেশি। এখন বিক্রয়ের নিজের
 * রিপোর্টের (sales.by_customer) সঙ্গে হুবহু মেলে।
 */
final class ProfitByCustomerCountsTheBillDiscountTest extends TestCase
{
    use RefreshDatabase;

    private const NET = 9500.40;

    private const COST = 6800.0;

    private int $branch;

    private int $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->branch = (int) Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'MMS')->value('id');
        CompanyContext::set($company->id, $this->branch);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);

        $this->customer = (int) Customer::query()->withoutGlobalScopes()->where('company_id', $company->id)->orderBy('id')->value('id');
        $products = Product::query()->withoutGlobalScopes()->where('company_id', $company->id)->orderBy('id')->limit(2)->pluck('id')->all();

        // ⓘ ৬,০০০ + ৪,০০০ (ভ্যাট ২০০ বাইরে) − ছাড় ৫০০ + রাউন্ডিং ০.৪০ + ভাড়া ১০০ = ৯,৮০০.৪০
        $invoice = DB::table('sal_invoices')->insertGetId([
            'company_id' => $company->id, 'branch_id' => $this->branch, 'document_no' => 'ZX-1', 'customer_id' => $this->customer,
            'trx_date' => '2019-02-10', 'status' => DocumentStatus::CONFIRMED, 'subtotal' => 10000, 'discount' => 0,
            'bill_discount' => 500, 'rounding_amount' => 0.40, 'tax' => 200, 'freight_charge' => 100, 'total' => 9800.40,
            'cost_of_goods' => self::COST, 'created_by' => $owner->id,
        ]);

        DB::table('sal_invoice_lines')->insert([
            ['sales_invoice_id' => $invoice, 'product_id' => $products[0], 'qty' => 10, 'rate' => 600, 'discount' => 0,
                'tax' => 0, 'amount' => 6000, 'unit_cost' => 400, 'line_no' => 1],
            ['sales_invoice_id' => $invoice, 'product_id' => $products[1], 'qty' => 4, 'rate' => 1000, 'discount' => 0,
                'tax' => 200, 'amount' => 4200, 'unit_cost' => 700, 'line_no' => 2],
        ]);
    }

    public function test_the_owners_profit_by_customer_is_the_bills_net_sale(): void
    {
        // ⚠️ লাইভের মতো কড়া MariaDB — GROUP BY যা বাছে তা-ই দলে
        DB::statement("SET SESSION sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");

        $row = $this->rows(ExecutiveReports::PROFIT_BY_CUSTOMER)->firstWhere('customer_id', $this->customer);
        $this->assertNotNull($row, 'প্রস্তুতিটাই ভুল — ক্রেতার সারি নেই।');

        $this->assertEqualsWithDelta(self::NET, (float) $row['net_sales'], 0.01, '⛔ ক্রেতা ধরে লাভে বিলের ছাড় বিক্রি হয়ে ঢুকল।');
        $this->assertEqualsWithDelta(self::COST, (float) $row['cost'], 0.01);
        $this->assertEqualsWithDelta(self::NET - self::COST, (float) $row['gross_profit'], 0.01, '⛔ লাভে বিলের ছাড় বাদ যায়নি।');

        // ⭐ বিক্রয়ের নিজের রিপোর্টের সঙ্গে হুবহু
        $sales = $this->rows('sales.by_customer')->firstWhere('customer_id', $this->customer);
        $this->assertNotNull($sales);
        $this->assertEqualsWithDelta((float) $sales['gross_profit'], (float) $row['gross_profit'], 0.01,
            '⛔ মালিকের কেন্দ্র আর বিক্রয়ের রিপোর্টে একই ক্রেতার লাভ আলাদা।');
    }

    /** @return Collection<int, array<string, mixed>> */
    private function rows(string $key): Collection
    {
        return collect(app(ReportEngine::class)->run($key, ['from' => '2019-02-01', 'to' => '2019-02-28', 'branch_id' => $this->branch], 1, 500)->rows)
            ->map(fn ($r) => is_array($r) ? $r : (array) $r);
    }
}
