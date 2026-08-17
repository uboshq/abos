<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Dashboard;

use App\Core\Contracts\DashboardWidgets;
use App\Core\Dashboard\Widget;
use App\Core\Engines\Report\ReportEngine;
use App\Modules\Inventory\Models\Product;

/**
 * গুদামের সংখ্যাগুলো হোম পর্দায়।
 *
 * ── কেন "ফুরিয়ে আসছে" আর "আটকানো" — মোট মজুদ নয় ─────────────────────
 * মোট মজুদ একটা বড় সংখ্যা যা দেখে কিছু করার থাকে না। কাজের প্রশ্ন
 * দুইটা: কোনগুলো ফুরিয়ে যাচ্ছে (নাহলে কাল বিক্রি আটকাবে), আর কতটা মাল
 * আটকানো আছে (ক্ষতি, মেয়াদ, বা দাম-হোল্ড — শেষটা সিদ্ধান্ত, ত্রুটি নয়)।
 */
final class InventoryWidgets implements DashboardWidgets
{
    /** @return list<Widget> */
    public static function widgets(): array
    {
        return [
            /*
             * পুনঃক্রয় সীমার নিচে নেমে আসা পণ্য।
             *
             * সীমাটা যাদের বসানো হয়নি (০) তারা বাদ — নাহলে প্রতিটা
             * নতুন পণ্য "ফুরিয়ে গেছে" হিসেবে গোনা হত, আর সংখ্যাটা
             * এত বড় হত যে কেউ আর তাকাত না।
             */
            new Widget(
                group: 'todo',
                label: __('inventory::dashboard.below_reorder'),
                value: (string) self::belowReorder(),
                href: route('inventory.stock.index', ['sort' => 'available']),
                permission: 'inventory.stock.view',
                tone: 'warn',
                sort: 80,
                icon: 'inventory',
            ),

            new Widget(
                group: 'todo',
                label: __('inventory::dashboard.on_hold'),
                value: (string) self::reportRows('inventory.hold'),
                href: route('inventory.report.show', ['slug' => 'hold']),
                permission: 'inventory.report',
                tone: 'neutral',
                sort: 90,
                icon: 'lock',
            ),
        ];
    }

    /**
     * সীমার নিচে কতটা পণ্য।
     *
     * বিক্রয়যোগ্য = তাকে যা আছে − অর্ডারে ধরা − আটকানো। ঠিক এই হিসাবটাই
     * স্টক পর্দায় "Available" কলামে দেখায়, তাই ক্লিক করে নামলে সংখ্যাটা
     * মেলে — দুই জায়গায় দুই হিসাব থাকলে মিলত না।
     */
    private static function belowReorder(): int
    {
        $available = '(select COALESCE(SUM(m.floor_change - m.reserved_change - m.hold_change), 0)
                       from inv_stock_movements m
                       where m.product_id = inv_products.id
                         and m.company_id = inv_products.company_id)';

        return Product::query()
            ->active()
            ->where('reorder_level', '>', 0)
            ->whereRaw("{$available} <= inv_products.reorder_level")
            ->count();
    }

    /** সংখ্যাটা রিপোর্ট থেকেই — ক্লিক করে যা খোলে, ঠিক তাই। */
    private static function reportRows(string $key): int
    {
        return app(ReportEngine::class)->run($key, perPage: 1)->totalRows;
    }
}
