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
use App\Modules\Hr\Models\SalaryHead;
use App\Modules\Hr\Services\EarnedLeave;
use App\Modules\Hr\Services\LeaveService;
use App\Modules\Hr\Services\SalaryStructureService;
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
 * ⭐ অর্জিত ছুটি — ধারা ১১৭ হুবহু: এক বছর চাকরির পরে, আগের বছরের প্রতি ১৮ দিন "উপস্থিত"-এ ১ দিন; বছর শেষে বড়জোর ৪০ দিন পরের বছরে;
 * চাকরি ছাড়লে না-নেওয়া দিনের টাকা (মালিকের অনুমোদন ও fe-র উত্তর, ২১ অক্টোবর ২০২৬; বাংলাদেশ শ্রম আইন ২০০৬ ধারা ১১, ১১৭;
 * [[EarnedLeave]], [[CarryEarnedLeave]]; a6, ২১ অক্টোবর ২০২৬)।
 *
 * ── দাবি ───────────────────────────────────────────────────────────────
 *  · আগের বছরে ৩৫ দিন কাজে ১, ৩৬ দিনে ২; চলতি বছরের কাজ চলতি পাওনায় নেই; অনুপস্থিত, ছুটি আর ছুটির দিনের সারি কাজ নয়; ধরনের
 *    "বছরে ১৫ দিন" খাটে না; যোগদানের এক বছর পূর্ণ হওয়ার আগের দিন শূন্য, সেদিন থেকে পুরোটা।
 *  · বছর শেষের জের: জের + পাওনা − নেওয়া, ৪০-এ থামে, শূন্যের নিচে নয়; সীমা সেটিং বদলালে নতুন সীমা; আবার চালালে একই একটা সারি;
 *    চলে যাওয়া কর্মীর জের নয়; চলতি বছর বন্ধ করা যায় না।
 *  · একই দিনে কর্মীর পাওনা (সেবা), ছুটির জের রিপোর্ট আর ফোন — তিনটাই একই; আর বাকির বেশি চাওয়া ফেরে।
 *  · চাকরি ছাড়ার দিনে: না-নেওয়া দিন = বাকি + চলতি বছরের কাজে অর্জিত, টাকা = দিন × মাসিক মজুরি ÷ ৩০; এক বছরের আগে ছাড়লে শূন্য।
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
        // ⓘ ২০২৪-এ যোগদান — ২০২৫ থেকে যোগ্য
        $this->employee = $this->employee('E-1', '2024-01-01', $this->worker);

        // ⓘ "বছরে ১৫ দিন" ইচ্ছে করে — অর্জিত ছুটিতে এটা খাটে না
        $this->earned = LeaveType::query()->create(['company_id' => $this->company->id, 'code' => LeaveType::EARNED,
            'name_en' => 'Earned Leave', 'name_bn' => 'অর্জিত ছুটি', 'days_per_year' => '15', 'is_paid' => true, 'is_active' => true]);
    }

    public function test_thirty_six_days_worked_last_year_earn_two_days_and_only_present_days_count(): void
    {
        $this->rows($this->employee, '2025-01-01', 35, Attendance::PRESENT);
        // ⓘ কাজ নয় — অনুপস্থিত, ছুটি, ছুটির দিন; আর চলতি বছরের কাজ চলতি পাওনায় নয়
        $this->rows($this->employee, '2025-03-01', 3, Attendance::ABSENT);
        $this->rows($this->employee, '2025-03-04', 3, Attendance::LEAVE);
        $this->rows($this->employee, '2025-03-07', 3, Attendance::HOLIDAY);
        $this->rows($this->employee, '2026-01-01', 50, Attendance::PRESENT);

        $balance = $this->leave()->balance($this->employee, $this->earned, Carbon::parse('2026-09-30'));
        $this->assertSame('1.0', $balance['accrued'], '⛔ আগের বছরের ৩৫ দিনে ১ নয় — বা অনুপস্থিত/ছুটির সারি, বা চলতি বছরের কাজও গোনা হল');
        $this->assertSame('1.0', $balance['allowed'], '⛔ ধরনের বাঁধা "বছরে ১৫ দিন" অর্জিত ছুটিতে খাটল');

        $this->rows($this->employee, '2025-04-01', 1, Attendance::PRESENT);
        $this->assertSame('2.0', $this->leave()->balance($this->employee, $this->earned, Carbon::parse('2026-09-30'))['accrued'],
            '⛔ ৩৬ দিন কাজে ২ দিন অর্জিত হয়নি');
    }

    public function test_nothing_is_earned_before_one_year_of_service(): void
    {
        // ⓘ ১ জুন ২০২৫ যোগদান — এক বছর পূর্ণ ১ জুন ২০২৬; ২০২৫-এ ১০০ দিন কাজ (৫ দিন)
        $new = $this->employee('E-J', '2025-06-01', null);
        $this->rows($new, '2025-06-02', 100, Attendance::PRESENT);

        $this->assertSame('0.0', $this->leave()->balance($new, $this->earned, Carbon::parse('2026-05-31'))['allowed'],
            '⛔ এক বছর পূর্ণ হওয়ার আগেই অর্জিত ছুটি');
        $this->assertSame('5.0', $this->leave()->balance($new, $this->earned, Carbon::parse('2026-06-01'))['allowed'],
            '⛔ এক বছর পূর্ণ হওয়ার দিনেও আগের বছরের কাজের ছুটি নেই');
    }

    public function test_the_year_end_carry_is_capped_at_forty_and_running_it_again_writes_the_same_row(): void
    {
        // ⓘ ২০২৫-এ ঢোকা জের ৩০, ২০২৪-এ ৩৬০ দিন কাজ (২০২৫-এর পাওনা ২০), ২০২৫-এ নেওয়া ৪ — সমাপনী ৪৬, সীমা ৪০
        LeaveCarryForward::query()->create(['company_id' => $this->company->id, 'employee_id' => $this->employee->id,
            'leave_type_id' => $this->earned->id, 'year' => 2025, 'days' => '30']);
        $this->rows($this->employee, '2024-01-01', 360, Attendance::PRESENT);
        $this->approved($this->employee, '2025-06-02', '2025-06-05', '4');
        // ⓘ সীমার নিচে নেওয়াটা কমায় — ৯০ দিন কাজ (৫), নেওয়া ২ → ৩
        $small = $this->employee('E-2', '2024-01-01', null);
        $this->rows($small, '2024-01-01', 90, Attendance::PRESENT);
        $this->approved($small, '2025-07-01', '2025-07-02', '2');
        // ⓘ বেশি নেওয়া (সরাসরি বসানো) — সমাপনী শূন্যের নিচে নামে না: ১৮ দিন কাজ (১), নেওয়া ৩ → ০
        $over = $this->employee('E-3', '2024-01-01', null);
        $this->rows($over, '2024-01-01', 18, Attendance::PRESENT);
        $this->approved($over, '2025-08-04', '2025-08-06', '3');
        $gone = $this->employee('E-9', '2024-01-01', null, '2025-12-31');
        $this->rows($gone, '2024-01-01', 100, Attendance::PRESENT);

        $this->artisan('abos:hr-carry-earned-leave', ['--year' => 2025])->assertSuccessful();
        $this->artisan('abos:hr-carry-earned-leave', ['--year' => 2025])->assertSuccessful();

        $rows = LeaveCarryForward::query()->where('year', 2026)->get()->keyBy('employee_id');
        $this->assertCount(3, $rows, '⛔ আবার চালানোয় দ্বিতীয় সারি বসল, বা চলে যাওয়া কর্মীর জের বসল');
        $this->assertSame('0.0', (string) $rows[$over->id]->days, '⛔ জের শূন্যের নিচে নামল');
        $this->assertSame('40.0', (string) $rows[$this->employee->id]->days, '⛔ জের ৪০-এ থামেনি');
        $this->assertSame('3.0', (string) $rows[$small->id]->days, '⛔ সমাপনীতে নেওয়া ছুটি বাদ যায়নি');
        $this->assertSame('40.0', $this->leave()->balance($this->employee, $this->earned, Carbon::parse('2026-01-01'))['carried']);

        app(SettingsService::class)->set('hr.earned_leave_carry_cap', 10);
        // ⓘ SettingsService cache — a6/settings-cache-fix
        app(SettingsService::class)->flush();
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
        $this->rows($this->employee, '2025-01-01', 40, Attendance::PRESENT);
        // ⓘ কাজ নয় — রিপোর্টও এগুলো গোনে না; চলতি বছরের কাজও এ বছরের পাওনায় নয়
        $this->rows($this->employee, '2025-05-01', 20, Attendance::ABSENT);
        $this->rows($this->employee, '2026-01-01', 30, Attendance::PRESENT);
        LeaveCarryForward::query()->create(['company_id' => $this->company->id, 'employee_id' => $this->employee->id,
            'leave_type_id' => $this->earned->id, 'year' => 2026, 'days' => '5']);
        $this->approved($this->employee, '2026-08-03', '2026-08-04', '2');

        // ⓘ পাওনা ⌊৪০ ÷ ১৮⌋ = ২, জের ৫, নেওয়া ২ — বাকি ৫
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

        // ⓘ এক বছরের আগে — রিপোর্টেও শূন্য (জের ছাড়া)
        $new = $this->employee('E-7', '2025-12-01', null);
        $this->rows($new, '2025-12-01', 30, Attendance::PRESENT);
        $young = collect(app(ReportEngine::class)->run('hr.leave_balance', ['from' => '2026-09-01', 'to' => '2026-09-30'])->rows)
            ->first(fn (array $r) => str_starts_with((string) $r['employee'], 'E-7 '));
        $this->assertSame(0, bccomp('0', (string) $young['allowed'], 1), '⛔ রিপোর্টে এক বছরের আগেই অর্জিত ছুটি');

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->worker->fresh(), [AuthController::APP]);
        $type = collect($this->getJson('/api/v1/hr/leave')->assertOk()->json('types'))->firstWhere('id', $this->earned->public_id);
        $this->assertSame(['7.0', '5.0', '2.0', '5.0'], [(string) $type['allowed'], (string) $type['left'], (string) $type['accrued'], (string) $type['carried']],
            '⛔ ফোনের পাওনা সেবার সাথে মেলে না');

        // ⓘ বাকির বেশি চাওয়া ফেরে — সীমা পাওনা + জের
        $this->assertThrows(fn () => $this->leave()->apply($this->employee, $this->earned, '2026-10-05', '2026-10-12', '6'), ValidationException::class);
    }

    public function test_a_leaver_is_paid_the_unused_earned_leave_at_the_monthly_wage_over_thirty(): void
    {
        $head = SalaryHead::query()->create(['company_id' => $this->company->id, 'code' => 'BASIC', 'name_en' => 'Basic',
            'kind' => SalaryHead::EARNING, 'calculation' => SalaryHead::FIXED, 'is_basic' => true, 'prorated_by_attendance' => true, 'is_active' => true]);

        // ⓘ ২০২৫-এ ৫৪ দিন কাজ (২০২৬-এর পাওনা ৩) + জের ৪ − নেওয়া ২ = বাকি ৫; ২০২৬-এ ছাড়ার দিন পর্যন্ত ৪০ দিন কাজ (২) — মোট ৭
        $leaver = $this->employee('E-L', '2024-01-01', null, '2026-09-30');
        app(SalaryStructureService::class)->set($leaver, $head, '2024-01-01', '30000');
        // ⓘ কর্তন মজুরি কমায় না — দৈনিক হার মোট আয় ধরে, নিট নয়
        $pf = SalaryHead::query()->create(['company_id' => $this->company->id, 'code' => 'PF', 'name_en' => 'PF',
            'kind' => SalaryHead::DEDUCTION, 'calculation' => SalaryHead::FIXED, 'is_basic' => false, 'prorated_by_attendance' => false, 'is_active' => true]);
        app(SalaryStructureService::class)->set($leaver, $pf, '2024-01-01', '2000');
        $this->rows($leaver, '2025-01-01', 54, Attendance::PRESENT);
        $this->rows($leaver, '2026-01-01', 40, Attendance::PRESENT);
        LeaveCarryForward::query()->create(['company_id' => $this->company->id, 'employee_id' => $leaver->id,
            'leave_type_id' => $this->earned->id, 'year' => 2026, 'days' => '4']);
        $this->approved($leaver, '2026-03-02', '2026-03-03', '2');

        $this->assertSame('7.0', $this->leave()->encashableEarnedDays($leaver, Carbon::parse('2026-09-30')));
        $this->assertSame(['days' => '7.0', 'monthly_wage' => '30000.00', 'daily_rate' => '1000.00', 'amount' => '7000.00'],
            $this->leave()->earnedLeaveEncashment($leaver, Carbon::parse('2026-09-30')), '⛔ না-নেওয়া ছুটির টাকা মাসিক মজুরি ÷ ৩০-এ নয়');

        // ⓘ বেশি নেওয়া (সরাসরি বসানো) বাকিকে শূন্যের নিচে নামায় না: পাওনা ১ − নেওয়া ৩ → ০, আর এ বছরের ১৮ দিন কাজে ১ — মোট ১
        $over = $this->employee('E-O', '2024-01-01', null, '2026-09-30');
        $this->rows($over, '2025-01-01', 18, Attendance::PRESENT);
        $this->rows($over, '2026-01-01', 18, Attendance::PRESENT);
        $this->approved($over, '2026-04-06', '2026-04-08', '3');
        $this->assertSame('1.0', $this->leave()->encashableEarnedDays($over, Carbon::parse('2026-09-30')), '⛔ বাকি শূন্যের নিচে নেমে এ বছরের অর্জন খেয়ে ফেলল');

        // ⛔ এক বছরের আগে ছাড়লে অর্জিত ছুটিই নেই
        $short = $this->employee('E-S', '2026-01-01', null, '2026-09-30');
        app(SalaryStructureService::class)->set($short, $head, '2026-01-01', '30000');
        $this->rows($short, '2026-01-01', 40, Attendance::PRESENT);
        $this->assertSame('0.0', $this->leave()->encashableEarnedDays($short, Carbon::parse('2026-09-30')));
        $this->assertSame('0.00', $this->leave()->earnedLeaveEncashment($short, Carbon::parse('2026-09-30'))['amount']);
    }

    /**
     * ⭐ ধারা ২(৪৫)-এর মজুরি — মূল + বাড়িভাড়া; যাতায়াত আর উৎসব ভাতা বাদ (fe-র উত্তর ১, ২১ অক্টোবর ২০২৬; [[EarnedLeave::monthlyWages()]])।
     */
    public function test_the_daily_rate_counts_basic_and_house_rent_but_not_travel_or_festival(): void
    {
        $head = fn (string $code, string $calculation, bool $basic, bool $outside) => SalaryHead::query()->create([
            'company_id' => $this->company->id, 'code' => $code, 'name_en' => $code, 'kind' => SalaryHead::EARNING,
            'calculation' => $calculation, 'is_basic' => $basic, 'prorated_by_attendance' => true, 'outside_wages' => $outside, 'is_active' => true]);

        $leaver = $this->employee('E-W', '2024-01-01', null, '2026-09-30');
        $structure = app(SalaryStructureService::class);
        $structure->set($leaver, $head('BASIC', SalaryHead::FIXED, true, false), '2024-01-01', '20000');
        $structure->set($leaver, $head('HRA', SalaryHead::PERCENT_OF_BASIC, false, false), '2024-01-01', '50');
        $structure->set($leaver, $head('CONVEY', SalaryHead::FIXED, false, true), '2024-01-01', '3000');
        $structure->set($leaver, $head('FESTIVAL', SalaryHead::FIXED, false, true), '2024-01-01', '5000');
        // ⓘ ২০২৫-এ ৩৬ দিন কাজ — ২ দিন
        $this->rows($leaver, '2025-01-01', 36, Attendance::PRESENT);

        $this->assertSame(['days' => '2.0', 'monthly_wage' => '30000.00', 'daily_rate' => '1000.00', 'amount' => '2000.00'],
            $this->leave()->earnedLeaveEncashment($leaver, Carbon::parse('2026-09-30')),
            '⛔ দৈনিক হারে যাতায়াত বা উৎসব ভাতা ঢুকল (৩৮,০০০ ÷ ৩০), বা বাড়িভাড়া বাদ পড়ল');

        // ⓘ যে পর্দা টাকাটা দেখায়, সেখানে নিয়মটা বাংলায় লেখা
        $viewer = $this->user(['hr.employee.view', 'hr.salary.view', 'hr.leave.view']);
        $this->as($viewer);
        $this->get(route('hr.employee.show', $leaver))->assertOk()
            ->assertSee('data-encashment', false)
            ->assertSee(__('hr::profile.encashment_rule'));
    }

    private function approved(Employee $employee, string $from, string $to, string $days): void
    {
        LeaveApplication::query()->create(['company_id' => $this->company->id, 'employee_id' => $employee->id,
            'leave_type_id' => $this->earned->id, 'from_date' => $from, 'to_date' => $to, 'days' => $days,
            'status' => LeaveApplication::APPROVED]);
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
