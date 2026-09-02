<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Dashboard;

use App\Core\Contracts\ProvidesDashboard;
use App\Core\Engines\Dashboard\Breakdown;
use App\Core\Engines\Dashboard\DashboardDefinition;
use App\Core\Engines\Dashboard\Stat;
use App\Core\Engines\Dashboard\Tile;
use App\Modules\MasterData\Models\Brand;
use App\Modules\MasterData\Models\Location;
use App\Modules\MasterData\Models\ProductCategory;
use App\Modules\MasterData\Models\Tax;
use App\Modules\MasterData\Models\Unit;

/**
 * মাস্টার ডাটা মডিউলের ড্যাশবোর্ড।
 *
 * ── কেন এখানে কোনো "আজকের" সংখ্যা নেই ────────────────────────────────
 * বাকি মডিউলের ড্যাশবোর্ড বলে **আজ কী হলো**। মাস্টার ডাটায় "আজ" বলে
 * কিছু নেই — একক, কর, ব্র্যান্ড বছরে দুই-চারবার বদলায়। এখানে "আজকের
 * একক" ধরনের সংখ্যা বসালে সেটা প্রায় সবসময় শূন্য দেখাত, আর পর্দাটা
 * অকেজো মনে হত।
 *
 * ── তাহলে এই পর্দার প্রশ্নটা কী ──────────────────────────────────────
 * **তালিকাগুলো ভরা আছে তো?** একটা খালি একক-তালিকা বা কর-তালিকা মানে
 * পণ্য বানানোই আটকে যাবে, আর ভুলটা ধরা পড়বে অনেক দূরে — পণ্যের ফর্মে,
 * একটা খালি ড্রপডাউন হিসেবে, যেখান থেকে কারণটা বোঝা যায় না।
 */
final class MasterDataDashboard implements ProvidesDashboard
{
    public static function dashboard(): DashboardDefinition
    {
        return new DashboardDefinition(
            title: __('master_data::dashboard.title'),
            subtitle: __('master_data::dashboard.subtitle'),

            tiles: [
                new Tile(label: __('master_data::menu.units'), href: route('master_data.unit.index'),
                    permission: 'master_data.view', icon: 'settings'),
                new Tile(label: __('master_data::menu.taxes'), href: route('master_data.tax.index'),
                    permission: 'master_data.view', icon: 'accounts'),
                new Tile(label: __('master_data::menu.categories'), href: route('master_data.product_category.index'),
                    permission: 'master_data.view', icon: 'inventory'),
                new Tile(label: __('master_data::menu.locations'), href: route('master_data.location.index'),
                    permission: 'master_data.view', icon: 'report'),
            ],

            stats: [
                /*
                 * ⚠️ শূন্য হলে `BAD` — আর সেটাই এই পর্দার আসল কাজ।
                 *
                 * একক ছাড়া পণ্য বানানো যায় না। সংখ্যাটা লাল দেখলে
                 * কারণটা এখানেই বোঝা যায়; নাহলে পণ্যের ফর্মে একটা
                 * খালি ড্রপডাউন দেখে কেউ ভাবতেন ফর্মটাই ভাঙা।
                 */
                new Stat(
                    label: __('master_data::menu.units'),
                    value: (string) Unit::query()->count(),
                    hint: __('master_data::dashboard.units_hint'),
                    href: route('master_data.unit.index'),
                    tone: Unit::query()->count() === 0 ? Stat::BAD : Stat::NEUTRAL,
                ),

                new Stat(
                    label: __('master_data::menu.taxes'),
                    value: (string) Tax::query()->count(),
                    hint: __('master_data::dashboard.taxes_hint'),
                    href: route('master_data.tax.index'),
                    tone: Tax::query()->count() === 0 ? Stat::BAD : Stat::NEUTRAL,
                ),

                new Stat(
                    label: __('master_data::menu.categories'),
                    value: (string) ProductCategory::query()->count(),
                    hint: __('master_data::dashboard.categories_hint'),
                    href: route('master_data.product_category.index'),
                ),

                new Stat(
                    label: __('master_data::menu.locations'),
                    value: (string) Location::query()->count(),
                    hint: __('master_data::dashboard.locations_hint'),
                    href: route('master_data.location.index'),
                ),
            ],

            panels: [
                new Breakdown(
                    label: __('master_data::dashboard.how_full'),
                    parts: [
                        ['label' => __('master_data::menu.units'), 'value' => (string) Unit::query()->count()],
                        ['label' => __('master_data::menu.categories'), 'value' => (string) ProductCategory::query()->count()],
                        ['label' => __('master_data::menu.brands'), 'value' => (string) Brand::query()->count()],
                        ['label' => __('master_data::menu.taxes'), 'value' => (string) Tax::query()->count()],
                    ],
                    hint: __('master_data::dashboard.how_full_hint'),
                ),
            ],
        );
    }
}
