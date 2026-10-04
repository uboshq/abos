<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Branch;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Loan;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\LoanService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\BankFacility;
use App\Modules\Finance\Models\CapitalEntry;
use App\Modules\Finance\Models\Deposit;
use App\Modules\Finance\Models\DepositKind;
use App\Modules\Finance\Models\HandLoanAccount;
use App\Modules\Finance\Models\RentalContract;
use App\Modules\Finance\Services\BankFacilityService;
use App\Modules\Finance\Services\CapitalService;
use App\Modules\Finance\Services\DepositKindInstaller;
use App\Modules\Finance\Services\DepositService;
use App\Modules\Finance\Services\HandLoanService;
use App\Modules\Finance\Services\ProfitDistribution;
use App\Modules\Finance\Services\RentalContractService;
use App\Modules\MasterData\Models\Person;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * অর্থের পর্দা দিয়ে টাকা নড়ত, কেউ সই না দিয়েই — অডিট গ১, গ১৪, গ১৫, ম২৩ (৪ অক্টোবর ২০২৬)।
 *
 * ── ⛔ কী ছিল ─────────────────────────────────────────────────────────
 * হিসাবের ভাউচারে সই লাগত, অথচ একই টাকা অর্থের পর্দা দিয়ে নড়লে লাগত না: হাতধার, জমা (খোলা, কিস্তি, ভাঙা),
 * ভাড়ার জামানত, চলতি ঋণের খোলা বকেয়া, লাভকে মূলধনে নেওয়া — সবগুলো সাথে সাথে খাতায়। মূলধন পোস্টের পুরনো
 * ঠিকানা যেকোনো খাতে সই ছাড়া বসাত; হাতধার আর জমার "টাকার খাত" যেকোনো খাত হতে পারত; ঋণে বাঁধা জমা ভাঙা যেত।
 * মালিকের নিয়ম: *যেকোনো টাকা, যেকোনো অঙ্ক — সই লাগে* (২৭ সেপ্টেম্বর ২০২৬)।
 *
 * ── ⭐ এখন ─────────────────────────────────────────────────────────────
 * ছক থাকলে খাতা নড়ে না, অবস্থা বদলায় না; শেষ সই পড়লে নিজে থেকে বসে ([[FinishTheFinancePaperOnTheLastSignature]]),
 * "না" হলে বাতিল। ছক না থাকলে আগের মতো সাথে সাথে (বাকি সব পুরনো দাবি সেটাই মাপে)।
 */
final class FinanceMoneyMovedWithNobodyToSignTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private Company $company;

    private User $signer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        $this->signer = User::query()->where('email', 'accounts@abos.test')->firstOrFail();

        app(StandardChart::class)->install();
        app(DepositKindInstaller::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();

        $this->putMoneyIn($this->cash(), '5000000', now()->subDays(5)->toDateString());
    }

    // ── হাতধার ─────────────────────────────────────────────────────────

    public function test_a_hand_loan_waits_for_its_signature_and_posts_on_the_last_one(): void
    {
        $this->flow('hand_loan');
        $karim = $this->karim();
        $before = $this->net(StandardChart::HAND_LOAN);

        $this->lend($karim, '20000');

        $this->assertSame(0, bccomp($this->net(StandardChart::HAND_LOAN), $before, 4), '⛔ সই ছাড়াই হাতধার খাতায় বসেছে।');
        $this->assertSame(0, bccomp(app(HandLoanService::class)->balanceOf($karim), '0', 4), '⛔ সইয়ের আগেই করিমের নামে ধার গোনা হচ্ছে।');

        $this->sign('hand_loan');

        $this->assertSame(0, bccomp(bcsub($this->net(StandardChart::HAND_LOAN), $before, 4), '20000', 4), '⛔ শেষ সই পড়ল, অথচ ধার খাতায় বসেনি।');
        $this->assertSame(0, bccomp(app(HandLoanService::class)->balanceOf($karim), '20000', 4));
    }

    public function test_a_refused_hand_loan_is_cancelled_and_never_counts(): void
    {
        $this->flow('hand_loan');
        $karim = $this->karim();

        $this->lend($karim, '20000');
        $this->refuse('hand_loan');

        $this->assertSame(0, bccomp(app(HandLoanService::class)->balanceOf($karim), '0', 4), '⛔ "না" পাওয়া ধার করিমের নামে গোনা হচ্ছে।');
        $this->assertSame(DocumentStatus::CANCELLED, $karim->movements()->firstOrFail()->voucher->status, '⛔ "না" পাওয়া ধারের ভাউচার বাতিল হয়নি।');
    }

    // ── জমা ───────────────────────────────────────────────────────────

    public function test_a_deposit_is_not_active_until_its_signature(): void
    {
        $this->flow('deposit');
        $before = $this->net(StandardChart::DEPOSITS_AND_INVESTMENTS);

        $fd = $this->fd('500000');

        $this->assertSame(Deposit::AWAITING, $fd->status, '⛔ সই ছাড়াই জমাটা চালু হয়ে গেছে।');
        $this->assertSame(0, bccomp($this->net(StandardChart::DEPOSITS_AND_INVESTMENTS), $before, 4), '⛔ সই ছাড়াই জমার টাকা খাতায়।');

        $this->sign('deposit');

        $this->assertSame(Deposit::ACTIVE, $fd->fresh()->status, '⛔ শেষ সই পড়ল, অথচ জমা চালু হয়নি।');
        $this->assertSame(0, bccomp(bcsub($this->net(StandardChart::DEPOSITS_AND_INVESTMENTS), $before, 4), '500000', 4));
    }

    public function test_an_instalment_grows_the_principal_only_after_signing(): void
    {
        $dps = $this->fd('10000', 'DPS', ['instalment_amount' => '5000', 'instalment_day' => 5]);
        $this->flow('deposit');

        app(DepositService::class)->instalment($dps, [
            'amount' => '5000',
            'moved_on' => now()->toDateString(),
            'money_account_id' => $this->cash()->id,
        ]);

        $this->assertSame(0, bccomp((string) $dps->fresh()->principal, '10000', 4), '⛔ সইয়ের আগেই কিস্তি মূলধনে যোগ হয়েছে।');

        $this->sign('deposit');

        $this->assertSame(0, bccomp((string) $dps->fresh()->principal, '15000', 4), '⛔ শেষ সই পড়ল, অথচ কিস্তি মূলধনে যোগ হয়নি।');
    }

    public function test_a_deposit_pledged_to_a_live_loan_cannot_be_broken(): void
    {
        $loan = app(LoanService::class)->create(
            data: [
                'lender' => 'Sonali Bank',
                'kind' => Loan::TERM,
                'sanctioned' => '300000',
                'interest_rate' => '12',
                'tenure_months' => 12,
                'interest_method' => 'flat',
                'start_date' => now()->subDays(3)->toDateString(),
                'principal_account_id' => Account::query()->where('code', '2210')->firstOrFail()->id,
                'interest_account_id' => Account::query()->where('type', Account::EXPENSE)->postable()->orderBy('code')->firstOrFail()->id,
            ],
            intoAccountId: $this->cash()->id,
        );

        $fd = $this->fd('200000', 'FDR', ['pledged_to_loan_id' => $loan->id]);
        $this->assertTrue($fd->isLocked(), 'দৃশ্যটাই বানানো যায়নি — জমাটা ঋণে বাঁধা নয়।');

        $this->assertRefused(fn () => app(DepositService::class)->close($fd, [
            'amount' => '200000',
            'moved_on' => now()->toDateString(),
            'money_account_id' => $this->cash()->id,
        ]), 'status', '⛔ ঋণে বাঁধা জমা ভাঙা গেল — ব্যাংকের জামানত খাতায় নগদ হয়ে ফিরল।');

        $this->assertSame(Deposit::ACTIVE, $fd->fresh()->status);
    }

    // ── ভাড়া ───────────────────────────────────────────────────────────

    public function test_rent_money_waits_for_its_signature(): void
    {
        $this->flow('rental');
        $before = $this->net(StandardChart::SECURITY_DEPOSIT);

        $contract = app(RentalContractService::class)->open([
            'counterparty' => 'Godown Owner',
            'deposit_amount' => '120000',
            'monthly_rent' => '10000',
            'monthly_adjustment' => '0',
            'term_months' => 12,
            'starts_on' => now()->toDateString(),
            'money_account_id' => $this->cash()->id,
        ]);

        $this->assertSame(RentalContract::AWAITING, $contract->status, '⛔ জামানতের সই ছাড়াই চুক্তি চালু।');
        $this->assertSame(StandardChart::SECURITY_DEPOSIT, (string) $contract->account->code, 'দৃশ্যটাই বানানো যায়নি — জামানত অন্য খাতে।');
        $this->assertSame(0, bccomp($this->net(StandardChart::SECURITY_DEPOSIT), $before, 4), '⛔ সই ছাড়াই জামানতের টাকা খাতায়।');

        $this->sign('rental');

        $this->assertSame(RentalContract::ACTIVE, $contract->fresh()->status, '⛔ শেষ সই পড়ল, অথচ চুক্তি চালু হয়নি।');

        app(RentalContractService::class)->addToDeposit($contract->fresh(), [
            'amount' => '30000',
            'money_account_id' => $this->cash()->id,
        ]);

        $this->assertSame(0, bccomp((string) $contract->fresh()->deposit_amount, '120000', 4), '⛔ সইয়ের আগেই জামানত বেড়েছে।');

        $this->sign('rental');

        $this->assertSame(0, bccomp((string) $contract->fresh()->deposit_amount, '150000', 4), '⛔ শেষ সই পড়ল, অথচ জামানত বাড়েনি।');
    }

    // ── চলতি ঋণের খোলা বকেয়া ─────────────────────────────────────────────

    public function test_a_running_loans_opening_waits_for_its_signature(): void
    {
        $this->flow('bank_facility');
        $service = app(BankFacilityService::class);
        $before = $this->net('2211');

        $facility = $service->open([
            'kind' => BankFacility::TERM,
            'bank' => 'Sonali Bank',
            'sanctioned_on' => now()->subMonths(2)->toDateString(), // ⓘ খাতার বছরের ভেতরে — খোলা বকেয়া অনুমোদনের তারিখে বসে
            'limit_amount' => '2000000',
            'interest_rate' => '12',
            'instalments' => 36,
            'opening_drawn' => '1500000',
            'liability_account_id' => Account::query()->where('code', '2211')->firstOrFail()->id,
        ]);

        $service->openingFor($facility, '1500000');

        $this->assertSame(0, bccomp($this->net('2211'), $before, 4), '⛔ সই ছাড়াই চলতি ঋণের বকেয়া খাতায়।');

        $this->sign('bank_facility');

        $this->assertSame(0, bccomp(bcsub($this->net('2211'), $before, 4), '-1500000', 4), '⛔ শেষ সই পড়ল, অথচ খোলা বকেয়া খাতায় বসেনি।');
    }

    // ── লাভ থেকে মূলধন ────────────────────────────────────────────────

    public function test_capitalising_profit_waits_for_its_signature_and_cannot_be_asked_twice(): void
    {
        $partner = $this->partner('SIGN-CAP');
        app(ProfitDistribution::class)->declare(['profit' => '10000', 'trx_date' => now()->toDateString()]);
        $this->flow('capitalise');

        $before = $this->net(StandardChart::OWNER_CAPITAL);

        $entries = app(ProfitDistribution::class)->capitalise(['trx_date' => now()->toDateString()]);

        $this->assertSame(CapitalEntry::DRAFT, $entries[0]->status, '⛔ সই ছাড়াই লাভ মূলধনে উঠেছে।');
        $this->assertSame(0, bccomp($this->net(StandardChart::OWNER_CAPITAL), $before, 4), '⛔ সই ছাড়াই মূলধন খাতা নড়েছে।');

        // ⛔ অপেক্ষার সময় একই পাওনা আরেকবার মূলধনে নয়
        $this->assertRefused(fn () => app(ProfitDistribution::class)->capitalise(['trx_date' => now()->toDateString()]),
            'trx_date', '⛔ সইয়ের অপেক্ষায় থাকতেই একই লাভ দ্বিতীয়বার মূলধনে চাওয়া গেল।');

        $this->sign('capitalise');

        $this->assertSame(CapitalEntry::POSTED, $entries[0]->fresh()->status, '⛔ শেষ সই পড়ল, অথচ সারি খসড়াই রইল।');
        $this->assertSame(0, bccomp(app(ProfitDistribution::class)->outstandingFor($partner->id), '0', 4));
    }

    // ── মূলধন পোস্টের পুরনো দরজা ────────────────────────────────────────

    public function test_the_old_capital_post_door_is_gone_and_the_service_takes_only_money(): void
    {
        $this->assertFalse(Route::has('finance.capital.post'), '⛔ সই ছাড়া মূলধন বসানোর পুরনো ঠিকানা এখনো খোলা।');

        $entry = CapitalEntry::query()->create([
            'branch_id' => Branch::query()->firstOrFail()->id,
            'document_no' => 'CAP-DOOR',
            'person_id' => $this->person('Door Partner')->id,
            'contributor_type' => CapitalEntry::PARTNER,
            'entry_type' => CapitalEntry::CONTRIBUTION,
            'trx_date' => now()->toDateString(),
            'amount' => '50000',
            'status' => CapitalEntry::DRAFT,
        ]);

        $this->assertRefused(fn () => app(CapitalService::class)->post($entry, $this->notMoney()),
            'received_into_account_id', '⛔ আয়ের খাতে মূলধন "এল" — নগদ বাড়েনি, অথচ মালিকের অংশ বাড়ল।');
    }

    // ── টাকার খাত ──────────────────────────────────────────────────────

    public function test_a_hand_loan_and_a_deposit_take_only_a_money_account(): void
    {
        $this->assertRefused(fn () => $this->lend($this->karim(), '1000', $this->notMoney()),
            'money_account_id', '⛔ আয়ের খাত থেকে হাতধার দেওয়া গেল।');

        $this->assertRefused(fn () => $this->fd('1000', 'FDR', ['funded_from_account_id' => $this->notMoney()->id]),
            'funded_from_account_id', '⛔ আয়ের খাত থেকে জমা খোলা গেল।');
    }

    // ── সহায়ক ───────────────────────────────────────────────────────────

    /** `finance.<কাজ>` — হিসাবরক্ষক সই দেন; মালিক বানান ([[AProfitWasSharedWithNobodyToSignTest]]-এর ছাঁচ)। */
    private function flow(string $action): void
    {
        $flow = ApprovalFlow::query()->create(['module' => 'finance', 'action' => $action, 'is_active' => true]);

        ApprovalFlowStep::query()->create([
            'approval_flow_id' => $flow->id,
            'level' => 1,
            'approver_type' => ApprovalFlowStep::BY_USER,
            'approver_id' => $this->signer->id,
        ]);

        $this->app->forgetInstance(ApprovalEngine::class);
    }

    private function pending(string $action): Approval
    {
        return Approval::query()->where('status', Approval::PENDING)->where('action', $action)->latest('id')->firstOrFail();
    }

    private function sign(string $action): void
    {
        app(ApprovalEngine::class)->approve($this->pending($action), $this->signer);
    }

    private function refuse(string $action): void
    {
        app(ApprovalEngine::class)->reject($this->pending($action), $this->signer, 'এখন নয়');
    }

    private function assertRefused(callable $what, string $field, string $why): void
    {
        try {
            $what();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors(), $why.' (অন্য ঘরে আটকেছে: '.implode(', ', array_keys($e->errors())).')');

            return;
        }

        $this->fail($why);
    }

    /** একটা খাতের নিট (ডেবিট − ক্রেডিট) — খাতা থেকেই। */
    private function net(string $code): string
    {
        $account = Account::query()->where('code', $code)->firstOrFail();

        $row = LedgerEntry::query()
            ->where('company_id', $this->company->id)
            ->where('account_id', $account->id)
            ->selectRaw('COALESCE(SUM(debit), 0) as d, COALESCE(SUM(credit), 0) as c')
            ->first();

        return bcsub((string) $row->d, (string) $row->c, 4);
    }

    private function cash(): Account
    {
        return Account::query()->money()->postable()->active()->orderBy('code')->firstOrFail();
    }

    /** পোস্টযোগ্য, চালু — কিন্তু টাকার খাত নয় */
    private function notMoney(): Account
    {
        return StandardChart::find(StandardChart::INTEREST_INCOME);
    }

    private function person(string $name): Person
    {
        return Person::query()->firstOrCreate(
            ['company_id' => $this->company->id, 'name_en' => $name],
            ['code' => 'P-'.mb_substr(md5($name), 0, 6), 'name_bn' => $name, 'is_active' => true],
        );
    }

    private function karim(): HandLoanAccount
    {
        return app(HandLoanService::class)->open(['person_id' => $this->person('Karim Lender')->id]);
    }

    private function lend(HandLoanAccount $account, string $amount, ?Account $from = null): void
    {
        app(HandLoanService::class)->move($account, [
            'direction' => 'out',
            'amount' => $amount,
            'moved_on' => now()->toDateString(),
            'money_account_id' => ($from ?? $this->cash())->id,
        ]);
    }

    /** @param  array<string, mixed>  $extra */
    private function fd(string $amount, string $kind = 'FDR', array $extra = []): Deposit
    {
        return app(DepositService::class)->open(array_merge([
            'kind_id' => DepositKind::query()->where('code', $kind)->firstOrFail()->id,
            'institution' => 'সোনালী ব্যাংক',
            'held_by' => Deposit::BUSINESS,
            'principal' => $amount,
            'return_word' => 'interest',
            'opened_on' => now()->toDateString(),
            'funded_from_account_id' => $this->cash()->id,
        ], $extra));
    }

    /** একজন অংশীদার, পোস্ট হওয়া মূলধনসহ — লাভ ঘোষণার জন্য */
    private function partner(string $code): Person
    {
        $person = $this->person($code);

        CapitalEntry::query()->create([
            'branch_id' => Branch::query()->firstOrFail()->id,
            'document_no' => 'CAP-'.$code,
            'person_id' => $person->id,
            'contributor_type' => CapitalEntry::PARTNER,
            'entry_type' => CapitalEntry::CONTRIBUTION,
            'in_kind' => CapitalEntry::CASH,
            'trx_date' => now()->subMonth()->toDateString(),
            'amount' => '100000',
            'status' => CapitalEntry::POSTED,
        ]);

        return $person;
    }
}
