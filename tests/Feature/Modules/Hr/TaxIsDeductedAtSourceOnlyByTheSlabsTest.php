<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Security\FieldSecurity;
use App\Core\Services\DataScope;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\PayslipLine;
use App\Modules\Hr\Models\SalaryHead;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\PayrollService;
use App\Modules\Hr\Services\SalaryHeadService;
use App\Modules\Hr\Services\SalaryStructureService;
use App\Modules\Hr\Services\SalaryTax;
use App\Modules\MasterData\Services\MasterListService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\View;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\SignsTheSecondHand;
use Tests\TestCase;

/**
 * ⭐ বেতন থেকে উৎসে কর — ধাপ না থাকলে শূন্য আর নোট, থাকলে পয়সা পর্যন্ত মাসিক ভাগ, সনদের যোগ = স্লিপের যোগ, e-TIN ঢাকা
 * (আয়কর আইন ২০২৩ ধারা ৮৬; a6, ২১ অক্টোবর ২০২৬; [[SalaryTax]])।
 *
 * দাবি:
 *  · ধাপ খালি (ডিফল্ট) — কোনো TDS সারি নেই, রানের পাতায় "করের ধাপ বসানো নেই"।
 *  · ধাপ বসানো — মূল ৬১২৩৪.৫৬ + বাড়িভাড়া ৫০%, আর বছরের দুই উৎসব ভাতা (২ × মূল; fe দফা ২): বার্ষিক ১২২৪৬৯১.২০, ছাড়
 *    এক-তৃতীয়াংশ ৪০৮২৩০.৪০, করযোগ্য ৮১৬৪৬০.৮০, কর ০ + ৫০০০ + ৩৬৬৪৬.০৮ = ৪১৬৪৬.০৮, মাসিক ৩৪৭০.৫১; বড় বেতনে ছাড় সীমায়
 *    আটকায় (৪৫০০০০) — মাসিক ৫৩৭৫০.০০।
 *  · খাতায় ২১৩২ ক্রেডিট = TDS সারির যোগ, পয়সা পর্যন্ত; দাখিলা মেলে।
 *  · বছরের সনদ — মাসে মাসে, যোগ = নিশ্চিত স্লিপগুলোর মোট আয় আর TDS-এর যোগ; ছাপা খোলে।
 *  · e-TIN — পরিচয়ের চাবি ছাড়া কর্মীর পাতা আর সনদে ঢাকা; চাবি থাকলে খোলা।
 */
final class TaxIsDeductedAtSourceOnlyByTheSlabsTest extends TestCase
{
    use RefreshDatabase;
    // ⛔ বেতনের রান দ্বিতীয় সই ছাড়া খাতায় বসে না, সব ছক বন্ধ থাকলেও — সই-শাখার নিয়ম, মেশানোর পরে এখানেও (a6, ২১ অক্টোবর ২০২৬; [[ApprovalRules::secondHand()]])
    use SignsTheSecondHand;

    private const SLABS = '350000:0, 100000:5, 400000:10, 500000:15, 500000:20, 2000000:25, *:30';

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

    public function test_without_slabs_nothing_is_deducted_and_the_run_says_so(): void
    {
        $this->employee('TX-A', '61234.56');

        $run = $this->payroll()->build('2026-07-01');

        $this->assertSame(0, $this->tdsLines($run)->count(), '⛔ ধাপ বসানো নেই, অথচ কর কাটা হলো।');
        $this->get(route('hr.payroll.show', $run))->assertOk()
            ->assertSee('data-tax-note', false)
            ->assertSee(__('hr::message.tax_slabs_missing'));
    }

    public function test_with_slabs_the_monthly_figure_is_exact_and_the_cap_holds(): void
    {
        $this->slabsOn();
        $a = $this->employee('TX-A', '61234.56');
        $b = $this->employee('TX-B', '200000');

        $estimate = app(SalaryTax::class)->estimate($a, Carbon::parse('2026-07-31'));
        $this->assertSame(0, bccomp($estimate['annual'], '1224691.20', 4));
        $this->assertSame(0, bccomp($estimate['exempt'], '408230.40', 4));
        $this->assertSame(0, bccomp($estimate['taxable'], '816460.80', 4));
        $this->assertSame(0, bccomp($estimate['tax'], '41646.08', 4));

        $run = $this->payroll()->build('2026-07-01');

        $this->assertSame('3470.5100', $this->tdsOf($run, $a), '⛔ মাসিক কর ৪১৬৪৬.০৮ ÷ ১২ = ৩৪৭০.৫১ হওয়ার কথা (উৎসব ভাতাসহ)।');
        $this->assertSame('53750.0000', $this->tdsOf($run, $b), '⛔ বড় বেতনে ছাড় ৪৫০০০০-এ আটকানোর কথা — মাসিক ৫৩৭৫০.০০।');

        $slip = $run->payslips()->where('employee_id', $a->id)->firstOrFail();
        $this->assertSame(0, bccomp((string) $slip->net, bcsub((string) $slip->gross, (string) $slip->deductions, 4), 4));
        $this->get(route('hr.payroll.show', $run))->assertOk()->assertDontSee('data-tax-note', false);
    }

    public function test_the_tax_payable_in_the_books_equals_the_payslips_to_the_paisa(): void
    {
        $this->slabsOn();
        $this->employee('TX-A', '61234.56');
        $this->employee('TX-B', '200000');

        $run = $this->confirmedPayroll($this->payroll()->build('2026-07-01'));

        $sum = $this->tdsLines($run)->reduce(fn ($c, $l) => bcadd($c, (string) $l->amount, 4), '0');
        $this->assertSame(0, bccomp($this->posted($run, StandardChart::TDS_PAYABLE, 'credit'), $sum, 4),
            '⛔ খাতার উৎসে কর আর বেতনের স্লিপের কর আলাদা।');
        $this->assertSame(0, bccomp($sum, '57220.51', 4));

        $rows = LedgerEntry::query()->where('source_type', PayrollRun::SOURCE_TYPE)->where('source_id', $run->id)->get();
        $this->assertSame(0, bccomp($rows->reduce(fn ($c, $e) => bcadd($c, (string) $e->debit, 4), '0'),
            $rows->reduce(fn ($c, $e) => bcadd($c, (string) $e->credit, 4), '0'), 4), '⛔ বেতনের দাখিলা মেলেনি।');
    }

    public function test_the_years_certificate_adds_up_to_the_payslips(): void
    {
        $this->slabsOn();
        $a = $this->employee('TX-A', '61234.56');

        $july = $this->confirmedPayroll($this->payroll()->build('2026-07-01'));
        $august = $this->confirmedPayroll($this->payroll()->build('2026-08-01'));
        // ⓘ জুন আগের আয়ের বছরের — সনদে আসে না
        $june = $this->confirmedPayroll($this->payroll()->build('2026-06-01'));

        $certificate = app(SalaryTax::class)->certificate($a, 2026);

        $this->assertCount(2, $certificate['rows'], '⛔ আয়ের বছর জুলাই থেকে জুন — জুনের রান ২০২৫-২৬-এর।');
        $slips = collect([$july, $august])->map(fn (PayrollRun $r) => $r->payslips()->where('employee_id', $a->id)->firstOrFail());
        $this->assertSame(0, bccomp($certificate['gross'], $slips->reduce(fn ($c, $s) => bcadd($c, (string) $s->gross, 4), '0'), 4));
        $this->assertSame(0, bccomp($certificate['tds'], '6941.02', 4), '⛔ সনদের কর দুই মাসের স্লিপের যোগ নয়।');
        $this->assertNotNull($june);

        $document = $this->printed($this->owner, route('hr.employee.tax_certificate', ['employee' => $a, 'year' => 2026]));
        $this->assertContains(\App\Core\Support\Money::format('6941.02'), $document->totals);
    }

    public function test_the_e_tin_is_masked_without_the_identity_key(): void
    {
        $a = $this->employee('TX-A', '30000', ['e_tin' => '123456789012']);
        $clerk = $this->member(['hr.employee.view', 'hr.salary.view']);

        $this->actingAs($clerk)->get(route('hr.employee.show', $a))->assertOk()->assertDontSee('123456789012');
        $document = $this->printed($clerk, route('hr.employee.tax_certificate', $a));
        $this->assertSame(FieldSecurity::mask(), $document->meta[__('hr::field.e_tin')] ?? null, '⛔ চাবি ছাড়া সনদে e-TIN খোলা।');

        $boss = $this->member(['hr.employee.view', 'hr.salary.view', 'hr.identity.view']);
        $this->actingAs($boss)->get(route('hr.employee.show', $a))->assertOk()->assertSee('123456789012');
    }

    // ── সাহায্য ──────────────────────────────────────────────────────────

    private function payroll(): PayrollService
    {
        return app(PayrollService::class);
    }

    private function slabsOn(): void
    {
        app(SettingsService::class)->set(SalaryTax::SLABS, self::SLABS);
    }

    /** কর্মী — মূল আর ৫০% বাড়িভাড়া */
    private function employee(string $code, string $basic, array $extra = []): Employee
    {
        $employee = app(EmployeeService::class)->create([
            'code' => $code, 'name_en' => 'Taxpayer '.$code, 'joining_date' => '2026-01-15', 'payment_method' => 'cash', ...$extra,
        ]);
        $salaries = app(SalaryStructureService::class);
        $salaries->set($employee, SalaryHead::query()->where('code', 'BASIC')->firstOrFail(), '2026-01-15', $basic);
        $salaries->set($employee, SalaryHead::query()->where('code', 'HRA')->firstOrFail(), '2026-01-15', '50');
        // ⓘ আরেকটা কর্তন — সনদ কেবল উৎসে কর গোনে, বাকি কর্তন নয়; আর কর্তন করের হিসাবে ঢোকে না
        $salaries->set($employee, SalaryHead::query()->where('code', 'PF')->firstOrFail(), '2026-01-15', '10');

        return $employee->fresh();
    }

    /** @return \Illuminate\Support\Collection<int, PayslipLine> */
    private function tdsLines(PayrollRun $run)
    {
        return PayslipLine::query()->whereIn('payslip_id', $run->payslips()->select('id'))->where('head_code', SalaryTax::LINE_CODE)->get();
    }

    private function tdsOf(PayrollRun $run, Employee $employee): string
    {
        $line = PayslipLine::query()
            ->whereIn('payslip_id', $run->payslips()->where('employee_id', $employee->id)->select('id'))
            ->where('head_code', SalaryTax::LINE_CODE)->firstOrFail();

        return bcadd((string) $line->amount, '0', 4);
    }

    private function posted(PayrollRun $run, string $code, string $side): string
    {
        return bcadd((string) LedgerEntry::query()
            ->where('source_type', PayrollRun::SOURCE_TYPE)->where('source_id', $run->id)
            ->where('account_id', Account::query()->where('code', $code)->value('id'))
            ->sum($side), '0', 4);
    }

    private function printed(User $user, string $url): \App\Core\Engines\Print\PrintableDocument
    {
        $documents = null;
        View::composer('*', function ($view) use (&$documents): void {
            $data = $view->getData();

            if ($documents === null && isset($data['documents']) && is_array($data['documents'])) {
                $documents = $data['documents'];
            }
        });

        $this->app['auth']->forgetGuards();
        app(DataScope::class)->forget();
        $this->actingAs($user->fresh())->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertIsArray($documents);

        return array_values($documents)[0];
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
        FieldSecurity::forget();

        return $user->fresh();
    }
}
