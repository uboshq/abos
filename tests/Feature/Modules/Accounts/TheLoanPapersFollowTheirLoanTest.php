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

    /**
     * ⛔ ঋণের দাখিলা ঋণের শাখায় — যিনি চাপলেন তাঁর হেডারে যে শাখাই থাকুক। ⚠️ আগে জমা, সুদ আর কিস্তির খাতার সারি বসত ক্লিককারীর
     * শাখায়: ঋণ এক শাখায়, তার শোধ আরেক শাখায় — শাখা ধরে দায় কোনোদিন মিলত না।
     */
    public function test_a_repayment_and_an_instalment_post_in_the_loans_own_branch(): void
    {
        $home = (int) $this->company->defaultBranch()->id;
        $cc = $this->cc();
        $term = $this->term();
        $this->assertSame($home, (int) $cc->branch_id, 'দৃশ্যটাই বানানো যায়নি — ঋণ প্রধান শাখার নয়');
        app(LoanService::class)->drawDown($cc, '50000', $this->money()->id);

        $this->lookAtOneBranch($this->owner);
        $repay = app(LoanService::class)->repay(Loan::acrossBranches()->findOrFail($cc->id), '10000', $this->money()->id);
        $due = $term->instalments()->orderBy('no')->firstOrFail();
        app(LoanService::class)->payInstalment($due, $this->money()->id);

        $branches = fn (string $type, int $id) => \App\Models\LedgerEntry::query()->withoutGlobalScopes()
            ->where('source_type', $type)->where('source_id', $id)->distinct()->pluck('branch_id')->map(fn ($b) => (int) $b)->all();
        $this->assertSame([$home], $branches(\App\Modules\Accounts\Models\LoanMovement::drillSourceType(), (int) $repay->id),
            '⛔ ঋণের শোধ ক্লিককারীর হেডারের শাখায় বসল।');
        $this->assertSame([$home], $branches(LoanInstalment::drillSourceType(), (int) $due->id),
            '⛔ কিস্তির দাখিলা ক্লিককারীর হেডারের শাখায় বসল।');
        $this->assertSame($home, (int) $repay->fresh()->branch_id, '⛔ শোধের সারিটাই অন্য শাখার।');
    }

    /**
     * ⛔ কিস্তিতে বাকি আসলের বেশি দেওয়া যায় না — বাংলায় কারণসহ থামে, আর বাকি পর্যন্ত দেওয়া যায়। ⚠️ আগে উপরের সীমা ছিল না: ১,২০,০০০-এর
     * ঋণে এক কিস্তিতে ২,০০,০০০ দিলে ঋণের খাত উল্টো দিকে যেত।
     */
    public function test_an_instalment_cannot_pay_more_principal_than_the_loan_has_left(): void
    {
        $term = $this->term();
        $due = $term->instalments()->orderBy('no')->firstOrFail();
        $interest = (string) $due->interest;

        try {
            app(LoanService::class)->payInstalment($due, $this->money()->id, null, bcadd('200000', $interest, 4));
            $this->fail('⛔ বাকি আসলের বেশি কিস্তি নেওয়া হলো — ঋণ উল্টো দিকে।');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertStringContainsString('বাকি আসলের', (string) ($e->errors()['amount'][0] ?? ''), 'অন্য কারণে থামল।');
        }
        $this->assertFalse($due->fresh()->isPaid());

        // ⓘ ঠিক বাকি পর্যন্ত — পুরো ঋণ এক কিস্তিতে শোধ করা যায়
        app(LoanService::class)->payInstalment($due->fresh(), $this->money()->id, null, bcadd('120000', $interest, 4));
        $this->assertTrue($due->fresh()->isPaid(), '⛔ বাকি পর্যন্ত দেওয়াটাও থামল।');

        // ⓘ তারপর আর এক টাকার আসলও নয়
        $next = $term->instalments()->where('no', 2)->firstOrFail();
        try {
            app(LoanService::class)->payInstalment($next, $this->money()->id, null, bcadd('1', (string) $next->interest, 4));
            $this->fail('⛔ শোধ হয়ে যাওয়া ঋণে আবার আসল নেওয়া হলো।');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertStringContainsString('বাকি আসলের', (string) ($e->errors()['amount'][0] ?? ''));
        }
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
        return Account::query()->firstOrCreate(['code' => '1102-LOANFOLLOW'], [
            'company_id' => $this->company->id, 'parent_id' => Account::query()->where('code', StandardChart::BANK)->value('id'),
            'name_en' => 'Loan follow bank', 'name_bn' => 'ঋণের ব্যাংক', 'type' => Account::ASSET, 'nature' => Account::DEBIT,
            'is_group' => false, 'money_kind' => Account::BANK,
        ]);
    }
}
