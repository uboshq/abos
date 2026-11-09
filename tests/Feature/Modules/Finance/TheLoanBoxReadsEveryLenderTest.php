<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Services\LedgerBalances;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Finance\Models\BankFacility;
use App\Modules\Finance\Services\LoanBox;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ⭐ অর্থের ড্যাশবোর্ডের ঋণের ঘর — প্রতিটা ঋণদাতা, মোট, ব্যাংক ঋণের মাসিক বদল, পরের কিস্তি, এ মাসের সুদ, ঋণসীমা (মালিকের অনুমোদিত
 * নমুনা, ১০ নভেম্বর ২০২৬)। [[LoanBox]]
 */
final class TheLoanBoxReadsEveryLenderTest extends TestCase
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

    public function test_the_total_is_the_rows_the_next_instalment_is_the_earliest_and_guarantees_are_not_debt(): void
    {
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $start = app(LoanBox::class)->on();
        $facility = fn (string $no, string $kind, string $bank, string $limit) => BankFacility::query()->create([
            'company_id' => $company->id, 'branch_id' => $company->defaultBranch()?->id, 'document_no' => $no,
            'kind' => $kind, 'bank' => $bank, 'limit_amount' => $limit, 'interest_rate' => '12',
            'sanctioned_on' => now()->subMonths(2)->toDateString(), 'status' => DocumentStatus::CONFIRMED,
        ]);
        $facility('BF-LB-1', BankFacility::CC, 'City Bank', '2500000');
        $facility('BF-LB-2', BankFacility::GUARANTEE, 'Guarantee Bank', '900000');

        $box = app(LoanBox::class)->on();

        $this->assertSame(array_reduce($box['rows'], fn (string $c, array $r) => bcadd($c, $r['left'], 4), '0'), $box['total'], '⛔ মোট = সারিগুলোর যোগ নয়।');
        $this->assertContains('City Bank', array_column($box['rows'], 'name'), '⛔ চালু CC সারিতে নেই।');
        $this->assertNotContains('Guarantee Bank', array_column($box['rows'], 'name'), '⛔ ব্যাংক গ্যারান্টি ঋণ বলে গোনা হলো — ওটা টাকা নয়।');
        $this->assertSame('2500000.0000', bcsub($box['od_limit'], $start['od_limit'], 4), '⛔ OD সীমায় নতুন CC-র সীমা যোগ হয়নি (বা গ্যারান্টির সীমাও ঢুকল)।');

        $dated = array_values(array_filter($box['rows'], fn (array $r) => $r['next'] !== null));
        if ($dated !== []) {
            $earliest = min(array_map(fn (array $r) => $r['next']['date'], $dated));
            $this->assertSame($earliest, $box['next']['date'], '⛔ "পরের কিস্তি" সবচেয়ে আগেরটা নয়।');
        }
    }

    public function test_the_bank_loan_change_and_this_months_interest_follow_the_books(): void
    {
        $before = app(LoanBox::class)->on();

        $loanAccount = Account::query()->where('code', 'like', '22%')->postable()->active()->orderBy('code')->firstOrFail();
        $till = Account::query()->where('money_kind', Account::CASH)->postable()->active()->orderBy('code')->firstOrFail();
        $interest = StandardChart::find(StandardChart::INTEREST_EXPENSE);

        // ⓘ গত মাসে নেওয়া ঋণ "এ মাসের বদলে" পড়ে না; এ মাসেরটা পড়ে
        $this->journal('2026-10-15', [[$till->id, '300000', null], [$loanAccount->id, null, '300000']]);
        $this->journal('2026-11-10', [[$till->id, '100000', null], [$loanAccount->id, null, '100000']]);
        $this->journal('2026-11-11', [[$interest->id, '4500', null], [$till->id, null, '4500']]);
        $this->journal('2026-10-30', [[$interest->id, '7000', null], [$till->id, null, '7000']]);

        $after = app(LoanBox::class)->on();

        $this->assertSame('100000.0000', bcsub($after['bank_change'], $before['bank_change'], 4), '⛔ ব্যাংক ঋণের বদল গত মাসের শেষ থেকে আজ নয়।');
        $this->assertSame('4500.0000', bcsub($after['interest_month'], $before['interest_month'], 4), '⛔ এ মাসের সুদে গত মাসের সুদও ঢুকল, বা এ মাসেরটা বাদ গেল।');
    }

    public function test_the_dashboard_draws_the_loan_box_last(): void
    {
        $html = (string) $this->get(route('module.dashboard', ['module' => 'finance']))->assertOk()->getContent();

        $this->assertStringContainsString('data-loan-total', $html, '⛔ অর্থের ড্যাশবোর্ডে ঋণের ঘর নেই।');
        $this->assertGreaterThan(strpos($html, 'data-working-capital'), strpos($html, 'data-loan-box'), '⛔ ঋণের ঘর সবার নিচে (চলতি মূলধনের পরে) নয়।');
        foreach (['next', 'interest', 'od', 'de', 'dscr'] as $kpi) {
            $this->assertStringContainsString('data-loan-kpi="'.$kpi.'"', $html, "⛔ '{$kpi}' মাপটা নেই।");
        }
    }

    /** @param  list<array{0: int, 1: ?string, 2: ?string}>  $lines */
    private function journal(string $date, array $lines): void
    {
        $voucher = app(VoucherService::class)->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => $date, 'narration' => 'ঋণের ঘরের দাবি'],
            array_map(fn (array $l) => array_filter(['account_id' => $l[0], 'debit' => $l[1], 'credit' => $l[2]], fn ($v) => $v !== null), $lines),
        );
        app(VoucherService::class)->post($voucher);
        app(LedgerBalances::class)->forget();
    }
}
