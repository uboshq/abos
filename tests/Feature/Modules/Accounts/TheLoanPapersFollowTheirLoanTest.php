<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Loan;
use App\Modules\Accounts\Models\LoanInstalment;
use App\Modules\Accounts\Services\AccountsSignature;
use App\Modules\Accounts\Services\LoanSchedule;
use App\Modules\Accounts\Services\LoanService;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ঋণের কাগজ ঋণকে অনুসরণ করে — খোঁজা, শাখা, সীমা (a6-র Accounts নিরীক্ষা, fe মারফত, ১৩ অক্টোবর ২০২৬)।
 */
final class TheLoanPapersFollowTheirLoanTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $signer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        app(StandardChart::class)->install();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        $this->signer = User::factory()->create(['current_company_id' => $this->company->id, 'is_active' => true]);
        $this->signer->companies()->attach($this->company->id, ['is_active' => true]);
        $this->signer->givePermissionTo(['approval.view', 'approval.decide']);
    }

    /**
     * ⛔ শেষ সইয়ে কিস্তি পরিশোধ হয় — ঋণটা যে শাখারই হোক, সইকারী যে শাখাই দেখান। ⚠️ আগে `$instalment->loan` সম্পর্কটা ঋণকে
     * সইকারীর শাখার দেয়াল দিয়ে খুঁজত; কোম্পানি-স্তরের (শাখাহীন) ঋণ এক শাখা দেখা সইকারীর হাতে "নেই" — কিস্তি চিরকাল অপেক্ষায়।
     */
    public function test_a_signed_instalment_of_a_branchless_loan_is_paid_whatever_branch_the_signer_looks_at(): void
    {
        $this->flow(AccountsSignature::LOAN_INSTALMENT);
        $term = $this->term();
        Loan::acrossBranches()->whereKey($term->id)->update(['branch_id' => null]);

        $due = $term->instalments()->orderBy('no')->firstOrFail();
        app(LoanService::class)->payInstalment($due, $this->money()->id);
        $this->assertFalse($due->fresh()->isPaid(), 'দৃশ্যটাই বানানো যায়নি — কিস্তি সইয়ের অপেক্ষায় নেই');

        $this->lookAtOneBranch($this->signer);
        app(ApprovalEngine::class)->approve(
            Approval::query()->where('approvable_type', $due->getMorphClass())->where('approvable_id', $due->id)->firstOrFail(),
            $this->signer->fresh(), 'ঠিক আছে');

        $this->assertTrue(LoanInstalment::query()->withoutGlobalScopes()->findOrFail($due->id)->isPaid(),
            '⛔ শাখাহীন ঋণ, এক শাখা দেখা সইকারী — শেষ সইয়ের পরেও কিস্তি পরিশোধ হয়নি।');
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    private function lookAtOneBranch(User $user, ?Branch $branch = null): void
    {
        $branch ??= Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)
            ->whereKeyNot((int) $this->company->defaultBranch()->id)->orderBy('id')->firstOrFail();
        $user->forceFill(['view_all_branches' => false, 'current_branch_id' => $branch->id])->save();
        CompanyContext::set($this->company->id, $branch->id);
        app(DataScope::class)->forget();
        $this->actingAs($user->fresh());
    }

    private function flow(string $action): void
    {
        $flow = ApprovalFlow::query()->updateOrCreate(
            ['company_id' => $this->company->id, 'module' => AccountsSignature::MODULE, 'action' => $action],
            ['code' => 'LF-'.strtoupper(substr($action, 5, 4)), 'threshold_amount' => '0', 'is_active' => true],
        );
        $flow->steps()->delete();
        ApprovalFlowStep::create([
            'approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => ApprovalFlowStep::BY_USER,
            'approver_id' => $this->signer->id, 'requires_all' => false,
        ]);
        $this->app->forgetInstance(ApprovalEngine::class);
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
        return Account::query()->firstOrCreate(['code' => '1102-LOANFOLLOW'], [
            'company_id' => $this->company->id, 'parent_id' => Account::query()->where('code', StandardChart::BANK)->value('id'),
            'name_en' => 'Loan follow bank', 'name_bn' => 'ঋণের ব্যাংক', 'type' => Account::ASSET, 'nature' => Account::DEBIT,
            'is_group' => false, 'money_kind' => Account::BANK,
        ]);
    }
}
