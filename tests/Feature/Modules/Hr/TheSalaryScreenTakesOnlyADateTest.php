<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Hr\Models\SalaryHead;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\SalaryHeadService;
use App\Modules\Hr\Services\SalaryStructureService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ বেতনের পর্দায় ভুল `?on=` — আজকের ছবি, ৫০০ নয় — ec, Hr লাইন-নিরীক্ষা, ১৪ অক্টোবর ২০২৬ (ⓘ৮; [[SalaryStructureController::asOf()]])।
 *
 * ⓘ ঠিকানার তারিখটা সোজা Carbon::parse-এ যেত; "abc" বা ভাঙা দিনে পাতা ভেঙে যেত।
 */
final class TheSalaryScreenTakesOnlyADateTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_bad_date_in_the_address_falls_back_to_today(): void
    {
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(SalaryHeadService::class)->installDefaults();

        $employee = app(EmployeeService::class)->create(['code' => 'EMP-ON', 'name_en' => 'Dated Screen', 'joining_date' => '2026-01-15', 'payment_method' => 'cash']);
        app(SalaryStructureService::class)->set($employee, SalaryHead::query()->where('code', 'BASIC')->firstOrFail(), '2026-01-15', '20000');

        foreach (['abc', '2026-02-31', 'next tuesday', '2026-13-01'] as $bad) {
            $this->get(route('hr.employee.salary', ['employee' => $employee, 'on' => $bad]))
                ->assertOk()->assertViewHas('on', fn ($on) => $on->isSameDay(now()));
        }

        $this->get(route('hr.employee.salary', $employee).'?on[]=x')->assertOk();

        $this->get(route('hr.employee.salary', ['employee' => $employee, 'on' => '2026-03-01']))
            ->assertOk()->assertViewHas('on', fn ($on) => $on->toDateString() === '2026-03-01');
    }
}
