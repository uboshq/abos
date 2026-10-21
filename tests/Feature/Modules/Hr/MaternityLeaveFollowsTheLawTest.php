<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Security\FieldSecurity;
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
use App\Modules\Hr\Services\LeaveTypeDefaults;
use App\Modules\Hr\Services\PayrollService;
use App\Modules\Hr\Services\SalaryHeadService;
use App\Modules\Hr\Services\SalaryStructureService;
use App\Modules\MasterData\Services\MasterListService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ মাতৃত্বকালীন ছুটি — ১৬ সপ্তাহ (১১২ দিন), বেতনসহ, কেবল নারী কর্মী, ছুটির প্রথম দিনে চাকরি অন্তত ৬ মাস (মালিকের অনুমোদন,
 * ২১ অক্টোবর ২০২৬; বাংলাদেশ শ্রম আইন ২০০৬ অধ্যায় ৪, ধারা ৪৫–৫০; [[LeaveService::assertEligible()]], [[LeaveTypeDefaults]];
 * a6, ২১ অক্টোবর ২০২৬)।
 *
 * ── দাবি ───────────────────────────────────────────────────────────────
 *  · নতুন কোম্পানিতে ধরনটা খোলার দিনেই থাকে (১১২ দিন, বেতনসহ); চলতি কোম্পানিতে কনসোলের কাজ বসায়, দুইবার চালালেও একটাই, আর
 *    কোম্পানির নিজের বদলানো ধরন ছোঁয় না।
 *  · পুরুষ, অন্যান্য আর লিঙ্গ-না-লেখা কর্মী ফেরেন; ৬ মাসের এক দিন আগে ফেরে (তারিখসহ), সেদিন চলে।
 *  · যোগ্য কর্মীর ছুটি মঞ্জুর হয়, শুক্রবারসহ প্রতিটা দিন হাজিরায় বসে, আর হাজিরায় বেতন কাটার সুইচ চালু থাকলেও বেতনশিট কাটে না।
 */
final class MaternityLeaveFollowsTheLawTest extends TestCase
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

    public function test_a_new_company_has_the_type_and_the_console_adds_it_to_an_existing_one_once(): void
    {
        // ⓘ কোনো বোতাম না চেপেই — খোলার দিনের প্রমিত ধরন
        $type = $this->maternity();
        $this->assertSame('112.0', (string) $type->days_per_year);
        $this->assertTrue($type->is_paid, '⛔ মাতৃত্বকালীন ছুটি বিনা বেতনে বসল');

        // ⓘ আগের কোম্পানি — খোলার দিনে এই তালিকা ছিল না; নিজের নৈমিত্তিক ছুটি ১২ দিন করা
        $old = Company::create(['code' => 'OLDCO', 'name_en' => 'Old Co']);
        CompanyContext::forCompany($old->id, fn () => LeaveType::query()->create(['code' => 'CASUAL', 'name_en' => 'Our casual',
            'days_per_year' => '12', 'is_paid' => true, 'is_active' => true]));

        // ⓘ পর্দার "বসান" বোতাম — নতুন কোম্পানিতে কিছু বাকি নেই, পুরনোটায় বাকি
        $this->assertFalse(app(LeaveTypeDefaults::class)->anyMissing(), '⛔ সব প্রমিত ধরন থাকতেও বোতাম দেখায়');
        $this->assertTrue(CompanyContext::forCompany($old->id, fn () => app(LeaveTypeDefaults::class)->anyMissing()),
            '⛔ মাতৃত্বকালীন নেই, অথচ বোতাম লুকায়');

        $this->artisan('abos:hr-install-leave-types')->assertSuccessful();
        $this->artisan('abos:hr-install-leave-types')->assertSuccessful();

        $types = CompanyContext::forCompany($old->id, fn () => LeaveType::query()->get()->keyBy('code'));
        $this->assertSame(1, $types->where('code', LeaveType::MATERNITY)->count());
        $this->assertSame(['CASUAL', 'EARNED', 'MATERNITY', 'SICK', 'UNPAID'], $types->keys()->sort()->values()->all(),
            '⛔ দুইবার চালানোয় দ্বিতীয় সারি, বা কোনো প্রমিত ধরন বাকি');
        $this->assertSame('12.0', (string) $types['CASUAL']->days_per_year, '⛔ কোম্পানির নিজের বদলানো ধরন ছুঁয়ে ফেলল');
        $this->assertSame(1, LeaveType::query()->where('code', LeaveType::MATERNITY)->count(), '⛔ চলতি কোম্পানিতে দ্বিতীয় মাতৃত্বকালীন ধরন');
    }

    public function test_only_a_woman_with_six_months_of_service_may_take_it(): void
    {
        foreach (['male', 'other', null] as $gender) {
            try {
                $this->leave()->apply($this->employee('E-'.($gender ?? 'none'), '2025-01-01', $gender), $this->maternity(), '2026-08-01', '2026-11-20', '112');
                $this->fail('⛔ '.($gender ?? 'লিঙ্গ-না-লেখা').' কর্মী মাতৃত্বকালীন ছুটি পেলেন');
            } catch (ValidationException $e) {
                $this->assertSame(__('hr::validation.maternity_women_only'), $e->errors()['leave_type_id'][0]);
            }
        }

        // ⓘ ১ মার্চ যোগদান — ৬ মাস পূর্ণ ১ সেপ্টেম্বর
        $new = $this->employee('E-NEW', '2026-03-01', 'female');
        try {
            $this->leave()->apply($new, $this->maternity(), '2026-08-31', '2026-12-20', '112');
            $this->fail('⛔ ৬ মাসের আগে মাতৃত্বকালীন ছুটি পার হল');
        } catch (ValidationException $e) {
            $this->assertSame(__('hr::validation.maternity_six_months', ['date' => '2026-09-01']), $e->errors()['from_date'][0]);
        }

        $pending = $this->leave()->apply($new, $this->maternity(), '2026-09-01', '2026-12-21', '112', null, '2026-10-27');
        $this->assertSame(LeaveApplication::PENDING, $pending->status, '⛔ ৬ মাস পূর্ণ হওয়ার দিনেও ফিরল');

        // ⛔ মঞ্জুরের মুহূর্তেও যোগ্যতা — আবেদনের পরে লিঙ্গ বদলালে
        $new->forceFill(['gender' => 'male'])->save();
        $this->assertThrows(fn () => $this->leave()->approve($pending->fresh(), $this->owner), ValidationException::class);
        $this->assertSame(LeaveApplication::PENDING, $pending->fresh()->status);
        $this->assertSame(0, LeaveApplication::query()->whereIn('employee_id',
            Employee::query()->whereIn('code', ['E-male', 'E-other', 'E-none'])->pluck('id'))->count());
    }

    public function test_an_eligible_womans_maternity_leave_is_approved_and_the_payslip_is_not_cut(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('hr.attendance_affects_salary', true);
        $settings->flush();

        $mother = $this->employee('E-MOM', '2026-01-15', 'female');
        app(SalaryStructureService::class)->set($mother, SalaryHead::query()->where('code', 'BASIC')->firstOrFail(), '2026-01-15', '20000');

        // ⓘ ১ আগস্ট থেকে ১৬ সপ্তাহ — ২০ নভেম্বর (প্রসবের সম্ভাব্য দিন ২৬ সেপ্টেম্বর: ৫৬ দিন আগে, ৫৬ দিন সেদিনসহ পরে); শুক্রবারও ছুটির দিন।
        // ⓘ আগের তিন মাসের বেতন নেই, তাই ধারা ৪৮-এর গড় নেই — কাঠামোর বেতনই চলে ([[MaternityBenefit]]; গড়ের দাবি MaternityBenefitFollowsSections46To48Test-এ)
        $application = $this->leave()->approve(
            $this->leave()->apply($mother, $this->maternity(), '2026-08-01', '2026-11-20', '112', null, '2026-09-26'), $this->owner);
        $this->assertSame(LeaveApplication::APPROVED, $application->status);
        $this->assertSame(31, Attendance::query()->where('leave_application_id', $application->id)
            ->whereBetween('work_date', ['2026-08-01', '2026-08-31'])->count(), '⛔ আগস্টের সব দিন (শুক্রবারসহ) ছুটির সারিতে নেই');

        $this->assertSame('0', app(AttendanceService::class)->unpaidDays($mother->fresh(), Carbon::parse('2026-08-01')),
            '⛔ মাতৃত্বকালীন ছুটির দিন বেতন থেকে কাটা হিসাবে গোনা');

        $run = app(PayrollService::class)->build('2026-08-01');
        $slip = $run->payslips()->where('employee_id', $mother->id)->firstOrFail();
        $this->assertSame(0, bccomp((string) $slip->gross, '20000', 2), '⛔ মাতৃত্বকালীন ছুটির মাসে বেতন কাটল');

        $this->assertSame('0.0', $this->leave()->balance($mother, $this->maternity(), Carbon::parse('2026-12-31'))['left']);
    }

    public function test_a_company_that_makes_it_unpaid_cuts_every_calendar_day_and_the_gender_hides_behind_the_identity_key(): void
    {
        // ⓘ কোম্পানির নীতি (আইনের বাইরে) — বিনা বেতনে করলে ক্যালেন্ডারের প্রতিটা দিন কাটে, শুক্রবারসহ
        $this->maternity()->forceFill(['is_paid' => false])->save();
        $mother = $this->employee('E-UP', '2025-01-01', 'female');
        $this->leave()->approve($this->leave()->apply($mother, $this->maternity(), '2026-08-01', '2026-08-14', '14', null, '2026-09-01'), $this->owner);
        $this->assertSame('14.0', app(AttendanceService::class)->unpaidDays($mother->fresh(), Carbon::parse('2026-08-01')),
            '⛔ ক্যালেন্ডারের দিনের ছুটিতে শুক্রবারের ভাগ শূন্য হয়ে দিন কম কাটল');

        // ⛔ লিঙ্গ পরিচয়ের চাবির পেছনে
        $clerk = User::factory()->create(['current_company_id' => $this->company->id]);
        $clerk->companies()->attach($this->company->id);
        CompanyContext::forCompany($this->company->id, fn () => $clerk->givePermissionTo(Permission::findOrCreate('hr.employee.view', 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($clerk->fresh());
        $this->assertFalse(FieldSecurity::visible(Employee::class, 'gender'), '⛔ পরিচয়ের চাবি ছাড়াই লিঙ্গ দেখা যায়');
        $this->assertFalse(FieldSecurity::visible(Employee::class, 'surviving_children'), '⛔ পরিচয়ের চাবি ছাড়াই জীবিত সন্তানের সংখ্যা দেখা যায়');
        $this->actingAs($this->owner);
        $this->assertTrue(FieldSecurity::visible(Employee::class, 'gender'));
    }

    private function leave(): LeaveService
    {
        return app(LeaveService::class);
    }

    private function maternity(): LeaveType
    {
        return LeaveType::query()->where('code', LeaveType::MATERNITY)->firstOrFail();
    }

    private function employee(string $code, string $joined, ?string $gender): Employee
    {
        return app(EmployeeService::class)->create(['code' => $code, 'name_en' => 'Person '.$code, 'joining_date' => $joined,
            'payment_method' => 'cash', 'gender' => $gender])->fresh();
    }
}
