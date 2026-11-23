<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Hr\Dashboard\HrDashboard;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\LeaveApplication;
use App\Modules\Hr\Models\LeaveType;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\Payslip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ হেডারের শাখা HR-এর পর্দাও ছাঁকে — মালিকের স্থায়ী নিয়ম "header branch filters everything" (fe, ২১ অক্টোবর ২০২৬; [[BranchReach::viewing()]])।
 *
 * দাবি: একটা শাখা বাছা থাকলে (`view_all_branches` false) কর্মীর তালিকা, হাজিরার দিনের পাতা আর মাসের পাতা, ছুটির তালিকা, রানের পাতার
 * স্লিপ আর HR ড্যাশবোর্ডের কর্মী-সংখ্যা থেকে অন্য শাখার মানুষ সরে যান; "সব শাখা"-তে ফিরে আসেন। ⓘ মানুষটার শাখার কোনো সীমা নেই —
 * সরে যাওয়াটা কেবল হেডারের বাছাইয়ে।
 */
final class TheHrScreensFollowTheHeaderBranchTest extends TestCase
{
    use RefreshDatabase;

    private const KEYS = ['hr.employee.view', 'hr.attendance.view', 'hr.leave.view', 'hr.payroll.view', 'hr.salary.view'];

    private Company $company;

    private Branch $dhaka;

    private User $hr;

    private PayrollRun $run;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['code' => 'HHB', 'name_en' => 'Header Co']);
        CompanyContext::set($this->company->id);

        $this->dhaka = Branch::query()->create(['company_id' => $this->company->id, 'code' => 'DHK', 'name_en' => 'Dhaka', 'is_active' => true]);
        $netrokona = Branch::query()->create(['company_id' => $this->company->id, 'code' => 'NTK', 'name_en' => 'Netrokona', 'is_active' => true]);

        foreach (self::KEYS as $key) {
            Permission::findOrCreate($key, 'web');
        }

        $this->hr = User::factory()->create(['current_company_id' => $this->company->id]);
        $this->hr->companies()->attach($this->company->id);
        CompanyContext::forCompany($this->company->id, fn () => $this->hr->givePermissionTo(self::KEYS));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // ⓘ নাগালে দুই শাখাই (সীমার সারিসহ) — "সব শাখা"-তে নাগাল আর শাখাহীন (প্রধান অফিসের) কর্মী, একটা বাছলে কেবল সেটা
        foreach ([$this->dhaka, $netrokona] as $reach) {
            UserDataScope::query()->create(['company_id' => $this->company->id, 'user_id' => $this->hr->id,
                'scope_type' => UserDataScope::BRANCH, 'scope_id' => $reach->id]);
        }

        $type = LeaveType::query()->create(['company_id' => $this->company->id, 'code' => 'CL', 'name_en' => 'Casual',
            'days_per_year' => '10', 'is_paid' => true, 'is_active' => true]);
        $this->run = PayrollRun::acrossBranches()->create([
            // ⓘ রান ঢাকায় বসে বানানো, কর্মী দুই শাখার — রানের পাতা খোলে, স্লিপ ছাঁকা হয়
            'company_id' => $this->company->id, 'branch_id' => $this->dhaka->id, 'document_no' => 'PRL-HDR', 'month' => Carbon::today()->startOfMonth()->toDateString(),
            'trx_date' => Carbon::today()->toDateString(), 'status' => DocumentStatus::CONFIRMED,
            'employee_count' => 2, 'gross_total' => '0', 'deduction_total' => '0', 'net_total' => '0',
        ]);

        foreach ([[$this->dhaka, 'E-1', 'Rahim Uddin'], [$netrokona, 'E-2', 'Karim Mia'], [null, 'E-3', 'Hasan Ali']] as [$branch, $code, $name]) {
            $employee = Employee::query()->create(['company_id' => $this->company->id, 'branch_id' => $branch?->id, 'code' => $code,
                'name_en' => $name, 'joining_date' => '2026-01-01']);
            Attendance::query()->create(['company_id' => $this->company->id, 'employee_id' => $employee->id,
                'work_date' => Carbon::today()->toDateString(), 'status' => Attendance::PRESENT]);
            LeaveApplication::query()->create(['company_id' => $this->company->id, 'employee_id' => $employee->id, 'leave_type_id' => $type->id,
                'from_date' => '2026-12-01', 'to_date' => '2026-12-01', 'days' => '1', 'status' => LeaveApplication::PENDING]);
            Payslip::query()->create(['company_id' => $this->company->id, 'payroll_run_id' => $this->run->id, 'employee_id' => $employee->id,
                'gross' => '1000', 'deductions' => '0', 'net' => '1000', 'payment_method' => 'cash']);
        }
    }

    public function test_with_one_branch_chosen_the_other_branchs_people_vanish_and_come_back_on_all_branches(): void
    {
        $this->choose($this->dhaka);

        foreach ($this->screens() as $name => $url) {
            $this->get($url)->assertOk()->assertSee('Rahim Uddin')->assertDontSee('Karim Mia', false)->assertDontSee('Hasan Ali', false);
        }

        $this->assertSame('1', $this->employeesStat(), "⛔ ড্যাশবোর্ডের কর্মী-সংখ্যায় অন্য শাখার মানুষ।");

        $this->choose(null);

        foreach ($this->screens() as $name => $url) {
            $this->get($url)->assertOk()->assertSee('Rahim Uddin')->assertSee('Karim Mia')->assertSee('Hasan Ali');
        }

        $this->assertSame('3', $this->employeesStat());
    }

    /** @return array<string, string> */
    private function screens(): array
    {
        return [
            'employees' => route('hr.employee.index'),
            'attendance' => route('hr.attendance.index'),
            'attendance_sheet' => route('hr.attendance.sheet'),
            'leave' => route('hr.leave.index'),
            'payroll_run' => route('hr.payroll.show', $this->run),
        ];
    }

    private function employeesStat(): ?string
    {
        return collect(HrDashboard::dashboard()->stats)->firstWhere('label', __('hr::dashboard.employees'))?->value;
    }

    /** ⓘ হেডারে একটা শাখা বাছা — `view_all_branches` false; `null` = "সব শাখা" */
    private function choose(?Branch $branch): void
    {
        $this->hr->forceFill(['current_branch_id' => $branch?->id, 'view_all_branches' => $branch === null])->save();
        CompanyContext::set($this->company->id, $branch?->id);
        $this->app['auth']->forgetGuards();
        app(DataScope::class)->forget();
        $this->actingAs($this->hr->fresh());
    }
}
