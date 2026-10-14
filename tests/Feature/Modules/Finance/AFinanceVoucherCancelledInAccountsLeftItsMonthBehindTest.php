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
use App\Modules\Accounts\Services\AccountsReversalService;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Finance\Models\BankFacility;
use App\Modules\Finance\Models\Deposit;
use App\Modules\Finance\Models\DepositKind;
use App\Modules\Finance\Models\Institution;
use App\Modules\Finance\Models\InsuranceClaim;
use App\Modules\Finance\Models\InsurancePolicy;
use App\Modules\Finance\Models\InterestAccrual;
use App\Modules\Finance\Models\RentalAccrual;
use App\Modules\Finance\Models\RentalContract;
use App\Modules\Finance\Models\Tenancy;
use App\Modules\Finance\Models\TenancyCharge;
use App\Modules\Finance\Services\BankFacilityService;
use App\Modules\Finance\Services\DepositKindInstaller;
use App\Modules\Finance\Services\DepositService;
use App\Modules\Finance\Services\InsuranceClaimService;
use App\Modules\Finance\Services\InsuranceService;
use App\Modules\Finance\Services\InterestAccrualService;
use App\Modules\Finance\Services\LoanSchedule;
use App\Modules\Finance\Services\RentalAccrualService;
use App\Modules\Finance\Services\RentalContractService;
use App\Modules\Finance\Services\TenancyService;
use App\Modules\MasterData\Models\Person;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ⛔ Accounts-এর পর্দা থেকে অর্থের ভাউচার বাতিল, অথচ অর্থের কাগজ আগের মতো — লাইন-ধরে নিরীক্ষা, ১৩ অক্টোবর ২০২৬
 * ([[UndoThePaperOfACancelledVoucher]]; আগের ধাপ [[AVoucherCancelledInAccountsLeftItsPaperConfirmedTest]])।
 *
 * ⭐ দাবি:
 *   · সুদ জমার ভাউচার বাতিল → তার উল্টো দাখিলাও বাতিল, সারি নেই, মাসটা আবার বসে; খাতায় ২১৪৫/৫৩১০ কেবল যা সত্যি
 *   · উল্টো দাখিলাটাই বাতিল → সারি আবার "না-উল্টানো", পরের চালে আবার উল্টায়
 *   · না-দেওয়া মাসের প্রদেয় ভাড়া বাতিল → মাসটা আবার বসে; দেওয়া মাসেরটা বাতিল হয় না
 *   · ভাড়াটের মাসের দাবি বাতিল → মাসটা আবার দাবি করা যায়
 *   · জমার ভাউচার, ভাড়ার চুক্তির জামানতের ভাউচার, বন্ধ দাবির রসিদ — কাগজ নিজের অঙ্ক রাখে, Accounts থেকে বাতিল হয় না
 *     (খাতা নড়ে না, কারণ বাংলায়); জমার নিজের বাতিল তবু চলে
 *   · দাবির বন্ধের দাখিলা বাতিল → দাবি আবার খোলা
 */
final class AFinanceVoucherCancelledInAccountsLeftItsMonthBehindTest extends TestCase
{
    use PutsMoneyInTheTill;
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
        app(CashTillService::class)->ensurePrimaryTill();

        Carbon::setTestNow('2026-11-15 10:00:00');
        $this->putMoneyIn($this->cash(), '5000000', '2026-07-01');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_cancelled_interest_accrual_takes_its_reversal_along_and_frees_the_month(): void
    {
        $this->loan();
        app(InterestAccrualService::class)->run(Carbon::parse('2026-08-01'));
        app(InterestAccrualService::class)->run(Carbon::parse('2026-09-01'));
        $august = InterestAccrual::query()->where('for_month', '2026-08-01')->sole();
        $this->assertNotNull($august->reversal_voucher_id, 'দৃশ্যটাই বানানো যায়নি — আগস্টের জমা উল্টায়নি');
        $september = InterestAccrual::query()->where('for_month', '2026-09-01')->sole();

        app(AccountsReversalService::class)->reverseVoucher(Voucher::acrossBranches()->findOrFail($august->voucher_id), auth()->user(), 'ভুল অঙ্ক');

        $this->assertSame(DocumentStatus::CANCELLED, Voucher::acrossBranches()->findOrFail($august->reversal_voucher_id)->status,
            '⛔ আগস্টের জমা বাতিল, অথচ তার উল্টো দাখিলা খাতায় একা — সেপ্টেম্বরের সুদ খরচ ততটা কম');
        $this->assertNull(InterestAccrual::query()->find($august->id), '⛔ বাতিল জমার সারি রয়ে গেল — আগস্ট আর কোনোদিন বসত না');
        $this->assertMoney((string) $september->amount, $this->balance(StandardChart::INTEREST_PAYABLE, credit: true),
            '⛔ প্রদেয় সুদে (২১৪৫) কেবল সেপ্টেম্বরের জমা থাকার কথা');

        $this->assertSame(1, app(InterestAccrualService::class)->run(Carbon::parse('2026-08-01'))['accrued'], '⛔ আগস্ট আবার বসানো গেল না');
    }

    public function test_a_cancelled_reversal_leaves_the_accrual_waiting_to_be_reversed_again(): void
    {
        $this->loan();
        app(InterestAccrualService::class)->run(Carbon::parse('2026-08-01'));
        app(InterestAccrualService::class)->run(Carbon::parse('2026-09-01'));
        $august = InterestAccrual::query()->where('for_month', '2026-08-01')->sole();

        app(VoucherService::class)->cancel(Voucher::acrossBranches()->findOrFail($august->reversal_voucher_id), 'ভুল দিন');

        $this->assertNull($august->fresh()->reversal_voucher_id, '⛔ উল্টো দাখিলা বাতিল, অথচ জমাটা "উল্টানো" — খাতায় চিরকাল থাকত');
        app(InterestAccrualService::class)->run(Carbon::parse('2026-10-01'));
        $this->assertNotNull($august->fresh()->reversal_voucher_id, '⛔ পরের চালে আগস্টের জমা আবার উল্টাল না');
    }

    public function test_an_unpaid_rent_month_cancelled_can_be_booked_again_but_a_paid_one_is_not_cancelled(): void
    {
        $contract = app(RentalContractService::class)->open([
            'counterparty' => 'Month Landlord', 'subject' => 'Godown', 'deposit_amount' => '0', 'monthly_rent' => '10000',
            'monthly_adjustment' => '0', 'term_months' => 36, 'starts_on' => '2026-08-01', 'rent_day' => 5,
        ]);
        app(RentalAccrualService::class)->run(Carbon::parse('2026-09-01'));
        app(RentalAccrualService::class)->run(Carbon::parse('2026-10-01'));
        $september = RentalAccrual::query()->whereDate('for_month', '2026-09-01')->sole();
        $october = RentalAccrual::query()->whereDate('for_month', '2026-10-01')->sole();

        app(VoucherService::class)->cancel(Voucher::acrossBranches()->findOrFail($september->voucher_id), 'ভুল মাস');
        $this->assertNull(RentalAccrual::query()->find($september->id), '⛔ বাতিল প্রদেয়ের সারি রয়ে গেল — মাসটা আর বসত না');
        $this->assertSame(1, app(RentalAccrualService::class)->run(Carbon::parse('2026-09-01'))['accrued'], '⛔ সেপ্টেম্বর আবার বসল না');

        app(RentalContractService::class)->adjustMonth($contract->fresh(), [
            'for_month' => '2026-10-01', 'rent' => '10000', 'from_deposit' => '0', 'money_account_id' => $this->cash()->id,
        ]);
        $this->assertRefusedInAccounts(Voucher::acrossBranches()->findOrFail($october->voucher_id), '⛔ দেওয়া মাসের প্রদেয় বাতিল হল — ২১৪১ উল্টো দিকে');
    }

    public function test_a_cancelled_tenancy_month_can_be_charged_again(): void
    {
        $person = Person::query()->create(['code' => 'TEN-1', 'name_en' => 'Shop Tenant', 'name_bn' => 'Shop Tenant', 'is_active' => true]);
        app(TenancyService::class)->open([
            'party_type' => 'person', 'party_id' => $person->id, 'tenant' => $person->name_en, 'monthly_rent' => '20000',
            'deposit_amount' => '0', 'term_months' => 24, 'starts_on' => '2026-09-01', 'money_account_id' => null,
        ]);
        $this->assertSame(Tenancy::ACTIVE, Tenancy::query()->sole()->status, 'দৃশ্যটাই বানানো যায়নি — ভাড়াটে চালু নয়');
        app(TenancyService::class)->charge(Carbon::parse('2026-10-01'));
        $charge = TenancyCharge::query()->sole();

        app(VoucherService::class)->cancel(Voucher::acrossBranches()->findOrFail($charge->voucher_id), 'ভুল দাবি');

        $this->assertNull(TenancyCharge::query()->find($charge->id), '⛔ বাতিল দাবির সারি রয়ে গেল — মাসটা আর কোনোদিন দাবি হত না');
        $this->assertSame(1, app(TenancyService::class)->charge(Carbon::parse('2026-10-01'))['charged']);
    }

    public function test_a_deposit_voucher_is_not_cancelled_from_accounts_but_the_deposit_itself_still_cancels(): void
    {
        app(DepositKindInstaller::class)->install();
        $deposit = app(DepositService::class)->open([
            'kind_id' => DepositKind::query()->where('code', 'FDR')->firstOrFail()->id, 'institution' => 'সোনালী ব্যাংক',
            'held_by' => Deposit::BUSINESS, 'principal' => '100000', 'return_word' => 'interest',
            'opened_on' => '2026-11-01', 'funded_from_account_id' => $this->cash()->id,
        ]);
        $voucher = Voucher::acrossBranches()->findOrFail($deposit->movements()->firstOrFail()->voucher_id);
        $this->assertTrue($voucher->isPosted(), 'দৃশ্যটাই বানানো যায়নি — জমার ভাউচার খাতায় নেই');

        $this->assertRefusedInAccounts($voucher, '⛔ জমার ভাউচার Accounts থেকে বাতিল হল — জমার আসল খাতা থেকে আলাদা');

        app(DepositService::class)->cancel($deposit->fresh(), 'ভুল জমা');
        $this->assertSame(Deposit::CANCELLED, $deposit->fresh()->status, '⛔ জমার নিজের বাতিলও আটকে গেল');
        $this->assertTrue($voucher->fresh()->isCancelled());
    }

    public function test_a_rent_deposit_voucher_is_not_cancelled_from_accounts(): void
    {
        $contract = app(RentalContractService::class)->open([
            'counterparty' => 'Deposit Landlord', 'subject' => 'Shop', 'deposit_amount' => '30000', 'monthly_rent' => '10000',
            'monthly_adjustment' => '0', 'term_months' => 12, 'starts_on' => '2026-10-01', 'rent_day' => 5,
            'money_account_id' => $this->cash()->id,
        ]);
        $voucher = Voucher::acrossBranches()->where('against_type', RentalContract::drillSourceType())->where('against_id', $contract->id)->firstOrFail();

        $this->assertRefusedInAccounts($voucher, '⛔ জামানতের ভাউচার Accounts থেকে বাতিল হল — চুক্তি এখনো ৩০,০০০ জামানত গোনে');
        $this->assertSame('30000', bcadd($contract->fresh()->depositLeft(), '0', 0));
    }

    public function test_a_cancelled_claim_close_reopens_the_claim_and_a_closed_claims_receipt_stays(): void
    {
        $claims = app(InsuranceClaimService::class);
        $insurer = Institution::query()->create(['company_id' => CompanyContext::id(), 'kind' => Institution::INSURANCE, 'name_en' => 'Insurer']);
        $policy = app(InsuranceService::class)->create([
            'institution_id' => $insurer->id, 'policy_no' => 'CL-1', 'covers' => InsurancePolicy::GOODS, 'subject' => 'Stock',
            'sum_insured' => '900000', 'premium' => '4500', 'starts_on' => '2026-09-01', 'ends_on' => '2027-08-31',
        ]);
        $claim = $claims->lodge($policy, [
            'incident_on' => '2026-11-10', 'claimed_on' => '2026-11-11', 'incident' => 'Water', 'claimed_amount' => '100000',
        ]);
        $claims->approve($claim->fresh(), ['approved_amount' => '80000', 'approved_on' => '2026-11-12', 'approval_ref' => 'GD/1']);
        $receipt = $claims->receive($claim->fresh(), [
            'money_account_id' => $this->cash()->id, 'amount' => '50000', 'received_on' => '2026-11-13', 'instrument_no' => 'CHQ-1',
        ])['voucher'];
        $claims->close($claim->fresh(), 'বাকিটা দেবে না', '2026-11-14');
        $this->assertSame(InsuranceClaim::SETTLED, $claim->fresh()->status, 'দৃশ্যটাই বানানো যায়নি — দাবি বন্ধ হয়নি');

        $this->assertRefusedInAccounts($receipt->fresh(), '⛔ বন্ধ দাবির রসিদ বাতিল হল — ১১৫২-এ ৫০,০০০ আটকে রইল, দাবি "নিষ্পন্ন"');

        app(VoucherService::class)->cancel(Voucher::acrossBranches()->findOrFail($claim->fresh()->close_voucher_id), 'ভুল বন্ধ');
        $fresh = $claim->fresh();
        $this->assertNull($fresh->closed_on, '⛔ বন্ধের দাখিলা বাতিল, অথচ দাবি এখনো বন্ধ');
        $this->assertSame(InsuranceClaim::PARTIAL, $fresh->status, '⛔ দাবি আবার খোলেনি — বাকি ৩০,০০০ আর পাওয়া যেত না');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function assertRefusedInAccounts(Voucher $voucher, string $why): void
    {
        $lines = LedgerEntry::query()->count();

        try {
            app(VoucherService::class)->cancel($voucher, 'ভুল');
            $this->fail($why);
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('cancel_reason', $e->errors(), $why);
        }

        $this->assertTrue($voucher->fresh()->isPosted(), $why);
        $this->assertSame($lines, LedgerEntry::query()->count(), '⛔ থামার আগে খাতায় উল্টো দাখিলা বসে গেল');
    }

    private function loan(): BankFacility
    {
        $liability = Account::query()->where('code', '2211')->firstOrFail();
        $loan = app(BankFacilityService::class)->open([
            'kind' => BankFacility::TERM, 'bank' => 'Sonali Bank', 'sanctioned_on' => '2026-07-08', 'first_instalment_on' => '2026-08-07',
            'limit_amount' => '1200000', 'interest_rate' => '12', 'instalments' => 12,
            'instalment_amount' => LoanSchedule::instalment('1200000', '12', 12),
            'liability_account_id' => $liability->id,
        ]);

        $vouchers = app(VoucherService::class);
        $vouchers->post($vouchers->create([
            'type' => Voucher::JOURNAL, 'trx_date' => '2026-07-08', 'narration' => 'loan drawn',
            'against_type' => BankFacility::drillSourceType(), 'against_id' => $loan->id,
        ], [
            ['account_id' => StandardChart::find(StandardChart::OWNER_CAPITAL)->id, 'debit' => '1200000', 'credit' => '0'],
            ['account_id' => $liability->id, 'debit' => '0', 'credit' => '1200000'],
        ]));

        return $loan;
    }

    private function balance(string $code, bool $credit = false): string
    {
        $row = LedgerEntry::query()->where('company_id', $this->company->id)->where('account_id', StandardChart::find($code)->id)
            ->selectRaw('COALESCE(SUM(debit), 0) as d, COALESCE(SUM(credit), 0) as c')->first();

        return $credit ? bcsub((string) $row->c, (string) $row->d, 4) : bcsub((string) $row->d, (string) $row->c, 4);
    }

    private function assertMoney(string $expected, string $actual, string $message): void
    {
        $this->assertSame(0, bccomp($expected, $actual, 2), "{$message}: চাই {$expected}, এল {$actual}");
    }

    private function cash(): Account
    {
        return Account::query()->money()->postable()->active()->where('money_kind', Account::CASH)->orderBy('code')->firstOrFail();
    }
}
