<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Finance\Models\BankFacility;
use App\Modules\Finance\Models\InterestAccrual;
use App\Modules\Finance\Services\BankFacilityService;
use App\Modules\Finance\Services\InterestAccrualService;
use App\Modules\Finance\Services\LoanSchedule;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ঋণের সুদের জমা ভুল দিন থেকে গুনত (Finance লাইন-নিরীক্ষা, ১৩ অক্টোবর ২০২৬; [[InterestAccrualService::accruesFrom()]])।
 *
 * ⭐ দাবিগুলো — জমা শেষ যেদিন সুদ সত্যিই দেওয়া হয়েছে সেদিন থেকে, নইলে প্রথম তোলা থেকে:
 *   · কিস্তির সংখ্যা ছাড়া LTR — জানুয়ারির মঞ্জুরি, অক্টোবরে তোলা: অক্টোবরের সুদ ৩০ দিনের, ২৯৯ দিনের নয়।
 *   · মেয়াদি ঋণের কিস্তি দেওয়া হয়নি — সুদ তোলার দিন থেকে, সূচির শেষ কিস্তির দিন থেকে নয়।
 *   · CC — ব্যাংক সুদ না কাটা পর্যন্ত জমা মোট বাড়ে (আগের মাস উল্টায়, তাই মাসের খরচ = বাড়তি অংশ); কাটলে পরের দিন থেকে।
 */
final class TheLoanInterestCountedFromTheWrongDayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 10:00:00');
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_an_ltr_without_instalments_accrues_from_the_day_it_was_drawn(): void
    {
        $ltr = app(BankFacilityService::class)->open([
            'kind' => BankFacility::LTR, 'bank' => 'Pubali Bank', 'sanctioned_on' => '2026-01-05',
            'limit_amount' => '2000000', 'interest_rate' => '12', 'margin_percent' => '10', 'liability_account_id' => $this->liability()->id,
        ]);
        $this->journal($ltr, '2026-10-01', '1000000');

        app(InterestAccrualService::class)->run(Carbon::parse('2026-10-01'));
        $accrual = InterestAccrual::query()->where('bank_facility_id', $ltr->id)->sole();

        $this->assertSame(30, (int) $accrual->days, '⛔ সুদ মঞ্জুরির দিন থেকে গোনা — তোলার আগের দিনগুলোরও সুদ');
        $this->assertSame(Money::round(bcdiv(bcmul(bcmul('1000000', '12', 8), '30', 8), '36500', 8), 2), bcadd((string) $accrual->amount, '0', 2));
    }

    public function test_an_unpaid_instalment_does_not_restart_the_interest(): void
    {
        $loan = app(BankFacilityService::class)->open([
            'kind' => BankFacility::TERM, 'bank' => 'Sonali Bank', 'sanctioned_on' => '2026-07-08', 'first_instalment_on' => '2026-08-07',
            'limit_amount' => '1200000', 'interest_rate' => '12', 'instalments' => 12,
            'instalment_amount' => LoanSchedule::instalment('1200000', '12', 12), 'liability_account_id' => $this->liability()->id,
        ]);
        $this->journal($loan, '2026-07-08', '1200000');

        app(InterestAccrualService::class)->run(Carbon::parse('2026-09-01'));
        $september = InterestAccrual::query()->where('bank_facility_id', $loan->id)->sole();

        // ⓘ ৮ জুলাই থেকে ৩০ সেপ্টেম্বর = ৮৪ দিন — ৭ আগস্ট আর ৭ সেপ্টেম্বরের কিস্তি দেওয়া হয়নি
        $this->assertSame(84, (int) $september->days, '⛔ না-দেওয়া কিস্তির দিন থেকে গোনা — বকেয়া সুদ বাদ পড়ল');
    }

    public function test_a_cash_credit_accrues_since_the_bank_last_charged_interest(): void
    {
        $bank = $this->bankLeaf();
        $cc = app(BankFacilityService::class)->open([
            'kind' => BankFacility::CC, 'bank' => 'Janata Bank', 'sanctioned_on' => '2026-07-01',
            'limit_amount' => '500000', 'interest_rate' => '10', 'money_account_id' => $bank->id,
            'stock_value' => '900000', 'margin_percent' => '25',
        ]);
        $this->book($cc, '2026-08-10', [
            ['account_id' => StandardChart::find(StandardChart::OWNER_CAPITAL)->id, 'debit' => '400000', 'credit' => '0'],
            ['account_id' => $bank->id, 'debit' => '0', 'credit' => '400000'],
        ]);

        app(InterestAccrualService::class)->run(Carbon::parse('2026-08-01'));
        app(InterestAccrualService::class)->run(Carbon::parse('2026-09-01'));
        $september = InterestAccrual::query()->where('bank_facility_id', $cc->id)->where('for_month', '2026-09-01')->sole();

        // ⓘ ব্যাংক এখনো সুদ কাটেনি — ১০ আগস্ট থেকে ৩০ সেপ্টেম্বর, ৫২ দিন
        $this->assertSame(52, (int) $september->days, '⛔ CC কেবল চলতি মাস গুনল, অথচ আগের মাস উল্টাল — মাঝের মাসের খরচ শূন্য');

        // ⓘ ৩০ সেপ্টেম্বর ব্যাংক সুদ কাটল — সেদিন পর্যন্ত নেওয়া, তাই অক্টোবরের জমা ১ অক্টোবর থেকে, ৩১ দিন
        $this->book($cc, '2026-09-30', [
            ['account_id' => StandardChart::find(StandardChart::INTEREST_EXPENSE)->id, 'debit' => '5000', 'credit' => '0'],
            ['account_id' => $bank->id, 'debit' => '0', 'credit' => '5000'],
        ]);
        app(InterestAccrualService::class)->run(Carbon::parse('2026-10-01'));
        $october = InterestAccrual::query()->where('bank_facility_id', $cc->id)->where('for_month', '2026-10-01')->sole();
        $this->assertSame(31, (int) $october->days, '⛔ ব্যাংক ৩০ সেপ্টেম্বর সুদ কাটার পরেও অক্টোবরের জমা আগের দিনগুলো গুনল');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function journal(BankFacility $facility, string $on, string $amount): void
    {
        $this->book($facility, $on, [
            ['account_id' => StandardChart::find(StandardChart::OWNER_CAPITAL)->id, 'debit' => $amount, 'credit' => '0'],
            ['account_id' => $this->liability()->id, 'debit' => '0', 'credit' => $amount],
        ]);
    }

    /** @param  list<array<string, mixed>>  $lines */
    private function book(BankFacility $facility, string $on, array $lines): void
    {
        $vouchers = app(VoucherService::class);
        $vouchers->post($vouchers->create([
            'type' => Voucher::JOURNAL, 'trx_date' => $on, 'narration' => 'loan test',
            'instrument_no' => 'TST-'.$on.'-'.substr(md5(json_encode($lines)), 0, 6),
            'against_type' => BankFacility::drillSourceType(), 'against_id' => $facility->id,
        ], $lines));
    }

    private function bankLeaf(): Account
    {
        $till = Account::query()->where('money_kind', Account::CASH)->postable()->firstOrFail();
        $bank = $till->replicate(['public_id']);
        $bank->forceFill(['code' => 'TST-CC2', 'name_en' => 'CC account', 'name_bn' => 'CC account', 'money_kind' => Account::BANK,
            'parent_id' => Account::query()->where('code', StandardChart::BANK)->value('id')])->save();

        return $bank;
    }

    private function liability(): Account
    {
        return Account::query()->where('code', '2211')->firstOrFail();
    }
}
