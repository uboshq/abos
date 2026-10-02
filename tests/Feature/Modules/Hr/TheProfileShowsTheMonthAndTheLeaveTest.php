<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\LeaveApplication;
use App\Modules\Hr\Models\LeaveType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * কর্মীর প্রোফাইলে মাস আর ছুটি — মালিকের অনুমোদিত নকশা, ১ অক্টোবর ২০২৬ (১ নম্বরের কভার, ৩ নম্বরের হাজিরা ও ছুটি)।
 *
 * ⭐ ক্যালেন্ডারের প্রতিটা দিন হাজিরার খাতার সারি থেকেই — দেরিতে আসা দিনটা "দেরি", অনুপস্থিতটা "অনুপস্থিত",
 * আর যে দিন লেখা হয়নি সেটা "লেখা হয়নি" (অনুপস্থিত নয় — বেতনের নিয়মও তাই বলে)।
 * ⭐ ছুটির হিসাব মঞ্জুর আবেদন থেকে গোনা।
 * ⛔ একই মানুষ: হাজিরা আর ছুটির চাবি ছাড়া প্রোফাইল খোলে, কিন্তু ঐ দুইটা অংশ নেই; চাবি পেলে আসে।
 */
final class TheProfileShowsTheMonthAndTheLeaveTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Employee $employee;

    private LeaveType $casual;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['code' => 'PRF', 'name_en' => 'Profile Co']);
        $branch = Branch::query()->create(['company_id' => $this->company->id, 'code' => 'MYM', 'name_en' => 'Mymensingh', 'is_active' => true]);
        CompanyContext::set($this->company->id, $branch->id);

        foreach (['hr.employee.view', 'hr.attendance.view', 'hr.leave.view'] as $key) {
            Permission::findOrCreate($key, 'web');
        }

        $this->employee = Employee::query()->create([
            'company_id' => $this->company->id, 'branch_id' => $branch->id, 'code' => 'E-42',
            'name_en' => 'Karim Uddin', 'joining_date' => '2024-03-01',
        ]);

        foreach ([['2026-09-01', Attendance::PRESENT, false], ['2026-09-02', Attendance::PRESENT, true], ['2026-09-03', Attendance::ABSENT, false]] as [$day, $status, $late]) {
            Attendance::query()->create([
                'company_id' => $this->company->id, 'employee_id' => $this->employee->id,
                'work_date' => $day, 'status' => $status, 'is_late' => $late,
            ]);
        }

        $this->casual = LeaveType::query()->create([
            'company_id' => $this->company->id, 'code' => 'CL', 'name_en' => 'Casual', 'name_bn' => 'নৈমিত্তিক',
            'days_per_year' => '10', 'is_paid' => true, 'is_active' => true,
        ]);

        LeaveApplication::query()->create([
            'company_id' => $this->company->id, 'employee_id' => $this->employee->id, 'leave_type_id' => $this->casual->id,
            'from_date' => '2026-09-10', 'to_date' => '2026-09-12', 'days' => '3', 'status' => LeaveApplication::APPROVED,
        ]);
    }

    public function test_the_month_and_the_leave_come_from_the_books_and_only_with_their_keys(): void
    {
        $user = User::factory()->create(['current_company_id' => $this->company->id]);
        $user->companies()->attach($this->company->id);
        $user->givePermissionTo('hr.employee.view');

        $url = route('hr.employee.show', ['employee' => $this->employee, 'month' => '2026-09']);

        $closed = (string) $this->actingAs($user)->get($url)->assertOk()->getContent();
        $this->assertStringContainsString('data-profile-cover', $closed, 'প্রোফাইলের কভার নেই।');
        $this->assertStringNotContainsString('data-profile-attendance', $closed, '⛔ হাজিরা আর ছুটির চাবি ছাড়াই অংশটা দেখা গেছে।');

        $user->givePermissionTo(['hr.attendance.view', 'hr.leave.view']);
        $response = $this->actingAs($user->fresh())->get($url)->assertOk();
        $html = (string) $response->getContent();

        $this->assertStringContainsString('data-profile-attendance', $html, 'চাবি পেয়েও হাজিরা আর ছুটি আসেনি।');

        $summary = $response->viewData('attendance')['summary'];
        $this->assertSame(2, $summary['present']);
        $this->assertSame(1, $summary['late']);
        $this->assertSame(1, $summary['absent']);
        $this->assertSame(3, $summary['marked']);

        $this->assertStringContainsString('title="02 Sep · '.e(__('hr::profile.late')).'"', $html, '২ তারিখ দেরিতে, অথচ ক্যালেন্ডারে "দেরি" নয়।');
        $this->assertStringContainsString('title="03 Sep · '.e(__('hr::kind.absent')).'"', $html);
        $this->assertStringContainsString('title="04 Sep · '.e(__('hr::profile.not_written')).'"', $html, '⛔ যে দিন লেখা হয়নি সেটা অন্য কিছু দেখাচ্ছে।');

        $leave = $response->viewData('leave');
        $this->assertCount(1, $leave);
        $this->assertSame('3.0', $leave[0]['taken']);
        $this->assertSame('7.0', $leave[0]['left']);
        $this->assertStringContainsString(e(__('hr::profile.left', ['left' => '7.0'])), $html);
    }

    public function test_a_wrong_month_in_the_address_falls_back_to_this_month(): void
    {
        $user = User::factory()->create(['current_company_id' => $this->company->id]);
        $user->companies()->attach($this->company->id);
        $user->givePermissionTo(['hr.employee.view', 'hr.attendance.view']);

        $response = $this->actingAs($user)->get(route('hr.employee.show', ['employee' => $this->employee, 'month' => '2026-13']))->assertOk();

        $this->assertSame(now()->format('Y-m'), $response->viewData('month')->format('Y-m'));
    }
}
