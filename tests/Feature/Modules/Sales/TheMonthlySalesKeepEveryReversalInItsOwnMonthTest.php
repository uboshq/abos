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
 * ⛔ পরে উল্টানো আদায় আর বাতিল ফেরত মাসিক বিক্রি থেকে পেছনে মুছে যেত — ১৩ অক্টোবর ২০২৬, Sales লাইন-ধরা নিরীক্ষা।
 *
 * ⓘ আগস্টে ৫০০ আদায়, সেপ্টেম্বরে উল্টানো; আগস্টে ৩০০ ফেরত (খরচ ১৮০), সেপ্টেম্বরে বাতিল। খাতায় আগস্ট আগের মতোই থাকে আর
 * উল্টো দাখিলা সেপ্টেম্বরে বসে — রিপোর্ট আগস্ট থেকে দুইটাই মুছত আর সেপ্টেম্বরে কিছুই দেখাত না, তাই ছাপা আগস্ট পরে বদলে যেত।
 */
final class TheMonthlySalesKeepEveryReversalInItsOwnMonthTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_reversed_collection_and_a_cancelled_return_stay_in_their_month_and_turn_back_in_the_next(): void
    {
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $branch = (int) Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'MMS')->value('id');
        CompanyContext::set($company->id, $branch);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $customer = (int) Customer::query()->withoutGlobalScopes()->where('company_id', $company->id)->orderBy('id')->value('id');
        $warehouse = (int) Warehouse::query()->withoutGlobalScopes()->where('company_id', $company->id)->orderBy('id')->value('id');
        $account = (int) Account::query()->where('is_group', false)->orderBy('id')->value('id');
        $year = (int) FinancialYear::query()->where('company_id', $company->id)->orderByDesc('starts_on')->value('id');

        $return = DB::table('sal_returns')->insertGetId([
            'company_id' => $company->id, 'branch_id' => $branch, 'document_no' => 'ZV-R1', 'customer_id' => $customer,
            'warehouse_id' => $warehouse, 'trx_date' => '2026-08-25', 'status' => DocumentStatus::CANCELLED,
            'cancelled_at' => '2026-09-10 11:00:00', 'subtotal' => 300, 'tax' => 0, 'total' => 300, 'cost_of_goods' => 180,
        ]);

        $ledger = fn (string $date, string $source, int $id, int $debit, int $credit) => DB::table('ledger_entries')->insert([
            'company_id' => $company->id, 'branch_id' => $branch, 'financial_year_id' => $year, 'account_id' => $account,
            'trx_date' => $date, 'source_type' => $source, 'source_id' => $id,
            'party_type' => 'customer', 'party_id' => $customer, 'debit' => $debit, 'credit' => $credit,
        ]);

        // ⓘ খাতার সারি — আদায় আর তার উল্টো, ফেরত আর তার উল্টো (গ্রাহকের দিকটা)
        $ledger('2026-08-20', 'collection', 951, 0, 500);
        $ledger('2026-09-05', 'collection:reversal', 951, 500, 0);
        $ledger('2026-08-25', 'sales_return', $return, 0, 300);
        $ledger('2026-09-10', 'sales_return:reversal', $return, 300, 0);

        $rows = $this->rows($branch, '2026-07-01', '2026-09-30');

        $this->assertEqualsWithDelta(500.0, $rows['2026-08']['collected'], 0.001, '⛔ পরে উল্টানো আদায় নিজের মাস থেকে মুছে গেল');
        $this->assertEqualsWithDelta(-500.0, $rows['2026-09']['collected'], 0.001, '⛔ উল্টানোর মাসে আদায় কমল না');

        $this->assertEqualsWithDelta(300.0, $rows['2026-08']['returned'], 0.001, '⛔ পরে বাতিল ফেরত নিজের মাস থেকে মুছে গেল');
        $this->assertEqualsWithDelta(-120.0, $rows['2026-08']['gross_profit'], 0.001);
        $this->assertEqualsWithDelta(-300.0, $rows['2026-09']['returned'], 0.001, '⛔ বাতিলের মাসে ফেরতের উল্টো নেই');
        $this->assertEqualsWithDelta(120.0, $rows['2026-09']['gross_profit'], 0.001);

        // ⓘ কেবল সেপ্টেম্বর বাছলেও উল্টোগুলো আসে — আগস্টের কাগজ পরিসরের বাইরে
        $september = $this->rows($branch, '2026-09-01', '2026-09-30');
        $this->assertEqualsWithDelta(-500.0, $september['2026-09']['collected'], 0.001);
        $this->assertEqualsWithDelta(-300.0, $september['2026-09']['returned'], 0.001);
    }

    /** @return \Illuminate\Support\Collection<string, array<string, mixed>> */
    private function rows(int $branch, string $from, string $to): \Illuminate\Support\Collection
    {
        return collect(app(ReportEngine::class)->run('sales.monthly', ['from' => $from, 'to' => $to, 'branch_id' => $branch])->rows)
            ->keyBy(fn ($r) => (is_array($r) ? $r : (array) $r)['month'])
            ->map(fn ($r) => array_map(fn ($v) => is_numeric($v) ? (float) $v : $v, is_array($r) ? $r : (array) $r));
    }
}
