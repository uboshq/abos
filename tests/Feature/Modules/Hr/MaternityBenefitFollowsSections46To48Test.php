<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Services\PhoneModules;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\LeaveApplication;
use App\Modules\Hr\Models\LeaveType;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\SalaryHead;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\LeaveService;
use App\Modules\Hr\Services\MaternityBenefit;
use App\Modules\Hr\Services\PayrollService;
use App\Modules\Hr\Services\SalaryHeadService;
use App\Modules\Hr\Services\SalaryStructureService;
use App\Modules\MasterData\Services\MasterListService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ⭐ মাতৃত্বকালীন সুবিধা — ধারা ৪৬(৩), ৪৭, ৪৮ (fe-র উত্তর ২, ২১ অক্টোবর ২০২৬; [[LeaveService::assertEligible()]], [[MaternityBenefit]];
 * a6, ২১ অক্টোবর ২০২৬)।
 *
 * ── দাবি ───────────────────────────────────────────────────────────────
 *  · দুই জীবিত সন্তানে ফেরে (সংখ্যাসহ), একজনে চলে; ঘর খালি হলে চলে — আর ওয়েবের তালিকা আর ফোনের সারিতে সইকারী "তথ্য নেই" সতর্কতা
 *    দেখেন; আবেদনের পরে দুই লিখলে মঞ্জুরিতে ফেরে।
 *  · ৮ + ৮ সপ্তাহ: সম্ভাব্য দিন ছাড়া ফেরে; ৫৬ দিনের আগে শুরু বা সম্ভাব্য দিন + ৫৫-এর পরে শেষ ফেরে; ঠিক সীমায় চলে।
 *  · টাকা: নোটিশের আগের তিন মাসের মোট মজুরি ÷ কাজ করা দিন — ৬০,০০০ ÷ ৭৫ = ৮০০; আগস্টের ৩১ দিনে কাঠামোর মূল বেতন ০, আর
 *    "মাতৃত্বকালীন সুবিধা" ২৪,৮০০; হাজিরা না রাখলে চাকরির দিন (৯২) দিয়ে ভাগ।
 */
final class MaternityBenefitFollowsSections46To48Test extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->owner);

        app(MasterListService::class)->installDefaults();
        app(SalaryHeadService::class)->installDefaults();
    }

    public function test_two_surviving_children_end_the_benefit_and_an_empty_box_warns_the_signer(): void
    {
        try {
            $this->leave()->apply($this->mother('E-2C', 2), $this->maternity(), '2026-08-01', '2026-11-20', '112', null, '2026-09-26');
            $this->fail('⛔ দুই জীবিত সন্তানেও মাতৃত্বকালীন সুবিধা');
        } catch (ValidationException $e) {
            $this->assertSame(__('hr::validation.maternity_two_children', ['count' => 2]), $e->errors()['leave_type_id'][0]);
        }

        $this->assertSame(LeaveApplication::PENDING,
            $this->leave()->apply($this->mother('E-1C', 1), $this->maternity(), '2026-08-01', '2026-11-20', '112', null, '2026-09-26')->status);

        $unknown = $this->mother('E-NC', null);
        $pending = $this->leave()->apply($unknown, $this->maternity(), '2026-08-01', '2026-11-20', '112', null, '2026-09-26');
        $this->assertSame(LeaveApplication::PENDING, $pending->status, '⛔ তথ্য না থাকায় আটকাল — খালি ঘর আটকানোর কথা নয়');

        $this->get(route('hr.leave.index'))->assertOk()->assertSee(__('hr::message.maternity_children_unknown'));

        app(SettingsService::class)->set(PhoneModules::key('hr'), true);
        // ⓘ SettingsService cache — a6/settings-cache-fix
        app(SettingsService::class)->flush();
        $this->owner->forceFill(['current_company_id' => $this->company->id])->save();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->owner->fresh(), [AuthController::APP]);
        $row = collect($this->getJson('/api/v1/hr/leave/register')->assertOk()->json('rows'))->firstWhere('id', $pending->public_id);
        $this->assertSame(__('hr::message.maternity_children_unknown'), $row['warning'], '⛔ ফোনের সারিতে সইকারীর সতর্কতা নেই');
        $this->actingAs($this->owner);

        // ⛔ পরে দুই লেখা হলে মঞ্জুরিতে ফেরে
        $unknown->forceFill(['surviving_children' => 2])->save();
        $this->assertThrows(fn () => $this->leave()->approve($pending->fresh(), $this->owner), ValidationException::class);
        $this->assertSame(LeaveApplication::PENDING, $pending->fresh()->status);
    }

    public function test_the_leave_sits_eight_weeks_before_and_eight_weeks_after_the_expected_day(): void
    {
        $mother = $this->mother('E-88', 0);
        $expect = fn (string $key, string $message, string $from, string $to, ?string $expected) => $this->refuses($key, $message,
            fn () => $this->leave()->apply($mother, $this->maternity(), $from, $to, '10', null, $expected));

        $expect('expected_delivery_date', __('hr::validation.maternity_expected_required'), '2026-08-01', '2026-08-10', null);
        // ⓘ সম্ভাব্য দিন ২৬ সেপ্টেম্বর — শুরু বড়জোর ১ আগস্ট, শেষ বড়জোর ২০ নভেম্বর
        $expect('from_date', __('hr::validation.maternity_too_early', ['date' => '2026-08-01']), '2026-07-31', '2026-08-09', '2026-09-26');
        $expect('to_date', __('hr::validation.maternity_too_late', ['date' => '2026-11-20']), '2026-11-12', '2026-11-21', '2026-09-26');

        $application = $this->leave()->apply($mother, $this->maternity(), '2026-08-01', '2026-11-20', '112', null, '2026-09-26');
        $this->assertSame('2026-09-26', $application->expected_delivery_date->toDateString());
    }

    public function test_the_benefit_is_the_average_daily_wage_of_the_three_months_before_notice(): void
    {
        $basic = SalaryHead::query()->where('code', 'BASIC')->firstOrFail();
        $mother = $this->mother('E-PAY', 0);
        $quiet = $this->mother('E-NOATT', 0);

        foreach ([$mother, $quiet] as $employee) {
            app(SalaryStructureService::class)->set($employee, $basic, '2026-01-15', '20000');
        }

        // ⓘ মে, জুন, জুলাই — নিশ্চিত রান, প্রত্যেকের মোট আয় ২০,০০০; মোট ৬০,০০০
        foreach (['2026-05-01', '2026-06-01', '2026-07-01'] as $i => $month) {
            $run = PayrollRun::acrossBranches()->create(['company_id' => $this->company->id, 'branch_id' => $this->company->defaultBranch()?->id,
                'document_no' => 'PRL-MB-'.$i, 'month' => $month, 'trx_date' => Carbon::parse($month)->endOfMonth()->toDateString()]);
            $run->forceFill(['status' => DocumentStatus::CONFIRMED])->save();

            foreach ([$mother, $quiet] as $employee) {
                Payslip::query()->create(['company_id' => $this->company->id, 'payroll_run_id' => $run->id, 'employee_id' => $employee->id,
                    'gross' => '20000', 'deductions' => '0', 'net' => '20000', 'payment_method' => 'cash']);
            }
        }

        // ⓘ বাতিল রানের স্লিপ মজুরি নয় — গোনায় এলে গড় বদলাত
        $void = PayrollRun::acrossBranches()->create(['company_id' => $this->company->id, 'branch_id' => $this->company->defaultBranch()?->id,
            'document_no' => 'PRL-MB-X', 'month' => '2026-06-01', 'trx_date' => '2026-06-30']);
        $void->forceFill(['status' => DocumentStatus::CANCELLED])->save();
        Payslip::query()->create(['company_id' => $this->company->id, 'payroll_run_id' => $void->id, 'employee_id' => $mother->id,
            'gross' => '99999', 'deductions' => '0', 'net' => '99999', 'payment_method' => 'cash']);

        // ⓘ মায়ের ৭৫ দিন "উপস্থিত"; অন্যজনের হাজিরা রাখা হয়নি
        $this->rows($mother, '2026-05-01', 75, Attendance::PRESENT);

        // ⓘ নোটিশ ১ আগস্ট — আগের তিন মাস মে–জুলাই
        $this->travelTo(Carbon::parse('2026-08-01 09:00:00'));
        foreach ([$mother, $quiet] as $employee) {
            $this->leave()->approve($this->leave()->apply($employee, $this->maternity(), '2026-08-01', '2026-11-20', '112', null, '2026-09-26'), $this->owner);
        }

        $application = LeaveApplication::query()->where('employee_id', $mother->id)->firstOrFail();
        $this->assertSame(0, bccomp('800', (string) app(MaternityBenefit::class)->averageDailyWage($application), 4),
            '⛔ গড় দৈনিক মজুরি ৬০,০০০ ÷ ৭৫ নয়');

        $run = app(PayrollService::class)->build('2026-08-01');
        $slip = $run->payslips()->where('employee_id', $mother->id)->with('lines')->firstOrFail();
        $lines = $slip->lines->keyBy('head_code');

        $this->assertSame(0, bccomp('0', (string) $lines['BASIC']->amount, 2), '⛔ মাতৃত্বকালীন দিনেও কাঠামোর মূল বেতন');
        $this->assertSame(0, bccomp('24800', (string) $lines[MaternityBenefit::LINE_CODE]->amount, 2), '⛔ সুবিধা ৩১ × ৮০০ নয়');
        $this->assertSame(0, bccomp('24800', (string) $slip->gross, 2));

        // ⓘ হাজিরা নেই — মে–জুলাইয়ের চাকরির ৯২ দিন: ৬০,০০০ ÷ ৯২ × ৩১ = ২০,২১৭.৩৯
        $other = $run->payslips()->where('employee_id', $quiet->id)->with('lines')->firstOrFail();
        $this->assertSame(0, bccomp('20217.39', (string) $other->lines->firstWhere('head_code', MaternityBenefit::LINE_CODE)->amount, 2));

        // ⓘ কোম্পানি ধরনটা বিনা বেতনে করলে সুবিধার সারি নেই — সুবিধা কেবল বেতনসহ মাতৃত্বকালীন ছুটির
        $this->maternity()->forceFill(['is_paid' => false])->save();
        app(PayrollService::class)->rebuild($run->fresh());
        $this->assertNull($run->fresh()->payslips()->where('employee_id', $mother->id)->firstOrFail()->lines()
            ->where('head_code', MaternityBenefit::LINE_CODE)->first(), '⛔ বিনা বেতনের ধরনেও মাতৃত্বকালীন সুবিধা বসল');
    }

    private function refuses(string $key, string $message, \Closure $work): void
    {
        try {
            $work();
            $this->fail("⛔ {$key}: ফেরার কথা ছিল");
        } catch (ValidationException $e) {
            $this->assertSame($message, $e->errors()[$key][0] ?? null);
        }
    }

    private function leave(): LeaveService
    {
        return app(LeaveService::class);
    }

    private function maternity(): LeaveType
    {
        return LeaveType::query()->where('code', LeaveType::MATERNITY)->firstOrFail();
    }

    private function mother(string $code, ?int $children): Employee
    {
        return app(EmployeeService::class)->create(['code' => $code, 'name_en' => 'Mother '.$code, 'joining_date' => '2025-01-01',
            'payment_method' => 'cash', 'gender' => Employee::FEMALE, 'surviving_children' => $children])->fresh();
    }

    private function rows(Employee $employee, string $from, int $count, string $status): void
    {
        $day = Carbon::parse($from);
        $batch = [];

        for ($i = 0; $i < $count; $i++, $day->addDay()) {
            $batch[] = ['public_id' => (string) Str::uuid(), 'company_id' => $this->company->id, 'employee_id' => $employee->id,
                'work_date' => $day->toDateString(), 'status' => $status, 'is_late' => false, 'created_at' => now(), 'updated_at' => now()];
        }

        DB::table('hr_attendance')->insert($batch);
    }
}
