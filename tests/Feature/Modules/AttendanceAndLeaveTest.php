<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\LeaveApplication;
use App\Modules\Hr\Models\LeaveType;
use App\Modules\Hr\Models\SalaryHead;
use App\Modules\Hr\Services\AttendanceService;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\LeaveService;
use App\Modules\Hr\Services\PayrollService;
use App\Modules\Hr\Services\SalaryHeadService;
use App\Modules\Hr\Services\SalaryStructureService;
use App\Modules\MasterData\Services\MasterListService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * হাজিরা ও ছুটি — আর দুইটাই বেতনে গিয়ে যেভাবে পৌঁছায়।
 *
 * ── এখানকার সবচেয়ে বিপজ্জনক ভুলটা কী ────────────────────────────────
 * খালি হাজিরার খাতাকে "সবাই অনুপস্থিত" ধরা। সুইচ চালু করার প্রথম মাসেই
 * সবার বেতন শূন্য হয়ে যেত, আর কেউ বুঝত না কেন। তাই সেই ক্ষেত্রটা
 * আলাদা করে পরীক্ষা করা হয়।
 */
class AttendanceAndLeaveTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->user);

        app(MasterListService::class)->installDefaults();
        app(SalaryHeadService::class)->installDefaults();

        $this->post(route('hr.leave_type.install'));
    }

    private function attendance(): AttendanceService
    {
        return app(AttendanceService::class);
    }

    private function leave(): LeaveService
    {
        return app(LeaveService::class);
    }

    private function leaveTypeNamed(string $code): LeaveType
    {
        return LeaveType::query()->where('code', $code)->firstOrFail();
    }

    /** একজন কর্মী, মূল ২০,০০০ — অগস্টে (৩১ দিন) হিসাব সহজ থাকে। */
    private function employee(): Employee
    {
        $employee = app(EmployeeService::class)->create([
            'code' => 'EMP-001',
            'name_en' => 'Rafiq Islam',
            'joining_date' => '2026-01-15',
            'payment_method' => 'cash',
        ]);

        app(SalaryStructureService::class)->set(
            $employee,
            SalaryHead::query()->where('code', 'BASIC')->firstOrFail(),
            '2026-01-15',
            '20000',
        );

        return $employee->fresh();
    }

    private function switchProrationOn(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('hr.attendance_affects_salary', true);
        $settings->flush();
    }

    // ── হাজিরা ────────────────────────────────────────────────────────

    public function test_a_day_is_marked_once_and_changing_it_replaces_it(): void
    {
        $employee = $this->employee();

        $this->attendance()->mark($employee, '2026-08-03', Attendance::ABSENT);
        $this->attendance()->mark($employee, '2026-08-03', Attendance::PRESENT);

        $this->assertSame(1, Attendance::query()->where('employee_id', $employee->id)->count());
        $this->assertSame(Attendance::PRESENT,
            Attendance::query()->where('employee_id', $employee->id)->value('status'));
    }

    public function test_a_day_before_joining_cannot_be_marked(): void
    {
        $employee = $this->employee();

        $this->expectException(ValidationException::class);

        $this->attendance()->mark($employee, '2026-01-01', Attendance::PRESENT);
    }

    public function test_an_unknown_status_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->attendance()->mark($this->employee(), '2026-08-03', 'maybe');
    }

    /** ফাঁকা ঘর মানে "বসানো হয়নি" — আগের সারি মোছে না। */
    public function test_a_blank_row_leaves_what_was_already_marked(): void
    {
        $employee = $this->employee();
        $this->attendance()->mark($employee, '2026-08-03', Attendance::PRESENT);

        $this->attendance()->markDay('2026-08-03', [$employee->id => ['status' => '']]);

        $this->assertSame(Attendance::PRESENT,
            Attendance::query()->where('employee_id', $employee->id)->value('status'));
    }

    public function test_the_monthly_summary_counts_each_kind(): void
    {
        $employee = $this->employee();

        $this->attendance()->mark($employee, '2026-08-03', Attendance::PRESENT, ['is_late' => true]);
        $this->attendance()->mark($employee, '2026-08-04', Attendance::ABSENT);
        $this->attendance()->mark($employee, '2026-08-05', Attendance::HOLIDAY);

        $summary = $this->attendance()->monthlySummary($employee, Carbon::parse('2026-08-01'));

        $this->assertSame(1, $summary['present']);
        $this->assertSame(1, $summary['absent']);
        $this->assertSame(1, $summary['holiday']);
        $this->assertSame(1, $summary['late']);
        $this->assertSame(3, $summary['marked']);
        $this->assertSame(0, bccomp($summary['unpaid'], '1', 1));
    }

    // ── ছুটি ──────────────────────────────────────────────────────────

    /**
     * মঞ্জুর হওয়া ছুটি হাজিরার খাতায় গিয়ে বসে।
     *
     * না বসলে ওই দিনগুলো খালি থাকত, আর বেতনের হিসাব সেগুলো অনুপস্থিত
     * ধরত — নিয়ম মেনে ছুটি নেওয়ার শাস্তি হিসেবে।
     */
    public function test_approving_leave_writes_the_days_into_attendance(): void
    {
        $employee = $this->employee();

        $application = $this->leave()->apply(
            $employee, $this->leaveTypeNamed('CASUAL'), '2026-08-10', '2026-08-12', '3');

        $this->leave()->approve($application, $this->user);

        $rows = Attendance::query()->where('employee_id', $employee->id)->orderBy('work_date')->get();

        $this->assertCount(3, $rows);
        $this->assertTrue($rows->every(fn ($r) => $r->status === Attendance::LEAVE));
        $this->assertTrue($rows->every(fn ($r) => $r->leave_application_id === $application->id));
    }

    /** প্রত্যাহার করলে হাজিরার সারিগুলোও ফিরে যায়। */
    public function test_withdrawing_leave_takes_the_attendance_rows_back(): void
    {
        $employee = $this->employee();

        $application = $this->leave()->apply(
            $employee, $this->leaveTypeNamed('CASUAL'), '2026-08-10', '2026-08-12', '3');
        $this->leave()->approve($application, $this->user);

        $this->leave()->cancel($application->fresh());

        $this->assertSame(0, Attendance::query()->where('employee_id', $employee->id)->count());
        $this->assertSame(LeaveApplication::CANCELLED, $application->fresh()->status);
    }

    public function test_leave_cannot_overlap_leave_already_asked_for(): void
    {
        $employee = $this->employee();

        $this->leave()->apply($employee, $this->leaveTypeNamed('CASUAL'), '2026-08-10', '2026-08-12', '3');

        $this->expectException(ValidationException::class);

        $this->leave()->apply($employee, $this->leaveTypeNamed('SICK'), '2026-08-12', '2026-08-13', '2');
    }

    /** তারিখের পরিসরের চেয়ে বেশি দিন চাওয়া যায় না। */
    public function test_more_days_than_the_dates_allow_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->leave()->apply(
            $this->employee(), $this->leaveTypeNamed('CASUAL'), '2026-08-10', '2026-08-10', '5');
    }

    /**
     * বছরের কোটার বেশি ছুটি নেওয়া যায় না।
     *
     * ব্যালেন্স জমা রাখা হয় না, গোনা হয় — তাই একটা আবেদন প্রত্যাহার
     * করলেই কোটাটা নিজে থেকে ফিরে আসে।
     */
    public function test_the_yearly_quota_is_enforced_and_recovers_when_leave_is_withdrawn(): void
    {
        $employee = $this->employee();
        $casual = $this->leaveTypeNamed('CASUAL');

        // নৈমিত্তিক ছুটি বছরে ১০ দিন
        $first = $this->leave()->apply($employee, $casual, '2026-03-01', '2026-03-09', '9');
        $this->leave()->approve($first, $this->user);

        $balance = $this->leave()->balance($employee, $casual, Carbon::parse('2026-08-01'));
        $this->assertSame(0, bccomp($balance['taken'], '9', 1));
        $this->assertSame(0, bccomp($balance['left'], '1', 1));

        try {
            $this->leave()->apply($employee, $casual, '2026-08-10', '2026-08-12', '3');
            $this->fail('কোটার বেশি ছুটি নেওয়া যাওয়ার কথা নয়');
        } catch (ValidationException) {
            // প্রত্যাশিত
        }

        $this->leave()->cancel($first->fresh());

        $this->assertSame(0, bccomp(
            $this->leave()->balance($employee, $casual, Carbon::parse('2026-08-01'))['taken'], '0', 1));
    }

    /** বিনা বেতনের ছুটিতে বছরের কোনো সীমা নেই। */
    public function test_unpaid_leave_has_no_yearly_limit(): void
    {
        $employee = $this->employee();

        $application = $this->leave()->apply(
            $employee, $this->leaveTypeNamed('UNPAID'), '2026-08-01', '2026-08-20', '20');

        $this->assertSame(LeaveApplication::PENDING, $application->status);
    }

    public function test_a_decided_application_cannot_be_decided_again(): void
    {
        $application = $this->leave()->apply(
            $this->employee(), $this->leaveTypeNamed('CASUAL'), '2026-08-10', '2026-08-11', '2');

        $this->leave()->reject($application, $this->user, 'কাজের চাপ');

        $this->expectException(ValidationException::class);

        $this->leave()->approve($application->fresh(), $this->user);
    }

    // ── বেতনে পৌঁছানো ─────────────────────────────────────────────────

    /**
     * খালি হাজিরার খাতা মানে কারও বেতন কাটে না।
     *
     * এটাই এই ফিচারের সবচেয়ে বিপজ্জনক ভুল হত: সুইচ চালু করার প্রথম
     * মাসেই সবার বেতন শূন্য।
     */
    public function test_an_empty_register_does_not_cut_anybodys_pay(): void
    {
        $this->employee();
        $this->switchProrationOn();

        $run = app(PayrollService::class)->build('2026-08-01');

        $this->assertSame(0, bccomp((string) $run->gross_total, '20000', 4),
            'হাজিরা না বসালে পুরো বেতনই প্রাপ্য');
    }

    /**
     * অনুপস্থিতির ভাগে বেতন কমে — মাসের দিন ধরে।
     *
     * অগস্টে ৩১ দিন, ৩ দিন কামাই — তাই ২৮/৩১ ভাগ।
     */
    public function test_absence_reduces_the_salary_by_the_days_of_that_month(): void
    {
        $employee = $this->employee();
        $this->switchProrationOn();

        foreach (['2026-08-03', '2026-08-04', '2026-08-05'] as $day) {
            $this->attendance()->mark($employee, $day, Attendance::ABSENT);
        }

        $run = app(PayrollService::class)->build('2026-08-01');

        // ২০০০০ × ২৮ ÷ ৩১ = ১৮০৬৪.৫০
        $this->assertSame(0, bccomp((string) $run->gross_total, '18064.5000', 2));
    }

    /** সুইচ বন্ধ থাকলে অনুপস্থিতি থাকলেও বেতন কাটে না। */
    public function test_with_the_switch_off_absence_costs_nothing(): void
    {
        $employee = $this->employee();

        foreach (['2026-08-03', '2026-08-04', '2026-08-05'] as $day) {
            $this->attendance()->mark($employee, $day, Attendance::ABSENT);
        }

        $run = app(PayrollService::class)->build('2026-08-01');

        $this->assertSame(0, bccomp((string) $run->gross_total, '20000', 4));
    }

    /**
     * বেতনসহ ছুটিতে বেতন কাটে না, বিনা বেতনের ছুটিতে কাটে।
     *
     * দুইটাই হাজিরার খাতায় "ছুটি" হয়ে বসে — পার্থক্যটা ধরনের ঘরে,
     * তাই এক জায়গায় ভুল করলে দুইটাই এক আচরণ করত।
     */
    public function test_paid_leave_costs_nothing_but_unpaid_leave_does(): void
    {
        $employee = $this->employee();
        $this->switchProrationOn();

        $paid = $this->leave()->apply(
            $employee, $this->leaveTypeNamed('CASUAL'), '2026-08-10', '2026-08-12', '3');
        $this->leave()->approve($paid, $this->user);

        $this->assertSame(0, bccomp(
            $this->attendance()->unpaidDays($employee, Carbon::parse('2026-08-31')), '0', 1),
            'বেতনসহ ছুটিতে কিছু কাটার কথা নয়');

        $unpaid = $this->leave()->apply(
            $employee, $this->leaveTypeNamed('UNPAID'), '2026-08-20', '2026-08-21', '2');
        $this->leave()->approve($unpaid, $this->user);

        $this->assertSame(0, bccomp(
            $this->attendance()->unpaidDays($employee, Carbon::parse('2026-08-31')), '2', 1),
            'বিনা বেতনের ছুটি অনুপস্থিতির মতোই গোনার কথা');
    }

    /**
     * অগ্রিমের কিস্তি অনুপস্থিতিতে কমে না।
     *
     * তিন দিন কামাই করলে বেতন কমে, কিন্তু ধার তো পুরোটাই নেওয়া হয়েছিল।
     */
    public function test_a_deduction_that_is_not_prorated_stays_whole(): void
    {
        $employee = $this->employee();

        app(SalaryStructureService::class)->set(
            $employee,
            SalaryHead::query()->where('code', 'ADVANCE')->firstOrFail(),
            '2026-01-15',
            '1000',
        );

        $this->switchProrationOn();

        foreach (['2026-08-03', '2026-08-04', '2026-08-05'] as $day) {
            $this->attendance()->mark($employee, $day, Attendance::ABSENT);
        }

        $run = app(PayrollService::class)->build('2026-08-01');

        $this->assertSame(0, bccomp((string) $run->deduction_total, '1000', 4),
            'অগ্রিমের কিস্তি অক্ষত থাকার কথা');
    }

    // ── পর্দা ─────────────────────────────────────────────────────────

    public function test_the_screens_open(): void
    {
        $this->employee();

        $this->get(route('hr.attendance.index'))->assertOk();
        $this->get(route('hr.attendance.sheet'))->assertOk();
        $this->get(route('hr.leave.index'))->assertOk();
        $this->get(route('hr.leave.create'))->assertOk();
        $this->get(route('hr.leave_type.index'))->assertOk()->assertSee('CASUAL');
    }

    public function test_a_day_is_marked_through_the_screen(): void
    {
        $employee = $this->employee();

        $this->post(route('hr.attendance.store'), [
            'work_date' => '2026-08-03',
            'rows' => [$employee->id => ['status' => Attendance::ABSENT, 'remarks' => 'জানায়নি']],
        ])->assertRedirect();

        $row = Attendance::query()->where('employee_id', $employee->id)->firstOrFail();

        $this->assertSame(Attendance::ABSENT, $row->status);
        $this->assertSame('জানায়নি', $row->remarks);
    }

    /**
     * ছুটি মঞ্জুর করার অনুমতি আলাদা।
     *
     * এক অনুমতিতে রাখলে যে কেউ নিজের ছুটি নিজেই মঞ্জুর করতে পারত।
     */
    public function test_approving_leave_needs_its_own_permission(): void
    {
        $employee = $this->employee();

        $application = $this->leave()->apply(
            $employee, $this->leaveTypeNamed('CASUAL'), '2026-08-10', '2026-08-11', '2');

        $clerk = User::factory()->create();
        $clerk->companies()->attach($this->company->id);
        $clerk->givePermissionTo(['hr.leave.view', 'hr.leave.manage']);

        $this->actingAs($clerk)
            ->post(route('hr.leave.approve', $application->id))
            ->assertForbidden();

        $this->assertSame(LeaveApplication::PENDING, $application->fresh()->status);
    }
}
