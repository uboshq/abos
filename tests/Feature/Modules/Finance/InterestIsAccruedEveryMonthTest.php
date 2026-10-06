<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\LedgerEntry;
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
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⭐ ব্যাংক ঋণের মাসিক সুদ জমা — অর্থ-মডিউলের পরিকল্পনা ৩.৩, ৬ অক্টোবর ২০২৬ (সমন্বয়কের অনুমোদিত নকশা ক;
 * [[InterestAccrualService]])।
 *
 * ⭐ দাবি:
 *   · মেয়াদি ঋণ: মাসের শেষ দিনে Dr ৫৩১০ / Cr ২১৪৫ = বাকি আসল × হার × (শেষ কিস্তির দিন থেকে মাসের শেষ) ÷ ৩৬৫
 *   · এক ঋণে এক মাস একবারই; চলতি মাস নয়
 *   · পরের মাস চালালে আগের মাসের জমা পরের মাসের প্রথম দিনে উল্টায় — ২১৪৫-এ কেবল সর্বশেষ জমা থাকে, খরচ একবারই
 *   · CC: মাসের প্রতিটা দিনের তোলা জের ধরে
 *   · সই "না" হলে খসড়া বাতিল আর মাসের সারি মোছা — মাসটা আবার চালানো যায়
 *   · পর্দার বোতাম থেকেই চলে
 */
final class InterestIsAccruedEveryMonthTest extends TestCase
{
    use RefreshDatabase;

    private const LIMIT = '1200000';

    private const RATE = '12';

    private BankFacility $loan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $this->loan = app(BankFacilityService::class)->open([
            'kind' => BankFacility::TERM, 'bank' => 'Sonali Bank',
            'sanctioned_on' => Carbon::parse('2026-07-08')->toDateString(),
            'first_instalment_on' => '2026-08-07',
            'limit_amount' => self::LIMIT, 'interest_rate' => self::RATE, 'instalments' => 12,
            'instalment_amount' => LoanSchedule::instalment(self::LIMIT, self::RATE, 12),
            'liability_account_id' => $this->liability()->id,
        ]);
        $this->journal($this->loan, '2026-07-08', [
            ['account_id' => $this->equity()->id, 'debit' => self::LIMIT, 'credit' => '0'],
            ['account_id' => $this->liability()->id, 'debit' => '0', 'credit' => self::LIMIT],
        ]);
    }

    public function test_a_term_loan_accrues_from_its_last_instalment_to_month_end(): void
    {
        $done = app(InterestAccrualService::class)->run(Carbon::parse('2026-08-01'));
        $accrual = InterestAccrual::query()->where('bank_facility_id', $this->loan->id)->firstOrFail();

        // ⓘ শেষ কিস্তির দিন ৭ আগস্ট → ৩১ আগস্ট = ২৪ দিন; বাকি আসল ১২,০০,০০০
        $this->assertSame(24, $accrual->days);
        $this->assertSame(bcdiv(bcmul(bcmul(self::LIMIT, self::RATE, 8), '24', 8), '36500', 2), bcadd((string) $accrual->amount, '0', 2));
        $this->assertSame(1, $done['accrued']);

        $voucher = $this->settle($accrual->voucher);
        $this->assertSame('2026-08-31', $voucher->trx_date->toDateString(), 'জমা মাসের শেষ দিনে নয়।');
        $this->assertSame(0, bccomp($this->balance(StandardChart::INTEREST_PAYABLE), bcadd((string) $accrual->amount, '0', 4), 2),
            '⛔ প্রদেয় সুদে জমাটা বসেনি।');
        $this->assertSame(0, bccomp($this->balance(StandardChart::INTEREST_EXPENSE), bcadd((string) $accrual->amount, '0', 4), 2));
    }

    public function test_one_month_once_and_never_a_month_not_yet_over(): void
    {
        $service = app(InterestAccrualService::class);
        $service->run(Carbon::parse('2026-08-01'));

        $this->assertSame(0, $service->run(Carbon::parse('2026-08-01'))['accrued'], '⛔ একই মাস দুবার বসল।');
        $this->assertSame(1, InterestAccrual::query()->where('bank_facility_id', $this->loan->id)->count());

        $this->expectException(ValidationException::class);
        $service->run(Carbon::today());
    }

    public function test_the_next_month_reverses_the_last_one_on_its_first_day(): void
    {
        $service = app(InterestAccrualService::class);
        $service->run(Carbon::parse('2026-08-01'));
        $august = InterestAccrual::query()->firstOrFail();
        $this->settle($august->voucher);

        $done = $service->run(Carbon::parse('2026-09-01'));
        $september = InterestAccrual::query()->where('for_month', '2026-09-01')->firstOrFail();
        $this->settle($september->voucher);

        $this->assertSame(1, $done['reversed']);
        $reversal = Voucher::query()->findOrFail($august->fresh()->reversal_voucher_id);
        $this->assertSame('2026-09-01', $reversal->trx_date->toDateString(), 'উল্টো দাখিলা পরের মাসের প্রথম দিনে নয়।');

        // ⓘ ২১৪৫-এ কেবল সেপ্টেম্বরের জমা — আগস্টেরটা ফিরে গেছে
        $this->assertSame(0, bccomp($this->balance(StandardChart::INTEREST_PAYABLE), bcadd((string) $september->amount, '0', 4), 2),
            '⛔ আগের মাসের জমা উল্টায়নি — প্রদেয় সুদ দ্বিগুণ।');

        // ⓘ সেপ্টেম্বর: শেষ কিস্তির দিন ৭ সেপ্টেম্বর → ৩০ সেপ্টেম্বর = ২৩ দিন
        $this->assertSame(23, $september->days);
    }

    public function test_a_cash_credit_accrues_on_each_days_drawn_balance(): void
    {
        $bank = $this->bankLeaf();
        $cc = app(BankFacilityService::class)->open([
            'kind' => BankFacility::CC, 'bank' => 'Janata Bank', 'sanctioned_on' => '2026-07-01',
            'limit_amount' => '500000', 'interest_rate' => '10', 'money_account_id' => $bank->id,
            'stock_value' => '900000', 'margin_percent' => '25',
        ]);

        // ⓘ ১ আগস্ট ১ লাখ জমা (হিসাবে টাকা, দেনা নেই — ৯ দিন শূন্য সুদ), ১০ আগস্ট ৪ লাখ তোলা (৬ দিন ৩ লাখ দেনা),
        //   ১৬ আগস্ট আরও ১ লাখ (১৬ দিন ৪ লাখ)
        $this->journal($cc, '2026-08-01', [
            ['account_id' => $bank->id, 'debit' => '100000', 'credit' => '0'],
            ['account_id' => $this->equity()->id, 'debit' => '0', 'credit' => '100000'],
        ]);
        $this->journal($cc, '2026-08-10', [
            ['account_id' => $this->equity()->id, 'debit' => '400000', 'credit' => '0'],
            ['account_id' => $bank->id, 'debit' => '0', 'credit' => '400000'],
        ]);
        $this->journal($cc, '2026-08-16', [
            ['account_id' => $this->equity()->id, 'debit' => '100000', 'credit' => '0'],
            ['account_id' => $bank->id, 'debit' => '0', 'credit' => '100000'],
        ]);

        app(InterestAccrualService::class)->run(Carbon::parse('2026-08-01'));
        $accrual = InterestAccrual::query()->where('bank_facility_id', $cc->id)->firstOrFail();

        $sum = bcadd(bcmul('300000', '6', 4), bcmul('400000', '16', 4), 4);
        $this->assertSame(bcdiv(bcmul($sum, '10', 8), '36500', 2), bcadd((string) $accrual->amount, '0', 2), '⛔ CC-র সুদ দৈনিক জের ধরে নয়।');
        $this->assertSame(31, $accrual->days);
    }

    public function test_a_refused_signature_cancels_the_draft_and_frees_the_month(): void
    {
        app(InterestAccrualService::class)->run(Carbon::parse('2026-08-01'));
        $accrual = InterestAccrual::query()->firstOrFail();
        $voucher = $accrual->voucher;

        if (! $voucher->isDraft()) {
            // ⓘ ছক না থাকলে সাথে সাথে খাতায় — "না"-র পথ মাপতে খসড়া অবস্থায় আনা হয়
            $voucher = app(VoucherService::class)->create(['type' => Voucher::JOURNAL, 'trx_date' => '2026-08-31', 'narration' => 'draft',
                'against_type' => BankFacility::drillSourceType(), 'against_id' => $this->loan->id], [
                    ['account_id' => StandardChart::find(StandardChart::INTEREST_EXPENSE)->id, 'debit' => '10', 'credit' => '0'],
                    ['account_id' => StandardChart::find(StandardChart::INTEREST_PAYABLE)->id, 'debit' => '0', 'credit' => '10'],
                ]);
            $accrual->forceFill(['voucher_id' => $voucher->id])->save();
        }

        app(InterestAccrualService::class)->dropRefused($voucher, 'no');

        $this->assertSame(DocumentStatus::CANCELLED, $voucher->fresh()->status);
        $this->assertSame(0, InterestAccrual::query()->count(), 'ফেরানো জমার সারি রয়ে গেল — মাসটা আর চালানো যেত না।');
        $this->assertSame(1, app(InterestAccrualService::class)->run(Carbon::parse('2026-08-01'))['accrued']);
    }

    public function test_the_last_signature_puts_a_held_accrual_in_the_books_once(): void
    {
        $voucher = app(VoucherService::class)->create(['type' => Voucher::JOURNAL, 'trx_date' => '2026-08-31', 'narration' => 'held',
            'against_type' => BankFacility::drillSourceType(), 'against_id' => $this->loan->id], [
                ['account_id' => StandardChart::find(StandardChart::INTEREST_EXPENSE)->id, 'debit' => '25', 'credit' => '0'],
                ['account_id' => StandardChart::find(StandardChart::INTEREST_PAYABLE)->id, 'debit' => '0', 'credit' => '25'],
            ]);
        $this->assertTrue($voucher->isDraft());

        app(InterestAccrualService::class)->finishSigned($voucher);
        app(InterestAccrualService::class)->finishSigned($voucher->fresh());

        $this->assertFalse($voucher->fresh()->isDraft(), '⛔ শেষ সইয়ের পরেও জমা খসড়ায় রইল।');
        $this->assertSame(0, bccomp($this->balance(StandardChart::INTEREST_PAYABLE), '25', 2), 'দুইবার খবরে দুইবার বসল।');
    }

    public function test_the_page_button_runs_it(): void
    {
        $this->post(route('finance.bank_facility.accrue'), ['month' => '2026-08'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(1, InterestAccrual::query()->count());

        $this->get(route('finance.bank_facility.index'))->assertOk()->assertSee('data-interest-accrual', false);
        $this->get(route('finance.bank_facility.show', $this->loan))->assertOk()->assertSee('data-interest-accruals', false);

        $this->post(route('finance.bank_facility.accrue'), ['month' => now()->format('Y-m')])->assertSessionHasErrors('month');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────────────

    /** খসড়া (সইয়ের অপেক্ষা) হলে শেষ সই পড়ার মতো খাতায় তোলা */
    private function settle(Voucher $voucher): Voucher
    {
        if ($voucher->fresh()->isDraft()) {
            app(InterestAccrualService::class)->finishSigned($voucher->fresh());
        }

        return $voucher->fresh();
    }

    private function balance(string $code): string
    {
        $id = StandardChart::find($code)->id;
        $row = LedgerEntry::query()->where('account_id', $id)->selectRaw('COALESCE(SUM(debit), 0) as d, COALESCE(SUM(credit), 0) as c')->first();

        return $code === StandardChart::INTEREST_PAYABLE
            ? bcsub((string) $row->c, (string) $row->d, 4)
            : bcsub((string) $row->d, (string) $row->c, 4);
    }

    /** @param  list<array<string, mixed>>  $lines */
    private function journal(BankFacility $facility, string $on, array $lines): void
    {
        $vouchers = app(VoucherService::class);
        $vouchers->post($vouchers->create([
            'type' => Voucher::JOURNAL, 'trx_date' => $on, 'narration' => 'loan test',
            // ⓘ ব্যাংকের খাতে টাকা গেলে লেনদেন নম্বর লাগে ([[VoucherService::assertBankReferenceIsFree]])
            'instrument_no' => 'TST-'.$on.'-'.substr(md5(json_encode($lines)), 0, 6),
            'against_type' => BankFacility::drillSourceType(), 'against_id' => $facility->id,
        ], $lines));
    }

    private function bankLeaf(): Account
    {
        $till = Account::query()->where('money_kind', Account::CASH)->postable()->firstOrFail();
        $bank = $till->replicate(['public_id']);
        $bank->forceFill(['code' => 'TST-CC', 'name_en' => 'CC account', 'name_bn' => 'CC account', 'money_kind' => Account::BANK,
            'parent_id' => Account::query()->where('code', StandardChart::BANK)->value('id')])->save();

        return $bank;
    }

    private function liability(): Account
    {
        return Account::query()->where('code', '2211')->firstOrFail();
    }

    private function equity(): Account
    {
        return StandardChart::find(StandardChart::OWNER_CAPITAL);
    }
}
