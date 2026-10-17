<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Engines\Dashboard\DashboardEngine;
use App\Core\Services\PhoneModules;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use App\Modules\Hr\Models\SalaryHead;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\PayrollService;
use App\Modules\Hr\Services\SalaryHeadService;
use App\Modules\Hr\Services\SalaryStructureService;
use App\Modules\MasterData\Models\Department;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ⭐ HR-এর ড্যাশবোর্ড ফোনে, বেতনের প্রতিটা অঙ্ক সার্ভারেই বাদ — মালিক, ১৭ অক্টোবর ২০২৬ ("ক"; [[PhoneAnswer]], [[HrDashboard::showsPay()]])।
 *
 * দাবি: মালিকের (বেতনের চাবিসহ) ওয়েবের ড্যাশবোর্ডে বেতন-খরচ, ভাতা-কর্তন আর বিভাগের খরচ আছে, আসল রানের অঙ্কসহ; ঠিক সেই মানুষের
 * ফোনের উত্তরে ওই তিনটা চার্ট নেই, বেতনের রানের গোনা নেই, আর রানের কোনো অঙ্ক (মোট আয়, নিট, কর্তন) কোথাও নেই — অথচ কর্মী, আজকের
 * হাজিরা আর বিভাগের চার্ট আছে। ড্যাশবোর্ড ফোনের তালিকাতেও আসে।
 */
final class TheHrDashboardReachesThePhoneWithoutPayTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_phone_gets_the_hr_dashboard_with_no_pay_figure_while_the_web_keeps_them(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $branch = $company->defaultBranch();
        CompanyContext::set($company->id, $branch?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);
        config(['abos.dashboards_v2' => true]);
        app(SettingsService::class)->set(PhoneModules::key('hr'), true);

        // ── আসল বেতন: এ মাসের রান, নিশ্চিত, চোখে পড়ার মতো অঙ্কে
        $department = Department::query()->create(['company_id' => $company->id, 'code' => 'PAYD', 'name_en' => 'Pay Department']);
        $person = app(EmployeeService::class)->create([
            'code' => 'PAY-1', 'name_en' => 'Pay Person', 'joining_date' => '2026-01-01', 'payment_method' => 'cash',
            'branch_id' => $branch->id, 'department_id' => $department->id,
        ]);
        app(SalaryHeadService::class)->installDefaults();
        app(SalaryStructureService::class)->set($person, SalaryHead::query()->where('code', 'BASIC')->firstOrFail(), '2026-01-01', '73519');
        $payroll = app(PayrollService::class);
        $run = $payroll->confirm($payroll->build(Carbon::today()->startOfMonth()->toDateString(), Carbon::today()->toDateString()))->fresh();
        $this->assertSame(1, bccomp((string) $run->gross_total, '0', 4), 'দাবির ভিত্তি নেই — রানে কোনো অঙ্ক নেই।');

        $pay = [__('hr::dashboard.salary_cost'), __('hr::dashboard.payroll_runs')];
        $figures = array_unique(array_filter([
            Money::format((string) $run->gross_total), Money::format((string) $run->net_total),
            bcadd((string) $run->gross_total, '0', 2), '73519', '73,519',
        ]));

        // ── ওয়েব: চাবি আছে, অঙ্কও আছে
        $web = app(DashboardEngine::class)->for('hr', $owner);
        $webLabels = [...array_map(fn ($s) => $s->label, $web->stats), ...array_map(fn ($p) => $p->label, $web->panels)];
        foreach ($pay as $label) {
            $this->assertContains($label, $webLabels, "দাবির ভিত্তি নেই — ওয়েবেই '{$label}' নেই।");
        }

        // ── ফোন: একই মানুষ, বেতনের কিছুই নয়
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($owner->fresh(), [AuthController::APP]);
        $answer = $this->getJson('/api/v1/dashboard/hr')->assertOk();
        $json = (string) $answer->getContent();

        $phoneLabels = [...array_column($answer->json('stats'), 'label'), ...array_column($answer->json('panels'), 'label')];
        foreach ($pay as $label) {
            $this->assertNotContains($label, $phoneLabels, "⛔ ফোনের উত্তরে '{$label}'।");
        }
        foreach ($figures as $figure) {
            $this->assertStringNotContainsString($figure, $json, "⛔ ফোনের উত্তরে বেতনের অঙ্ক '{$figure}'।");
        }
        $this->assertContains(__('hr::dashboard.employees'), array_column($answer->json('stats'), 'label'), 'বাকি ড্যাশবোর্ড আসার কথা।');
        $this->assertContains(__('hr::dashboard.todays_roll'), array_column($answer->json('panels'), 'label'));

        $modules = array_column($this->getJson('/api/v1/dashboard')->assertOk()->json('modules'), 'module');
        $this->assertContains('hr', $modules, '⛔ HR-এর ড্যাশবোর্ড ফোনের তালিকায় নেই।');

        // ⓘ ফোনের উত্তরের পরে ওয়েব আবার পুরোটা পায় — চিহ্নটা উত্তরের সাথেই নামে
        $this->assertContains(__('hr::dashboard.salary_cost'),
            array_map(fn ($p) => $p->label, app(DashboardEngine::class)->for('hr', $owner)->panels), '⛔ ফোনের পরে ওয়েবেও বেতন হারাল।');
    }
}
