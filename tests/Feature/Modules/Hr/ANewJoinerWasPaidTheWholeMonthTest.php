<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\SalaryHead;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\PayrollService;
use App\Modules\Hr\Services\SalaryHeadService;
use App\Modules\Hr\Services\SalaryStructureService;
use App\Modules\MasterData\Services\MasterListService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * মাসের মাঝে যোগ দেওয়া বা ছেড়ে যাওয়া কর্মী পুরো মাসের বেতন পেতেন (ec, Hr লাইন-নিরীক্ষা, ১৪ অক্টোবর ২০২৬;
 * [[PayrollService::attendanceFactor()]])।
 *
 * ⭐ দাবি — হাজিরার সুইচ বন্ধ থাকলেও, চাকরির দিন ÷ মাসের দিন:
 *   · ২৫ অক্টোবর যোগদান, মূল ৩০,০০০ + বাড়িভাড়া ৫০% — ৭/৩১ ভাগ: ৬,৭৭৪.১৯ + ৩,৩৮৭.১০
 *   · ১০ অক্টোবর ছাড়া — ১০/৩১ ভাগ
 *   · পুরো মাস কাজ — পুরোটা, আগের মতো
 */
final class ANewJoinerWasPaidTheWholeMonthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();
        app(MasterListService::class)->installDefaults();
        app(SalaryHeadService::class)->installDefaults();

        // ⓘ ডেমোর মালিক-কর্মী বাদ — [[TheBankFileAndTheBooksPaidTheSameSalaryTest]]-এর একই কারণে
        Employee::query()->delete();
    }

    public function test_a_part_month_pays_only_the_days_employed(): void
    {
        $joiner = $this->employee('EMP-J', joined: '2026-10-25');
        $leaver = $this->employee('EMP-L', joined: '2026-01-01', left: '2026-10-10');
        $whole = $this->employee('EMP-W', joined: '2026-01-01');

        $run = app(PayrollService::class)->build('2026-10-01');

        $this->assertSame('10161.29', $this->gross($run->id, $joiner), '⛔ ২৫ অক্টোবরের নতুন কর্মী পুরো মাসের বেতন পেলেন');
        $this->assertSame('14516.13', $this->gross($run->id, $leaver), '⛔ ১০ অক্টোবরে ছেড়ে যাওয়া কর্মী পুরো মাসের বেতন পেলেন');
        $this->assertSame('45000.00', $this->gross($run->id, $whole), 'পুরো মাস কাজ — পুরো বেতন, আগের মতো');
    }

    private function gross(int $runId, Employee $employee): string
    {
        return bcadd((string) Payslip::query()->where('payroll_run_id', $runId)->where('employee_id', $employee->id)->value('gross'), '0', 2);
    }

    private function employee(string $code, string $joined, ?string $left = null): Employee
    {
        $employee = app(EmployeeService::class)->create([
            'code' => $code, 'name_en' => 'Worker '.$code, 'joining_date' => $joined, 'payment_method' => 'cash',
        ]);

        if ($left !== null) {
            $employee->forceFill(['leaving_date' => $left])->save();
        }

        $salaries = app(SalaryStructureService::class);
        $salaries->set($employee, SalaryHead::query()->where('code', 'BASIC')->firstOrFail(), '2026-01-01', '30000');
        $salaries->set($employee, SalaryHead::query()->where('code', 'HRA')->firstOrFail(), '2026-01-01', '50');

        return $employee->fresh();
    }
}
