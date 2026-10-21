<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Services\DataScope;
use App\Core\Services\PermissionSyncer;
use App\Core\Services\PhoneModules;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Hr\Dashboard\HrDashboard;
use App\Modules\Hr\Dashboard\HrWidgets;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\LeaveApplication;
use App\Modules\Hr\Models\LeaveType;
use App\Modules\Hr\Support\LeaveSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ ছুটির সইয়ে দায়িত্বের ভাগ — লাইন দেয়াল পেরোয় না, যিনি বসান তিনি সই দেন না, আর "আমার সইয়ের অপেক্ষায়" কেবল নিজের
 * (fe-র উত্তর, ২১ অক্টোবর ২০২৬; a6; [[LeaveSigner]])।
 *
 * দাবি:
 * ১. কর্মী ব্যবস্থাপকের শাখার নাগালের বাইরে — ছুটি HR-এর: ব্যবস্থাপকের ওয়েবের তালিকা আর ফোনের সারিতে বোতামের বদলে
 *    "এই ছুটি HR-এর কাছে গেছে, কারণ আপনার নাগালের বাইরে"; দরজা ৪০৩; HR সই দেন।
 * ২. যিনি অন্যের নামে ছুটি বসালেন (মালিক নন) তিনি সই দেন না, ফেরানও না — ওয়েব আর ফোন; আরেকজন HR দেন; মালিক নিজে বসিয়ে
 *    নিজে সই দিতে পারেন, আর অডিটে `owner_self_signed` চিহ্ন থাকে।
 * ৩. "আমার সইয়ের অপেক্ষায়" (ড্যাশবোর্ড, হোম, ফোন) কেবল এই মানুষ যেগুলোতে সই দিতে পারেন; HR-জুড়ে হলে "সব অপেক্ষার ছুটি" আলাদা।
 */
final class LeaveSignaturesKeepTheirDutiesApartTest extends TestCase
{
    use RefreshDatabase;

    private const SIGNS = ['hr.leave.view', 'hr.leave.approve'];

    private const HR = ['hr.leave.view', 'hr.leave.approve', 'hr.leave.manage'];

    private Company $company;

    private Branch $dhaka;

    private Branch $netrokona;

    private LeaveType $casual;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['code' => 'HSD', 'name_en' => 'Duties Co']);
        CompanyContext::set($this->company->id);
        app(SettingsService::class)->set(PhoneModules::key('hr'), true);

        $this->dhaka = Branch::query()->create(['company_id' => $this->company->id, 'code' => 'DHK', 'name_en' => 'Dhaka', 'is_active' => true]);
        $this->netrokona = Branch::query()->create(['company_id' => $this->company->id, 'code' => 'NTK', 'name_en' => 'Netrokona', 'is_active' => true]);

        foreach (self::HR as $key) {
            Permission::findOrCreate($key, 'web');
        }

        $this->casual = LeaveType::query()->create(['company_id' => $this->company->id, 'code' => 'CL', 'name_en' => 'Casual',
            'days_per_year' => '10', 'is_paid' => true, 'is_active' => true]);
    }

    public function test_a_line_that_crosses_the_branch_wall_falls_to_hr_and_says_so(): void
    {
        $manager = $this->user(self::SIGNS, $this->dhaka);
        $managerRow = $this->employee('E-M', 'Dhaka Manager', $this->dhaka, $manager->id);
        $far = $this->employee('E-F', 'Far Worker', $this->netrokona, null, $managerRow->id);
        $near = $this->employee('E-N', 'Near Worker', $this->dhaka, null, $managerRow->id);
        $farLeave = $this->leaveFor($far, '2026-10-05');
        $nearLeave = $this->leaveFor($near, '2026-10-06');
        $note = __('hr::message.leave_falls_to_hr');

        $this->assertSame('এই ছুটি HR-এর কাছে গেছে, কারণ আপনার নাগালের বাইরে', __('hr::message.leave_falls_to_hr', [], 'bn'));

        // ⭐ ওয়েব: দূরের কর্মীর সারি আছে, বোতাম নেই, কথাটা আছে; কাছেরটার বোতাম আছে
        $this->web($manager)->get(route('hr.leave.index'))->assertOk()
            ->assertSee('Far Worker')
            ->assertSee($note)
            ->assertDontSee(route('hr.leave.approve', $farLeave->id))
            ->assertSee(route('hr.leave.approve', $nearLeave->id));

        $this->web($manager)->post(route('hr.leave.approve', $farLeave->id))->assertForbidden();

        // ⭐ ফোন: একই কথা, বোতাম নেই; দরজাও ৪০৩
        $rows = collect($this->phone($manager)->getJson('/api/v1/hr/leave/register?pending=1')->assertOk()->json('rows'))->keyBy('id');
        $this->assertFalse($rows[(string) $farLeave->public_id]['can_decide']);
        $this->assertSame($note, $rows[(string) $farLeave->public_id]['note']);
        $this->assertTrue($rows[(string) $nearLeave->public_id]['can_decide']);
        $this->assertNull($rows[(string) $nearLeave->public_id]['note']);
        $this->phone($manager)->postJson('/api/v1/hr/leave/register/'.$farLeave->public_id.'/approve')->assertForbidden();
        $this->assertSame(LeaveApplication::PENDING, $farLeave->fresh()->status);

        // ⭐ HR সই দেন
        $this->web($this->user(self::HR))->post(route('hr.leave.approve', $farLeave->id))->assertSessionHasNoErrors();
        $this->assertSame(LeaveApplication::APPROVED, $farLeave->fresh()->status);
    }

    public function test_whoever_entered_a_leave_for_someone_else_does_not_sign_it_unless_the_owner(): void
    {
        $clerk = $this->user(self::HR);
        $other = $this->user(self::HR);
        $worker = $this->employee('E-W', 'Worker Person', $this->dhaka);

        $this->web($clerk)->post(route('hr.leave.store'), ['employee_id' => $worker->id, 'leave_type_id' => $this->casual->id,
            'from_date' => '2026-10-05', 'to_date' => '2026-10-05', 'days' => '1'])->assertSessionHasNoErrors();
        $entered = LeaveApplication::query()->where('employee_id', $worker->id)->latest('id')->firstOrFail();
        $this->assertSame((int) $clerk->id, (int) $entered->created_by);

        // ⛔ বসানো মানুষ — বোতাম নেই, ওয়েব আর ফোনে ফেরত, ফেরানোও নয়
        $this->web($clerk)->get(route('hr.leave.index'))->assertOk()->assertDontSee(route('hr.leave.approve', $entered->id));
        $this->web($clerk)->post(route('hr.leave.approve', $entered->id))->assertSessionHasErrors('status');
        $this->web($clerk)->post(route('hr.leave.reject', $entered->id), ['remarks' => 'না'])->assertSessionHasErrors('status');
        $this->phone($clerk)->postJson('/api/v1/hr/leave/register/'.$entered->public_id.'/approve')->assertUnprocessable();
        $this->phone($clerk)->postJson('/api/v1/hr/leave/register/'.$entered->public_id.'/reject', ['remarks' => 'না'])->assertUnprocessable();
        $this->assertSame(LeaveApplication::PENDING, $entered->fresh()->status, '⛔ যিনি বসালেন তিনিই সই দিলেন।');

        // ⭐ আরেকজন HR — পারেন, আর চিহ্ন নেই
        $this->phone($other)->postJson('/api/v1/hr/leave/register/'.$entered->public_id.'/approve')->assertOk();
        $this->assertSame(LeaveApplication::APPROVED, $entered->fresh()->status);
        $this->assertFalse($entered->fresh()->auditTrail()->where('action', LeaveSigner::OWNER_SELF_SIGNED)->exists());

        // ⭐ মালিক নিজে বসিয়ে নিজে সই দেন — আর অডিটে চিহ্ন
        $owner = $this->user(self::HR);
        CompanyContext::forCompany($this->company->id, fn () => $owner->assignRole(Role::findOrCreate(PermissionSyncer::SUPER_ADMIN_ROLE, 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->web($owner)->post(route('hr.leave.store'), ['employee_id' => $worker->id, 'leave_type_id' => $this->casual->id,
            'from_date' => '2026-10-12', 'to_date' => '2026-10-12', 'days' => '1'])->assertSessionHasNoErrors();
        $ownersEntry = LeaveApplication::query()->where('employee_id', $worker->id)->latest('id')->firstOrFail();

        $this->web($owner)->post(route('hr.leave.approve', $ownersEntry->id))->assertSessionHasNoErrors();
        $this->assertSame(LeaveApplication::APPROVED, $ownersEntry->fresh()->status);

        $mark = $ownersEntry->fresh()->auditTrail()->where('action', LeaveSigner::OWNER_SELF_SIGNED)->first();
        $this->assertNotNull($mark, '⛔ মালিকের নিজে-বসানো নিজে-সই অডিটে চিহ্নিত নয়।');
        $this->assertSame((int) $owner->id, (int) $mark->user_id);
        $this->assertSame(__('hr::validation.leave_owner_self_signed'), $mark->reason);
    }

    public function test_awaiting_my_signature_counts_only_what_i_may_sign_and_hr_also_sees_the_whole(): void
    {
        $manager = $this->user(self::SIGNS);
        $managerRow = $this->employee('E-M', 'Line Manager', $this->dhaka, $manager->id);
        $hr = $this->user(self::HR);

        $this->leaveFor($this->employee('E-1', 'Team One', $this->dhaka, null, $managerRow->id), '2026-10-05');
        $this->leaveFor($this->employee('E-2', 'Team Two', $this->dhaka, null, $managerRow->id), '2026-10-06');
        $this->leaveFor($this->employee('E-3', 'Not Mine', $this->dhaka), '2026-10-07');
        // ⓘ নিজের ছুটি — নিজের গোনায় নয়
        $this->leaveFor($managerRow, '2026-10-08');

        // ⭐ ব্যবস্থাপক: নিজের দলের ২টা; "সব"-এর ঘর নেই
        $this->web($manager);
        $this->assertSame('2', $this->stat(__('hr::dashboard.my_pending_leave'))?->value);
        $this->assertNull($this->stat(__('hr::dashboard.pending_leave')), '⛔ HR-জুড়ে নন এমন কেউ সব অপেক্ষার ছুটির গোনা পেলেন।');
        $this->assertSame('2', $this->widget(__('hr::dashboard.my_pending_leave'))?->value);
        $this->assertNull($this->widget(__('hr::dashboard.all_pending_leave')));

        $phone = $this->phone($manager)->getJson('/api/v1/hr/leave/register?pending=1')->assertOk();
        $this->assertSame(2, $phone->json('mine_pending'));
        $this->assertNull($phone->json('all_pending'));

        // ⭐ HR: নিজের সইয়ের ৪টা, আর সব অপেক্ষার ৪টা আলাদা লাইনে
        $this->web($hr);
        $this->assertSame('4', $this->stat(__('hr::dashboard.my_pending_leave'))?->value);
        $this->assertSame('4', $this->stat(__('hr::dashboard.pending_leave'))?->value);
        $this->assertSame('4', $this->widget(__('hr::dashboard.all_pending_leave'))?->value);

        $phone = $this->phone($hr)->getJson('/api/v1/hr/leave/register?pending=1')->assertOk();
        $this->assertSame(4, $phone->json('mine_pending'));
        $this->assertSame(4, $phone->json('all_pending'));
    }

    // ── সাহায্যকারী ─────────────────────────────────────────────────────

    private function stat(string $label): mixed
    {
        return collect(HrDashboard::dashboard()->stats)->firstWhere('label', $label);
    }

    private function widget(string $label): mixed
    {
        return collect(HrWidgets::widgets())->firstWhere('label', $label);
    }

    /** @param  list<string>  $keys */
    private function user(array $keys, ?Branch $only = null): User
    {
        $user = User::factory()->create(['current_company_id' => $this->company->id, 'is_active' => true]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);

        if ($keys !== []) {
            CompanyContext::forCompany($this->company->id, fn () => $user->givePermissionTo($keys));
        }

        if ($only !== null) {
            UserDataScope::query()->create(['company_id' => $this->company->id, 'user_id' => $user->id,
                'scope_type' => UserDataScope::BRANCH, 'scope_id' => $only->id]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        app(DataScope::class)->forget();

        return $user;
    }

    private function employee(string $code, string $name, Branch $branch, ?int $user = null, ?int $reportsTo = null): Employee
    {
        return Employee::query()->create(['company_id' => $this->company->id, 'branch_id' => $branch->id, 'code' => $code,
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
        app(DataScope::class)->forget();

        return $this->actingAs($user->fresh());
    }

    private function phone(User $user): static
    {
        $this->app['auth']->forgetGuards();
        app(DataScope::class)->forget();
        Sanctum::actingAs($user->fresh(), [AuthController::APP]);

        return $this;
    }
}
