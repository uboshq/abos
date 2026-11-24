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
            app(\App\Modules\Hr\Services\EmployeeService::class)->create(['name_en' => "Day Sheet {$i}", 'joining_date' => now()->subYear()->toDateString(),
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
