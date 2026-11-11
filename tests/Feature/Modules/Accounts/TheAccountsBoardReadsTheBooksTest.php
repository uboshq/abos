<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Dashboard\AccountsBoard;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ⭐ হিসাবের ড্যাশবোর্ড — মালিকের চূড়ান্ত নমুনা TErMgXrtgVBkuyuoCZq8NS (10 নভেম্বর 2026 রাত: "100% স্যাম্পলের সাথে মিল রেখে")। [[AccountsBoard]]
 *
 * দাবি: লাভ-ক্ষতির সেতু খাতার অঙ্কে মেলে (বিক্রি − কেনা দাম = মোট লাভ; নিট = সব আয় − সব খরচ); স্থিতিপত্রের দুই পাশ সমান; রেওয়ামিলে
 * ডেবিট = ক্রেডিট; পেছনের তারিখের এন্ট্রি আর এক লাখের বেশি হাতের জাবেদা সমস্যা কেন্দ্রে ওঠে; পাতায় কেবল নমুনার সাত সারি, ক্রমে।
 */
final class TheAccountsBoardReadsTheBooksTest extends TestCase
{
    use RefreshDatabase;

    private Account $till;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-20 12:00:00'));
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        $this->till = Account::query()->where('money_kind', Account::CASH)->postable()->active()->orderBy('code')->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CompanyContext::clear();
        parent::tearDown();
    }

    /** ⭐ 2,00,000 বিক্রি, 30,000 ভাড়া, 5,000 অন্যান্য আয় — সেতুর প্রতিটা ধাপ আর নিট লাভ */
    public function test_the_profit_bridge_reads_each_step_from_the_books(): void
    {
        $before = app(AccountsBoard::class)->pnl();
        $this->journal('2026-11-05', [[$this->till->id, '200000', null], [StandardChart::find(StandardChart::SALES)->id, null, '200000']]);
        $this->journal('2026-11-06', [[StandardChart::find(StandardChart::RENT)->id, '30000', null], [$this->till->id, null, '30000']]);
        $this->journal('2026-11-07', [[$this->till->id, '5000', null], [StandardChart::find('4300')->id, null, '5000']]);

        $after = app(AccountsBoard::class)->pnl();
        $d = fn (string $k) => bcsub($after[$k], $before[$k], 4);

        $this->assertSame('200000.0000', $d('sales'), '⛔ বিক্রি নিট বিক্রির খাত থেকে আসেনি।');
        $this->assertSame('30000.0000', $d('people'), '⛔ ভাড়া "বেতন ও ভাড়া"-য় ওঠেনি।');
        $this->assertSame('5000.0000', $d('other_income'), '⛔ অন্যান্য আয় আলাদা হয়নি।');
        $this->assertSame('175000.0000', $d('net'), '⛔ নিট = সব আয় − সব খরচ মেলেনি।');
        $this->assertSame(0, bccomp(bcsub($after['sales'], $after['cogs'], 4), $after['gross'], 4), '⛔ বিক্রি − কেনা দাম ≠ মোট লাভ।');
        $steps = bcadd(bcsub(bcsub(bcsub($after['gross'], $after['people'], 4), $after['other_cost'], 4), '0', 4), $after['other_income'], 4);
        $this->assertSame(0, bccomp($steps, $after['net'], 4), '⛔ সেতুর ধাপগুলো যোগ করে নিট লাভে পৌঁছায় না।');
    }

    /** ⭐ স্থিতিপত্র: সম্পদ = দায় + মালিকানা — জমা লাভ সমেত; আর রেওয়ামিলের মোট ডেবিট = মোট ক্রেডিট */
    public function test_the_balance_sheet_balances_and_the_trial_balance_agrees(): void
    {
        $this->journal('2026-11-05', [[$this->till->id, '90000', null], [StandardChart::find(StandardChart::OWNER_CAPITAL)->id, null, '90000']]);
        $this->journal('2026-11-06', [[$this->till->id, '40000', null], [StandardChart::find(StandardChart::SALES)->id, null, '40000']]);

        $bs = app(AccountsBoard::class)->balanceSheet();
        $assets = array_reduce($bs['assets'], fn (string $c, string $v) => bcadd($c, $v, 4), '0');
        $claims = array_reduce($bs['claims'], fn (string $c, string $v) => bcadd($c, $v, 4), '0');

        $this->assertSame(0, bccomp($assets, $bs['total'], 4), '⛔ সম্পদের ভাগগুলোর যোগ মোট সম্পদ নয়।');
        $this->assertSame(0, bccomp($assets, $claims, 4), '⛔ স্থিতিপত্রের দুই পাশ সমান নয়।');

        $tb = app(AccountsBoard::class)->ledgerChecks();
        $this->assertSame(0, bccomp($tb['debit'], $tb['credit'], 2), '⛔ রেওয়ামিলে ডেবিট ≠ ক্রেডিট।');
        $this->assertContains('trial_balance', array_column($tb['checks'], 'key'), '⛔ খাতা-যাচাইয়ের রেওয়ামিলের নিয়ম চলেনি।');
    }

    /** ⭐ পেছনের তারিখ (লেখার দিনের 3 দিনের বেশি আগে) আর এক লাখের বেশি জাবেদা — সমস্যা কেন্দ্রে, সংখ্যাসহ */
    public function test_back_dated_entries_and_large_manual_journals_reach_the_problem_centre(): void
    {
        $count = fn (string $key) => (int) collect(app(AccountsBoard::class)->problems())->firstWhere('key', $key)['n'];
        $backBefore = $count('backdated');
        $bigBefore = $count('journal');

        $this->journal('2026-11-02', [[$this->till->id, '150000', null], [StandardChart::find(StandardChart::OWNER_CAPITAL)->id, null, '150000']]);
        $this->journal('2026-11-19', [[$this->till->id, '1000', null], [StandardChart::find(StandardChart::OWNER_CAPITAL)->id, null, '1000']]);

        $this->assertSame($backBefore + 1, $count('backdated'), '⛔ 18 দিন পেছনের তারিখের এন্ট্রি ধরা পড়েনি — বা 1 দিনেরটাও ধরা পড়ল।');
        $this->assertSame($bigBefore + 1, $count('journal'), '⛔ দেড় লাখের হাতের জাবেদা ধরা পড়েনি — বা হাজার টাকারটাও ধরা পড়ল।');
        $this->assertSame('today', collect(app(AccountsBoard::class)->problems())->firstWhere('key', 'journal')['sev'], '⛔ বড় জাবেদার রং "আজই" নয়।');
    }

    /** ⭐ পাতায় কেবল নমুনার সাত সারি, ক্রমে — পুরনো কার্ড-চার্ট-তালিকা নেই */
    public function test_the_page_is_the_samples_seven_rows_in_order(): void
    {
        $html = (string) $this->get(route('module.dashboard', ['module' => 'accounts']))->assertOk()->getContent();
        $marks = ['data-acc-problems', 'data-acc-close', 'data-acc-pnl', 'data-acc-balance-sheet', 'data-acc-trial', 'data-acc-glance', 'data-acc-tree',
            'data-acc-timeline', 'data-acc-banks', 'data-acc-tax', 'data-acc-flow', 'data-acc-bullets', 'data-acc-income-expense',
            'data-acc-funnel', 'data-acc-types', 'data-acc-calendar', 'data-acc-tills', 'data-acc-bubbles', 'data-acc-health', 'data-acc-top', 'data-acc-recent'];
        $at = [];

        foreach ($marks as $m) {
            $at[$m] = strpos($html, $m);
            $this->assertNotFalse($at[$m], '⛔ পাতায় '.$m.' নেই।');
        }

        $sorted = $at;
        asort($sorted);
        $this->assertSame($marks, array_keys($sorted), '⛔ ঘরগুলো নমুনার ক্রমে নেই।');
        $this->assertStringNotContainsString('data-stat-grid', $html, '⛔ পুরনো সংখ্যার কার্ড এখনো পাতায়।');
    }

    /** @param  list<array{0: int, 1: ?string, 2: ?string}>  $lines */
    private function journal(string $date, array $lines): void
    {
        $voucher = app(VoucherService::class)->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => $date, 'narration' => 'হিসাবের বোর্ডের দাবি'],
            array_map(fn (array $l) => array_filter(['account_id' => $l[0], 'debit' => $l[1], 'credit' => $l[2]], fn ($v) => $v !== null), $lines),
        );
        app(VoucherService::class)->post($voucher);
        app(\App\Core\Services\LedgerBalances::class)->forget();
    }
}
