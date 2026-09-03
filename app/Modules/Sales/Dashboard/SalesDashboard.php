<?php

declare(strict_types=1);

namespace App\Modules\Sales\Dashboard;

use App\Core\Contracts\ProvidesDashboard;
use App\Core\Engines\Dashboard\Breakdown;
use App\Core\Engines\Dashboard\DashboardDefinition;
use App\Core\Engines\Dashboard\Listing;
use App\Core\Engines\Dashboard\Series;
use App\Core\Engines\Dashboard\Stat;
use App\Core\Engines\Dashboard\Tile;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Modules\Sales\Metrics\SalesMetrics;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Support\Carbon;

/**
 * বিক্রয় মডিউলের ড্যাশবোর্ড।
 *
 * ── কেন সংখ্যাগুলো [[SalesMetrics]] থেকে ─────────────────────────────
 * "আজকের বিক্রয়" এই রিপোতেই একবার চার জায়গায় গোনা হত, আর দুইটা আলাদা
 * উত্তর দিয়েছিল — খসড়া বিল গোনা হচ্ছিল কি না তা নিয়ে। তারপর সংজ্ঞাটা
 * এক জায়গায় আনা হয়েছে, আর এই পর্দাটা সেখান থেকেই নেয়।
 *
 * এখানে নতুন করে একটা `sum()` লিখলে সেটা হত **পঞ্চম** সংজ্ঞা।
 */
final class SalesDashboard implements ProvidesDashboard
{
    public static function dashboard(): DashboardDefinition
    {
        $today = SalesMetrics::salesToday();
        $month = SalesMetrics::salesThisMonth();
        $collectedToday = SalesMetrics::collectedToday();
        $collectedMonth = SalesMetrics::collectedThisMonth();

        return new DashboardDefinition(
            title: __('sales::dashboard.title'),
            subtitle: __('sales::dashboard.subtitle'),

            tiles: [
                new Tile(
                    label: __('sales::action.new_invoice'),
                    href: route('sales.invoice.create'),
                    permission: 'sales.invoice.create',
                    icon: 'receipt',
                ),
                new Tile(
                    label: __('sales::action.new_order'),
                    href: route('sales.order.create'),
                    permission: 'sales.order.create',
                    icon: 'plus',
                ),
                new Tile(
                    label: __('sales::action.new_collection'),
                    href: route('sales.collection.create'),
                    permission: 'sales.collection.create',
                    icon: 'cash',
                ),
                new Tile(
                    label: __('sales::menu.invoices'),
                    href: route('sales.invoice.index'),
                    permission: 'sales.invoice.view',
                    icon: 'reports',
                ),
            ],

            stats: [
                /*
                 * ── কেন আজ ও এই মাস, দুইটাই ──────────────────────────
                 * আজকের সংখ্যাটা দিনের কাজের, মাসেরটা লক্ষ্যের। একটা
                 * দেখালে সকালবেলা পর্দাটা প্রায় খালি দেখাত (দিন শুরু
                 * হয়নি), আর কেউ ভাবতেন ব্যবসা বন্ধ।
                 */
                new Stat(
                    label: $today->label,
                    value: Money::format($today->value()),
                    hint: __('sales::dashboard.today_hint'),
                    href: route('sales.invoice.index', ['view' => 'today']),
                    tone: Stat::GOOD,
                ),

                new Stat(
                    label: $month->label,
                    value: Money::format($month->value()),
                    hint: __('sales::dashboard.month_hint'),
                    href: route('sales.invoice.index'),
                ),

                new Stat(
                    label: $collectedToday->label,
                    value: Money::format($collectedToday->value()),
                    hint: __('sales::dashboard.collected_hint'),
                    href: route('sales.collection.index'),
                    tone: Stat::GOOD,
                ),

                /*
                 * ⚠️ বকেয়া বাড়া খারাপ খবর, তাই `BAD` — আর সেটাই তীরের
                 * রংও ঠিক করে। দিক দেখে রং দিলে "বকেয়া ▲২১%" সবুজ হত।
                 */
                new Stat(
                    label: __('sales::dashboard.outstanding'),
                    value: Money::format(self::outstanding()),
                    hint: __('sales::dashboard.outstanding_hint'),
                    href: route('sales.invoice.index', ['view' => 'due']),
                    tone: Stat::BAD,
                ),
            ],

            panels: [
                new Series(
                    label: __('sales::dashboard.six_months'),
                    points: self::monthly(),
                    firstLabel: __('sales::dashboard.billed'),
                    secondLabel: __('sales::dashboard.collected'),
                ),

                new Breakdown(
                    label: __('sales::dashboard.where_bills_stand'),
                    parts: self::byStatus(),
                    hint: __('sales::dashboard.status_hint'),
                ),
            ],

            listings: [
                new Listing(
                    label: __('sales::dashboard.biggest_dues'),
                    columns: [
                        ['key' => 'no', 'label' => __('sales::field.document_no'),
                            'render' => fn ($i) => $i->document_no],
                        ['key' => 'party', 'label' => __('sales::field.customer'),
                            'render' => fn ($i) => $i->customer?->name() ?? '—'],
                        ['key' => 'due', 'label' => __('sales::dashboard.outstanding'), 'width' => '9rem',
                            'render' => fn ($i) => Money::format($i->total)],
                    ],
                    rows: self::biggestDues(),
                    empty: __('sales::dashboard.nothing_due'),
                    href: route('sales.invoice.index', ['view' => 'due']),
                ),
            ],
        );
    }

    /**
     * মোট বকেয়া — নিশ্চিত হওয়া বিলের অঙ্ক, আদায় বাদ।
     *
     * খসড়া বাদ, ইচ্ছাকৃতভাবে: খসড়া বিল কারও কাছে পাওনা নয়, ওটা এখনো
     * একটা কাগজের খসড়া মাত্র।
     */
    private static function outstanding(): string
    {
        return (string) SalesInvoice::query()
            ->posted()
            ->sum('total');
    }

    /**
     * ছয় মাসের বিল ও আদায়, পাশাপাশি।
     *
     * প্রতিটা মাস তালিকায় থাকে, লেনদেন না থাকলেও — নাহলে ফাঁকা মাস
     * চার্ট থেকে উধাও হত আর ছয়টার বদলে চারটা বার দেখা যেত।
     *
     * @return list<array{label: string, first: string, second: string}>
     */
    private static function monthly(): array
    {
        $out = [];
        $cursor = Carbon::today()->startOfMonth()->subMonths(5);

        for ($i = 0; $i < 6; $i++) {
            $from = $cursor->copy()->startOfMonth()->toDateString();
            $to = $cursor->copy()->endOfMonth()->toDateString();

            $billed = SalesInvoice::query()
                ->posted()
                ->whereBetween('trx_date', [$from, $to])
                ->sum('total');

            $out[] = [
                'label' => $cursor->translatedFormat('M'),
                'first' => (string) $billed,
                'second' => (string) Collection::query()
                    ->whereBetween('trx_date', [$from, $to])
                    ->sum('amount'),
            ];

            $cursor->addMonth();
        }

        return $out;
    }

    /**
     * কাগজগুলো কোন অবস্থায় দাঁড়িয়ে।
     *
     * ── কেন এটা দরকার ───────────────────────────────────────────────
     * "এই মাসে ৪২ লাখ বিক্রি" শুনতে ভালো, কিন্তু তার কতটা এখনো খসড়া?
     * খসড়া বিল কোনো টাকা নয় — মাল যায়নি, খাতায় বসেনি। ভাগটা না
     * দেখালে মাসের সংখ্যাটা প্রকৃতপক্ষের চেয়ে বড় শোনাত।
     *
     * @return list<array{label: string, value: string}>
     */
    private static function byStatus(): array
    {
        $rows = SalesInvoice::query()
            ->selectRaw('status, COUNT(*) as n')
            ->groupBy('status')
            ->pluck('n', 'status')
            ->all();

        $out = [];

        foreach (DocumentStatus::STAGES as $status) {
            $out[] = [
                'label' => __('core.status.'.$status),
                'value' => (string) ($rows[$status] ?? 0),
            ];
        }

        return $out;
    }

    /** সবচেয়ে বড় বকেয়াগুলো, উপরে। */
    private static function biggestDues()
    {
        return SalesInvoice::query()
            ->posted()
            ->with('customer')
            ->orderByDesc('total')
            ->limit(8)
            ->get();
    }
}
