<?php

declare(strict_types=1);

namespace App\Modules\Restaurant\Dashboard;

use App\Core\Contracts\ProvidesDashboard;
use App\Core\Engines\Dashboard\Breakdown;
use App\Core\Engines\Dashboard\DashboardDefinition;
use App\Core\Engines\Dashboard\Listing;
use App\Core\Engines\Dashboard\Stat;
use App\Core\Engines\Dashboard\Tile;
use App\Modules\Restaurant\Models\KitchenTicket;
use Illuminate\Support\Carbon;

/**
 * রেস্টুরেন্ট মডিউলের ড্যাশবোর্ড।
 *
 * ── কেন এখানে টাকার সংখ্যা নেই ───────────────────────────────────────
 * রেস্টুরেন্ট একটা **সাজানোর স্তর** — সে অর্ডারকে রান্নাঘর হয়ে বিলিং
 * পর্যন্ত নিয়ে যায়, কিন্তু টাকার খাতা রাখে না। বিক্রির অঙ্ক বিক্রয়ের,
 * খরচ মজুদের, নগদ অর্থের। এখানে ওগুলো দেখালে একই সংখ্যা দুই মডিউলে
 * দুইবার সংজ্ঞায়িত হত, আর একদিন দুই রকম হত।
 *
 * তাই এই পর্দার প্রশ্ন একটাই, আর সেটা সময়ের: **এই মুহূর্তে রান্নাঘরে
 * কী চলছে, আর কে কতক্ষণ ধরে অপেক্ষা করছে।**
 */
final class RestaurantDashboard implements ProvidesDashboard
{
    public static function dashboard(): DashboardDefinition
    {
        $today = Carbon::today()->toDateString();

        $counts = KitchenTicket::query()
            ->selectRaw('state, COUNT(*) as n')
            ->groupBy('state')
            ->pluck('n', 'state')
            ->all();

        $open = (int) ($counts[KitchenTicket::PLACED] ?? 0)
            + (int) ($counts[KitchenTicket::COOKING] ?? 0);

        return new DashboardDefinition(
            title: __('restaurant::dashboard.title'),
            subtitle: __('restaurant::dashboard.subtitle'),

            tiles: [
                new Tile(
                    label: __('restaurant::menu.kitchen_board'),
                    href: route('restaurant.kitchen.index'),
                    permission: 'restaurant.kitchen.view',
                    icon: 'grid',
                ),
                new Tile(
                    label: __('restaurant::menu.kot'),
                    href: route('restaurant.kitchen.tickets'),
                    permission: 'restaurant.kitchen.view',
                    icon: 'reports',
                ),
            ],

            stats: [
                /*
                 * ── কেন "চলমান" সবার আগে ─────────────────────────────
                 * এটাই একমাত্র সংখ্যা যেটা **এখনই কিছু করতে বলে**।
                 * বাকিগুলো ইতিহাস; এটা হাতে থাকা কাজ।
                 */
                new Stat(
                    label: __('restaurant::dashboard.open_tickets'),
                    value: (string) $open,
                    hint: __('restaurant::dashboard.open_tickets_hint'),
                    href: route('restaurant.kitchen.index'),
                    tone: Stat::WARN,
                ),

                /*
                 * ⚠️ "তৈরি হয়ে অপেক্ষায়" আলাদা সংখ্যা, চলমানের ভেতরে নয়।
                 *
                 * রান্না শেষ অথচ টেবিলে যায়নি — এটা রান্নাঘরের সমস্যা
                 * নয়, **পরিবেশনের**। এক সংখ্যায় মিশিয়ে দিলে রান্নাঘরকে
                 * দোষ দেওয়া হত, আর আসল দেরিটা কোথায় তা কেউ দেখত না।
                 */
                new Stat(
                    label: __('restaurant::dashboard.ready'),
                    value: (string) ($counts[KitchenTicket::READY] ?? 0),
                    hint: __('restaurant::dashboard.ready_hint'),
                    href: route('restaurant.kitchen.index'),
                    tone: Stat::BAD,
                ),

                new Stat(
                    label: __('restaurant::dashboard.today_tickets'),
                    value: (string) KitchenTicket::query()->whereDate('placed_at', $today)->count(),
                    hint: __('restaurant::dashboard.today_tickets_hint'),
                    href: route('restaurant.kitchen.tickets'),
                ),

                new Stat(
                    label: __('restaurant::dashboard.served'),
                    value: (string) ($counts[KitchenTicket::SERVED] ?? 0),
                    hint: __('restaurant::dashboard.served_hint'),
                    href: route('restaurant.kitchen.tickets'),
                    tone: Stat::GOOD,
                ),
            ],

            panels: [
                new Breakdown(
                    label: __('restaurant::dashboard.on_the_pass'),
                    parts: [
                        ['label' => __('restaurant::state.placed'),
                            'value' => (string) ($counts[KitchenTicket::PLACED] ?? 0)],
                        ['label' => __('restaurant::state.cooking'),
                            'value' => (string) ($counts[KitchenTicket::COOKING] ?? 0)],
                        ['label' => __('restaurant::state.ready'),
                            'value' => (string) ($counts[KitchenTicket::READY] ?? 0)],
                    ],
                    hint: __('restaurant::dashboard.on_the_pass_hint'),
                ),
            ],

            listings: [
                /* ⚠️ উপরের ভাগটার নাম নয়। দুইটা ঘরে একই শিরোনাম বসলে
                   পর্দাটা দেখে মনে হয় একই জিনিস দুইবার — অথচ উপরেরটা
                   **গোনা** (কয়টা কোন অবস্থায়), আর এটা **কারা**
                   (৩ সেপ্টেম্বর ২০২৬-এ ছবি দেখে ধরা পড়ে) */
                new Listing(
                    label: __('restaurant::dashboard.waiting_now'),
                    columns: [
                        ['key' => 'doc', 'label' => __('restaurant::dashboard.ticket'), 'width' => '10rem',
                            'render' => fn ($t) => $t->document_no],
                        ['key' => 'product', 'label' => __('restaurant::dashboard.item'),
                            'render' => fn ($t) => $t->product?->name() ?? '—'],
                        ['key' => 'state', 'label' => __('restaurant::dashboard.state'), 'width' => '8rem',
                            'render' => fn ($t) => __('restaurant::state.'.$t->state)],
                    ],
                    rows: KitchenTicket::query()
                        ->whereIn('state', [KitchenTicket::PLACED, KitchenTicket::COOKING, KitchenTicket::READY])
                        ->with('product')
                        ->orderBy('placed_at')
                        ->limit(8)
                        ->get(),
                    empty: __('restaurant::dashboard.nothing_cooking'),
                    href: route('restaurant.kitchen.index'),
                ),
            ],
        );
    }
}
