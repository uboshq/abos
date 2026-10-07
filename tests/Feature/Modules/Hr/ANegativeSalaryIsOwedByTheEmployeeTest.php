<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\SalaryHead;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\PayrollService;
use App\Modules\Hr\Services\SalaryHeadService;
use App\Modules\Hr\Services\SalaryStructureService;
use App\Modules\Hr\Support\AdvanceBalance;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ⛔ ঋণাত্মক নিট বেতন সবার মোটে কাটাকাটি হয়ে হারাত — পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬ (HR ⚠️৭)।
 *
 * ⓘ কর্তন বেতনের বেশি হলে শিটের নিট ঋণাত্মক। [[PayrollService::ledgerLines()]] সবার নিট যোগ করে প্রদেয় বেতনে বসাত — একজনের
 * ঋণাত্মক অঙ্ক অন্যদের দেনা কমাত, আর কর্মীর কাছে পাওনা খাতায় কোথাও থাকত না (IAS 1: অফসেট নয়)। এখন সেটা ১১৩১-এ তাঁর নামে।
 */
final class ANegativeSalaryIsOwedByTheEmployeeTest extends TestCase
{
    use RefreshDatabase;

    private Employee $short;

    private Employee $clean;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        app(SalaryHeadService::class)->installDefaults();

        // ⓘ নিজের হিসাব-খাত ছাড়া কর্তন — ফলব্যাকে প্রদেয় বেতনে যায়
        SalaryHead::create([
            'code' => 'FINE', 'name_en' => 'Fine', 'name_bn' => 'জরিমানা', 'kind' => SalaryHead::DEDUCTION,
            'calculation' => SalaryHead::FIXED, 'is_basic' => false, 'prorated_by_attendance' => false,
            'account_id' => null, 'sort_order' => 90, 'is_active' => true,
        ]);

        $basic = SalaryHead::query()->where('code', 'BASIC')->firstOrFail();

        $this->short = app(EmployeeService::class)->create(['code' => 'EMP-NEG', 'name_en' => 'Over Deducted', 'joining_date' => '2026-01-15', 'payment_method' => 'cash']);
        app(SalaryStructureService::class)->set($this->short, $basic, '2026-01-15', '20000');

        $this->clean = app(EmployeeService::class)->create(['code' => 'EMP-POS', 'name_en' => 'Paid In Full', 'joining_date' => '2026-01-15', 'payment_method' => 'cash']);
        app(SalaryStructureService::class)->set($this->clean, $basic, '2026-01-15', '20000');
    }

    public function test_the_shortfall_sits_in_the_employees_name_and_does_not_eat_the_others_salary(): void
    {
        app(SalaryStructureService::class)->set($this->short, SalaryHead::query()->where('code', 'FINE')->firstOrFail(), '2026-01-15', '25000');

        $run = app(PayrollService::class)->confirm(app(PayrollService::class)->build('2026-08-01'));

        $slip = $run->payslips()->where('employee_id', $this->short->id)->firstOrFail();
        $this->assertSame('-5000.00', bcadd((string) $slip->net, '0', 2), 'প্রস্তুতিটাই ভুল — নিট ঋণাত্মক হওয়ার কথা');

        $advance = (int) StandardChart::find(StandardChart::EMPLOYEE_ADVANCE)->id;
        $named = LedgerEntry::query()->where('source_type', PayrollRun::SOURCE_TYPE)->where('source_id', $run->id)
            ->where('account_id', $advance)->where('party_type', Employee::drillSourceType())->where('party_id', $this->short->id);

        $this->assertSame('5000.00', bcadd((string) (clone $named)->sum('debit'), '0', 2), '⛔ ঋণাত্মক নিট কর্মীর নামে পাওনা হয়ে বসেনি');
        $this->assertSame('5000.00', app(AdvanceBalance::class)->open($this->short, Carbon::parse('2026-08-31')), '⛔ কর্মীর খোলা অগ্রিমে পাওনাটা দেখা যায় না');

        // ⓘ প্রদেয় বেতন = ধনাত্মক নিটগুলো + ফলব্যাকের কর্তন; ঋণাত্মকটা অন্যদের দেনা থেকে কাটা নয়
        $positives = '0';

        foreach ($run->payslips as $s) {
            if (bccomp((string) $s->net, '0', 4) >= 0) {
                $positives = bcadd($positives, (string) $s->net, 4);
            }
        }

        $payable = (int) StandardChart::find(StandardChart::SALARY_PAYABLE)->id;
        $credited = LedgerEntry::query()->where('source_type', PayrollRun::SOURCE_TYPE)->where('source_id', $run->id)
            ->where('account_id', $payable)->selectRaw('COALESCE(SUM(credit), 0) - COALESCE(SUM(debit), 0) as n')->value('n');

        $this->assertSame(bcadd(bcadd($positives, '25000', 4), '0', 2), bcadd((string) $credited, '0', 2),
            '⛔ ঋণাত্মক নিট অন্য কর্মীদের বেতন-দেনা কমিয়ে দিল');
        $this->assertGreaterThanOrEqual(bcadd('20000', '0', 4), bcadd($positives, '0', 4));
    }

    public function test_no_shortfall_no_advance_line(): void
    {
        $run = app(PayrollService::class)->confirm(app(PayrollService::class)->build('2026-08-01'));

        $this->assertSame(0, LedgerEntry::query()->where('source_type', PayrollRun::SOURCE_TYPE)->where('source_id', $run->id)
            ->where('account_id', StandardChart::find(StandardChart::EMPLOYEE_ADVANCE)->id)->count(), 'ঋণাত্মক নিট নেই, অথচ অগ্রিমে সারি বসল');
    }
}
