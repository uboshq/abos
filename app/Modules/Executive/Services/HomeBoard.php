<?php

declare(strict_types=1);

namespace App\Modules\Executive\Services;

use App\Core\Contracts\HomeBoard as HomeBoardContract;
use App\Core\Contracts\StockDemand;
use App\Core\Dashboard\HomeFilter;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Security\FieldSecurity;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Core\Support\MoneyMethod;
use App\Core\Support\ViewedBranch;
use App\Models\User;
use App\Modules\Accounts\Dashboard\AccountsWidgets;
use App\Modules\Approval\Dashboard\ApprovalDashboard;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\CustomerMetrics;
use App\Modules\Inventory\Dashboard\InventoryBoard;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\StockFacts;
use App\Modules\Sales\Metrics\SalesAnalytics;
use App\Modules\Sales\Metrics\SalesMetrics;
use App\Modules\Sales\Models\Collection as SalesCollection;
use App\Modules\Sales\Models\CommissionClaim;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Services\PrincipalCommissionFigures;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

/**
 * ⭐ হোমের গোটা-ব্যবসার বোর্ড — মালিকের অনুমোদিত নমুনা, ১৯ নভেম্বর ২০২৬ (claude.ai artifact AAjAfjZ8TnCfnjtt3Kthjz v11; মালিকের
 * ছবি ২৩): "নিজ দিয়ে দেওয়ার কথা ছিল" — আগের হোমে যে তথ্য ছিল তা ফেরত, গ্রাফ আগে (মালিকের নিয়ম)।
 *
 * ── ⛔ এখানে কোনো সংখ্যার নতুন সংজ্ঞা নেই ──────────────────────────────
 * প্রতিটা সংখ্যা মডিউলের নিজের ক্লাস থেকে, যেটা তার নিজের ড্যাশবোর্ড বা রিপোর্টও পড়ে:
 *   · বিক্রি, শীর্ষ পণ্য/দোকান, বিল    [[SalesAnalytics]] / [[SalesMetrics]] — পাকা বিল, ফেরত আর VAT বাদে, শাখা ও ডিলারের দেয়াল,
 *                                         হোমের ছাঁকনি (গুদাম · এলাকা · SR) ভিতরে খাটে
 *   · আদায়                              [[SalesMetrics::collectionTotal()]] — আদায় + গ্রাহকের রসিদ ভাউচার, পাকা
 *   · বাকি, বাকির বয়স, পয়েন্ট ধরে        [[CustomerMetrics::dues()]] আর `customer.ageing` রিপোর্ট — চাবি `customer.report`
 *   · মজুদ                              [[StockFacts]] — মূল্য খাতা থেকে (১১২০), খরচের চাবি ছাড়া null
 *   · টাকা                              [[AccountsWidgets]] — নগদ, ব্যাংক, MFS, পথে; চাবি `accounts.till.view`
 *   · লাভ                               [[StockDemand::grossProfit()]] — চাবি `sales.cost.view` (a-board-must-keep-the-cost-key)
 *   · প্রিন্সিপালকে দেনা                 `supplier.ageing` রিপোর্ট; প্রিন্সিপালের কমিশন [[PrincipalCommissionFigures]]
 *   · প্রতিষ্ঠানের টেবিল                 [[Figures]] প্রতিটা কোম্পানির প্রসঙ্গে ([[CompanyLens::within()]]) — মালিকের কেন্দ্রের একই সংখ্যা
 *
 * ⓘ ছাঁকনি যেখানে খাটে না, ঘরটা সেটা নিজেই বলে (`note`) — চুপ করে "সব" দেখানো হয় না।
 * ⓘ খালি হলে `empty` — পর্দায় "এই সময়ে কিছু নেই।"; চাবি না থাকলে ঘরটাই নেই।
 */
final class HomeBoard implements HomeBoardContract
{
    /** গ্রাফের রং — নমুনার, প্রতিটা গ্রাফ নিজের রঙে */
    private const BLUE = '#2563eb';

    private const ORANGE = '#f97316';

    private const PURPLE = '#7c3aed';

    private const GREEN = '#16a34a';

    private const RED = '#dc2626';

    private const AMBER = '#f59e0b';

    private const PINK = '#db2777';

    private const TEAL = '#0891b2';

    private const YELLOW = '#eab308';

    /** দোকান "সক্রিয়" — শেষ এত দিনে অন্তত একটা পাকা বিল */
    public const ACTIVE_DAYS = 90;

    /** ধীরে চলা মজুদ — এত দিনে কিছুই বেরোয়নি ([[StockFacts::stagnantCount()]]) */
    public const SLOW_DAYS = 90;

    /** অনুমোদন "দুই দিনের বেশি অপেক্ষায়" */
    public const WAITING_DAYS = 2;

    public function __construct(
        private readonly SalesAnalytics $sales,
        private readonly CustomerMetrics $customers,
        private readonly ReportEngine $reports,
        private readonly CompanyLens $lens,
        private readonly Figures $figures,
    ) {}

    public function for(User $user, string $period): array
    {
        [$from, $to] = \App\Core\Dashboard\HomePeriod::window($period);
        [$prevFrom, $prevTo] = self::previous($period, $from, $to);
        $filter = HomeFilter::current();

        $can = fn (string $key): bool => $user->can($key);
        $costVisible = FieldSecurity::visible(StockMovement::class, 'unit_cost');

        // ── একবারই গোনা, কয়েক জায়গায় পড়া (N+1 নয়) ──
        $months = $can('sales.invoice.view') ? $this->sales->monthly(Carbon::today()) : null;
        $collectedByMonth = $can('sales.collection.view') ? $this->sales->monthlyCollection(Carbon::today()) : null;
        $ageing = $can('customer.report') ? $this->ageingRows('customer.ageing', $filter?->dueCustomers()) : null;
        $dues = $can('customer.report') ? $this->customers->dues($user, Carbon::today()->toDateString(), $filter?->dueCustomers()) : null;
        $stock = $costVisible && $can('inventory.stock.view') ? $this->stockByCategory($filter?->warehouse) : null;
        $money = $can('accounts.till.view') ? [
            'cash' => AccountsWidgets::cashInHand(),
            'bank' => AccountsWidgets::bankBalance(),
            'mfs' => AccountsWidgets::mfsBalance(),
        ] : null;

        return [
            'window' => [$from, $to],
            'compared' => [$prevFrom, $prevTo],
            'charts' => array_values(array_filter([
                $this->salesTrend($months),
                $this->salesAgainstCollection($months, $collectedByMonth, $filter),
                $this->duesAgeing($ageing),
                $this->stockChart($stock, $filter),
                $this->topProducts($can('sales.invoice.view'), $from, $to),
                $this->topShops($can('sales.invoice.view'), $from, $to),
                $this->byChannel($can('sales.collection.view'), $from, $to, $filter),
                $this->workingCapital($money, $stock, $dues, $filter),
            ])),
            'cards' => array_values(array_filter([
                $this->salesTile($can('sales.invoice.view'), $period, $from, $to, $prevFrom, $prevTo, $months),
                $this->collectionTile($can('sales.collection.view'), $period, $from, $to, $prevFrom, $prevTo, $collectedByMonth, $filter),
                $this->duesTile($dues),
                $this->overNinetyTile($ageing),
                $this->profitTile($can('sales.cost.view') && $can('sales.invoice.view'), $period, $from, $to, $prevFrom, $prevTo),
                $this->stockTile($costVisible && $can('inventory.stock.view'), $stock),
                $this->activeShopsTile($can('sales.invoice.view')),
                $this->billsTile($can('sales.invoice.view'), $period, $from, $to, $prevFrom, $prevTo, $dues),
            ])),
            'lists' => array_values(array_filter([
                $this->principals($can('supplier.report')),
                $this->commission($can('supplier.report'), $can('sales.commission.view')),
                $this->duesByPoint($ageing),
            ])),
            // ⓘ প্রতিষ্ঠানের টেবিল ছাঁকনির বাইরে — গুদাম বা এলাকার নম্বর এক কোম্পানির, অন্য কোম্পানিতে তার মানে নেই
            'companies' => HomeFilter::none()->during(fn () => $this->companies($user, $from, $to)),
            'todo' => $this->todo($user, $ageing),
        ];
    }

    // ── গ্রাফ ──────────────────────────────────────────────────────────

    /** @param  list<array<string, mixed>>|null  $months */
    private function salesTrend(?array $months): ?array
    {
        if ($months === null) {
            return null;
        }

        $values = array_map(fn (array $m): float => (float) $m['net'], $months);

        return [
            'key' => 'sales_trend',
            'title' => __('home.board.chart_sales_trend'),
            'sub' => __('home.board.last_12_months'),
            'href' => self::link('sales.invoice.index'),
            'type' => 'line',
            'colour' => self::BLUE,
            'labels' => array_map(fn (array $m): string => self::latin((string) $m['label']), $months),
            'series' => [['name' => __('home.board.sales'), 'colour' => self::BLUE, 'values' => $values]],
            'last' => self::short((string) end($values)),
            'empty' => array_sum($values) == 0.0,
        ];
    }

    /**
     * @param  list<array<string, mixed>>|null  $months
     * @param  array<string, string>|null  $collected
     */
    private function salesAgainstCollection(?array $months, ?array $collected, ?HomeFilter $filter): ?array
    {
        if ($months === null || $collected === null) {
            return null;
        }

        $sales = array_map(fn (array $m): float => (float) $m['net'], $months);
        $in = array_map(fn (array $m): float => (float) ($collected[$m['ym']] ?? 0), $months);

        return [
            'key' => 'sales_collection',
            'title' => __('home.board.chart_sales_collection'),
            'sub' => __('home.board.last_12_months'),
            'href' => self::link('sales.collection.index'),
            'type' => 'pair',
            'labels' => array_map(fn (array $m): string => self::latin((string) $m['label']), $months),
            'series' => [
                ['name' => __('home.board.sales'), 'colour' => self::BLUE, 'values' => $sales],
                ['name' => __('home.board.collection'), 'colour' => self::ORANGE, 'values' => $in],
            ],
            'empty' => array_sum($sales) + array_sum($in) == 0.0,
            // ⓘ আদায়ে গুদাম/এলাকা/SR-এর ছাঁকনি নেই ([[HomeFilter]]) — বিক্রির বার ছাঁকা, আদায়ের বার গোটা
            'note' => $filter?->active() ? __('home.board.note_collection_unfiltered') : null,
        ];
    }

    /** @param  list<array<string, mixed>>|null  $rows */
    private function duesAgeing(?array $rows): ?array
    {
        if ($rows === null) {
            return null;
        }

        $buckets = [
            'bucket_current' => [__('home.board.age_0_30'), self::GREEN],
            'bucket_30' => [__('home.board.age_31_60'), self::AMBER],
            'bucket_60' => [__('home.board.age_61_90'), self::ORANGE],
            'bucket_90' => [__('home.board.age_90_plus'), self::RED],
        ];

        $sums = array_fill_keys(array_keys($buckets), '0');
        $over = 0;
        foreach ($rows as $row) {
            foreach ($buckets as $key => $_) {
                $sums[$key] = bcadd($sums[$key], (string) ($row[$key] ?? '0'), 4);
            }
            $over += bccomp((string) ($row['bucket_90'] ?? '0'), '0', 4) > 0 ? 1 : 0;
        }

        $total = array_reduce($sums, fn (string $c, string $v) => bcadd($c, $v, 4), '0');

        return [
            'key' => 'dues_ageing',
            'title' => __('home.board.chart_dues_ageing'),
            'sub' => __('home.board.shops_over_90', ['count' => $over]),
            'href' => self::link('customer.report.show', ['slug' => 'ageing'] + self::branchParam()),
            'type' => 'donut',
            'centre' => self::short($total),
            'centre_label' => __('home.board.total_dues'),
            'parts' => self::parts(array_map(fn (string $key) => [
                'label' => $buckets[$key][0],
                'colour' => $buckets[$key][1],
                'amount' => $sums[$key],
            ], array_keys($buckets)), $total, false),
            'empty' => bccomp($total, '0', 4) <= 0,
        ];
    }

    /** @param  array{total: string, rows: list<array{name: string, value: string}>}|null  $stock */
    private function stockChart(?array $stock, ?HomeFilter $filter): ?array
    {
        if ($stock === null) {
            return null;
        }

        $colours = [self::BLUE, self::ORANGE, self::PURPLE, self::GREEN, self::YELLOW, self::PINK, self::TEAL, self::RED];
        $rows = array_slice($stock['rows'], 0, 8);

        return [
            'key' => 'stock_category',
            'title' => __('home.board.chart_stock_category'),
            'sub' => __('home.board.categories', ['count' => count($stock['rows'])]),
            'href' => self::link('inventory.stock.index'),
            'type' => 'donut',
            'centre' => self::short($stock['total']),
            'centre_label' => __('home.board.stock_value'),
            'parts' => self::parts(array_map(fn (array $r, int $i) => [
                'label' => $r['name'],
                'colour' => $colours[$i % count($colours)],
                'amount' => $r['value'],
            ], $rows, array_keys($rows)), $stock['total'], true),
            'empty' => bccomp($stock['total'], '0', 4) <= 0,
            // ⓘ গুদাম খাটে; এলাকা আর SR মজুদের কথা নয়
            'note' => $filter?->active() && ($filter->area !== null || $filter->seller !== null) ? __('home.board.note_stock_filter') : null,
        ];
    }

    private function topProducts(bool $allowed, string $from, string $to): ?array
    {
        if (! $allowed) {
            return null;
        }

        $rows = $this->sales->topProducts($from, $to);

        return [
            'key' => 'top_products',
            'title' => __('home.board.chart_top_products'),
            'sub' => __('home.board.sales_in_period'),
            'href' => self::link('sales.report.show', ['slug' => 'by-product', 'from' => $from, 'to' => $to]),
            'type' => 'bars',
            'colour' => self::PURPLE,
            'rows' => array_map(fn (array $r): array => [
                'label' => (string) $r['name'],
                'value' => (float) $r['amount'],
                'text' => self::short((string) $r['amount']),
            ], $rows),
            'empty' => $rows === [],
        ];
    }

    private function topShops(bool $allowed, string $from, string $to): ?array
    {
        if (! $allowed) {
            return null;
        }

        $rows = $this->sales->topCustomers($from, $to);

        return [
            'key' => 'top_shops',
            'title' => __('home.board.chart_top_shops'),
            'sub' => __('home.board.sales_in_period'),
            'href' => self::link('sales.invoice.index', ['from' => $from, 'to' => $to]),
            'type' => 'bars',
            'colour' => self::TEAL,
            'rows' => array_map(fn (array $r): array => [
                'label' => (string) $r['name'],
                'value' => (float) $r['amount'],
                'text' => self::short((string) $r['amount']),
                'href' => self::link('customer.show', ['customer' => $r['id']]),
            ], $rows),
            'empty' => $rows === [],
        ];
    }

    /**
     * ⭐ মাধ্যম ধরে আদায় — পাকা আদায়ের কাগজ, মাধ্যম (`instrument`) ধরে; নতুন উৎস নয়, `sal_collections`-এর একই সারি যা আদায়ের
     * তালিকা দেখায় (মডেলের শাখা ও ডিলারের দেয়ালসহ)। ⓘ গ্রাহকের রসিদ ভাউচার এখানে নেই — তার মাধ্যম ভাউচারের খাতে, আলাদা ঘরে।
     */
    private function byChannel(bool $allowed, string $from, string $to, ?HomeFilter $filter): ?array
    {
        if (! $allowed) {
            return null;
        }

        $sums = SalesCollection::query()
            ->whereIn('status', DocumentStatus::POSTED)
            ->whereBetween('trx_date', [$from, $to])
            ->groupBy('instrument')
            ->selectRaw('instrument, COALESCE(SUM(amount), 0) as total')
            ->toBase()
            ->pluck('total', 'instrument');

        $colours = ['cash' => self::AMBER, 'mfs' => self::PINK, 'transfer' => self::BLUE, 'deposit' => self::TEAL, 'cheque' => self::PURPLE];
        $total = '0';
        $parts = [];
        foreach ($sums as $method => $amount) {
            $method = (string) ($method ?: 'cash');
            $total = bcadd($total, (string) $amount, 4);
            $parts[] = [
                'label' => in_array($method, [...MoneyMethod::METHODS, ...MoneyMethod::LEGACY], true) ? __('money.method.'.$method) : $method,
                'colour' => $colours[$method] ?? self::RED,
                'amount' => (string) $amount,
            ];
        }
        usort($parts, fn (array $a, array $b) => bccomp($b['amount'], $a['amount'], 4));

        return [
            'key' => 'collection_channel',
            'title' => __('home.board.chart_channel'),
            'sub' => __('home.board.collection_in_period'),
            'href' => self::link('sales.collection.index', ['from' => $from, 'to' => $to]),
            'type' => 'donut',
            'centre' => self::short($total),
            'centre_label' => __('home.board.collection'),
            'parts' => self::parts($parts, $total, true),
            'empty' => bccomp($total, '0', 4) <= 0,
            'note' => $filter?->active() ? __('home.board.note_collection_unfiltered') : null,
        ];
    }

    /**
     * ⭐ খাটানো মূলধন — টাকা কোথায় আটকে আছে: নগদ+ব্যাংক+MFS, মজুদ, বাজারে বাকি। প্রতিটা ভাগ নিজের চাবিতে; যেটার চাবি নেই সেটা
     * ভাগেই নেই (গোপন সংখ্যা মোটের ভিতরে লুকিয়ে ঢোকে না)।
     *
     * @param  array{cash: string, bank: string, mfs: string}|null  $money
     * @param  array{total: string, rows: list<array<string, string>>}|null  $stock
     * @param  array{amount: string, shops: int, advance: string}|null  $dues
     */
    private function workingCapital(?array $money, ?array $stock, ?array $dues, ?HomeFilter $filter): ?array
    {
        $parts = [];

        if ($money !== null) {
            $parts[] = ['label' => __('home.board.wc_money'), 'colour' => self::BLUE,
                'amount' => bcadd(bcadd($money['cash'], $money['bank'], 4), $money['mfs'], 4)];
        }
        if ($stock !== null) {
            $parts[] = ['label' => __('home.board.stock_value'), 'colour' => self::PINK, 'amount' => $stock['ledger'] ?? $stock['total']];
        }
        if ($dues !== null) {
            $parts[] = ['label' => __('home.board.market_dues'), 'colour' => self::PURPLE, 'amount' => $dues['amount']];
        }

        if ($parts === []) {
            return null;
        }

        $total = array_reduce($parts, fn (string $c, array $p) => bcadd($c, $p['amount'], 4), '0');

        return [
            'key' => 'working_capital',
            'title' => __('home.board.chart_working_capital'),
            'sub' => __('home.board.where_money_sits'),
            'href' => self::link('accounts.custody'),
            'type' => 'donut',
            'centre' => self::short($total),
            'centre_label' => __('home.board.total'),
            'parts' => self::parts($parts, $total, false),
            'empty' => bccomp($total, '0', 4) == 0,
            'note' => $filter?->active() ? __('home.board.note_balances_unfiltered') : null,
        ];
    }

    // ── ঘর ──────────────────────────────────────────────────────────────

    /** @param  list<array<string, mixed>>|null  $months */
    private function salesTile(bool $allowed, string $period, string $from, string $to, string $pf, string $pt, ?array $months): ?array
    {
        if (! $allowed) {
            return null;
        }

        $now = SalesMetrics::net($from, $to);
        $before = SalesMetrics::net($pf, $pt);

        return self::tile('sales', __('home.board.tile_sales', ['period' => self::periodWord($period)]), '৳'.Money::format($now, 0),
            self::LINE_BLUE, self::link('sales.invoice.index', ['from' => $from, 'to' => $to]),
            self::delta($now, $before), self::comparedWord($period),
            $months === null ? [] : array_slice(array_map(fn (array $m) => (float) $m['net'], $months), -7));
    }

    /** @param  array<string, string>|null  $byMonth */
    private function collectionTile(bool $allowed, string $period, string $from, string $to, string $pf, string $pt, ?array $byMonth, ?HomeFilter $filter): ?array
    {
        if (! $allowed) {
            return null;
        }

        $now = SalesMetrics::collectionTotal($from, $to);
        $before = SalesMetrics::collectionTotal($pf, $pt);

        $tile = self::tile('collection', __('home.board.tile_collection', ['period' => self::periodWord($period)]), '৳'.Money::format($now, 0),
            self::LINE_ORANGE, self::link('sales.collection.index', ['from' => $from, 'to' => $to]),
            self::delta($now, $before), self::comparedWord($period),
            $byMonth === null ? [] : array_slice(array_map('floatval', array_values($byMonth)), -7));
        $tile['note'] = $filter?->active() ? __('home.board.note_collection_unfiltered') : null;

        return $tile;
    }

    /** @param  array{amount: string, shops: int, advance: string}|null  $dues */
    private function duesTile(?array $dues): ?array
    {
        if ($dues === null) {
            return null;
        }

        return self::tile('dues', __('home.board.market_dues'), '৳'.Money::format($dues['amount'], 0), self::LINE_PURPLE,
            self::link('customer.report.show', ['slug' => 'ageing'] + self::branchParam()), null,
            __('home.board.net_of_advance', ['amount' => '৳'.Money::format(bcsub($dues['amount'], $dues['advance'], 4), 0)]));
    }

    /** @param  list<array<string, mixed>>|null  $rows */
    private function overNinetyTile(?array $rows): ?array
    {
        if ($rows === null) {
            return null;
        }

        [$shops, $amount, $total] = self::overNinety($rows);

        return self::tile('over_90', __('home.board.tile_over_90'), '৳'.Money::format($amount, 0), self::LINE_RED,
            self::link('customer.report.show', ['slug' => 'ageing'] + self::branchParam()), null,
            __('home.board.over_90_sub', ['count' => $shops, 'pct' => self::pct($amount, $total)]));
    }

    /**
     * ⛔ লাভ কেবল খরচের চাবিতে (`sales.cost.view`) — a-board-must-keep-the-cost-key; চাবি না থাকলে ঘরটাই নেই।
     */
    private function profitTile(bool $allowed, string $period, string $from, string $to, string $pf, string $pt): ?array
    {
        if (! $allowed) {
            return null;
        }

        $demand = app(StockDemand::class);
        $now = (string) $demand->grossProfit($from, $to);
        $before = (string) $demand->grossProfit($pf, $pt);
        $net = SalesMetrics::net($from, $to);

        return self::tile('profit', __('home.board.tile_profit', ['period' => self::periodWord($period)]), '৳'.Money::format($now, 0),
            self::LINE_GREEN, self::link('module.dashboard', ['module' => 'sales']),
            self::delta($now, $before), self::comparedWord($period).' · '.__('home.board.margin', ['pct' => self::pct($now, $net)]));
    }

    /** @param  array{total: string, ledger?: ?string, rows: list<array<string, string>>}|null  $stock */
    private function stockTile(bool $allowed, ?array $stock): ?array
    {
        if (! $allowed || $stock === null) {
            return null;
        }

        return self::tile('stock', __('home.board.stock_value'), '৳'.Money::format($stock['ledger'] ?? $stock['total'], 0), self::LINE_PINK,
            self::link('inventory.stock.index'), null, __('home.board.categories', ['count' => count($stock['rows'])]));
    }

    private function activeShopsTile(bool $allowed): ?array
    {
        if (! $allowed) {
            return null;
        }

        $since = Carbon::today()->subDays(self::ACTIVE_DAYS - 1)->toDateString();
        $count = SalesInvoice::query()
            ->whereIn('status', DocumentStatus::POSTED)
            ->where('trx_date', '>=', $since)
            ->distinct()
            ->count('customer_id');

        return self::tile('active_shops', __('home.board.tile_active_shops'), (string) $count, self::LINE_TEAL,
            self::link('customer.index'), null, __('home.board.bought_in_days', ['days' => self::ACTIVE_DAYS]));
    }

    /** @param  array{amount: string, shops: int, advance: string}|null  $dues */
    private function billsTile(bool $allowed, string $period, string $from, string $to, string $pf, string $pt, ?array $dues): ?array
    {
        if (! $allowed) {
            return null;
        }

        $now = $this->sales->totals($from, $to)['count'];
        $before = $this->sales->totals($pf, $pt)['count'];

        // ⓘ আদায়ের গড় সময় (DSO) = আজকের বাকি ÷ শেষ ৯০ দিনের নিট বিক্রি × ৯০ — [[SalesBoard::collectionEffectiveness()]]-এর একই সূত্র
        $sub = self::comparedWord($period);
        if ($dues !== null) {
            $sold = SalesMetrics::net(Carbon::today()->subDays(89)->toDateString(), Carbon::today()->toDateString());
            if (bccomp($sold, '0', 4) > 0) {
                $sub .= ' · '.__('home.board.days_to_collect', ['days' => number_format((float) bcmul(bcdiv($dues['amount'], $sold, 6), '90', 4), 1, '.', '')]);
            }
        }

        return self::tile('bills', __('home.board.tile_bills', ['period' => self::periodWord($period)]), (string) $now, self::LINE_YELLOW,
            self::link('sales.invoice.index', ['from' => $from, 'to' => $to]), self::delta((string) $now, (string) $before), $sub);
    }

    // ── তালিকা ──────────────────────────────────────────────────────────

    /** ⭐ প্রিন্সিপালকে দেনা — `supplier.ageing`-এর সারি, বড়টা আগে, প্রতিটার পাশে ৯০ দিনের বেশি পুরনো অংশ */
    private function principals(bool $allowed): ?array
    {
        if (! $allowed) {
            return null;
        }

        $rows = $this->ageingRows('supplier.ageing', null) ?? [];
        usort($rows, fn (array $a, array $b) => bccomp((string) ($b['outstanding'] ?? '0'), (string) ($a['outstanding'] ?? '0'), 4));
        $total = array_reduce($rows, fn (string $c, array $r) => bcadd($c, (string) ($r['outstanding'] ?? '0'), 4), '0');
        $top = array_values(array_filter(array_slice($rows, 0, 5), fn (array $r) => bccomp((string) ($r['outstanding'] ?? '0'), '0', 4) > 0));

        $names = Supplier::query()->whereIn('id', array_map(fn (array $r) => (int) $r['party_id'], $top) ?: [0])->get()
            ->mapWithKeys(fn (Supplier $s) => [$s->id => $s->name()])->all();

        return [
            'key' => 'principals',
            'title' => __('home.board.list_principals'),
            'sub' => __('home.board.as_booked_total', ['amount' => '৳'.Money::format($total, 0)]),
            'href' => self::link('supplier.report.show', ['slug' => 'payable-list']),
            'link' => __('home.board.payable_list'),
            'rows' => array_map(fn (array $r, int $i) => [
                'name' => (string) ($names[(int) $r['party_id']] ?? '—'),
                'hint' => bccomp((string) ($r['bucket_90'] ?? '0'), '0', 4) > 0
                    ? __('home.board.over_90_amount', ['amount' => '৳'.Money::format((string) $r['bucket_90'], 0)]) : null,
                'amount' => '৳'.Money::format((string) $r['outstanding'], 0),
                'colour' => [self::BLUE, self::ORANGE, self::PURPLE, self::TEAL, self::GREEN][$i % 5],
                'href' => self::link('supplier.show', ['supplier' => (int) $r['party_id']]),
            ], $top, array_keys($top)),
        ];
    }

    /**
     * ⭐ Depot360 · কমিশন — প্রিন্সিপাল যে কমিশন দেবে ([[PrincipalCommissionFigures]], রিপোর্টের নিজের বাকি) আর ডিলারের কমিশন বকেয়া
     * (অপেক্ষার দাবি, [[CommissionClaim]])। প্রতিটা সারি নিজের চাবিতে।
     */
    private function commission(bool $principal, bool $dealer): ?array
    {
        $rows = [];
        $total = '0';

        if ($principal && ! PrincipalCommissionFigures::screenOff()) {
            $due = (string) (app(PrincipalCommissionFigures::class)->figures()['total']['balance'] ?? '0');
            $total = bcadd($total, $due, 4);
            $rows[] = ['name' => __('home.board.principal_commission'), 'hint' => null, 'amount' => '৳'.Money::format($due, 0),
                'colour' => self::GREEN, 'tint' => 'ok',
                'href' => self::link('supplier.report.show', ['slug' => 'principal-commission'])];
        }

        if ($dealer) {
            $due = (string) CommissionClaim::query()->where('status', CommissionClaim::PENDING)->sum('amount');
            $total = bcadd($total, $due, 4);
            $rows[] = ['name' => __('home.board.dealer_commission'), 'hint' => null, 'amount' => '৳'.Money::format($due, 0),
                'colour' => self::YELLOW, 'tint' => 'week', 'href' => self::link('sales.commission.index')];
        }

        if ($rows === []) {
            return null;
        }

        return [
            'key' => 'commission',
            'title' => __('home.board.list_commission'),
            'sub' => __('home.board.as_booked_total', ['amount' => '৳'.Money::format($total, 0)]),
            'href' => $rows[0]['href'],
            'link' => __('home.details'),
            'rows' => $rows,
        ];
    }

    /**
     * ⭐ পয়েন্ট ধরে বকেয়া, শীর্ষ ৫ — বাকির বয়সের একই সারি, দোকানের পয়েন্টে জড়ো ([[Customer::pointName()]]); এক কোয়েরিতে সব দোকান।
     *
     * @param  list<array<string, mixed>>|null  $rows
     */
    private function duesByPoint(?array $rows): ?array
    {
        if ($rows === null) {
            return null;
        }

        $owing = array_filter($rows, fn (array $r) => bccomp((string) ($r['outstanding'] ?? '0'), '0', 4) > 0);
        $customers = Customer::query()->with(Customer::PLACE)
            ->whereIn('id', array_map(fn (array $r) => (int) $r['party_id'], $owing) ?: [0])->get()->keyBy('id');

        $points = [];
        $total = '0';
        foreach ($owing as $r) {
            $name = $customers->get((int) $r['party_id'])?->pointName() ?? __('home.board.no_point');
            $points[$name] ??= ['amount' => '0', 'shops' => 0];
            $points[$name]['amount'] = bcadd($points[$name]['amount'], (string) $r['outstanding'], 4);
            $points[$name]['shops']++;
            $total = bcadd($total, (string) $r['outstanding'], 4);
        }
        uasort($points, fn (array $a, array $b) => bccomp($b['amount'], $a['amount'], 4));
        $top = array_slice($points, 0, 5, true);
        $tints = [['urgent', self::RED], ['today', self::ORANGE], ['week', self::YELLOW], ['info', self::TEAL], ['info', self::BLUE]];

        return [
            'key' => 'points',
            'title' => __('home.board.list_points'),
            'sub' => __('home.board.top_5_total', ['amount' => '৳'.Money::format($total, 0)]),
            'href' => self::link('customer.report.show', ['slug' => 'ageing'] + self::branchParam()),
            'link' => __('home.board.see_all'),
            'rows' => array_map(fn (string $name, array $p, int $i) => [
                'name' => $name.' · '.__('home.board.shop_count', ['count' => $p['shops']]),
                'hint' => null,
                'amount' => '৳'.Money::format($p['amount'], 0),
                'colour' => $tints[$i][1],
                'tint' => $tints[$i][0],
                'href' => null,
            ], array_keys($top), array_values($top), array_keys(array_values($top))),
        ];
    }

    // ── সব প্রতিষ্ঠান ───────────────────────────────────────────────────

    /**
     * ⭐ "আপনার সব প্রতিষ্ঠান" — যে কোম্পানিগুলোতে মালিকের কেন্দ্রের চাবি আছে ([[CompanyLens::companies()]]), প্রতিটার এক লাইন,
     * নিচে মোট; প্রতিটা সংখ্যা [[Figures::value()]] — মালিকের কেন্দ্রের হুবহু, ঐ কোম্পানির প্রসঙ্গে। চাবি না থাকলে কেবল চলতি কোম্পানি।
     * ⓘ পাঁচ মিনিট মনে রাখা — মালিকের কেন্দ্রের মতোই ([[Board::CACHE_SECONDS]])।
     */
    private function companies(User $user, string $from, string $to): ?array
    {
        $keys = [Figures::SALES, Figures::COLLECTIONS, Figures::RECEIVABLE, Figures::STOCK, Figures::FUND, Figures::PROFIT];
        $current = (int) ($user->current_company_id ?? 0);
        $list = $user->can(CompanyLens::KEY) ? $this->lens->companies($user) : [];

        if ($list === []) {
            $company = $user->currentCompany;
            if ($company === null) {
                return null;
            }
            $list = [['id' => (int) $company->id, 'name' => $company->name()]];
        }

        $rows = [];
        $total = array_fill_keys($keys, '0');
        $hidden = [];
        $branchTotal = 0;

        foreach ($list as $company) {
            $id = (int) $company['id'];
            $only = $id === $current ? ViewedBranch::one($user) : null;
            $cacheKey = implode(':', ['home-board', 'u'.$user->id, 'c'.$id, 'b'.($only ?? 'all'), $from, $to, app()->getLocale()]);

            $values = Cache::remember($cacheKey, Board::CACHE_SECONDS, fn () => $id === $current
                ? $this->valuesHere($user, $keys, $from, $to)
                : $this->lens->within($user, $id, null, fn () => $this->valuesHere($user, $keys, $from, $to)));

            $branches = $user->can(CompanyLens::KEY) ? count($this->lens->branches($user, $id)) : (int) ($user->currentCompany?->branches()->count() ?? 0);
            $branchTotal += $branches;

            foreach ($keys as $key) {
                if ($values[$key] === null) {
                    $hidden[$key] = true;
                } else {
                    $total[$key] = bcadd($total[$key], $values[$key], 4);
                }
            }

            $rows[] = [
                'id' => $id,
                'name' => (string) $company['name'],
                'here' => $id === $current,
                // ⓘ কোম্পানি বদল POST ([[WorkspaceController::switchCompany()]]) — তাই অন্য প্রতিষ্ঠানের নাম মালিকের কেন্দ্রে নামে, সেখানে সব আছে
                'href' => $id === $current ? null : self::link('executive.today'),
                'branches' => $branches,
                'values' => array_map(fn (?string $v) => $v === null ? Figures::HIDDEN : '৳'.Money::format($v), $values),
            ];
        }

        return [
            'rows' => $rows,
            'total' => [
                'branches' => $branchTotal,
                'values' => array_map(fn (string $key) => isset($hidden[$key]) ? Figures::HIDDEN : '৳'.Money::format($total[$key]), array_combine($keys, $keys)),
            ],
            'keys' => $keys,
            'href' => self::link('executive.today'),
        ];
    }

    /** @return array<string, ?string> */
    private function valuesHere(User $user, array $keys, string $from, string $to): array
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $this->figures->value($user, $key, $from, $to);
        }

        return $out;
    }

    // ── আজ যা করতে হবে ───────────────────────────────────────────────────

    /** @param  list<array<string, mixed>>|null  $ageing */
    private function todo(User $user, ?array $ageing): array
    {
        $items = [];

        if ($ageing !== null) {
            [$shops, $amount] = self::overNinety($ageing);
            $items[] = ['key' => 'over_90', 'count' => $shops, 'level' => 'urgent', 'label' => __('home.board.todo_over_90'),
                'hint' => __('home.board.todo_over_90_hint', ['amount' => '৳'.Money::format($amount, 0), 'count' => $shops]),
                'href' => self::link('customer.report.show', ['slug' => 'ageing'] + self::branchParam())];
        }

        if ($user->can('inventory.stock.view')) {
            $items[] = ['key' => 'out_of_stock', 'count' => app(StockFacts::class)->outOfStock(), 'level' => 'urgent',
                'label' => __('home.board.todo_out_of_stock'), 'hint' => __('home.board.todo_out_of_stock_hint'),
                'href' => self::link('inventory.stock.index', ['sort' => 'available'])];
        }

        if ($user->can('inventory.report')) {
            $items[] = ['key' => 'slow', 'count' => app(StockFacts::class)->stagnantCount(self::SLOW_DAYS), 'level' => 'today',
                'label' => __('home.board.todo_slow'), 'hint' => __('home.board.todo_slow_hint', ['days' => self::SLOW_DAYS]),
                'href' => self::link('inventory.report.show', ['slug' => 'slow-dead'])];
        }

        if ($user->can('approval.decide') || $user->can('approval.view')) {
            $items[] = ['key' => 'approvals', 'level' => 'week', 'label' => __('home.board.todo_approvals', ['days' => self::WAITING_DAYS]),
                'count' => ApprovalDashboard::pendingSeen()->where('approvals.requested_at', '<', now()->subDays(self::WAITING_DAYS))->count(),
                'hint' => __('home.board.todo_approvals_hint'), 'href' => self::link('approval.inbox.index')];
        }

        return $items;
    }

    // ── উৎস ─────────────────────────────────────────────────────────────

    /**
     * বাকির বয়সের রিপোর্টের সব সারি (গ্রাহক বা সরবরাহকারী) — শাখার দেয়াল রিপোর্টের নিজের ([[ReportEngine]]); এলাকার ছাঁকনি থাকলে
     * কেবল সেই এলাকার দোকান।
     *
     * @param  list<int>|null  $only
     * @return list<array<string, mixed>>|null
     */
    private function ageingRows(string $key, ?array $only): ?array
    {
        if (! in_array($key, $this->reports->keys(), true)) {
            return null;
        }

        $rows = array_map(fn ($r) => (array) $r, $this->reports->run($key,
            ['to' => Carbon::today()->toDateString(), 'branch_id' => ViewedBranch::one()], 1, 100000)->rows);

        if ($only !== null) {
            $rows = array_values(array_filter($rows, fn (array $r) => in_array((int) ($r['party_id'] ?? 0), $only, true)));
        }

        return $rows;
    }

    /**
     * ⭐ ক্যাটাগরি ধরে মজুদ — [[StockFacts::snapshot()]]-এর প্রতি পণ্যের ছবি (গুদামের দেয়াল), দাম [[InventoryBoard::valueOf()]]
     * (চালু স্তরের গড় খরচ); মোট খাতার [[StockFacts::value()]] — ঘর আর খাটানো মূলধনে খাতার সংখ্যাই। ⛔ খরচের চাবি ছাড়া ডাকা হয় না।
     *
     * @return array{total: string, ledger: ?string, rows: list<array{name: string, value: string}>}
     */
    private function stockByCategory(?int $warehouse): array
    {
        $facts = app(StockFacts::class)->forWarehouse($warehouse);
        $byCategory = [];
        $sum = '0';

        $names = [];
        $bn = app()->getLocale() === 'bn';

        // ⓘ ক্যাটাগরির নাম পণ্যের নিজের সম্পর্ক থেকে, এক কোয়েরিতে (N+1 নয়)
        foreach ($facts->snapshot()->load('categoryRow') as $product) {
            $qty = (string) ($product->getAttribute('on_hand_qty') ?? '0');
            if (bccomp($qty, '0', 4) <= 0) {
                continue;
            }
            $value = InventoryBoard::valueOf($product, $qty);
            $id = (int) ($product->category_id ?? 0);
            $byCategory[$id] = bcadd($byCategory[$id] ?? '0', $value, 4);
            $sum = bcadd($sum, $value, 4);

            $category = $product->categoryRow;
            if ($category !== null && ! isset($names[$id])) {
                $names[$id] = $bn ? ($category->name_bn ?: $category->name_en) : ($category->name_en ?: $category->name_bn);
            }
        }

        arsort($byCategory, SORT_NUMERIC);

        $rows = [];
        foreach ($byCategory as $id => $value) {
            $rows[] = ['name' => (string) ($names[$id] ?? __('home.board.no_category')), 'value' => $value];
        }

        return ['total' => $sum, 'ledger' => $warehouse === null ? app(StockFacts::class)->value() : null, 'rows' => $rows];
    }

    // ── সাহায্য ─────────────────────────────────────────────────────────

    private const LINE_BLUE = self::BLUE;

    private const LINE_ORANGE = self::ORANGE;

    private const LINE_PURPLE = self::PURPLE;

    private const LINE_RED = self::RED;

    private const LINE_GREEN = self::GREEN;

    private const LINE_PINK = self::PINK;

    private const LINE_TEAL = self::TEAL;

    private const LINE_YELLOW = self::YELLOW;

    /** @param  list<float>  $spark */
    private static function tile(string $key, string $label, string $value, string $tone, string $href, ?array $delta, ?string $sub, array $spark = []): array
    {
        return ['key' => $key, 'label' => $label, 'value' => $value, 'tone' => $tone, 'href' => $href,
            'delta' => $delta, 'sub' => $sub, 'spark' => $spark, 'note' => null];
    }

    /**
     * আগের একই দিনগুলো — মাস হলে গত মাসের ১ তারিখ থেকে আজকের তারিখ পর্যন্ত, আজ হলে গত মাসের এই তারিখ, বছর হলে গত বছরের একই দিনগুলো।
     * ⛔ `subMonthNoOverflow` — ৩১ তারিখে `subMonth()` এই মাসেই থেকে যেত (last-month-on-the-31st-is-this-month)।
     *
     * @return array{0: string, 1: string}
     */
    private static function previous(string $period, string $from, string $to): array
    {
        $f = Carbon::parse($from);
        $t = Carbon::parse($to);

        if ($period === 'year') {
            return [$f->copy()->subYearNoOverflow()->toDateString(), $t->copy()->subYearNoOverflow()->toDateString()];
        }

        return [$f->copy()->subMonthNoOverflow()->toDateString(), $t->copy()->subMonthNoOverflow()->toDateString()];
    }

    /** @return array{up: bool, text: string}|null আগের সময়ের তুলনায় কত শতাংশ — আগেরটা শূন্য হলে তুলনা নেই */
    private static function delta(string $now, string $before): ?array
    {
        if (bccomp($before, '0', 4) === 0) {
            return null;
        }

        $change = bcmul(bcdiv(bcsub($now, $before, 4), $before, 6), '100', 4);

        return ['up' => bccomp($change, '0', 4) >= 0, 'text' => number_format(abs((float) $change), 1, '.', '').'%'];
    }

    private static function comparedWord(string $period): string
    {
        return $period === 'year' ? __('home.board.vs_last_year') : __('home.board.vs_last_month');
    }

    private static function periodWord(string $period): string
    {
        return match ($period) {
            'month' => __('core.dashboard.this_month'),
            'year' => __('home.board.this_fy'),
            default => __('core.dashboard.today'),
        };
    }

    /** @param  list<array<string, mixed>>  $rows  @return array{0: int, 1: string, 2: string} দোকান, ৯০+ টাকা, মোট বাকি */
    private static function overNinety(array $rows): array
    {
        $shops = 0;
        $amount = '0';
        $total = '0';
        foreach ($rows as $r) {
            $over = (string) ($r['bucket_90'] ?? '0');
            $total = bcadd($total, (string) ($r['outstanding'] ?? '0'), 4);
            if (bccomp($over, '0', 4) > 0) {
                $shops++;
                $amount = bcadd($amount, $over, 4);
            }
        }

        return [$shops, $amount, $total];
    }

    /**
     * ডোনাটের ভাগ — টাকা, ভাগ (%), ছোট লেখা।
     *
     * @param  list<array{label: string, colour: string, amount: string}>  $parts
     * @return list<array{label: string, colour: string, value: float, text: string, share: string}>
     */
    private static function parts(array $parts, string $total, bool $short): array
    {
        return array_map(fn (array $p) => [
            'label' => $p['label'],
            'colour' => $p['colour'],
            'value' => max(0.0, (float) $p['amount']),
            'text' => $short ? self::short($p['amount']) : '৳'.Money::format($p['amount'], 0),
            'share' => self::pct($p['amount'], $total),
        ], $parts);
    }

    private static function pct(string $part, string $whole): string
    {
        if (bccomp($whole, '0', 4) <= 0) {
            return '0%';
        }

        $p = (float) bcmul(bcdiv($part, $whole, 8), '100', 4);

        return ($p > 0 && $p < 1 ? number_format($p, 1, '.', '') : (string) round($p)).'%';
    }

    /** ছোট টাকা — "৳12.12 লাখ", "৳13.23 কোটি", "৳44 হাজার", "৳881" (নমুনার রূপ; অঙ্ক ইংরেজি) */
    public static function short(string $amount): string
    {
        $n = (float) $amount;
        $abs = abs($n);
        $trim = fn (float $v): string => rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');

        return '৳'.match (true) {
            $abs >= 10000000 => $trim($n / 10000000).' '.__('home.crore'),
            $abs >= 100000 => $trim($n / 100000).' '.__('home.lakh'),
            $abs >= 1000 => $trim($n / 1000).' '.__('home.board.thousand'),
            default => Money::format($n, 0),
        };
    }

    /** ⓘ পর্দায় অঙ্ক ইংরেজি (মালিক, ১৬ নভেম্বর ২০২৬) — মাসের নামের সাথে Carbon বাংলা অঙ্ক বসায় */
    private static function latin(string $text): string
    {
        return strtr($text, ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']);
    }

    /** @return array<string, int> হেডারে এক শাখা বাছা থাকলে রিপোর্টের লিংকেও সেটা */
    private static function branchParam(): array
    {
        $one = ViewedBranch::one();

        return $one === null ? [] : ['branch_id' => $one];
    }

    /** লিংক — রুট না থাকলে "#" নয়, ঘরটা লিংকহীন (`''`) */
    private static function link(string $route, array $params = [], array $query = []): string
    {
        if (! Route::has($route)) {
            return '';
        }

        try {
            return route($route, $params).($query === [] ? '' : '?'.http_build_query($query));
        } catch (\Throwable) {
            return '';
        }
    }
}
