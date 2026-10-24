<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportEngine;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * ⭐ রিপোর্টের প্রতিটা টাকার অঙ্কে চাপলে তার উৎস খোলে — drill-down (মালিক, ২৪ অক্টোবর ২০২৬, প্রিন্সিপালের কমিশনের ছবিতে:
 * *"যেখানে চিহ্ন দিয়েছি, সেখানে লিংক থাকার কথা, যাতে হিসাব কোথা থেকে এল বোঝা যায়"*; fe)।
 *
 * ── পাহারা ──────────────────────────────────────────────────────────────
 * প্রতিটা নিবন্ধিত রিপোর্টের প্রতিটা টাকার কলাম (MONEY, DR_CR) `drill` ঘোষণা করে ([[ReportColumn::$drill]]), নয়তো রিপোর্টটা
 * [[KNOWN]]-এ, ঠিক ততগুলো কলাম নিয়ে — একটা সারাও, তালিকার গোনাও কমাও (তালিকা সত্য থাকে)। নতুন রিপোর্ট বা নতুন টাকার কলাম
 * drill ছাড়া এলে লাল।
 *
 * ⓘ নিজের ছাদ, ছাড়ের সাধারণ ছাদে নয় (fe): এটা পুরনো দেনার তালিকা, প্রথমবার গোনা — রিপোর্ট [[REPORT_CEILING]], কলাম
 * [[COLUMN_CEILING]]; দুইটাই কেবল কমে। মালিক মডিউল ধরে (accounts আর finance ec, sales a5/a6, purchase আর supplier cb; বাকি fe ভাগ করবেন)।
 * ⓘ ২৪ অক্টোবরে গোনা ১৩৫ রিপোর্ট, ৪৫৭ কলাম; প্রিন্সিপালের কমিশন একই কমিটে সারানো — তাই ১৩৪ আর ৪৫২।
 */
final class EveryReportFigureOpensItsSourceTest extends TestCase
{
    public const REPORT_CEILING = 134;

    public const COLUMN_CEILING = 452;

    /**
     * আজও drill ছাড়া টাকার কলাম — রিপোর্ট => [কতগুলো, মালিক]। ⓘ কেবল ছোট হবে।
     *
     * @var array<string, array{0: int, 1: string}>
     */
    public const KNOWN = [
        'accounts.asset_book_vs_tax' => [5, 'ec'],
        'accounts.asset_depreciation_schedule' => [1, 'ec'],
        'accounts.asset_disposals' => [5, 'ec'],
        'accounts.asset_fully_depreciated' => [2, 'ec'],
        'accounts.asset_maintenance' => [2, 'ec'],
        'accounts.asset_movement' => [12, 'ec'],
        'accounts.asset_nbv' => [3, 'ec'],
        'accounts.asset_register' => [3, 'ec'],
        'accounts.balance_sheet' => [3, 'ec'],
        'accounts.bank_book' => [3, 'ec'],
        'accounts.branch_dues' => [3, 'ec'],
        'accounts.branches_side_by_side' => [8, 'ec'],
        'accounts.by_cost_centre' => [3, 'ec'],
        'accounts.cash_book' => [3, 'ec'],
        'accounts.cash_flow' => [3, 'ec'],
        'accounts.cheque_register' => [1, 'ec'],
        'accounts.customer_ledger' => [3, 'ec'],
        'accounts.day_book' => [2, 'ec'],
        'accounts.expense_analysis' => [3, 'ec'],
        'accounts.expense_by_head' => [1, 'ec'],
        'accounts.income_by_head' => [1, 'ec'],
        'accounts.inflow' => [1, 'ec'],
        'accounts.ledger' => [3, 'ec'],
        'accounts.monthly_cash' => [14, 'ec'],
        'accounts.person_ledger' => [3, 'ec'],
        'accounts.profit_loss' => [4, 'ec'],
        'accounts.project_ledger' => [3, 'ec'],
        'accounts.supplier_ledger' => [3, 'ec'],
        'accounts.trial_balance' => [2, 'ec'],
        'approval.approved' => [1, 'fe ঠিক করবে'],
        'approval.pending' => [1, 'fe ঠিক করবে'],
        'approval.rejected' => [1, 'fe ঠিক করবে'],
        'credit_control.ageing' => [8, 'fe ঠিক করবে'],
        'credit_control.blocked' => [7, 'fe ঠিক করবে'],
        'credit_control.exposure_by_branch' => [4, 'fe ঠিক করবে'],
        'credit_control.exposure_by_salesperson' => [4, 'fe ঠিক করবে'],
        'credit_control.high_risk' => [2, 'fe ঠিক করবে'],
        'credit_control.limit_breaches' => [4, 'fe ঠিক করবে'],
        'credit_control.limit_requests' => [2, 'fe ঠিক করবে'],
        'credit_control.over_limit' => [7, 'fe ঠিক করবে'],
        'credit_control.overdue_bills' => [1, 'fe ঠিক করবে'],
        'credit_control.overdue_stopped' => [7, 'fe ঠিক করবে'],
        'credit_control.payment_behaviour' => [1, 'fe ঠিক করবে'],
        'credit_control.pending_requests' => [2, 'fe ঠিক করবে'],
        'credit_control.positions' => [7, 'fe ঠিক করবে'],
        'credit_control.temporary_limits' => [2, 'fe ঠিক করবে'],
        'credit_control.wall_evidence' => [4, 'fe ঠিক করবে'],
        'customer.ageing' => [7, 'fe ঠিক করবে'],
        'customer.collection' => [3, 'fe ঠিক করবে'],
        'customer.due_list' => [2, 'fe ঠিক করবে'],
        'customer.ledger_check' => [3, 'fe ঠিক করবে'],
        'customer.no_limit' => [1, 'fe ঠিক করবে'],
        'executive.profit_by_customer' => [5, 'fe ঠিক করবে'],
        'finance.bank_limit_usage' => [4, 'ec'],
        'finance.bank_loan_instalments' => [3, 'ec'],
        'finance.bank_loan_ledger' => [3, 'ec'],
        'finance.capital_changes' => [7, 'ec'],
        'finance.capital_ledger' => [3, 'ec'],
        'finance.capital_reconcile' => [6, 'ec'],
        'finance.capital_return' => [4, 'ec'],
        'finance.deposit_accrued' => [4, 'ec'],
        'finance.deposit_instalments' => [5, 'ec'],
        'finance.deposit_liens' => [3, 'ec'],
        'finance.hand_loan_activity' => [4, 'ec'],
        'finance.hand_loan_age_payable' => [5, 'ec'],
        'finance.hand_loan_age_receivable' => [5, 'ec'],
        'finance.hand_loan_ledger' => [3, 'ec'],
        'finance.hand_loan_payable' => [1, 'ec'],
        'finance.hand_loan_receivable' => [1, 'ec'],
        'finance.hand_loan_reconcile' => [3, 'ec'],
        'finance.hand_loan_schedule' => [2, 'ec'],
        'finance.insurance_claims' => [4, 'ec'],
        'finance.insurance_premiums' => [2, 'ec'],
        'finance.rental_advance' => [6, 'ec'],
        'finance.rental_contracts' => [2, 'ec'],
        'finance.rental_deposit_book' => [3, 'ec'],
        'finance.rental_schedule' => [5, 'ec'],
        'finance.tenancy_arrears' => [5, 'ec'],
        'finance.tenancy_collections' => [4, 'ec'],
        'governance.backdated' => [1, 'fe ঠিক করবে'],
        'inventory.count_vs_book' => [1, 'fe ঠিক করবে'],
        'inventory.expiring' => [1, 'fe ঠিক করবে'],
        'inventory.monthly_movement' => [4, 'fe ঠিক করবে'],
        'inventory.slow_dead' => [1, 'fe ঠিক করবে'],
        'inventory.stock_position' => [1, 'fe ঠিক করবে'],
        'inventory.stock_value' => [6, 'fe ঠিক করবে'],
        'promotion.active' => [1, 'fe ঠিক করবে'],
        'promotion.budgets' => [3, 'fe ঠিক করবে'],
        'promotion.by_customer' => [3, 'fe ঠিক করবে'],
        'promotion.by_product' => [3, 'fe ঠিক করবে'],
        'promotion.cancelled_offers' => [1, 'fe ঠিক করবে'],
        'promotion.discounts' => [1, 'fe ঠিক করবে'],
        'promotion.expired' => [1, 'fe ঠিক করবে'],
        'promotion.gift_stock' => [1, 'fe ঠিক করবে'],
        'promotion.gifts' => [2, 'fe ঠিক করবে'],
        'promotion.overrides' => [3, 'fe ঠিক করবে'],
        'promotion.reversals' => [1, 'fe ঠিক করবে'],
        'promotion.utilization' => [3, 'fe ঠিক করবে'],
        'purchase.analysis' => [5, 'cb'],
        'purchase.by_supplier' => [4, 'cb'],
        'purchase.match_exceptions' => [2, 'cb'],
        'purchase.payment_due' => [5, 'cb'],
        'purchase.price_analysis' => [5, 'cb'],
        'purchase.price_history' => [3, 'cb'],
        'purchase.register' => [1, 'cb'],
        'purchase.return_on_capital' => [6, 'cb'],
        'purchase.settlement' => [6, 'cb'],
        'purchase.uninvoiced' => [1, 'cb'],
        'restaurant.food_cost' => [2, 'fe ঠিক করবে'],
        'sales.by_brand' => [3, 'a5/a6'],
        'sales.by_channel' => [5, 'a5/a6'],
        'sales.by_customer' => [5, 'a5/a6'],
        'sales.by_product' => [4, 'a5/a6'],
        'sales.by_route' => [6, 'a5/a6'],
        'sales.by_salesperson' => [3, 'a5/a6'],
        'sales.collection_due' => [6, 'a5/a6'],
        'sales.collection_target' => [3, 'a5/a6'],
        'sales.credit_blocked' => [3, 'a5/a6'],
        'sales.credit_blocked_customers' => [2, 'a5/a6'],
        'sales.credit_limit_history' => [1, 'a5/a6'],
        'sales.credit_risk' => [3, 'a5/a6'],
        'sales.credit_use' => [4, 'a5/a6'],
        'sales.invoice_book' => [6, 'a5/a6'],
        'sales.margin' => [5, 'a5/a6'],
        'sales.monthly' => [7, 'a5/a6'],
        'sales.party_target' => [2, 'a5/a6'],
        'sales.party_target_areas' => [2, 'a5/a6'],
        'sales.register' => [1, 'a5/a6'],
        'sales.return_by_reason' => [3, 'a5/a6'],
        'sales.uninvoiced' => [1, 'a5/a6'],
        'supplier.ageing' => [6, 'cb'],
        'supplier.ledger_check' => [3, 'cb'],
        'supplier.payable_list' => [3, 'cb'],
        'supplier.payment_schedule' => [1, 'cb'],
    ];

    public function test_every_money_figure_in_every_report_opens_its_source(): void
    {
        $found = $this->gaps();

        foreach ($found as $key => $count) {
            $known = self::KNOWN[$key][0] ?? 0;
            $this->assertLessThanOrEqual($known, $count,
                "⛔ {$key}: {$count}টা টাকার কলামে চাপলে কিছু খোলে না (তালিকায় {$known}) — কলামে 'drill' দিন, হিসাব কোথা থেকে এল দেখাতে");
        }

        foreach (self::KNOWN as $key => [$known, $owner]) {
            $this->assertSame($known, $found[$key] ?? 0,
                "⛔ {$key}: KNOWN বলছে {$known}টা, আছে ".($found[$key] ?? 0)."টা — সারানো হলে তালিকা থেকেও কমাও ({$owner})");
        }
    }

    public function test_the_list_and_its_ceilings_only_shrink(): void
    {
        $this->assertLessThanOrEqual(self::REPORT_CEILING, count(self::KNOWN), '⛔ drill-হীন রিপোর্টের তালিকা ছাদ ছাড়াল — নতুন দেনা নয়, drill দিন');
        $this->assertLessThanOrEqual(self::COLUMN_CEILING, array_sum(array_column(self::KNOWN, 0)), '⛔ drill-হীন টাকার কলাম ছাদ ছাড়াল');
    }

    public function test_every_declared_drill_names_a_real_door(): void
    {
        $engine = app(ReportEngine::class);

        foreach ($engine->keys() as $key) {
            foreach ($engine->get($key)->columns as $column) {
                if ($column->hasDrill() && ! isset($column->drill['source'])) {
                    $this->assertTrue(Route::has($column->drill['route']), "⛔ {$key}.{$column->key}: drill-এর দরজা '{$column->drill['route']}' নেই");
                    if (isset($column->drill['total_route'])) {
                        $this->assertTrue(Route::has($column->drill['total_route']), "⛔ {$key}.{$column->key}: মোটের দরজা নেই");
                    }
                }
            }
        }

        $this->assertContains(\App\Modules\Supplier\Reports\PrincipalCommissionReport::KEY, $engine->keys(), 'পাহারাটাই রিপোর্ট দেখছে না');
    }

    /**
     * ⭐ যে অঙ্ক দেখা যায়, তার উৎসও রিপোর্টের নিজের চাবিতে খোলে (fe, ২৪ অক্টোবর ২০২৬)। ⓘ দরজার চাবি: পথের `can:` মিডলওয়্যার,
     * আর `slug` লেখা থাকলে কন্ট্রোলারের `SLUGS` ধরে সেই রিপোর্টের নিজের চাবি — প্রতিটা রিপোর্টের চাবির সমান হতে হবে।
     * ⓘ `source` ধরনের drill (ভাউচার) এখানে নয় — সেটা কাগজের নিজের নিয়মে খোলে ([[DrillResolver]])।
     */
    public function test_every_drill_opens_with_the_reports_own_key(): void
    {
        $engine = app(ReportEngine::class);
        $checked = 0;

        foreach ($engine->keys() as $key) {
            $report = $engine->get($key);

            foreach ($report->columns as $column) {
                if (! $column->hasDrill() || isset($column->drill['source'])) {
                    continue;
                }

                $doors = [[$column->drill['route'], $column->drill['params'] ?? []]];
                if (isset($column->drill['total'])) {
                    $doors[] = [$column->drill['total_route'] ?? $column->drill['route'], $column->drill['total']];
                }

                foreach ($doors as [$route, $params]) {
                    foreach ($this->keysOfTheDoor($route, $params) as $needed) {
                        $this->assertSame($report->permission, $needed,
                            "⛔ {$key}.{$column->key}: অঙ্ক দেখতে '{$report->permission}' লাগে, অথচ উৎসের দরজা ({$route}) চায় '{$needed}' — পাঠক ৪০৩ পেতেন");
                    }
                    $checked++;
                }
            }
        }

        $this->assertGreaterThan(0, $checked, 'পাহারা কোনো drill-ই দেখল না');
    }

    /**
     * @param  array<string, string>  $params
     * @return list<string>
     */
    private function keysOfTheDoor(string $route, array $params): array
    {
        $door = Route::getRoutes()->getByName($route);
        $this->assertNotNull($door, "⛔ drill-এর দরজা '{$route}' নেই");
        $keys = [];

        foreach ($door->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'can:') && ! str_contains($middleware, ',')) {
                $keys[] = substr($middleware, 4);
            }
        }

        $controller = $door->getControllerClass();
        $slug = isset($params['slug']) && str_starts_with($params['slug'], '=') ? substr($params['slug'], 1) : null;

        if ($controller !== null && $slug !== null && (new \ReflectionClass($controller))->hasConstant('SLUGS')) {
            $slugs = (new \ReflectionClassConstant($controller, 'SLUGS'))->getValue();
            $this->assertArrayHasKey($slug, $slugs, "⛔ drill-এর পাতা '{$slug}' দরজায় নেই — লিংক ৪০৪ দিত");
            if (($permission = app(ReportEngine::class)->get($slugs[$slug])->permission) !== null) {
                $keys[] = $permission;
            }
        }

        return array_values(array_unique($keys));
    }

    /** @return array<string, int>  রিপোর্ট => drill-হীন টাকার কলাম */
    private function gaps(): array
    {
        $engine = app(ReportEngine::class);
        $out = [];

        foreach ($engine->keys() as $key) {
            $n = 0;
            foreach ($engine->get($key)->columns as $column) {
                if (in_array($column->type, [ReportColumn::MONEY, ReportColumn::DR_CR], true) && ! $column->hasDrill()) {
                    $n++;
                }
            }
            if ($n > 0) {
                $out[$key] = $n;
            }
        }

        return $out;
    }
}
