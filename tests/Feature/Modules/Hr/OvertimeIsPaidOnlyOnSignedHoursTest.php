<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\OvertimeSheet;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\PayslipLine;
use App\Modules\Hr\Models\SalaryHead;
use App\Modules\Hr\Services\AttendanceService;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\OvertimeService;
use App\Modules\Hr\Services\PayrollService;
use App\Modules\Hr\Services\SalaryHeadService;
use App\Modules\Hr\Services\SalaryStructureService;
use App\Modules\MasterData\Models\EmploymentType;
use App\Modules\MasterData\Services\MasterListService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\SignsTheSecondHand;
use Tests\TestCase;

/**
 * ⭐ অধিকাল — BLA ধারা ১০৮: মূল ÷ ২০৮ × ২ × বাড়তি ঘণ্টা, কেবল ব্যবস্থাপকের সই হওয়া ঘণ্টা (a6, ২১ অক্টোবর ২০২৬; [[OvertimeService]])।
 *
 * দাবি:
 *  · হাজিরা থেকে ঘণ্টা — ১০.৫ ঘণ্টার দিনে ২.৫, ৮ ঘণ্টার দিনে ০, ১০.২৫ ঘণ্টার দিনে ২.২৫ → ৪.৭৫ ঘণ্টা, ২ দিন।
 *  · টাকা পয়সা পর্যন্ত — মূল ২৩৪৫৬.৭৮ × ২ × ৪.৭৫ ÷ ২০৮ = ১০৭১.৩৪; স্লিপের মোট আয়ে, খাতায় ৫২০১ ডেবিটে, দাখিলা মেলে।
 *  · সই নেই — বেতনে নেই; যোগ্য নন (স্থায়ী) — সই থাকলেও নেই; সুইচ বন্ধ — নেই।
 *  · নিজের শিটে নিজে নয়, যিনি বানালেন তিনিও নন; ব্যবস্থাপক পারেন (দরজা দিয়েও); মালিক নিজে বানিয়ে সই দিলে অডিটে owner_self_signed।
 */
final class OvertimeIsPaidOnlyOnSignedHoursTest extends TestCase
{
    use RefreshDatabase;
    // ⛔ বেতনের রান দ্বিতীয় সই ছাড়া খাতায় বসে না, সব ছক বন্ধ থাকলেও — সই-শাখার নিয়ম, মেশানোর পরে এখানেও (a6, ২১ অক্টোবর ২০২৬; [[ApprovalRules::secondHand()]])
    use SignsTheSecondHand;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->owner);

        app(StandardChart::class)->install();
        app(MasterListService::class)->installDefaults();
        app(SalaryHeadService::class)->installDefaults();
        Employee::query()->delete();
    }

    public function test_the_hours_come_from_attendance_beyond_eight_a_day(): void
    {
        $worker = $this->worker('OT-W', 'WORKER');

        $counted = app(OvertimeService::class)->hoursFromAttendance($worker, Carbon::parse('2026-07-01'));

        $this->assertSame('4.7500', $counted['hours']);
        $this->assertSame(2, $counted['days']);
    }

    public function test_signed_hours_are_paid_to_the_paisa_and_posted(): void
    {
        $this->overtimeOn();
        [$manager, $managerUser] = $this->manager();
        $worker = $this->worker('OT-W', 'WORKER', $manager);

        $sheet = $this->prepared($worker);
        app(OvertimeService::class)->sign($sheet, $managerUser);

        // ⛔ সই হওয়া ঘণ্টা আর বদলায় না — পরে হাজিরা বদলে আবার বানালেও
        app(AttendanceService::class)->mark($worker, '2026-07-04', 'present', ['in_time' => '08:00:00', 'out_time' => '20:00:00']);
        $this->assertNull(app(OvertimeService::class)->prepareFor($worker, Carbon::parse('2026-07-01')));
        $this->assertSame('4.7500', (string) $sheet->fresh()->hours, '⛔ সই হওয়ার পরে ঘণ্টা বদলাল।');

        $run = $this->payroll()->build('2026-07-01');
        $line = $this->otLine($run, $worker);

        $this->assertNotNull($line, '⛔ সই হওয়া ঘণ্টা বেতনে আসেনি।');
        $this->assertSame('1071.3400', bcadd((string) $line->amount, '0', 4), '⛔ ২৩৪৫৬.৭৮ × ২ × ৪.৭৫ ÷ ২০৮ = ১০৭১.৩৪ হওয়ার কথা।');
        $slip = $run->payslips()->where('employee_id', $worker->id)->firstOrFail();
        $this->assertSame(0, bccomp((string) $slip->gross, '24528.12', 4), '⛔ মোট আয়ে অধিকাল নেই।');

        $run = $this->confirmedPayroll($run);
        $earned = PayslipLine::query()->whereIn('payslip_id', $run->payslips()->select('id'))->where('kind', SalaryHead::EARNING)->sum('amount');
        $this->assertSame(0, bccomp($this->posted($run, StandardChart::SALARY_EXPENSE, 'debit'), bcadd((string) $earned, '0', 4), 4),
            '⛔ খাতার বেতন-খরচ বেতনের স্লিপের আয়ের সমান নয়।');
        $this->assertBalanced($run);
    }

    public function test_hours_waiting_for_a_signature_are_not_paid(): void
    {
        $this->overtimeOn();
        [$manager] = $this->manager();
        $worker = $this->worker('OT-W', 'WORKER', $manager);
        $this->prepared($worker);

        $run = $this->payroll()->build('2026-07-01');

        $this->assertNull($this->otLine($run, $worker), '⛔ সইয়ের অপেক্ষায় থাকা ঘণ্টার টাকা বেতনে।');
    }

    public function test_someone_not_in_an_eligible_type_gets_none_even_when_signed(): void
    {
        $this->overtimeOn();
        [$manager, $managerUser] = $this->manager();
        $officer = $this->worker('OT-P', 'PERM', $manager);
        app(OvertimeService::class)->sign($this->prepared($officer), $managerUser);

        $run = $this->payroll()->build('2026-07-01');

        $this->assertNull($this->otLine($run, $officer), '⛔ শ্রমিক নন, অথচ অধিকাল পেলেন।');
    }

    public function test_with_overtime_off_nothing_is_paid(): void
    {
        [$manager, $managerUser] = $this->manager();
        $worker = $this->worker('OT-W', 'WORKER', $manager);
        app(OvertimeService::class)->sign($this->prepared($worker), $managerUser);

        $this->assertNull($this->otLine($this->payroll()->build('2026-07-01'), $worker), '⛔ সুইচ বন্ধ, অথচ অধিকাল বসল।');
    }

    public function test_nobody_signs_their_own_sheet_or_one_they_built_but_the_manager_does(): void
    {
        [$manager, $managerUser] = $this->manager();
        $workerUser = $this->user([]);
        $worker = $this->worker('OT-W', 'WORKER', $manager, $workerUser);
        $hr = $this->user(['hr.attendance.view', 'hr.attendance.manage']);

        $this->actingAs($hr);
        app(OvertimeService::class)->prepare(Carbon::parse('2026-07-01'), $hr);
        $sheet = OvertimeSheet::query()->where('employee_id', $worker->id)->firstOrFail();

        $service = app(OvertimeService::class);
        $this->assertFalse($service->maySign($workerUser, $sheet), '⛔ নিজের অধিকালে নিজে সই।');
        $this->assertFalse($service->maySign($this->user([]), $sheet), '⛔ ব্যবস্থাপক নন, অথচ সই দিতে পারেন।');
        $this->assertFalse($service->maySign($hr, $sheet), '⛔ যিনি শিট বানালেন তিনিই সই দিলেন।');

        try {
            $service->sign($sheet, $hr);
            $this->fail('⛔ বানানো মানুষটার সই নেওয়া হলো।');
        } catch (ValidationException) {
            $this->assertFalse($sheet->fresh()->isSigned());
        }

        $this->actingAs($workerUser)->post(route('hr.overtime.sign', $sheet->id))->assertForbidden();
        $this->actingAs($managerUser)->post(route('hr.overtime.sign', $sheet->id))->assertRedirect();
        $this->assertTrue($sheet->fresh()->isSigned(), '⛔ ব্যবস্থাপকের সই বসেনি।');
        $this->assertSame((int) $managerUser->id, (int) $sheet->fresh()->signed_by);
    }

    public function test_a_manager_who_built_the_sheet_does_not_sign_it(): void
    {
        [$manager, $managerUser] = $this->manager();
        $worker = $this->worker('OT-W', 'WORKER', $manager);
        $sheet = app(OvertimeService::class)->prepareFor($worker, Carbon::parse('2026-07-01'), (int) $managerUser->id);

        $this->assertFalse(app(OvertimeService::class)->maySign($managerUser, $sheet), '⛔ ব্যবস্থাপক নিজে শিট বানিয়ে নিজেই সই দিলেন।');
    }

    public function test_the_owner_may_sign_a_sheet_they_built_and_it_is_marked(): void
    {
        $worker = $this->worker('OT-W', 'WORKER');
        $sheet = $this->prepared($worker);

        app(OvertimeService::class)->sign($sheet, $this->owner);

        $this->assertTrue($sheet->fresh()->isSigned());
        $this->assertTrue(AuditTrail::query()->forRecord(OvertimeSheet::class, (int) $sheet->id)
            ->where('action', OvertimeService::OWNER_SELF_SIGNED)->exists(), '⛔ মালিকের নিজে-বানানো শিটে নিজের সই অডিটে আলাদা নয়।');
    }

    // ── সাহায্য ──────────────────────────────────────────────────────────

    private function payroll(): PayrollService
    {
        return app(PayrollService::class);
    }

    private function overtimeOn(): void
    {
        app(SettingsService::class)->set(OvertimeService::ENABLED, true);
    }

    /** @return array{0: Employee, 1: User} ব্যবস্থাপক কর্মী আর তাঁর লগইন */
    private function manager(): array
    {
        $user = $this->user([]);
        $employee = app(EmployeeService::class)->create([
            'code' => 'OT-M', 'name_en' => 'Line Manager', 'joining_date' => '2026-01-01', 'payment_method' => 'cash', 'user_id' => $user->id,
        ]);

        return [$employee, $user];
    }

    /** কর্মী — মূল ২৩৪৫৬.৭৮, জুলাইয়ের তিন দিনের হাজিরা (১০.৫, ৮, ১০.২৫ ঘণ্টা) */
    private function worker(string $code, string $type, ?Employee $manager = null, ?User $user = null): Employee
    {
        $employee = app(EmployeeService::class)->create([
            'code' => $code, 'name_en' => 'Worker '.$code, 'joining_date' => '2026-01-15', 'payment_method' => 'cash',
            'employment_type_id' => EmploymentType::query()->where('code', $type)->value('id'),
            'reports_to_employee_id' => $manager?->id, 'user_id' => $user?->id,
        ]);
        app(SalaryStructureService::class)->set($employee, SalaryHead::query()->where('code', 'BASIC')->firstOrFail(), '2026-01-15', '23456.78');

        $attendance = app(AttendanceService::class);
        $attendance->mark($employee, '2026-07-01', 'present', ['in_time' => '09:00:00', 'out_time' => '19:30:00']);
        $attendance->mark($employee, '2026-07-02', 'present', ['in_time' => '09:00:00', 'out_time' => '17:00:00']);
        $attendance->mark($employee, '2026-07-03', 'present', ['in_time' => '08:00:00', 'out_time' => '18:15:00']);

        return $employee->fresh();
    }

    private function prepared(Employee $employee): OvertimeSheet
    {
        $sheet = app(OvertimeService::class)->prepareFor($employee, Carbon::parse('2026-07-01'), (int) $this->owner->id);
        $this->assertNotNull($sheet);
        $this->assertSame(OvertimeSheet::WAITING, $sheet->status);

        return $sheet;
    }

    private function otLine(PayrollRun $run, Employee $employee): ?PayslipLine
    {
        return PayslipLine::query()
            ->whereIn('payslip_id', $run->payslips()->where('employee_id', $employee->id)->select('id'))
            ->where('head_code', OvertimeService::LINE_CODE)
            ->first();
    }

    private function posted(PayrollRun $run, string $code, string $side): string
    {
        return bcadd((string) LedgerEntry::query()
            ->where('source_type', PayrollRun::SOURCE_TYPE)->where('source_id', $run->id)
            ->where('account_id', Account::query()->where('code', $code)->value('id'))
            ->sum($side), '0', 4);
    }

    private function assertBalanced(PayrollRun $run): void
    {
        $rows = LedgerEntry::query()->where('source_type', PayrollRun::SOURCE_TYPE)->where('source_id', $run->id)->get();
        $this->assertSame(0, bccomp(
            $rows->reduce(fn ($c, $e) => bcadd($c, (string) $e->debit, 4), '0'),
            $rows->reduce(fn ($c, $e) => bcadd($c, (string) $e->credit, 4), '0'), 4), '⛔ বেতনের দাখিলা মেলেনি।');
    }

    /** @param list<string> $keys */
    private function user(array $keys): User
    {
        foreach ($keys as $key) {
            Permission::findOrCreate($key, 'web');
        }

        $user = User::factory()->create(['current_company_id' => $this->company->id, 'is_active' => true]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);

        if ($keys !== []) {
            CompanyContext::forCompany($this->company->id, fn () => $user->givePermissionTo($keys));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }
}
