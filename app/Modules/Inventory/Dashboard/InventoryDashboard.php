<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Dashboard;

use App\Core\Contracts\ProvidesDashboard;
use App\Core\Engines\Dashboard\Breakdown;
use App\Core\Engines\Dashboard\DashboardDefinition;
use App\Core\Engines\Dashboard\Listing;
use App\Core\Engines\Dashboard\Series;
use App\Core\Engines\Dashboard\Stat;
use App\Core\Engines\Dashboard\Tile;
use App\Core\Support\Money;
use App\Modules\Inventory\Services\StockFacts;

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
            ],

            panels: [
                new Series(
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
}
