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
}
