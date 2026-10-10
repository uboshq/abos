<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Hr\Dashboard\HrDashboard;
use App\Modules\Hr\Dashboard\HrWidgets;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\LeaveApplication;
use App\Modules\Hr\Models\LeaveType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * HR-এর অপেক্ষমাণ ছুটির গণনা শাখার দেয়াল মানত না — পুনঃনিরীক্ষা, ৯ অক্টোবর ২০২৬।
 *
 * ── ⛔ কী ভুল ছিল ────────────────────────────────────────────────────
 * `LeaveApplication`-এ শাখার স্কোপ নেই। ছুটির তালিকা আর অনুমোদনের দরজা
 * দেয়াল মানে ([[AttendanceAndLeaveStayInsideTheBranchTest]]), কিন্তু HR-এর
 * ড্যাশবোর্ডের ঘর, তার "অপেক্ষমাণ ছুটি" তালিকা আর হোমের ঘর গোটা কোম্পানি
 * গুনত — অন্য শাখার কর্মীর নামসহ।
 *
 * ⓘ বিপজ্জনক মানুষটা এখানে: কেবল ঢাকা শাখা যাঁর নাগালে।
 */
final class ThePendingLeaveCountWentOverTheBranchWallTest extends TestCase
{
    use RefreshDatabase;

    private const KEYS = ['hr.view', 'hr.leave.view', 'hr.leave.approve', 'hr.attendance.view'];

    public function test_a_branch_limited_manager_counts_and_sees_only_their_branchs_leave(): void
    {
        $company = Company::create(['code' => 'HRL', 'name_en' => 'Leave Wall Co']);
        CompanyContext::set($company->id);

        $dhaka = Branch::query()->create(['company_id' => $company->id, 'code' => 'DHK', 'name_en' => 'Dhaka', 'is_active' => true]);
        $netrokona = Branch::query()->create(['company_id' => $company->id, 'code' => 'NTK', 'name_en' => 'Netrokona', 'is_active' => true]);

        foreach (self::KEYS as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $manager = User::factory()->create(['current_company_id' => $company->id, 'current_branch_id' => null]);
        $manager->companies()->attach($company->id);
        $manager->givePermissionTo(self::KEYS);
        UserDataScope::query()->create([
            'company_id' => $company->id, 'user_id' => $manager->id, 'scope_type' => UserDataScope::BRANCH, 'scope_id' => $dhaka->id,
        ]);
        app(DataScope::class)->forget();

        $casual = LeaveType::query()->create([
            'company_id' => $company->id, 'code' => 'CL', 'name_en' => 'Casual', 'name_bn' => 'নৈমিত্তিক',
            'days_per_year' => '10', 'is_paid' => true, 'is_active' => true,
        ]);

        $leaveFor = function (Branch $branch, string $code, string $name) use ($company, $casual): void {
            $employee = Employee::query()->create([
                'company_id' => $company->id, 'branch_id' => $branch->id, 'code' => $code, 'name_en' => $name, 'joining_date' => '2026-01-01',
            ]);
            LeaveApplication::query()->create([
                'company_id' => $company->id, 'employee_id' => $employee->id, 'leave_type_id' => $casual->id,
                'from_date' => '2026-10-05', 'to_date' => '2026-10-05', 'days' => '1', 'status' => LeaveApplication::PENDING,
            ]);
        };

        $leaveFor($dhaka, 'E-1', 'Dhaka Person');
        $leaveFor($netrokona, 'E-2', 'Netrokona Person');
        $leaveFor($netrokona, 'E-3', 'Another Netrokona Person');

        $this->actingAs($manager->fresh());

        $dashboard = HrDashboard::dashboard();

        $stat = collect($dashboard->stats)->firstWhere('label', __('hr::dashboard.pending_leave'));
        $this->assertNotNull($stat, 'অপেক্ষমাণ ছুটির ঘরটাই নেই — দাবি অন্ধ।');
        $this->assertSame('1', $stat->value, '⛔ ঢাকার মানুষ নেত্রকোনার অপেক্ষমাণ ছুটিও গুনলেন।');

        $listing = collect($dashboard->listings)->firstWhere('label', __('hr::dashboard.pending_leave'));
        $this->assertSame(['Dhaka Person'], $listing->rows->map(fn (LeaveApplication $l) => $l->employee?->name_en)->all(),
            '⛔ "অপেক্ষমাণ ছুটি" তালিকায় অন্য শাখার কর্মীর নাম এল।');

        $widget = collect(HrWidgets::widgets())->firstWhere('label', __('hr::dashboard.leave_awaiting'));
        $this->assertSame('1', $widget->value, '⛔ হোমের "ছুটির অপেক্ষায়" ঘরে অন্য শাখার ছুটি গোনা হলো।');
    }
}
