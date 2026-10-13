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
use App\Modules\Sales\Reports\SalespersonReports;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⛔ বিক্রয়কর্মী ধরে বিক্রি বিলের মাথার ছাড় আর রাউন্ডিংকে কর্মীর বিক্রি ধরত — ec, Executive লাইন-নিরীক্ষা, ১৪ অক্টোবর ২০২৬।
 *
 * ⓘ রিপোর্টটা মালিকের বিশ্লেষণ পাতার "বিক্রয়কর্মী" কার্ডও পড়ে। ১০,০০০ টাকার মাল, বিলে ৫০০ ছাড়, ০.৪০ রাউন্ডিং, ভাড়া ১০০,
 * একটা সারিতে ২০০ ভ্যাট → খাতার বিক্রি ৯,৫০০.৪০; আগে রিপোর্ট দেখাত ১০,০০০।
 */
final class TheSalespeopleCardCountsTheBillDiscountTest extends TestCase
{
    use RefreshDatabase;

    private const NET = 9500.40;

    private int $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->branch = (int) Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'MMS')->value('id');
        CompanyContext::set($company->id, $this->branch);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);

        $customer = (int) Customer::query()->withoutGlobalScopes()->where('company_id', $company->id)->orderBy('id')->value('id');
        $products = Product::query()->withoutGlobalScopes()->where('company_id', $company->id)->orderBy('id')->limit(2)->pluck('id')->all();

        // ⓘ ৬,০০০ + ৪,০০০ (ভ্যাট ২০০ বাইরে) − ছাড় ৫০০ + রাউন্ডিং ০.৪০ + ভাড়া ১০০ = ৯,৮০০.৪০
        $invoice = DB::table('sal_invoices')->insertGetId([
            'company_id' => $company->id, 'branch_id' => $this->branch, 'document_no' => 'ZS-1', 'customer_id' => $customer,
            'trx_date' => '2019-02-10', 'status' => DocumentStatus::CONFIRMED, 'subtotal' => 10000, 'discount' => 0,
            'bill_discount' => 500, 'rounding_amount' => 0.40, 'tax' => 200, 'freight_charge' => 100, 'total' => 9800.40,
            'cost_of_goods' => 6800, 'created_by' => $owner->id,
        ]);

        DB::table('sal_invoice_lines')->insert([
            ['sales_invoice_id' => $invoice, 'product_id' => $products[0], 'qty' => 10, 'rate' => 600, 'discount' => 0,
                'tax' => 0, 'amount' => 6000, 'unit_cost' => 400, 'line_no' => 1],
            ['sales_invoice_id' => $invoice, 'product_id' => $products[1], 'qty' => 4, 'rate' => 1000, 'discount' => 0,
                'tax' => 200, 'amount' => 4200, 'unit_cost' => 700, 'line_no' => 2],
        ]);
    }

    public function test_the_salesperson_report_counts_the_bills_net_sale(): void
    {
        // ⚠️ লাইভের মতো কড়া MariaDB
        DB::statement("SET SESSION sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");

        $rows = collect(app(ReportEngine::class)->run(SalespersonReports::KEY,
            ['from' => '2019-02-01', 'to' => '2019-02-28', 'branch_id' => $this->branch], 1, 500)->rows)
            ->map(fn ($r) => is_array($r) ? $r : (array) $r);

        $this->assertEqualsWithDelta(self::NET, $rows->sum(fn ($r) => (float) $r['sales']), 0.01, '⛔ কর্মীর বিক্রিতে বিলের ছাড় নেই।');
        $this->assertEqualsWithDelta(self::NET, $rows->sum(fn ($r) => (float) $r['net_sales']), 0.01);
    }
}
