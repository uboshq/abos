<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Services\PhoneModules;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\LeaveApplication;
use App\Modules\Hr\Models\LeaveType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ ছুটি যায় কর্মীর নিজের ব্যবস্থাপকের কাছে, আর HR-এর কাছে (ঘাটতির তালিকা #১৫; a6, ২১ অক্টোবর ২০২৬; [[LeaveSigner]])।
 *
 * দাবি: কর্মীর নিজের ব্যবস্থাপক (`reports_to`) সই দেন আর ফেরান — ওয়েবে আর ফোনে; চাবি আছে অথচ ব্যবস্থাপক নন, HR-ও নন — ৪০৩, দুই
 * দরজাতেই, আর বোতামও দেখেন না; HR (`hr.leave.manage`) যেকারও ছুটিতে সই দেন; ব্যবস্থাপক নেই এমন কর্মীর ছুটি কেবল HR-এর;
 * কেউ নিজের ছুটিতে সই দেন না, ফেরানও না; ছেড়ে যাওয়া (নিষ্ক্রিয়) ব্যবস্থাপক আর সই দেন না।
 */
final class LeaveGoesToTheLineManagerTest extends TestCase
{
    use RefreshDatabase;

    private const SIGNS = ['hr.leave.view', 'hr.leave.approve'];

    private Company $company;

    private Branch $dhaka;

    private User $boss;

    private User $stranger;

    private User $hr;

    private Employee $bossRow;

    private Employee $worker;

    private Employee $orphan;

    private Employee $hrRow;

    private LeaveType $casual;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['code' => 'HLM', 'name_en' => 'Line Co']);
        CompanyContext::set($this->company->id);
        app(SettingsService::class)->set(PhoneModules::key('hr'), true);

        $this->dhaka = Branch::query()->create(['company_id' => $this->company->id, 'code' => 'DHK', 'name_en' => 'Dhaka', 'is_active' => true]);

        foreach ([...self::SIGNS, 'hr.leave.manage'] as $key) {
            Permission::findOrCreate($key, 'web');
        }

        $this->boss = $this->user(self::SIGNS);
        $this->stranger = $this->user(self::SIGNS);
        $this->hr = $this->user([...self::SIGNS, 'hr.leave.manage']);

        $this->bossRow = $this->employee('E-B', 'Boss Person', $this->boss->id);
        $this->employee('E-S', 'Stranger Person', $this->stranger->id);
        $this->hrRow = $this->employee('E-H', 'Hr Person', $this->hr->id);
        $this->worker = $this->employee('E-W', 'Worker Person', null, $this->bossRow->id);
        $this->orphan = $this->employee('E-O', 'Orphan Person');

        $this->casual = LeaveType::query()->create(['company_id' => $this->company->id, 'code' => 'CL', 'name_en' => 'Casual',
            'days_per_year' => '10', 'is_paid' => true, 'is_active' => true]);
    }

    public function test_the_employees_own_manager_signs_and_refuses_on_the_web_and_the_phone(): void
    {
        $first = $this->leaveFor($this->worker, '2026-10-05');
        $second = $this->leaveFor($this->worker, '2026-10-12');

        // ⭐ বোতাম ব্যবস্থাপকের পাতায় আছে
        $this->web($this->boss)->get(route('hr.leave.index'))->assertOk()->assertSee(route('hr.leave.approve', $first->id));

        $this->web($this->boss)->post(route('hr.leave.approve', $first->id))->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(LeaveApplication::APPROVED, $first->fresh()->status);
        $this->assertSame((int) $this->boss->id, (int) $first->fresh()->decided_by);

        $rows = collect($this->phone($this->boss)->getJson('/api/v1/hr/leave/register?pending=1')->assertOk()->json('rows'))->keyBy('id');
        $this->assertTrue($rows[(string) $second->public_id]['can_decide'], '⛔ নিজের দলের ছুটিতে ব্যবস্থাপকের বোতাম নেই।');

        $this->phone($this->boss)->postJson('/api/v1/hr/leave/register/'.$second->public_id.'/reject', ['remarks' => 'কাজের চাপ'])->assertOk();
        $this->assertSame(LeaveApplication::REJECTED, $second->fresh()->status);
    }

    public function test_a_key_holder_who_is_neither_the_manager_nor_hr_gets_403_on_both_doors(): void
    {
        $leave = $this->leaveFor($this->worker, '2026-10-05');

        $this->web($this->stranger)->get(route('hr.leave.index'))->assertOk()
            ->assertSee('Worker Person')
            ->assertDontSee(route('hr.leave.approve', $leave->id));

        $this->web($this->stranger)->post(route('hr.leave.approve', $leave->id))->assertForbidden();
        $this->web($this->stranger)->post(route('hr.leave.reject', $leave->id), ['remarks' => 'না'])->assertForbidden();

        $rows = collect($this->phone($this->stranger)->getJson('/api/v1/hr/leave/register?pending=1')->assertOk()->json('rows'))->keyBy('id');
        $this->assertFalse($rows[(string) $leave->public_id]['can_decide'], '⛔ অন্য দলের ছুটিতে সইয়ের বোতাম।');

        $this->phone($this->stranger)->postJson('/api/v1/hr/leave/register/'.$leave->public_id.'/approve')->assertForbidden();
        $this->phone($this->stranger)->postJson('/api/v1/hr/leave/register/'.$leave->public_id.'/reject', ['remarks' => 'না'])->assertForbidden();

        $this->assertSame(LeaveApplication::PENDING, $leave->fresh()->status, '⛔ ব্যবস্থাপক নন এমন কেউ ছুটিতে সই দিলেন।');
    }

    public function test_hr_signs_anyones_leave_and_a_leave_with_no_manager_falls_to_hr(): void
    {
        $orphans = $this->leaveFor($this->orphan, '2026-10-05');
        $workers = $this->leaveFor($this->worker, '2026-10-06');

        // ⛔ ব্যবস্থাপক নেই — অন্য দলের ব্যবস্থাপকের হাতে নয়
        $this->web($this->boss)->post(route('hr.leave.approve', $orphans->id))->assertForbidden();
        $this->assertSame(LeaveApplication::PENDING, $orphans->fresh()->status);

        $this->web($this->hr)->get(route('hr.leave.index'))->assertOk()->assertSee(route('hr.leave.approve', $orphans->id));
        $this->web($this->hr)->post(route('hr.leave.approve', $orphans->id))->assertSessionHasNoErrors();
        $this->assertSame(LeaveApplication::APPROVED, $orphans->fresh()->status);

        // ⭐ HR অন্যের দলের ছুটিতেও — ফোনে
        $this->phone($this->hr)->postJson('/api/v1/hr/leave/register/'.$workers->public_id.'/approve')->assertOk();
        $this->assertSame(LeaveApplication::APPROVED, $workers->fresh()->status);
    }

    public function test_nobody_signs_or_refuses_their_own_leave(): void
    {
        $hrsOwn = $this->leaveFor($this->hrRow, '2026-10-05');
        $bossOwn = $this->leaveFor($this->bossRow, '2026-10-06');

        // ⓘ HR-জুড়ে হলেও নিজের আবেদনে বোতাম নেই — দরজা যা ফেরায়, বোতামও দেখায় না
        $this->web($this->hr)->get(route('hr.leave.index'))->assertOk()->assertDontSee(route('hr.leave.approve', $hrsOwn->id));
        $this->web($this->hr)->post(route('hr.leave.approve', $hrsOwn->id))->assertSessionHasErrors('status');
        $this->web($this->hr)->post(route('hr.leave.reject', $hrsOwn->id), ['remarks' => 'নিজে'])->assertSessionHasErrors('status');
        $this->phone($this->hr)->postJson('/api/v1/hr/leave/register/'.$hrsOwn->public_id.'/approve')->assertUnprocessable();
        $this->phone($this->boss)->postJson('/api/v1/hr/leave/register/'.$bossOwn->public_id.'/reject', ['remarks' => 'নিজে'])->assertUnprocessable();

        $this->assertSame(LeaveApplication::PENDING, $hrsOwn->fresh()->status, '⛔ HR নিজের ছুটিতে নিজে সই দিলেন।');
        $this->assertSame(LeaveApplication::PENDING, $bossOwn->fresh()->status, '⛔ নিজের ছুটি নিজে ফেরানো হলো।');
    }

    public function test_a_manager_who_is_no_longer_active_no_longer_signs(): void
    {
        $leave = $this->leaveFor($this->worker, '2026-10-05');
        $this->bossRow->forceFill(['is_active' => false])->save();

        $this->web($this->boss)->post(route('hr.leave.approve', $leave->id))->assertForbidden();
        $this->assertSame(LeaveApplication::PENDING, $leave->fresh()->status);
    }

    // ── সাহায্যকারী ─────────────────────────────────────────────────────

    /** @param  list<string>  $keys */
    private function user(array $keys): User
    {
        $user = User::factory()->create(['current_company_id' => $this->company->id]);
        $user->companies()->attach($this->company->id);
        CompanyContext::forCompany($this->company->id, fn () => $user->givePermissionTo($keys));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    private function employee(string $code, string $name, ?int $user = null, ?int $reportsTo = null): Employee
    {
        return Employee::query()->create(['company_id' => $this->company->id, 'branch_id' => $this->dhaka->id, 'code' => $code,
            'name_en' => $name, 'joining_date' => '2026-01-01', 'user_id' => $user, 'reports_to_employee_id' => $reportsTo]);
    }

    private function leaveFor(Employee $employee, string $day): LeaveApplication
    {
        return LeaveApplication::query()->create(['company_id' => $this->company->id, 'employee_id' => $employee->id,
            'leave_type_id' => $this->casual->id, 'from_date' => $day, 'to_date' => $day, 'days' => '1',
            'status' => LeaveApplication::PENDING]);
    }

    private function web(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user->fresh());
    }

    private function phone(User $user): static
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh(), [AuthController::APP]);

        return $this;
    }
}
