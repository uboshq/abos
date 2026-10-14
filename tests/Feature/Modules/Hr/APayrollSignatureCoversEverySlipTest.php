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
use App\Models\User;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\SalaryHead;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\PayrollService;
use App\Modules\Hr\Services\SalaryHeadService;
use App\Modules\Hr\Services\SalaryStructureService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⭐ বেতনের সই প্রতিটা বেতনশিটের — কেবল মোটের নয় (হিসাবের নিরীক্ষা, ১৩ অক্টোবর ২০২৬; [[PayrollRun::fingerprintRelations()]])।
 *
 * ⛔ আগে: আগস্টের রান (ক ২০,০০০, খ ৩০,০০০; নিট ৫০,০০০) সই পেল। তারপর কাঠামো বদলে ক ৩০,০০০, খ ২০,০০০ করে "আবার বানান" —
 * মোট এক, লোক এক, তাই ছাপ এক; পুরনো সইয়েই নতুন মাথাপিছু বেতন খাতায় আর ব্যাংকের ফাইলে।
 */
final class APayrollSignatureCoversEverySlipTest extends TestCase
{
    use RefreshDatabase;

    private User $signer;

    /** @var array<string, Employee> */
    private array $people = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(SalaryHeadService::class)->installDefaults();

        foreach (['A' => '20000', 'B' => '30000'] as $code => $basic) {
            $this->people[$code] = app(EmployeeService::class)->create(['code' => 'EMP-S'.$code, 'name_en' => 'Signed '.$code,
                'joining_date' => '2026-01-15', 'payment_method' => 'cash']);
            $this->pay($code, '2026-01-15', $basic);
        }

        $this->signer = User::factory()->create(['is_active' => true, 'current_company_id' => $company->id]);
        $this->signer->companies()->attach($company->id, ['is_active' => true]);
        $flow = ApprovalFlow::query()->create(['company_id' => $company->id, 'module' => 'hr', 'action' => 'payroll',
            'document_type' => '', 'threshold_amount' => null, 'is_active' => true]);
        ApprovalFlowStep::query()->create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => 'user', 'approver_id' => $this->signer->id]);
    }

    public function test_swapping_two_salaries_under_the_same_total_asks_for_a_new_signature(): void
    {
        $run = $this->signedRun();
        $net = (string) $run->net_total;

        $this->pay('A', '2026-08-01', '30000');
        $this->pay('B', '2026-08-01', '20000');
        $run = app(PayrollService::class)->rebuild($run->fresh());
        $this->assertSame(0, bccomp($net, (string) $run->net_total, 4), 'প্রস্তুতি — মোট একই থাকার কথা');

        try {
            app(PayrollService::class)->confirm($run->fresh());
            $this->fail('⛔ দুজনের বেতন অদলবদলের পরেও পুরনো সইয়ে রান নিশ্চিত হলো।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }

        $this->assertSame(DocumentStatus::DRAFT, $run->fresh()->status);
        $this->assertSame(Approval::PENDING, app(ApprovalEngine::class)->latestFor($run->fresh(), 'payroll')?->status,
            '⛔ বদলানো রানের জন্য নতুন সই চাওয়া হয়নি।');
    }

    public function test_an_unchanged_run_confirms_on_its_signature(): void
    {
        $run = $this->signedRun();

        $this->assertSame(DocumentStatus::CONFIRMED, app(PayrollService::class)->confirm($run->fresh())->status,
            '⛔ রান এক চুলও বদলায়নি, তবু নতুন সই চাইল।');
    }

    private function signedRun(): PayrollRun
    {
        $run = app(PayrollService::class)->build('2026-08-01');

        try {
            app(PayrollService::class)->confirm($run->fresh());
            $this->fail('প্রস্তুতি — রান সইয়ের জন্য থামার কথা');
        } catch (ValidationException) {
        }

        $asked = app(ApprovalEngine::class)->latestFor($run->fresh(), 'payroll');
        $this->assertSame(Approval::PENDING, $asked?->status);
        app(ApprovalEngine::class)->approve($asked, $this->signer);

        return $run->fresh();
    }

    private function pay(string $code, string $from, string $basic): void
    {
        app(SalaryStructureService::class)->set($this->people[$code], SalaryHead::query()->where('code', 'BASIC')->firstOrFail(), $from, $basic);
    }
}
