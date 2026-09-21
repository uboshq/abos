<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Modules\Inventory\Models\Product;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Carbon;
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
        $engine->register(self::stockByBatch());
        $engine->register(self::stockValue());
    }

    /**
     * একই মজুদ, কিন্তু টাকায় — প্রতিটা ঘরে পরিমাণ **আর** মূল্য।
     *
     * ── ⭐ মালিকের নির্দেশ, ২১ সেপ্টেম্বর ২০২৬ ───────────────────────
     * *"মজুদ list e in Qty with Velu/Amount diye alada মজুদ list koro
     * zate purches price, sales price, opening stock, in, out, Closing
     * stock protitir qnty soho duti velu thakbe"*।
     *
     * ⓘ **আলাদা** রিপোর্ট, পুরনোটায় কলাম যোগ নয় — আর সেটাই চাওয়া
     * হয়েছে। মজুদের পর্দা দিনে বহুবার খোলা হয় গুদামের প্রশ্নে ("কত
     * আছে, কতটা বেচা যাবে"), আর সেখানে বারোটা কলাম বসালে রোজকার
     * কাজটাই কঠিন হত। ⚠️ টাকার প্রশ্নটা আলাদা মানুষ, আলাদা সময়ে করেন।
     *
     * ── ⛔ সংখ্যাগুলো পণ্যের দাম গুণ করে বের করা **হয় না** ───────────
     * সহজ পথটা হত `qty × purchase_price`, আর সেটা **মিথ্যা** হত: আজকের
     * ক্রয়মূল্য দিয়ে ছয় মাস আগের মাল মাপা হত। ⓘ একই পণ্য ৮০ টাকায়
     * কিনে পরে ৯৫-এ কিনলে দুইটা দামই সত্য, আর কোনটা কোন মালে বসবে
     * সেটা FIFO ঠিক করে।
     *
     * ⭐ তাই টাকাটা আসে খরচের স্তর থেকে, যেখানে হিসাবটা আগেই লেখা:
     *   · আগমন  → [[CostLayer]]      — `qty_in`, `unit_cost`
     *   · নির্গমন → [[CostLayerUse]]  — `qty`, আর **`amount` লেখাই আছে**
     *
     * ⓘ অর্থাৎ এই রিপোর্টের টাকা আর খাতার টাকা একই উৎস থেকে আসে; দুইটা
     * আলাদা হওয়ার উপায় নেই। প্রারম্ভিক মজুদেও দাম বসে
     * ([[OpeningStockService]]), তাই পুরনো মালের ঘর ফাঁকা থাকে না।
     *
     * ── ⚠️ শাখার ছাঁকনি নেই, আর সেটা লুকানো হয়নি ────────────────────
     * ⛔ `inv_cost_layers`-এ `branch_id` নেই — খরচ কোম্পানিভিত্তিক, কারণ
     * এক শাখার কেনা মাল অন্য শাখা থেকে বেচা যায় আর তাতে দামটা বদলায়
     * না। ⓘ ছাঁকনিটা দেখিয়ে **উপেক্ষা** করলে সংখ্যাটা ভুল বলে পড়া হত,
     * তাই ঘরটাই নেই।
     */
    public static function stockValue(): ReportDefinition
    {
        /*
         * ⓘ চারটা উপ-কোয়েরি, তারপর বিয়োগ — কারণ "প্রারম্ভিক" বলে কোনো
         * টেবিল নেই। ⚠️ প্রারম্ভিক = শুরুর তারিখের **আগের** সব আগমন
         * বিয়োগ সব নির্গমন, ঠিক যেভাবে খাতার ওপেনিং বের হয়।
         */
        $in = fn (array $f, ?string $from) => DB::table('inv_cost_layers')
            ->where('company_id', $f['company_id'])
            ->when($from, fn ($q) => $q->where('trx_date', '>=', $from))
            ->where('trx_date', '<=', $f['to'])
            ->groupBy('product_id')
            ->select([
                'product_id',
                DB::raw('SUM(qty_in) as q'),
                DB::raw('SUM(qty_in * unit_cost) as v'),
            ]);

        $out = fn (array $f, ?string $from) => DB::table('inv_cost_layer_uses')
            ->where('company_id', $f['company_id'])
            ->when($from, fn ($q) => $q->where('trx_date', '>=', $from))
            ->where('trx_date', '<=', $f['to'])
            ->groupBy('product_id')
            ->select([
                'product_id',
                DB::raw('SUM(qty) as q'),
                /* ⓘ `amount` স্তরে লেখাই আছে — গুণ করে বের করা হয় না। */
                DB::raw('SUM(amount) as v'),
            ]);

        return new ReportDefinition(
            key: 'inventory.stock_value',
            title: 'inventory::menu.stock_value',
            filters: ['date_range'],
            groupBy: 'product_id',
            query: function (array $f) use ($in, $out) {
                /*
                 * ⓘ প্রারম্ভিকের জন্য উপ-কোয়েরিগুলো চলে **শুরুর আগের
                 * দিন পর্যন্ত**, তাই `to` বদলে দেওয়া হয়। ⚠️ `from`-ও
                 * একই দিন ধরলে শুরুর দিনের লেনদেন দুইবার গোনা হত —
                 * একবার প্রারম্ভিকে, একবার "আগমনে"।
                 */
                $before = ['company_id' => $f['company_id']] + [
                    'to' => Carbon::parse($f['from'])->subDay()->toDateString(),
                ];

                return DB::table('inv_products as p')
                    ->leftJoin('mdm_units as u', 'u.id', '=', 'p.unit_id')
                    ->leftJoinSub($in($before, null), 'oi', 'oi.product_id', '=', 'p.id')
                    ->leftJoinSub($out($before, null), 'oo', 'oo.product_id', '=', 'p.id')
                    ->leftJoinSub($in($f, $f['from']), 'pi', 'pi.product_id', '=', 'p.id')
                    ->leftJoinSub($out($f, $f['from']), 'po', 'po.product_id', '=', 'p.id')
                    ->where('p.company_id', $f['company_id'])
                    /*
                     * ⛔ যে পণ্যের এই পরিসরে কিছুই ঘটেনি আর প্রারম্ভিকও
                     * শূন্য, তার সারি আসে না। ⚠️ নাহলে গোটা পণ্য-তালিকা
                     * শূন্যের সারি হয়ে ছাপা হত, আর আসল সারিগুলো তার
                     * ভিতরে হারাত।
                     */
                    ->whereRaw('COALESCE(oi.q,0) <> 0 OR COALESCE(oo.q,0) <> 0
                                OR COALESCE(pi.q,0) <> 0 OR COALESCE(po.q,0) <> 0')
                    ->orderBy('p.code')
                    ->select([
                        'p.id as product_id',
                        self::productName(),
                        DB::raw("'".Product::drillSourceType()."' as party_type_literal"),
                        DB::raw(app()->getLocale() === 'bn'
                            ? "COALESCE(NULLIF(u.name_bn, ''), u.name_en) as unit_name"
                            : 'u.name_en as unit_name'),

                        'p.purchase_price',
                        'p.sale_price',

                        DB::raw('COALESCE(oi.q,0) - COALESCE(oo.q,0) as opening_qty'),
                        DB::raw('COALESCE(oi.v,0) - COALESCE(oo.v,0) as opening_value'),

                        DB::raw('COALESCE(pi.q,0) as in_qty'),
                        DB::raw('COALESCE(pi.v,0) as in_value'),

                        DB::raw('COALESCE(po.q,0) as out_qty'),
                        DB::raw('COALESCE(po.v,0) as out_value'),

                        /*
                         * ⓘ সমাপনী গোনা হয়, আলাদা করে খোঁজা হয় না —
                         * তাই "প্রারম্ভিক + আগমন − নির্গমন" সমীকরণটা
                         * সারিতে সবসময় মেলে। ⚠️ দুই জায়গা থেকে দুইভাবে
                         * আনলে কোনো একদিন দুইটা আলাদা হত, আর পাঠক
                         * বুঝতেন না কোনটা সত্য।
                         */
                        DB::raw('COALESCE(oi.q,0) - COALESCE(oo.q,0)
                                 + COALESCE(pi.q,0) - COALESCE(po.q,0) as closing_qty'),
                        DB::raw('COALESCE(oi.v,0) - COALESCE(oo.v,0)
                                 + COALESCE(pi.v,0) - COALESCE(po.v,0) as closing_value'),
                    ]);
            },
            columns: [
                [
                    'key' => 'product_name',
                    'label' => 'inventory::field.product',
                    'type' => ReportColumn::DOCUMENT,
                    'source_type' => 'party_type_literal',
                    'source_id' => 'product_id',
                ],
                ['key' => 'unit_name', 'label' => 'inventory::field.unit', 'width' => '5rem'],

                ['key' => 'purchase_price', 'label' => 'inventory::field.purchase_price', 'type' => ReportColumn::MONEY],
                ['key' => 'sale_price', 'label' => 'inventory::field.sale_price', 'type' => ReportColumn::MONEY],

                ['key' => 'opening_qty', 'label' => 'inventory::field.qty_opening', 'type' => ReportColumn::QUANTITY],
                ['key' => 'opening_value', 'label' => 'inventory::field.amount_opening', 'type' => ReportColumn::MONEY],

                ['key' => 'in_qty', 'label' => 'inventory::field.qty_in', 'type' => ReportColumn::QUANTITY],
                ['key' => 'in_value', 'label' => 'inventory::field.amount_in', 'type' => ReportColumn::MONEY],

                ['key' => 'out_qty', 'label' => 'inventory::field.qty_out', 'type' => ReportColumn::QUANTITY],
                ['key' => 'out_value', 'label' => 'inventory::field.amount_out', 'type' => ReportColumn::MONEY],

                ['key' => 'closing_qty', 'label' => 'inventory::field.qty_closing', 'type' => ReportColumn::QUANTITY],
                ['key' => 'closing_value', 'label' => 'inventory::field.amount_closing', 'type' => ReportColumn::MONEY],
            ],
        );
    }

    /**
     * কোন ব্যাচের কত মাল, কোন গুদামে — কেনা আর ফ্রি আলাদা।
     *
     * ── ⭐ কেন এটা লাগল, ১৮ সেপ্টেম্বর ২০২৬ ─────────────────────────
     * মালিকের নির্দেশ: *"ইনভেন্টরিতে স্টক আলাদা ম্যানেজ হওয়ার কথা"* —
     * আর তালিকা করে দেখা গেল ব্যাচ-ভাগটাই একমাত্র সত্যিকারের ফাঁক।
     *
     * ⓘ ভিত্তিটা আগে থেকেই ছিল: `inv_batches` টেবিল, চলাচলে `batch_id`,
     * আর [[BatchAllocator]] বিক্রির সময় লট ধরে মাল কাটে। ⛔ কিন্তু
     * *"এই মুহূর্তে কোন ব্যাচে কত আছে"* জিজ্ঞেস করার কোনো জায়গা ছিল না
     * — কেবল মেয়াদের রিপোর্ট, আর সে শুধু মেয়াদ থাকা লট দেখাত।
     *
     * ── ⚠️ মেয়াদের রিপোর্ট থেকে এটা আলাদা, তিন জায়গায় ──────────────
     * ⓘ **এক** — মেয়াদ না থাকা ব্যাচও আসে (চাল, তেল, সাবানের অনেক লটে
     * মেয়াদ লেখা হয় না, তবু লট আলাদা)।
     * ⓘ **দুই** — গুদাম ধরে ভাগ: একই ব্যাচ দুই গুদামে থাকলে দুই সারি,
     * কারণ *"কত আছে"* প্রশ্নের উত্তর গুদামভেদে আলাদা।
     * ⓘ **তিন** — ফ্রি মাল নিজের কলামে।
     *
     * ── ⛔ শূন্য লট বাদ, আর কারণটা মেয়াদের রিপোর্টের মতোই ───────────
     * যে লট শেষ, তাকে নিয়ে কারও কিছু করার নেই। ⚠️ রাখলে ছয় মাসে
     * তালিকাটা এত লম্বা হত যে কেউ পড়ত না, আর যেটা আজ গুদামে আছে
     * সেটাই খুঁজে পাওয়া যেত না।
     */
    public static function stockByBatch(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'inventory.stock_by_batch',
            title: 'inventory::menu.stock_by_batch',
            filters: ['branch'],
            query: fn (array $f) => DB::table('inv_stock_movements as m')
                ->join('inv_products as p', 'p.id', '=', 'm.product_id')
                ->join('inv_warehouses as w', 'w.id', '=', 'm.warehouse_id')

                /*
                 * ⚠️ `leftJoin` — `batch_id` খালি থাকতে পারে, আর সেটা
                 * বৈধ: যে ব্যবসায় লট ধরা হয় না তার প্রতিটা চলাচলেই
                 * ঘরটা খালি। ⛔ ভেতরের join করলে ঐ মালটা **রিপোর্ট
                 * থেকেই উবে যেত**, আর মোট মিলত না।
                 */
                ->leftJoin('inv_batches as b', 'b.id', '=', 'm.batch_id')

                ->where('m.company_id', $f['company_id'])
                ->when($f['branch_id'], fn ($q, $b) => $q->where('m.branch_id', $b))
                ->groupBy('p.code', 'p.name_en', 'p.name_bn', 'w.name_en', 'b.batch_no', 'b.expiry_date')
                ->havingRaw('COALESCE(SUM(m.floor_change), 0) + COALESCE(SUM(m.free_change), 0) > 0')
                ->orderBy('p.code')
                ->orderBy('b.expiry_date')
                ->select([
                    'p.code as product_code',
                    self::productName(),
                    'w.name_en as warehouse_name',
                    'b.batch_no',
                    'b.expiry_date',
                    DB::raw('COALESCE(SUM(m.floor_change), 0) as on_hand'),
                    DB::raw('COALESCE(SUM(m.free_change), 0) as free_on_hand'),
                    DB::raw('COALESCE(SUM(m.unplaced_change), 0) as unplaced'),
                ]),
            columns: [
                ['key' => 'product_code', 'label' => 'inventory::field.code', 'width' => '7rem'],
                ['key' => 'product_name', 'label' => 'inventory::field.product'],
                ['key' => 'warehouse_name', 'label' => 'inventory::field.warehouse', 'width' => '10rem'],
                ['key' => 'batch_no', 'label' => 'inventory::field.batch_no', 'width' => '8rem'],
                ['key' => 'expiry_date', 'label' => 'inventory::field.expiry_date', 'type' => ReportColumn::DATE, 'width' => '7rem'],
                ['key' => 'on_hand', 'label' => 'inventory::field.floor', 'type' => ReportColumn::MONEY],
                ['key' => 'free_on_hand', 'label' => 'inventory::field.free', 'type' => ReportColumn::MONEY],
                ['key' => 'unplaced', 'label' => 'inventory::field.unplaced', 'type' => ReportColumn::MONEY],
            ],
        );
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
                ])
                /*
                 * "আজ" কোনটা, সেটা ডাটাবেজকে জিজ্ঞেস করা হয় না।
                 *
                 * আগে লেখা ছিল `DATEDIFF(b.expiry_date, CURDATE())`।
                 * MySQL-এর `CURDATE()` উত্তর দেয় **ডাটাবেজ সার্ভারের**
                 * ঘড়ি ধরে, অ্যাপের ঘড়ি ধরে নয় — আর দুইটা এক হওয়ার
                 * কোনো নিশ্চয়তা কোথাও লেখা নেই।
                 *
                 * ২৫/৮/২০২৬-এ লাইভে দুইটা সত্যিই আলাদা ছিল: অ্যাপ চলত
                 * UTC-তে (২৪ তারিখ), MySQL চলত মেশিনের ঘড়িতে (২৫)।
                 * ফলে এই একটা কলাম গোটা অ্যাপের চেয়ে এক দিন এগিয়ে
                 * থাকত — আজ মেয়াদ শেষ হওয়া লট দেখাত "১ দিন বাকি"।
                 *
                 * অ্যাপের ঘড়ি ঢাকায় সরানোয় আজ দুইটা মিলে গেছে, কিন্তু
                 * মিলেছে **কাকতালীয়ভাবে** — MySQL এই মেশিনেই চলে বলে।
                 * ডাটাবেজ একদিন ম্যানেজড হোস্টে গেলে (যেখানে ডিফল্ট
                 * UTC) ফাঁকটা নীরবে ফিরে আসত, আর মেয়াদের হিসাবে এক
                 * দিনের ভুল মানে ফেরত পাঠানোর সুযোগ হাতছাড়া।
                 *
                 * তাই তারিখটা অ্যাপ থেকেই বাঁধা হয়। একটা ঘড়ি, একটা উত্তর।
                 */
                ->selectRaw('DATEDIFF(b.expiry_date, ?) as days_left', [
                    Carbon::today()->toDateString(),
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
                /*
                 * ⚠️ বসার অপেক্ষায় থাকা মালও সারিটা আনে।
                 *
                 * আগে শর্তটা ছিল কেবল floor বা hold। ফলে যে পণ্যের সব
                 * মালই সদ্য এসেছে আর কেউ বুঝে নেয়নি, তার সারিটাই
                 * রিপোর্টে আসত না — **গুদামে মাল আছে, রিপোর্টে পণ্যটাই
                 * নেই।** ⓘ ঠিক এভাবেই মাল "উধাও" দেখায়।
                 */
                ->havingRaw('SUM(m.floor_change) <> 0 OR SUM(m.hold_change) <> 0 OR SUM(m.unplaced_change) <> 0')
                ->orderBy('p.code')
                ->select([
                    'm.product_id',
                    self::productName(),
                    DB::raw("'".Product::drillSourceType()."' as party_type_literal"),
                    DB::raw('SUM(m.floor_change) as floor'),
                    DB::raw('SUM(m.reserved_change) as reserved'),
                    DB::raw('SUM(m.hold_change) as hold'),

                    /*
                     * ⛔ `available`-এর সূত্রে `unplaced` নেই, আর থাকবেও না —
                     * বসানো হয়নি এমন মাল বিক্রয়যোগ্য নয়। কলামটা আলাদা
                     * থাকে যাতে পাঠক দুইটা প্রশ্নের দুইটা উত্তর পান:
                     * *গুদামে কত আছে* আর *কতটা বেচা যাবে*।
                     */
                    DB::raw('SUM(m.unplaced_change) as unplaced'),
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
                ['key' => 'unplaced', 'label' => 'inventory::field.unplaced', 'type' => ReportColumn::MONEY],
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

    /*
     * ⓘ `foodCost()` এখানে ছিল — ১৫ সেপ্টেম্বর ২০২৬-এ রেস্তোরাঁয় গেছে
     * ([[App\Modules\Restaurant\Reports\RestaurantReports::foodCost()]]).
     *
     * মালিক ছবিতে দাগিয়ে বলেছেন খাদ্য-খরচ রেস্তোরাঁর পর্দা। কোয়েরিটা
     * মজুদের টেবিল পড়ে ঠিকই, কিন্তু **প্রশ্নটা** রান্না করা খাবারের —
     * আর রিপোর্ট বসে প্রশ্ন ধরে, টেবিল ধরে নয়।
     */
}
