<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\PayslipLine;
use App\Modules\Hr\Models\SalaryHead;
use App\Modules\Hr\Models\SalaryStructure;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\PayrollService;
use App\Modules\Hr\Services\ProvidentFund;
use App\Modules\Hr\Services\SalaryHeadService;
use App\Modules\Hr\Services\SalaryStructureService;
use App\Modules\MasterData\Services\MasterListService;
use Database\Seeders\DemoSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\SignsTheSecondHand;
use Tests\TestCase;

/**
 * ⭐ ভবিষ্য তহবিল — প্রতিষ্ঠানের সমান ভাগ, খাতায় পয়সা পর্যন্ত, পুরনো হার নীরবে নয় (a6, ২১ অক্টোবর ২০২৬; [[ProvidentFund]])।
 *
 * দাবি:
 *  · চালু — প্রতিজনের স্লিপে প্রতিষ্ঠানের ভাগ = মূল × ৮%, পয়সায় গোল; নিট বদলায় না; খাতায় ৫২২৫ ডেবিট = প্রতিষ্ঠানের ভাগের যোগ,
 *    ২১৩১ ক্রেডিট = কর্মীর ভাগ + প্রতিষ্ঠানের ভাগ, সব স্লিপের যোগ — পয়সা পর্যন্ত; দাখিলা মেলে।
 *  · বন্ধ (ডিফল্ট) — প্রতিষ্ঠানের সারি নেই, ৫২২৫-এ কিছু নয়, ২১৩১ = কেবল কর্মীর ভাগ।
 *  · সদস্য নন (PF খাত নেই) — চালু থাকলেও প্রতিষ্ঠানের ভাগ নেই।
 *  · প্রস্তাবিত বদল — আজকের ১০% বনাম প্রস্তাবিত ৮%, সদস্য নন এমন কেউ "বদলাবে" নন; কিছুই বসায় না।
 *  · তারিখ থেকে বসানো — আগের মাস ১০%, সেই মাস থেকে ৮%; অডিটে সারি; চাবি ছাড়া বসানো যায় না।
 *  · তহবিলের খাতা — রিপোর্ট আর কর্মীর পাতার জের একই, আর খাতার ২১৩১-এর সমান; চাবি ছাড়া রিপোর্ট ৪০৩, কর্মীর পাতায় তহবিল নেই।
 */
final class TheProvidentFundIsMatchedByTheEmployerTest extends TestCase
{
    use \Tests\Concerns\OpensTheYears;
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
        $this->openTheYearsOf('2026-06-30');
        $this->actingAs($this->owner);

        app(StandardChart::class)->install();
        app(MasterListService::class)->installDefaults();
        app(SalaryHeadService::class)->installDefaults();
        Employee::query()->delete();
    }

    public function test_with_the_fund_on_the_employer_share_is_on_the_slip_and_the_books_match_to_the_paisa(): void
    {
        $this->fundOn();
        $a = $this->employee('PF-A', '23456.78', '10');
        $b = $this->employee('PF-B', '17777.77', '7.5');

        $run = $this->confirmedPayroll($this->payroll()->build('2026-07-01'));

        $employer = $this->linesOf($run, PayslipLine::EMPLOYER);
        $this->assertCount(2, $employer, '⛔ প্রতিজনের প্রতিষ্ঠানের ভাগ একটা সারি হয়ে বসার কথা।');
        // ⓘ ২৩৪৫৬.৭৮ × ৮% = ১৮৭৬.৫৪২৪ → ১৮৭৬.৫৪; ১৭৭৭৭.৭৭ × ৮% = ১৪২২.২২১৬ → ১৪২২.২২
        $this->assertSame('1876.5400', $this->amountFor($employer, $a));
        $this->assertSame('1422.2200', $this->amountFor($employer, $b));

        // ⓘ নিট বদলায় না — কর্মীর ভাগ ২৩৪৫.৬৮ (১০%), ১৩৩৩.৩৩ (৭.৫% = ১৩৩৩.৩৩২৭৫)
        $slipA = $run->payslips()->where('employee_id', $a->id)->firstOrFail();
        $this->assertSame(0, bccomp((string) $slipA->deductions, '2345.68', 4));
        $this->assertSame(0, bccomp((string) $slipA->net, bcsub('23456.78', '2345.68', 2), 4));

        $employeeShares = $this->linesOf($run, SalaryHead::DEDUCTION)
            ->filter(fn (PayslipLine $l) => (int) $l->account_id === (int) StandardChart::find(StandardChart::PROVIDENT_FUND_PAYABLE)->id);
        $sumEmployee = $employeeShares->reduce(fn ($c, $l) => bcadd($c, (string) $l->amount, 4), '0');
        $sumEmployer = $employer->reduce(fn ($c, $l) => bcadd($c, (string) $l->amount, 4), '0');

        $this->assertSame(0, bccomp($this->posted($run, StandardChart::PF_EMPLOYER_EXPENSE, 'debit'), $sumEmployer, 4),
            '⛔ খরচের খাতে প্রতিষ্ঠানের ভাগের যোগ বসার কথা — পয়সা পর্যন্ত।');
        $this->assertSame(0, bccomp($this->posted($run, StandardChart::PROVIDENT_FUND_PAYABLE, 'credit'), bcadd($sumEmployee, $sumEmployer, 4), 4),
            '⛔ তহবিলের দায়ে দুই ভাগের যোগ বসার কথা — পয়সা পর্যন্ত।');
        $this->assertSame(0, bccomp($this->posted($run, StandardChart::SALARY_PAYABLE, 'credit'), (string) $run->fresh()->net_total, 4),
            '⛔ প্রদেয় বেতনে কেবল নিট — প্রতিষ্ঠানের ভাগ সেখানে নয়।');
        $this->assertBalanced($run);
    }

    public function test_with_the_fund_off_nothing_new_is_booked(): void
    {
        $this->employee('PF-A', '20000', '10');

        $run = $this->confirmedPayroll($this->payroll()->build('2026-07-01'));

        $this->assertCount(0, $this->linesOf($run, PayslipLine::EMPLOYER), '⛔ সুইচ বন্ধ, অথচ প্রতিষ্ঠানের ভাগ বসল।');
        $this->assertSame(0, bccomp($this->posted($run, StandardChart::PF_EMPLOYER_EXPENSE, 'debit'), '0', 4));
        $this->assertSame(0, bccomp($this->posted($run, StandardChart::PROVIDENT_FUND_PAYABLE, 'credit'), '2000', 4));
        $this->assertBalanced($run);
    }

    public function test_someone_outside_the_fund_gets_no_employer_share(): void
    {
        $this->fundOn();
        $outsider = $this->employee('PF-N', '20000', null);
        // ⓘ তহবিলের নয় এমন একটা কর্তন — "কোনো কর্তন আছে" আর "তহবিলে দিলেন" আলাদা প্রশ্ন
        $loan = app(SalaryHeadService::class)->create([
            'code' => 'LOAN', 'name_en' => 'Loan', 'kind' => SalaryHead::DEDUCTION, 'calculation' => SalaryHead::FIXED,
            'account_id' => StandardChart::find(StandardChart::SALARY_PAYABLE)->id, 'sort_order' => 30,
        ]);
        app(SalaryStructureService::class)->set($outsider, $loan, '2026-01-15', '500');

        $run = $this->payroll()->build('2026-07-01');

        $this->assertCount(0, $this->linesOf($run, PayslipLine::EMPLOYER), '⛔ তহবিলের সদস্য নন, অথচ প্রতিষ্ঠানের ভাগ বসল।');
    }

    public function test_the_proposed_change_lists_today_against_eight_percent_and_sets_nothing(): void
    {
        $member = $this->employee('PF-A', '20000', '10');
        $outsider = $this->employee('PF-N', '15000', null);
        $this->employee('PF-E', '18000', '8');
        $rows = collect(app(ProvidentFund::class)->proposal(Carbon::parse('2026-08-01')))->keyBy(fn ($r) => $r['employee']->code);

        $this->assertSame('2000.00', $rows['PF-A']['current']);
        $this->assertSame('1600.00', $rows['PF-A']['proposed']);
        $this->assertSame('1600.00', $rows['PF-A']['employer']);
        $this->assertTrue($rows['PF-A']['changes']);
        $this->assertFalse($rows['PF-N']['member']);
        $this->assertFalse($rows['PF-N']['changes'], '⛔ সদস্য নন এমন কাউকে তহবিলে ঢোকানোর প্রস্তাব।');
        $this->assertTrue($rows['PF-E']['member']);
        $this->assertFalse($rows['PF-E']['changes'], '⛔ হার আগে থেকেই ৮%, অথচ "বদলাবে"।');

        $this->assertSame(1, SalaryStructure::query()->where('employee_id', $member->id)->where('salary_head_id', $this->salaryHead('PF')->id)->count(),
            '⛔ প্রস্তাব দেখাতেই কাঠামো বদলাল।');
        $this->assertSame(0, SalaryStructure::query()->where('employee_id', $outsider->id)->where('salary_head_id', $this->salaryHead('PF')->id)->count());
    }

    public function test_applying_from_a_date_changes_that_month_on_and_leaves_the_month_before(): void
    {
        $this->fundOn();
        $member = $this->employee('PF-A', '20000', '10');
        $outsider = $this->employee('PF-N', '15000', null);

        $result = app(ProvidentFund::class)->apply(Carbon::parse('2026-08-01'), $this->owner);
        $this->assertSame(1, $result['applied']);

        $july = $this->payroll()->build('2026-07-01');
        $this->assertSame(0, bccomp((string) $july->payslips()->where('employee_id', $member->id)->value('deductions'), '2000', 4),
            '⛔ বসানোর তারিখের আগের মাসও বদলাল।');
        $this->payroll()->cancel($july, 'test');

        $august = $this->payroll()->build('2026-08-01');
        $this->assertSame(0, bccomp((string) $august->payslips()->where('employee_id', $member->id)->value('deductions'), '1600', 4),
            '⛔ বসানোর তারিখ থেকে ৮% হওয়ার কথা।');
        $this->assertSame(0, SalaryStructure::query()->where('employee_id', $outsider->id)->where('salary_head_id', $this->salaryHead('PF')->id)->count());

        $this->assertTrue(AuditTrail::query()->forRecord(Employee::class, (int) $member->id)
            ->where('action', ProvidentFund::RATE_APPLIED)->exists(), '⛔ হার বসল, অথচ অডিটে নেই।');
    }

    public function test_applying_needs_the_salary_change_key(): void
    {
        $this->employee('PF-A', '20000', '10');
        $clerk = $this->member(['hr.salary.view']);

        $this->expectException(AuthorizationException::class);
        app(ProvidentFund::class)->apply(Carbon::parse('2026-08-01'), $clerk);
    }

    public function test_the_fund_ledger_report_and_the_employee_page_agree_with_the_books(): void
    {
        $this->fundOn();
        $member = $this->employee('PF-A', '23456.78', '8');

        $july = $this->confirmedPayroll($this->payroll()->build('2026-07-01'));
        $august = $this->confirmedPayroll($this->payroll()->build('2026-08-01'));

        $ledger = app(ProvidentFund::class)->ledgerOf($member);
        $this->assertCount(2, $ledger);
        // ⓘ ১৮৭৬.৫৪ + ১৮৭৬.৫৪ প্রতি মাসে
        $this->assertSame(0, bccomp($ledger[0]['balance'], '3753.08', 4));
        $this->assertSame(0, bccomp($ledger[1]['balance'], '7506.16', 4));

        $books = bcadd($this->posted($july, StandardChart::PROVIDENT_FUND_PAYABLE, 'credit'), $this->posted($august, StandardChart::PROVIDENT_FUND_PAYABLE, 'credit'), 4);
        $this->assertSame(0, bccomp($ledger[1]['balance'], $books, 4), '⛔ তহবিলের খাতার জের খাতার ২১৩১-এর সাথে মেলে না।');

        $report = app(ReportEngine::class)->run('hr.provident_fund', ['from' => '2026-08-01', 'to' => '2026-08-31']);
        $this->assertCount(1, $report->rows);
        $row = (array) $report->rows[0];
        $this->assertSame(0, bccomp((string) $row['balance'], '7506.16', 4), '⛔ পরিসরের প্রথম সারির জেরে আগের মাসের জমা নেই।');
        $this->assertSame(0, bccomp((string) $row['employer_share'], '1876.54', 4));

        $first = (array) app(ReportEngine::class)->run('hr.provident_fund', ['from' => '2026-07-01', 'to' => '2026-07-31'])->rows[0];
        $this->assertSame(0, bccomp((string) $first['balance'], '3753.08', 4), '⛔ চলতি জের মাসের ক্রমে নয় — জুলাইয়ের জেরে আগস্টও ঢুকেছে।');

        $this->get(route('hr.employee.show', $member))->assertOk()->assertSee('data-pf-month="2026-08"', false);
    }

    public function test_without_the_salary_key_the_fund_stays_shut(): void
    {
        $this->fundOn();
        $member = $this->employee('PF-A', '20000', '8');
        $this->confirmedPayroll($this->payroll()->build('2026-07-01'));

        $clerk = $this->member(['hr.employee.view', 'hr.payroll.view']);
        $this->actingAs($clerk);

        $this->get(route('hr.report.show', 'provident-fund'))->assertForbidden();
        $this->get(route('hr.pf.proposal'))->assertForbidden();
        $this->get(route('hr.employee.show', $member))->assertOk()->assertDontSee('data-pf-month', false);
    }

    // ── সাহায্য ──────────────────────────────────────────────────────────

    private function payroll(): PayrollService
    {
        return app(PayrollService::class);
    }

    private function fundOn(): void
    {
        app(SettingsService::class)->set(ProvidentFund::ENABLED, true);
    }

    private function salaryHead(string $code): SalaryHead
    {
        return SalaryHead::query()->where('code', $code)->firstOrFail();
    }

    private function employee(string $code, string $basic, ?string $pf): Employee
    {
        $employee = app(EmployeeService::class)->create([
            'code' => $code, 'name_en' => 'Person '.$code, 'joining_date' => '2026-01-15', 'payment_method' => 'cash',
        ]);

        $salaries = app(SalaryStructureService::class);
        $salaries->set($employee, $this->salaryHead('BASIC'), '2026-01-15', $basic);

        if ($pf !== null) {
            $salaries->set($employee, $this->salaryHead('PF'), '2026-01-15', $pf);
        }

        return $employee->fresh();
    }

    /** @return \Illuminate\Support\Collection<int, PayslipLine> */
    private function linesOf(PayrollRun $run, string $kind)
    {
        return PayslipLine::query()->whereIn('payslip_id', $run->payslips()->select('id'))->where('kind', $kind)->get();
    }

    /** @param \Illuminate\Support\Collection<int, PayslipLine> $lines */
    private function amountFor($lines, Employee $employee): string
    {
        $line = $lines->first(fn (PayslipLine $l) => (int) $l->payslip->employee_id === (int) $employee->id);

        // ⓘ চার ঘরে — গোল না করা ১৮৭৬.৫৪২৪ এখানে ধরা পড়ে
        return bcadd((string) $line->amount, '0', 4);
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
        $debit = $rows->reduce(fn ($c, $e) => bcadd($c, (string) $e->debit, 4), '0');
        $credit = $rows->reduce(fn ($c, $e) => bcadd($c, (string) $e->credit, 4), '0');
        $this->assertSame(0, bccomp($debit, $credit, 4), '⛔ বেতনের দাখিলা মেলেনি।');
    }

    /** @param list<string> $keys */
    private function member(array $keys): User
    {
        foreach ($keys as $key) {
            Permission::findOrCreate($key, 'web');
        }

        $user = User::factory()->create(['current_company_id' => $this->company->id, 'is_active' => true]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);
        CompanyContext::forCompany($this->company->id, fn () => $user->givePermissionTo($keys));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }
}
