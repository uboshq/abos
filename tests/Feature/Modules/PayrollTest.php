<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
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
use Tests\TestCase;

/**
 * বেতনের রান — শিট, খাতা, কাগজ, ব্যাংক ফাইল।
 *
 * ── প্ল্যানের মাপকাঠি ────────────────────────────────────────────────
 * "বেতন খাতায় বসে, ব্যাংক ফাইল বের হয়।" এই দুইটাই এখানে যাচাই হয়,
 * আর তার সাথে সেই ভুলগুলো যা মাস পরে ধরা পড়ে: একই মাসে দুইবার বেতন,
 * নিশ্চিত করা রান নীরবে বদলে যাওয়া, আর বাতিল করার পরেও খাতায় অঙ্ক
 * থেকে যাওয়া।
 */
class PayrollTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->user);

        app(MasterListService::class)->installDefaults();
        app(SalaryHeadService::class)->installDefaults();
    }

    private function payroll(): PayrollService
    {
        return app(PayrollService::class);
    }

    private function headNamed(string $code): SalaryHead
    {
        return SalaryHead::query()->where('code', $code)->firstOrFail();
    }

    /**
     * একজন কর্মী, বেতন বসানো — ২০,০০০ মূল, ৫০% বাড়িভাড়া, ১০% PF।
     * মোট আয় ৩০,০০০, কর্তন ২,০০০, নিট ২৮,০০০।
     */
    private function employeeWithSalary(array $overrides = [], string $code = 'EMP-001'): Employee
    {
        $employee = app(EmployeeService::class)->create([
            'code' => $code,
            'name_en' => 'Rafiq Islam',
            'joining_date' => '2026-01-15',
            'payment_method' => 'bank',
            'bank_name' => 'Sonali',
            'bank_account_no' => '1234567890',
            'bank_account_name' => 'Rafiq Islam',
            ...$overrides,
        ]);

        $salaries = app(SalaryStructureService::class);
        $salaries->set($employee, $this->headNamed('BASIC'), '2026-01-15', '20000');
        $salaries->set($employee, $this->headNamed('HRA'), '2026-01-15', '50');
        $salaries->set($employee, $this->headNamed('PF'), '2026-01-15', '10');

        return $employee->fresh();
    }

    // ── শিট বানানো ────────────────────────────────────────────────────

    public function test_a_run_builds_a_payslip_for_everyone_on_the_payroll(): void
    {
        $this->employeeWithSalary();
        $this->employeeWithSalary(['name_en' => 'Karim Mia'], 'EMP-002');

        $run = $this->payroll()->build('2026-07-01');

        $this->assertSame(2, $run->employee_count);
        $this->assertSame(0, bccomp((string) $run->gross_total, '60000', 4));
        $this->assertSame(0, bccomp((string) $run->deduction_total, '4000', 4));
        $this->assertSame(0, bccomp((string) $run->net_total, '56000', 4));
        $this->assertSame(DocumentStatus::DRAFT, $run->status);
    }

    /**
     * শিটে অঙ্কগুলো কপি হয়ে বসে।
     *
     * ── কেন এটাই সবচেয়ে গুরুত্বপূর্ণ পরীক্ষা ───────────────────────
     * কাঠামো থেকে প্রতিবার হিসাব করলে আজ কেউ বেতন বাড়ালে গত মাসের
     * শিটটাও বেড়ে যেত — অথচ ব্যাংকে পুরনো টাকা গেছে। কাগজ আর ব্যাংক
     * আলাদা কথা বললে কোনটা সত্যি তা বলার উপায় থাকে না।
     */
    public function test_a_later_raise_does_not_change_a_slip_already_built(): void
    {
        $employee = $this->employeeWithSalary();

        $run = $this->payroll()->build('2026-07-01');

        app(SalaryStructureService::class)
            ->set($employee, $this->headNamed('BASIC'), '2026-07-01', '30000');

        $slip = $run->payslips()->first();

        $this->assertSame(0, bccomp((string) $slip->gross, '30000', 4),
            'শিট বানানোর দিনের অঙ্কেই থাকার কথা');
    }

    /** খসড়া আবার বানানো যায় — কাঠামো শুধরানোর পর। */
    public function test_a_draft_can_be_rebuilt_after_the_structure_is_corrected(): void
    {
        $employee = $this->employeeWithSalary();
        $run = $this->payroll()->build('2026-07-01');

        app(SalaryStructureService::class)
            ->set($employee, $this->headNamed('BASIC'), '2026-01-15', '25000');

        $run = $this->payroll()->rebuild($run);

        $this->assertSame(1, $run->employee_count);
        $this->assertSame(0, bccomp((string) $run->gross_total, '37500', 4));
    }

    public function test_one_month_cannot_be_run_twice(): void
    {
        $this->employeeWithSalary();
        $this->payroll()->build('2026-07-01');

        $this->expectException(ValidationException::class);

        $this->payroll()->build('2026-07-15');
    }

    /** বাতিল করা রান পথ আটকায় না — নাহলে ভুল রান শুধরানোর উপায় থাকত না। */
    public function test_a_cancelled_run_does_not_block_the_month(): void
    {
        $this->employeeWithSalary();
        $run = $this->payroll()->build('2026-07-01');
        $this->payroll()->cancel($run, 'ভুল মাস');

        $again = $this->payroll()->build('2026-07-01');

        $this->assertSame(DocumentStatus::DRAFT, $again->status);
    }

    public function test_a_month_with_nobody_employed_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->payroll()->build('2026-07-01');
    }

    /** যোগ দেওয়ার আগের মাসে কেউ বেতন পায় না। */
    public function test_someone_who_had_not_joined_yet_is_not_on_the_run(): void
    {
        $this->employeeWithSalary(['joining_date' => '2026-08-01']);

        $this->expectException(ValidationException::class);

        $this->payroll()->build('2026-07-01');
    }

    // ── খাতায় বসা ─────────────────────────────────────────────────────

    /**
     * নিশ্চিত করলে বেতন খাতায় বসে, আর দুই দিক মেলে।
     *
     * আয় খরচে ডেবিট, কর্তন ও নিট দায়ে ক্রেডিট — যোগফল সমান।
     */
    public function test_confirming_puts_the_salary_into_the_books(): void
    {
        $this->employeeWithSalary();
        $run = $this->payroll()->confirm($this->payroll()->build('2026-07-01'));

        $this->assertSame(DocumentStatus::CONFIRMED, $run->status);

        $entries = LedgerEntry::query()
            ->where('source_type', PayrollRun::SOURCE_TYPE)
            ->where('source_id', $run->id)
            ->get();

        $this->assertTrue($entries->isNotEmpty(), 'খাতায় কিছুই বসেনি');

        $debit = $entries->reduce(fn ($c, $e) => bcadd($c, (string) $e->debit, 4), '0');
        $credit = $entries->reduce(fn ($c, $e) => bcadd($c, (string) $e->credit, 4), '0');

        $this->assertSame(0, bccomp($debit, $credit, 4), 'ডেবিট ও ক্রেডিট মেলেনি');
        $this->assertSame(0, bccomp($debit, '30000', 4), 'মোট আয়টাই খরচে বসার কথা');
    }

    /** খরচ যায় বেতনের খাতে, নিট যায় প্রদেয় বেতনে, PF যায় নিজের খাতে। */
    public function test_each_amount_lands_in_the_account_it_belongs_to(): void
    {
        $this->employeeWithSalary();
        $run = $this->payroll()->confirm($this->payroll()->build('2026-07-01'));

        $byCode = LedgerEntry::query()
            ->where('source_type', PayrollRun::SOURCE_TYPE)
            ->where('source_id', $run->id)
            ->get()
            ->mapWithKeys(fn ($e) => [
                Account::query()->whereKey($e->account_id)->value('code') => ['debit' => (string) $e->debit, 'credit' => (string) $e->credit],
            ]);

        $this->assertSame(0, bccomp($byCode[StandardChart::SALARY_EXPENSE]['debit'], '30000', 4));
        $this->assertSame(0, bccomp($byCode[StandardChart::SALARY_PAYABLE]['credit'], '28000', 4));
        $this->assertSame(0, bccomp($byCode[StandardChart::PROVIDENT_FUND_PAYABLE]['credit'], '2000', 4),
            'ভবিষ্য তহবিল নিজের খাতে বসার কথা, প্রদেয় বেতনে নয়');
    }

    public function test_a_confirmed_run_cannot_be_rebuilt(): void
    {
        $this->employeeWithSalary();
        $run = $this->payroll()->confirm($this->payroll()->build('2026-07-01'));

        $this->expectException(ValidationException::class);

        $this->payroll()->rebuild($run);
    }

    /**
     * বাতিল করলে খাতার অঙ্ক বিপরীত এন্ট্রিতে ফেরে, মুছে যায় না।
     *
     * মুছে দিলে ট্রায়াল ব্যালেন্স মিললেও "কী হয়েছিল" প্রশ্নের উত্তর
     * হারাত, আর নিরীক্ষায় একটা ব্যাখ্যাহীন ফাঁক থাকত।
     */
    public function test_cancelling_reverses_the_books_instead_of_erasing_them(): void
    {
        $this->employeeWithSalary();
        $run = $this->payroll()->confirm($this->payroll()->build('2026-07-01'));

        $this->payroll()->cancel($run, 'ভুল অঙ্ক');

        $original = LedgerEntry::query()
            ->where('source_type', PayrollRun::SOURCE_TYPE)->where('source_id', $run->id)->count();

        $reversal = LedgerEntry::query()
            ->where('source_type', PayrollRun::SOURCE_TYPE.':reversal')->where('source_id', $run->id)->count();

        $this->assertGreaterThan(0, $original, 'মূল এন্ট্রিগুলো থেকে যাওয়ার কথা');
        $this->assertSame($original, $reversal, 'প্রতিটার বিপরীত এন্ট্রি হওয়ার কথা');

        $net = LedgerEntry::query()
            ->whereIn('source_type', [PayrollRun::SOURCE_TYPE, PayrollRun::SOURCE_TYPE.':reversal'])
            ->where('source_id', $run->id)
            ->selectRaw('SUM(debit) - SUM(credit) as n')
            ->value('n');

        $this->assertSame(0, bccomp((string) $net, '0', 4), 'বইয়ে মোট প্রভাব শূন্য হওয়ার কথা');
    }

    // ── ব্যাংক ফাইল ───────────────────────────────────────────────────

    /**
     * ফাইলে কেবল ব্যাংকের সারি।
     *
     * নগদ ও MFS-এর সারি থাকলে ব্যাংক পুরো ফাইলটাই প্রত্যাখ্যান করত,
     * আর একজনের কারণে সবার বেতন আটকে যেত।
     */
    public function test_the_bank_file_carries_only_the_bank_rows(): void
    {
        $this->employeeWithSalary();
        $this->employeeWithSalary(['payment_method' => 'cash', 'bank_account_no' => null,
            'name_en' => 'Cash Man'], 'EMP-002');

        $run = $this->payroll()->confirm($this->payroll()->build('2026-07-01'));
        $file = $this->payroll()->bankFile($run);

        $this->assertSame(1, $file['rows'], 'নগদের সারিটা ফাইলে থাকার কথা নয়');
        $this->assertStringContainsString('1234567890', $file['content']);
        $this->assertStringContainsString('28000.00', $file['content']);
        $this->assertStringNotContainsString('Cash Man', $file['content']);
        $this->assertStringEndsWith('.csv', $file['name']);
    }

    /** খসড়া রানের ব্যাংক ফাইল নামানো যায় না। */
    public function test_a_draft_run_has_no_bank_file(): void
    {
        $this->employeeWithSalary();
        $run = $this->payroll()->build('2026-07-01');

        $this->get(route('hr.payroll.bank_file', $run))->assertNotFound();
    }

    // ── পর্দা ও কাগজ ──────────────────────────────────────────────────

    public function test_the_screens_and_the_payslips_open(): void
    {
        $this->employeeWithSalary();

        $this->get(route('hr.payroll.index'))->assertOk();
        $this->get(route('hr.payroll.create'))->assertOk();

        $run = $this->payroll()->build('2026-07-01');

        $this->get(route('hr.payroll.show', $run))->assertOk()->assertSee($run->document_no);

        // খসড়া অবস্থাতেও শিট ছাপা যায় — কিন্তু কাগজে "খসড়া" লেখা থাকে
        $this->get(route('hr.payroll.payslips', $run))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->get(route('hr.payslip.print', $run->payslips()->first()))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_the_whole_month_runs_through_the_screens(): void
    {
        $this->employeeWithSalary();

        $this->post(route('hr.payroll.store'), ['month' => '2026-07'])->assertRedirect();

        $run = PayrollRun::query()->latest('id')->firstOrFail();

        $this->post(route('hr.payroll.confirm', $run))->assertRedirect();

        $this->assertSame(DocumentStatus::CONFIRMED, $run->fresh()->status);

        $this->get(route('hr.payroll.bank_file', $run))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    }

    /**
     * বেতনের রান চালানোর অনুমতি আলাদা।
     *
     * মাসের শেষে কে কত পাচ্ছে তা অনেকেরই দেখা দরকার, কিন্তু খাতায়
     * বসানো ও ব্যাংকে ফাইল পাঠানো একজনের কাজ।
     */
    public function test_running_the_payroll_needs_its_own_permission(): void
    {
        $this->employeeWithSalary();
        $run = $this->payroll()->build('2026-07-01');

        $viewer = User::factory()->create();
        $viewer->companies()->attach($this->company->id);
        $viewer->givePermissionTo('hr.payroll.view');

        $this->actingAs($viewer)->get(route('hr.payroll.show', $run))->assertOk();
        $this->actingAs($viewer)->post(route('hr.payroll.confirm', $run))->assertForbidden();
    }

    /** বেতনশিটের অঙ্ক শিটের সারি থেকেই আসে — যোগফল মিলতে হবে। */
    public function test_a_slip_adds_up_to_what_its_lines_say(): void
    {
        $this->employeeWithSalary();
        $run = $this->payroll()->build('2026-07-01');

        $slip = $run->payslips()->with('lines')->first();

        $earnings = $slip->lines->where('kind', SalaryHead::EARNING)
            ->reduce(fn ($c, $l) => bcadd($c, (string) $l->amount, 4), '0');
        $deductions = $slip->lines->where('kind', SalaryHead::DEDUCTION)
            ->reduce(fn ($c, $l) => bcadd($c, (string) $l->amount, 4), '0');

        $this->assertSame(0, bccomp($earnings, (string) $slip->gross, 4));
        $this->assertSame(0, bccomp($deductions, (string) $slip->deductions, 4));
        $this->assertSame(0, bccomp(bcsub($earnings, $deductions, 4), (string) $slip->net, 4));
    }

    /**
     * ছাড়ার মাসেও বেতন হয়, পরের মাসে নয়।
     *
     * ওই মাসের কিছু দিন সে কাজ করেছে। "নিষ্ক্রিয় মানেই বাদ" ধরলে শেষ
     * মাসের বেতনটাই কেউ পেত না।
     */
    public function test_the_month_someone_leaves_still_gets_paid(): void
    {
        $employee = $this->employeeWithSalary();
        app(EmployeeService::class)->endEmployment($employee, '2026-07-10');

        $july = $this->payroll()->build('2026-07-01');
        $this->assertSame(1, $july->employee_count, 'ছাড়ার মাসে বেতন হওয়ার কথা');

        $this->expectException(ValidationException::class);
        $this->payroll()->build('2026-08-01');
    }
}
