<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Hr\Dashboard\HrDashboard;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HR ড্যাশবোর্ডের "আজকের হাজিরা" — মালিকের ড্যাশবোর্ড নকশা, ২ অক্টোবর ২০২৬।
 *
 * ⭐ ভাগগুলোর যোগফল কর্মীসংখ্যার সমান: দেরিতে আসা মানুষ "উপস্থিত"-এ দ্বিতীয়বার গোনা হয় না,
 * আর যাঁর হাজিরা আজ লেখা হয়নি তিনি "লেখা হয়নি" — অনুপস্থিত নন।
 * ⛔ চাকরি ছেড়ে যাওয়া কর্মী গোনায় নেই।
 */
final class TheDashboardCountsTodaysRollTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_person_lands_in_exactly_one_part_of_todays_roll(): void
    {
        $company = Company::create(['code' => 'ROL', 'name_en' => 'Roll Co']);
        $branch = Branch::query()->create(['company_id' => $company->id, 'code' => 'B1', 'name_en' => 'Main', 'is_active' => true]);
        CompanyContext::set($company->id, $branch->id);

        $owner = User::factory()->create(['current_company_id' => $company->id]);
        $owner->companies()->attach($company->id);
        $this->actingAs($owner);

        $today = now()->toDateString();

        foreach ([
            ['P', Attendance::PRESENT, false, null],
            ['L', Attendance::PRESENT, true, null],
            ['A', Attendance::ABSENT, false, null],
            ['V', Attendance::LEAVE, false, null],
            ['N', null, false, null],
            ['X', Attendance::PRESENT, false, '2026-01-31'],
        ] as [$code, $status, $late, $left]) {
            $employee = Employee::query()->create([
                'company_id' => $company->id, 'branch_id' => $branch->id, 'code' => 'E-'.$code,
                'name_en' => 'Person '.$code, 'joining_date' => '2024-01-01', 'leaving_date' => $left,
            ]);

            if ($status !== null) {
                Attendance::query()->create([
                    'company_id' => $company->id, 'employee_id' => $employee->id,
                    'work_date' => $today, 'status' => $status, 'is_late' => $late,
                ]);
            }
        }

        $panel = HrDashboard::dashboard()->panels[0];
        $parts = array_column($panel->parts, 'value', 'label');

        $this->assertSame([
            __('hr::kind.present') => '1',
            __('hr::dashboard.late') => '1',
            __('hr::kind.leave') => '1',
            __('hr::kind.absent') => '1',
            __('hr::dashboard.not_written') => '1',
        ], $parts, '⛔ আজকের হাজিরার ভাগ ভুল — কেউ দুইবার গোনা, কেউ বাদ, বা ছেড়ে যাওয়া কর্মী গোনায়।');

        $this->assertSame(5, array_sum(array_map('intval', $parts)), 'ভাগগুলোর যোগফল চলতি কর্মীসংখ্যা (৫) নয়।');
    }

    /**
     * ⭐ বেতন খরচ — গত ছয় মাস, কেবল নিশ্চিত বেতনশিট, আর কেবল `hr.payroll.view`-এ (নতুন ড্যাশবোর্ড, ২ অক্টোবর ২০২৬)।
     * ⛔ একই মানুষ চাবি ছাড়া → চার্টই নেই; চাবিসহ → এ মাসের দণ্ডে নিশ্চিত শিটের অঙ্ক, খসড়া বাদ।
     */
    public function test_the_salary_cost_shows_posted_payroll_only_to_the_payroll_key(): void
    {
        config(['abos.dashboards_v2' => true]);

        $company = Company::create(['code' => 'PAY', 'name_en' => 'Pay Co']);
        $branch = Branch::query()->create(['company_id' => $company->id, 'code' => 'B1', 'name_en' => 'Main', 'is_active' => true]);
        CompanyContext::set($company->id, $branch->id);

        $clerk = User::factory()->create(['current_company_id' => $company->id]);
        $clerk->companies()->attach($company->id);
        $this->actingAs($clerk);

        foreach ([['PR-1', \App\Core\Support\DocumentStatus::CONFIRMED, '50000.00', '45000.00'], ['PR-2', \App\Core\Support\DocumentStatus::DRAFT, '99999.00', '99999.00']] as [$no, $status, $gross, $net]) {
            \App\Modules\Hr\Models\PayrollRun::query()->create([
                'company_id' => $company->id, 'branch_id' => $branch->id, 'document_no' => $no,
                'month' => now()->startOfMonth()->toDateString(), 'trx_date' => now()->toDateString(),
                'gross_total' => $gross, 'deduction_total' => '0', 'net_total' => $net, 'employee_count' => 1, 'status' => $status,
            ]);
        }

        $label = __('hr::dashboard.salary_cost');
        $labels = fn () => array_map(fn ($p) => $p->label, HrDashboard::dashboard()->panels);

        $this->assertNotContains($label, $labels(), '⛔ বেতনের চাবি ছাড়াই বেতন খরচের চার্ট দেখা গেছে।');

        \Spatie\Permission\Models\Permission::findOrCreate('hr.payroll.view', 'web');
        $clerk->givePermissionTo('hr.payroll.view');
        $this->actingAs($clerk->fresh());

        $panel = collect(HrDashboard::dashboard()->panels)->firstWhere('label', $label);
        $this->assertNotNull($panel, 'বেতনের চাবি থাকা সত্ত্বেও বেতন খরচের চার্ট নেই।');
        $this->assertCount(6, $panel->points, 'ছয় মাসের দণ্ড নেই।');

        $now = $panel->points[array_key_last($panel->points)];
        $this->assertSame(0, bccomp((string) $now['first'], '50000', 2), '⛔ এ মাসের মোট আয়ে খসড়া শিটও গোনা — বা নিশ্চিতটা বাদ।');
        $this->assertSame(0, bccomp((string) $now['second'], '45000', 2), 'এ মাসের হাতে পাওয়া অঙ্ক ভুল।');
    }
}
