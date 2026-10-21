<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Services\DataScope;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\AuditTrail;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\Holiday;
use App\Modules\Hr\Models\LeaveApplication;
use App\Modules\Hr\Models\LeaveType;
use App\Modules\Hr\Services\AttendanceService;
use App\Modules\Hr\Services\LeaveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * ⭐ ছুটি কেবল কাজের দিনে গোনা — সাপ্তাহিক ছুটি আর তালিকার ছুটি বাদ (মালিকের অনুমোদন, ২১ অক্টোবর ২০২৬; বাংলাদেশ শ্রম আইন ২০০৬
 * ধারা ১০৩, ১১৫–১১৮; [[WorkCalendar]], [[HolidayController]]; a6, ২১ অক্টোবর ২০২৬)।
 *
 * ── দাবি ───────────────────────────────────────────────────────────────
 *  · রবি ১৩ – শনি ১৯ ডিসেম্বর ২০২৬: শুক্রবার ১৮ সাপ্তাহিক, বুধবার ১৬ বিজয় দিবস — কাজের দিন ৫; ৬ চাওয়া যায় না, ৫ যায়, আর হাজিরায়
 *    ঠিক ৫টা "ছুটি"-র সারি, ছুটির দুই দিনে কিছু নয়।
 *  · ছুটি শাখা ধরে: ঢাকার ছুটি নেত্রকোনার কর্মীর দিন কমায় না; শাখাহীন কর্মীর কেবল সব-শাখার ছুটি।
 *  · বেতন: ছুটির দিনে লেখা "অনুপস্থিত" কাটে না, কাজের দিনেরটা কাটে; বিনা বেতনের ছুটি শুক্রবার পেরোলে শুক্রবার কাটে না।
 *  · সাপ্তাহিক ছুটির সেটিং "নেই" হলে শুক্রবারও কাজের দিন — সুইচটাই নিয়ম চালায়।
 *  · পর্দা: চাবি ছাড়া ৪০৩, দেখার চাবিতে তালিকা, লেখার চাবিতে বসানো/বদল/মোছা — প্রতিটা অডিটে; সীমিত নাগালে সব-শাখার ছুটি নয়,
 *    অন্য শাখার সারি বদলানো নয় (৪০৩); একই দিনে দুইবার নয়।
 */
final class LeaveCountsOnlyWorkingDaysTest extends TestCase
{
    use RefreshDatabase;

    private const KEYS = ['hr.leave.view', 'hr.leave.manage', 'hr.leave.approve', 'hr.attendance.view', 'hr.attendance.manage'];

    private Company $company;

    private Branch $dhaka;

    private Branch $netrokona;

    private User $hr;

    private LeaveType $casual;

    private LeaveType $unpaid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['code' => 'HRH', 'name_en' => 'Holiday Co']);
        CompanyContext::set($this->company->id);

        $this->dhaka = Branch::query()->create(['company_id' => $this->company->id, 'code' => 'DHK', 'name_en' => 'Dhaka', 'is_active' => true]);
        $this->netrokona = Branch::query()->create(['company_id' => $this->company->id, 'code' => 'NTK', 'name_en' => 'Netrokona', 'is_active' => true]);

        foreach (self::KEYS as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $this->hr = $this->person(self::KEYS);

        $this->casual = LeaveType::query()->create(['company_id' => $this->company->id, 'code' => 'CL', 'name_en' => 'Casual',
            'days_per_year' => '10', 'is_paid' => true, 'is_active' => true]);
        $this->unpaid = LeaveType::query()->create(['company_id' => $this->company->id, 'code' => 'LWP', 'name_en' => 'Unpaid',
            'days_per_year' => '0', 'is_paid' => false, 'is_active' => true]);

        // ⓘ বিজয় দিবস — সব শাখার
        Holiday::query()->create(['company_id' => $this->company->id, 'holiday_date' => '2026-12-16', 'name' => 'Victory Day']);
    }

    public function test_a_leave_over_a_friday_and_a_holiday_counts_only_the_working_days(): void
    {
        $employee = $this->employee('E-1', $this->dhaka);

        try {
            $this->leave()->apply($employee, $this->casual, '2026-12-13', '2026-12-19', '6');
            $this->fail('⛔ ৫ কাজের দিনে ৬ দিনের ছুটি পার হল — শুক্রবার বা বিজয় দিবস গোনা হচ্ছে');
        } catch (ValidationException $e) {
            $this->assertSame(__('hr::validation.days_exceed_working', ['working' => '5']), $e->errors()['days'][0]);
        }

        $application = $this->leave()->apply($employee, $this->casual, '2026-12-13', '2026-12-19', '5');
        $this->leave()->approve($application, $this->hr);

        $days = Attendance::query()->where('leave_application_id', $application->id)->orderBy('work_date')
            ->pluck('work_date')->map(fn ($d) => Carbon::parse($d)->toDateString())->all();
        $this->assertSame(['2026-12-13', '2026-12-14', '2026-12-15', '2026-12-17', '2026-12-19'], $days,
            '⛔ ছুটির সারি শুক্রবার বা বিজয় দিবসে বসল, বা কাজের দিন বাদ গেল');
        $this->assertSame('5.0', $this->leave()->balance($employee, $this->casual, Carbon::parse('2026-12-31'))['taken']);
    }

    public function test_a_holiday_listed_after_the_application_is_measured_again_at_approval(): void
    {
        $employee = $this->employee('E-L', $this->dhaka);
        // ⓘ সোম ৯ – বৃহস্পতি ১২ নভেম্বর, ৪ কাজের দিন
        $application = $this->leave()->apply($employee, $this->casual, '2026-11-09', '2026-11-12', '4');

        Holiday::query()->create(['company_id' => $this->company->id, 'holiday_date' => '2026-11-11', 'name' => 'Declared later']);

        try {
            $this->leave()->approve($application, $this->hr);
            $this->fail('⛔ পরে বসানো ছুটির দিনসহ ৪ দিন মঞ্জুর হল — কোটা একদিন বেশি কাটত');
        } catch (ValidationException $e) {
            $this->assertSame(__('hr::validation.days_exceed_working', ['working' => '3']), $e->errors()['days'][0]);
        }

        $this->assertSame(LeaveApplication::PENDING, $application->fresh()->status);
    }

    public function test_a_holiday_of_one_branch_does_not_shorten_another_branchs_leave(): void
    {
        Holiday::query()->create(['company_id' => $this->company->id, 'branch_id' => $this->dhaka->id,
            'holiday_date' => '2026-11-05', 'name' => 'Dhaka local']);

        // ⓘ বৃহস্পতি ৫ – শনি ৭ নভেম্বর: শুক্রবার সবার, বৃহস্পতি কেবল ঢাকার
        $this->assertThrows(fn () => $this->leave()->apply($this->employee('E-D', $this->dhaka), $this->casual, '2026-11-05', '2026-11-07', '2'),
            ValidationException::class);
        $this->assertSame(LeaveApplication::PENDING,
            $this->leave()->apply($this->employee('E-N', $this->netrokona), $this->casual, '2026-11-05', '2026-11-07', '2')->status,
            '⛔ ঢাকার ছুটি নেত্রকোনার কর্মীর দিন কমাল');
        $this->assertSame(LeaveApplication::PENDING,
            $this->leave()->apply($this->employee('E-H', null), $this->casual, '2026-11-05', '2026-11-07', '2')->status,
            '⛔ শাখাহীন কর্মীর উপর একটা শাখার ছুটি খাটল');
    }

    public function test_absence_on_a_weekly_off_or_a_holiday_is_not_deducted(): void
    {
        $employee = $this->employee('E-A', $this->dhaka);
        $attendance = app(AttendanceService::class);

        // ⓘ শুক্র ১১, বুধ ১৬ (বিজয় দিবস) — ছুটির দিন; সোম ১৪ — কাজের দিন
        foreach (['2026-12-11', '2026-12-16', '2026-12-14'] as $day) {
            $attendance->mark($employee, $day, Attendance::ABSENT);
        }

        // ⓘ বিনা বেতনে বৃহস্পতি ২৪ – শনি ২৬: কাজের দিন ২ (শুক্র ২৫ বাদ)
        $unpaid = $this->leave()->approve($this->leave()->apply($employee, $this->unpaid, '2026-12-24', '2026-12-26', '2'), $this->hr);
        $this->assertSame(2, Attendance::query()->where('leave_application_id', $unpaid->id)->count(), 'শুক্রবারে ছুটির সারি বসে না');

        // ⓘ পুরনো নিয়মে বসানো শুক্রবারের "ছুটি"-র সারি — ভাগ শূন্য, কাটে না
        Attendance::query()->create(['company_id' => $this->company->id, 'employee_id' => $employee->id, 'work_date' => '2026-12-25',
            'status' => Attendance::LEAVE, 'leave_application_id' => $unpaid->id]);

        $this->assertSame('3.0', $attendance->unpaidDays($employee, Carbon::parse('2026-12-01')),
            '⛔ ছুটির দিনের "অনুপস্থিত" বা বিনা বেতনের ছুটির মাঝের শুক্রবার বেতন থেকে কাটল (আশা: ১ কামাই + ২ দিন ছুটি)');
        $this->assertSame(3, $attendance->monthlySummary($employee, Carbon::parse('2026-12-01'))['absent'],
            'খাতার সারি তিনটাই থাকে — কেবল কাটে না');
    }

    public function test_the_weekly_off_setting_decides_the_off_day(): void
    {
        $employee = $this->employee('E-S', $this->dhaka);

        app(SettingsService::class)->set('hr.weekly_off', 'none');
        // ⓘ SettingsService cache — a6/settings-cache-fix
        app(SettingsService::class)->flush();

        // ⓘ শুক্রবার এখন কাজের দিন — রবি ১৩ – শনি ১৯-এ কেবল বিজয় দিবস বাদ, ৬ দিন
        $this->assertSame(LeaveApplication::PENDING,
            $this->leave()->apply($employee, $this->casual, '2026-12-13', '2026-12-19', '6')->status);
    }

    public function test_the_holiday_screen_keeps_its_doors_and_writes_the_audit(): void
    {
        $stranger = $this->person([]);
        $this->actingAs($stranger)->get(route('hr.holiday.index'))->assertForbidden();

        $viewer = $this->person(['hr.attendance.view']);
        $this->actingAs($viewer)->get(route('hr.holiday.index', ['year' => 2026]))->assertOk()->assertSee('Victory Day');
        $this->actingAs($viewer)->post(route('hr.holiday.store'), ['holiday_date' => '2026-03-26', 'name' => 'Independence Day'])
            ->assertForbidden();
        $this->assertSame(0, Holiday::query()->where('name', 'Independence Day')->count());

        // ⭐ ছুটির চাবিতেও দেখা যায়, লেখা যায় না — বসানো, বদলের পাতা, বদল, মোছা চারটাই ৪০৩ (fe-র উত্তর ৫, ২১ অক্টোবর ২০২৬)
        $leaveViewer = $this->person(['hr.leave.view']);
        $victory = Holiday::query()->where('name', 'Victory Day')->firstOrFail();
        $this->actingAs($leaveViewer)->get(route('hr.holiday.index', ['year' => 2026]))->assertOk()->assertSee('Victory Day');
        $this->actingAs($leaveViewer)->post(route('hr.holiday.store'), ['holiday_date' => '2026-03-26', 'name' => 'Independence Day'])->assertForbidden();
        $this->actingAs($leaveViewer)->get(route('hr.holiday.edit', $victory->id))->assertForbidden();
        $this->actingAs($leaveViewer)->put(route('hr.holiday.update', $victory->id), ['holiday_date' => '2026-12-16', 'name' => 'Changed'])->assertForbidden();
        $this->actingAs($leaveViewer)->delete(route('hr.holiday.destroy', $victory->id))->assertForbidden();
        $this->assertSame('Victory Day', $victory->fresh()->name);
        $this->assertSame(0, Holiday::query()->where('name', 'Independence Day')->count());

        $this->actingAs($this->hr)->post(route('hr.holiday.store'), ['holiday_date' => '2026-03-26', 'name' => 'Independence Day'])
            ->assertSessionHasNoErrors();
        $row = Holiday::query()->where('name', 'Independence Day')->firstOrFail();
        $this->assertNull($row->branch_id, 'শাখা খালি = সব শাখা');

        // ⛔ একই দিনে আবার নয়
        $this->actingAs($this->hr)->post(route('hr.holiday.store'), ['holiday_date' => '2026-03-26', 'name' => 'Again', 'branch_id' => $this->dhaka->id])
            ->assertSessionHasErrors(['holiday_date' => __('hr::validation.holiday_twice')]);

        $this->actingAs($this->hr)->put(route('hr.holiday.update', $row->id), ['holiday_date' => '2026-03-26', 'name' => 'Swadhinata Dibos'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Swadhinata Dibos', $row->fresh()->name);

        $this->actingAs($this->hr)->delete(route('hr.holiday.destroy', $row->id))->assertRedirect();
        $this->assertNull(Holiday::query()->find($row->id));

        $actions = AuditTrail::query()->forRecord(Holiday::class, (int) $row->id)->pluck('action')->all();
        foreach ([AuditTrail::CREATED, AuditTrail::UPDATED, AuditTrail::DELETED] as $action) {
            $this->assertContains($action, $actions, "⛔ ছুটির দিনের {$action} অডিটে নেই");
        }

        // ⛔ সীমিত নাগাল: সব-শাখার ছুটি নয়, অন্য শাখার সারিতে হাত নয়; নিজের শাখায় চলে
        $limited = $this->person(['hr.attendance.view', 'hr.attendance.manage'], $this->dhaka);
        $this->actingAs($limited)->post(route('hr.holiday.store'), ['holiday_date' => '2026-04-14', 'name' => 'Pohela Boishakh'])
            ->assertSessionHasErrors('branch_id');
        $theirs = Holiday::query()->create(['company_id' => $this->company->id, 'branch_id' => $this->netrokona->id,
            'holiday_date' => '2026-05-01', 'name' => 'Netrokona local']);
        $this->actingAs($limited)->delete(route('hr.holiday.destroy', $theirs->id))->assertForbidden();
        $this->actingAs($limited)->delete(route('hr.holiday.destroy', Holiday::query()->where('name', 'Victory Day')->value('id')))->assertForbidden();
        $this->assertNotNull($theirs->fresh());
        $this->actingAs($limited)->post(route('hr.holiday.store'), ['holiday_date' => '2026-04-14', 'name' => 'Pohela Boishakh', 'branch_id' => $this->dhaka->id])
            ->assertSessionHasNoErrors();
        $this->assertSame((int) $this->dhaka->id, (int) Holiday::query()->where('name', 'Pohela Boishakh')->value('branch_id'));
    }

    private function leave(): LeaveService
    {
        return app(LeaveService::class);
    }

    /** @param  list<string>  $keys */
    private function person(array $keys, ?Branch $limitedTo = null): User
    {
        $user = User::factory()->create(['current_company_id' => $this->company->id]);
        $user->companies()->attach($this->company->id);
        if ($keys !== []) {
            $user->givePermissionTo($keys);
        }

        if ($limitedTo !== null) {
            UserDataScope::query()->create(['company_id' => $this->company->id, 'user_id' => $user->id,
                'scope_type' => UserDataScope::BRANCH, 'scope_id' => $limitedTo->id]);
            app(DataScope::class)->forget();
        }

        return $user;
    }

    private function employee(string $code, ?Branch $branch): Employee
    {
        return Employee::query()->create(['company_id' => $this->company->id, 'branch_id' => $branch?->id, 'code' => $code,
            'name_en' => 'Person '.$code, 'joining_date' => '2026-01-01']);
    }
}
