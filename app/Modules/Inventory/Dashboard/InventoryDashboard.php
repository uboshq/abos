<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Dashboard;

use App\Core\Contracts\ProvidesDashboard;
use App\Core\Engines\Dashboard\Breakdown;
use App\Core\Engines\Dashboard\DashboardDefinition;
use App\Core\Engines\Dashboard\DateRange;
use App\Core\Engines\Dashboard\Listing;
use App\Core\Engines\Dashboard\Series;
use App\Core\Engines\Dashboard\Stat;
use App\Core\Engines\Dashboard\Tile;
use App\Core\Security\FieldSecurity;
use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockFacts;
use App\Modules\Inventory\Services\StockService;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * মজুদ মডিউলের ড্যাশবোর্ড।
 *
 * ── এখানে একটাও হিসাব নেই, ইচ্ছাকৃতভাবে ──────────────────────────────
 * প্রতিটা সংখ্যা [[StockFacts]] থেকে আসে। এখানে একটা `SUM` লিখলে সেটা
 * হত ওই সংখ্যার **দ্বিতীয় সংজ্ঞা**, আর একদিন স্টক পর্দার সাথে মিলত না
 * — ঠিক যে ভুলটা বিক্রয়ে একবার ঘটেছিল ([[SalesMetrics]]-এর মন্তব্য)।
 *
 * এই ফাইলের কাজ কেবল **বাছাই ও সাজানো**: কোন চারটা সংখ্যা উপরে থাকবে,
 * কোনটা কোন তালিকায় নামবে, আর কোনটা কোন চাবির পেছনে।
 */
final class InventoryDashboard implements ProvidesDashboard
{
    public static function dashboard(): DashboardDefinition
    {
        $facts = app(StockFacts::class);
        $states = $facts->states();

        /*
         * কত দিনের জানালা — ঠিকানা থেকে, তবে তালিকার ভেতর থেকেই।
         *
         * যেকোনো সংখ্যা মানলে কেউ `?days=99999` দিয়ে পুরো ইতিহাস
         * স্ক্যান করাতে পারতেন ([[StockFacts::WINDOWS]]-এ কারণ লেখা)।
         */
        $days = (int) request()->query('days', '7');
        $days = in_array($days, StockFacts::WINDOWS, true) ? $days : 7;
        $window = __('inventory::overview.window', ['days' => $days]);

        // ⭐ চার্ট টাকায়, কেনা দরে (মালিক, ১ অক্টোবর ২০২৬); খরচের চাবি না থাকলে null — তখন আগের পরিমাণে
        $valueFlow = $facts->monthlyValueFlow();

        /*
         * ⭐ কোন তারিখ থেকে কোন তারিখ — মালিক, ৫ অক্টোবর ২০২৬ ("kobe theke kobe porjonto")।
         * ⓘ শেষ সাত মাস ([[StockFacts::monthlyFlow()]]-এর একই শুরু) থেকে আজ; নতুন ড্যাশবোর্ডে বছরের শুরু থেকে আজ।
         */
        $sevenMonths = DateRange::label(Carbon::today()->startOfMonth()->subMonths(6), Carbon::today());
        $flowRange = $sevenMonths;

        /*
         * ⭐ নতুন ড্যাশবোর্ডে এ বছরের জানুয়ারি–ডিসেম্বর — মালিকের নির্দেশ, ২ অক্টোবর ২০২৬ ("১২ মাসের দিবে January to Dec")।
         * ⓘ জানুয়ারি থেকে এই মাস পর্যন্ত একই হিসাব ([[StockFacts::monthlyValueFlow()]]), বাকি মাসগুলো শূন্য নিয়ে — বছরের
         * ছকটা পুরো দেখা যায়। ⚠️ StockFacts নিজে বদলায়নি; সুইচ বন্ধ থাকলে আগের মতো শেষ সাত মাস।
         */
        if ($valueFlow !== null && config('abos.dashboards_v2')) {
            $valueFlow = $facts->monthlyValueFlow(Carbon::today()->month);

            for ($month = Carbon::today()->startOfMonth()->addMonth(); $month->year === Carbon::today()->year; $month->addMonth()) {
                $valueFlow[] = ['month' => $month->translatedFormat('M'), 'in' => '0.00', 'out' => '0.00'];
            }

            $flowRange = DateRange::label(Carbon::today()->startOfYear(), Carbon::today());
        }

        return new DashboardDefinition(
            title: __('inventory::dashboard.title'),
            subtitle: __('inventory::dashboard.subtitle'),

            /*
             * ── কেন এই চারটা কাজ, আর কেন এই ক্রমে ────────────────────
             * গুদামের লোক ড্যাশবোর্ডে এসে যা করেন: মাল ঢোকান (খোলা
             * মজুদ বা সমন্বয়), মাল সরান (গুদাম বদল), বা গুনে মিলান।
             * পণ্য বানানো সবচেয়ে কম হয়, তাই শেষে।
             */
            tiles: [
                new Tile(
                    label: __('inventory::action.adjust'),
                    href: route('inventory.stock.adjust'),
                    permission: 'inventory.stock.adjust',
                    icon: 'scale',
                ),
                new Tile(
                    label: __('inventory::action.new_transfer'),
                    href: route('inventory.transfer.create'),
                    permission: 'inventory.transfer.create',
                    icon: 'swap',
                ),
                new Tile(
                    label: __('inventory::action.new_product'),
                    href: route('inventory.product.create'),
                    permission: 'inventory.product.create',
                    icon: 'plus',
                ),
                new Tile(
                    label: __('inventory::menu.stock'),
                    href: route('inventory.stock.overview'),
                    permission: 'inventory.stock.view',
                    icon: 'reports',
                ),
            ],

            stats: [
                // ⭐ মালিকের নির্দেশ, ১ অক্টোবর ২০২৬: মজুদের মূল্য সবার আগে
                /*
                 * ⚠️ মজুদের মূল্য একটা **খরচের সংখ্যা**।
                 *
                 * [[FieldSecurity]] পণ্যের পাতায় ক্রয়মূল্য `inventory.cost.view`-এর
                 * পেছনে রাখে। এই সংখ্যাটা খোলা রাখলে ওই পাহারা টপকানোর
                 * সবচেয়ে সহজ দরজা হত এটাই — একটা পণ্যের দর ঢাকা, অথচ
                 * গোটা গুদামের দাম খোলা।
                 *
                 * চাবি না থাকলে ইঞ্জিন নিজেই ঢেকে দেয় ([[DashboardEngine]]),
                 * তাই এখানে কেবল চাবিটার নাম বলাই যথেষ্ট।
                 */
                new Stat(
                    label: __('inventory::overview.stock_value'),
                    value: $facts->value() === null ? null : Money::format($facts->value()),
                    hint: __('inventory::overview.stock_value_hint'),
                    permission: 'inventory.cost.view',
                ),

                new Stat(
                    label: __('inventory::overview.available'),
                    value: Money::format($states['available'], 0),
                    hint: __('inventory::overview.available_hint'),
                    href: route('inventory.stock.index'),
                ),

                new Stat(
                    label: __('inventory::overview.below_reorder'),
                    value: (string) $facts->belowReorder(),
                    hint: __('inventory::overview.below_reorder_hint'),
                    href: route('inventory.stock.index', ['sort' => 'available']),
                    tone: Stat::WARN,
                ),

                /*
                 * ── কেন ধীরগতি আর নিশ্চল আলাদা দুইটা সংখ্যা ───────────
                 * দুইটাই "পড়ে থাকা মাল", কিন্তু কাজ দুই রকম। ধীরগতির
                 * মাল নড়ছে — ঢুকছে, সরছে — কেবল **বিক্রি হচ্ছে না**;
                 * ওখানে দাম বা প্রচারের প্রশ্ন। নিশ্চল মালে কেউ হাতই
                 * দেয়নি; ওখানে প্রশ্নটা অন্য — ওটা কি আদৌ বিক্রির
                 * জিনিস, নাকি ভুলে পড়ে আছে।
                 *
                 * এক সংখ্যায় মিলিয়ে দিলে দুইটা আলাদা সিদ্ধান্ত একটা
                 * সংখ্যার পেছনে হারিয়ে যেত।
                 */
                /*
                 * ── সংখ্যাটা ঠিক ওই পণ্যগুলোতেই নিয়ে যায় ─────────────
                 * ৩ সেপ্টেম্বর ২০২৬ পর্যন্ত এই দুইটা লিংক **গোটা স্টক
                 * তালিকায়** নিয়ে যেত। অর্থাৎ "ধীর ৫" ক্লিক করলে ওই
                 * পাঁচটা নয়, সব পণ্য দেখা যেত — সংখ্যাটা তখন বিশ্বাস
                 * করতে হত, **যাচাই করা যেত না**।
                 *
                 * মালিকের স্থায়ী নিয়ম: প্রতিটা সংখ্যা তার উৎসে নিয়ে
                 * যাবে। নিজের কোড খুলে দেখেই ধরা পড়ে।
                 *
                 * তালিকাটা আসে `StockFacts`-এর **একই predicate** থেকে
                 * (`slowMovingList` ≡ `slowMoving`), তাই সংখ্যা ৫ মানে
                 * তালিকাতেও ৫ — আর একটা টেস্ট ওই সমতাটা পাহারা দেয়।
                 */
                new Stat(
                    label: __('inventory::overview.slow_moving'),
                    value: (string) $facts->slowMoving($days),
                    hint: __('inventory::overview.slow_moving_hint').' · '.$window,
                    href: route('inventory.stock.movement', ['type' => 'slow', 'days' => $days]),
                    tone: Stat::WARN,
                ),

                new Stat(
                    label: __('inventory::overview.non_moving'),
                    value: (string) $facts->nonMoving($days),
                    hint: __('inventory::overview.non_moving_hint').' · '.$window,
                    href: route('inventory.stock.movement', ['type' => 'non', 'days' => $days]),
                    tone: Stat::BAD,
                ),

                new Stat(
                    label: __('inventory::overview.out_of_stock'),
                    value: (string) $facts->outOfStock(),
                    hint: __('inventory::overview.out_of_stock_hint'),
                    href: route('inventory.stock.index', ['sort' => 'available']),
                    tone: Stat::BAD,
                ),
                ...self::wholeSpecStats(),
            ],

            panels: [
                $valueFlow !== null
                    ? new Series(
                        label: __('inventory::overview.flow_value'),
                        points: array_map(
                            fn (array $m): array => [
                                'label' => $m['month'],
                                'first' => $m['in'],
                                'second' => $m['out'],
                                // ⓘ বারের মাথায় ছোট অঙ্ক, মাউস রাখলে পুরোটা
                                'firstNote' => StockFacts::shortTaka($m['in']),
                                'secondNote' => StockFacts::shortTaka($m['out']),
                                'firstTitle' => Money::format($m['in']),
                                'secondTitle' => Money::format($m['out']),
                            ],
                            $valueFlow,
                        ),
                        firstLabel: __('inventory::overview.moved_in'),
                        secondLabel: __('inventory::overview.moved_out'),
                        // ⓘ ঢোকা আর বেরোনোর ধারা — ভরা রেখা (মালিক, ৪ অক্টোবর ২০২৬: "vino rokomer graph")
                        chart: 'area',
                        range: $flowRange,
                    )
                    : new Series(
                        label: __('inventory::overview.flow'),
                        points: array_map(
                            fn (array $m): array => [
                                'label' => $m['month'],
                                'first' => $m['in'],
                                'second' => $m['out'],
                            ],
                            $facts->monthlyFlow(),
                        ),
                        firstLabel: __('inventory::overview.moved_in'),
                        secondLabel: __('inventory::overview.moved_out'),
                        // ⓘ ঢোকা আর বেরোনোর ধারা — ভরা রেখা (মালিক, ৪ অক্টোবর ২০২৬: "vino rokomer graph")
                        chart: 'area',
                        range: $sevenMonths,
                    ),

                /*
                 * ── কেন এই ভাগটা এই পর্দার সবচেয়ে দামি অংশ ───────────
                 * বেশিরভাগ ব্যবস্থায় "মজুদ" একটাই সংখ্যা। ABOS আলাদা
                 * করে রাখে কতটা তাকে, কতটা অর্ডারে ধরা, কতটা আটকানো —
                 * আর ওই পার্থক্যটাই বিক্রয়কর্মীকে এমন প্রতিশ্রুতি
                 * দেওয়া থেকে বাঁচায় যা গুদাম রাখতে পারবে না।
                 */
                new Breakdown(
                    label: __('inventory::overview.states'),
                    parts: [
                        ['label' => __('inventory::overview.available'), 'value' => Money::format($states['available'], 0)],
                        ['label' => __('inventory::overview.reserved'), 'value' => Money::format($states['reserved'], 0)],
                        ['label' => __('inventory::overview.hold'), 'value' => Money::format($states['hold'], 0)],
                    ],
                    hint: __('inventory::overview.states_hint'),
                ),
                ...self::expiryWindows(),
                ...self::wholeSpecPanels($facts),
            ],

            listings: [
                new Listing(
                    label: __('inventory::overview.below_reorder'),
                    columns: [
                        ['key' => 'name', 'label' => __('inventory::field.product'),
                            'render' => fn ($p) => $p->name()],
                        ['key' => 'available', 'label' => __('inventory::overview.available'),
                            'width' => '7rem', 'render' => fn ($p) => Money::format($p->available_qty, 0)],
                        ['key' => 'reorder', 'label' => __('inventory::overview.reorder_level'),
                            'width' => '7rem', 'render' => fn ($p) => Money::format($p->reorder_level, 0)],
                    ],
                    rows: $facts->lowStock(),
                    empty: __('inventory::overview.nothing_low'),
                    href: route('inventory.stock.index', ['sort' => 'available']),
                ),

                new Listing(
                    label: __('inventory::overview.stagnant').' · '.$window,
                    columns: [
                        ['key' => 'name', 'label' => __('inventory::field.product'),
                            'render' => fn ($p) => $p->name()],
                        ['key' => 'qty', 'label' => __('inventory::overview.available'),
                            'width' => '7rem', 'render' => fn ($p) => Money::format($p->available_qty, 0)],
                        ['key' => 'touches', 'label' => __('inventory::overview.touches'),
                            'width' => '7rem', 'render' => fn ($p) => $p->touches],
                    ],
                    rows: $facts->stagnant($days),
                    empty: __('inventory::overview.nothing_stagnant'),
                    /*
                     * ⚠️ `stagnant`, `slow` নয়।
                     *
                     * `stagnant()` মাপে `out = 0` — অর্থাৎ **কিছুই
                     * বেরোয়নি**। এতে দুই দলই পড়ে: যা ঢুকেছে কিন্তু
                     * বিক্রি হয়নি, আর যা কেউ ছোঁয়নি। রিপোর্টের `slow`
                     * ট্যাবে পাঠালে **তালিকা দেখাত এক জিনিস আর লিংক
                     * নিয়ে যেত আরেকটায়** — ঠিক যে ভুলটা সারাতে এই
                     * লিংকগুলো বদলানো হচ্ছে, সেটাই ছদ্মবেশে ফিরত।
                     */
                    href: route('inventory.stock.movement', ['type' => 'stagnant', 'days' => $days]),
                ),

                new Listing(
                    label: __('inventory::overview.recent'),
                    columns: [
                        ['key' => 'product', 'label' => __('inventory::field.product'),
                            'render' => fn ($m) => $m->product?->name() ?? '—'],
                        ['key' => 'warehouse', 'label' => __('inventory::menu.warehouses'),
                            'width' => '9rem', 'render' => fn ($m) => $m->warehouse?->name() ?? '—'],
                        ['key' => 'qty', 'label' => __('inventory::overview.change'),
                            'width' => '7rem', 'render' => fn ($m) => Money::format($m->floor_change, 0)],
                    ],
                    rows: $facts->recentMovements(),
                    empty: __('inventory::overview.nothing_moved'),
                    href: route('inventory.stock.index'),
                ),
            ],
        );
    }

    /**
     * ⭐ মেয়াদ নিয়ন্ত্রণ — মাল আছে এমন কয়টা লটের মেয়াদ কবে (মালিকের ড্যাশবোর্ড নকশা, ২ অক্টোবর ২০২৬)।
     *
     * ⓘ সংজ্ঞা "মেয়াদ পেরোচ্ছে" রিপোর্টের ([[StockReports::expiring()]]) মতোই: মেয়াদের তারিখ আছে, তাকে মাল > ০,
     * আর "আজ" অ্যাপের ঘড়ি থেকে — ডাটাবেজের নয়। ⓘ পেরিয়ে যাওয়াটা প্রথম ভাগ, কারণ ওটা নিয়েই এখন কিছু করতে হয়।
     * ⓘ লটের সংখ্যা, টাকা নয় — দাম দেখানোর চাবি এখানে লাগে না, তাই খরচ কারও চোখে পড়ে না।
     * ⓘ দেখার শাখা মেনে ([[DataScope::inView()]]) — হেডারে বাছা শাখার চলাচলই গোনা।
     * ⓘ নতুন ড্যাশবোর্ডের অংশ — বাকিগুলোর সাথে একসাথে চালু হবে (config abos.dashboards_v2)।
     *
     * @return list<Breakdown>
     */
    private static function expiryWindows(): array
    {
        if (! config('abos.dashboards_v2')) {
            return [];
        }

        $movements = app(DataScope::class)->inView(
            DB::table('inv_stock_movements')->where('company_id', CompanyContext::id()),
            'branch_id',
        )->whereNotNull('batch_id')
            ->selectRaw('batch_id, SUM(floor_change) as on_hand')
            ->groupBy('batch_id');

        $days = DB::table('inv_batches as b')
            ->joinSub($movements, 'm', 'm.batch_id', '=', 'b.id')
            ->where('b.company_id', CompanyContext::id())
            ->whereNull('b.deleted_at')
            ->whereNotNull('b.expiry_date')
            ->where('m.on_hand', '>', 0)
            ->selectRaw('DATEDIFF(b.expiry_date, ?) as days_left', [Carbon::today()->toDateString()])
            ->pluck('days_left')
            ->map(fn ($d) => (int) $d);

        $windows = [
            'expired' => fn (int $d) => $d < 0,
            'within_7' => fn (int $d) => $d >= 0 && $d <= 7,
            'within_30' => fn (int $d) => $d > 7 && $d <= 30,
            'within_90' => fn (int $d) => $d > 30 && $d <= 90,
            'later' => fn (int $d) => $d > 90,
        ];

        $parts = [];

        foreach ($windows as $key => $match) {
            $parts[] = ['label' => __('inventory::dashboard.expiry_'.$key), 'value' => (string) $days->filter($match)->count()];
        }

        return [new Breakdown(
            label: __('inventory::dashboard.expiry_title'),
            parts: $parts,
            hint: __('inventory::dashboard.expiry_hint', ['count' => $days->count()]),
        )];
    }

    /**
     * ⭐ মালিকের ড্যাশবোর্ড নকশার বাকি তিনটা সংখ্যা — মোট পণ্য, ঋণাত্মক মজুদ, চালু লট (৫ অক্টোবর ২০২৬)।
     *
     * ⓘ নতুন ড্যাশবোর্ডের অংশ — সুইচ বন্ধে কিছুই যোগ হয় না (config abos.dashboards_v2), [[expiryWindows()]]-এর মতো।
     * ⓘ মোট পণ্যের ভিত্তি [[StockFacts::outOfStock()]]-এর ভিত্তিই (`Product::active()`), তাই স্বাস্থ্যের তিন ভাগ যোগ করলে এটাই।
     *
     * @return list<Stat>
     */
    private static function wholeSpecStats(): array
    {
        if (! config('abos.dashboards_v2')) {
            return [];
        }

        $negative = self::negativeStock();

        return [
            new Stat(
                label: __('inventory::dashboard.total_sku'),
                value: (string) Product::query()->active()->count(),
                hint: __('inventory::dashboard.total_sku_hint'),
                href: route('inventory.product.index'),
            ),

            // ⓘ শূন্যে চুপ, একটাও থাকলে লাল — ঋণাত্মক মজুদ মানে কোথাও একটা ভুল চলাচল
            new Stat(
                label: __('inventory::dashboard.negative_stock'),
                value: (string) $negative,
                hint: __('inventory::dashboard.negative_stock_hint'),
                href: route('inventory.stock.index', ['sort' => 'available']),
                tone: $negative > 0 ? Stat::BAD : Stat::NEUTRAL,
            ),

            new Stat(
                label: __('inventory::dashboard.active_batches'),
                value: (string) self::activeBatches(),
                hint: __('inventory::dashboard.active_batches_hint'),
            ),
        ];
    }

    /**
     * ⭐ নকশার বাকি তিনটা চার্ট — গুদামভিত্তিক মূল্য, মজুদের স্বাস্থ্য, এ মাসের চলাচলের ধরন (৫ অক্টোবর ২০২৬)।
     *
     * @return list<Breakdown>
     */
    private static function wholeSpecPanels(StockFacts $facts): array
    {
        if (! config('abos.dashboards_v2')) {
            return [];
        }

        return array_values(array_filter([
            self::stockByWarehouse(),
            self::stockHealth($facts),
            self::movementKinds(),
        ]));
    }

    /**
     * চলাচলের ভিত্তি — এই কোম্পানির, দেখার শাখার ([[DataScope::inView()]]), আর যে গুদামগুলো এই মানুষ দেখতে পান।
     *
     * ⓘ কাঁচা কোয়েরি, তাই গুদামের দেয়াল নিজে চলে না — তালিকাটা মডেল থেকেই আসে, যেখানে দেয়াল চলে।
     */
    private static function movements(): QueryBuilder
    {
        return app(DataScope::class)->inView(
            DB::table('inv_stock_movements')->where('company_id', CompanyContext::id()),
            'branch_id',
        )->whereIn('warehouse_id', Warehouse::query()->pluck('id')->all());
    }

    /**
     * ঋণাত্মক মজুদ — পণ্য × গুদাম, যেখানে মালের কোনো ঘরের যোগফল শূন্যের নিচে।
     *
     * ⓘ ঘরগুলো স্টক তালিকার ([[StockController::sumOf()]]) মালের চার ঘর: তাকে, বসানো বাকি, ফ্রি, ফ্রি বসানো বাকি।
     * ⓘ অর্ডারে ধরা আর আটকানো মাল নয়, ওগুলো তাকের মালেরই অংশ।
     */
    private static function negativeStock(): int
    {
        $combos = self::movements()
            ->select('product_id', 'warehouse_id')
            ->groupBy('product_id', 'warehouse_id')
            ->havingRaw('SUM(floor_change) < 0 OR SUM(unplaced_change) < 0 OR SUM(free_change) < 0 OR SUM(unplaced_free_change) < 0');

        return DB::query()->fromSub($combos, 'n')->count();
    }

    /**
     * চালু লট — যে লটে এখনো মাল আছে।
     *
     * ⓘ মাল = তাকে + বসানো বাকি + ফ্রি + ফ্রি বসানো বাকি। ⚠️ মেয়াদের চার্ট কেবল তাক গোনে, তাই গাড়ি থেকে নেমে
     * এখনো না বসা লট এখানে আছে, ওখানে নেই — এটা জেনেশুনে: লটটা গুদামে আছে, কেবল তাকে ওঠেনি।
     */
    private static function activeBatches(): int
    {
        $left = self::movements()
            ->whereNotNull('batch_id')
            ->selectRaw('batch_id, SUM(floor_change + unplaced_change + free_change + unplaced_free_change) as left_qty')
            ->groupBy('batch_id');

        return DB::table('inv_batches as b')
            ->joinSub($left, 'm', 'm.batch_id', '=', 'b.id')
            ->where('b.company_id', CompanyContext::id())
            ->whereNull('b.deleted_at')
            ->where('m.left_qty', '>', 0)
            ->count();
    }

    /**
     * গুদামভিত্তিক মজুদের মূল্য — কেনা পরিমাণ × স্তরের গড় দর।
     *
     * ⓘ ঠিক স্টক তালিকার হিসাব ([[StockController::index()]], ৪ অক্টোবরের সারাই): `(floor + unplaced) × TRUNCATE(স্তরের মূল্য ÷
     * স্তরের পরিমাণ, 4)`, পণ্য প্রতি `TRUNCATE(…, 4)`। ⛔ ফ্রি মালের খরচ শূন্য — ফ্রি ঘর দুইটা এখানে নেই।
     * ⓘ স্তর কোম্পানির (গুদাম নেই), তাই দর পণ্যের, পরিমাণ গুদামের।
     * ⚠️ খরচের সংখ্যা: [[StockFacts::value()]]-এর একই চাবি — না থাকলে চার্টটাই নেই।
     */
    private static function stockByWarehouse(): ?Breakdown
    {
        if (! FieldSecurity::visible(StockMovement::class, 'unit_cost')) {
            return null;
        }

        $warehouses = Warehouse::query()->orderBy('code')->get();

        if ($warehouses->isEmpty()) {
            return null;
        }

        $paid = self::movements()
            ->selectRaw('warehouse_id, product_id, SUM(floor_change + unplaced_change) as paid')
            ->groupBy('warehouse_id', 'product_id');

        $layers = DB::table('inv_cost_layers')
            ->where('company_id', CompanyContext::id())
            ->where('qty_remaining', '>', 0)
            ->selectRaw('product_id, SUM(qty_remaining * unit_cost) as layer_value, SUM(qty_remaining) as layer_qty')
            ->groupBy('product_id');

        $values = DB::query()
            ->fromSub($paid, 'm')
            ->joinSub($layers, 'l', 'l.product_id', '=', 'm.product_id')
            ->where('l.layer_qty', '>', 0)
            ->selectRaw('m.warehouse_id, SUM(TRUNCATE(m.paid * TRUNCATE(l.layer_value / l.layer_qty, 4), 4)) as stock_value')
            ->groupBy('m.warehouse_id')
            ->pluck('stock_value', 'warehouse_id');

        $parts = [];

        foreach ($warehouses as $warehouse) {
            $value = bcadd((string) ($values[$warehouse->id] ?? '0'), '0', 2);

            // ⓘ বন্ধ গুদাম কেবল তখনই, যখন তাতে এখনো টাকা আটকে
            if ($warehouse->is_active || bccomp($value, '0', 2) !== 0) {
                $parts[] = ['label' => $warehouse->name(), 'value' => Money::format($value)];
            }
        }

        return $parts === [] ? null : new Breakdown(
            label: __('inventory::dashboard.by_warehouse'),
            parts: $parts,
            hint: __('inventory::dashboard.by_warehouse_hint'),
            chart: 'hbars',
        );
    }

    /**
     * মজুদের স্বাস্থ্য — ঠিক আছে / ফুরিয়ে আসছে / মাল নেই, একটা ডোনাটে।
     *
     * ⓘ সংজ্ঞা উপরের দুই সংখ্যারই: "মাল নেই" = [[StockFacts::outOfStock()]], "ফুরিয়ে আসছে" = [[StockFacts::lowStock()]]-এর
     * তালিকা থেকে যাদের এখনো কিছু আছে (শূন্যগুলো "মাল নেই"-তে, তাই কেউ দুইবার গোনা হয় না)। বাকি সচল পণ্য ঠিক আছে।
     */
    private static function stockHealth(StockFacts $facts): Breakdown
    {
        $all = Product::query()->active()->count();
        $out = $facts->outOfStock();
        $low = $facts->lowStock(1000000)
            ->filter(fn ($p) => bccomp((string) $p->available_qty, '0', 4) > 0)
            ->count();

        return new Breakdown(
            label: __('inventory::dashboard.health'),
            parts: [
                ['label' => __('inventory::dashboard.health_ok'), 'value' => (string) max(0, $all - $out - $low)],
                ['label' => __('inventory::dashboard.health_low'), 'value' => (string) $low],
                ['label' => __('inventory::dashboard.health_out'), 'value' => (string) $out],
            ],
            hint: __('inventory::dashboard.health_hint', ['count' => $all]),
            chart: 'donut',
        );
    }

    /**
     * এ মাসের চলাচল — কতবার মাল ঢুকল, বেরোল, গুদাম বদলাল, সমন্বয় হলো।
     *
     * ⓘ গুদাম বদল আর সমন্বয় চেনা যায় উৎস থেকে (`stock_transfer`, `stock_adjustment`, বাতিলসহ); বাকি সব উৎস মাল বাড়ালে
     * ঢোকা, কমালে বেরোনো। ⓘ গোনা সারি, পরিমাণ নয় — বস্তা আর পিস এক যোগফলে কিছু বলে না।
     * ⓘ মাল = চার ঘরের যোগফল, তাই বসানো (বসানো বাকি → তাক) আর আটকানো/ধরা চলাচল নয়, শূন্যে মেলে।
     * ⓘ মাসের সীমা অ্যাপের ঘড়ি থেকে, ডাটাবেজের নয়।
     */
    private static function movementKinds(): Breakdown
    {
        $physical = '(floor_change + unplaced_change + free_change + unplaced_free_change)';

        $counts = self::movements()
            ->whereBetween('trx_date', [Carbon::today()->startOfMonth()->toDateString(), Carbon::today()->toDateString()])
            ->whereRaw("{$physical} <> 0")
            ->selectRaw(
                "CASE WHEN source_type = ? OR source_type LIKE ? THEN 'transfer'"
                ." WHEN source_type = ? OR source_type LIKE ? THEN 'adjustment'"
                ." WHEN {$physical} > 0 THEN 'receive' ELSE 'issue' END as kind, COUNT(*) as moves",
                [StockTransfer::STOCK_SOURCE, StockTransfer::STOCK_SOURCE.':%', StockService::ADJUSTMENT, StockService::ADJUSTMENT.':%'],
            )
            ->groupBy('kind')
            ->pluck('moves', 'kind');

        $parts = [];

        foreach (['receive', 'issue', 'transfer', 'adjustment'] as $kind) {
            $parts[] = ['label' => __('inventory::dashboard.moves_'.$kind), 'value' => (string) (int) ($counts[$kind] ?? 0)];
        }

        return new Breakdown(
            label: __('inventory::dashboard.moves_title'),
            parts: $parts,
            hint: __('inventory::dashboard.moves_hint'),
            chart: 'columns',
            range: DateRange::label(Carbon::today()->startOfMonth(), Carbon::today()),
        );
    }
}
