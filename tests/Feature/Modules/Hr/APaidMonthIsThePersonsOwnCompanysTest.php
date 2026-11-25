<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\FinalSettlement;
use App\Modules\Hr\Models\LeaveApplication;
use App\Modules\Hr\Models\LeaveType;
use App\Modules\Hr\Models\OvertimeSheet;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\PayslipLine;
use App\Modules\Hr\Models\SalaryHead;
use App\Modules\Hr\Models\SalaryStructure;
use App\Modules\Hr\Services\Gratuity;
use App\Modules\Hr\Services\ProvidentFund;
use App\Modules\Hr\Services\SalaryHeadService;
use App\Modules\Hr\Services\SalaryStructureService;
use App\Modules\Hr\Services\AttendanceService;
use App\Modules\Hr\Services\LeaveService;
use App\Modules\Hr\Services\OvertimeService;
use App\Modules\Hr\Services\PayrollService;
use App\Modules\Hr\Services\SalaryTax;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⭐ কর্মী-খাতা গোটা গ্রুপের এক হলেও (`hr.shared_across_companies`) দেওয়া মাস আর বেতনের অঙ্ক কর্মীর নিজের কোম্পানির (২৫ নভেম্বর ২০২৬)।
 *
 * দাবি, দুই দিকে: ক-র দেওয়া মাস খ-র মানুষকে আটকায় না; খ-র পর্দা থেকেও ক-র মানুষের দেওয়া মাস বদলায় না — হাজিরা, ছুটি (মঞ্জুর, প্রত্যাহার),
 * অধিকাল, মিশ্র তালিকাতেও। খ থেকে ক-র রান নিশ্চিত, বাতিল বা আবার বানানো যায় না; ক-র বেতন খ-র রিপোর্ট, তালিকা, ড্যাশবোর্ড, কর-হিসাবে যোগ হয় না।
 */
final class APaidMonthIsThePersonsOwnCompanysTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;

    private Company $b;

    private User $owner;

    private Employee $ofA;

    private Employee $ofB;

    private Carbon $month;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->a = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->b = Company::query()->where('code', 'FMART')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->month = Carbon::today()->startOfMonth();

        $this->in($this->a);
        app(SettingsService::class)->set('hr.shared_across_companies', true);
        [$this->ofA, $this->ofB] = [$this->person($this->a, 'SH-A'), $this->person($this->b, 'SH-B')];
        $this->paidRun($this->a, $this->ofA, '30000');
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    public function test_attendance_follows_the_person_from_either_screen(): void
    {
        foreach ([$this->a, $this->b] as $screen) {
            $this->in($screen);
            $this->stops('work_date', fn () => app(AttendanceService::class)->mark($this->ofA->fresh(), $this->month->toDateString(), Attendance::PRESENT));
            $row = app(AttendanceService::class)->mark($this->ofB->fresh(), $this->month->toDateString(), Attendance::PRESENT);
            $this->assertSame((int) $this->b->id, (int) $row->company_id, '⛔ খ-র মানুষের দিন অন্য কোম্পানির সারিতে।');
        }
    }

    public function test_leave_follows_the_person_from_either_screen(): void
    {
        $type = LeaveType::query()->create(['code' => 'SHLV', 'name_en' => 'Shared leave', 'days_per_year' => '30', 'is_paid' => true, 'is_active' => true]);
        $leave = fn (Employee $e, string $status, int $day) => LeaveApplication::query()->create(['employee_id' => $e->id, 'leave_type_id' => $type->id,
            'from_date' => $this->month->copy()->addDays($day)->toDateString(), 'to_date' => $this->month->copy()->addDays($day)->toDateString(),
            'days' => 1, 'status' => $status]);

        $this->in($this->b);
        $this->stops('status', fn () => app(LeaveService::class)->approve($leave($this->ofA, LeaveApplication::PENDING, 1), $this->owner));
        $this->stops('status', fn () => app(LeaveService::class)->cancel($leave($this->ofA, LeaveApplication::APPROVED, 2), $this->owner));

        $this->in($this->a);
        app(LeaveService::class)->approve($theirs = $leave($this->ofB, LeaveApplication::PENDING, 3), $this->owner);
        $this->assertSame(LeaveApplication::APPROVED, $theirs->fresh()->status, '⛔ ক-র দেওয়া মাস খ-র মানুষের ছুটি আটকাল।');
        app(LeaveService::class)->cancel($theirs->fresh(), $this->owner);
        $this->assertSame(LeaveApplication::CANCELLED, $theirs->fresh()->status);
    }

    public function test_a_mixed_overtime_list_leaves_out_only_the_paid_companys_people(): void
    {
        $sheet = fn (Employee $e) => OvertimeSheet::query()->create(['company_id' => $e->company_id, 'employee_id' => $e->id,
            'month' => $this->month->toDateString(), 'status' => OvertimeSheet::WAITING, 'hours' => '5', 'days' => 1]);
        [$sheetA, $sheetB] = [$sheet($this->ofA), $sheet($this->ofB)];

        foreach ([$this->a, $this->b] as $screen) {
            $this->in($screen);
            $this->stops('month', fn () => app(OvertimeService::class)->sign($sheetA->fresh(), $this->owner));
        }

        $this->in($this->a);
        $signed = app(OvertimeService::class)->sign($sheetB->fresh(), $this->owner);
        $this->assertSame(OvertimeSheet::SIGNED, $signed->fresh()->status, '⛔ ক-র দেওয়া মাস খ-র মানুষের অধিকাল আটকাল।');

        // ⓘ মিশ্র তালিকা থামে না; দুই কোম্পানিরই মাস দেওয়া হলে থামে
        app(OvertimeService::class)->prepare($this->month, $this->owner);
        $this->paidRun($this->b, $this->ofB, '20000');
        Employee::query()->withoutGlobalScopes()->whereNotIn('company_id', [$this->a->id, $this->b->id])->delete();
        $this->stops('month', fn () => app(OvertimeService::class)->prepare($this->month, $this->owner));
    }

    public function test_another_companys_run_cannot_be_confirmed_cancelled_or_rebuilt_from_here(): void
    {
        $run = $this->paidRun($this->b, $this->ofB, '20000');
        $this->in($this->a);
        $this->stops('status', fn () => app(PayrollService::class)->cancel($run->fresh(), 'x'));
        $run->forceFill(['status' => DocumentStatus::DRAFT])->save();
        $this->stops('status', fn () => app(PayrollService::class)->confirm($run->fresh()));
        $this->stops('status', fn () => app(PayrollService::class)->rebuild($run->fresh()));
        $this->assertSame(DocumentStatus::DRAFT, (string) $run->fresh()->status);
    }

    public function test_money_figures_stay_in_the_book_of_their_company(): void
    {
        $this->paidRun($this->b, $this->ofB, '77777');
        $this->in($this->a);

        $register = app(\App\Core\Engines\Report\ReportEngine::class)->run('hr.payroll_register',
            ['from' => $this->month->toDateString(), 'to' => $this->month->copy()->endOfMonth()->toDateString(), 'branch_id' => null], 1, 100);
        $this->assertSame(0, bccomp('30000', (string) $register->totals['gross'], 2), '⛔ বেতনের রেজিস্টারে অন্য কোম্পানির বেতন।');

        $list = $this->get(route('hr.payroll.index'))->assertOk();
        $this->assertSame([(int) $this->a->id], collect($list->viewData('runs')->items())->pluck('company_id')->map(fn ($id) => (int) $id)->unique()->values()->all());

        $months = (new \ReflectionMethod(\App\Modules\Hr\Dashboard\HrDashboard::class, 'salaryMonths'))->invoke(null);
        $this->assertSame(0, bccomp('30000', (string) end($months)['gross'], 2), '⛔ ড্যাশবোর্ডের বেতন খরচে অন্য কোম্পানির বেতন।');

        // ⓘ একই মানুষের নামে অন্য কোম্পানির স্লিপ থাকলেও (পুরনো রান) কর তাঁর নিজের কোম্পানির
        $this->slip(PayrollRun::acrossBranches()->withoutGlobalScope('company')->where('company_id', $this->b->id)->firstOrFail(), $this->ofA, '99999');
        $certificate = app(SalaryTax::class)->certificate($this->ofA->fresh(), SalaryTax::incomeYearOf($this->month));
        $this->assertSame(0, bccomp('30000', (string) $certificate['gross'], 2), '⛔ কর-সনদে অন্য কোম্পানির বেতন।');
        $this->assertSame(0, bccomp('30000', (string) app(SalaryTax::class)->trueUp($this->ofA->fresh(), $this->month->copy()->endOfMonth(), '0')['income'], 2),
            '⛔ কর-সমন্বয়ে অন্য কোম্পানির বেতন।');
    }

    public function test_rows_written_from_a_sister_screen_belong_to_the_persons_company(): void
    {
        $type = LeaveType::query()->create(['code' => 'SHAP', 'name_en' => 'Apply leave', 'days_per_year' => '30', 'is_paid' => true, 'is_active' => true]);
        app(SalaryHeadService::class)->installDefaults();
        $head = SalaryHead::query()->where('code', 'BASIC')->firstOrFail();
        $next = $this->month->copy()->addMonthNoOverflow();
        $day = $next->copy()->addDays(4);
        $day = ($day->isFriday() ? $day->addDay() : $day)->toDateString();   // ⓘ সাপ্তাহিক ছুটির দিন নয়

        $this->in($this->a);
        $leave = app(LeaveService::class)->apply($this->ofB->fresh(), $type, $day, $day, '1', 'x');
        $structure = app(SalaryStructureService::class)->set($this->ofB->fresh(), $head, $next->toDateString(), '20000');
        $this->assertSame([(int) $this->b->id, (int) $this->b->id], [(int) $leave->company_id, (int) $structure->company_id], '⛔ সারি পর্দার কোম্পানিতে বসল।');

        // ⓘ সুইচ বন্ধ হলেও কর্মীর কোম্পানিতে সারি দুটো থাকে
        app(SettingsService::class)->set('hr.shared_across_companies', false);
        $this->in($this->b);
        $this->assertNotNull(LeaveApplication::query()->find($leave->id));
        $this->assertNotNull(SalaryStructure::query()->find($structure->id));
    }

    public function test_fund_gratuity_settlements_and_the_certificate_are_the_companys_own(): void
    {
        app(StandardChart::class)->install();
        app(SalaryHeadService::class)->installDefaults();
        $runB = $this->paidRun($this->b, $this->ofB, '77777');
        $runA = PayrollRun::acrossBranches()->withoutGlobalScope('company')->where('company_id', $this->a->id)->where('document_no', 'PAY-'.$this->a->code)->firstOrFail();
        foreach ([[$runA, '111'], [$runB, '222']] as [$run, $share]) {
            $slip = Payslip::query()->withoutGlobalScopes()->where('payroll_run_id', $run->id)->firstOrFail();
            PayslipLine::query()->create(['company_id' => $run->company_id, 'payslip_id' => $slip->id, 'head_code' => PayslipLine::PF_EMPLOYER_CODE,
                'head_name_en' => 'PF', 'head_name_bn' => 'PF', 'kind' => PayslipLine::EMPLOYER, 'amount' => $share, 'sort_order' => 1]);
        }

        $this->in($this->a);
        $this->assertSame([(int) $this->ofA->id], app(ProvidentFund::class)->contributions()->pluck('employee_id')->map(fn ($id) => (int) $id)->all(), '⛔ তহবিলে অন্য কোম্পানির চাঁদা।');
        $this->assertSame(0, bccomp('222', (string) app(ProvidentFund::class)->ledgerOf($this->ofB->fresh())[0]['employer_share'], 2), 'ⓘ একজনের খাতা তাঁর নিজের কোম্পানির।');

        $ids = collect(app(Gratuity::class)->rows(Carbon::today()))->map(fn ($r) => (int) $r['employee']->id);
        $this->assertContains((int) $this->ofA->id, $ids);
        $this->assertNotContains((int) $this->ofB->id, $ids, '⛔ গ্র্যাচুইটির দায়ে অন্য কোম্পানির মানুষ।');

        foreach ([$this->ofA, $this->ofB] as $person) {
            FinalSettlement::query()->create(['company_id' => $person->company_id, 'branch_id' => $person->branch_id, 'employee_id' => $person->id,
                'last_day' => now()->toDateString(), 'separation' => 'resignation', 'wages' => '10', 'leave_encashment' => '0', 'gratuity' => '0',
                'provident_fund' => '0', 'advance_recovered' => '0', 'net' => '10', 'status' => FinalSettlement::PENDING, 'requested_by' => $this->owner->id]);
        }
        $rows = $this->get(route('hr.final_settlement.index'))->assertOk()->viewData('rows');
        $this->assertSame([(int) $this->ofA->id], collect($rows->items())->pluck('employee_id')->map(fn ($id) => (int) $id)->all(), '⛔ চূড়ান্ত পাওনার তালিকায় অন্য কোম্পানির কাগজ।');

        $this->get(route('hr.employee.tax_certificate', $this->ofA->id))->assertOk();
        $this->get(route('hr.employee.tax_certificate', $this->ofB->id))->assertNotFound();
    }

    private function in(Company $company): void
    {
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        User::query()->whereKey($this->owner->id)->update(['current_company_id' => $company->id, 'current_branch_id' => $company->defaultBranch()?->id]);
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->owner = User::query()->findOrFail($this->owner->id));
    }

    private function person(Company $company, string $code): Employee
    {
        return CompanyContext::forCompany($company->id, fn () => Employee::query()->create(['branch_id' => $company->defaultBranch()?->id, 'code' => $code,
            'name_en' => $code, 'joining_date' => now()->subYears(2)->toDateString(), 'payment_method' => 'cash', 'is_active' => true]));
    }

    private function paidRun(Company $company, Employee $employee, string $gross): PayrollRun
    {
        return CompanyContext::forCompany($company->id, function () use ($company, $employee, $gross): PayrollRun {
            $run = PayrollRun::query()->create(['company_id' => $company->id, 'branch_id' => $company->defaultBranch()?->id, 'document_no' => 'PAY-'.$company->code,
                'run_type' => PayrollRun::SALARY, 'month' => $this->month->toDateString(), 'trx_date' => $this->month->copy()->endOfMonth()->toDateString()]);
            $run->forceFill(['status' => DocumentStatus::CONFIRMED, 'employee_count' => 1, 'gross_total' => $gross, 'deduction_total' => '0', 'net_total' => $gross])->save();
            $this->slip($run, $employee, $gross);

            return $run;
        });
    }

    private function slip(PayrollRun $run, Employee $employee, string $gross): void
    {
        Payslip::query()->create(['company_id' => $run->company_id, 'payroll_run_id' => $run->id, 'employee_id' => $employee->id,
            'gross' => $gross, 'deductions' => '0', 'net' => $gross, 'payment_method' => 'cash']);
    }

    private function stops(string $field, callable $act): void
    {
        try {
            $act();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());

            return;
        }

        $this->fail('⛔ দেওয়া মাস বা অন্য কোম্পানির রান বদলে গেল।');
    }
}
