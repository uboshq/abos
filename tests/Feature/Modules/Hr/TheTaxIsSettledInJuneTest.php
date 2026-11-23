<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
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
use Tests\SignsTheSecondHand;
use Tests\TestCase;

/**
 * ⭐ উৎসব ভাতা করযোগ্য, আর জুনে বছরের সমন্বয় (আয়কর আইন ২০২৩; fe, ২১ অক্টোবর ২০২৬, দফা ২; [[SalaryTax::trueUp()]])।
 *
 * দাবি:
 *  · আনুমানিক হিসাবে দুই উৎসব ভাতা — আয়ের বছর শেষের আগে এক বছর হলে; নইলে শূন্য।
 *  · জুনের রান — বছরের আসল আয় (১২ মাসের স্লিপ + এপ্রিলের উৎসব ভাতা = ১১৬৩৪৫৬.৬৪), কর ৩৭৫৬৩.৭৭৬, আগে কাটা ৫ × ৩৪৭০.৫১ =
 *    ১৭৩৫২.৫৫ → জুনে ২০২১১.২৩; বছরের TDS-এর যোগ = বছরের কর, পয়সায়।
 *  · বেশি কাটা হলে জুনে শূন্য — ঋণাত্মক কর্তন নয়।
 */
final class TheTaxIsSettledInJuneTest extends TestCase
{
    use RefreshDatabase;
    // ⛔ বেতনের রান দ্বিতীয় সই ছাড়া খাতায় বসে না, সব ছক বন্ধ থাকলেও — সই-শাখার নিয়ম, মেশানোর পরে এখানেও (a6, ২১ অক্টোবর ২০২৬; [[ApprovalRules::secondHand()]])
    use SignsTheSecondHand;

    private const SLABS = '350000:0, 100000:5, 400000:10, 500000:15, 500000:20, 2000000:25, *:30';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();
        app(MasterListService::class)->installDefaults();
        app(SalaryHeadService::class)->installDefaults();
        Employee::query()->delete();
    }

    public function test_the_estimate_counts_two_festival_bonuses_only_for_a_year_of_service(): void
    {
        $old = $this->employee('TU-O', '2024-01-01');
        $new = $this->employee('TU-N', '2026-08-01');
        $tax = app(SalaryTax::class);

        $this->assertSame(0, bccomp($tax->expectedBonus($old, Carbon::parse('2026-09-30'), '61234.56'), '122469.12', 4),
            '⛔ দুই উৎসব ভাতা আনুমানিক আয়ে নেই।');
        $this->assertSame(0, bccomp($tax->expectedBonus($new, Carbon::parse('2026-09-30'), '61234.56'), '0', 4),
            '⛔ বছর শেষেও এক বছর হবে না, অথচ ভাতা ধরা হলো।');
    }

    public function test_june_settles_the_year_on_actual_income_including_the_bonus(): void
    {
        $a = $this->employee('TU-A', '2024-01-01');
        $payroll = app(PayrollService::class);

        // ⓘ জুন ২০২৫ আগের আয়ের বছরের — জুন ২০২৬-এর সমন্বয়ে আসবে না
        foreach (['2025-06', '2025-07', '2025-08', '2025-09', '2025-10', '2025-11', '2025-12'] as $month) {
            $this->confirmedPayroll($payroll->build($month.'-01'));
        }

        app(SettingsService::class)->set(SalaryTax::SLABS, self::SLABS);

        foreach (['2026-01', '2026-02', '2026-03', '2026-04', '2026-05'] as $month) {
            $run = $this->confirmedPayroll($payroll->build($month.'-01'));
            $this->assertSame('3470.5100', $this->tdsOf($run), '⛔ মাসিক আনুমানিক ভাগ ৩৪৭০.৫১ নয়।');
        }

        $this->confirmedPayroll($payroll->buildBonus('2026-04-10'));

        $june = $payroll->build('2026-06-01');
        $this->assertSame('20211.2300', $this->tdsOf($june), '⛔ জুনের সমন্বয় বছরের আসল আয়ের উপর নয়।');

        $settled = app(SalaryTax::class)->trueUp($a, Carbon::parse('2026-06-30'), '91851.84');
        $this->assertSame(0, bccomp($settled['income'], '1163456.64', 4), '⛔ উৎসব ভাতা বছরের আয়ে নেই।');
        // ⓘ বছরের TDS (৫ মাস + জুন) = বছরের কর, পয়সায় গোল
        $this->assertSame(\App\Core\Support\Money::round($settled['tax'], 2), bcadd(bcmul('3470.51', '5', 2), '20211.23', 2),
            '⛔ বছরের TDS-এর যোগ বছরের কর নয়।');
    }

    public function test_an_over_deducted_year_settles_at_zero_not_below(): void
    {
        $this->employee('TU-A', '2024-01-01');
        $payroll = app(PayrollService::class);
        app(SettingsService::class)->set(SalaryTax::SLABS, self::SLABS);

        foreach (['2025-07', '2025-08', '2025-09', '2025-10', '2025-11', '2025-12', '2026-01', '2026-02', '2026-03', '2026-04', '2026-05'] as $month) {
            $this->confirmedPayroll($payroll->build($month.'-01'));
        }

        // ⓘ ভাতা ধরা হয়েছিল, দেওয়া হয়নি — ১১ × ৩৪৭০.৫১ = ৩৮১৭৫.৬১, বছরের কর ৩৩৪৮১.৪৭
        $june = $payroll->build('2026-06-01');

        $this->assertSame(0, PayslipLine::query()->whereIn('payslip_id', $june->payslips()->select('id'))
            ->where('head_code', SalaryTax::LINE_CODE)->count(), '⛔ বেশি কাটা বছরে জুনে আবার কাটা হলো।');
    }

    private function employee(string $code, string $joined): Employee
    {
        $employee = app(EmployeeService::class)->create([
            'code' => $code, 'name_en' => 'Taxpayer '.$code, 'joining_date' => $joined, 'payment_method' => 'cash',
        ]);
        $salaries = app(SalaryStructureService::class);
        $salaries->set($employee, SalaryHead::query()->where('code', 'BASIC')->firstOrFail(), $joined, '61234.56');
        $salaries->set($employee, SalaryHead::query()->where('code', 'HRA')->firstOrFail(), $joined, '50');

        return $employee->fresh();
    }

    private function tdsOf(PayrollRun $run): string
    {
        return bcadd((string) PayslipLine::query()->whereIn('payslip_id', $run->payslips()->select('id'))
            ->where('head_code', SalaryTax::LINE_CODE)->value('amount'), '0', 4);
    }
}
