<?php

declare(strict_types=1);

namespace App\Modules\Sales\Dashboard;

use App\Core\Engines\Dashboard\Breakdown;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Core\Support\ViewedBranch;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Sales\Metrics\SalesAnalytics;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\DeliveryOrder;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesQuotation;
use App\Modules\Sales\Reports\SalesReturnReasonReports;
use App\Modules\Sales\Support\DeliveryOrderStatus;
use Illuminate\Support\Carbon;

/**
 * বিক্রয় ড্যাশবোর্ডের নতুন চার্ট — মালিকের ড্যাশবোর্ড নকশা (৩ অক্টোবর ২০২৬), নতুন ড্যাশবোর্ডের অংশ (config abos.dashboards_v2)।
 *
 * ⓘ আলাদা ফাইল, যাতে [[SalesDashboard]] আর [[SalesOverview]]-এর কাজ ছোঁয়া না লাগে (SR আর এলাকা ধরে বিক্রি
 * abos-69/bb-এর SalesAnalytics-এ, ৪ অক্টোবর ২০২৬ — দুইবার নয়)।
 *
 * ⭐ ৫ অক্টোবর ২০২৬: পুরো নকশা — টার্গেট, মোট লাভ, এরিয়া, বিক্রেতা, ফেরতের কারণ, আর ফানেলে DO ও আদায়ের ধাপ।
 * ⓘ প্রতিটা সংখ্যা আগের কোনো সংজ্ঞা থেকে ধার করা ([[SalesAnalytics]], [[PurchaseWidgets::marginThisMonth()]],
 * ফেরতের কারণের রিপোর্ট) — ⛔ এখানে নতুন কোনো যোগফলের সংজ্ঞা জন্মায় না, তাই চার্ট আর রিপোর্ট দুই কথা বলে না।
 */
final class SalesCharts
{
    /** র‍্যাঙ্কিং চার্টে কয়টা সারি — বাকিটা রিপোর্টে */
    private const RANKED = 8;

    /**
     * ⓘ সুপারভাইজারের সই পেরোনো DO — তার পরের সব ধাপ (হিসাব, ডিপো, বিল) "অনুমোদিত"-ই।
     * ⛔ জমা দেওয়া কিন্তু সইয়ের অপেক্ষার DO গোনা হয় না — ওটা এখনো কেবল চাওয়া।
     */
    private const DO_APPROVED = [
        DeliveryOrderStatus::SUPERVISOR_APPROVED, DeliveryOrderStatus::ACCOUNTS_HELD,
        DeliveryOrderStatus::ACCOUNTS_APPROVED, DeliveryOrderStatus::DEPOT_CHECK, DeliveryOrderStatus::INVOICED,
    ];

    /** @return list<Breakdown> */
    public static function all(): array
    {
        if (! config('abos.dashboards_v2') || ! auth()->user()?->can('sales.invoice.view')) {
            return [];
        }

        $from = Carbon::today()->startOfMonth()->toDateString();
        $to = Carbon::today()->toDateString();

        return array_values(array_filter([
            self::funnel(),
            self::target(),
            self::profit(),
            self::territory($from, $to),
            self::sellers($from, $to),
            self::returnReasons($from, $to),
        ]));
    }

    /**
     * ⭐ বিক্রয়ের ফানেল — এ মাসে কয়টা উদ্ধৃতি, অর্ডার, DO, চালান, বিল আর আদায় (Quotation → Order → DO → Delivery → Invoice → Collection)।
     * ⓘ খসড়া আর বাতিল বাদ; উদ্ধৃতি পাঠানো/গৃহীত/অনুমোদিত যেকোনো ধাপে গোনে, অর্ডার-চালান-বিল পাকা (নিশ্চিত বা বন্ধ) হলে,
     * DO সুপারভাইজারের সইয়ের পরে ([[DO_APPROVED]])।
     * ⓘ আদায় = খাতায় বসা আদায়ের কাগজ + গ্রাহকের রসিদ ভাউচার — [[SalesMetrics::collectionTotal()]]-এর হুবহু দুই উৎস
     * (৫ অক্টোবর ২০২৬)। ⚠️ কেবল `sal_collections` গুনলে কাউন্টারের নগদ বিক্রি (রসিদ ভাউচারে আদায়) এই ধাপে শূন্য দেখাত।
     * ⓘ সংখ্যা কাগজের, টাকার নয় — কোন ধাপে এসে বিক্রি আটকে যাচ্ছে সেটাই প্রশ্ন। দেখার শাখা মানে (মডেলের পাহারা)।
     */
    private static function funnel(): Breakdown
    {
        $from = Carbon::today()->startOfMonth()->toDateString();
        $to = Carbon::today()->toDateString();
        $month = fn ($query) => $query->whereBetween('trx_date', [$from, $to]);

        $quotes = $month(SalesQuotation::query())
            ->whereNotIn('status', [SalesQuotation::DRAFT, SalesQuotation::CANCELLED])->count();
        $orders = $month(SalesOrder::query())->whereIn('status', DocumentStatus::POSTED)->count();
        $deliveryOrders = $month(DeliveryOrder::query())->whereIn('status', self::DO_APPROVED)->count();
        $challans = $month(DeliveryChallan::query())->whereIn('status', DocumentStatus::POSTED)->count();
        $invoices = $month(SalesInvoice::query())->whereIn('status', DocumentStatus::POSTED)->count();
        $collections = $month(Collection::query())->posted()->count()
            + $month(Voucher::query())->where('type', Voucher::RECEIPT)->where('party_type', 'customer')->posted()->count();

        return new Breakdown(
            label: __('sales::dashboard.funnel'),
            range: self::monthRange(),
            parts: [
                ['label' => __('sales::dashboard.funnel_quotes'), 'value' => (string) $quotes],
                ['label' => __('sales::dashboard.funnel_orders'), 'value' => (string) $orders],
                ['label' => __('sales::dashboard.funnel_delivery_orders'), 'value' => (string) $deliveryOrders],
                ['label' => __('sales::dashboard.funnel_challans'), 'value' => (string) $challans],
                ['label' => __('sales::dashboard.funnel_invoices'), 'value' => (string) $invoices],
                ['label' => __('sales::dashboard.funnel_collections'), 'value' => (string) $collections],
            ],
            hint: __('sales::dashboard.funnel_hint'),
            chart: 'funnel',
        );
    }

    /**
     * ⭐ এ মাসের টার্গেট — অর্জন আর বাকি, ডোনাটে; মোট লক্ষ্য আর শতাংশ নিচের লেখায় (৫ অক্টোবর ২০২৬)।
     * ⓘ সংজ্ঞা [[SalesAnalytics::targetAchievement()]]-এর — ফোন আর বিক্রয়ের সারাংশ পর্দা একই অঙ্ক বলে (ভ্যাট বাদে,
     * কেবল যাঁদের টার্গেট বসানো)। ⓘ লক্ষ্য ছাড়ালে বাকি শূন্য, ঋণাত্মক নয়।
     * ⓘ এ মাসে কারও টার্গেট বসানো না থাকলে চার্টই নেই — শূন্যের ডোনাট "লক্ষ্য পূরণ হয়নি" বলে ভুল পড়া যেত।
     */
    private static function target(): ?Breakdown
    {
        $t = app(SalesAnalytics::class)->targetAchievement(Carbon::today());

        if ($t['target'] === null) {
            return null;
        }

        $remaining = bccomp($t['target'], $t['achieved'], 4) > 0 ? bcsub($t['target'], $t['achieved'], 4) : '0';

        return new Breakdown(
            label: __('sales::dashboard.target'),
            range: self::monthRange(),
            parts: [
                ['label' => __('sales::dashboard.target_achieved'), 'value' => Money::format($t['achieved'])],
                ['label' => __('sales::dashboard.target_remaining'), 'value' => Money::format($remaining)],
            ],
            hint: __('sales::dashboard.target_hint', [
                'target' => Money::format($t['target']),
                'percent' => $t['percent'] ?? '0',
            ]),
            // ⓘ অর্জিত বনাম বাকি — পুরোটার দুই ভাগ, তাই ডোনাট
            chart: 'donut',
        );
    }

    /**
     * ⭐ এ মাসের মোট লাভ — বিক্রয়, বিক্রীত পণ্যের ব্যয় আর তার ফারাক, খাড়া স্তম্ভে (৫ অক্টোবর ২০২৬)।
     *
     * ⓘ সংজ্ঞা [[PurchaseWidgets::marginThisMonth()]]-এর হুবহু: খাতায় বসা বিলের `total` বনাম `cost_of_goods`,
     * মাসের প্রথম থেকে শেষ দিন, আর শতাংশ **ক্রয়মূল্যের উপর** (কোম্পানির "৪%" যেভাবে বোঝায়)। ⛔ এখানে আলাদা করে
     * বিক্রয়ের উপর গুনলে একই মাসের মার্জিন দুই পর্দায় দুই রকম পড়ত।
     * ⓘ মডেল দিয়ে, তাই কোম্পানি আর দেখার শাখা নিজেই বসে। ⛔ ব্যয়ের অঙ্ক কেবল `sales.cost.view` চাবিতে।
     * ⓘ এ মাসে বিক্রি না থাকলে চার্ট নেই — ক্রয়ের কার্ডেরও একই নিয়ম।
     */
    private static function profit(): ?Breakdown
    {
        if (! auth()->user()?->can('sales.cost.view')) {
            return null;
        }

        $row = SalesInvoice::query()
            ->posted()
            ->whereBetween('trx_date', [
                Carbon::today()->startOfMonth()->toDateString(),
                Carbon::today()->endOfMonth()->toDateString(),
            ])
            ->selectRaw('COALESCE(SUM(total), 0) as sold, COALESCE(SUM(cost_of_goods), 0) as cost')
            ->toBase()
            ->first();

        $sold = (string) ($row->sold ?? '0');
        $cost = (string) ($row->cost ?? '0');

        if (bccomp($sold, '0', 4) === 0) {
            return null;
        }

        $margin = bcsub($sold, $cost, 4);
        $percent = bccomp($cost, '0', 4) > 0
            ? Money::round(bcmul(bcdiv($margin, $cost, 6), '100', 6), 2)
            : null;

        return new Breakdown(
            label: __('sales::dashboard.profit'),
            range: self::monthRange(),
            parts: [
                ['label' => __('sales::dashboard.profit_sold'), 'value' => Money::format($sold)],
                ['label' => __('sales::dashboard.profit_cost'), 'value' => Money::format($cost)],
                ['label' => __('sales::dashboard.profit_margin'), 'value' => Money::format($margin)],
            ],
            hint: $percent === null
                ? __('sales::dashboard.profit_hint_no_cost')
                : __('sales::dashboard.profit_hint', ['percent' => $percent]),
            // ⓘ তিনটা আলাদা অঙ্ক পাশাপাশি — খাড়া স্তম্ভ
            chart: 'columns',
        );
    }

    /**
     * ⭐ এরিয়া ধরে এ মাসের বিক্রি — আড়াআড়ি দণ্ডে, বড়টা উপরে (৫ অক্টোবর ২০২৬)।
     * ⓘ [[SalesAnalytics::byTerritory()]] — বিক্রয়ের সারাংশ পর্দার একই সারি, দ্বিতীয় হিসাব নয়।
     */
    private static function territory(string $from, string $to): ?Breakdown
    {
        $rows = app(SalesAnalytics::class)->byTerritory($from, $to)['rows'];

        return self::ranked(__('sales::dashboard.by_territory'), $rows, __('sales::dashboard.by_territory_hint'));
    }

    /**
     * ⭐ বিক্রেতা ধরে এ মাসের বিক্রি — যিনি বিল কেটেছেন (৫ অক্টোবর ২০২৬)।
     * ⓘ [[SalesAnalytics::bySeller()]] — টার্গেটও এই মানুষটাকেই মাপে।
     */
    private static function sellers(string $from, string $to): ?Breakdown
    {
        $rows = app(SalesAnalytics::class)->bySeller($from, $to);

        return self::ranked(__('sales::dashboard.by_seller'), $rows, __('sales::dashboard.by_seller_hint'));
    }

    /**
     * ⭐ এ মাসের ফেরত, কারণ ধরে — আড়াআড়ি দণ্ডে, টাকার অঙ্কে বড়টা উপরে (৫ অক্টোবর ২০২৬)।
     *
     * ⓘ নিজের হিসাব নয়: "কোন কারণে কত ফেরত" রিপোর্টের ([[SalesReturnReasonReports]]) সারিগুলোই — লাইনের কারণ আগে,
     * তারপর ফেরতের নিজের (`sal_returns.reason_code_id`), কারণহীন সারিও থাকে। ⓘ রিপোর্টের শাখার দেয়াল এখানেও খাটে।
     * ⛔ রিপোর্টের নিজের চাবি ছাড়া চার্টই নেই — রিপোর্ট যেখানে বন্ধ, ড্যাশবোর্ডেও বন্ধ।
     */
    private static function returnReasons(string $from, string $to): ?Breakdown
    {
        if (! auth()->user()?->can(SalesReturnReasonReports::PERMISSION)) {
            return null;
        }

        $result = app(ReportEngine::class)->run(
            SalesReturnReasonReports::KEY,
            ['from' => $from, 'to' => $to, 'branch_id' => ViewedBranch::one()],
            1,
            self::RANKED,
        );

        if ($result->rows === []) {
            return null;
        }

        return new Breakdown(
            label: __('sales::dashboard.return_reasons'),
            range: self::monthRange(),
            parts: array_map(fn (array $row): array => [
                'label' => (string) $row['reason_name'],
                'value' => Money::format($row['total']),
            ], $result->rows),
            hint: __('sales::dashboard.return_reasons_hint', ['total' => Money::format($result->totals['total'] ?? '0')]),
            chart: 'hbars',
        );
    }

    /**
     * র‍্যাঙ্কিংয়ের আড়াআড়ি দণ্ড — প্রথম আটটা; এ মাসে কিছুই না থাকলে চার্ট নেই (খালি ভাগ আঁকা যায় না)।
     *
     * @param  list<array{name: string, amount: string}>  $rows
     */
    private static function ranked(string $label, array $rows, string $hint): ?Breakdown
    {
        if ($rows === []) {
            return null;
        }

        return new Breakdown(
            label: $label,
            range: self::monthRange(),
            parts: array_map(fn (array $r): array => [
                'label' => $r['name'],
                'value' => Money::format($r['amount']),
            ], array_slice($rows, 0, self::RANKED)),
            hint: $hint,
            chart: 'hbars',
        );
    }

    /** ⓘ কবে থেকে কবে — মাসের ১ তারিখ থেকে আজ (মালিক, ৫ অক্টোবর ২০২৬: প্রতিটা চার্টে তারিখ) */
    private static function monthRange(): string
    {
        return \App\Core\Engines\Dashboard\DateRange::label(now()->startOfMonth(), now());
    }
}
