<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Services\DataScope;
use App\Core\Services\PermissionSyncer;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\LeaveApplication;
use App\Modules\Hr\Models\LeaveType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * হাজিরা আর ছুটি শাখার দেয়ালের ভেতরে — চূড়ান্ত অডিট ⛔১৭, ৩০ সেপ্টেম্বর ২০২৬।
 *
 * ── ⛔ কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * কর্মী, বেতনের কাঠামো আর বেতনের পাতা শাখা মানত ([[AnotherBranchesPayslipWasOneAddressAwayTest]]); হাজিরা আর
 * ছুটি মানত না। ঢাকায় সীমিত ব্যবস্থাপক নেত্রকোনার কর্মীর ছুটি **অনুমোদন** করতে পারতেন, তালিকায় সবার আবেদন
 * দেখতেন, আর হাজিরার পাতায় অন্য শাখার কর্মীর দিন বসাতে পারতেন — আর বেতন সেই হাজিরা ধরেই কাটে।
 *
 * ── ⓘ দাবির নিয়ম ─────────────────────────────────────────────────────
 * একই মানুষ, একই কাগজ — কেবল শাখার নাগাল বদলায় ([[a-door-claim-needs-one-actor-twice]]): নেত্রকোনা নাগালের
 * বাইরে থাকলে দরজা বন্ধ, নাগালে এলে খোলা। ⭐ মালিকের শর্ত: super_admin-কে দেয়াল কখনো আটকায় না।
 */
final class AttendanceAndLeaveStayInsideTheBranchTest extends TestCase
{
    use RefreshDatabase;

    private const KEYS = ['hr.leave.view', 'hr.leave.manage', 'hr.leave.approve', 'hr.attendance.view', 'hr.attendance.manage'];

    private Company $company;

    private Branch $dhaka;

    private Branch $netrokona;

    private User $manager;

    private Employee $ours;

    private Employee $theirs;

    private LeaveType $casual;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['code' => 'HRW', 'name_en' => 'Wall Co']);
        CompanyContext::set($this->company->id);

        $this->dhaka = Branch::query()->create([
            'company_id' => $this->company->id, 'code' => 'DHK', 'name_en' => 'Dhaka', 'is_active' => true,
        ]);
        $this->netrokona = Branch::query()->create([
            'company_id' => $this->company->id, 'code' => 'NTK', 'name_en' => 'Netrokona', 'is_active' => true,
        ]);

        foreach (self::KEYS as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $this->manager = $this->person(limitedTo: $this->dhaka);

        $this->ours = $this->employee('E-1', $this->dhaka, 'Dhaka Person');
        $this->theirs = $this->employee('E-2', $this->netrokona, 'Netrokona Person');

        $this->casual = LeaveType::query()->create([
            'company_id' => $this->company->id, 'code' => 'CL', 'name_en' => 'Casual', 'name_bn' => 'নৈমিত্তিক',
            'days_per_year' => '10', 'is_paid' => true, 'is_active' => true,
        ]);
    }

    public function test_another_branches_leave_cannot_be_approved_until_that_branch_is_reached(): void
    {
        $theirLeave = $this->leaveFor($this->theirs);

        $this->actingAs($this->manager)
            ->post(route('hr.leave.approve', $theirLeave->id))
            ->assertForbidden();

        $this->assertSame(LeaveApplication::PENDING, $theirLeave->fresh()->status, 'দেয়াল থাকতেও ছুটি অনুমোদন হয়ে গেছে।');

        // ⓘ একই মানুষ — এবার নেত্রকোনাও নাগালে
        $this->reach($this->manager, $this->netrokona);

        $this->actingAs($this->manager)
            ->post(route('hr.leave.approve', $theirLeave->id))
            ->assertRedirect();

        $this->assertSame(LeaveApplication::APPROVED, $theirLeave->fresh()->status, 'নাগালে আসার পরেও দরজা খোলেনি — দেয়ালটা সবাইকে আটকাচ্ছে।');
    }

    public function test_another_branches_leave_cannot_be_rejected_or_cancelled(): void
    {
        $theirLeave = $this->leaveFor($this->theirs);

        $this->actingAs($this->manager)
            ->post(route('hr.leave.reject', $theirLeave->id), ['remarks' => 'no'])
            ->assertForbidden();

        $this->actingAs($this->manager)
            ->post(route('hr.leave.cancel', $theirLeave->id))
            ->assertForbidden();

        $this->assertSame(LeaveApplication::PENDING, $theirLeave->fresh()->status);
    }

    public function test_the_leave_list_and_the_apply_form_keep_to_the_branch(): void
    {
        $this->leaveFor($this->ours);
        $this->leaveFor($this->theirs);

        $list = (string) $this->actingAs($this->manager)->get(route('hr.leave.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Dhaka Person', $list);
        $this->assertStringNotContainsString('Netrokona Person', $list, 'ছুটির তালিকায় অন্য শাখার কর্মীর আবেদন দেখা যায়।');

        $form = (string) $this->actingAs($this->manager)->get(route('hr.leave.create'))->assertOk()->getContent();
        $this->assertStringNotContainsString('Netrokona Person', $form, 'আবেদনের ফর্মে অন্য শাখার কর্মী বাছা যায়।');

        $this->actingAs($this->manager)->post(route('hr.leave.store'), [
            'employee_id' => $this->theirs->id, 'leave_type_id' => $this->casual->id,
            'from_date' => '2026-10-05', 'to_date' => '2026-10-05', 'days' => '1',
        ])->assertForbidden();
    }

    public function test_another_branches_attendance_cannot_be_marked_or_seen(): void
    {
        $screen = (string) $this->actingAs($this->manager)
            ->get(route('hr.attendance.index', ['date' => '2026-10-05']))->assertOk()->getContent();
        $this->assertStringContainsString('Dhaka Person', $screen);
        $this->assertStringNotContainsString('Netrokona Person', $screen, 'হাজিরার পাতায় অন্য শাখার কর্মী।');

        $this->actingAs($this->manager)->post(route('hr.attendance.store'), [
            'work_date' => '2026-10-05',
            'rows' => [$this->theirs->id => ['status' => Attendance::PRESENT]],
        ])->assertForbidden();

        $this->assertSame(0, Attendance::query()->where('employee_id', $this->theirs->id)->count(), 'দেয়াল থাকতেও অন্য শাখার হাজিরা বসেছে।');

        // ⓘ নিজের শাখার হাজিরা আগের মতোই বসে
        $this->actingAs($this->manager)->post(route('hr.attendance.store'), [
            'work_date' => '2026-10-05',
            'rows' => [$this->ours->id => ['status' => Attendance::PRESENT]],
        ])->assertRedirect();

        $this->assertSame(1, Attendance::query()->where('employee_id', $this->ours->id)->count());
    }

    /** ⭐ মালিকের শর্ত — super_admin-কে শাখার সারি থাকলেও দেয়াল আটকায় না */
    public function test_the_super_admin_is_never_walled_off(): void
    {
        $owner = $this->person(limitedTo: $this->dhaka);
        $owner->assignRole(Role::findOrCreate(PermissionSyncer::SUPER_ADMIN_ROLE, 'web'));

        $theirLeave = $this->leaveFor($this->theirs);

        $this->actingAs($owner)
            ->post(route('hr.leave.approve', $theirLeave->id))
            ->assertRedirect();

        $this->assertSame(LeaveApplication::APPROVED, $theirLeave->fresh()->status);
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function person(Branch $limitedTo): User
    {
        $user = User::factory()->create(['current_company_id' => $this->company->id]);
        $user->companies()->attach($this->company->id);
        $user->givePermissionTo(self::KEYS);
        $this->reach($user, $limitedTo);

        return $user;
    }

    private function reach(User $user, Branch $branch): void
    {
        UserDataScope::query()->create([
            'company_id' => $this->company->id,
            'user_id' => $user->id,
            'scope_type' => UserDataScope::BRANCH,
            'scope_id' => $branch->id,
        ]);
        app(DataScope::class)->forget();
    }

    private function employee(string $code, Branch $branch, string $name): Employee
    {
        return Employee::query()->create([
            'company_id' => $this->company->id,
            'branch_id' => $branch->id,
            'code' => $code,
            'name_en' => $name,
            'joining_date' => '2026-01-01',
        ]);
    }

    private function leaveFor(Employee $employee): LeaveApplication
    {
        return LeaveApplication::query()->create([
            'company_id' => $this->company->id,
            'employee_id' => $employee->id,
            'leave_type_id' => $this->casual->id,
            'from_date' => '2026-10-05',
            'to_date' => '2026-10-05',
            'days' => '1',
            'status' => LeaveApplication::PENDING,
        ]);
    }
}
