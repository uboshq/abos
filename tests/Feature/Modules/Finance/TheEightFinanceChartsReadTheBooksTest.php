<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Services\LedgerBalances;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Loan;
use App\Modules\Accounts\Models\LoanInstalment;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Finance\Services\FinanceCharts;
use App\Modules\Finance\Services\WorkingCapital;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ⭐ অর্থের ড্যাশবোর্ডের আট ছোট চার্ট খাতা পড়ে (মালিকের অনুমোদিত নমুনা, ১০ নভেম্বর ২০২৬)। [[FinanceCharts]]
 *
 * ⓘ দাবি পার্থক্যে: আগে মাপা, কাগজ বসানো, আবার মাপা।
 */
final class TheEightFinanceChartsReadTheBooksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-20 12:00:00'));
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CompanyContext::clear();
        parent::tearDown();
    }

    public function test_cash_flow_the_fund_trend_and_the_expense_split_follow_the_books(): void
    {
        $before = app(FinanceCharts::class)->all();

        $till = Account::query()->where('money_kind', Account::CASH)->postable()->active()->orderBy('code')->firstOrFail();
        $rent = StandardChart::find(StandardChart::RENT);
        $this->journal('2026-11-12', [[$till->id, '50000', null], [StandardChart::find(StandardChart::OWNER_CAPITAL)->id, null, '50000']]);
        $this->journal('2026-11-13', [[$rent->id, '9000', null], [$till->id, null, '9000']]);

        $after = app(FinanceCharts::class)->all();

        $this->assertSame('41000.0000', bcsub($after['cashflow']['net'], $before['cashflow']['net'], 4), '⛔ নগদ প্রবাহের নিট = এল − গেল নয়।');
        $this->assertSame('41000.0000', bcsub(end($after['trend']['points'])['total'], end($before['trend']['points'])['total'], 4), '⛔ তহবিলের ধারার শেষ বিন্দু আজকের তহবিল নয়।');
        $this->assertSame($before['trend']['points'][0]['total'], $after['trend']['points'][0]['total'], '⛔ বারো মাস আগের বিন্দুতে আজকের টাকা ঢুকল।');
        $this->assertSame('2025-12-31', $after['trend']['points'][0]['date'], '⛔ তহবিলের ধারা ১২ মাসের নয় (প্রথম বিন্দু ডিসেম্বর ২০২৫-এর শেষ)।');
        $this->assertCount(12, $after['trend']['points']);

        $this->assertSame('9000.0000', bcsub($after['expense']['total'], $before['expense']['total'], 4), '⛔ এ মাসের খরচে ভাড়া ওঠেনি।');
        $this->assertContains($rent->name(), array_column($after['expense']['parts'], 'label'), '⛔ খরচের ভাগে ভাড়ার খাত নেই।');
    }

    public function test_payables_fall_in_the_right_due_bucket(): void
    {
        $far = now()->addDays(400)->toDateString();
        PurchaseBill::query()->withoutGlobalScopes()->update(['due_on' => $far]);
        // ⓘ দাবির নিজের তিন বিল — ডেমোর বিল সব শোধ হয়ে থাকতে পারে (Mac, ১০ নভেম্বর ২০২৬)
        $bills = collect([$this->bill('PB-CH-1', '11000'), $this->bill('PB-CH-2', '23000'), $this->bill('PB-CH-3', '37000')]);

        $set = fn (int $i, int $days) => PurchaseBill::query()->withoutGlobalScopes()->whereKey($bills[$i]->id)->update(['due_on' => now()->addDays($days)->toDateString()]);
        $set(0, 7);
        $set(1, -30);
        $set(2, -31);

        $p = collect(app(FinanceCharts::class)->all()['payable']['buckets'])->keyBy('key');
        $this->assertSame(bcadd($bills[0]->dueAmount(), '0', 4), $p['week']['amount'], '⛔ ৭ দিন পরের বিল "৭ দিনে" ঘরে নেই।');
        $this->assertSame(bcadd($bills[1]->dueAmount(), '0', 4), $p['late30']['amount'], '⛔ ৩০ দিন পেরোনো বিল "১–৩০ পার" ঘরে নেই।');
        $this->assertSame(bcadd($bills[2]->dueAmount(), '0', 4), $p['late_more']['amount'], '⛔ ৩১ দিন পেরোনো বিল "৩০+ পার" ঘরে নেই।');
    }

    public function test_the_ratios_and_the_cash_cycle_use_the_working_capital_parts_and_ninety_days_of_flow(): void
    {
        $today = Carbon::today();
        // ⓘ মজুদ আর চলতি দায় দুটোই শূন্যের বেশি — নইলে "দ্রুত অনুপাতে মজুদ রয়ে গেল" ধরা পড়ে না (Mac মিউট্যান্ট C3 বেঁচেছিল, ১০ নভেম্বর ২০২৬)
        $till = Account::query()->where('money_kind', Account::CASH)->postable()->active()->orderBy('code')->firstOrFail();
        $stock = Account::query()->where('code', 'like', StandardChart::INVENTORY.'%')->postable()->active()->orderBy('code')->firstOrFail();
        $this->journal('2026-11-10', [[$stock->id, '80000', null], [StandardChart::find(StandardChart::OWNER_CAPITAL)->id, null, '80000']]);
        $this->journal('2026-11-11', [[$till->id, '5000', null], [StandardChart::find(StandardChart::VAT_PAYABLE)->id, null, '5000']]);

        $r = app(FinanceCharts::class)->all()['ratios'];
        $wc = app(WorkingCapital::class)->on($today);
        $this->assertSame(1, bccomp($wc['stock'], '0', 4), 'দাবির মজুদ শূন্য');
        $this->assertSame(1, bccomp(bcadd($wc['payable'], $wc['other'], 4), '0', 4), 'দাবির চলতি দায় শূন্য');

        $owes = bcadd($wc['payable'], $wc['other'], 4);
        $this->assertSame($wc['ratio'], $r['current'], '⛔ চলতি অনুপাত চলতি মূলধনের ঘরেরটা নয়।');
        $this->assertSame(bccomp($owes, '0', 4) > 0 ? bcdiv(bcadd($wc['receivable'], $wc['money'], 4), $owes, 2) : null, $r['quick'],
            '⛔ দ্রুত অনুপাত = (পাওনা + টাকা) ÷ চলতি দায় নয় — মজুদ বাদ যায়নি।');

        $cogs = $this->moved([StandardChart::COST_OF_GOODS_SOLD], $today->copy()->subDays(89), $today);
        $this->assertSame(bccomp($cogs, '0', 4) > 0 ? (int) bcdiv(bcmul($wc['stock'], '90', 4), $cogs, 0) : null, $r['dio'], '⛔ DIO = মজুদ × ৯০ ÷ ৯০ দিনের বিক্রীত পণ্যের খরচ নয়।');

        $c = (fn () => $this->cycle($today))->call(app(FinanceCharts::class));
        if ($r['ccc'] !== null) {
            $this->assertSame($c['dso'] + $c['dio'] - $c['dpo'], $r['ccc'], '⛔ নগদ চক্র = DSO + DIO − DPO নয়।');
        }
    }

    public function test_instalments_and_deposits_land_in_their_month(): void
    {
        LoanInstalment::query()->withoutGlobalScopes()->update(['due_date' => now()->addYears(3)->toDateString()]);
        // ⓘ দাবির নিজের ব্যাংক ঋণ — ডেমোতে নেওয়া ঋণ না-ও থাকতে পারে (Mac, ১০ নভেম্বর ২০২৬)
        $loan = Loan::query()->forceCreate([
            'company_id' => CompanyContext::id(), 'branch_id' => CompanyContext::branchId(), 'document_no' => 'LN-CH-1',
            'lender' => 'Chart Bank', 'kind' => Loan::TERM, 'direction' => Loan::TAKEN, 'sanctioned' => '1200000',
            'interest_rate' => '12', 'tenure_months' => 12, 'start_date' => '2026-09-01',
            'principal_account_id' => StandardChart::find('2211')->id, 'interest_account_id' => StandardChart::find(StandardChart::INTEREST_EXPENSE)->id,
            'status' => DocumentStatus::CONFIRMED,
        ]);
        $row = LoanInstalment::query()->create(['loan_id' => $loan->id, 'no' => 99, 'due_date' => '2027-01-15', 'principal' => '200000', 'interest' => '20000', 'status' => LoanInstalment::DUE]);

        $months = collect(app(FinanceCharts::class)->all()['loans']['months'])->keyBy('month');
        $this->assertSame(['2026-11', '2026-12', '2027-01', '2027-02', '2027-03', '2027-04'], $months->keys()->all(), '⛔ সামনের ছয় মাস এ মাস থেকে নয়।');
        $this->assertSame('220000.0000', $months['2027-01']['instalments'], '⛔ জানুয়ারির কিস্তি (আসল + সুদ) জানুয়ারিতে নেই।');
        $this->assertSame('0', bcadd($months['2026-12']['instalments'], '0', 0), '⛔ অন্য মাসে কিস্তি গোনা হলো।');
        $this->assertSame('2027-01', app(FinanceCharts::class)->all()['loans']['big'], '⛔ একমাত্র কিস্তির মাস "বড় কিস্তি" বলে দেখায় না।');
        $row->delete();
    }

    /**
     * ⭐ চূড়ান্ত নমুনায় (4h63xhNiy4m9KaJkZZQaEL, 10 নভেম্বর 2026 রাত) আট ছোট চার্টের ঘর নেই — তাদের অঙ্ক সারি 2–7-এ, তহবিলের সারির নিচে।
     * ⓘ চার্টের অঙ্ক এই ফাইলের বাকি দাবিতে ([[FinanceCharts::all()]]) পাহারায় থাকে — হোম আর ফোন পড়ে; সারির ক্রম [[TheFinanceBoardReadsTheBooksTest]]-এ।
     */
    public function test_the_page_carries_the_board_rows_under_the_fund_row_and_not_the_old_charts(): void
    {
        $html = (string) $this->get(route('module.dashboard', ['module' => 'finance']))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-finance-charts', $html, '⛔ পুরনো আট চার্টের ঘর এখনো পাতায় — চূড়ান্ত নমুনায় নেই।');
        foreach (['data-fin-forecast', 'data-fin-board-position', 'data-fin-loans', 'data-fin-health'] as $row) {
            $this->assertGreaterThan(strpos($html, 'data-fund-news'), strpos($html, $row), '⛔ '.$row.' তহবিলের সারির নিচে নয়।');
        }
    }

    private function bill(string $no, string $total): PurchaseBill
    {
        $bill = PurchaseBill::query()->create([
            'branch_id' => CompanyContext::branchId(), 'financial_year_id' => FinancialYear::forDate(now())?->id,
            'supplier_id' => Supplier::query()->firstOrFail()->id, 'warehouse_id' => Warehouse::query()->firstOrFail()->id,
            'document_no' => $no, 'trx_date' => now()->subDays(5)->toDateString(), 'due_on' => now()->addDays(400)->toDateString(),
            'subtotal' => $total, 'discount' => '0', 'tax' => '0', 'total' => $total, 'status' => DocumentStatus::CONFIRMED,
        ]);

        return PurchaseBill::query()->withPaid()->findOrFail($bill->id);
    }

    /** @param  list<string>  $codes */
    private function moved(array $codes, Carbon $from, Carbon $to): string
    {
        $total = '0';

        foreach ($codes as $code) {
            $account = StandardChart::find($code);
            $total = bcadd($total, bcsub($account->balanceOn($to->toDateString()), $account->balanceOn($from->copy()->subDay()->toDateString()), 4), 4);
        }

        return $total;
    }

    /** @param  list<array{0: int, 1: ?string, 2: ?string}>  $lines */
    private function journal(string $date, array $lines): void
    {
        $voucher = app(VoucherService::class)->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => $date, 'narration' => 'চার্টের দাবি'],
            array_map(fn (array $l) => array_filter(['account_id' => $l[0], 'debit' => $l[1], 'credit' => $l[2]], fn ($v) => $v !== null), $lines),
        );
        app(VoucherService::class)->post($voucher);
        app(LedgerBalances::class)->forget();
    }
}
