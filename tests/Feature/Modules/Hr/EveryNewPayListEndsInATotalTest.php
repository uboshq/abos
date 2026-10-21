<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\OvertimeSheet;
use App\Modules\Hr\Models\SalaryHead;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\PayrollService;
use App\Modules\Hr\Services\ProvidentFund;
use App\Modules\Hr\Services\SalaryHeadService;
use App\Modules\Hr\Services\SalaryStructureService;
use App\Modules\MasterData\Services\MasterListService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\SignsTheSecondHand;
use Tests\TestCase;

/**
 * ⭐ নতুন প্রতিটা তালিকার নিচে যোগের সারি, বাংলায় "সর্বমোট" — অধিকাল, উৎসব ভাতার রান, ভবিষ্য তহবিলের খাতা (পাতা আর রিপোর্ট),
 * প্রস্তাবিত বদল (fe, ২১ অক্টোবর ২০২৬, দফা ৯)। ⓘ কর-সনদের যোগ [[TaxIsDeductedAtSourceOnlyByTheSlabsTest]]-এ।
 */
final class EveryNewPayListEndsInATotalTest extends TestCase
{
    use RefreshDatabase;
    // ⛔ বেতনের রান দ্বিতীয় সই ছাড়া খাতায় বসে না, সব ছক বন্ধ থাকলেও — সই-শাখার নিয়ম, মেশানোর পরে এখানেও (a6, ২১ অক্টোবর ২০২৬; [[ApprovalRules::secondHand()]])
    use SignsTheSecondHand;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app()->setLocale('bn');

        app(StandardChart::class)->install();
        app(MasterListService::class)->installDefaults();
        app(SalaryHeadService::class)->installDefaults();
        Employee::query()->delete();
    }

    public function test_the_overtime_list_totals_its_hours(): void
    {
        $this->sheet($this->employee('TT-A', '20000'), '3.5');
        $this->sheet($this->employee('TT-B', '20000'), '2.25');

        $this->get(route('hr.overtime.index', ['month' => '2026-07']))->assertOk()
            ->assertSee(__('core.print.total'))->assertSee('5.75');
    }

    public function test_the_bonus_run_ends_in_its_total(): void
    {
        $this->employee('TT-A', '23456.78');
        $this->employee('TT-B', '30000.01');

        $run = app(PayrollService::class)->buildBonus('2026-04-10');

        $this->get(route('hr.payroll.show', $run))->assertOk()
            ->assertSee(__('core.print.total'))->assertSee(Money::format('53456.79'));
    }

    public function test_the_fund_ledger_ends_in_its_total_on_the_page_and_in_the_report(): void
    {
        app(SettingsService::class)->set(ProvidentFund::ENABLED, true);
        $a = $this->employee('TT-A', '23456.78', '8');
        $payroll = app(PayrollService::class);
        $this->confirmedPayroll($payroll->build('2026-07-01'));
        $this->confirmedPayroll($payroll->build('2026-08-01'));

        // ⓘ দুই মাস × (১৮৭৬.৫৪ + ১৮৭৬.৫৪)
        $this->get(route('hr.employee.show', $a))->assertOk()
            ->assertSee('data-pf-total', false)->assertSee(Money::format('7506.16'));

        $report = app(ReportEngine::class)->run('hr.provident_fund', ['from' => '2026-07-01', 'to' => '2026-08-31']);
        $this->assertSame(0, bccomp((string) $report->totals['month_total'], '7506.16', 4), '⛔ রিপোর্টের যোগ দুই মাসের জমা নয়।');

        $this->get(route('hr.pf.proposal', ['on' => '2026-09-01']))->assertOk()->assertSee(__('core.print.total'));
    }

    private function employee(string $code, string $basic, ?string $pf = null): Employee
    {
        $employee = app(EmployeeService::class)->create([
            'code' => $code, 'name_en' => 'Person '.$code, 'joining_date' => '2025-01-01', 'payment_method' => 'cash',
        ]);
        $salaries = app(SalaryStructureService::class);
        $salaries->set($employee, SalaryHead::query()->where('code', 'BASIC')->firstOrFail(), '2025-01-01', $basic);

        if ($pf !== null) {
            $salaries->set($employee, SalaryHead::query()->where('code', 'PF')->firstOrFail(), '2025-01-01', $pf);
        }

        return $employee->fresh();
    }

    private function sheet(Employee $employee, string $hours): OvertimeSheet
    {
        return OvertimeSheet::query()->create([
            'company_id' => $this->company->id, 'employee_id' => $employee->id, 'month' => '2026-07-01',
            'hours' => $hours, 'days' => 1, 'status' => OvertimeSheet::WAITING,
        ]);
    }
}
