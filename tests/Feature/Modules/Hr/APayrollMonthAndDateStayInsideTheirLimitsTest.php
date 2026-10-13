<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Support\CompanyContext;
use App\Models\Company;
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
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⛔ বেতনের মাস সামনে নয়, তারিখ মাসের আগে নয় — ec, Hr লাইন-নিরীক্ষা, ১৪ অক্টোবর ২০২৬ (⚠️৫; [[PayrollController::store()]], [[PayrollService::build()]])।
 *
 * ⓘ আগে ২০২৭-০৩-এর মতো সামনের মাস নেওয়া হত (নিশ্চিত করলে না-খাটা মাসের বেতন আগাম খরচে), আর অক্টোবরের রান ১ সেপ্টেম্বর তারিখেও
 * বসত — অক্টোবরের খরচ সেপ্টেম্বরের খাতায়।
 */
final class APayrollMonthAndDateStayInsideTheirLimitsTest extends TestCase
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
        app(SalaryHeadService::class)->installDefaults();

        $employee = app(EmployeeService::class)->create(['code' => 'EMP-LIM', 'name_en' => 'Dated Salary', 'joining_date' => '2026-01-15', 'payment_method' => 'cash']);
        app(SalaryStructureService::class)->set($employee, SalaryHead::query()->where('code', 'BASIC')->firstOrFail(), '2026-01-15', '20000');
    }

    public function test_a_future_month_is_refused(): void
    {
        $this->post(route('hr.payroll.store'), ['month' => now()->addMonthsNoOverflow(2)->format('Y-m')])
            ->assertSessionHasErrors('month');

        $this->assertSame(0, PayrollRun::acrossBranches()->count(), '⛔ সামনের মাসের বেতন আগাম বানানো হল');
    }

    public function test_a_date_before_the_month_is_refused_at_the_door_and_in_the_service(): void
    {
        $this->post(route('hr.payroll.store'), ['month' => '2026-08', 'trx_date' => '2026-07-15'])
            ->assertSessionHasErrors('trx_date');
        $this->assertSame(0, PayrollRun::acrossBranches()->count(), '⛔ আগস্টের বেতন জুলাইয়ের তারিখে বসল');

        try {
            app(PayrollService::class)->build('2026-08-01', '2026-07-31');
            $this->fail('⛔ সেবাটা মাসের আগের তারিখ মেনে নিল');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('trx_date', $e->errors());
        }

        $this->assertSame(0, PayrollRun::acrossBranches()->count());
    }

    public function test_a_past_month_on_a_date_inside_it_still_builds(): void
    {
        $this->post(route('hr.payroll.store'), ['month' => '2026-08', 'trx_date' => '2026-08-01'])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, PayrollRun::acrossBranches()->count(), 'ঠিক মাস আর ঠিক তারিখ আগের মতোই যায়');
        $this->assertSame('2026-08-01', PayrollRun::acrossBranches()->sole()->trx_date->toDateString());
    }

    public function test_the_current_month_still_builds(): void
    {
        $this->post(route('hr.payroll.store'), ['month' => now()->format('Y-m')])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, PayrollRun::acrossBranches()->count(), 'চলতি মাসের রান আগের মতোই');
    }
}
