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
use App\Modules\Hr\Models\GratuityProvision;
use App\Modules\Hr\Models\SalaryHead;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\Gratuity;
use App\Modules\Hr\Services\PayrollService;
use App\Modules\Hr\Services\SalaryHeadService;
use App\Modules\Hr\Services\SalaryStructureService;
use App\Modules\MasterData\Services\MasterListService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\SignsTheSecondHand;
use Tests\TestCase;

/**
 * ⭐ গ্র্যাচুইটি — দায় পয়সা পর্যন্ত, আর মাস-শেষে ২১৩৪-এর জের = হিসাব করা দায় (BLA ধারা ২(১০), IAS 19; fe, ২১ অক্টোবর ২০২৬, দফা ৮;
 * [[Gratuity]])।
 *
 * দাবি (৩১ জুলাই ২০২৬):
 *  · ক — ২০১৫-০১-০১, ১১ বছর ৬ মাস ৩০ দিন → ১২ বছর (ছয় মাসের বেশি), দশের বেশি তাই ৪৫ দিন × ১২ = ৫৪০ দিন, মূল ২৩৪৫৬.৭৮ → ৪২২২২২.০৪।
 *  · খ — ২০২৩-০৩-১৫, ৩ বছর ৪.৫ মাস → ৩ বছর, ৯০ দিন, মূল ৩০০০০.০১ → ৯০০০০.০৩।
 *  · গ — ২০১৬-০৭-০১, ঠিক ১০ বছর ১ মাস → ১০ বছর, দশের বেশি নয় তাই ৩০০ দিন, মূল ২০০০০ → ২০০০০০.০০।
 *  · ঘ — ২০২৬-০৩-০১ → ০।
 *  · জুলাই — ৫২২৭ ডেবিট = ২১৩৪ জের = ৭১২২২২.০৭; আগস্টে খ-এর মূল ৩৫০০০ → ১৪৯৯৯.৯৭ বসে, জের ৭২৭২২২.০৪; সেপ্টেম্বরে গ চলে গেলেন →
 *    −২০০০০০ (২১৩৪ ডেবিট), জের ৫২৭২২২.০৪; একই মাস আবার — কিছুই নয়।
 *  · বেতন নিশ্চিত হলে সুইচ চালু থাকলে সঞ্চিতি বসে, বন্ধ থাকলে নয়; খাত না থাকলে থামে; রিপোর্টে যোগ, চাবি ছাড়া ৪০৩।
 */
final class GratuityIsBookedToTheObligationTest extends TestCase
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

    public function test_each_obligation_is_exact_to_the_paisa(): void
    {
        [$a, $b, $c, $d] = $this->staff();
        $g = app(Gratuity::class);
        $on = Carbon::parse('2026-07-31');

        $this->assertSame(['years' => 12, 'days' => 540, 'obligation' => '422222.04'], $this->pick($g->obligation($a, $on)));
        $this->assertSame(['years' => 3, 'days' => 90, 'obligation' => '90000.03'], $this->pick($g->obligation($b, $on)));
        $this->assertSame(['years' => 10, 'days' => 300, 'obligation' => '200000.00'], $this->pick($g->obligation($c, $on)));
        $this->assertSame(['years' => 0, 'days' => 0, 'obligation' => '0.00'], $this->pick($g->obligation($d, $on)));
    }

    public function test_the_liability_equals_the_obligation_at_every_month_end(): void
    {
        [, $b, $c] = $this->staff();
        $g = app(Gratuity::class);

        $g->accrue(Carbon::parse('2026-07-01'));
        $this->assertSame(0, bccomp($this->balance(StandardChart::GRATUITY_EXPENSE), '712222.07', 4), '⛔ জুলাইয়ের খরচ দায়ের সমান নয়।');
        $this->assertSame(0, bccomp($this->liability(), '712222.07', 4));
        $this->assertSame(0, bccomp($this->liability(), $this->obligationOn('2026-07-31'), 4));

        app(SalaryStructureService::class)->set($b, $this->head('BASIC'), '2026-08-01', '35000');
        $made = $g->accrue(Carbon::parse('2026-08-01'));
        $this->assertSame(0, bccomp(collect($made)->reduce(fn ($s, $p) => bcadd($s, (string) $p->amount, 4), '0'), '14999.97', 4));
        $this->assertSame(0, bccomp($this->liability(), '727222.04', 4), '⛔ আগস্টে বেতন বাড়ার পরে দায় আর খাতা মেলে না।');

        $c->forceFill(['leaving_date' => '2026-09-15'])->save();
        $g->accrue(Carbon::parse('2026-09-01'));
        $this->assertSame(0, bccomp($this->liability(), '527222.04', 4), '⛔ চলে যাওয়ার পরে দায় কমেনি।');
        $this->assertSame(0, bccomp($this->liability(), $this->obligationOn('2026-09-30'), 4));

        $rows = GratuityProvision::query()->count();
        $this->assertSame([], $g->accrue(Carbon::parse('2026-09-01')), '⛔ একই মাস দুইবার বসল।');
        $this->assertSame($rows, GratuityProvision::query()->count());
    }

    public function test_confirming_a_salary_month_books_it_only_when_switched_on(): void
    {
        $this->staff();
        $payroll = app(PayrollService::class);

        $this->confirmedPayroll($payroll->build('2026-06-01'));
        $this->assertSame(0, GratuityProvision::query()->count(), '⛔ সুইচ বন্ধ, অথচ সঞ্চিতি বসল।');

        app(SettingsService::class)->set(Gratuity::ENABLED, true);
        $this->confirmedPayroll($payroll->build('2026-07-01'));
        $this->assertSame(0, bccomp($this->liability(), $this->obligationOn('2026-07-31'), 4), '⛔ বেতন নিশ্চিত হলো, সঞ্চিতি বসেনি।');
    }

    public function test_without_the_accounts_it_stops_and_posts_nothing(): void
    {
        $this->staff();
        DB::table('accounts')->where('company_id', $this->company->id)->where('code', StandardChart::GRATUITY_LIABILITY)->delete();

        try {
            app(Gratuity::class)->accrue(Carbon::parse('2026-07-01'));
            $this->fail('⛔ খাত নেই, অথচ সঞ্চিতি বসল।');
        } catch (ValidationException $e) {
            $this->assertContains(__('hr::validation.gratuity_accounts_missing'), collect($e->errors())->flatten()->all());
        }

        $this->assertSame(0, GratuityProvision::query()->count());
        $this->assertSame(0, LedgerEntry::query()->where('source_type', Gratuity::SOURCE_TYPE)->count());
    }

    public function test_the_report_totals_and_needs_the_salary_key(): void
    {
        $this->staff();

        $this->get(route('hr.gratuity.report', ['on' => '2026-07-31']))->assertOk()
            ->assertSee(\App\Core\Support\Money::format('712222.07'));

        Permission::findOrCreate('hr.employee.view', 'web');
        $clerk = User::factory()->create(['current_company_id' => $this->company->id, 'is_active' => true]);
        $clerk->companies()->attach($this->company->id, ['is_active' => true]);
        CompanyContext::forCompany($this->company->id, fn () => $clerk->givePermissionTo('hr.employee.view'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($clerk->fresh())->get(route('hr.gratuity.report'))->assertForbidden();
    }

    // ── সাহায্য ──────────────────────────────────────────────────────────

    /** @return list<Employee> ক, খ, গ, ঘ */
    private function staff(): array
    {
        return [
            $this->employee('GR-A', '2015-01-01', '23456.78'),
            $this->employee('GR-B', '2023-03-15', '30000.01'),
            $this->employee('GR-C', '2016-07-01', '20000'),
            $this->employee('GR-D', '2026-03-01', '18000'),
        ];
    }

    private function employee(string $code, string $joined, string $basic): Employee
    {
        $employee = app(EmployeeService::class)->create([
            'code' => $code, 'name_en' => 'Person '.$code, 'joining_date' => $joined, 'payment_method' => 'cash',
        ]);
        app(SalaryStructureService::class)->set($employee, $this->head('BASIC'), $joined, $basic);

        return $employee->fresh();
    }

    private function head(string $code): SalaryHead
    {
        return SalaryHead::query()->where('code', $code)->firstOrFail();
    }

    /** @param array<string, mixed> $row */
    private function pick(array $row): array
    {
        return ['years' => $row['years'], 'days' => $row['days'], 'obligation' => $row['obligation']];
    }

    private function obligationOn(string $date): string
    {
        return collect(app(Gratuity::class)->rows(Carbon::parse($date)))->reduce(fn ($c, $r) => bcadd($c, (string) $r['obligation'], 4), '0');
    }

    /** ⓘ ২১৩৪-এর জের — ক্রেডিট − ডেবিট */
    private function liability(): string
    {
        $id = Account::query()->where('code', StandardChart::GRATUITY_LIABILITY)->value('id');

        return bcsub(bcadd((string) LedgerEntry::query()->where('account_id', $id)->sum('credit'), '0', 4),
            bcadd((string) LedgerEntry::query()->where('account_id', $id)->sum('debit'), '0', 4), 4);
    }

    /** ⓘ খরচের খাতের জের — ডেবিট − ক্রেডিট */
    private function balance(string $code): string
    {
        $id = Account::query()->where('code', $code)->value('id');

        return bcsub(bcadd((string) LedgerEntry::query()->where('account_id', $id)->sum('debit'), '0', 4),
            bcadd((string) LedgerEntry::query()->where('account_id', $id)->sum('credit'), '0', 4), 4);
    }
}
