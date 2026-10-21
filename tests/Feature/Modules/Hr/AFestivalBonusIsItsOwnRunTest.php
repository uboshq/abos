<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\SalaryHead;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\PayrollService;
use App\Modules\Hr\Services\SalaryHeadService;
use App\Modules\Hr\Services\SalaryStructureService;
use App\Modules\MasterData\Services\MasterListService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\SignsTheSecondHand;
use Tests\TestCase;

/**
 * ⭐ উৎসব ভাতা — আলাদা রান, এক বছরের চাকরি, মূল পর্যন্ত, বছরে দুইটা, নিজের খাত (শ্রম বিধিমালা ২০১৫ বিধি ১১১; a6, ২১ অক্টোবর ২০২৬;
 * [[PayrollService::buildBonus()]])।
 *
 * দাবি:
 *  · যোগ্যতা রানের দিনে — ঠিক এক বছর হলে পান, এক দিন কম হলে নন।
 *  · অঙ্ক পয়সা পর্যন্ত — ১০০%-এ পুরো মূল; ৭৫%-এ ২৩৪৫৬.৭৮ × ০.৭৫ = ১৭৫৯২.৫৮৫ → ১৭৫৯২.৫৯; ১০০-এর বেশি বসালেও এক মাসের মূল।
 *  · বছরে তৃতীয়টা ফেরে; বাতিলটা গোনায় নয়। একই মাসে বেতন আর ভাতা দুটোই চলে, দুইটা ভাতা নয়।
 *  · খাতায় ৫২২৬ ডেবিট = স্লিপের যোগ, প্রদেয় বেতন ক্রেডিট = নিট, বেতন-খরচে (৫২০১) কিছু নয়; দাখিলা মেলে। রানের ধরন `bonus`,
 *    পুরনো রান `salary`।
 */
final class AFestivalBonusIsItsOwnRunTest extends TestCase
{
    use RefreshDatabase;
    // ⛔ বেতনের রান দ্বিতীয় সই ছাড়া খাতায় বসে না, সব ছক বন্ধ থাকলেও — সই-শাখার নিয়ম, মেশানোর পরে এখানেও (a6, ২১ অক্টোবর ২০২৬; [[ApprovalRules::secondHand()]])
    use SignsTheSecondHand;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();
        app(MasterListService::class)->installDefaults();
        app(SalaryHeadService::class)->installDefaults();
        Employee::query()->delete();
    }

    public function test_only_a_full_year_of_service_on_the_run_date_earns_the_bonus(): void
    {
        $old = $this->employee('BN-A', '2025-03-01', '23456.78');
        $exact = $this->employee('BN-C', '2025-04-10', '30000');
        $short = $this->employee('BN-B', '2025-04-11', '30000');

        $run = $this->payroll()->buildBonus('2026-04-10', 'Eid-ul-Fitr');

        $this->assertSame(PayrollRun::BONUS, $run->run_type);
        $this->assertSame(2, $run->employee_count);
        $this->assertSame(0, bccomp((string) $run->payslips()->where('employee_id', $old->id)->value('gross'), '23456.78', 4), '⛔ ১০০%-এ পুরো মূল।');
        $this->assertTrue($run->payslips()->where('employee_id', $exact->id)->exists(), '⛔ ঠিক এক বছর হয়েছে, অথচ ভাতা নেই।');
        $this->assertFalse($run->payslips()->where('employee_id', $short->id)->exists(), '⛔ এক বছর হয়নি, অথচ ভাতা পেলেন।');
    }

    public function test_the_amount_follows_the_percentage_to_the_paisa_and_never_passes_one_basic(): void
    {
        $a = $this->employee('BN-A', '2025-03-01', '23456.78');

        app(SettingsService::class)->set(PayrollService::BONUS_PERCENT, 75);
        $run = $this->payroll()->buildBonus('2026-04-10');
        $this->assertSame(0, bccomp((string) $run->payslips()->where('employee_id', $a->id)->value('gross'), '17592.59', 4),
            '⛔ ২৩৪৫৬.৭৮ × ৭৫% = ১৭৫৯২.৫৯ হওয়ার কথা।');
        $this->payroll()->cancel($run, 'test');

        app(SettingsService::class)->set(PayrollService::BONUS_PERCENT, 150);
        $run = $this->payroll()->buildBonus('2026-04-10');
        $this->assertSame(0, bccomp((string) $run->payslips()->where('employee_id', $a->id)->value('gross'), '23456.78', 4),
            '⛔ এক মাসের মূলের বেশি উৎসব ভাতা।');
    }

    public function test_a_third_bonus_in_a_year_is_refused_and_a_cancelled_one_does_not_count(): void
    {
        $this->employee('BN-A', '2025-03-01', '23456.78');

        $this->payroll()->buildBonus('2026-04-10');
        $second = $this->payroll()->buildBonus('2026-06-16');

        try {
            $this->payroll()->buildBonus('2026-10-05');
            $this->fail('⛔ বছরের তৃতীয় উৎসব ভাতা বসল।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('bonus_date', $e->errors());
        }

        $this->payroll()->cancel($second, 'wrong date');
        $this->assertSame(PayrollRun::BONUS, $this->payroll()->buildBonus('2026-10-05')->run_type, '⛔ বাতিল রানও গোনায় পড়ল।');
    }

    /**
     * ⭐ সীমা পঞ্জিকা-বছরের — জানুয়ারি থেকে ডিসেম্বর, আয়ের বছর (জুলাই–জুন) নয় (fe, ২১ অক্টোবর ২০২৬, দফা ৫)।
     * ⓘ ২০২৬-এর দুইটার পরে ডিসেম্বর ২০২৬ ফেরে, অথচ জানুয়ারি ২০২৭ চলে — জুলাই–জুন ধরলে দুটোই এক বছরের হত।
     */
    public function test_the_limit_is_two_per_calendar_year(): void
    {
        $this->employee('BN-A', '2025-03-01', '23456.78');

        $this->payroll()->buildBonus('2026-04-10');
        $this->payroll()->buildBonus('2026-06-16');

        try {
            $this->payroll()->buildBonus('2026-12-20');
            $this->fail('⛔ ২০২৬ সালের তৃতীয় উৎসব ভাতা বসল।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('bonus_date', $e->errors());
        }

        $this->assertSame(PayrollRun::BONUS, $this->payroll()->buildBonus('2027-01-10')->run_type,
            '⛔ নতুন পঞ্জিকা-বছরের প্রথম উৎসব ভাতা আটকে গেল।');
    }

    public function test_one_live_run_a_month_holds_per_type(): void
    {
        $this->employee('BN-A', '2025-03-01', '23456.78');

        $salary = $this->payroll()->build('2026-04-01');
        $bonus = $this->payroll()->buildBonus('2026-04-10');
        $this->assertSame(PayrollRun::SALARY, $salary->run_type);
        $this->assertSame(PayrollRun::BONUS, $bonus->run_type);

        $this->expectException(ValidationException::class);
        $this->payroll()->buildBonus('2026-04-20');
    }

    public function test_the_bonus_journal_matches_the_payslips(): void
    {
        $this->employee('BN-A', '2025-03-01', '23456.78');
        $this->employee('BN-C', '2025-04-10', '30000.01');

        $run = $this->confirmedPayroll($this->payroll()->buildBonus('2026-04-10'));

        $slips = $run->payslips()->get();
        $gross = $slips->reduce(fn ($c, $s) => bcadd($c, (string) $s->gross, 4), '0');
        $this->assertSame(0, bccomp($gross, '53456.79', 4));
        $this->assertSame(0, bccomp($this->posted($run, StandardChart::FESTIVAL_BONUS_EXPENSE, 'debit'), $gross, 4), '⛔ ভাতার খরচ স্লিপের যোগ নয়।');
        $this->assertSame(0, bccomp($this->posted($run, StandardChart::SALARY_PAYABLE, 'credit'), (string) $run->net_total, 4));
        $this->assertSame(0, bccomp($this->posted($run, StandardChart::SALARY_EXPENSE, 'debit'), '0', 4), '⛔ ভাতা বেতন-খরচে বসল।');

        $rows = LedgerEntry::query()->where('source_type', PayrollRun::SOURCE_TYPE)->where('source_id', $run->id)->get();
        $this->assertSame(0, bccomp($rows->reduce(fn ($c, $e) => bcadd($c, (string) $e->debit, 4), '0'),
            $rows->reduce(fn ($c, $e) => bcadd($c, (string) $e->credit, 4), '0'), 4), '⛔ ভাতার দাখিলা মেলেনি।');

        // ⓘ আবার বানানো খসড়াতেই চলে; নিশ্চিত রান বদলায় না
        $this->expectException(ValidationException::class);
        $this->payroll()->rebuild($run);
    }

    public function test_a_confirmed_bonus_does_not_close_the_months_attendance(): void
    {
        $a = $this->employee('BN-A', '2025-03-01', '23456.78');
        $this->confirmedPayroll($this->payroll()->buildBonus('2026-04-10'));

        // ⓘ ভাতা হাজিরা পড়ে না — মাসের বেতন এখনো বাকি, তাই এপ্রিলের হাজিরা খোলা ([[PaidMonth]])
        $day = app(\App\Modules\Hr\Services\AttendanceService::class)->mark($a, '2026-04-15', 'present');
        $this->assertSame('present', $day->status, '⛔ উৎসব ভাতা নিশ্চিত হতেই মাসের হাজিরা বন্ধ হলো।');
    }

    public function test_a_bonus_draft_rebuilds_by_its_own_rule(): void
    {
        $a = $this->employee('BN-A', '2025-03-01', '23456.78');
        $run = $this->payroll()->buildBonus('2026-04-10');

        app(SalaryStructureService::class)->set($a, SalaryHead::query()->where('code', 'BASIC')->firstOrFail(), '2026-04-01', '25000');
        $run = $this->payroll()->rebuild($run);

        $this->assertSame(1, $run->employee_count);
        $this->assertSame(0, bccomp((string) $run->gross_total, '25000', 4), '⛔ আবার বানানো ভাতার নিয়মে নয়।');
        $this->assertSame(0, $run->payslips()->first()->lines()->where('head_code', '<>', PayrollService::BONUS_CODE)->count(),
            '⛔ ভাতার রানে বেতনের সারি ঢুকল।');
    }

    // ── সাহায্য ──────────────────────────────────────────────────────────

    private function payroll(): PayrollService
    {
        return app(PayrollService::class);
    }

    private function employee(string $code, string $joined, string $basic): Employee
    {
        $employee = app(EmployeeService::class)->create([
            'code' => $code, 'name_en' => 'Person '.$code, 'joining_date' => $joined, 'payment_method' => 'cash',
        ]);
        $salaries = app(SalaryStructureService::class);
        $salaries->set($employee, SalaryHead::query()->where('code', 'BASIC')->firstOrFail(), $joined, $basic);
        $salaries->set($employee, SalaryHead::query()->where('code', 'HRA')->firstOrFail(), $joined, '50');

        return $employee->fresh();
    }

    private function posted(PayrollRun $run, string $code, string $side): string
    {
        return bcadd((string) LedgerEntry::query()
            ->where('source_type', PayrollRun::SOURCE_TYPE)->where('source_id', $run->id)
            ->where('account_id', Account::query()->where('code', $code)->value('id'))
            ->sum($side), '0', 4);
    }
}
