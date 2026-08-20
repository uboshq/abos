<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Loan;
use App\Modules\Accounts\Services\LoanService;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ব্যাংকে রাখা টাকা, আর ঋণের পেছনে বাঁধা FD।
 *
 * ── কেন FD-ও একটা ঋণ ────────────────────────────────────────────────
 * FD মানে আমরা ব্যাংককে টাকা ধার দিয়েছি নির্দিষ্ট মেয়াদে, নির্দিষ্ট
 * সুদে। DPS একই জিনিস, কেবল টাকাটা মাসে মাসে যায়। দুইটাই
 * `direction = given` — তাই `acc_loans`-এই বসে, আর সুদ-খতিয়ান-বকেয়ার
 * পুরো যন্ত্রটা আবার লিখতে হয় না।
 *
 * ── সবচেয়ে দামি ভুলটা যেখানে ────────────────────────────────────────
 * ব্যাংক প্রায়ই ঋণ দেয় FD বন্ধক রেখে। কাগজে দুইটা আলাদা জিনিস — একটা
 * সম্পদ, একটা দায়। কিন্তু ওই FD-র টাকাটা আমাদের হাতে নেই: ঋণ শোধ না
 * হওয়া পর্যন্ত ভাঙানো যায় না।
 *
 * সম্পর্কটা না রাখলে তালিকায় FD-টা "আছে" দেখাত, আর কেউ দরকারের দিনে
 * ধরে নিত ওটা ভাঙিয়ে ফেলা যাবে।
 */
class TheFdBehindTheLoanTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();
    }

    private function money(): Account
    {
        return Account::query()->firstOrCreate(
            ['code' => '1101-FD'],
            [
                'company_id' => $this->company->id,
                'parent_id' => StandardChart::find(StandardChart::CASH_IN_HAND)->id,
                'name_en' => 'Main till',
                'name_bn' => 'প্রধান ক্যাশ',
                'type' => Account::ASSET,
                'nature' => Account::DEBIT,
                'is_cash' => true,
            ],
        );
    }

    /** FD-র নিজের খাত — সম্পদ। */
    private function deposit(): Account
    {
        return Account::query()->firstOrCreate(
            ['code' => '1150-FD'],
            [
                'company_id' => $this->company->id,
                'parent_id' => null,
                'name_en' => 'Fixed deposits',
                'name_bn' => 'স্থায়ী আমানত',
                'type' => Account::ASSET,
                'nature' => Account::DEBIT,
            ],
        );
    }

    /** সুদ আয়ের খাত — খরচ নয়। */
    private function interestIncome(): Account
    {
        return Account::query()->firstOrCreate(
            ['code' => '4900-INT'],
            [
                'company_id' => $this->company->id,
                'parent_id' => null,
                'name_en' => 'Interest income',
                'name_bn' => 'সুদ আয়',
                'type' => Account::INCOME,
                'nature' => Account::CREDIT,
            ],
        );
    }

    private function payable(): Account
    {
        return Account::query()->where('code', '2210')->firstOrFail();
    }

    private function interestExpense(): Account
    {
        return Account::query()->where('type', Account::EXPENSE)->postable()->orderBy('code')->firstOrFail();
    }

    private function fd(string $amount, ?int $pledgedAgainst = null): Loan
    {
        return app(LoanService::class)->create(
            data: [
                'lender' => 'Sonali Bank',
                'kind' => Loan::FD,
                'direction' => Loan::GIVEN,
                'sanctioned' => $amount,
                'interest_rate' => '9',
                'start_date' => '2026-08-01',
                'matures_on' => '2027-08-01',
                'pledged_against_id' => $pledgedAgainst,
                'principal_account_id' => $this->deposit()->id,
                'interest_account_id' => $this->interestIncome()->id,
            ],
            intoAccountId: $this->money()->id,
        );
    }

    private function bankLoan(string $amount): Loan
    {
        return app(LoanService::class)->create(
            data: [
                'lender' => 'Sonali Bank',
                'kind' => Loan::CC,
                'sanctioned' => $amount,
                'interest_rate' => '12',
                'start_date' => '2026-08-01',
                'principal_account_id' => $this->payable()->id,
                'interest_account_id' => $this->interestExpense()->id,
            ],
        );
    }

    /* ── FD নিজে ────────────────────────────────────────────────── */

    public function test_money_put_into_an_fd_leaves_the_till(): void
    {
        $fd = $this->fd('200000');

        $this->assertTrue($fd->isDeposit());
        $this->assertTrue($fd->isGiven());

        $out = LedgerEntry::query()->where('account_id', $this->money()->id)->sum('credit');
        $this->assertSame('200000.0000', (string) $out);

        $in = LedgerEntry::query()->where('account_id', $this->deposit()->id)->sum('debit');
        $this->assertSame('200000.0000', (string) $in);
    }

    public function test_an_fd_has_no_instalment_schedule(): void
    {
        $this->assertSame(0, $this->fd('200000')->instalments()->count());
    }

    /**
     * FD-র সুদ আয়, খরচ নয়।
     *
     * দাখিলা উল্টো না বসালে পাওয়া সুদটা খরচ হয়ে বসত, আর মুনাফা দুইবার
     * কমত: একবার আয়টা না দেখিয়ে, আরেকবার ওটাকে খরচ দেখিয়ে।
     */
    public function test_interest_on_an_fd_is_income_not_expense(): void
    {
        $fd = $this->fd('200000');

        app(LoanService::class)->chargeInterest($fd, '18000', '2026-12-31');

        $income = LedgerEntry::query()->where('account_id', $this->interestIncome()->id)->sum('credit');
        $this->assertSame('18000.0000', (string) $income);

        $wrongSide = LedgerEntry::query()->where('account_id', $this->interestIncome()->id)->sum('debit');
        $this->assertSame('0.0000', (string) $wrongSide,
            'FD-র সুদ খরচের দিকে বসেছে — ওটা আয়।');

        // সুদটা টাকার সাথেই জমেছে
        $this->assertSame('218000.0000', $fd->refresh()->outstanding());
    }

    public function test_breaking_an_fd_brings_the_money_back(): void
    {
        $fd = $this->fd('200000');

        app(LoanService::class)->repay($fd, '200000', $this->money()->id, '2026-12-31');

        $this->assertSame('0.0000', $fd->refresh()->outstanding());

        $back = LedgerEntry::query()->where('account_id', $this->money()->id)->sum('debit');
        $this->assertSame('200000.0000', (string) $back);
    }

    /* ── DPS ────────────────────────────────────────────────────── */

    /**
     * DPS-এ শুরুর দিনে কিছুই নড়ে না।
     *
     * টাকাটা মাসে মাসে যায়। শুরুতেই পুরোটা বসিয়ে দিলে খাতা বলত আমরা
     * আজই সবটা জমা দিয়ে ফেলেছি — অথচ প্রথম কিস্তিটাও তখনো যায়নি।
     */
    public function test_a_dps_moves_no_money_on_the_day_it_opens(): void
    {
        $dps = app(LoanService::class)->create(
            data: [
                'lender' => 'Sonali Bank',
                'kind' => Loan::DPS,
                'sanctioned' => '120000',
                'interest_rate' => '9',
                'start_date' => '2026-08-01',
                'matures_on' => '2031-08-01',
                'principal_account_id' => $this->deposit()->id,
                'interest_account_id' => $this->interestIncome()->id,
            ],
            intoAccountId: $this->money()->id,
        );

        $this->assertSame(Loan::GIVEN, $dps->direction);
        $this->assertSame('0.0000', $dps->outstanding());
        $this->assertSame(0, LedgerEntry::query()->where('account_id', $this->deposit()->id)->count());
    }

    public function test_each_dps_instalment_is_its_own_event(): void
    {
        $dps = app(LoanService::class)->create(
            data: [
                'lender' => 'Sonali Bank',
                'kind' => Loan::DPS,
                'sanctioned' => '120000',
                'interest_rate' => '9',
                'start_date' => '2026-08-01',
                'principal_account_id' => $this->deposit()->id,
                'interest_account_id' => $this->interestIncome()->id,
            ],
        );

        app(LoanService::class)->drawDown($dps, '2000', $this->money()->id, '2026-08-01');
        app(LoanService::class)->drawDown($dps, '2000', $this->money()->id, '2026-09-01');

        $this->assertSame('4000.0000', $dps->refresh()->outstanding());
    }

    /* ── বাঁধা FD ───────────────────────────────────────────────── */

    public function test_an_unpledged_fd_is_money_we_can_reach(): void
    {
        $this->assertFalse($this->fd('200000')->isLocked());
    }

    public function test_an_fd_pledged_against_a_live_loan_is_out_of_reach(): void
    {
        $loan = $this->bankLoan('300000');
        app(LoanService::class)->drawDown($loan, '300000', $this->money()->id, '2026-08-02');

        $fd = $this->fd('200000', $loan->id);

        $this->assertTrue($fd->isLocked(),
            'বাঁধা FD-টাকে হাতের টাকা দেখাচ্ছে — অথচ ঋণ শোধ না হলে ওটা ভাঙানো যায় না।');
        $this->assertSame($loan->id, $fd->pledgedAgainst->id);
    }

    /**
     * ঋণ শোধ হলে বাঁধনও খোলে।
     *
     * বন্ধক থাকে দায়ের জন্য; দায় না থাকলে বন্ধকেরও কারণ নেই। এটা না
     * থাকলে শোধ করা ঋণের FD চিরকাল আটকে দেখাত, আর মালিক জানতেন না
     * তাঁর টাকাটা আসলে ছাড়া পেয়ে গেছে।
     */
    public function test_paying_off_the_loan_frees_the_fd(): void
    {
        $loan = $this->bankLoan('300000');
        app(LoanService::class)->drawDown($loan, '300000', $this->money()->id, '2026-08-02');

        $fd = $this->fd('200000', $loan->id);
        $this->assertTrue($fd->isLocked());

        app(LoanService::class)->repay($loan, '300000', $this->money()->id, '2026-09-01');

        $this->assertFalse($fd->refresh()->isLocked());
    }

    /** একটা ঋণের বিপরীতে একাধিক FD বাঁধা থাকতে পারে। */
    public function test_a_loan_can_have_more_than_one_fd_behind_it(): void
    {
        $loan = $this->bankLoan('500000');
        app(LoanService::class)->drawDown($loan, '500000', $this->money()->id, '2026-08-02');

        $this->fd('200000', $loan->id);
        $this->fd('150000', $loan->id);

        $this->assertSame(2, $loan->refresh()->pledges()->count());
    }
}
