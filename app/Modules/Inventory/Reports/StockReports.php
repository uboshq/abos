<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Modules\Inventory\Models\Product;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;

/**
 * মজুদের রিপোর্ট।
 *
 * তিনটাই চলাচলের টেবিল থেকে গোনা, কোনো সারাংশ কলাম থেকে নয় — তাই
 * পর্দার সংখ্যা আর রিপোর্টের সংখ্যা আলাদা হতে পারে না।
 */
final class StockReports
{
    public static function registerAll(ReportEngine $engine): void
    {
        $engine->register(self::stockLedger());
        $engine->register(self::stockSummary());
        $engine->register(self::holdReport());
        $engine->register(self::expiring());
    }

    /**
     * কোন লটগুলোর মেয়াদ ঘনিয়ে আসছে — আর কতটা পড়ে আছে।
     *
     * ── কেন সতর্কতা নয়, রিপোর্ট ────────────────────────────────────
     * মেয়াদোত্তীর্ণ মাল বিক্রয়ে আসেই না (BatchAllocator), কিন্তু ওটা
     * শেষ প্রতিরক্ষা — তখন টাকাটা ইতিমধ্যেই হারানো। যেটা টাকা বাঁচায়
     * সেটা হলো **আগে থেকে জানা**: এখনো তিন মাস আছে, এখন ফেরত পাঠানো
     * যায়, ছাড় দিয়ে বেচা যায়, বা অন্য শাখায় সরানো যায়।
     *
     * ── ইতিমধ্যে মেয়াদ পেরোনো লটও থাকে, ইচ্ছাকৃতভাবে ────────────────
     * ঋণাত্মক "কত দিন বাকি" নিয়ে ওগুলো তালিকার একদম উপরে বসে। ওগুলো
     * বাদ দিলে তালিকাটা পরিচ্ছন্ন দেখাত আর **যে মালটা আসলে গুদামে
     * পড়ে আছে সেটাই অদৃশ্য থাকত** — অথচ ওটাই সেই মাল যা নিয়ে এখনই
     * কিছু করতে হবে: ডিস্ট্রিবিউটরকে ফেরত, নয়তো লিখে ফেলা।
     *
     * ── শূন্য লট বাদ ───────────────────────────────────────────────
     * যে লট শেষ হয়ে গেছে তার মেয়াদ নিয়ে কারও কিছু করার নেই। ওগুলো
     * রাখলে ছয় মাসে তালিকাটা এত লম্বা হত যে কেউ পড়ত না।
     */
    public static function expiring(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'inventory.expiring',
            title: 'inventory::menu.expiring',
            filters: ['branch'],
            query: fn (array $f) => DB::table('inv_batches as b')
                ->join('inv_products as p', 'p.id', '=', 'b.product_id')
                ->leftJoin('inv_stock_movements as m', function ($join) {
                    $join->on('m.batch_id', '=', 'b.id');
                })
                ->where('b.company_id', $f['company_id'])
                ->whereNull('b.deleted_at')
                ->whereNotNull('b.expiry_date')
                ->when($f['branch_id'], fn ($q, $b) => $q->where('m.branch_id', $b))
                ->groupBy('b.id', 'b.batch_no', 'b.expiry_date', 'b.mrp', 'p.code', 'p.name_en')
                // শূন্য বা ঋণাত্মক লট বাদ — তালিকাটা কাজের জিনিস, ইতিহাস নয়
                ->havingRaw('COALESCE(SUM(m.floor_change), 0) > 0')
                ->orderBy('b.expiry_date')
                ->select([
                    'b.expiry_date',
                    'p.code as product_code',
                    'p.name_en as product_name',
                    'b.batch_no',
                    'b.mrp',
                    DB::raw('COALESCE(SUM(m.floor_change), 0) as on_hand'),
                    DB::raw('DATEDIFF(b.expiry_date, CURDATE()) as days_left'),
                ]),
            columns: [
                ['key' => 'expiry_date', 'label' => 'inventory::field.expiry_date', 'type' => ReportColumn::DATE, 'width' => '7rem'],
                ['key' => 'days_left', 'label' => 'inventory::field.days_left', 'width' => '6rem'],
                ['key' => 'product_code', 'label' => 'inventory::field.code', 'width' => '7rem'],
                ['key' => 'product_name', 'label' => 'inventory::field.product'],
                ['key' => 'batch_no', 'label' => 'inventory::field.batch_no', 'width' => '8rem'],
                ['key' => 'on_hand', 'label' => 'inventory::field.floor', 'type' => ReportColumn::MONEY],
                ['key' => 'mrp', 'label' => 'inventory::field.mrp', 'type' => ReportColumn::MONEY],
            ],
        );
    }

    /** এক পণ্যের প্রতিটা নড়াচড়া, ক্রমানুসারে। */
    public static function stockLedger(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'inventory.stock_ledger',
            title: 'inventory::menu.stock_ledger',
            filters: ['date_range', 'branch'],
            query: fn (array $f) => DB::table('inv_stock_movements as m')
                ->join('inv_products as p', 'p.id', '=', 'm.product_id')
                ->join('inv_warehouses as w', 'w.id', '=', 'm.warehouse_id')
                ->where('m.company_id', $f['company_id'])
                ->when($f['branch_id'], fn ($q, $b) => $q->where('m.branch_id', $b))
                ->whereBetween('m.trx_date', [$f['from'], $f['to']])
                ->orderBy('m.trx_date')
                ->orderBy('m.id')
                ->select([
                    'm.trx_date',
                    'm.document_no',
                    self::productName(),
                    'w.name_en as warehouse_name',
                    'm.floor_change',
                    'm.reserved_change',
                    'm.hold_change',
                    'm.narration',
                    'm.source_type',
                    'm.source_id',
                ]),
            columns: [
                ['key' => 'trx_date', 'label' => 'core.print.date', 'type' => ReportColumn::DATE, 'width' => '7rem'],
                [
                    'key' => 'document_no',
                    'label' => 'core.table.document',
                    'type' => ReportColumn::DOCUMENT,
                    'source_type' => 'source_type',
                    'source_id' => 'source_id',
                ],
                ['key' => 'product_name', 'label' => 'inventory::field.product'],
                ['key' => 'warehouse_name', 'label' => 'inventory::field.warehouse'],
                ['key' => 'floor_change', 'label' => 'inventory::field.floor', 'type' => ReportColumn::MONEY],
                ['key' => 'reserved_change', 'label' => 'inventory::field.reserved', 'type' => ReportColumn::MONEY],
                ['key' => 'hold_change', 'label' => 'inventory::field.hold', 'type' => ReportColumn::MONEY],
            ],
        );
    }

    /**
     * প্রতিটা পণ্যের চারটা অবস্থা, এক পাতায়।
     *
     * এই একটা টেবিলই ব্যবহারকারীর আসল প্রশ্নের উত্তর: "কী কত আছে, আর
     * তার কতটা বেচা যাবে"। চারটা আলাদা রিপোর্টে ভাগ করলে তিনটা খুলে
     * মনে মনে বিয়োগ করতে হত।
     */
    public static function stockSummary(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'inventory.stock_summary',
            title: 'inventory::menu.stock_summary',
            filters: ['date_range', 'branch'],
            groupBy: 'product_id',
            query: fn (array $f) => DB::table('inv_stock_movements as m')
                ->join('inv_products as p', 'p.id', '=', 'm.product_id')
                ->where('m.company_id', $f['company_id'])
                ->when($f['branch_id'], fn ($q, $b) => $q->where('m.branch_id', $b))
                // শুরুর তারিখ ধরা হয় না: মজুদ একটা মুহূর্তের অবস্থা,
                // পরিসরের নয় — ব্যালেন্স শিটে ঠিক একই যুক্তি
                ->where('m.trx_date', '<=', $f['to'])
                ->groupBy('m.product_id', 'p.code', 'p.name_en', 'p.name_bn')
                ->havingRaw('SUM(m.floor_change) <> 0 OR SUM(m.hold_change) <> 0')
                ->orderBy('p.code')
                ->select([
                    'm.product_id',
                    self::productName(),
                    DB::raw("'".Product::drillSourceType()."' as party_type_literal"),
                    DB::raw('SUM(m.floor_change) as floor'),
                    DB::raw('SUM(m.reserved_change) as reserved'),
                    DB::raw('SUM(m.hold_change) as hold'),
                    DB::raw('SUM(m.floor_change) - SUM(m.reserved_change) - SUM(m.hold_change) as available'),
                ]),
            columns: [
                [
                    'key' => 'product_name',
                    'label' => 'inventory::field.product',
                    'type' => ReportColumn::DOCUMENT,
                    'source_type' => 'party_type_literal',
                    'source_id' => 'product_id',
                ],
                ['key' => 'floor', 'label' => 'inventory::field.floor', 'type' => ReportColumn::MONEY],
                ['key' => 'reserved', 'label' => 'inventory::field.reserved', 'type' => ReportColumn::MONEY],
                ['key' => 'hold', 'label' => 'inventory::field.hold', 'type' => ReportColumn::MONEY],
                ['key' => 'available', 'label' => 'inventory::field.available', 'type' => ReportColumn::MONEY],
            ],
        );
    }

    /**
     * আটকানো মাল — কারণ ধরে ভাগ করা।
     *
     * এই ভাগটাই এই রিপোর্টের একমাত্র কারণ। "৪০ কার্টন আটকানো" বললে
     * মালিক ভাবতেন তার মালে সমস্যা; "৫ ক্ষতিগ্রস্ত, ৩৫ দাম বাড়ার
     * অপেক্ষায়" বললে তিনি জানেন ৩৫টা তার নিজের সিদ্ধান্ত।
     */
    public static function holdReport(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'inventory.hold',
            title: 'inventory::menu.hold_report',
            filters: ['date_range', 'branch'],
            groupBy: 'product_id',
            query: fn (array $f) => DB::table('inv_stock_movements as m')
                ->join('inv_products as p', 'p.id', '=', 'm.product_id')
                ->leftJoin('mdm_reason_codes as r', 'r.id', '=', 'm.reason_code_id')
                ->where('m.company_id', $f['company_id'])
                ->when($f['branch_id'], fn ($q, $b) => $q->where('m.branch_id', $b))
                ->where('m.trx_date', '<=', $f['to'])
                ->where('m.hold_change', '<>', 0)
                ->groupBy('m.product_id', 'p.code', 'p.name_en', 'p.name_bn', 'm.reason_code_id', 'r.name_en', 'r.name_bn')
                ->havingRaw('SUM(m.hold_change) <> 0')
                ->orderBy('p.code')
                ->select([
                    'm.product_id',
                    self::productName(),
                    DB::raw("'".Product::drillSourceType()."' as party_type_literal"),
                    self::reasonName(),
                    DB::raw('SUM(m.hold_change) as held'),
                ]),
            columns: [
                [
                    'key' => 'product_name',
                    'label' => 'inventory::field.product',
                    'type' => ReportColumn::DOCUMENT,
                    'source_type' => 'party_type_literal',
                    'source_id' => 'product_id',
                ],
                ['key' => 'reason_name', 'label' => 'inventory::field.reason'],
                ['key' => 'held', 'label' => 'inventory::field.hold', 'type' => ReportColumn::MONEY],
            ],
        );
    }

    /**
     * পণ্যের নাম — কোড সহ, ব্যবহারকারীর ভাষায়।
     */
    private static function productName(): Expression
    {
        $name = app()->getLocale() === 'bn'
            ? "COALESCE(NULLIF(p.name_bn, ''), p.name_en)"
            : 'p.name_en';

        return DB::raw("CONCAT(p.code, ' - ', {$name}) as product_name");
    }

    /**
     * কারণের নাম।
     *
     * কারণ ছাড়া আটকানো যায় না, তবু LEFT JOIN ও একটা fallback: মুছে ফেলা
     * কারণ-কোড থাকলে সারিটা যেন উধাও না হয়। হিসাবের রিপোর্টে খাতের নামে
     * INNER JOIN দিয়ে সারি হারানোটা একবার ধরাও পড়েছিল।
     */
    private static function reasonName(): Expression
    {
        $name = app()->getLocale() === 'bn'
            ? "COALESCE(NULLIF(r.name_bn, ''), r.name_en)"
            : 'r.name_en';

        return DB::raw("COALESCE({$name}, '?') as reason_name");
    }
}
