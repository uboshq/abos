<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Support\BranchReach;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ দিনের হাজিরার খাতা মানক বারে, একটা লেখাও না হারিয়ে (২৪ নভেম্বর ২০২৬)।
 *
 * দাবি: না-পাঠানো কর্মী আর খালি অবস্থা — আগের সারি যেমন ছিল তেমন, নতুন সারি নয় (অনুপস্থিত ধরা হয় না); থামলে যা লেখা হয়েছিল ফেরে;
 * বারে তারিখ আর সংরক্ষণ, খোঁজা আর বিভাগের ঘরের নাম নেই (তাই পাতা আবার খোলে না), সব কর্মীর সারি পাতায়; SAP-এ আগের পাতা।
 */
final class TheDaySheetLosesNothingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private string $day;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->look('rose');
        $this->day = now()->toDateString();

        for ($i = Employee::query()->count(); $i < 3; $i++) {
            app(\App\Modules\Hr\Services\EmployeeService::class)->create(['name_en' => "Day Sheet {$i}", 'code' => "DAY-{$i}", 'payment_method' => 'cash', 'joining_date' => now()->subYear()->toDateString(),
                'branch_id' => $company->defaultBranch()?->id]);
        }
        $this->assertGreaterThan(2, $this->people()->count(), 'প্রস্তুতিটাই ভুল — তিনজন কর্মী নেই।');
    }

    public function test_people_not_sent_or_left_blank_are_neither_absent_nor_wiped(): void
    {
        [$sent, $blank, $missing] = $this->people()->take(3)->all();
        foreach ([$blank, $missing] as $e) {
            Attendance::writeDay($e->id, $this->day, ['status' => Attendance::PRESENT, 'is_late' => true, 'remarks' => 'আগের', 'created_by' => $this->owner->id]);
        }

        $this->post(route('hr.attendance.store'), ['work_date' => $this->day, 'rows' => [
            $sent->id => ['status' => Attendance::ABSENT], $blank->id => ['status' => '', 'remarks' => 'নতুন'],
        ]])->assertSessionHasNoErrors();

        $this->assertSame(Attendance::ABSENT, $this->row($sent)?->status);
        foreach ([$blank, $missing] as $e) {
            $this->assertSame([Attendance::PRESENT, true, 'আগের'], [$this->row($e)->status, (bool) $this->row($e)->is_late, $this->row($e)->remarks]);
        }
        $this->assertSame(3, Attendance::query()->whereDate('work_date', $this->day)->whereIn('employee_id', [$sent->id, $blank->id, $missing->id])->count());
    }

    public function test_a_stopped_save_gives_back_what_was_written(): void
    {
        $e = $this->people()->first();
        $html = (string) $this->followingRedirects()->from(route('hr.attendance.index', ['date' => $this->day]))
            ->post(route('hr.attendance.store'), ['work_date' => $this->day, 'rows' => [
                $e->id => ['status' => 'no-such-status', 'is_late' => '1', 'remarks' => 'লেখা হারাবে না'],
            ]])->assertOk()->getContent();

        $this->assertStringContainsString('লেখা হারাবে না', $html);
        $this->assertNull($this->row($e));
    }

    public function test_an_untouched_row_is_not_written_and_a_mark_made_elsewhere_survives(): void
    {
        [$mine, $theirs, $blank] = $this->people()->take(3)->all();
        $first = User::factory()->create();
        foreach ([$mine, $theirs] as $e) {
            Attendance::writeDay($e->id, $this->day, ['status' => Attendance::PRESENT, 'is_late' => false, 'remarks' => null, 'created_by' => $first->id]);
        }
        $was = fn ($e) => Attendance::stampOfRow($this->row($e));
        $page = [$mine->id => ['status' => Attendance::PRESENT, 'remarks' => '', 'was' => $was($mine)],
            $theirs->id => ['status' => Attendance::PRESENT, 'remarks' => null, 'was' => $was($theirs)],
            $blank->id => ['status' => '', 'was' => $was($blank)]];
        $audits = \App\Models\AuditTrail::query()->where('auditable_type', Attendance::class)->count();

        // পাতা খোলার পরে: ফোনে একজন অনুপস্থিত, আরেকজনের নতুন হাজিরা
        Attendance::writeDay($theirs->id, $this->day, ['status' => Attendance::ABSENT, 'remarks' => 'ফোন']);
        Attendance::writeDay($blank->id, $this->day, ['status' => Attendance::HOLIDAY, 'created_by' => $first->id]);
        $after = \App\Models\AuditTrail::query()->where('auditable_type', Attendance::class)->count();
        $this->assertGreaterThan($audits, $after, 'প্রস্তুতিটাই ভুল — বদল অডিটে ওঠে না।');

        $this->post(route('hr.attendance.store'), ['work_date' => $this->day, 'rows' => $page])->assertSessionHasNoErrors();

        $this->assertSame([Attendance::ABSENT, 'ফোন'], [$this->row($theirs)->status, $this->row($theirs)->remarks], '⛔ অন্যের বদল চাপা পড়ল।');
        $this->assertSame(Attendance::HOLIDAY, $this->row($blank)->status);
        $this->assertSame((int) $first->id, (int) $this->row($mine)->created_by, '⛔ না-ছোঁয়া সারির লেখক বদলাল।');
        $this->assertSame($after, \App\Models\AuditTrail::query()->where('auditable_type', Attendance::class)->count(), '⛔ না-ছোঁয়া সারির অডিট সারি।');
    }

    public function test_the_same_mark_changed_by_both_is_refused_by_name_and_the_rest_is_saved(): void
    {
        [$both, $other] = $this->people()->take(2)->all();
        Attendance::writeDay($both->id, $this->day, ['status' => Attendance::PRESENT, 'created_by' => $this->owner->id]);
        $rows = [$both->id => ['status' => Attendance::LEAVE, 'remarks' => 'আমার লেখা', 'was' => Attendance::stampOfRow($this->row($both))],
            $other->id => ['status' => Attendance::PRESENT, 'was' => Attendance::stampOfRow($this->row($other))]];
        Attendance::writeDay($both->id, $this->day, ['status' => Attendance::ABSENT]);

        $html = (string) $this->followingRedirects()->post(route('hr.attendance.store'), ['work_date' => $this->day, 'rows' => $rows])->assertOk()->getContent();

        $this->assertSame(Attendance::ABSENT, $this->row($both)->status, '⛔ অন্যের বদল চাপা পড়ল।');
        $this->assertSame(Attendance::PRESENT, $this->row($other)?->status, '⛔ বাকি সারি বসেনি।');
        $this->assertStringContainsString(e(__('hr::message.attendance_changed_meanwhile', ['name' => $both->name(), 'now' => __('hr::kind.absent')])), $html);
        $this->assertStringContainsString('আমার লেখা', $html, '⛔ যা লেখা হয়েছিল হারাল।');
        $this->assertStringContainsString(Attendance::stampOfRow($this->row($both)), $html, 'আবার চাপার জন্য এখনকার ছাপ চাই।');

        $rows[$both->id]['was'] = Attendance::stampOfRow($this->row($both));
        $this->post(route('hr.attendance.store'), ['work_date' => $this->day, 'rows' => [$both->id => $rows[$both->id]]])->assertSessionHasNoErrors();
        $this->assertSame([Attendance::LEAVE, 'আমার লেখা'], [$this->row($both)->status, $this->row($both)->remarks]);
    }

    public function test_without_the_stamp_it_is_as_before_and_a_leave_link_stays(): void
    {
        $e = $this->people()->first();
        $type = \App\Modules\Hr\Models\LeaveType::query()->create(['code' => 'DAYSHEET', 'name_en' => 'Day sheet leave', 'days_per_year' => '10', 'is_paid' => true, 'is_active' => true]);
        $leave = \App\Modules\Hr\Models\LeaveApplication::query()->create(['employee_id' => $e->id, 'leave_type_id' => $type->id,
            'from_date' => $this->day, 'to_date' => $this->day, 'days' => 1, 'reason' => 'x', 'status' => 'approved']);
        Attendance::writeDay($e->id, $this->day, ['status' => Attendance::LEAVE, 'leave_application_id' => $leave->id, 'created_by' => $this->owner->id]);

        $this->post(route('hr.attendance.store'), ['work_date' => $this->day, 'rows' => [$e->id => ['status' => Attendance::PRESENT]]])->assertSessionHasNoErrors();
        $this->assertSame([Attendance::PRESENT, (int) $leave->id], [$this->row($e)->status, (int) $this->row($e)->leave_application_id]);
    }

    public function test_the_stamp_treats_empty_and_null_alike_and_sees_every_box(): void
    {
        $base = Attendance::stampOf('present', false, null);
        $this->assertSame($base, Attendance::stampOf('present', false, '  '));
        foreach ([['absent', false, null], ['present', true, null], ['present', false, 'x'], [null, false, null]] as $other) {
            $this->assertNotSame($base, Attendance::stampOf(...$other));
        }
        $this->assertSame(Attendance::stampOf(null, false, null), Attendance::stampOfRow(null));
    }

    public function test_the_bar_holds_the_date_and_save_and_nothing_in_it_reloads_the_sheet(): void
    {
        foreach (['rose', 'navy', 'blue'] as $look) {
            $this->look($look);
            $html = (string) $this->get(route('hr.attendance.index', ['date' => $this->day]))->assertOk()->getContent();
            $this->assertStringContainsString('form="attendance-form"', $html);
            $this->assertStringContainsString('name="date"', $html);
            $this->assertSame(1, preg_match('/<input type="search"[^>]*data-day-find[^>]*>/s', $html, $find));
            $this->assertStringNotContainsString(' name=', $find[0], '⛔ খোঁজার ঘরের নাম আছে — ঠিকানায় গিয়ে পাতা আবার খুলবে।');
            $this->assertSame($this->people()->count(), substr_count($html, 'data-day-row'), '⛔ কোনো কর্মীর সারি পাতায় নেই।');
        }

        $this->look('tiles');
        $this->get(route('hr.attendance.index'))->assertOk()->assertDontSee('data-day-find', false)->assertDontSee('id="list-form"', false)
            ->assertSee(__('hr::action.save_attendance'));
    }

    private function people(): \Illuminate\Support\Collection
    {
        return app(BranchReach::class)->employeesInView(Employee::query(), $this->owner)->onPayrollFor(now())->orderBy('code')->get();
    }

    private function row(Employee $e): ?Attendance
    {
        return Attendance::query()->where('employee_id', $e->id)->whereDate('work_date', $this->day)->first();
    }

    private function look(string $look): void
    {
        User::query()->whereKey($this->owner->id)->update(['ui' => $look]);
        $this->owner = User::query()->findOrFail($this->owner->id);
        $this->actingAs($this->owner);
    }
}
