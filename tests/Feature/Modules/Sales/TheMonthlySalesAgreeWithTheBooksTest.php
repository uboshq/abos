<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Models\Product;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⛔ মাসিক বিক্রির রিপোর্ট খাতার সাথে মিলত না — পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬ (বিক্রয় ⚠️১০)।
 *
 * ⓘ [[MonthlySalesReport]]: লাভে বিলের ভাড়াও ঢুকত (খাতায় ভাড়ার আয়); পণ্য ধরলে ফেরতের অঙ্ক আগেই ভ্যাট-বাদ, তবু আবার ভ্যাট বাদ,
 * আর "ফেরত" ভ্যাট-বাদ অথচ "বিক্রি" ভ্যাট-সহ; বাতিল বিল নিজের মাস থেকে উধাও, অথচ খাতায় আয় সেই মাসে আর উল্টো বাতিলের মাসে।
 */
final class TheMonthlySalesAgreeWithTheBooksTest extends TestCase
{
    use RefreshDatabase;

    private int $company;

    private int $branch;

    private int $customer;

    private int $product;

    private int $year;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->company = (int) $company->id;
        $this->branch = (int) Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'MMS')->value('id');
        CompanyContext::set($company->id, $this->branch);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->customer = (int) Customer::query()->withoutGlobalScopes()->where('company_id', $company->id)->orderBy('id')->value('id');
        $this->product = (int) Product::query()->withoutGlobalScopes()->where('company_id', $company->id)->orderBy('id')->value('id');
        $this->year = (int) FinancialYear::query()->where('company_id', $company->id)->orderByDesc('starts_on')->value('id');
        $warehouse = (int) Warehouse::query()->withoutGlobalScopes()->where('company_id', $company->id)->orderBy('id')->value('id');

        // ⓘ আগস্ট: ভাড়াসহ বিল — মাল ১,০০০, ভ্যাট ৫০, ভাড়া ১০০, মোট ১,১৫০, খরচ ৬০০ → লাভ ৪০০ (ভাড়া লাভ নয়)
        $a = $this->bill('ZB-1', '2026-08-05', DocumentStatus::CONFIRMED, 1000, 50, 100, 1150, 600);
        $this->line($a, 10, 100, 50, 1050, 60);
        $this->booked($a);

        // ⓘ আগস্টের বিল ২,০০০ (খরচ ১,২০০), খাতায় বসেছিল, সেপ্টেম্বরের ৩ তারিখে বাতিল — আগস্টে থাকে, সেপ্টেম্বরে উল্টো
        $b = $this->bill('ZB-2', '2026-08-10', DocumentStatus::CANCELLED, 2000, 0, 0, 2000, 1200);
        $this->line($b, 20, 100, 0, 2000, 60);
        $this->booked($b);
        DB::table('sal_invoice_cancellations')->insert([
            'company_id' => $this->company, 'branch_id' => $this->branch, 'sales_invoice_id' => $b, 'customer_id' => $this->customer,
            'document_no' => 'CXL-ZB-2', 'trx_date' => '2026-09-03', 'reason' => 'ভুল বিল', 'total' => 2000, 'status' => DocumentStatus::CONFIRMED,
        ]);

        // ⓘ খসড়ায় বাতিল — কখনো খাতায় বসেনি, কোনো মাসেই নয়
        $c = $this->bill('ZB-3', '2026-08-12', DocumentStatus::CANCELLED, 5000, 0, 0, 5000, 3000);
        $this->line($c, 50, 100, 0, 5000, 60);

        // ⓘ সেপ্টেম্বরের ফেরত — সারির অঙ্ক ভ্যাট-বাদ ২০০, ভ্যাট ২০, ২টা, মূল সারির খরচ ৬০ করে
        $return = DB::table('sal_returns')->insertGetId([
            'company_id' => $this->company, 'branch_id' => $this->branch, 'document_no' => 'ZB-R1', 'customer_id' => $this->customer,
            'warehouse_id' => $warehouse, 'sales_invoice_id' => $a, 'trx_date' => '2026-09-15', 'status' => DocumentStatus::CONFIRMED,
            'subtotal' => 200, 'tax' => 20, 'total' => 220, 'cost_of_goods' => 120,
        ]);
        DB::table('sal_return_lines')->insert([
            'company_id' => $this->company, 'sales_return_id' => $return, 'product_id' => $this->product,
            'sales_invoice_line_id' => DB::table('sal_invoice_lines')->where('sales_invoice_id', $a)->value('id'),
            'qty' => 2, 'rate' => 100, 'tax' => 20, 'amount' => 200, 'line_no' => 1,
        ]);
    }

    public function test_the_month_by_month_figures_say_what_the_books_say(): void
    {
        $rows = $this->rows([]);

        $this->assertSame(['2026-08', '2026-09'], $rows->keys()->all());

        // ⓘ আগস্ট: ZB-1 আর বাতিলের আগের ZB-2 — খসড়ায় বাতিল ZB-3 নয়
        $this->assertEqualsWithDelta(3150.0, $rows['2026-08']['net_sales'], 0.001, '⛔ খাতায় বসা বাতিল বিল নিজের মাস থেকে উধাও, বা খসড়া-বাতিল ঢুকল');
        $this->assertEqualsWithDelta(1200.0, $rows['2026-08']['gross_profit'], 0.001, '⛔ লাভে বিলের ভাড়া ঢুকল (৪০০ + ৮০০ হওয়ার কথা)');

        // ⓘ সেপ্টেম্বর: ZB-2-এর উল্টো আর ফেরত — ফেরত ভ্যাট-সহ ২২০
        $this->assertEqualsWithDelta(-2000.0 - 220.0, $rows['2026-09']['net_sales'], 0.001, '⛔ বাতিলের মাসে উল্টো সারি নেই');
        $this->assertEqualsWithDelta(220.0, $rows['2026-09']['returned'], 0.001);
        // ⓘ বাতিলের −৮০০ আর ফেরতের −(২২০ − ভ্যাট ২০ − খরচ ১২০) = −৮০
        $this->assertEqualsWithDelta(-800.0 - 80.0, $rows['2026-09']['gross_profit'], 0.001);
    }

    public function test_a_september_report_shows_the_august_bill_cancelled_in_september(): void
    {
        // ⓘ বিলটা আগস্টের, পরিসরের বাইরে — বাতিলটা সেপ্টেম্বরের, ভেতরে; খাতার উল্টো দাখিলাও সেপ্টেম্বরে
        $rows = collect(app(ReportEngine::class)->run('sales.monthly', ['from' => '2026-09-01', 'to' => '2026-09-30', 'branch_id' => $this->branch])->rows)
            ->keyBy(fn ($r) => (is_array($r) ? $r : (array) $r)['month'])
            ->map(fn ($r) => array_map(fn ($v) => is_numeric($v) ? (float) $v : $v, is_array($r) ? $r : (array) $r));

        $this->assertEqualsWithDelta(-2000.0 - 220.0, $rows['2026-09']['net_sales'], 0.001, '⛔ পরিসরের বাইরের বিলের বাতিল বাতিলের মাসে এল না');
    }

    public function test_by_product_the_return_carries_its_vat_once_and_the_cancellation_comes_back(): void
    {
        $rows = $this->rows(['product_id' => $this->product]);

        $this->assertEqualsWithDelta(1050.0 + 2000.0, $rows['2026-08']['net_sales'], 0.001, '⛔ পণ্য ধরলে খাতায় বসা বাতিল বিলের সারি উধাও');
        $this->assertEqualsWithDelta(220.0, $rows['2026-09']['returned'], 0.001, '⛔ পণ্য ধরলে ফেরত ভ্যাট-বাদ — বিক্রি ভ্যাট-সহ');
        $this->assertEqualsWithDelta(-(200.0 - 120.0) - (2000.0 - 1200.0), $rows['2026-09']['gross_profit'], 0.001, '⛔ ফেরতের লাভে ভ্যাট দুবার বাদ, বা বাতিলের সারি নেই');
        $this->assertEqualsWithDelta(-2000.0 - 220.0, $rows['2026-09']['net_sales'], 0.001);
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /** @return \Illuminate\Support\Collection<string, array<string, mixed>> */
    private function rows(array $filters): \Illuminate\Support\Collection
    {
        return collect(app(ReportEngine::class)->run('sales.monthly', ['from' => '2026-07-01', 'to' => '2026-09-30', 'branch_id' => $this->branch, ...$filters])->rows)
            ->keyBy(fn ($r) => (is_array($r) ? $r : (array) $r)['month'])
            ->map(fn ($r) => array_map(fn ($v) => is_numeric($v) ? (float) $v : $v, is_array($r) ? $r : (array) $r));
    }

    private function bill(string $no, string $date, string $status, int $subtotal, int $tax, int $freight, int $total, int $cost): int
    {
        return DB::table('sal_invoices')->insertGetId([
            'company_id' => $this->company, 'branch_id' => $this->branch, 'document_no' => $no, 'customer_id' => $this->customer,
            'trx_date' => $date, 'status' => $status, 'subtotal' => $subtotal, 'discount' => 0, 'bill_discount' => 0, 'tax' => $tax,
            'freight_charge' => $freight, 'total' => $total, 'cost_of_goods' => $cost,
            'cancelled_at' => $status === DocumentStatus::CANCELLED ? '2026-09-03 10:00:00' : null,
        ]);
    }

    private function line(int $invoice, int $qty, int $rate, int $tax, int $amount, int $unitCost): void
    {
        DB::table('sal_invoice_lines')->insert([
            'sales_invoice_id' => $invoice, 'product_id' => $this->product,
            'qty' => $qty, 'rate' => $rate, 'tax' => $tax, 'amount' => $amount, 'unit_cost' => $unitCost, 'line_no' => 1,
        ]);
    }

    /** খাতায় বসেছিল — বিলের একটা সারি */
    private function booked(int $invoice): void
    {
        DB::table('ledger_entries')->insert([
            'company_id' => $this->company, 'branch_id' => $this->branch, 'financial_year_id' => $this->year,
            'account_id' => (int) Account::query()->where('is_group', false)->orderBy('id')->value('id'),
            'trx_date' => '2026-08-05', 'source_type' => 'sales_invoice', 'source_id' => $invoice, 'debit' => 1, 'credit' => 0,
        ]);
    }
}
