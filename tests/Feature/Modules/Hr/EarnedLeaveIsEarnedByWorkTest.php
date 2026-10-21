<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\DataScope;
use App\Core\Services\PhoneModules;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\LeaveApplication;
use App\Modules\Hr\Models\LeaveCarryForward;
use App\Modules\Hr\Models\LeaveType;
use App\Modules\Hr\Services\EarnedLeave;
use App\Modules\Hr\Services\LeaveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ অর্জিত ছুটি কাজ থেকে — প্রতি ১৮ দিন "উপস্থিত"-এ ১ দিন, বছর শেষে বড়জোর ৪০ দিন পরের বছরে (মালিকের অনুমোদন, ২১ অক্টোবর ২০২৬;
 * বাংলাদেশ শ্রম আইন ২০০৬ ধারা ১১৭; [[EarnedLeave]], [[CarryEarnedLeave]]; a6, ২১ অক্টোবর ২০২৬)।
 *
 * ── দাবি ───────────────────────────────────────────────────────────────
 *  · ৩৫ দিন কাজে ১, ৩৬ দিনে ২ দিন অর্জিত; অনুপস্থিত, ছুটি আর ছুটির দিনের সারি কাজ নয়; ধরনের "বছরে ১৫ দিন" খাটে না।
 *  · বছর শেষের জের: জের + অর্জিত − নেওয়া, ৪০-এ থামে; সীমা সেটিং বদলালে নতুন সীমা; আবার চালালে একই একটা সারি; চলে যাওয়া কর্মীর
 *    জের নয়; চলতি বছর বন্ধ করা যায় না।
 *  · একই দিনে কর্মীর পাওনা (সেবা), ছুটির জের রিপোর্ট আর ফোন — তিনটাই একই অর্জিত + জের − নেওয়া; আর বাকির বেশি চাওয়া ফেরে।
 */
final class EarnedLeaveIsEarnedByWorkTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Branch $dhaka;

    private User $worker;

    private Employee $employee;

    private LeaveType $earned;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-30 10:00:00'));

        $this->company = Company::create(['code' => 'HRE', 'name_en' => 'Earned Co']);
        CompanyContext::set($this->company->id);
        $this->dhaka = Branch::query()->create(['company_id' => $this->company->id, 'code' => 'DHK', 'name_en' => 'Dhaka', 'is_active' => true]);
        app(SettingsService::class)->set(PhoneModules::key('hr'), true);

        $this->worker = $this->user(['hr.leave.self']);
        $this->employee = $this->employee('E-1', '2025-01-01', $this->worker);

        // ⓘ "বছরে ১৫ দিন" ইচ্ছে করে — অর্জিত ছুটিতে এটা খাটে না
        $this->earned = LeaveType::query()->create(['company_id' => $this->company->id, 'code' => LeaveType::EARNED,
            'name_en' => 'Earned Leave', 'name_bn' => 'অর্জিত ছুটি', 'days_per_year' => '15', 'is_paid' => true, 'is_active' => true]);
    }

    public function test_thirty_six_worked_days_earn_two_days_and_only_present_days_count(): void
    {
        $this->rows($this->employee, '2026-01-01', 35, Attendance::PRESENT);
        // ⓘ কাজ নয় — অনুপস্থিত, ছুটি, ছুটির দিন
        $this->rows($this->employee, '2026-03-01', 3, Attendance::ABSENT);
        $this->rows($this->employee, '2026-03-04', 3, Attendance::LEAVE);
        $this->rows($this->employee, '2026-03-07', 3, Attendance::HOLIDAY);

        $balance = $this->leave()->balance($this->employee, $this->earned, Carbon::parse('2026-09-30'));
        $this->assertSame('1.0', $balance['accrued'], '⛔ ৩৫ দিনে ১ নয় — বা অনুপস্থিত/ছুটির সারিও কাজ গোনা হল');
        $this->assertSame('1.0', $balance['allowed'], '⛔ ধরনের বাঁধা "বছরে ১৫ দিন" অর্জিত ছুটিতে খাটল');

        $this->rows($this->employee, '2026-04-01', 1, Attendance::PRESENT);
        $this->assertSame('2.0', $this->leave()->balance($this->employee, $this->earned, Carbon::parse('2026-09-30'))['accrued'],
            '⛔ ৩৬ দিন কাজে ২ দিন অর্জিত হয়নি');

        // ⓘ হিসাবের দিনের পরের কাজ সেদিনের পাওনায় নেই
        $this->assertSame('1.0', $this->leave()->balance($this->employee, $this->earned, Carbon::parse('2026-03-31'))['accrued']);
    }

    public function test_the_year_end_carry_is_capped_at_forty_and_running_it_again_writes_the_same_row(): void
    {
        // ⓘ ২০২৫-এ ঢোকা জের ৩০, ২০২৫-এ ৩৬০ দিন কাজ (২০ দিন), নেওয়া ৪ — সমাপনী ৪৬, সীমা ৪০
        LeaveCarryForward::query()->create(['company_id' => $this->company->id, 'employee_id' => $this->employee->id,
            'leave_type_id' => $this->earned->id, 'year' => 2025, 'days' => '30']);
        $this->rows($this->employee, '2025-01-01', 360, Attendance::PRESENT);
        LeaveApplication::query()->create(['company_id' => $this->company->id, 'employee_id' => $this->employee->id,
            'leave_type_id' => $this->earned->id, 'from_date' => '2025-06-02', 'to_date' => '2025-06-05', 'days' => '4',
            'status' => LeaveApplication::APPROVED]);
        // ⓘ সীমার নিচে নেওয়াটা কমায় — ৯০ দিন কাজ (৫), নেওয়া ২ → ৩
        $small = $this->employee('E-2', '2025-01-01', null);
        $this->rows($small, '2025-01-01', 90, Attendance::PRESENT);
        LeaveApplication::query()->create(['company_id' => $this->company->id, 'employee_id' => $small->id,
            'leave_type_id' => $this->earned->id, 'from_date' => '2025-07-01', 'to_date' => '2025-07-02', 'days' => '2',
            'status' => LeaveApplication::APPROVED]);
        // ⓘ বেশি নেওয়া (সরাসরি বসানো) — সমাপনী শূন্যের নিচে নামে না: ১৮ দিন কাজ (১), নেওয়া ৩ → ০
        $over = $this->employee('E-3', '2025-01-01', null);
        $this->rows($over, '2025-01-01', 18, Attendance::PRESENT);
        LeaveApplication::query()->create(['company_id' => $this->company->id, 'employee_id' => $over->id,
            'leave_type_id' => $this->earned->id, 'from_date' => '2025-08-04', 'to_date' => '2025-08-06', 'days' => '3',
            'status' => LeaveApplication::APPROVED]);
        $gone = $this->employee('E-9', '2025-01-01', null, '2025-12-31');
        $this->rows($gone, '2025-01-01', 100, Attendance::PRESENT);

        $this->artisan('abos:hr-carry-earned-leave', ['--year' => 2025])->assertSuccessful();
        $this->artisan('abos:hr-carry-earned-leave', ['--year' => 2025])->assertSuccessful();

        $rows = LeaveCarryForward::query()->where('year', 2026)->get()->keyBy('employee_id');
        $this->assertCount(3, $rows, '⛔ আবার চালানোয় দ্বিতীয় সারি বসল, বা চলে যাওয়া কর্মীর জের বসল');
        $this->assertSame('0.0', (string) $rows[$over->id]->days, '⛔ জের শূন্যের নিচে নামল');
        $this->assertSame('40.0', (string) $rows[$this->employee->id]->days, '⛔ জের ৪০-এ থামেনি');
        $this->assertSame('3.0', (string) $rows[$small->id]->days, '⛔ সমাপনীতে নেওয়া ছুটি বাদ যায়নি');
        $this->assertSame('40.0', $this->leave()->balance($this->employee, $this->earned, Carbon::parse('2026-01-01'))['carried']);

        app(SettingsService::class)->set('hr.earned_leave_carry_cap', 10);
        app()->forgetInstance(SettingsService::class);
        $this->artisan('abos:hr-carry-earned-leave', ['--year' => 2025])->assertSuccessful();
        $this->assertSame('10.0', (string) LeaveCarryForward::query()->where('year', 2026)->where('employee_id', $this->employee->id)->sole()->days,
            '⛔ সীমার সেটিং খাটেনি');

        // ⛔ চলতি বছর বন্ধ হয় না
        $this->artisan('abos:hr-carry-earned-leave', ['--year' => 2026])->assertFailed();
        $this->assertSame(0, LeaveCarryForward::query()->where('year', 2027)->count());
    }

    public function test_the_balance_the_report_and_the_phone_say_the_same(): void
    {
        // ⓘ প্রমিত ধরনে "বছরে দিন" শূন্যও হতে পারে — অর্জিত ছুটি তবু সীমাহীন নয়, আর রিপোর্টে থাকে
        $this->earned->forceFill(['days_per_year' => 0])->save();
        $this->rows($this->employee, '2026-01-01', 40, Attendance::PRESENT);
        // ⓘ কাজ নয় — রিপোর্টও এগুলো গোনে না
        $this->rows($this->employee, '2026-05-01', 20, Attendance::ABSENT);
        LeaveCarryForward::query()->create(['company_id' => $this->company->id, 'employee_id' => $this->employee->id,
            'leave_type_id' => $this->earned->id, 'year' => 2026, 'days' => '5']);
        LeaveApplication::query()->create(['company_id' => $this->company->id, 'employee_id' => $this->employee->id,
            'leave_type_id' => $this->earned->id, 'from_date' => '2026-08-03', 'to_date' => '2026-08-04', 'days' => '2',
            'status' => LeaveApplication::APPROVED]);

        // ⓘ অর্জিত ⌊৪০ ÷ ১৮⌋ = ২, জের ৫, নেওয়া ২ — বাকি ৫
        $balance = $this->leave()->balance($this->employee, $this->earned, Carbon::parse('2026-09-30'));
        $this->assertSame(['7.0', '2.0', '5.0', '2.0', '5.0'],
            [$balance['allowed'], $balance['taken'], $balance['left'], $balance['accrued'], $balance['carried']]);

        $this->as($this->user(['hr.leave.view']));
        $row = collect(app(ReportEngine::class)->run('hr.leave_balance', ['from' => '2026-09-01', 'to' => '2026-09-30'])->rows)
            ->first(fn (array $r) => str_starts_with((string) $r['employee'], 'E-1 '));
        $this->assertNotNull($row, '⛔ অর্জিত ছুটি জেরের রিপোর্টে নেই');
        $this->assertSame(0, bccomp($balance['allowed'], (string) $row['allowed'], 1), '⛔ রিপোর্টের পাওনা সেবার সাথে মেলে না');
        $this->assertSame(0, bccomp($balance['taken'], (string) $row['taken'], 1));
        $this->assertSame(0, bccomp((string) $balance['left'], (string) $row['remaining'], 1), '⛔ রিপোর্টের বাকি সেবার সাথে মেলে না');

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->worker->fresh(), [AuthController::APP]);
        $type = collect($this->getJson('/api/v1/hr/leave')->assertOk()->json('types'))->firstWhere('id', $this->earned->public_id);
        $this->assertSame(['7.0', '5.0', '2.0', '5.0'], [(string) $type['allowed'], (string) $type['left'], (string) $type['accrued'], (string) $type['carried']],
            '⛔ ফোনের পাওনা সেবার সাথে মেলে না');

        // ⓘ বাকির বেশি চাওয়া ফেরে — সীমা অর্জিত + জের
        $this->assertThrows(fn () => $this->leave()->apply($this->employee, $this->earned, '2026-10-05', '2026-10-12', '6'), ValidationException::class);
    }

    private function leave(): LeaveService
    {
        return app(LeaveService::class);
    }

    /** একটানা `$count`টা দিনের সারি — সোজা টেবিলে, অডিট ছাড়া (দ্রুত) */
    private function rows(Employee $employee, string $from, int $count, string $status): void
    {
        $day = Carbon::parse($from);
        $batch = [];

        for ($i = 0; $i < $count; $i++, $day->addDay()) {
            $batch[] = ['public_id' => (string) Str::uuid(), 'company_id' => $this->company->id, 'employee_id' => $employee->id,
                'work_date' => $day->toDateString(), 'status' => $status, 'is_late' => false,
                'created_at' => now(), 'updated_at' => now()];
        }

        foreach (array_chunk($batch, 200) as $chunk) {
            DB::table('hr_attendance')->insert($chunk);
        }
    }

    /** @param  list<string>  $keys */
    private function user(array $keys): User
    {
        $user = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);
        CompanyContext::forCompany($this->company->id, function () use ($user, $keys): void {
            foreach ($keys as $key) {
                $user->givePermissionTo(Permission::findOrCreate($key, 'web'));
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    private function as(User $user): void
    {
        $this->app['auth']->forgetGuards();
        app(DataScope::class)->forget();
        $this->actingAs($user->fresh());
    }

    private function employee(string $code, string $joined, ?User $user, ?string $left = null): Employee
    {
        return Employee::query()->create(['company_id' => $this->company->id, 'branch_id' => $this->dhaka->id, 'code' => $code,
            'name_en' => 'Person '.$code, 'joining_date' => $joined, 'leaving_date' => $left, 'user_id' => $user?->id,
            'is_active' => true]);
    }
}
