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
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * মাসওয়ারি বিক্রয় — মালিক, ১ অক্টোবর ২০২৬।
 *
 * ── মাপ ─────────────────────────────────────────────────────────────────
 * আগস্ট: দুই বিল (১,০০০ − ছাড় ৫০ = ৯৫০, খরচ ৬০০; ৫০০ − ২০ বিলের ছাড় = ৪৮০, খরচ ৩০০), আদায় ৭০০।
 * সেপ্টেম্বর: এক বিল ২,০০০ (খরচ ১,২০০), এক ফেরত ৩০০ (খরচ ১৮০), আদায় ১,০০০; আর একটা খসড়া বিল যা
 * কোথাও গোনা হয় না। ময়মনসিংহ বাছলে নেত্রকোনার একটা বিল নেই।
 */
final class TheMonthlySalesAddUpTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_month_adds_its_bills_returns_and_collections(): void
    {
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $branch = fn (string $code) => Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', $code)->firstOrFail();
        $mms = $branch('MMS');
        $ntk = $branch('NTK');
        CompanyContext::set($company->id, $mms->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $customer = (int) Customer::query()->withoutGlobalScopes()->where('company_id', $company->id)->orderBy('id')->value('id');
        $warehouse = (int) Warehouse::query()->withoutGlobalScopes()->where('company_id', $company->id)->orderBy('id')->value('id');
        $account = (int) Account::query()->where('is_group', false)->orderBy('id')->value('id');
        $year = (int) FinancialYear::query()->where('company_id', $company->id)->orderByDesc('starts_on')->value('id');

        $bill = fn (string $no, string $date, array $money, string $status = DocumentStatus::CONFIRMED, ?int $at = null) => DB::table('sal_invoices')->insert([
            'company_id' => $company->id, 'branch_id' => $at ?? $mms->id, 'document_no' => $no, 'customer_id' => $customer,
            'trx_date' => $date, 'status' => $status,
        ] + $money);

        $bill('ZQ-1', '2026-08-05', ['subtotal' => 1000, 'discount' => 50, 'bill_discount' => 0, 'tax' => 0, 'total' => 950, 'cost_of_goods' => 600]);
        $bill('ZQ-2', '2026-08-20', ['subtotal' => 500, 'discount' => 0, 'bill_discount' => 20, 'tax' => 0, 'total' => 480, 'cost_of_goods' => 300]);
        $bill('ZQ-3', '2026-09-10', ['subtotal' => 2000, 'discount' => 0, 'bill_discount' => 0, 'tax' => 0, 'total' => 2000, 'cost_of_goods' => 1200]);
        $bill('ZQ-4', '2026-09-11', ['subtotal' => 9999, 'discount' => 0, 'bill_discount' => 0, 'tax' => 0, 'total' => 9999, 'cost_of_goods' => 0], DocumentStatus::DRAFT);
        $bill('ZQ-5', '2026-09-12', ['subtotal' => 7777, 'discount' => 0, 'bill_discount' => 0, 'tax' => 0, 'total' => 7777, 'cost_of_goods' => 0], DocumentStatus::CONFIRMED, $ntk->id);

        DB::table('sal_returns')->insert([
            'company_id' => $company->id, 'branch_id' => $mms->id, 'document_no' => 'ZQ-R1', 'customer_id' => $customer,
            'warehouse_id' => $warehouse, 'trx_date' => '2026-09-15', 'status' => DocumentStatus::CONFIRMED,
            'subtotal' => 300, 'tax' => 0, 'total' => 300, 'cost_of_goods' => 180,
        ]);

        foreach ([['2026-08-25', 700, 'collection', 901], ['2026-09-20', 1000, 'receipt_voucher', 902]] as [$date, $amount, $source, $id]) {
            DB::table('ledger_entries')->insert([
                'company_id' => $company->id, 'branch_id' => $mms->id, 'financial_year_id' => $year, 'account_id' => $account,
                'trx_date' => $date, 'source_type' => $source, 'source_id' => $id,
                'party_type' => 'customer', 'party_id' => $customer, 'debit' => 0, 'credit' => $amount,
            ]);
        }

        $rows = collect(app(ReportEngine::class)->run('sales.monthly', ['from' => '2026-07-01', 'to' => '2026-09-30', 'branch_id' => $mms->id])->rows)
            ->keyBy(fn ($r) => (is_array($r) ? $r : (array) $r)['month'])
            ->map(fn ($r) => array_map(fn ($v) => is_numeric($v) ? (float) $v : $v, is_array($r) ? $r : (array) $r));

        $this->assertSame(['2026-08', '2026-09'], $rows->keys()->all(), 'মাসের সারি ভুল।');

        $expect = [
            '2026-08' => ['bills' => 2.0, 'gross' => 1500.0, 'discount' => 70.0, 'returned' => 0.0, 'net_sales' => 1430.0, 'collected' => 700.0, 'due_added' => 730.0, 'gross_profit' => 530.0],
            '2026-09' => ['bills' => 1.0, 'gross' => 2000.0, 'discount' => 0.0, 'returned' => 300.0, 'net_sales' => 1700.0, 'collected' => 1000.0, 'due_added' => 700.0, 'gross_profit' => 680.0],
        ];

        foreach ($expect as $month => $figures) {
            foreach ($figures as $column => $value) {
                $this->assertEqualsWithDelta($value, $rows[$month][$column], 0.001, "⛔ {$month} — {$column} ভুল।");
            }
        }
    }
}
