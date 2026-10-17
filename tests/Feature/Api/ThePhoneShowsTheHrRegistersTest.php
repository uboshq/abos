<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Services\DataScope;
use App\Core\Services\PhoneModules;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\LeaveApplication;
use App\Modules\Hr\Models\LeaveType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * ⭐ হাজিরা আর ছুটির রেজিস্টার ফোনের নিজের পর্দায় — মালিক, ১৭ অক্টোবর ২০২৬ ([[HrRegisterApiController]])।
 *
 * দাবি: দুই রেজিস্টারই শাখার দেয়ালে (নাগালের বাইরের কর্মী নেই, তার আবেদনে সিদ্ধান্ত ৪০৩); হাজিরা দিন ধরে, অবস্থার গোনা আর মোটসহ;
 * ⛔ নিজের ছুটি নিজে মঞ্জুর নয় — বোতামও নেই, দরজাও ৪২২; অন্যের ছুটি মঞ্জুর হয়; ফেরতে কারণ বাধ্যতামূলক; চাবি ছাড়া ৪০৩।
 */
final class ThePhoneShowsTheHrRegistersTest extends TestCase
{
    use RefreshDatabase;

    private const KEYS = ['hr.leave.view', 'hr.leave.approve', 'hr.attendance.view'];

    private Company $company;

    private Branch $dhaka;

    private User $manager;

    private Employee $mine;

    private Employee $colleague;

    private Employee $outside;

    private LeaveType $casual;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['code' => 'HRP', 'name_en' => 'Phone HR Co']);
        CompanyContext::set($this->company->id);
        app(SettingsService::class)->set(PhoneModules::key('hr'), true);

        $this->dhaka = Branch::query()->create(['company_id' => $this->company->id, 'code' => 'DHK', 'name_en' => 'Dhaka', 'is_active' => true]);
        $netrokona = Branch::query()->create(['company_id' => $this->company->id, 'code' => 'NTK', 'name_en' => 'Netrokona', 'is_active' => true]);

        foreach (self::KEYS as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $this->manager = User::factory()->create(['current_company_id' => $this->company->id]);
        $this->manager->companies()->attach($this->company->id);
        $this->manager->givePermissionTo(self::KEYS);
        UserDataScope::query()->create(['company_id' => $this->company->id, 'user_id' => $this->manager->id,
            'scope_type' => UserDataScope::BRANCH, 'scope_id' => $this->dhaka->id]);
        app(DataScope::class)->forget();

        $this->mine = $this->employee('E-1', $this->dhaka, 'Manager Self', $this->manager->id);
        $this->colleague = $this->employee('E-2', $this->dhaka, 'Dhaka Colleague');
        $this->outside = $this->employee('E-3', $netrokona, 'Netrokona Person');

        $this->casual = LeaveType::query()->create(['company_id' => $this->company->id, 'code' => 'CL', 'name_en' => 'Casual',
            'name_bn' => 'নৈমিত্তিক', 'days_per_year' => '10', 'is_paid' => true, 'is_active' => true]);
    }

    public function test_the_attendance_register_counts_the_day_inside_the_branch(): void
    {
        foreach ([[$this->mine, Attendance::PRESENT], [$this->outside, Attendance::ABSENT]] as [$e, $status]) {
            Attendance::query()->create(['company_id' => $this->company->id, 'employee_id' => $e->id, 'work_date' => '2026-10-05', 'status' => $status]);
        }

        $day = $this->phone()->getJson('/api/v1/hr/attendance?date=2026-10-05')->assertOk();

        $this->assertSame(['E-1', 'E-2'], array_column($day->json('rows'), 'code'), '⛔ নাগালের বাইরের কর্মী রেজিস্টারে।');
        $this->assertSame(2, $day->json('count'));
        $counts = collect($day->json('counts'))->pluck('count', 'status');
        $this->assertSame(1, $counts['present']);
        $this->assertSame(0, $counts['absent'], '⛔ অন্য শাখার অনুপস্থিতি এই শাখার গোনায়।');
        $this->assertSame(1, $counts['unmarked']);
        $this->assertSame('2026-10-05', $day->json('date'));
    }

    public function test_one_cannot_approve_ones_own_leave_but_can_approve_a_colleagues_and_refusing_needs_a_reason(): void
    {
        $own = $this->leaveFor($this->mine);
        $theirs = $this->leaveFor($this->colleague);
        $outside = $this->leaveFor($this->outside);

        $rows = collect($this->phone()->getJson('/api/v1/hr/leave/register?pending=1')->assertOk()->json('rows'))->keyBy('id');
        $this->assertEqualsCanonicalizing([(string) $own->public_id, (string) $theirs->public_id], $rows->keys()->all(),
            '⛔ নাগালের বাইরের আবেদন তালিকায়, বা নাগালেরটা নেই।');
        $this->assertFalse($rows[(string) $own->public_id]['can_decide'], '⛔ নিজের আবেদনে মঞ্জুরের বোতাম।');
        $this->assertTrue($rows[(string) $theirs->public_id]['can_decide']);

        $this->phone()->postJson('/api/v1/hr/leave/register/'.$own->public_id.'/approve')->assertUnprocessable();
        $this->assertSame(LeaveApplication::PENDING, $own->fresh()->status, '⛔ নিজের ছুটি নিজে মঞ্জুর হলো।');

        $this->phone()->postJson('/api/v1/hr/leave/register/'.$outside->public_id.'/approve')->assertForbidden();
        $this->assertSame(LeaveApplication::PENDING, $outside->fresh()->status);

        $this->phone()->postJson('/api/v1/hr/leave/register/'.$theirs->public_id.'/reject')->assertUnprocessable()->assertJsonValidationErrors('remarks');
        $this->phone()->postJson('/api/v1/hr/leave/register/'.$theirs->public_id.'/approve')->assertOk();
        $this->assertSame(LeaveApplication::APPROVED, $theirs->fresh()->status);
    }

    public function test_without_the_keys_the_registers_are_shut(): void
    {
        $clerk = User::factory()->create(['current_company_id' => $this->company->id]);
        $clerk->companies()->attach($this->company->id);

        $this->phone($clerk)->getJson('/api/v1/hr/attendance')->assertForbidden();
        $this->phone($clerk)->getJson('/api/v1/hr/leave/register')->assertForbidden();
        $this->phone($clerk)->postJson('/api/v1/hr/leave/register/'.$this->leaveFor($this->colleague)->public_id.'/approve')->assertForbidden();
    }

    private function phone(?User $user = null): static
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(($user ?? $this->manager)->fresh(), [AuthController::APP]);

        return $this;
    }

    private function employee(string $code, Branch $branch, string $name, ?int $user = null): Employee
    {
        return Employee::query()->create(['company_id' => $this->company->id, 'branch_id' => $branch->id, 'code' => $code,
            'name_en' => $name, 'joining_date' => '2026-01-01', 'user_id' => $user]);
    }

    private function leaveFor(Employee $employee): LeaveApplication
    {
        return LeaveApplication::query()->create(['company_id' => $this->company->id, 'employee_id' => $employee->id,
            'leave_type_id' => $this->casual->id, 'from_date' => '2026-10-05', 'to_date' => '2026-10-05', 'days' => '1',
            'status' => LeaveApplication::PENDING]);
    }
}
