<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\ListTotals;

use App\Core\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * কর্মী আর বাকি মডিউলের তালিকার নিচে যোগফলের পট্টি — মালিক, ৫ অক্টোবর ২০২৬ ([[x-ui.list-totals]])।
 *
 *   বেতনের রান   সারি · মোট বেতন · কর্তন · নিট — ৫১টা (দুই পাতা) আর ৩টা, দুই খোঁজাতেই ডাটাবেজের সরাসরি যোগ
 *   বাকি সব      খোলে, আর পট্টিতে পাতা ভাগের নিজের গোনা (গোটা ছাঁকনির, এই পাতার নয়)
 */
final class HrAndOtherListsCarryTheirTotalsTest extends TestCase
{
    use ReadsTheTotalsBar;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sitTheOwnerInTheNavyLook();
    }

    public function test_the_payroll_runs_sum_gross_deductions_and_net_of_every_page(): void
    {
        $n = 0;
        foreach ([['ZQG', 51], ['ZQH', 3]] as [$prefix, $count]) {
            for ($i = 1; $i <= $count; $i++) {
                $n++;
                DB::table('hr_payroll_runs')->insert([
                    'public_id' => (string) Str::uuid(), 'company_id' => CompanyContext::id(), 'branch_id' => CompanyContext::branchId(),
                    'document_no' => sprintf('%s-%03d', $prefix, $i), 'month' => now()->startOfMonth()->subMonths(100 + $n)->toDateString(),
                    'trx_date' => now()->toDateString(), 'gross_total' => '100', 'deduction_total' => '30', 'net_total' => '70',
                    'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        foreach (['ZQG', 'ZQH'] as $prefix) {
            $this->assertBar('hr.payroll.index', ['q' => $prefix], $this->dbCount('hr_payroll_runs', $prefix), [
                __('hr::field.gross') => $this->dbSum('hr_payroll_runs', 'gross_total', $prefix),
                __('hr::field.deductions') => $this->dbSum('hr_payroll_runs', 'deduction_total', $prefix),
                __('hr::field.net') => $this->dbSum('hr_payroll_runs', 'net_total', $prefix),
            ]);
        }
    }

    public function test_every_other_list_opens_with_a_bar_that_counts_the_whole_filter(): void
    {
        foreach ([
            'hr.employee.index' => 'employees', 'hr.leave.index' => 'applications', 'hr.salary_head.index' => 'heads',
            'hr.attendance.sheet' => 'rows',
            'approval.delegation.index' => 'given', 'approval.flow.index' => 'flows', 'approval.inbox.mine' => 'approvals',
            'backup.verification.index' => 'runs',
            'governance.audit.index' => 'trails', 'governance.error.index' => 'rows', 'governance.export.index' => 'rows',
            'governance.login.index' => 'rows',
            'master_data.unit.index' => 'records', 'master_data.location.level' => 'rows',
            'promotion.index' => 'rows', 'promotion.coupon.index' => 'rows', 'promotion.gift.index' => 'rows',
            'promotion.loyalty.index' => 'rows',
            'restaurant.production.index' => 'productions', 'restaurant.recipe.index' => 'recipes',
            'system_admin.branch.index' => 'rows', 'system_admin.user.index' => 'users',
            'system_admin.notice.index' => 'notices', 'system_admin.notice.category.index' => 'categories',
            'system_admin.notice.template.index' => 'templates',
            'paper.history' => 'rows',
        ] as $list => $rows) {
            $response = $this->get(route($list, $list === 'master_data.location.level' ? ['level' => 'route'] : []));

            if ($response->status() === 404) {
                // ⓘ সুইচে বন্ধ পর্দা ([[default-off-screens-404-every-test]]) — খোলাই যায় না, পট্টির প্রশ্ন ওঠে না
                continue;
            }

            $response->assertOk();
            $html = (string) $response->getContent();

            $listed = $response->viewData($rows);
            $count = $listed instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator ? $listed->total() : count($listed);
            $this->assertStringContainsString($this->rowsText($count), $this->bar($html, $list));
        }
    }
}
