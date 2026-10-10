<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Loan;
use App\Modules\Accounts\Models\LoanInstalment;
use App\Modules\Accounts\Models\LoanMovement;
use App\Modules\Accounts\Services\AccountsSignature;
use App\Modules\Accounts\Services\LoanSchedule;
use App\Modules\Accounts\Services\LoanService;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ঋণের টাকা সই ছাড়া নড়ে না — অ্যাকাউন্টসের অডিট ⛔ (সমন্বয়ক, ১০ অক্টোবর ২০২৬)।
 *
 * ⛔ আগে তোলা, কিস্তি, শোধ আর সুদ সরাসরি খাতায় বসত; অন্য সব টাকার পথ সইয়ের পেছনে থাকলেও ঋণের পর্দা ছিল না।
 *
 * দাবি:
 *   ছক থাকলে CC-তে তোলা থামে (খাতায় কিছু নেই), শেষ সইয়ে একবারই খাতায়; পরের একই অঙ্কের তোলায় আবার সই লাগে — আগের
 *   সই নতুনটাকে ঢাকে না; "না" এলে সারি প্রত্যাখ্যাত, খাতা অক্ষত; কিস্তি, শোধ আর সুদও থামে, আর কিস্তি শেষ সইয়ে পরিশোধ হয়;
 *   ছক না থাকলে (UB-এর মতো সব বন্ধ) আজকের মতো এখনই খাতায়।
 */
final class ALoanMovesMoneyOnlyOnceSignedTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $signer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        app(StandardChart::class)->install();
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->signer = User::factory()->create(['current_company_id' => $this->company->id, 'is_active' => true]);
        $this->signer->companies()->attach($this->company->id, ['is_active' => true]);
        $this->signer->givePermissionTo(['approval.view', 'approval.decide']);
    }

    public function test_a_draw_waits_for_its_signature_and_the_next_draw_asks_again(): void
    {
        $this->flow(AccountsSignature::LOAN_DRAW);
        $loan = $this->cc();
        $loans = app(LoanService::class);

        $first = $loans->drawDown($loan, '50000', $this->money()->id);
        $this->assertSame(LoanMovement::AWAITING, $first->fresh()->status, '⛔ ছক থাকা সত্ত্বেও তোলা সই ছাড়াই এগোল।');
        $this->assertSame(0, $this->posted($first), '⛔ সইয়ের আগেই খাতায় টাকা বসেছে।');

        $this->approveFor($first);
        $this->assertSame(LoanMovement::POSTED, $first->fresh()->status, '⛔ শেষ সইয়ের পরেও তোলা খাতায় ওঠেনি।');
        $this->assertSame(2, $this->posted($first));
        $this->assertSame(0, bccomp($loan->fresh()->outstanding(), '50000', 4));

        // ⛔ একই অঙ্কের পরের তোলা — আগের সই একে ঢাকে না
        $second = $loans->drawDown($loan, '50000', $this->money()->id);
        $this->assertSame(LoanMovement::AWAITING, $second->fresh()->status, '⛔ আগের সই দিয়েই পরের ৫০,০০০ তোলা পার হলো।');

        // ⓘ "না" — সারি প্রত্যাখ্যাত, খাতা অক্ষত
        app(ApprovalEngine::class)->reject($this->approvalOf($second), $this->signer, 'লাগবে না');
        $this->assertSame(LoanMovement::REJECTED, $second->fresh()->status);
        $this->assertSame(0, $this->posted($second));
        $this->assertSame(0, bccomp($loan->fresh()->outstanding(), '50000', 4), '⛔ প্রত্যাখ্যাত তোলা ঋণ বাড়িয়েছে।');
    }

    public function test_repay_interest_and_an_instalment_wait_too(): void
    {
        foreach ([AccountsSignature::LOAN_REPAY, AccountsSignature::LOAN_INTEREST, AccountsSignature::LOAN_INSTALMENT] as $action) {
            $this->flow($action);
        }
        $loans = app(LoanService::class);

        $cc = $this->cc();
        $loans->drawDown($cc, '80000', $this->money()->id);

        $repay = $loans->repay($cc, '10000', $this->money()->id);
        $interest = $loans->chargeInterest($cc, '900');
        $this->assertSame([LoanMovement::AWAITING, LoanMovement::AWAITING], [$repay->fresh()->status, $interest->fresh()->status],
            '⛔ শোধ বা সুদ সই ছাড়াই খাতায় বসল।');
        $this->assertSame(0, $this->posted($repay) + $this->posted($interest));

        $term = $this->term();
        $due = $term->instalments()->orderBy('no')->firstOrFail();
        $after = $loans->payInstalment($due, $this->money()->id);
        $this->assertFalse($after->fresh()->isPaid(), '⛔ কিস্তি সই ছাড়াই পরিশোধ হলো।');
        $this->assertFalse(LedgerEntry::query()->where('source_type', LoanInstalment::drillSourceType())->where('source_id', $due->id)->exists());

        app(ApprovalEngine::class)->approve(
            Approval::query()->where('approvable_type', $due->getMorphClass())->where('approvable_id', $due->id)->firstOrFail(),
            $this->signer, 'ঠিক আছে');
        $this->assertTrue($due->fresh()->isPaid(), '⛔ শেষ সইয়ের পরেও কিস্তি পরিশোধ হয়নি।');
    }

    public function test_with_no_flow_the_money_moves_at_once_as_today(): void
    {
        ApprovalFlow::query()->where('module', AccountsSignature::MODULE)->update(['is_active' => false]);
        $loan = $this->cc();

        $moved = app(LoanService::class)->drawDown($loan, '25000', $this->money()->id);

        $this->assertSame(LoanMovement::POSTED, $moved->fresh()->status, '⛔ ছক নেই, তবু তোলা থেমে রইল — আজকের আচরণ ভাঙল।');
        $this->assertSame(0, bccomp($loan->fresh()->outstanding(), '25000', 4));
    }

    private function flow(string $action): void
    {
        $flow = ApprovalFlow::query()->updateOrCreate(
            ['company_id' => $this->company->id, 'module' => AccountsSignature::MODULE, 'action' => $action],
            ['code' => 'LN-'.strtoupper(substr($action, 5, 4)), 'threshold_amount' => '0', 'is_active' => true],
        );
        $flow->steps()->delete();
        ApprovalFlowStep::create([
            'approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => ApprovalFlowStep::BY_USER,
            'approver_id' => $this->signer->id, 'requires_all' => false,
        ]);
    }

    private function approvalOf(LoanMovement $movement): Approval
    {
        return Approval::query()->where('approvable_type', $movement->getMorphClass())->where('approvable_id', $movement->id)->latest('id')->firstOrFail();
    }

    private function approveFor(LoanMovement $movement): void
    {
        app(ApprovalEngine::class)->approve($this->approvalOf($movement), $this->signer, 'ঠিক আছে');
    }

    private function posted(LoanMovement $movement): int
    {
        return LedgerEntry::query()->where('source_type', LoanMovement::drillSourceType())->where('source_id', $movement->id)->count();
    }

    private function cc(): Loan
    {
        return app(LoanService::class)->create(data: [
            'lender' => 'City Bank', 'kind' => Loan::CC, 'sanctioned' => '500000', 'interest_rate' => '13.5', 'start_date' => '2026-07-01',
            'principal_account_id' => Account::query()->where('code', '2210')->value('id'),
            'interest_account_id' => Account::query()->where('type', Account::EXPENSE)->postable()->orderBy('code')->value('id'),
        ]);
    }

    private function term(): Loan
    {
        return app(LoanService::class)->create(data: [
            'lender' => 'Sonali Bank', 'kind' => Loan::TERM, 'interest_method' => LoanSchedule::REDUCING, 'sanctioned' => '120000',
            'interest_rate' => '12', 'tenure_months' => 12, 'start_date' => '2026-07-01', 'first_instalment_on' => '2026-08-01',
            'principal_account_id' => Account::query()->where('code', '2210')->value('id'),
            'interest_account_id' => Account::query()->where('type', Account::EXPENSE)->postable()->orderBy('code')->value('id'),
        ]);
    }

    private function money(): Account
    {
        return Account::query()->firstOrCreate(['code' => '1102-LOANSIGN'], [
            'company_id' => $this->company->id, 'parent_id' => Account::query()->where('code', StandardChart::BANK)->value('id'),
            'name_en' => 'Loan sign bank', 'name_bn' => 'ঋণের সইয়ের ব্যাংক', 'type' => Account::ASSET, 'nature' => Account::DEBIT,
            'is_group' => false, 'money_kind' => Account::BANK,
        ]);
    }
}
