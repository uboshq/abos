<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\SalaryHead;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\PayrollService;
use App\Modules\Hr\Services\SalaryHeadService;
use App\Modules\Hr\Services\SalaryStructureService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ বাতিলের উল্টো দাখিলা বেতনের দাখিলার আগের দিনে বসে না — ec, Hr লাইন-নিরীক্ষা, ১৪ অক্টোবর ২০২৬ (ⓘ৪; [[PayrollService::cancel()]])।
 *
 * ⓘ মাসের রান মাস-শেষের তারিখে বসে, অথচ মাসের মাঝে নিশ্চিত করে পরদিন বাতিল করলে উল্টোটা বসত আজকের তারিখে — মাস-শেষের আগে।
 * মাঝের দিনগুলোতে খাতায় বেতন-খরচ ঋণাত্মক দেখাত।
 */
final class AReversalNeverLandsBeforeThePayrollTest extends TestCase
{
    use RefreshDatabase;

    public function test_cancelling_before_the_runs_own_date_dates_the_reversal_on_that_date(): void
    {
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        app(SalaryHeadService::class)->installDefaults();

        $employee = app(EmployeeService::class)->create(['code' => 'EMP-REV', 'name_en' => 'Early Cancel', 'joining_date' => '2026-01-15', 'payment_method' => 'cash']);
        app(SalaryStructureService::class)->set($employee, SalaryHead::query()->where('code', 'BASIC')->firstOrFail(), '2026-01-15', '20000');

        // ⓘ আগস্টের ২০ তারিখ — রান বসে ৩১ আগস্ট, নিশ্চিত আর বাতিল তার আগেই
        $this->travelTo('2026-08-20 10:00:00');

        $payroll = app(PayrollService::class);
        $run = $payroll->confirm($payroll->build('2026-08-01'));
        $this->assertSame('2026-08-31', $run->trx_date->toDateString(), 'দৃশ্যটাই বানানো যায়নি — রান মাস-শেষে বসেনি');

        $this->travelTo('2026-08-21 10:00:00');
        $payroll->cancel($run->fresh(), 'Wrong amounts');

        $dates = LedgerEntry::query()->where('source_type', PayrollRun::SOURCE_TYPE.':reversal')->where('source_id', $run->id)
            ->get()->map(fn (LedgerEntry $row) => \Illuminate\Support\Carbon::parse($row->trx_date)->toDateString())->unique()->values()->all();

        $this->assertNotSame([], $dates, 'দৃশ্যটাই বানানো যায়নি — উল্টো দাখিলা বসেনি');
        $this->assertSame(['2026-08-31'], $dates, '⛔ উল্টো দাখিলা বেতনের দাখিলার আগের দিনে বসল');
    }

    public function test_cancelling_after_the_runs_date_still_dates_the_reversal_today(): void
    {
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        app(SalaryHeadService::class)->installDefaults();

        $employee = app(EmployeeService::class)->create(['code' => 'EMP-REV', 'name_en' => 'Late Cancel', 'joining_date' => '2026-01-15', 'payment_method' => 'cash']);
        app(SalaryStructureService::class)->set($employee, SalaryHead::query()->where('code', 'BASIC')->firstOrFail(), '2026-01-15', '20000');

        $this->travelTo('2026-09-05 10:00:00');

        $payroll = app(PayrollService::class);
        $run = $payroll->confirm($payroll->build('2026-08-01'));
        $payroll->cancel($run->fresh(), 'Wrong amounts');

        $dates = LedgerEntry::query()->where('source_type', PayrollRun::SOURCE_TYPE.':reversal')->where('source_id', $run->id)
            ->get()->map(fn (LedgerEntry $row) => \Illuminate\Support\Carbon::parse($row->trx_date)->toDateString())->unique()->values()->all();

        $this->assertSame(['2026-09-05'], $dates, 'রানের দিন পেরিয়ে গেলে উল্টোটা আগের মতোই আজকের দিনে');
    }
}
