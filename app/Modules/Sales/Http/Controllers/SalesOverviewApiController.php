<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Support\Money;
use App\Http\Controllers\Controller;
use App\Modules\Sales\Dashboard\SalesOverview;
use App\Modules\Sales\Metrics\SalesAnalytics;
use App\Modules\Sales\Metrics\SalesPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * ⭐ "বিক্রয়ের হালচাল" ফোনে, ফোনের নিজের ঢঙে — মালিক, ১৭ অক্টোবর ২০২৬: *"mobile er ta mobile app style e zate bujazay sei
 * vabe koro, direct webpage app e bujajayna"*।
 *
 * ⓘ সংখ্যা ওয়েবের পাতার একই সেবা থেকে ([[SalesOverview::for()]], [[SalesOverviewController]]), একই চাবি `sales.report`; ফোনে
 * নতুন হিসাব নেই। ⭐ উত্তরের আকার মডিউলের ড্যাশবোর্ডের ([[DashboardApiController::show()]]) — stats · panels · listings — যাতে
 * ফোনের একই পর্দা দুটোই আঁকে। ⓘ কার্ডের নিজের চাবি সেবাই দেখে; চাবি না থাকলে কার্ডটাই আসে না।
 */
final class SalesOverviewApiController extends Controller implements HasMiddleware
{
    /** একটা তালিকার সর্বোচ্চ সারি — ড্যাশবোর্ডের দরজার একই সীমা */
    private const MAX_ROWS = 20;

    public function __construct(private readonly SalesOverview $overview) {}

    public static function middleware(): array
    {
        return [new Middleware('can:sales.report')];
    }

    /** `GET /sales/overview?period=today|month|year|custom&from=&to=` */
    public function __invoke(Request $request): JsonResponse
    {
        $period = SalesPeriod::fromQuery($request->query());
        $board = $this->overview->for($request->user(), $period);
        $panels = $board['panels'];
        $totals = $board['totals'];

        return response()->json([
            'module' => 'sales_overview',
            'title' => __('sales::overview.title'),
            'subtitle' => __('sales::overview.showing', ['from' => $period->from, 'to' => $period->to]),
            'period' => [
                'kind' => $period->kind,
                'from' => $period->from,
                'to' => $period->to,
                'refused' => $period->refused ? __('sales::overview.range_refused', ['days' => SalesPeriod::MAX_DAYS]) : null,
            ],
            'stats' => array_map(fn (array $card) => [
                'label' => (string) $card['label'],
                'value' => (string) $card['value'],
                'hidden' => false,
                'hint' => (string) ($card['hint'] ?? ''),
                'tone' => in_array($card['tone'] ?? null, ['good', 'warn', 'bad'], true) ? $card['tone'] : 'neutral',
                'previous' => null,
                'previousLabel' => null,
            ], $board['cards']),
            'panels' => array_values(array_filter([
                isset($panels['daily']) ? $this->breakdown(
                    __('sales::overview.panel.daily', ['days' => SalesAnalytics::DAYS]),
                    array_map(fn (array $d) => ['label' => $d['label'], 'value' => $d['value']], $panels['daily']),
                ) : null,
                isset($panels['monthly']) ? $this->monthly($panels['monthly']) : null,
                isset($panels['return_trend']) ? $this->breakdown(
                    __('sales::overview.panel.return_trend'),
                    array_map(fn (array $m) => ['label' => $m['label'], 'value' => $m['amount']], $panels['return_trend']),
                ) : null,
            ])),
            'listings' => array_values(array_filter([
                isset($panels['top_customers']) ? $this->listing(__('sales::overview.panel.top_customers'), $panels['top_customers'], ['name', 'count', 'net', 'share'], $totals['top_customers'] ?? null) : null,
                isset($panels['top_products']) ? $this->listing(__('sales::overview.panel.top_products'), $panels['top_products'], ['product', 'qty', 'revenue'], $totals['top_products'] ?? null) : null,
                isset($panels['slow_products']) ? $this->listing(__('sales::overview.panel.slow_products'), $panels['slow_products'], ['product', 'qty', 'revenue'], $totals['slow_products'] ?? null) : null,
                isset($panels['by_seller']) ? $this->listing(__('sales::overview.panel.by_seller'), $panels['by_seller'], ['name', 'count', 'net', 'share'], $totals['by_seller'] ?? null) : null,
                isset($panels['by_territory']) ? $this->listing(
                    __('sales::overview.panel.by_territory', ['level' => __('master_data::level.'.$panels['by_territory']['level'])]),
                    $panels['by_territory']['rows'], ['name', 'count', 'net', 'share'], $totals['by_territory'] ?? null,
                ) : null,
                isset($panels['by_branch']) ? $this->listing(__('sales::overview.panel.by_branch'), $panels['by_branch'], ['name', 'count', 'net', 'share'], $totals['by_branch'] ?? null) : null,
            ])),
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * @param  list<array{label: string, value: string}>  $parts
     * @return array<string, mixed>
     */
    private function breakdown(string $label, array $parts): array
    {
        return [
            'kind' => 'breakdown', 'label' => $label, 'chart' => 'bar', 'hint' => null,
            'parts' => array_map(fn (array $p) => ['label' => (string) $p['label'], 'value' => Money::format((string) $p['value']), 'tone' => null], $parts),
        ];
    }

    /**
     * বারো মাস — নিট বিক্রয়, আর আদায়ের চাবি থাকলে পাশে আদায় (ওয়েবের তালিকার একই দুই কলাম)।
     *
     * @param  array{rows: list<array<string, mixed>>, collected: ?array<string, string>}  $monthly
     * @return array<string, mixed>
     */
    private function monthly(array $monthly): array
    {
        if ($monthly['collected'] === null) {
            return $this->breakdown(__('sales::overview.panel.monthly'),
                array_map(fn (array $m) => ['label' => $m['label'], 'value' => $m['net']], $monthly['rows']));
        }

        return [
            'kind' => 'series', 'label' => __('sales::overview.panel.monthly'), 'chart' => 'bar',
            'firstLabel' => __('sales::overview.col.net'), 'secondLabel' => __('sales::overview.col.collected'),
            'points' => array_map(fn (array $m) => [
                'label' => (string) $m['label'],
                'first' => Money::format((string) $m['net']),
                'second' => Money::format((string) ($monthly['collected'][$m['ym']] ?? '0')),
            ], $monthly['rows']),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $columns  `sales::overview.col.*` — প্রথমটা সারির নাম
     * @param  array{whole: bool, count: ?int, qty: ?string, amount: string, share: ?string}|null  $total  [[SalesOverview::totals()]] — ওয়েবের একই যোগের সারি
     * @return array<string, mixed>
     */
    private function listing(string $label, array $rows, array $columns, ?array $total = null): array
    {
        $value = fn (array $row, string $column): string => match ($column) {
            'name', 'product' => (string) ($row['name'] ?? ''),
            'count' => (string) ($row['count'] ?? 0),
            'qty' => Money::quantity((string) ($row['qty'] ?? '0')),
            'amount', 'net', 'revenue' => Money::format((string) ($row['amount'] ?? '0')),
            'share' => ($row['share'] ?? null) === null ? '' : $row['share'].'%',
            default => '',
        };

        return [
            'label' => $label,
            'empty' => __('sales::overview.empty'),
            'columns' => array_map(fn (string $c) => ['key' => $c, 'label' => __('sales::overview.col.'.$c)], $columns),
            'rows' => array_map(fn (array $row) => array_map(fn (string $c) => $value($row, $c), $columns),
                array_slice($rows, 0, self::MAX_ROWS)),
            /*
             * ⭐ যোগের সারি — কলামের চাবি → লেখা ([[DashboardApiController::listing()]]-এর একই `total`); প্রথম ঘরে "মোট" বা
             * "এই সারিগুলোর যোগ" (fe, ২১ অক্টোবর ২০২৬)। ⓘ ফোন নিজে কিছু যোগ করে না।
             */
            ...($total === null ? [] : ['total' => array_combine($columns, array_map(fn (string $c, int $i) => $i === 0
                ? (string) __($total['whole'] ? 'sales::overview.total_all' : 'sales::overview.total_shown')
                : $value(['count' => $total['count'], 'qty' => $total['qty'], 'amount' => $total['amount'], 'share' => $total['share']], $c),
                $columns, array_keys($columns)))]),
        ];
    }
}
