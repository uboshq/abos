<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Dashboard;

use App\Core\Contracts\DashboardWidgets;
use App\Core\Dashboard\Widget;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\Money;
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
        return [...self::base(), ...self::kpis()];
    }

    /**
     * ⭐ হোমের মূল সূচক (দল `kpi`) — মালিক, ৫ অক্টোবর ২০২৬: হোমের পরিকল্পনা ২, প্রতিটা সংখ্যা একবারই।
     * মজুদের মূল্য — কেনা দরে, ফ্রি শূন্য ([[StockFacts::value()]]); দাম দেখার চাবি ছাড়া ঘরটাই নেই।
     *
     * @return list<Widget>
     */
    private static function kpis(): array
    {
        $value = app(\App\Modules\Inventory\Services\StockFacts::class)->value();

        if ($value === null) {
            return [];
        }

        return [
            new Widget(
                group: 'kpi',
                label: __('inventory::overview.stock_value'),
                value: Money::format($value),
                href: route('inventory.stock.index'),
                permission: 'inventory.cost.view',
                tone: 'money',
                hint: __('inventory::dashboard.kpi_stock_value_hint'),
                sort: 60,
                icon: 'inventory',
            ),
        ];
    }

    /** @return list<Widget> */
    private static function base(): array
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
                icon: 'alert-triangle',
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
        /*
         * ⭐ ড্যাশবোর্ডের একই প্রশ্ন, একই জায়গা থেকে — Inventory অডিট ম২৮, ৫ অক্টোবর ২০২৬।
         * ⛔ আগে এখানে নিজের হাতে লেখা কোয়েরি: শাখার পণ্য-তালিকা মানত কিন্তু শাখার গুদাম নয় (অন্য শাখার মাল "আছে" বলত),
         * আর ড্যাশবোর্ড উল্টোটা — দুই পর্দায় দুই সংখ্যা। ⓘ এখন দুটোই [[StockFacts::belowReorder()]]।
         */
        return app(\App\Modules\Inventory\Services\StockFacts::class)->belowReorder();
    }

    /** সংখ্যাটা রিপোর্ট থেকেই — ক্লিক করে যা খোলে, ঠিক তাই। */
    private static function reportRows(string $key): int
    {
        return app(ReportEngine::class)->run($key, perPage: 1)->totalRows;
    }

    /**
     * ⭐ পণ্য আর গুদাম তালিকার স্বাস্থ্য ([[MasterHealth]]) — পণ্যে একক আর শ্রেণি ভরা, একই নাম দুইবার হলে দ্বিতীয়বার;
     * গুদামে ঠিকানা ভরা, একই নাম দুইবার হলে দ্বিতীয়বার।
     *
     * @return list<Widget>
     */
    public static function health(): array
    {
        return [
            \App\Core\Dashboard\MasterHealth::widget(
                label: __('inventory::menu.products'),
                href: route('inventory.product.index'),
                permission: 'inventory.product.view',
                rows: Product::query(),
                complete: fn ($q) => $q->whereNotNull('inv_products.unit_id')->whereNotNull('inv_products.category_id'),
                sameColumn: 'name_en',
                sort: 30,
            ),
            \App\Core\Dashboard\MasterHealth::widget(
                label: __('inventory::menu.warehouses'),
                href: route('inventory.warehouse.index'),
                permission: 'inventory.warehouse.view',
                rows: \App\Modules\Inventory\Models\Warehouse::query(),
                complete: fn ($q) => $q->where(fn ($a) => $a->where('inv_warehouses.address_bn', '<>', '')->orWhere('inv_warehouses.address_en', '<>', '')),
                sameColumn: 'name_en',
                sort: 50,
            ),
        ];
    }
}
