<?php

declare(strict_types=1);

namespace App\Modules\Sales\Dashboard;

use App\Core\Metrics\Metric;
use App\Core\Support\Money;
use App\Models\User;
use App\Modules\Sales\Metrics\SalesAnalytics;
use App\Modules\Sales\Metrics\SalesMetrics;
use App\Modules\Sales\Metrics\SalesPeriod;

/**
 * বিক্রয়ের পর্দা — কোন কার্ড কে দেখবেন, আর কোন সংখ্যা কোথা থেকে (NEXUS §৪)।
 *
 * ── ⛔ চাবি নেই মানে কার্ডটাই নেই ─────────────────────────────────────
 * মডিউলের ড্যাশবোর্ড ([[DashboardEngine::allowed()]]) সংখ্যা **ঢাকে**
 * (`••••`) — ওখানে কাঠামো এক রাখাই চাওয়া। এই পর্দা ফোনের নিয়ম মানে
 * ([[DashboardTodayController]], চুক্তির নিয়ম ক): চাবি না থাকলে কার্ডটা
 * আসেই না, আর তার কোয়েরিটাও চলে না। ⓘ ফলে যিনি আদায় দেখতে পারেন না,
 * তাঁর পাতা আদায়ের বারোটা মাসিক যোগফলও গোনে না।
 *
 * ── চাবিগুলো ────────────────────────────────────────────────────────
 *   বিক্রয়, বিল, দাম-ছাড়-ভ্যাট, ভাগ, ধারা   sales.invoice.view  (ফোন ও হোম পর্দার একই)
 *   আদেশ, মাল যায়নি                     sales.order.view
 *   ফেরত, ফেরতের ধারা                    sales.return.view
 *   আদায়                                sales.collection.view
 *   বাজারে পাওনা                         customer.report     (ফোনের `dues`-এর একই)
 *   বাকি পড়ল (বিল − আদায়)                sales.invoice.view **আর** sales.collection.view
 *   টার্গেটের অর্জন                       sales.target.view
 */
final class SalesOverview
{
    public const INVOICE = 'sales.invoice.view';

    public const ORDER = 'sales.order.view';

    public const RETURN = 'sales.return.view';

    public const COLLECTION = 'sales.collection.view';

    public const DUES = 'customer.report';

    public const TARGET = 'sales.target.view';

    public function __construct(private readonly SalesAnalytics $analytics) {}

    /**
     * @return array{cards: list<array<string, mixed>>, panels: array<string, mixed>}
     */
    public function for(User $user, SalesPeriod $period): array
    {
        $can = fn (string ...$keys): bool => collect($keys)->every(fn (string $k) => $user->can($k));
        $from = $period->from;
        $to = $period->to;
        $range = ['from' => $from, 'to' => $to];

        $cards = [];

        /* ⓘ আজ · মাস · বছর — হোম পর্দার একই তিনটা মেট্রিক, সময়কাল যা-ই বাছা হোক */
        foreach ([SalesMetrics::salesToday(), SalesMetrics::salesThisMonth(), SalesMetrics::salesThisYear()] as $metric) {
            if ($can($metric->permission)) {
                $cards[] = self::metricCard($metric);
            }
        }

        if ($can(self::INVOICE)) {
            $totals = $this->analytics->totals($from, $to);
            $direct = $this->analytics->directSales($from, $to);

            $cards[] = self::card('invoices', (string) $totals['count'], (string) $totals['count'],
                route('sales.invoice.index', $range), count: true);
            $cards[] = self::card('direct', $direct['amount'], Money::format($direct['amount']),
                route('sales.invoice.index', $range),
                hint: __('sales::overview.direct_hint', ['count' => $direct['count']]));
            $cards[] = self::card('gross', $totals['gross'], Money::format($totals['gross']));
            $cards[] = self::card('discount', $totals['discount'], Money::format($totals['discount']), tone: 'warn',
                hint: __('sales::overview.discount_hint', [
                    'percent' => SalesAnalytics::share($totals['discount'], $totals['gross']) ?? '—',
                ]));
            $cards[] = self::card('tax', $totals['tax'], Money::format($totals['tax']));
            $cards[] = self::card('net', $totals['net'], Money::format($totals['net']),
                route('sales.invoice.index', $range), tone: 'good');
        }

        if ($can(self::ORDER)) {
            $orders = $this->analytics->orders($from, $to);
            $pending = $this->analytics->pendingDelivery();

            $cards[] = self::card('orders', (string) $orders, (string) $orders, route('sales.order.index'), count: true);
            $cards[] = self::card('pending_delivery', (string) $pending, (string) $pending,
                route('sales.order.track'), tone: $pending > 0 ? 'warn' : 'neutral', count: true);
        }

        if ($can(self::RETURN)) {
            $returns = $this->analytics->returns($from, $to);

            $cards[] = self::card('returns', $returns['amount'], Money::format($returns['amount']),
                route('sales.return.index'), tone: 'warn',
                hint: __('sales::overview.returns_hint', ['count' => $returns['count']]));
        }

        if ($can(self::COLLECTION)) {
            $collected = $this->analytics->collection($from, $to);

            $cards[] = self::card('collection', $collected, Money::format($collected),
                route('sales.collection.index', $range), tone: 'good');
        }

        if ($can(self::DUES)) {
            $dues = $this->analytics->receivable($user, $to);

            $cards[] = self::card('receivable', $dues['amount'], Money::format($dues['amount']),
                route('customer.report.show', ['slug' => 'due-list']), tone: 'bad',
                hint: __('sales::overview.receivable_hint', ['shops' => $dues['shops'], 'date' => $to]));
        }

        if ($can(self::INVOICE, self::COLLECTION)) {
            $gap = $this->analytics->outstanding($from, $to);

            $cards[] = self::card('outstanding', $gap, Money::format($gap),
                tone: bccomp($gap, '0', 4) > 0 ? 'bad' : 'good');
        }

        if ($can(self::TARGET)) {
            $target = $this->analytics->targetAchievement($period->end());

            $cards[] = self::card('target', $target['percent'] ?? '', $target['percent'] === null ? '—' : $target['percent'].'%',
                route('sales.target.index', ['month' => $target['month']]),
                hint: $target['target'] === null
                    ? __('sales::overview.target_none', ['month' => $target['month']])
                    : __('sales::overview.target_hint', [
                        'achieved' => Money::format($target['achieved']),
                        'target' => Money::format($target['target']),
                    ]));
        }

        return ['cards' => $cards, 'panels' => $this->panels($can, $period)];
    }

    /**
     * @param  callable(string...): bool  $can
     * @return array<string, mixed>
     */
    private function panels(callable $can, SalesPeriod $period): array
    {
        $from = $period->from;
        $to = $period->to;
        $end = $period->end();
        $panels = [];

        if ($can(self::INVOICE)) {
            $monthly = $this->analytics->monthly($end);

            /* ⓘ আদায়ের মাসিক সারি কেবল আদায়ের চাবিতে — নাহলে কলামটাই নেই */
            $collected = $can(self::COLLECTION) ? $this->analytics->monthlyCollection($end) : null;

            $panels['daily'] = $this->analytics->daily($end);
            $panels['monthly'] = ['rows' => $monthly, 'collected' => $collected];
            $panels['top_customers'] = $this->analytics->topCustomers($from, $to);
            $panels['top_products'] = $this->analytics->topProducts($from, $to);
            $panels['slow_products'] = $this->analytics->slowProducts($from, $to);
            $panels['by_seller'] = $this->analytics->bySeller($from, $to);
            $panels['by_territory'] = $this->analytics->byTerritory($from, $to);
            $panels['by_branch'] = $this->analytics->byBranch($from, $to);
        }

        if ($can(self::RETURN)) {
            $panels['return_trend'] = $this->analytics->returnTrend($end);
        }

        return $panels;
    }

    /** @return array<string, mixed> */
    private static function metricCard(Metric $metric): array
    {
        return [
            'key' => $metric->key,
            'label' => $metric->label,
            'raw' => $metric->value(),
            'value' => Money::format($metric->value(), $metric->scale),
            'hint' => $metric->definition(),
            'href' => null,
            'tone' => 'money',
        ];
    }

    /** @return array<string, mixed> */
    private static function card(string $key, string $raw, string $shown, ?string $href = null,
        string $tone = 'neutral', ?string $hint = null, bool $count = false): array
    {
        return [
            'key' => 'sales.overview.'.$key,
            'label' => __('sales::overview.card.'.$key),
            'raw' => $raw,
            'value' => $shown,
            'hint' => $hint ?? __('sales::overview.hint.'.$key),
            'href' => $href,
            'tone' => $tone,
            'count' => $count,
        ];
    }
}
