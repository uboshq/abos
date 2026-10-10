<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Modules\Inventory\Services\StockService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * মজুদের নিয়ন্ত্রণের রিপোর্ট — রিপোর্ট সেন্টার (মালিক, ১ অক্টোবর ২০২৬)।
 *
 * ⓘ সব রিপোর্ট একই ছাঁকনির নাম ঘোষণা করে (`warehouse_id`, `product_id`, `brand_id`, `category_id` …) — রিপোর্ট
 * সেন্টারের ধাপ ১ ঘরগুলো আঁকলে এগুলো আপনা থেকে চলে; শাখার দেয়াল প্রতিটায় ([[ReportEngine::branchWall()]])।
 */
final class InventoryControlReports
{
    public static function registerAll(ReportEngine $engine): void
    {
        $engine->register(self::countVsBook());
        $engine->register(self::stockAlerts());
        $engine->register(self::slowAndDead());
        $engine->register(self::lotTrace());
    }

    /**
     * লটের গতিপথ — ধাপ ৪: *"লট ও মেয়াদ: মেয়াদ শেষ / শেষের পথে, লটের গতিপথ"* (মেয়াদের অংশ [[StockReports::expiring()]])।
     *
     * একটা লট কোথা থেকে এল, কোন গুদামে গেল, কাকে কোন কাগজে বেচা হলো — প্রতিটা চলাচল এক সারি, কাগজটা খোলা যায়।
     * ⓘ `batch_id` দিলে কেবল সেই লট (রিকলের প্রশ্ন: *"এই লটের মাল কোথায় কোথায় গেছে"*); না দিলে সময়ের সব লটের চলাচল।
     * ⓘ ঢোকা/বেরোনো "হাতে"-র মাপে (তাকে + বসানো বাকি + আটকে), ফ্রি আলাদা — বসানো বা আটকানো তাই শূন্য সারি নয়, বাদ।
     */
    public static function lotTrace(): ReportDefinition
    {
        $qty = '(m.floor_change + m.unplaced_change)';
        $free = '(m.free_change + m.unplaced_free_change)';

        return new ReportDefinition(
            key: 'inventory.lot_trace',
            permission: 'inventory.report',
            title: 'inventory::control.lot_trace',
            filters: ['date_range', 'branch', 'batch_id', 'product_id', 'warehouse_id'],
            query: fn (array $f) => DB::table('inv_stock_movements as m')
                ->join('inv_batches as b', 'b.id', '=', 'm.batch_id')
                ->join('inv_products as p', 'p.id', '=', 'm.product_id')
                ->join('inv_warehouses as w', 'w.id', '=', 'm.warehouse_id')
                ->where('m.company_id', $f['company_id'])
                ->tap(ReportEngine::branchWall($f, 'w.branch_id'))
                ->tap(ReportEngine::warehouseWall($f, 'm.warehouse_id'))
                ->whereBetween('m.trx_date', [$f['from'], $f['to']])
                ->whereRaw("({$qty} <> 0 OR {$free} <> 0)")
                ->when(! empty($f['batch_id']), fn ($q) => $q->where('m.batch_id', (int) $f['batch_id']))
                ->when(! empty($f['product_id']), fn ($q) => $q->where('m.product_id', (int) $f['product_id']))
                ->when(! empty($f['warehouse_id']), fn ($q) => $q->where('m.warehouse_id', (int) $f['warehouse_id']))
                ->orderBy('b.batch_no')
                ->orderBy('m.trx_date')
                ->orderBy('m.id')
                ->select([
                    'b.batch_no',
                    'b.expiry_date',
                    DB::raw("CONCAT(p.code, ' - ', ".self::name('p').') as product_name'),
                    'm.trx_date',
                    'm.document_no',
                    'm.source_type',
                    'm.source_id',
                    DB::raw(self::name('w').' as warehouse_name'),
                    DB::raw("CASE WHEN {$qty} > 0 THEN {$qty} ELSE 0 END as qty_in"),
                    DB::raw("CASE WHEN {$qty} < 0 THEN -{$qty} ELSE 0 END as qty_out"),
                    DB::raw("{$free} as free_change"),
                ]),
            columns: [
                ['key' => 'batch_no', 'label' => 'inventory::field.batch_no', 'width' => '8rem'],
                ['key' => 'expiry_date', 'label' => 'inventory::field.expiry_date', 'type' => ReportColumn::DATE, 'width' => '7rem'],
                ['key' => 'product_name', 'label' => 'inventory::field.product'],
                ['key' => 'trx_date', 'label' => 'core.print.date', 'type' => ReportColumn::DATE, 'width' => '7rem'],
                [
                    'key' => 'document_no',
                    'label' => 'core.table.document',
                    'type' => ReportColumn::DOCUMENT,
                    'source_type' => 'source_type',
                    'source_id' => 'source_id',
                ],
                ['key' => 'warehouse_name', 'label' => 'inventory::field.warehouse', 'width' => '9rem'],
                ['key' => 'qty_in', 'label' => 'inventory::control.qty_in', 'type' => ReportColumn::QUANTITY],
                ['key' => 'qty_out', 'label' => 'inventory::control.qty_out', 'type' => ReportColumn::QUANTITY],
                ['key' => 'free_change', 'label' => 'inventory::field.free', 'type' => ReportColumn::QUANTITY],
            ],
        );
    }

    /** ধীর মালের সীমা — শেষ বেরোনোর পর এতদিন */
    public const SLOW_DAYS = 90;

    /** অচল মালের সীমা — শেষ বেরোনোর পর এতদিন (বা কোনোদিন বেরোয়নি) */
    public const DEAD_DAYS = 180;

    /**
     * ধীর ও অচল মাল, বছরে কতবার ঘোরে — ধাপ ৪।
     *
     * পণ্য প্রতি এক সারি, হেডারে বাছা শাখায়, কেবল যাঁর হাতে মাল আছে:
     *   শেষ বেরোনো      তাক থেকে শেষ কবে মাল কমেছে (যেকোনো কাগজে)
     *   দিন             তারপর কতদিন
     *   গত ৩৬৫ দিনে গেল সেই সময়ে কত বেরিয়েছে
     *   ঘোরে            গত বছরে বেরোনো ÷ এখন হাতে — "বছরে কতবার"; ০ মানে এক বছর একটাও যায়নি
     *   মূল্য            হাতে × পণ্যের স্তরের গড় দর (স্তর কোম্পানির, শাখার নয় — [[CostLayerService]])
     * ⓘ অবস্থা: অচল = ১৮০ দিনে একবারও বেরোয়নি (বা কোনোদিন না), ধীর = ৯০ দিনে; বাকিরা তালিকায় আসে না।
     */
    public static function slowAndDead(): ReportDefinition
    {
        $slow = self::SLOW_DAYS;
        $dead = self::DEAD_DAYS;
        /*
         * ⭐ "আজ" অ্যাপের ঘড়ি থেকে, ডেটাবেসের নয় — Inventory অডিট ম২৯, ৫ অক্টোবর ২০২৬।
         * ⛔ আগে ডেটাবেসের নিজের "আজ": ডেটাবেস সার্ভার UTC-তে চলে, তাই বাংলাদেশের রাত ১২টা থেকে ভোর ৬টা পর্যন্ত "আজ" ছিল গতকাল —
         * অলস দিনের সংখ্যা এক কম, আর ৯০/১৮০ দিনের সীমায় দাঁড়ানো পণ্য ঐ ছয় ঘণ্টা ভুল ঘরে। ⓘ তারিখটা ডাকার সময়ই গোনা হয়,
         * সংজ্ঞা বানানোর সময় নয় — নইলে দীর্ঘ চলা প্রক্রিয়া কালকের "আজ" রাখত।
         */
        $state = fn (string $today) => "CASE
                WHEN s.last_out IS NULL OR DATEDIFF({$today}, s.last_out) >= {$dead} THEN 'dead'
                WHEN DATEDIFF({$today}, s.last_out) >= {$slow} THEN 'slow'
            END";
        $labels = fn (string $state) => "CASE ({$state}) WHEN 'dead' THEN ".DB::getPdo()->quote((string) __('inventory::control.state_dead'))
            .' WHEN \'slow\' THEN '.DB::getPdo()->quote((string) __('inventory::control.state_slow')).' END';

        $avgCost = '(select CASE WHEN SUM(cl.qty_remaining) > 0 THEN SUM(cl.qty_remaining * cl.unit_cost) / SUM(cl.qty_remaining) END
                from inv_cost_layers cl where cl.product_id = p.id and cl.qty_remaining > 0)';

        return new ReportDefinition(
            key: 'inventory.slow_dead',
            permission: 'inventory.report',
            title: 'inventory::control.slow_dead',
            filters: ['branch', 'warehouse_id', 'brand_id', 'category_id', 'status'],
            query: function (array $f) use ($state, $labels, $avgCost) {
                $today = DB::getPdo()->quote(Carbon::today()->toDateString());
                $state = $state($today);
                $labels = $labels($state);

                return DB::query()
                    ->fromSub(
                        DB::table('inv_stock_movements as m')
                            ->join('inv_warehouses as w', 'w.id', '=', 'm.warehouse_id')
                            ->where('m.company_id', $f['company_id'])
                            ->tap(ReportEngine::branchWall($f, 'w.branch_id'))
                            ->tap(ReportEngine::warehouseWall($f, 'm.warehouse_id'))
                            ->when(! empty($f['warehouse_id']), fn ($q) => $q->where('m.warehouse_id', (int) $f['warehouse_id']))
                            ->groupBy('m.product_id')
                            ->selectRaw('m.product_id')
                            ->selectRaw('SUM(m.floor_change + m.unplaced_change) as on_hand')
                            ->selectRaw('MAX(CASE WHEN m.floor_change < 0 THEN m.trx_date END) as last_out')
                            ->selectRaw("SUM(CASE WHEN m.floor_change < 0 AND m.trx_date >= DATE_SUB({$today}, INTERVAL 365 DAY) THEN -m.floor_change ELSE 0 END) as out_year"),
                        's',
                    )
                    ->join('inv_products as p', 'p.id', '=', 's.product_id')
                    ->whereNull('p.deleted_at')
                    ->where('s.on_hand', '>', 0)
                    ->when(! empty($f['brand_id']), fn ($q) => $q->where('p.brand_id', (int) $f['brand_id']))
                    ->when(! empty($f['category_id']), fn ($q) => $q->where('p.category_id', (int) $f['category_id']))
                    ->whereRaw("({$state}) IS NOT NULL")
                    ->when(in_array($f['status'] ?? null, ['slow', 'dead'], true),
                        fn ($q) => $q->whereRaw("({$state}) = ?", [$f['status']]))
                    ->orderByRaw('s.last_out IS NOT NULL')
                    ->orderBy('s.last_out')
                    ->orderBy('p.code')
                    ->select([
                        DB::raw("{$labels} as state"),
                        DB::raw("CONCAT(p.code, ' - ', ".self::name('p').') as product_name'),
                        's.last_out',
                        DB::raw("DATEDIFF({$today}, s.last_out) as idle_days"),
                        's.on_hand',
                        's.out_year',
                        DB::raw('ROUND(s.out_year / s.on_hand, 2) as turns'),
                        DB::raw("ROUND(s.on_hand * COALESCE({$avgCost}, 0), 4) as value"),
                    ]);
            },
            columns: [
                ['key' => 'state', 'label' => 'inventory::control.state', 'width' => '6rem'],
                ['key' => 'product_name', 'label' => 'inventory::field.product'],
                ['key' => 'last_out', 'label' => 'inventory::control.last_out', 'type' => ReportColumn::DATE, 'width' => '7rem'],
                ['key' => 'idle_days', 'label' => 'inventory::control.idle_days', 'width' => '6rem'],
                ['key' => 'on_hand', 'label' => 'inventory::control.on_hand', 'type' => ReportColumn::QUANTITY],
                ['key' => 'out_year', 'label' => 'inventory::control.out_year', 'type' => ReportColumn::QUANTITY],
                ['key' => 'turns', 'label' => 'inventory::control.turns', 'width' => '6rem'],
                ['key' => 'value', 'label' => 'inventory::control.value', 'type' => ReportColumn::MONEY, 'permission' => 'inventory.cost.view'],
            ],
        );
    }

    /**
     * মজুদের সতর্কতা — ধাপ ৪: *"সীমার নিচে, শূন্য/মাইনাস, বেশি জমা"*।
     *
     * পণ্য প্রতি এক সারি, হেডারে বাছা শাখার গুদামগুলো মিলিয়ে, আর কোন সতর্কতা:
     *   below    বেচা-যোগ্য (তাকে − ধরা − আটকে) পুনঃক্রয়ের স্তরে বা নিচে — [[StockReports::replenishment()]]-এর একই মাপ
     *   zero     হাতে শূন্য (চলাচল আছে, মাল নেই)
     *   negative হাতে শূন্যের নিচে — খাতার ভুল, আগে দেখার
     *   over     সর্বোচ্চ মজুদের উপরে — টাকা গুদামে আটকে
     * ⓘ "হাতে" = তাকে + না-বসানো + আটকে। ⓘ স্তর আর সর্বোচ্চ পণ্যের; শূন্য মানে "বলা নেই", তাই সেই সতর্কতা ওঠে না।
     * ⓘ গোনা আগে পণ্য ধরে একটা ভেতরের কোয়েরিতে, তারপর পণ্যের সাথে জোড়া — লাইভ MariaDB-র ONLY_FULL_GROUP_BY
     * পণ্যের নাম GROUP BY-তে না থাকলে আপত্তি তোলে।
     */
    public static function stockAlerts(): ReportDefinition
    {
        $kind = "CASE
                WHEN s.on_hand < 0 THEN 'negative'
                WHEN s.on_hand = 0 THEN 'zero'
                WHEN p.reorder_level > 0 AND s.available <= p.reorder_level THEN 'below'
                WHEN p.max_level > 0 AND s.on_hand > p.max_level THEN 'over'
            END";

        // ⓘ নামগুলো রিপোর্ট চালানোর সময় গড়া — বুটে নয়: `composer install`-এর package:discover-এ ডেটাবেস থাকে না
        $labels = fn (): string => collect(['negative', 'zero', 'below', 'over'])
            ->map(fn (string $k) => "WHEN '{$k}' THEN ".DB::getPdo()->quote((string) __('inventory::control.alert_'.$k)))
            ->implode(' ');

        return new ReportDefinition(
            key: 'inventory.stock_alerts',
            permission: 'inventory.report',
            title: 'inventory::control.stock_alerts',
            filters: ['branch', 'warehouse_id', 'brand_id', 'category_id', 'status'],
            query: fn (array $f) => DB::query()
                ->fromSub(
                    DB::table('inv_stock_movements as m')
                        ->join('inv_warehouses as w', 'w.id', '=', 'm.warehouse_id')
                        ->where('m.company_id', $f['company_id'])
                        ->tap(ReportEngine::branchWall($f, 'w.branch_id'))
                        ->tap(ReportEngine::warehouseWall($f, 'm.warehouse_id'))
                        ->when(! empty($f['warehouse_id']), fn ($q) => $q->where('m.warehouse_id', (int) $f['warehouse_id']))
                        ->groupBy('m.product_id')
                        ->selectRaw('m.product_id')
                        ->selectRaw('SUM(m.floor_change + m.unplaced_change) as on_hand')
                        ->selectRaw('SUM('.StockService::availableSql('m.').') as available'),
                    's',
                )
                ->join('inv_products as p', 'p.id', '=', 's.product_id')
                ->whereNull('p.deleted_at')
                ->where('p.is_active', true)
                ->when(! empty($f['brand_id']), fn ($q) => $q->where('p.brand_id', (int) $f['brand_id']))
                ->when(! empty($f['category_id']), fn ($q) => $q->where('p.category_id', (int) $f['category_id']))
                ->whereRaw("({$kind}) IS NOT NULL")
                ->when(in_array($f['status'] ?? null, ['negative', 'zero', 'below', 'over'], true),
                    fn ($q) => $q->whereRaw("({$kind}) = ?", [$f['status']]))
                ->orderByRaw("FIELD(({$kind}), 'negative', 'zero', 'below', 'over')")
                ->orderBy('p.code')
                ->select([
                    DB::raw("CASE ({$kind}) {$labels()} END as alert"),
                    DB::raw("CONCAT(p.code, ' - ', ".self::name('p').') as product_name'),
                    's.on_hand',
                    's.available',
                    'p.reorder_level',
                    'p.max_level',
                ]),
            columns: [
                ['key' => 'alert', 'label' => 'inventory::control.alert', 'width' => '9rem'],
                ['key' => 'product_name', 'label' => 'inventory::field.product'],
                ['key' => 'on_hand', 'label' => 'inventory::control.on_hand', 'type' => ReportColumn::QUANTITY, 'total' => false],
                ['key' => 'available', 'label' => 'inventory::field.available', 'type' => ReportColumn::QUANTITY, 'total' => false],
                ['key' => 'reorder_level', 'label' => 'inventory::overview.reorder_level', 'type' => ReportColumn::QUANTITY, 'total' => false],
                ['key' => 'max_level', 'label' => 'inventory::field.max_level', 'type' => ReportColumn::QUANTITY, 'total' => false],
            ],
        );
    }

    /**
     * গণনা বনাম খাতা — ধাপ ৬: *"গণনা বনাম খাতা, কম-বেশি"*।
     *
     * প্রতিটা গোনা লাইন এক সারি: কোন গণনা, কোন গুদাম, কোন পণ্য/লট, খাতায় কত, গুনে কত, কম-বেশি, আর তার টাকা (লাইনের
     * নিজের দরে — গণনা যে দরে সমন্বয় বসিয়েছিল)। ⓘ বাতিল গণনা বাদ; খসড়াও দেখায়, কারণ "এখনো বসেনি এমন ফারাক"
     * জানাটাই কাজের।
     */
    public static function countVsBook(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'inventory.count_vs_book',
            permission: 'inventory.report',
            title: 'inventory::control.count_vs_book',
            filters: ['date_range', 'branch', 'warehouse_id', 'product_id'],
            query: fn (array $f) => DB::table('inv_stock_count_lines as l')
                ->join('inv_stock_counts as c', 'c.id', '=', 'l.stock_count_id')
                ->join('inv_products as p', 'p.id', '=', 'l.product_id')
                ->join('inv_warehouses as w', 'w.id', '=', 'c.warehouse_id')
                ->leftJoin('inv_batches as b', 'b.id', '=', 'l.batch_id')
                ->where('c.company_id', $f['company_id'])
                ->tap(ReportEngine::branchWall($f, 'c.branch_id'))
                ->tap(ReportEngine::warehouseWall($f, 'c.warehouse_id'))
                ->whereBetween('c.count_date', [$f['from'], $f['to']])
                ->whereNull('c.deleted_at')
                ->where('c.status', '<>', 'cancelled')
                ->when(! empty($f['warehouse_id']), fn ($q) => $q->where('c.warehouse_id', (int) $f['warehouse_id']))
                ->when(! empty($f['product_id']), fn ($q) => $q->where('l.product_id', (int) $f['product_id']))
                ->orderBy('c.count_date')
                ->orderBy('c.document_no')
                ->orderBy('l.id')
                ->select([
                    'c.count_date',
                    'c.document_no',
                    DB::raw(self::name('w').' as warehouse_name'),
                    DB::raw("CONCAT(p.code, ' - ', ".self::name('p').') as product_name'),
                    'b.batch_no',
                    'l.book_qty',
                    'l.counted_qty',
                    DB::raw('l.counted_qty - l.book_qty as difference'),
                    DB::raw('(l.counted_qty - l.book_qty) * l.unit_cost as difference_value'),
                ]),
            columns: [
                ['key' => 'count_date', 'label' => 'core.print.date', 'type' => ReportColumn::DATE, 'width' => '7rem'],
                // ⓘ গণনার কাগজ খোলার পথ নেই (ড্রিলযোগ্য নয়) — নম্বরটা লেখা হিসেবেই
                ['key' => 'document_no', 'label' => 'core.table.document', 'width' => '9rem'],
                ['key' => 'warehouse_name', 'label' => 'inventory::field.warehouse', 'width' => '10rem'],
                ['key' => 'product_name', 'label' => 'inventory::field.product'],
                ['key' => 'batch_no', 'label' => 'inventory::field.batch_no', 'width' => '8rem'],
                ['key' => 'book_qty', 'label' => 'inventory::control.book_qty', 'type' => ReportColumn::QUANTITY],
                ['key' => 'counted_qty', 'label' => 'inventory::control.counted_qty', 'type' => ReportColumn::QUANTITY],
                ['key' => 'difference', 'label' => 'inventory::control.difference', 'type' => ReportColumn::QUANTITY],
                ['key' => 'difference_value', 'label' => 'inventory::control.difference_value', 'type' => ReportColumn::MONEY, 'permission' => 'inventory.cost.view'],
            ],
        );
    }

    /** পাতার ভাষায় নাম — বাংলায় বাংলা নাম, না থাকলে ইংরেজি */
    private static function name(string $alias): string
    {
        return app()->getLocale() === 'bn'
            ? "COALESCE(NULLIF({$alias}.name_bn, ''), {$alias}.name_en)"
            : "{$alias}.name_en";
    }
}
