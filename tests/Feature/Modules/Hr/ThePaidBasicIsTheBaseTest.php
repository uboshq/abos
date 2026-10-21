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
use App\Modules\Hr\Services\AttendanceService;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\OvertimeService;
use App\Modules\Hr\Services\PayrollService;
use App\Modules\Hr\Services\ProvidentFund;
use App\Modules\Hr\Services\SalaryHeadService;
use App\Modules\Hr\Services\SalaryStructureService;
use App\Modules\MasterData\Models\EmploymentType;
use App\Modules\MasterData\Services\MasterListService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ⭐ প্রতিষ্ঠানের PF ভাগ আর অধিকালের ভিত — দেওয়া মূল, মাসের কাজের দিনের ভাগে (fe, ২১ অক্টোবর ২০২৬, দফা ৪)।
 *
 * দাবি: ১৬ জুলাই ২০২৬ যোগ দিলে মূল ২৩৪৫৬.৭৮ × ১৬/৩১ = ১২১০৬.৭৩ দেওয়া হয়; প্রতিষ্ঠানের ভাগ ৮% = ৯৬৮.৫৪ (পুরো মূলের ১৮৭৬.৫৪ নয়);
 * অধিকাল ১২১০৬.৭৩ × ২ × ৪.৭৫ ÷ ২০৮ = ৫৫২.৯৫।
 */
final class ThePaidBasicIsTheBaseTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_a_mid_month_joiner_gets_the_employer_share_and_overtime_on_the_basic_paid(): void
    {
        app(SettingsService::class)->set(ProvidentFund::ENABLED, true);
        app(SettingsService::class)->set(OvertimeService::ENABLED, true);

        $employee = app(EmployeeService::class)->create([
            'code' => 'PB-A', 'name_en' => 'Mid Month', 'joining_date' => '2026-07-16', 'payment_method' => 'cash',
            'employment_type_id' => EmploymentType::query()->where('code', 'WORKER')->value('id'),
        ]);
        $salaries = app(SalaryStructureService::class);
        $salaries->set($employee, SalaryHead::query()->where('code', 'BASIC')->firstOrFail(), '2026-07-16', '23456.78');
        $salaries->set($employee, SalaryHead::query()->where('code', 'PF')->firstOrFail(), '2026-07-16', '8');

        $attendance = app(AttendanceService::class);
        $attendance->mark($employee, '2026-07-20', 'present', ['in_time' => '09:00:00', 'out_time' => '19:30:00']);
        $attendance->mark($employee, '2026-07-21', 'present', ['in_time' => '08:00:00', 'out_time' => '18:15:00']);
        $sheet = app(OvertimeService::class)->prepareFor($employee, Carbon::parse('2026-07-01'));
        app(OvertimeService::class)->sign($sheet, User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $run = app(PayrollService::class)->build('2026-07-01');

        $this->assertSame('12106.7300', $this->line($run, 'BASIC'), '⛔ ১৬/৩১ দিনের মূল ১২১০৬.৭৩ হওয়ার কথা।');
        $this->assertSame('968.5400', $this->line($run, 'PF_ER'), '⛔ প্রতিষ্ঠানের ভাগ দেওয়া মূলের ৮% নয়।');
        $this->assertSame('552.9500', $this->line($run, 'OT'), '⛔ অধিকাল দেওয়া মূলের উপর নয়।');
    }

    private function line(PayrollRun $run, string $code): string
    {
        return bcadd((string) PayslipLine::query()->whereIn('payslip_id', $run->payslips()->select('id'))
            ->where('head_code', $code)->value('amount'), '0', 4);
    }
}
