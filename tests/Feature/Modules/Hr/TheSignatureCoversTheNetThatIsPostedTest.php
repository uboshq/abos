<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\SalaryHead;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\PayrollService;
use App\Modules\Hr\Services\SalaryHeadService;
use App\Modules\Hr\Services\SalaryStructureService;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⛔ সই যে অঙ্কে দেখা হল, খাতায় তার বেশি বসে না — ec, Hr লাইন-নিরীক্ষা, ১৪ অক্টোবর ২০২৬ (⚠️৩; [[PayrollService::confirm()]])।
 *
 * ⓘ সই দেখা হত হাতের কপির net-এ, আর তুলনার অঙ্কটা পড়া হত তালার পরে তাজা সারি থেকে। মাঝখানে আরেক ট্যাবে "আবার বানান" net
 * সীমার উপরে তুললে তাজা অঙ্কটাই "সই হওয়া" ধরা হত — বড় অঙ্কটা সই ছাড়া খাতায় বসত।
 */
final class TheSignatureCoversTheNetThatIsPostedTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Employee $worker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        app(SalaryHeadService::class)->installDefaults();

        $this->worker = app(EmployeeService::class)->create(['code' => 'EMP-SGN', 'name_en' => 'Signed Salary', 'joining_date' => '2026-01-15', 'payment_method' => 'cash']);
        app(SalaryStructureService::class)->set($this->worker, SalaryHead::query()->where('code', 'BASIC')->firstOrFail(), '2026-01-15', '20000');
    }

    public function test_a_rebuild_between_the_signature_check_and_the_lock_cannot_post_more_than_was_signed(): void
    {
        $run = app(PayrollService::class)->build('2026-08-01');
        $draftNet = (string) $run->fresh()->net_total;

        // ⓘ সীমা খসড়ার ঠিক উপরে — খসড়ার অঙ্কে সই লাগে না, ১০,০০০ বেশিতে লাগে
        ApprovalFlow::query()->withoutGlobalScopes()->where('company_id', $this->company->id)
            ->where('module', 'hr')->where('action', 'payroll')->update(['is_active' => false]);
        $signer = User::factory()->create(['current_company_id' => $this->company->id, 'is_active' => true]);
        $signer->companies()->attach($this->company->id, ['is_active' => true]);
        $flow = ApprovalFlow::query()->create(['company_id' => $this->company->id, 'module' => 'hr', 'action' => 'payroll',
            'document_type' => '', 'threshold_amount' => bcadd($draftNet, '1000', 4), 'is_active' => true]);
        ApprovalFlowStep::query()->create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => 'user', 'approver_id' => $signer->id]);
        $this->app->forgetInstance(ApprovalEngine::class);
        $this->app->forgetScopedInstances();

        /*
         * ⓘ দৌড়টা — সই দেখার মাঝখানে (সইয়ের সারি পড়ার মুহূর্তে) আরেক ট্যাবে মূল বেতন ২০,০০০ থেকে ৩০,০০০, আর "আবার বানান"।
         * একবারই — দ্বিতীয় নিশ্চিতে আর নয়।
         */
        $raced = false;
        DB::listen(function (QueryExecuted $query) use (&$raced, $run): void {
            if ($raced || ! str_contains($query->sql, 'from `approvals`')) {
                return;
            }

            $raced = true;
            app(SalaryStructureService::class)->set($this->worker, SalaryHead::query()->where('code', 'BASIC')->firstOrFail(), '2026-08-01', '30000');
            app(PayrollService::class)->rebuild(PayrollRun::query()->findOrFail($run->id));
        });

        try {
            app(PayrollService::class)->confirm($run->fresh());
            $this->fail('⛔ সই দেখা হল খসড়ার অঙ্কে, খাতায় বসল আবার-বানানো বড় অঙ্ক — সই ছাড়া');
        } catch (ValidationException) {
        }

        $this->assertTrue($raced, 'দৃশ্যটাই বানানো যায়নি — সই দেখার মাঝে আবার-বানানো চলেনি');
        $this->assertSame(0, bccomp((string) $run->fresh()->net_total, bcadd($draftNet, '10000', 4), 4), 'দৃশ্যটাই বানানো যায়নি — net বাড়েনি');
        $this->assertSame(DocumentStatus::DRAFT, $run->fresh()->status, '⛔ রান নিশ্চিত হয়ে গেল');
        $this->assertSame(0, LedgerEntry::query()->where('source_type', PayrollRun::SOURCE_TYPE)->where('source_id', $run->id)->count(), '⛔ খাতায় বেতন বসল, সই ছাড়া');

        $asked = Approval::query()->where('approvable_type', $run->getMorphClass())->where('approvable_id', $run->id)->where('status', Approval::PENDING)->sole();
        $this->assertSame(0, bccomp((string) $asked->amount, bcadd($draftNet, '10000', 4), 4), '⛔ সই চাওয়া হল পুরনো অঙ্কে');
    }
}
