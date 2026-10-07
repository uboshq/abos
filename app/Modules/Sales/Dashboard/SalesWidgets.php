<?php

declare(strict_types=1);

namespace App\Modules\Sales\Dashboard;

use App\Core\Contracts\DashboardWidgets;
use App\Core\Dashboard\HomePeriod;
use App\Core\Dashboard\Widget;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Metrics\Metric;
use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Modules\Sales\Metrics\SalesMetrics;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\OrderTracking;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * বিক্রয়ের সংখ্যাগুলো হোম পর্দায়।
 *
 * ── কেন ডকুমেন্ট থেকে গোনা, খতিয়ান থেকে নয় ──────────────────────────
 * "আজ কত বিক্রি হলো" প্রশ্নটার উত্তর দোকানদার বিলের খাতা গুনে দেন, আর
 * ক্লিক করে তিনি সেই বিলগুলোই দেখতে চান। খতিয়ানের আয় হিসাব থেকে গুনলে
 * সংখ্যাটা একই হত (দুটোই একই লেনদেন থেকে), কিন্তু ক্লিক করলে নামতে হত
 * খতিয়ানের সারিতে — যেখানে বিলের নম্বর আছে, গ্রাহকের নাম নেই।
 *
 * ব্যালেন্স (বকেয়া, প্রদেয়) উল্টো — ওগুলো হিসাবের প্রশ্ন, আর ওগুলো
 * খতিয়ান থেকেই আসে (Accounts মডিউলের উইজেট)।
 */
final class SalesWidgets implements DashboardWidgets
{
    /** @return list<Widget> */
    public static function widgets(): array
    {
        return [...self::base(), ...self::kpis()];
    }

    /**
     * ⭐ হোমের মূল সূচক (দল `kpi`) — মালিক, ৫ অক্টোবর ২০২৬: হোমের পরিকল্পনা ২, প্রতিটা সংখ্যা একবারই।
     * বিক্রি আর আদায় — বাছা সময়ে (আজ/এ মাস/এ বছর); আজ হলে গত সপ্তাহের একই বারের তুলনা আর সাত দিনের রেখা।
     *
     * @return list<Widget>
     */
    private static function kpis(): array
    {
        $today = Carbon::today()->toDateString();
        $from = match (HomePeriod::current()) {
            'month' => Carbon::today()->startOfMonth()->toDateString(),
            'year' => SalesMetrics::financialYear()[0],
            default => $today,
        };
        [$sales, $collected] = match (HomePeriod::current()) {
            'month' => [SalesMetrics::salesThisMonth(), SalesMetrics::collectedThisMonth()],
            'year' => [SalesMetrics::salesThisYear(), SalesMetrics::collectedThisYear()],
            default => [SalesMetrics::salesToday(), SalesMetrics::collectedToday()],
        };
        $isToday = HomePeriod::current() === 'today';

        return [
            self::money($sales, 'kpi', 10, route('sales.invoice.index', ['from' => $from, 'to' => $today]),
                $isToday ? self::lastSevenDays('sal_invoices', 'total') : [], 'receipt',
                $isToday ? self::againstLastWeek('sal_invoices', 'total') : null),
            self::money($collected, 'kpi', 20, route('sales.collection.index', ['from' => $from, 'to' => $today]),
                $isToday ? self::lastSevenDays('sal_collections', 'amount') : [], 'inbox',
                $isToday ? self::againstLastWeek('sal_collections', 'amount') : null),
        ];
    }

    /** @return list<Widget> */
    private static function base(): array
    {
        $today = Carbon::today()->toDateString();
        $monthStart = Carbon::today()->startOfMonth()->toDateString();

        /*
         * বছরটা অর্থবছর, ক্যালেন্ডার বছর নয়।
         *
         * ব্যবসার বছর জুলাই থেকে জুন। জানুয়ারি ধরে গুনলে পর্দার সংখ্যা
         * আর হিসাবরক্ষকের সংখ্যা আলাদা হত।
         */
        $yearStart = auth()->user()?->currentCompany?->currentFinancialYear()?->starts_on?->toDateString()
            ?? $monthStart;

        return [
            self::money(SalesMetrics::salesToday(), 'today', 10,
                route('sales.invoice.index', ['from' => $today, 'to' => $today]),
                /* আঁকা আইকন, মডিউলের ইমোজি নয় — পাশের কার্ডগুলোয়
                   `inbox`/`challan` বসে, আর এক সারিতে একটা রঙিন ইমোজি
                   বাকিদের থেকে আলাদা হয়ে চোখে লাগত (৩ সেপ্টেম্বর ২০২৬) */
                self::lastSevenDays('sal_invoices', 'total'), 'receipt',
                self::againstLastWeek('sal_invoices', 'total')),

            self::money(SalesMetrics::collectedToday(), 'today', 20,
                route('sales.collection.index', ['from' => $today, 'to' => $today]),
                self::lastSevenDays('sal_collections', 'amount'), 'inbox',
                self::againstLastWeek('sal_collections', 'amount')),

            self::money(SalesMetrics::salesThisMonth(), 'month', 10,
                route('sales.invoice.index', ['from' => $monthStart, 'to' => $today])),

            self::money(SalesMetrics::collectedThisMonth(), 'month', 20,
                route('sales.collection.index', ['from' => $monthStart, 'to' => $today]), [], 'inbox'),

            self::money(SalesMetrics::salesThisYear(), 'year', 10,
                route('sales.invoice.index', ['from' => $yearStart, 'to' => $today])),

            self::money(SalesMetrics::collectedThisYear(), 'year', 20,
                route('sales.collection.index', ['from' => $yearStart, 'to' => $today]), [], 'inbox'),

            /*
             * খসড়া বিল — কেউ শুরু করে শেষ করেনি।
             *
             * খসড়া বিল কোনো হিসাবে নেই: মাল হয়তো চলে গেছে, টাকা পাওনা
             * দেখাচ্ছে না। এটাই সেই ধরনের কাজ যা কেউ ইচ্ছাকৃতভাবে ফেলে
             * রাখে না, শুধু ভুলে যায় — আর মাস শেষে হিসাব মেলে না।
             */
            new Widget(
                group: 'todo',
                label: __('sales::dashboard.draft_invoices'),
                value: (string) SalesInvoice::query()->where('status', DocumentStatus::DRAFT)->count(),
                href: route('sales.invoice.index', ['sort' => 'oldest']),
                permission: 'sales.invoice.view',
                tone: 'warn',

                /*
                 * সবচেয়ে পুরনোটা কত দিনের।
                 *
                 * "৩টা খসড়া বিল" আর "৩টা খসড়া বিল, সবচেয়ে পুরনোটা ১১
                 * দিনের" — দ্বিতীয়টা পড়ে মানুষ আজই খোলে। সংখ্যাটা
                 * বলে কত, বাক্যটা বলে কতটা জরুরি।
                 */
                hint: self::oldestDraftAge(),
                sort: 10,
                icon: 'edit',
            ),

            new Widget(
                group: 'todo',
                label: __('sales::dashboard.uninvoiced_lines'),
                value: (string) self::reportRows('sales.uninvoiced'),
                href: route('sales.report.show', ['slug' => 'uninvoiced']),
                permission: 'sales.report',
                tone: 'warn',
                sort: 20,
                icon: 'challan',
            ),

            new Widget(
                group: 'todo',
                label: __('sales::dashboard.pending_order_lines'),
                value: (string) self::reportRows('sales.pending_orders'),
                href: route('sales.report.show', ['slug' => 'pending-orders']),
                permission: 'sales.report',
                tone: 'neutral',
                sort: 30,
                icon: 'clock',
            ),

            /*
             * ⭐ পুরনো খসড়া আদেশ — খসড়া অবস্থায় তিন দিন বা তার বেশি (মালিক, ৪ অক্টোবর ২০২৬; পরিকল্পনার §৪.৩)।
             *
             * ⓘ গোনা অর্ডার তালিকার "পুরনো খসড়া" ট্যাবেরই ([[OrderTracking::applyListTab()]]) — ঘর চাপলে ঠিক সেই
             * সারিগুলোই খোলে, আর আদেশ দেখার চাবি যাঁর আছে কেবল তিনিই ঘরটা পান।
             */
            new Widget(
                group: 'todo',
                label: __('sales::order_status.tile_stale'),
                value: (string) app(OrderTracking::class)
                    ->applyListTab(SalesOrder::query(), OrderTracking::LIST_STALE)
                    ->count(),
                href: route('sales.order.index', ['tab' => OrderTracking::LIST_STALE]),
                permission: 'sales.order.view',
                tone: 'warn',
                sort: 15,
                icon: 'edit',
            ),
        ];
    }

    /**
     * একটা ঘোষিত সংখ্যাকে হোম পর্দার একটা ঘরে বসানো।
     *
     * ── কেন উইজেট নিজে গোনে না ──────────────────────────────────────
     * আগে গুনত, আর কাউন্টারও আলাদা করে গুনত — একবার তারা দুইটা আলাদা
     * উত্তর দিয়েছিল। এখন প্রশ্নটার উত্তর এক জায়গা থেকেই আসে, আর
     * সংজ্ঞাটাও সঙ্গে আসে, তাই ঘরটার উপর মাউস রাখলে দেখা যায় কী গোনা
     * হয়েছে — যে সংখ্যার সংজ্ঞা লুকানো, দুইজন মানুষ তার দুই অর্থ করে।
     */
    private static function money(Metric $metric, string $group, int $sort, string $href,
        array $spark = [], string $icon = 'sales', ?array $against = null): Widget
    {
        return new Widget(
            group: $group,
            label: $metric->label,
            value: Money::format($metric->value(), $metric->scale),
            href: $href,
            permission: $metric->permission,
            tone: 'money',
            sort: $sort,
            hint: $against['hint'] ?? null,
            delta: $against['delta'] ?? null,
            definition: $metric->definition(),

            /*
             * লেবেলের আগে আইকন।
             *
             * চারটা কার্ড পাশাপাশি বসলে লেখাগুলো একই রকম দেখায়;
             * আইকনটাই দূর থেকে বলে দেয় কোনটা বিক্রয়ের আর কোনটা
             * আদায়ের — আর কাউন্টার থেকে ফিরে মালিক ওভাবেই তাকান।
             */
            icon: $icon,
            spark: $spark,
        );
    }

    /**
     * সবচেয়ে পুরনো খসড়া বিলটা কত দিনের — না থাকলে খালি।
     */
    private static function oldestDraftAge(): ?string
    {
        $oldest = SalesInvoice::query()
            ->where('status', DocumentStatus::DRAFT)
            ->min('trx_date');

        if ($oldest === null) {
            return null;
        }

        $days = Carbon::parse($oldest)->diffInDays(Carbon::today());

        return __('core.dashboard.oldest_is', ['days' => (int) $days]);
    }

    /**
     * আজকের সাথে গত সপ্তাহের একই বারের তুলনা।
     *
     * ── কেন গতকাল নয়, গত সপ্তাহের একই বার ───────────────────────────
     * ডিপোর সপ্তাহে একটা ছক আছে: শুক্রবার বন্ধের কাছাকাছি, বৃহস্পতিবার
     * ভরা। গতকালের সাথে তুলনা করলে প্রতি শনিবার "৮০% পড়ে গেছে" দেখাত,
     * অথচ কিছুই ঘটেনি — আর দুই সপ্তাহে মানুষ সংখ্যাটা দেখা বন্ধ করত।
     *
     * একই বারের সাথে মিলিয়ে দেখলে ছকটা কাটা পড়ে, আর যা থাকে সেটাই
     * সত্যিকারের বদল।
     *
     * ── আগের বার শূন্য হলে তুলনা নেই ────────────────────────────────
     * শূন্য থেকে বাড়া শতাংশে অসীম। "নতুন" আর "১০০% বেড়েছে" এক কথা নয়,
     * আর দ্বিতীয়টা লিখলে সেটা মিথ্যা।
     *
     * @return array{delta: ?string, hint: string}
     */
    private static function againstLastWeek(string $table, string $column): array
    {
        $today = Carbon::today();
        $before = $today->copy()->subWeek();

        /*
         * কোম্পানির ছাঁকনি হাতে — DB::table() গ্লোবাল স্কোপ মানে না।
         *
         * ৩১ আগস্ট ২০২৬-এ ধরা পড়েছে: এই দুইটা ফাংশন ছাঁকনি ছাড়া চলত, তাই
         * এক কোম্পানির মালিক ড্যাশবোর্ডে **সব কোম্পানির** বিক্রি একসাথে
         * দেখতেন। [[TenantIsolationTest]] ধরেনি কারণ সে Eloquent-এর
         * গ্লোবাল স্কোপ পরীক্ষা করে, আর DB::table() ঠিক সেটাই এড়ায়।
         * পাহারা: [[EveryRawQueryNamesItsCompanyTest]]।
         */
        // ⭐ দেখার শাখা — উপরের কার্ডের কাগজগুলো যা মানে, তুলনাটাও তাই (২৯ সেপ্টেম্বর ২০২৬)
        $sum = fn (Carbon $day) => (string) (self::homeFilter(self::inView(DB::table($table), $table.'.branch_id'), $table)
            ->where('company_id', CompanyContext::id())
            ->whereIn('status', DocumentStatus::POSTED)
            ->whereNull('deleted_at')
            ->whereDate('trx_date', $day->toDateString())
            ->sum($column) ?: '0');

        $now = $sum($today);
        $then = $sum($before);

        $hint = __('core.dashboard.against_last', [
            'day' => $before->locale(app()->getLocale())->dayName,
        ]);

        if (bccomp($then, '0', 4) === 0) {
            return ['delta' => null, 'hint' => $hint];
        }

        $change = bcdiv(bcmul(bcsub($now, $then, 4), '100', 6), $then, 1);

        return [
            'delta' => (bccomp($change, '0', 1) >= 0 ? '+' : '').$change.'%',
            'hint' => $hint,
        ];
    }

    /**
     * শেষ সাত দিনের বিক্রয় — কার্ডের নিচের রেখাটা।
     *
     * ── কেন সাত, আর কেন দিন ধরে ─────────────────────────────────────
     * সাত দিনে সপ্তাহের ছকটা একবার পুরো দেখা যায় — শুক্রবারে কম,
     * বৃহস্পতিবারে বেশি। কম দিনে ছকটা ধরা পড়ত না, বেশি দিনে রেখাটা
     * এত ঘন হত যে আজকের বিন্দুটাই আলাদা করা যেত না।
     *
     * ── কেন খালি দিনগুলোও থাকে ──────────────────────────────────────
     * যেদিন কিছু বিক্রি হয়নি সেদিন শূন্য, আর রেখাটা মাটি ছোঁয়। বাদ
     * দিলে সাত দিনের ছয়টা বিন্দু সমান দূরত্বে বসত আর বন্ধের দিনটা
     * অদৃশ্য হয়ে যেত — অথচ ওটাই ছকের অর্ধেক ব্যাখ্যা।
     *
     * @return list<string>
     */
    private static function lastSevenDays(string $table, string $column): array
    {
        $from = Carbon::today()->subDays(6);

        // কোম্পানির ছাঁকনি হাতে — কারণ উপরের `againstLastWeek()`-এ লেখা
        $byDay = self::homeFilter(self::inView(DB::table($table), $table.'.branch_id'), $table)
            ->where('company_id', CompanyContext::id())
            ->whereIn('status', DocumentStatus::POSTED)
            ->whereNull('deleted_at')
            ->whereBetween('trx_date', [$from->toDateString(), Carbon::today()->toDateString()])
            ->groupBy('trx_date')
            ->pluck(DB::raw("COALESCE(SUM({$column}), 0)"), 'trx_date');

        $series = [];

        for ($i = 0; $i < 7; $i++) {
            $day = $from->copy()->addDays($i)->toDateString();

            $series[] = (string) ($byDay[$day] ?? '0');
        }

        return $series;
    }

    /**
     * "কী বাকি" — সংখ্যাটা রিপোর্ট থেকেই, নিজের কোয়েরি থেকে নয়।
     *
     * ── কেন এটা গুরুত্বপূর্ণ ────────────────────────────────────────
     * এখানে নিজে করে গুনলে দুইটা সংখ্যা তৈরি হত: হোম পর্দায় একটা, আর
     * ক্লিক করে যে রিপোর্ট খোলে তাতে আরেকটা। "বিল বাকি ৭" দেখে ক্লিক
     * করে পাঁচটা সারি পেলে ব্যবহারকারী দুইটার কোনটাই আর বিশ্বাস করেন
     * না — আর তিনি ঠিকই করেন, কারণ একটা তো ভুল।
     *
     * রিপোর্ট ইঞ্জিন যে ছাঁকনি ছাড়া চালালে চলতি মাস ধরে, পর্দাটাও তাই
     * ধরে — তাই সংখ্যাটা আর তালিকাটা একই কথা বলে।
     */
    private static function reportRows(string $key): int
    {
        return app(ReportEngine::class)->run($key, perPage: 1)->totalRows;
    }

    /**
     * ⭐ দেখার শাখা — হেডারে যা বাছা (২৯ সেপ্টেম্বর ২০২৬)।
     *
     * একটা শাখা বাছা থাকলে কেবল সেটা, শাখাহীন সারি ছাড়া; "সব শাখা"-তে নাগাল,
     * শাখাহীনসহ ([[DataScope::viewBranchIds()]])। ⛔ কেবল দেখানোর সংখ্যায়।
     *
     * @template T of Builder
     *
     * @param  T  $query
     * @return T
     */
    private static function inView(Builder $query, string $column): Builder
    {
        // ⭐ ডিলারের দেয়ালও — বিল আর আদায় দুই টেবিলেই `customer_id` (⛔১৬, ২ অক্টোবর ২০২৬)
        app(\App\Core\Services\DealerScope::class)->restrict($query, strstr($column, '.', true).'.customer_id');

        return app(DataScope::class)->inView($query, $column);
    }

    /**
     * ⭐ হোমের ছাঁকনি — কেবল বিক্রির কার্ডে; আদায়ের কার্ড বদলায় না (মালিক, ৪ অক্টোবর ২০২৬: "বিক্রি আর বকেয়া")।
     *
     * @template T of Builder
     *
     * @param  T  $query
     * @return T
     */
    private static function homeFilter(Builder $query, string $table): Builder
    {
        $filter = \App\Core\Dashboard\HomeFilter::current();

        return $filter !== null && $table === 'sal_invoices' ? $filter->onInvoices($query, $table) : $query;
    }
}
