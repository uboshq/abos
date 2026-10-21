<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\Holiday;
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
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⛔ অনুমোদিত কাগজ আর দেওয়া মাস কখনো নতুন করে লেখা হয় না — ছুটির দিন, সাপ্তাহিক ছুটি বা ছুটির নিয়ম বদলালেও (fe-র উত্তর ৩,
 * ২১ অক্টোবর ২০২৬; [[PaidMonth]], [[PayrollService::rebuild()]]; a6, ২১ অক্টোবর ২০২৬)।
 *
 * ── দাবি ───────────────────────────────────────────────────────────────
 *  · আগস্টের রান নিশ্চিত — সাপ্তাহিক ছুটি "নেই" থাকায় শুক্রবারের কামাইও কেটেছিল। তারপর শুক্রবার সাপ্তাহিক ছুটি হল, মাঝে
 *    তালিকায় ছুটির দিন বসল, বিনা বেতনের ধরন বেতনসহ হল — বেতনশিটের প্রতিটা অঙ্ক আর রানের মোট হুবহু আগের মতো, আবার-বানানো ফেরে।
 *  · মঞ্জুর হওয়া ছুটির দিন সংখ্যা আর হাজিরার সারি নতুন ছুটির দিনে বদলায় না; দেওয়া মাসে প্রত্যাহারও ফেরে।
 */
final class APaidMonthKeepsItsAmountsWhenTheRulesChangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_confirmed_run_and_an_approved_leave_stay_as_they_were(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs($owner);
        app(MasterListService::class)->installDefaults();
        app(SalaryHeadService::class)->installDefaults();

        $settings = app(SettingsService::class);
        $settings->set('hr.attendance_affects_salary', true);
        $settings->set('hr.weekly_off', 'none');
        // ⓘ SettingsService cache — a6/settings-cache-fix
        $settings->flush();

        $employee = app(EmployeeService::class)->create(['code' => 'E-PAID', 'name_en' => 'Paid Month', 'joining_date' => '2026-01-15', 'payment_method' => 'cash']);
        app(SalaryStructureService::class)->set($employee, SalaryHead::query()->where('code', 'BASIC')->firstOrFail(), '2026-01-15', '31000');

        // ⓘ শুক্রবার ৭ আগস্ট কামাই; সোম ১০ – বুধ ১২ বিনা বেতনে ছুটি
        app(AttendanceService::class)->mark($employee, '2026-08-07', Attendance::ABSENT);
        $unpaid = LeaveType::query()->where('code', 'UNPAID')->firstOrFail();
        $leave = app(LeaveService::class)->approve(app(LeaveService::class)->apply($employee, $unpaid, '2026-08-10', '2026-08-12', '3'), $owner);

        $run = app(PayrollService::class)->build('2026-08-01');
        // ⓘ নিশ্চিত রানের অবস্থা — সইয়ের ছক এখানে প্রশ্ন নয়
        $run->forceFill(['status' => DocumentStatus::CONFIRMED])->save();

        $slip = $run->payslips()->where('employee_id', $employee->id)->with('lines')->firstOrFail();
        $before = ['gross' => (string) $slip->gross, 'net' => (string) $slip->net, 'lines' => $slip->lines->pluck('amount', 'head_code')->map(fn ($a) => (string) $a)->all(),
            'run' => [(string) $run->fresh()->gross_total, (string) $run->fresh()->net_total]];
        // ⓘ ৩১,০০০ × (৩১ − ৪) ÷ ৩১ = ২৭,০০০ — শুক্রবারের কামাইসহ চার দিন কাটা
        $this->assertSame(0, bccomp('27000', $before['gross'], 2));

        // ⓘ নিয়ম বদলায়: শুক্রবার ছুটি, ১১ আগস্ট তালিকার ছুটি, বিনা বেতনের ধরন বেতনসহ
        $settings->set('hr.weekly_off', 'friday');
        $settings->flush();
        Holiday::query()->create(['company_id' => $company->id, 'holiday_date' => '2026-08-11', 'name' => 'Listed later']);
        $unpaid->forceFill(['is_paid' => true])->save();

        $slip = $slip->fresh(['lines']);
        $this->assertSame($before, ['gross' => (string) $slip->gross, 'net' => (string) $slip->net,
            'lines' => $slip->lines->pluck('amount', 'head_code')->map(fn ($a) => (string) $a)->all(),
            'run' => [(string) $run->fresh()->gross_total, (string) $run->fresh()->net_total]], '⛔ নিশ্চিত রানের অঙ্ক নিয়ম বদলে বদলাল');
        $this->assertThrows(fn () => app(PayrollService::class)->rebuild($run->fresh()), ValidationException::class);

        $this->assertSame('3.0', (string) $leave->fresh()->days, '⛔ মঞ্জুর হওয়া ছুটির দিন নতুন ছুটির দিনে বদলাল');
        $this->assertSame(3, Attendance::query()->where('leave_application_id', $leave->id)->count(), '⛔ মঞ্জুর হওয়া ছুটির হাজিরা নতুন করে লেখা হল');
        $this->assertThrows(fn () => app(LeaveService::class)->cancel($leave->fresh(), $owner), ValidationException::class);
        $this->assertSame(LeaveApplication::APPROVED, $leave->fresh()->status);
        $this->assertTrue(Employee::query()->whereKey($employee->id)->exists());
    }
}
