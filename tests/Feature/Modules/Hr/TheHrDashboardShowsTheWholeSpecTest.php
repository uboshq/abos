<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Engines\Dashboard\Breakdown;
use App\Core\Engines\Dashboard\DashboardDefinition;
use App\Core\Engines\Dashboard\DateRange;
use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Models\Company;
use App\Models\User;
use App\Modules\Hr\Dashboard\HrDashboard;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\PayslipLine;
use App\Modules\Hr\Models\SalaryHead;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\PayrollService;
use App\Modules\Hr\Services\SalaryHeadService;
use App\Modules\Hr\Services\SalaryStructureService;
use App\Modules\MasterData\Models\Department;
use App\Modules\MasterData\Models\Designation;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * কর্মী ও বেতনের ড্যাশবোর্ড — মালিকের পুরো নকশা (৬ অক্টোবর ২০২৬): শাখা ও পদবি অনুযায়ী চালু কর্মী, ভাতা বনাম কর্তন,
 * বিভাগ অনুযায়ী বেতন খরচ।
 *
 * ⓘ দাবি, একই মালিক দুইবার (সুইচ বন্ধ, তারপর চালু): বন্ধে নতুন একটা চার্টও নেই, প্রথম চার্ট (আজকের হাজিরা) জায়গায়;
 * চালুতে একজন নতুন কর্মী শাখা আর পদবির যোগফলে ঠিক ১ বাড়ায় (চাকরি ছাড়া জন বাড়ায় না), আর আসল বেতন-রান নিশ্চিত
 * করলে ভাতা-কর্তন ও বিভাগের খরচ বেতনশিটের সারির সাথে হুবহু মেলে — মোট আয়, মোট কর্তন, রানের মোট।
 */
final class TheHrDashboardShowsTheWholeSpecTest extends TestCase
{
    use RefreshDatabase;

    private const PANELS = ['by_branch', 'by_designation', 'allowance_deduction', 'cost_by_department'];

    public function test_every_new_chart_matches_the_people_and_the_payslips(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $branch = $company->defaultBranch();
        CompanyContext::set($company->id, $branch?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);

        // ── সুইচ বন্ধ: নতুন একটা চার্টও নেই, প্রথম চার্ট জায়গায় ──
        config(['abos.dashboards_v2' => false]);
        $off = HrDashboard::dashboard();
        foreach (self::PANELS as $key) {
            $this->assertNull($this->panel($off, $key), "⛔ সুইচ বন্ধেও '{$key}'।");
        }
        $this->assertSame(__('hr::dashboard.todays_roll'), $off->panels[0]->label, '⛔ প্রথম চার্ট সরে গেছে।');

        config(['abos.dashboards_v2' => true]);
        $before = HrDashboard::dashboard();
        $this->assertSame(__('hr::dashboard.todays_roll'), $before->panels[0]->label, '⛔ সুইচ চালুতে প্রথম চার্ট সরে গেছে — হোম এটাই দেখায়।');

        // ── আসল মানুষ: একজন চালু, একজন চাকরি ছেড়েছেন ──
        $department = Department::query()->create(['company_id' => $company->id, 'code' => 'SPECD', 'name_en' => 'Spec Department']);
        $designation = Designation::query()->create(['company_id' => $company->id, 'code' => 'SPECR', 'name_en' => 'Spec Role']);

        $employees = app(EmployeeService::class);
        $person = $employees->create([
            'code' => 'SPEC-1', 'name_en' => 'Spec One', 'joining_date' => '2026-01-01', 'payment_method' => 'cash',
            'branch_id' => $branch->id, 'department_id' => $department->id, 'designation_id' => $designation->id,
        ]);
        $employees->create([
            'code' => 'SPEC-2', 'name_en' => 'Spec Gone', 'joining_date' => '2026-01-01', 'leaving_date' => '2026-02-28',
            'payment_method' => 'cash', 'branch_id' => $branch->id, 'department_id' => $department->id, 'designation_id' => $designation->id,
        ]);

        $after = HrDashboard::dashboard();
        $active = app(DataScope::class)->inView(Employee::query()->whereNull('leaving_date'), 'hr_employees.branch_id')->count();

        foreach (['by_branch' => 'hbars', 'by_designation' => 'donut'] as $key => $kind) {
            $panel = $this->panel($after, $key);
            $this->assertNotNull($panel, "⛔ '{$key}' চার্ট নেই।");
            $this->assertSame($kind, $panel->chart);
            $this->assertSame(1, $this->total($panel) - $this->total($this->panel($before, $key)), "⛔ '{$key}': নতুন চালু কর্মী ঠিক ১ বাড়ায়নি (বা ছেড়ে যাওয়া জনও গোনা)।");
            $this->assertSame($active, $this->total($panel), "⛔ '{$key}' এর যোগফল দেখার শাখার চালু কর্মী নয়।");
            $this->assertSame(__('hr::dashboard.workforce_hint', ['count' => $active]), $panel->hint);
        }

        $was = (int) ($this->parts($this->panel($before, 'by_branch'))[$branch->name()] ?? 0);
        $this->assertSame($was + 1, (int) $this->parts($this->panel($after, 'by_branch'))[$branch->name()], '⛔ নতুন কর্মী নিজের শাখার দণ্ডে আসেননি।');

        // ── আসল বেতন: এ মাসের রান, নিশ্চিত ──
        app(SalaryHeadService::class)->installDefaults();
        $heads = SalaryHead::query()->pluck('id', 'code');
        $structure = app(SalaryStructureService::class);
        $structure->set($person, SalaryHead::query()->findOrFail($heads['BASIC']), '2026-01-01', '20000');
        $structure->set($person, SalaryHead::query()->findOrFail($heads['MEDICAL']), '2026-01-01', '1500');
        $structure->set($person, SalaryHead::query()->findOrFail($heads['ADVANCE']), '2026-01-01', '700');
        $this->giveAdvance($person, '5000', Carbon::today()->startOfMonth()->toDateString());

        $month = Carbon::today()->startOfMonth();
        $payroll = app(PayrollService::class);
        $run = $payroll->confirm($payroll->build($month->toDateString(), Carbon::today()->toDateString()))->fresh();

        $paid = HrDashboard::dashboard();
        $range = DateRange::label($month, $month->copy()->endOfMonth());

        // ভাতা বনাম কর্তন — বেতনশিটের সারি, খাত ধরে
        $split = $this->panel($paid, 'allowance_deduction');
        $this->assertNotNull($split, '⛔ ভাতা-কর্তনের চার্ট নেই।');
        $this->assertSame('columns', $split->chart);
        $this->assertSame($range, $split->range, '⛔ ভাতা-কর্তনের চার্ট কোন মাসের, তা বলে না।');

        $bn = app()->getLocale() === 'bn';
        $expected = [];
        $lines = PayslipLine::query()->whereIn('payslip_id', Payslip::query()->where('payroll_run_id', $run->id)->select('id'))->get();
        foreach ($lines->groupBy(fn ($l) => $l->kind.'|'.$l->head_code) as $group) {
            $sum = $group->reduce(fn (string $c, $l) => bcadd($c, (string) $l->amount, 4), '0');
            if (bccomp($sum, '0', 4) === 0) {
                continue;
            }
            $first = $group->first();
            $name = $bn && filled($first->head_name_bn) ? $first->head_name_bn : $first->head_name_en;
            $label = $first->kind === SalaryHead::DEDUCTION ? __('hr::dashboard.deduction_head', ['name' => $name]) : $name;
            $expected[$label] = Money::format($sum);
        }
        $this->assertEqualsCanonicalizing($expected, $this->parts($split), '⛔ ভাতা-কর্তনের খাতগুলো বেতনশিটের সারির সাথে মেলে না।');
        $this->assertArrayHasKey(__('hr::dashboard.deduction_head', ['name' => $bn ? 'অগ্রিম কর্তন' : 'Advance Recovery']), $this->parts($split),
            '⛔ কর্তনের খাত চার্টে আসেনি।');
        $this->assertSame(__('hr::dashboard.allowance_deduction_hint', [
            'earning' => Money::format((string) $run->gross_total), 'deduction' => Money::format((string) $run->deduction_total),
        ]), $split->hint, '⛔ আয় আর কর্তনের মোট রানের মোটের সাথে মেলে না।');

        // বিভাগ অনুযায়ী খরচ — মোট আয়, কর্মীর বিভাগে
        $cost = $this->panel($paid, 'cost_by_department');
        $this->assertNotNull($cost, '⛔ বিভাগের খরচের চার্ট নেই।');
        $this->assertSame('hbars', $cost->chart);
        $this->assertSame($range, $cost->range);
        $gross = (string) Payslip::query()->where('payroll_run_id', $run->id)->where('employee_id', $person->id)->value('gross');
        $this->assertSame(0, bccomp($gross, '21500', 4), 'প্রস্তুতিটাই ভুল — মূল ২০,০০০ + চিকিৎসা ১,৫০০ বেতনশিটে আসেনি।');
        $this->assertSame(Money::format($gross), $this->parts($cost)[$department->name()] ?? null, '⛔ নতুন বিভাগের খরচ কর্মীর বেতনশিটের মোট আয় নয়।');
        $this->assertSame(__('hr::dashboard.cost_by_department_hint', ['total' => Money::format((string) $run->gross_total)]), $cost->hint,
            '⛔ বিভাগগুলোর মোট রানের মোট আয়ের সমান নয়।');
    }

    private function panel(DashboardDefinition $def, string $key): ?Breakdown
    {
        return collect($def->panels)->firstWhere('label', __('hr::dashboard.'.$key));
    }

    /** @return array<string, string> */
    private function parts(?Breakdown $panel): array
    {
        return array_column($panel?->parts ?? [], 'value', 'label');
    }

    private function total(?Breakdown $panel): int
    {
        return array_sum(array_map('intval', array_column($panel?->parts ?? [], 'value')));
    }

    /**
     * ⓘ কর্মীর নামে আসল অগ্রিম — খাতায় (১১৩১, পক্ষ `employee`)। অগ্রিমের কিস্তি এখন খোলা অগ্রিমের বেশি কাটে না
     * (পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬ HR ⛔৪), তাই কিস্তি কাটতে হলে আগে অগ্রিমটা দিতে হয়।
     */
    private function giveAdvance(\App\Modules\Hr\Models\Employee $employee, string $amount, string $on): void
    {
        app(\App\Modules\Accounts\Services\StandardChart::class)->install();
        $cash = \App\Modules\Accounts\Models\Account::query()->money()->postable()->active()->orderBy('code')->firstOrFail();

        app(\App\Core\Engines\Posting\PostingEngine::class)->post(sourceType: 'payment_voucher', sourceId: random_int(1, 9_999_999), trxDate: $on, lines: [
            ['account_id' => \App\Modules\Accounts\Services\StandardChart::find(\App\Modules\Accounts\Services\StandardChart::EMPLOYEE_ADVANCE)->id,
                'debit' => $amount, 'party_type' => 'employee', 'party_id' => $employee->id],
            ['account_id' => $cash->id, 'credit' => $amount],
        ]);
    }
}
