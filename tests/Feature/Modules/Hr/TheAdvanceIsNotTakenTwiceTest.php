<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\ExpenseClaim;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\SalaryHead;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\ExpenseClaimService;
use App\Modules\Hr\Services\PayrollService;
use App\Modules\Hr\Services\SalaryHeadService;
use App\Modules\Hr\Services\SalaryStructureService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ⛔ অগ্রিম একবারই আদায় — পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬ (HR ১; [[PayrollService::capAdvancesNow()]])।
 *
 * ⓘ বেতনের খসড়ায় অগ্রিমের কিস্তি মাস-শেষের জেরে আটকানো হত, নিশ্চিত করার সময় আর মাপা হত না। খসড়ার পরে কর্মী অগ্রিম নগদে ফেরত
 * দিলে বা খরচের দাবি দিয়ে মেটালে বেতন থেকেও পুরো কিস্তি কাটা হত — একই টাকা দুইবার আদায়, আর তাঁর নামের ১১৩১ ঋণাত্মক।
 */
final class TheAdvanceIsNotTakenTwiceTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Employee $borrower;

    private int $cash;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();
        app(SalaryHeadService::class)->installDefaults();

        $this->borrower = app(EmployeeService::class)->create(['code' => 'EMP-TWC', 'name_en' => 'Took Advance', 'joining_date' => '2026-01-15', 'payment_method' => 'cash']);
        app(SalaryStructureService::class)->set($this->borrower, SalaryHead::query()->where('code', 'BASIC')->firstOrFail(), '2026-01-15', '20000');
        app(SalaryStructureService::class)->set($this->borrower, SalaryHead::query()->where('code', 'ADVANCE')->firstOrFail(), '2026-01-15', '2000');

        // ⓘ ৩,০০০ অগ্রিম, ১ আগস্ট, কর্মীর নামে
        $till = app(CashTillService::class)->ensurePrimaryTill();
        $this->cash = (int) $till->account_id;
        $this->putMoneyIn($till->account, '100000', '2026-07-01');
        app(PostingEngine::class)->post(sourceType: 'payment_voucher', sourceId: random_int(1, 9_999_999), trxDate: '2026-08-01', lines: [
            ['account_id' => StandardChart::find(StandardChart::EMPLOYEE_ADVANCE)->id, 'debit' => '3000', 'party_type' => 'employee', 'party_id' => $this->borrower->id],
            ['account_id' => $this->cash, 'credit' => '3000'],
        ], branchId: $this->company->defaultBranch()?->id);
    }

    public function test_an_advance_returned_in_cash_after_the_draft_is_not_taken_from_the_salary_too(): void
    {
        $run = app(PayrollService::class)->build('2026-08-01');
        $this->assertSame('2000', $this->deducted($run), 'খসড়ায় পুরো কিস্তি — মাস-শেষে ৩,০০০ খোলা');

        // ⓘ মাস-শেষের পরে, খসড়া নিশ্চিত হওয়ার আগে — ২,৫০০ নগদে ফেরত
        app(PostingEngine::class)->post(sourceType: 'receipt_voucher', sourceId: random_int(1, 9_999_999), trxDate: '2026-09-03', lines: [
            ['account_id' => $this->cash, 'debit' => '2500'],
            ['account_id' => StandardChart::find(StandardChart::EMPLOYEE_ADVANCE)->id, 'credit' => '2500', 'party_type' => 'employee', 'party_id' => $this->borrower->id],
        ], branchId: $this->company->defaultBranch()?->id);

        $run = app(PayrollService::class)->confirm($run->fresh());

        $this->assertSame('500', $this->deducted($run), '⛔ ফেরত দেওয়া অগ্রিম বেতন থেকেও কাটা হল');
        $this->assertSame('0', $this->open(), '⛔ কর্মীর অগ্রিম ঋণাত্মক — একই টাকা দুইবার আদায়');
        $this->assertSame('19500', bcadd((string) $run->net_total, '0', 0), '⛔ রানের নিট মোট নতুন কিস্তিতে গোনা হয়নি');
    }

    public function test_an_advance_settled_by_an_expense_claim_after_the_draft_is_not_taken_from_the_salary_too(): void
    {
        $run = app(PayrollService::class)->build('2026-08-01');
        $this->assertSame('2000', $this->deducted($run));

        // ⓘ খসড়ার পরে কর্মীর ২,৮০০ টাকার খরচের দাবি — অগ্রিম থেকে মেটে, বাকি ২০০ খোলা
        $worker = User::factory()->create(['email' => 'worker@twice.test', 'current_company_id' => $this->company->id, 'is_active' => true]);
        $worker->companies()->attach($this->company->id, ['is_active' => true]);
        CompanyContext::forCompany($this->company->id, fn () => $worker->givePermissionTo(Permission::findOrCreate('hr.claim.self', 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->borrower->forceFill(['user_id' => $worker->id])->save();

        $claim = app(ExpenseClaimService::class)->submit($worker, [
            'kind' => ExpenseClaim::EXPENSE, 'amount' => '2800', 'reason' => 'Market visit', 'spent_on' => now()->toDateString(),
            'expense_account_id' => Account::query()->postable()->active()->where('type', Account::EXPENSE)->orderBy('code')->firstOrFail()->id,
        ]);
        app(ApprovalEngine::class)->approve(Approval::query()->where('approvable_type', $claim->getMorphClass())
            ->where('approvable_id', $claim->id)->where('status', Approval::PENDING)->sole(), $this->owner);
        $this->assertSame('2800', bcadd((string) $claim->fresh()->from_advance, '0', 0), 'দাবি অগ্রিম থেকে মিটল');

        $run = app(PayrollService::class)->confirm($run->fresh());

        $this->assertSame('200', $this->deducted($run), '⛔ দাবিতে মেটানো অগ্রিম বেতন থেকেও কাটা হল');
        $this->assertSame('0', $this->open(), '⛔ কর্মীর অগ্রিম ঋণাত্মক — একই টাকা দুইবার আদায়');
    }

    /**
     * ⛔ কিস্তি কমায় net বাড়ে — বাড়া অঙ্কে আবার সই (cloud/people-fixes রিভিউ, ১০ অক্টোবর ২০২৬; [[PayrollService::confirm()]])।
     * ⓘ সই দেখা হত খসড়ার net-এ: সীমার ঠিক নিচে খসড়া, অগ্রিম ফেরতে net সীমা পার — তবু খাতায় বসত, সই ছাড়া।
     */
    public function test_a_net_that_rises_past_the_threshold_asks_for_a_signature_again(): void
    {
        $run = app(PayrollService::class)->build('2026-08-01');
        $draftNet = (string) $run->fresh()->net_total;

        \App\Models\ApprovalFlow::query()->withoutGlobalScopes()->where('company_id', $this->company->id)
            ->where('module', 'hr')->where('action', 'payroll')->update(['is_active' => false]);
        $signer = User::factory()->create(['current_company_id' => $this->company->id, 'is_active' => true]);
        $signer->companies()->attach($this->company->id, ['is_active' => true]);
        $flow = \App\Models\ApprovalFlow::query()->create(['company_id' => $this->company->id, 'module' => 'hr', 'action' => 'payroll',
            'document_type' => '', 'threshold_amount' => bcadd($draftNet, '1000', 4), 'is_active' => true]);
        \App\Models\ApprovalFlowStep::query()->create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => 'user', 'approver_id' => $signer->id]);
        $this->app->forgetInstance(ApprovalEngine::class);
        $this->app->forgetScopedInstances();

        // ⓘ খসড়ার পরে ২,০০০ নগদে ফেরত — খোলা ১,০০০, কিস্তি ২,০০০ থেকে ১,০০০; net ১,০০০ বাড়ে, সীমা ছোঁয়
        app(PostingEngine::class)->post(sourceType: 'receipt_voucher', sourceId: random_int(1, 9_999_999), trxDate: '2026-09-03', lines: [
            ['account_id' => $this->cash, 'debit' => '2000'],
            ['account_id' => StandardChart::find(StandardChart::EMPLOYEE_ADVANCE)->id, 'credit' => '2000', 'party_type' => 'employee', 'party_id' => $this->borrower->id],
        ], branchId: $this->company->defaultBranch()?->id);

        try {
            app(PayrollService::class)->confirm($run->fresh());
            $this->fail('⛔ net সীমা পার হল, তবু সই ছাড়া খাতায় বসল');
        } catch (\Illuminate\Validation\ValidationException) {
        }

        $this->assertSame(\App\Core\Support\DocumentStatus::DRAFT, $run->fresh()->status, '⛔ রান নিশ্চিত হয়ে গেল');
        $this->assertSame(0, LedgerEntry::query()->where('source_type', PayrollRun::SOURCE_TYPE)->where('source_id', $run->id)->count(), '⛔ খাতায় বেতন বসল');
        $asked = Approval::query()->where('approvable_type', $run->getMorphClass())->where('approvable_id', $run->id)->where('status', Approval::PENDING)->sole();
        $this->assertSame(0, bccomp((string) $asked->amount, bcadd($draftNet, '1000', 4), 4), '⛔ সই চাওয়া হল পুরনো অঙ্কে');
        $this->assertSame('1000', $this->deducted($run), 'কিস্তি নতুন খোলায় ঠিক হয়ে খসড়ায় থাকে');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function deducted(PayrollRun $run): string
    {
        return bcadd((string) $run->payslips()->where('employee_id', $this->borrower->id)->firstOrFail()
            ->lines()->where('head_code', 'ADVANCE')->sum('amount'), '0', 0);
    }

    private function open(): string
    {
        return bcadd((string) LedgerEntry::query()->where('account_id', StandardChart::find(StandardChart::EMPLOYEE_ADVANCE)->id)
            ->where('party_type', 'employee')->where('party_id', $this->borrower->id)
            ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as n')->value('n'), '0', 0);
    }
}
