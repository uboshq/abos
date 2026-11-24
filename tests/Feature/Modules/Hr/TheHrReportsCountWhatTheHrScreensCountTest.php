<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\LeaveApplication;
use App\Modules\Hr\Models\LeaveType;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Services\AttendanceService;
use App\Modules\Hr\Services\LeaveService;
use App\Modules\MasterData\Models\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ HR-এর চারটা রিপোর্ট HR-এর পর্দা যা গোনে তা-ই গোনে (ঘাটতির তালিকা #১৬; a6, ২১ অক্টোবর ২০২৬; [[HrReports]])।
 *
 * দাবি: প্রতিটা রিপোর্ট নিজের চাবিতে ২০০, চাবি ছাড়া ৪০৩; হাজিরার সারাংশ = [[AttendanceService::monthlySummary()]], ছুটির জের =
 * [[LeaveService::balance()]], বেতনশিট = নিশ্চিত রানের স্লিপ (খসড়া আর বাতিল নয়); বেতনের চাবি ছাড়া বেতনশিটে টাকার ঘর নেই, আর
 * অঙ্কটা পাতার HTML-এই নেই; শাখায় সীমিত ব্যবস্থাপক কেবল নিজের শাখার কর্মী পান; কর্মী সংখ্যায় যোগদান আর বিদায় পরিসর ধরে।
 */
final class TheHrReportsCountWhatTheHrScreensCountTest extends TestCase
{
    use RefreshDatabase;

    /** slug → [রিপোর্টের কী, চাবি] — [[HrReportController::SLUGS]]-এর হুবহু */
    private const DOORS = [
        'attendance' => ['hr.attendance_summary', 'hr.attendance.view'],
        'payroll' => ['hr.payroll_register', 'hr.payroll.view'],
        'headcount' => ['hr.headcount', 'hr.employee.view'],
        'leave-balance' => ['hr.leave_balance', 'hr.leave.view'],
    ];

    private const SEPT = ['from' => '2026-09-01', 'to' => '2026-09-30'];

    private Company $company;

    private Branch $dhaka;

    private Employee $rahim;

    private Employee $karim;

    private Employee $brief;

    private LeaveType $casual;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['code' => 'HRR', 'name_en' => 'Report Co']);
        CompanyContext::set($this->company->id);

        $this->dhaka = Branch::query()->create(['company_id' => $this->company->id, 'code' => 'DHK', 'name_en' => 'Dhaka', 'is_active' => true]);
        $netrokona = Branch::query()->create(['company_id' => $this->company->id, 'code' => 'NTK', 'name_en' => 'Netrokona', 'is_active' => true]);
        $sales = Department::query()->create(['company_id' => $this->company->id, 'code' => 'SAL', 'name_en' => 'Field Sales Team', 'is_active' => true]);

        foreach (['hr.attendance.view', 'hr.payroll.view', 'hr.salary.view', 'hr.employee.view', 'hr.leave.view'] as $key) {
            Permission::findOrCreate($key, 'web');
        }

        $this->rahim = $this->employee('E-1', $this->dhaka, 'Rahim Uddin', $sales);
        $this->karim = $this->employee('E-2', $netrokona, 'Karim Mia', $sales);
        // ⓘ সেপ্টেম্বরে যোগ দিলেন আর ছাড়লেন — কর্মী সংখ্যার যোগদান আর বিদায়
        $this->brief = $this->employee('E-3', $this->dhaka, 'Selim Brief', $sales, '2026-09-10', '2026-09-25');
        // ⓘ আগস্টেই ছেড়ে গেছেন — সেপ্টেম্বরের হাজিরা আর ছুটির জেরে নেই
        $this->employee('E-4', $this->dhaka, 'Old Timer', $sales, '2026-01-01', '2026-08-15');
        // ⓘ নেত্রকোনায় মাসের মাঝে যোগ দিলেন, রয়ে গেলেন — যোগদান আছে, বিদায় নেই
        $this->employee('E-5', $netrokona, 'New Hire', $sales, '2026-09-15');

        // ⓘ রহিমের সেপ্টেম্বর: উপস্থিত ৩ (একদিন দেরি), অনুপস্থিত ১, ছুটি ১, সাপ্তাহিক ছুটি ২; করিমের উপস্থিত ২; আগস্টের সারি গোনায় নয়
        foreach ([
            [$this->rahim, '2026-09-01', Attendance::PRESENT, true],
            [$this->rahim, '2026-09-02', Attendance::PRESENT, false],
            [$this->rahim, '2026-09-03', Attendance::PRESENT, false],
            [$this->rahim, '2026-09-04', Attendance::ABSENT, false],
            [$this->rahim, '2026-09-05', Attendance::HOLIDAY, false],
            [$this->rahim, '2026-09-06', Attendance::LEAVE, false],
            [$this->rahim, '2026-09-07', Attendance::HOLIDAY, false],
            [$this->rahim, '2026-08-31', Attendance::ABSENT, false],
            [$this->karim, '2026-09-01', Attendance::PRESENT, false],
            [$this->karim, '2026-09-02', Attendance::PRESENT, true],
        ] as [$who, $day, $status, $late]) {
            Attendance::query()->create(['company_id' => $this->company->id, 'employee_id' => $who->id,
                'work_date' => $day, 'status' => $status, 'is_late' => $late]);
        }

        $this->casual = LeaveType::query()->create(['company_id' => $this->company->id, 'code' => 'CL', 'name_en' => 'Casual',
            'days_per_year' => '10', 'is_paid' => true, 'is_active' => true]);
        // ⓘ সীমাহীন ধরন — জেরে আসে না
        LeaveType::query()->create(['company_id' => $this->company->id, 'code' => 'LWP', 'name_en' => 'Without Pay',
            'days_per_year' => '0', 'is_paid' => false, 'is_active' => true]);

        // ⓘ মঞ্জুর ২.৫ দিন গোনায়; অপেক্ষমাণ ১ আর গত বছরের ৩ নয়
        $this->leave($this->rahim, '2026-09-06', '2026-09-08', '2.5', LeaveApplication::APPROVED);
        $this->leave($this->rahim, '2026-09-20', '2026-09-20', '1', LeaveApplication::PENDING);
        $this->leave($this->rahim, '2025-12-01', '2025-12-03', '3', LeaveApplication::APPROVED);

        // ⓘ সেপ্টেম্বরের নিশ্চিত রান; একই মাসের খসড়া, বাতিল আর মুছে ফেলা রান বেতনশিটে নয়
        $this->makeRun('PRL-SEP', '2026-09-01', DocumentStatus::CONFIRMED, [[$this->rahim, '98765.43', '1234.56', 'bank'], [$this->karim, '45678.91', '0', 'cash']]);
        $this->makeRun('PRL-SEPD', '2026-09-01', DocumentStatus::DRAFT, [[$this->rahim, '33333.33', '0', 'bank']]);
        $this->makeRun('PRL-SEPX', '2026-09-01', DocumentStatus::CANCELLED, [[$this->rahim, '22222.22', '0', 'bank']]);
        $this->makeRun('PRL-SEPZ', '2026-09-01', DocumentStatus::CONFIRMED, [[$this->rahim, '11111.11', '0', 'bank']])->delete();
    }

    public function test_each_hr_report_opens_for_its_own_key_and_is_refused_without_it(): void
    {
        foreach (self::DOORS as $slug => [$report, $key]) {
            $this->assertSame([$key], app(ReportEngine::class)->get($report)->permissions(), "⛔ {$report}-এর সংজ্ঞা অন্য চাবি বলে।");

            $this->as($this->user([$key]))->get(route('hr.report.show', ['slug' => $slug] + self::SEPT))->assertOk();

            // ⛔ বাকি তিনটা চাবি হাতে, কেবল এটা নেই — ৪০৩
            $others = array_values(array_diff(array_column(self::DOORS, 1), [$key]));
            $this->as($this->user($others))->get(route('hr.report.show', ['slug' => $slug] + self::SEPT))->assertForbidden();
        }

        $this->as($this->user(['hr.payroll.view']))->get(route('hr.report.show', ['slug' => 'no-such-report']))->assertNotFound();
    }

    public function test_the_attendance_summary_counts_what_the_monthly_summary_counts(): void
    {
        $this->as($this->user(['hr.attendance.view']));
        $rows = $this->rows('hr.attendance_summary');

        foreach ([$this->rahim, $this->karim] as $employee) {
            $row = $rows[$employee->code] ?? null;
            $this->assertNotNull($row, "⛔ {$employee->code} হাজিরার সারাংশে নেই।");

            $summary = app(AttendanceService::class)->monthlySummary($employee, Carbon::parse('2026-09-01'));

            foreach (['present' => 'present', 'absent' => 'absent', 'on_leave' => 'leave', 'holiday' => 'holiday', 'late' => 'late', 'marked' => 'marked'] as $column => $field) {
                $this->assertSame($summary[$field], (int) $row[$column], "⛔ {$employee->code}-এর {$column} প্রোফাইলের গোনার সাথে মেলে না।");
            }
        }

        // ⓘ সংখ্যাগুলো নিজেরাও — দুই দিকই শূন্য হয়ে "মিলে যাওয়া" নয়
        $this->assertSame([3, 1, 1, 2, 1, 7], array_map('intval', array_values(array_intersect_key($rows['E-1'], array_flip(['present', 'absent', 'on_leave', 'holiday', 'late', 'marked'])))));
        $this->assertSame([2, 1], [(int) $rows['E-2']['present'], (int) $rows['E-2']['late']]);
        // ⓘ কর্মরত অথচ একদিনও বসানো নেই — তালিকায় থাকেন, শূন্য নিয়ে
        $this->assertNotNull($rows['E-3']['marked']);
        $this->assertSame(0, (int) $rows['E-3']['marked']);
        $this->assertArrayNotHasKey('E-4', $rows, '⛔ আগস্টে ছেড়ে যাওয়া কর্মী সেপ্টেম্বরের হাজিরায়।');
    }

    public function test_the_leave_balance_is_the_balance_the_leave_screen_shows(): void
    {
        $this->as($this->user(['hr.leave.view']));
        $rows = collect(app(ReportEngine::class)->run('hr.leave_balance', self::SEPT)->rows)
            ->filter(fn (array $row) => str_starts_with((string) $row['employee'], 'E-1 '))
            ->values();

        $this->assertCount(1, $rows, '⛔ রহিমের জেরে সীমাহীন ধরনও এসেছে, বা চালু ধরনটা নেই।');
        $this->assertFalse(collect(app(ReportEngine::class)->run('hr.leave_balance', self::SEPT)->rows)
            ->contains(fn (array $row) => str_starts_with((string) $row['employee'], 'E-4 ')), '⛔ ছেড়ে যাওয়া কর্মীর ছুটির জের।');

        $balance = app(LeaveService::class)->balance($this->rahim, $this->casual, Carbon::parse('2026-09-30'));

        $this->assertSame(0, bccomp($balance['allowed'], (string) $rows[0]['allowed'], 1));
        $this->assertSame(0, bccomp($balance['taken'], (string) $rows[0]['taken'], 1), '⛔ নেওয়া ছুটি ছুটির পাতার জেরের সাথে মেলে না।');
        $this->assertSame(0, bccomp((string) $balance['left'], (string) $rows[0]['remaining'], 1));
        $this->assertSame(0, bccomp('7.5', (string) $rows[0]['remaining'], 1), '⛔ অপেক্ষমাণ বা গত বছরের ছুটি গোনা হয়েছে।');
    }

    public function test_the_payroll_register_lists_the_confirmed_slips_with_the_payslips_money(): void
    {
        $this->as($this->user(['hr.payroll.view', 'hr.salary.view']));
        $rows = $this->rows('hr.payroll_register');

        $this->assertEqualsCanonicalizing(['E-1', 'E-2'], array_keys($rows), '⛔ খসড়া বা বাতিল রানের স্লিপ বেতনশিটে।');
        $this->assertCount(2, app(ReportEngine::class)->run('hr.payroll_register', self::SEPT)->rows, '⛔ খসড়া, বাতিল বা মুছে ফেলা রানের স্লিপ বেতনশিটে।');
        $this->assertSame(__('hr::kind.cash'), $rows['E-2']['paid_by']);
        // ⓘ মাসের মাঝের তারিখ থেকে চাইলেও সেই মাসের বেতনশিট — মাস ধরে, দিন ধরে নয়
        $this->assertCount(2, app(ReportEngine::class)->run('hr.payroll_register', ['from' => '2026-09-15', 'to' => '2026-09-30'])->rows);

        $confirmed = PayrollRun::acrossBranches()->where('document_no', 'PRL-SEP')->firstOrFail();

        foreach (Payslip::query()->where('payroll_run_id', $confirmed->id)->get() as $slip) {
            $row = $rows[Employee::query()->findOrFail($slip->employee_id)->code];

            foreach (['gross', 'deductions', 'net'] as $money) {
                $this->assertSame(0, bccomp((string) $slip->{$money}, (string) $row[$money], 2), "⛔ বেতনশিটের {$money} স্লিপের সাথে মেলে না।");
            }

            $this->assertSame('PRL-SEP', $row['document_no']);
        }

        $this->get(route('hr.report.show', ['slug' => 'payroll'] + self::SEPT))->assertOk()
            ->assertSee(Money::format('98765.43'))
            ->assertDontSee(Money::format('33333.33'))
            ->assertDontSee(Money::format('22222.22'))
            ->assertDontSee(Money::format('11111.11'));
    }

    public function test_without_the_salary_key_the_register_has_no_money_at_all(): void
    {
        $user = $this->user(['hr.payroll.view']);
        $result = $this->as($user)->get(route('hr.report.show', ['slug' => 'payroll'] + self::SEPT))->assertOk()
            ->assertSee('Rahim Uddin');

        foreach (['98765.43', '1234.56', '97530.87', '45678.91', '144444.34'] as $amount) {
            $result->assertDontSee(Money::format($amount));
        }

        $shown = array_map(fn ($c) => $c->key, app(ReportEngine::class)->run('hr.payroll_register', self::SEPT)->columnsFor($user));
        $this->assertSame([], array_intersect(['gross', 'deductions', 'net'], $shown), '⛔ টাকার ঘর বেতনের চাবি ছাড়াই দেখা যায়।');

        // ⭐ চাবি ফেরালে অঙ্কটা ফেরে — ঢাকনাটা চাবির জন্যই
        $user->givePermissionTo('hr.salary.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->as($user)->get(route('hr.report.show', ['slug' => 'payroll'] + self::SEPT))->assertOk()
            ->assertSee(Money::format('98765.43'));
    }

    public function test_a_branch_limited_manager_sees_only_their_branchs_people(): void
    {
        $manager = $this->user(array_column(self::DOORS, 1), $this->dhaka);

        foreach (self::DOORS as $slug => [$report]) {
            $this->as($manager);
            $text = json_encode(app(ReportEngine::class)->run($report, self::SEPT)->rows, JSON_UNESCAPED_UNICODE);

            // ⓘ কর্মী সংখ্যার সারি বিভাগ আর শাখা — নাম নয়; বাকি তিনটায় কর্মীর নাম
            $this->assertStringContainsString($report === 'hr.headcount' ? 'Dhaka' : 'Rahim Uddin', $text, "⛔ {$report}: নিজের শাখার সারি নেই।");
            $this->assertStringNotContainsString('Karim Mia', $text, "⛔ {$report}: অন্য শাখার কর্মী সীমিত ব্যবস্থাপকের রিপোর্টে।");
            $this->assertStringNotContainsString('Netrokona', $text, "⛔ {$report}: অন্য শাখার সারি।");

            $this->as($manager)->get(route('hr.report.show', ['slug' => $slug] + self::SEPT))->assertOk()
                ->assertDontSee('Karim Mia');
        }

        // ⓘ বেতনশিটের মোটও কেবল ঢাকার — করিমের ৪৫,৬৭৮.৯১ মোটে মেশে না
        $this->as($manager);
        $this->assertSame(0, bccomp('98765.43', (string) app(ReportEngine::class)->run('hr.payroll_register', self::SEPT)->totals['gross'], 2));
    }

    public function test_the_headcount_counts_the_day_and_the_joiners_and_leavers_of_the_range(): void
    {
        $this->as($this->user(['hr.employee.view']));
        $rows = collect(app(ReportEngine::class)->run('hr.headcount', self::SEPT)->rows)->keyBy('branch');

        // ⓘ ঢাকা: রহিম আছেন, সেলিম ১০ তারিখে এসে ২৫ তারিখে গেলেন — ৩০ তারিখে ১ জন, যোগ ১, বিদায় ১
        $this->assertSame([1, 1, 1], [(int) $rows['Dhaka']['headcount'], (int) $rows['Dhaka']['joiners'], (int) $rows['Dhaka']['leavers']]);
        $this->assertSame([2, 1, 0], [(int) $rows['Netrokona']['headcount'], (int) $rows['Netrokona']['joiners'], (int) $rows['Netrokona']['leavers']]);
        $this->assertSame('Field Sales Team', $rows['Dhaka']['department']);

        // ⓘ ২০ তারিখে সেলিম তখনো ছিলেন — "যেদিন পর্যন্ত" বদলালে সংখ্যাও বদলায়, ছাড়ার দিনটাও কাজের দিন
        $this->assertSame(2, (int) collect(app(ReportEngine::class)->run('hr.headcount', ['from' => '2026-09-01', 'to' => '2026-09-25'])->rows)
            ->keyBy('branch')['Dhaka']['headcount']);
    }

    // ── সাহায্যকারী ─────────────────────────────────────────────────────

    /** @return array<string, array<string, mixed>> কর্মীর কোড → সারি */
    private function rows(string $report): array
    {
        $out = [];

        foreach (app(ReportEngine::class)->run($report, self::SEPT)->rows as $row) {
            $out[strtok((string) $row['employee'], ' ')] = $row;
        }

        return $out;
    }

    /** @param  list<string>  $keys */
    private function user(array $keys, ?Branch $only = null): User
    {
        $user = User::factory()->create(['current_company_id' => $this->company->id]);
        $user->companies()->attach($this->company->id);
        CompanyContext::forCompany($this->company->id, fn () => $user->givePermissionTo($keys));

        if ($only !== null) {
            UserDataScope::query()->create(['company_id' => $this->company->id, 'user_id' => $user->id,
                'scope_type' => UserDataScope::BRANCH, 'scope_id' => $only->id]);
            // ⛔ একটা শাখা বাছা — নইলে "সব শাখা" আর দেয়ালের দাবি কিছুই মাপে না (a-branch-wall-claim-needs-one-chosen-branch)
            $user->forceFill(['current_branch_id' => $only->id, 'view_all_branches' => false])->save();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        app(DataScope::class)->forget();

        return $user;
    }

    private function as(User $user): static
    {
        $this->app['auth']->forgetGuards();
        app(DataScope::class)->forget();

        return $this->actingAs($user->fresh());
    }

    private function employee(string $code, Branch $branch, string $name, Department $department, string $joined = '2026-01-01', ?string $left = null): Employee
    {
        return Employee::query()->create(['company_id' => $this->company->id, 'branch_id' => $branch->id, 'code' => $code,
            'name_en' => $name, 'department_id' => $department->id, 'joining_date' => $joined, 'leaving_date' => $left]);
    }

    private function leave(Employee $employee, string $from, string $to, string $days, string $status): void
    {
        LeaveApplication::query()->create(['company_id' => $this->company->id, 'employee_id' => $employee->id,
            'leave_type_id' => $this->casual->id, 'from_date' => $from, 'to_date' => $to, 'days' => $days, 'status' => $status]);
    }

    /** @param  list<array{0: Employee, 1: string, 2: string, 3: string}>  $slips */
    private function makeRun(string $no, string $month, string $status, array $slips): PayrollRun
    {
        $run = PayrollRun::acrossBranches()->create([
            'company_id' => $this->company->id, 'branch_id' => $this->dhaka->id, 'document_no' => $no,
            'month' => $month, 'trx_date' => Carbon::parse($month)->endOfMonth()->toDateString(), 'status' => $status,
            'employee_count' => count($slips), 'gross_total' => '0', 'deduction_total' => '0', 'net_total' => '0',
        ]);

        foreach ($slips as [$employee, $gross, $deductions, $method]) {
            Payslip::query()->create(['company_id' => $this->company->id, 'payroll_run_id' => $run->id, 'employee_id' => $employee->id,
                'gross' => $gross, 'deductions' => $deductions, 'net' => bcsub($gross, $deductions, 2), 'payment_method' => $method]);
        }

        return $run;
    }
}
