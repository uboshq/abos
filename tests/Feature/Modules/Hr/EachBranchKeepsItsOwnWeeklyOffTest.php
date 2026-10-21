<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\AuditTrail;
use App\Models\Branch;
use App\Models\BranchSetting;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Employee;
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
 * ⭐ শাখার নিজের সাপ্তাহিক ছুটি — কোম্পানির নিয়মের উপরে, শাখারটা জেতে (fe-র উত্তর ৬, ২১ অক্টোবর ২০২৬; শ্রম আইন ধারা ১০৩, ১১৪;
 * [[WorkCalendar::weeklyOff()]], [[HolidayController::storeWeeklyOff()]]; a6)।
 *
 * ── দাবি ───────────────────────────────────────────────────────────────
 *  · কোম্পানি শুক্রবার, নেত্রকোনা (দোকান) শনিবার: শুক্রবার ৬ নভেম্বরের এক দিনের ছুটি ঢাকা আর শাখাহীন কর্মীর ফেরে (কাজের দিন ০),
 *    নেত্রকোনার চলে; শনিবার নেত্রকোনার কামাই কাটে না, শুক্রবারের কাটে।
 *  · পর্দা থেকে বসানো আর ফেরানো (খালি = কোম্পানির মতো), অডিটে; ঢাকায় সীমিত মানুষ নেত্রকোনারটা বদলাতে পারেন না।
 */
final class EachBranchKeepsItsOwnWeeklyOffTest extends TestCase
{
    use RefreshDatabase;

    private const KEYS = ['hr.leave.view', 'hr.leave.manage', 'hr.leave.approve', 'hr.attendance.view', 'hr.attendance.manage'];

    private Company $company;

    private Branch $dhaka;

    private Branch $netrokona;

    private LeaveType $casual;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['code' => 'HRWO', 'name_en' => 'Weekly Co']);
        CompanyContext::set($this->company->id);
        $this->dhaka = Branch::query()->create(['company_id' => $this->company->id, 'code' => 'DHK', 'name_en' => 'Dhaka', 'is_active' => true]);
        $this->netrokona = Branch::query()->create(['company_id' => $this->company->id, 'code' => 'NTK', 'name_en' => 'Netrokona', 'is_active' => true]);

        foreach (self::KEYS as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $this->casual = LeaveType::query()->create(['company_id' => $this->company->id, 'code' => 'CL', 'name_en' => 'Casual',
            'days_per_year' => '10', 'is_paid' => true, 'is_active' => true]);
    }

    public function test_the_branchs_own_weekly_off_wins_over_the_companys(): void
    {
        $hr = $this->person(null);
        $this->actingAs($hr)->post(route('hr.holiday.weekly_off.store'), ['branch_id' => $this->netrokona->id, 'weekly_off' => 'saturday'])
            ->assertSessionHasNoErrors();
        $this->assertSame('saturday', BranchSetting::query()->where('branch_id', $this->netrokona->id)->where('key', 'hr.weekly_off')->value('value'));
        $this->assertTrue(AuditTrail::query()->withoutGlobalScopes()->where('auditable_type', (new BranchSetting)->getMorphClass())
            ->where('action', AuditTrail::CREATED)->exists(), '⛔ শাখার সাপ্তাহিক ছুটি অডিটে নেই');

        $dhakaPerson = $this->employee('E-D', $this->dhaka);
        $ntkPerson = $this->employee('E-N', $this->netrokona);
        $headOffice = $this->employee('E-H', null);

        foreach ([$dhakaPerson, $headOffice] as $employee) {
            $this->assertThrows(fn () => $this->leave()->apply($employee, $this->casual, '2026-11-06', '2026-11-06', '1'), ValidationException::class);
        }
        $this->assertSame(LeaveApplication::PENDING, $this->leave()->apply($ntkPerson, $this->casual, '2026-11-06', '2026-11-06', '1')->status,
            '⛔ নেত্রকোনার শুক্রবার কাজের দিন, অথচ ছুটি ফিরল');

        // ⓘ শনি ৭ নভেম্বর নেত্রকোনার ছুটির দিন — কামাই কাটে না; শুক্র ১৩ কাজের দিন — কাটে
        app(AttendanceService::class)->mark($ntkPerson, '2026-11-07', Attendance::ABSENT);
        app(AttendanceService::class)->mark($ntkPerson, '2026-11-13', Attendance::ABSENT);
        $this->assertSame('1.0', app(AttendanceService::class)->unpaidDays($ntkPerson, Carbon::parse('2026-11-01')));

        // ⓘ খালি পাঠালে কোম্পানির মতো — শুক্রবার আবার ছুটির দিন
        $this->actingAs($hr)->post(route('hr.holiday.weekly_off.store'), ['branch_id' => $this->netrokona->id, 'weekly_off' => ''])
            ->assertSessionHasNoErrors();
        $this->assertNull(BranchSetting::query()->where('branch_id', $this->netrokona->id)->where('key', 'hr.weekly_off')->first());
        $this->assertThrows(fn () => $this->leave()->apply($ntkPerson, $this->casual, '2026-11-20', '2026-11-20', '1'), ValidationException::class);
    }

    public function test_a_branch_limited_person_sets_only_their_own_branch(): void
    {
        $limited = $this->person($this->dhaka);

        $this->actingAs($limited)->post(route('hr.holiday.weekly_off.store'), ['branch_id' => $this->netrokona->id, 'weekly_off' => 'sunday'])
            ->assertSessionHasErrors('branch_id');
        $this->assertSame(0, BranchSetting::query()->where('key', 'hr.weekly_off')->count());

        $this->actingAs($limited)->post(route('hr.holiday.weekly_off.store'), ['branch_id' => $this->dhaka->id, 'weekly_off' => 'sunday'])
            ->assertSessionHasNoErrors();
        $this->assertSame('sunday', BranchSetting::query()->where('branch_id', $this->dhaka->id)->value('value'));

        $viewer = $this->person(null, ['hr.attendance.view']);
        $this->actingAs($viewer)->post(route('hr.holiday.weekly_off.store'), ['branch_id' => $this->dhaka->id, 'weekly_off' => 'none'])
            ->assertForbidden();
    }

    private function leave(): LeaveService
    {
        return app(LeaveService::class);
    }

    /** @param  list<string>|null  $keys */
    private function person(?Branch $limitedTo, ?array $keys = null): User
    {
        $user = User::factory()->create(['current_company_id' => $this->company->id]);
        $user->companies()->attach($this->company->id);
        $user->givePermissionTo($keys ?? self::KEYS);

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
