<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Models\Approval;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * ⛔ ড্যাশবোর্ড নিরীক্ষা, 23 Nov 2026 (ডেমো 73ede568b, মালিকের অনুমোদিত নমুনার সাথে মিলিয়ে) — চাবি ছাড়া লাভ আর কেনা দর দেখা যেত।
 *
 *   হিসাব:     নিচের সারিতে লাভ-ক্ষতি, বিক্রীত পণ্যের খরচ, স্থিতিপত্র (মজুদের মূল্য, স্থায়ী সম্পদ, জমা লাভ), এক নজরে নিট লাভ, খাত ধরে খরচ
 *              আর আয় বনাম খরচ — কেবল `accounts.view`-এ, অথচ লাভ-ক্ষতি আর স্থিতিপত্রের পাতা `accounts.report.final` চায়।
 *   ক্রয়:      দামের ওঠানামায় কেনা দর — কেবল `purchase.bill.view`-এ, অথচ ক্রয়ের API আর ফোন কেনা দর `inventory.cost.view` ছাড়া দেখায় না।
 *   অনুমোদন:   গোটা কোম্পানির অপেক্ষমাণ তালিকায় বেতনের রানের মোট অঙ্ক — `hr.payroll.view` ছাড়াই।
 *
 * ⭐ একই মানুষ, চাবি ছাড়া আর চাবিসহ — চাবি ছাড়া ঘরটা নেই, চাবিসহ আছে (a-board-must-keep-the-cost-key)।
 */
final class TheBoardsShowedProfitAndCostWithoutTheKeyTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $clerk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        config(['abos.dashboards_v2' => true]);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->clerk = User::factory()->create(['current_company_id' => $this->company->id]);
        $this->clerk->companies()->attach($this->company->id, ['is_active' => true]);
    }

    public function test_the_accounts_board_hides_profit_statements_and_expense_heads_without_the_final_report_key(): void
    {
        $this->grant(['accounts.view']);

        $html = $this->board('accounts');
        foreach (['data-acc-board-statements', 'data-acc-pnl', 'data-acc-balance-sheet', 'data-acc-tree', 'data-acc-board-trends', 'data-glance="profit"'] as $mark) {
            $this->assertStringNotContainsString($mark, $html, "⛔ চাবি ছাড়া হিসাবের ড্যাশবোর্ডে {$mark} — লাভ বা খাত ধরে খরচ দেখা গেল।");
        }
        $this->assertStringContainsString('data-acc-board-glance', $html, '⛔ এক নজরের বাকি সারিগুলোও হারাল — কেবল নিট লাভ লুকানোর কথা।');

        $this->grant(['accounts.report.final']);

        // ⓘ নিট লাভ এখন কেবল প্রথম গ্রাফে, এক নজরের সারিতে নয় (ড্যাশবোর্ড যাচাই, ২৩ নভেম্বর ২০২৬: "দুইবার নয়")
        $html = $this->board('accounts');
        foreach (['data-acc-board-statements', 'data-acc-balance-sheet', 'data-acc-tree', 'data-acc-board-trends'] as $mark) {
            $this->assertStringContainsString($mark, $html, "⛔ চাবি থাকতেও {$mark} এল না — যার চাবি আছে সে-ও হারাল।");
        }
    }

    public function test_the_purchase_board_shows_the_purchase_rates_only_with_the_cost_key(): void
    {
        $this->grant(['purchase.bill.view']);
        $title = __('purchase::dashboard.price_changes');

        $this->assertStringNotContainsString($title, $this->board('purchase'), '⛔ কেনা দর দেখার চাবি ছাড়াই দামের ওঠানামা (শেষ দুই বিলের দর) দেখা গেল।');

        $this->grant(['inventory.cost.view']);

        $this->assertStringContainsString($title, $this->board('purchase'), '⛔ কেনা দর দেখার চাবি থাকতেও দামের ওঠানামা এল না।');
    }

    public function test_the_approval_board_hides_a_payroll_total_without_the_payroll_key(): void
    {
        $this->grant(['approval.view', 'approval.report']);
        Approval::query()->create([
            'company_id' => $this->company->id, 'approvable_type' => 'test:payroll', 'approvable_id' => 1, 'module' => 'hr', 'action' => 'payroll',
            'amount' => '987654.32', 'status' => Approval::PENDING, 'current_level' => 1, 'requested_by' => $this->clerk->id, 'requested_at' => now(),
        ]);

        // ⓘ বোর্ড যেভাবে লেখে সেভাবেই খোঁজা — লাখের কমা (9,87,654.32), [[Money::format()]]; '987,654.32' খুঁজলে "নেই" দাবিটা অন্ধ থাকত
        $shown = Money::format('987654.32');

        $this->assertStringNotContainsString($shown, $this->board('approval'), '⛔ বেতন দেখার চাবি ছাড়াই অপেক্ষমাণ তালিকায় বেতনের রানের মোট অঙ্ক।');

        $this->grant(['hr.payroll.view']);

        $this->assertStringContainsString($shown, $this->board('approval'), '⛔ বেতন দেখার চাবি থাকতেও অঙ্কটা এল না।');
    }

    /** @param  list<string>  $keys */
    private function grant(array $keys): void
    {
        CompanyContext::forCompany($this->company->id, fn () => $this->clerk->givePermissionTo(
            array_map(fn (string $key) => Permission::findOrCreate($key, 'web'), $keys),
        ));
        $this->clerk->unsetRelation('permissions')->unsetRelation('roles');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function board(string $module): string
    {
        $response = $this->actingAs($this->clerk->fresh())->get(route('module.dashboard', ['module' => $module]));
        $response->assertOk();

        return (string) $response->getContent();
    }
}
