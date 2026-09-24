<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\StockService;
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
        $engine->register(self::reservedReport());
        $engine->register(self::replenishment());
        $engine->register(self::stockByBatch());
        $engine->register(self::stockValue());
        $engine->register(self::stockByWarehouse());
        $engine->register(self::adjustments());
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

        /*
         * ফ্রি মাল — চলাচলের টেবিল থেকে, খরচের স্তর থেকে নয়।
         *
         * ── ⛔ কেন আলাদা উৎস, ২২ সেপ্টেম্বর ২০২৬ ─────────────────────
         * `inv_cost_layers`-এ ফ্রির কোনো কলামই নেই — স্কিমা দেখে যাচাই
         * করা: `qty_in`, `qty_remaining`, `unit_cost`, ব্যস। ⓘ কারণটা
         * যুক্তিসঙ্গত: **ফ্রি মালের দাম নেই**, তাই তার কোনো খরচ-স্তরও
         * নেই। ⚠️ উপরের চারটা `joinSub` দিয়ে খুঁজলে চিরকাল শূন্য পাওয়া
         * যেত, আর সেটা দেখতে "ফ্রি মাল নেই"-এর মতোই লাগত।
         *
         * ⭐ `free_change` চিহ্নসহ, তাই আগমন ও নির্গমন আলাদা করতে
         * `GREATEST`: ঢুকলে ধনাত্মক, বেরোলে ঋণাত্মক। ⓘ আর `fnet`
         * আলাদা করে রাখা হয় না — সমাপনী গোনা হয় (আগমন − নির্গমন),
         * ঠিক যেভাবে টাকার দিকটা গোনা হয়, যাতে সারিতে সমীকরণটা মেলে।
         */
        $free = fn (array $f, ?string $from) => DB::table('inv_stock_movements')
            ->where('company_id', $f['company_id'])
            ->when($from, fn ($q) => $q->where('trx_date', '>=', $from))
            ->where('trx_date', '<=', $f['to'])
            ->groupBy('product_id')
            ->select([
                'product_id',
                DB::raw('SUM(GREATEST(free_change, 0)) as fin'),
                DB::raw('SUM(GREATEST(-free_change, 0)) as fout'),
            ]);

        return new ReportDefinition(
            key: 'inventory.stock_value',
            title: 'inventory::menu.stock_value',
            filters: ['date_range'],
            groupBy: 'product_id',
            query: function (array $f) use ($in, $out, $free) {
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
                    ->leftJoinSub($free($before, null), 'of', 'of.product_id', '=', 'p.id')
                    ->leftJoinSub($free($f, $f['from']), 'pf', 'pf.product_id', '=', 'p.id')
                    ->where('p.company_id', $f['company_id'])
                    /*
                     * ⛔ যে পণ্যের এই পরিসরে কিছুই ঘটেনি আর প্রারম্ভিকও
                     * শূন্য, তার সারি আসে না। ⚠️ নাহলে গোটা পণ্য-তালিকা
                     * শূন্যের সারি হয়ে ছাপা হত, আর আসল সারিগুলো তার
                     * ভিতরে হারাত।
                     */
                    /*
                     * ⚠️ ফ্রিও শর্তে আছে — ২২ সেপ্টেম্বর ২০২৬।
                     *
                     * ⛔ আগে শর্তটা কেবল খরচের স্তর দেখত। ⓘ ফলে যে পণ্য
                     * **শুধু ফ্রি হিসেবে** এসেছে, তার কোনো স্তর নেই বলে
                     * সারিটাই আসত না — গুদামে মাল আছে, রিপোর্টে পণ্যটাই
                     * নেই। ⚠️ ঠিক সেই ধরনটা যেটা [[stockSummary]]-তেও
                     * একবার ধরা পড়েছে।
                     */
                    ->whereRaw('COALESCE(oi.q,0) <> 0 OR COALESCE(oo.q,0) <> 0
                                OR COALESCE(pi.q,0) <> 0 OR COALESCE(po.q,0) <> 0
                                OR COALESCE(of.fin,0) <> 0 OR COALESCE(of.fout,0) <> 0
                                OR COALESCE(pf.fin,0) <> 0 OR COALESCE(pf.fout,0) <> 0')
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
                        DB::raw('COALESCE(of.fin,0) - COALESCE(of.fout,0) as opening_free'),
                        DB::raw('COALESCE(oi.v,0) - COALESCE(oo.v,0) as opening_value'),

                        DB::raw('COALESCE(pi.q,0) as in_qty'),
                        DB::raw('COALESCE(pf.fin,0) as in_free'),
                        DB::raw('COALESCE(pi.v,0) as in_value'),

                        DB::raw('COALESCE(po.q,0) as out_qty'),
                        DB::raw('COALESCE(pf.fout,0) as out_free'),
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
                        DB::raw('COALESCE(of.fin,0) - COALESCE(of.fout,0)
                                 + COALESCE(pf.fin,0) - COALESCE(pf.fout,0) as closing_free'),
                        DB::raw('COALESCE(oi.v,0) - COALESCE(oo.v,0)
                                 + COALESCE(pi.v,0) - COALESCE(po.v,0) as closing_value'),

                        /*
                         * ⭐ মালিকের *"last e Total qty Diba free soho"* —
                         * হাতে যত মাল, কেনা আর ফ্রি একসাথে।
                         *
                         * ⛔ এই সংখ্যাটা **টাকার কলামের সাথে মিলবে না**,
                         * আর মেলার কথাও নয়: ফ্রি মালের দাম শূন্য, তাই
                         * সমাপনী মূল্য কেবল কেনা মালেরই। ⓘ লেবেলে তাই
                         * "ফ্রি সহ" কথাটা থাকে — নাহলে পাঠক ভাবতেন
                         * হিসাব মেলেনি, আর ঐ সন্দেহ গোটা রিপোর্টে বসত।
                         */
                        DB::raw('COALESCE(oi.q,0) - COALESCE(oo.q,0)
                                 + COALESCE(pi.q,0) - COALESCE(po.q,0)
                                 + COALESCE(of.fin,0) - COALESCE(of.fout,0)
                                 + COALESCE(pf.fin,0) - COALESCE(pf.fout,0) as closing_total'),
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

                /*
                 * ⓘ প্রতিটা পরিমাণের **ঠিক পাশে** তার ফ্রি — মালিকের
                 * *"egulor pase free qty diye dio"*। ⚠️ সবগুলো ফ্রি
                 * একসাথে শেষে বসালে পাঠককে চোখ দুই দিকে নিতে হত,
                 * আর তখন কোন ফ্রি কোন ঘরের তা গুলিয়ে যেত।
                 */
                ['key' => 'opening_qty', 'label' => 'inventory::field.qty_opening', 'type' => ReportColumn::QUANTITY],
                ['key' => 'opening_free', 'label' => 'inventory::field.free_opening', 'type' => ReportColumn::QUANTITY],
                ['key' => 'opening_value', 'label' => 'inventory::field.amount_opening', 'type' => ReportColumn::MONEY],

                ['key' => 'in_qty', 'label' => 'inventory::field.qty_in', 'type' => ReportColumn::QUANTITY],
                ['key' => 'in_free', 'label' => 'inventory::field.free_in', 'type' => ReportColumn::QUANTITY],
                ['key' => 'in_value', 'label' => 'inventory::field.amount_in', 'type' => ReportColumn::MONEY],

                ['key' => 'out_qty', 'label' => 'inventory::field.qty_out', 'type' => ReportColumn::QUANTITY],
                ['key' => 'out_free', 'label' => 'inventory::field.free_out', 'type' => ReportColumn::QUANTITY],
                ['key' => 'out_value', 'label' => 'inventory::field.amount_out', 'type' => ReportColumn::MONEY],

                ['key' => 'closing_qty', 'label' => 'inventory::field.qty_closing', 'type' => ReportColumn::QUANTITY],
                ['key' => 'closing_free', 'label' => 'inventory::field.free_closing', 'type' => ReportColumn::QUANTITY],
                ['key' => 'closing_value', 'label' => 'inventory::field.amount_closing', 'type' => ReportColumn::MONEY],

                ['key' => 'closing_total', 'label' => 'inventory::field.qty_total_with_free', 'type' => ReportColumn::QUANTITY],
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
                /*
                 * ⛔ `w.name_bn` এখানে ছিল না — ২২ সেপ্টেম্বর ২০২৬।
                 *
                 * ⚠️ [[warehouseName()]] বাংলায় `COALESCE(NULLIF(w.name_bn,
                 * ''), w.name_en)` বাছে, আর `ONLY_FULL_GROUP_BY` তখন গোটা
                 * প্রশ্নটাই বাতিল করে — পাতাটা **৫০০** দিত।
                 *
                 * ⓘ পাশের `p.name_bn` ঠিকই বসানো ছিল, তাই এটা নীতির ভুল
                 * নয়, লেখার সময়ের একটা বাদ পড়া।
                 *
                 * ⛔ আর ধরা পড়েনি কারণ ভুলটা **কেবল বাংলায়** ঘটে:
                 * ইংরেজিতে `w.name_bn` ছোঁয়াই হয় না, আর মালিকের লাইভ
                 * অ্যাকাউন্ট ইংরেজিতে পড়ে। ⚠️ সাথে ব্যাচের সুইচও বন্ধ,
                 * তাই সারিটা মেনুতেও আসত না — **দুইটা আলাদা পর্দা**
                 * একসাথে একটা ভাঙা পাতাকে অদৃশ্য রেখেছিল।
                 */
                ->groupBy('p.code', 'p.name_en', 'p.name_bn', 'w.name_en', 'w.name_bn', 'b.batch_no', 'b.expiry_date')
                ->havingRaw('COALESCE(SUM(m.floor_change), 0) + COALESCE(SUM(m.free_change), 0) > 0')
                ->orderBy('p.code')
                ->orderBy('b.expiry_date')
                ->select([
                    'p.code as product_code',
                    self::productName(),
                    self::warehouseName(),
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
                    self::warehouseName(),
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
     * একই পণ্য কোন গুদামে কত।
     *
     * ── ⭐ কেন আলাদা রিপোর্ট, [[stockSummary]]-তে কলাম যোগ নয় ──────────
     * মজুদ-সারাংশ প্রশ্নের উত্তর দেয় *"কী কত আছে"* — এক পণ্য, এক সারি।
     * ⓘ কিন্তু তিনটা গুদাম থাকলে ঐ এক সারিটা **যোগফল**, আর যোগফল
     * দিয়ে চালান পাঠানো যায় না: ৫০ কার্টন আছে শুনে কেউ অর্ডার নেন,
     * পরে দেখা যায় ৪৫টা অন্য গুদামে।
     *
     * ⚠️ ঐ এক কলাম যোগ করলে সারাংশের প্রতিটা সারি গুদামের সংখ্যা গুণ
     * হয়ে যেত, আর রোজকার প্রশ্নটাই কঠিন হত।
     *
     * ── ⛔ যে জোড়া শূন্যে নেমেছে, তার সারি আসে না ─────────────────────
     * পণ্য × গুদাম মানে সারির সংখ্যা গুণফল। ⓘ মাল একবার ঢুকে পুরোটা
     * বেরিয়ে গেলে জোড়াটার সব যোগফল শূন্য — সেই সারিগুলো রাখলে তালিকাটা
     * পড়ার অযোগ্য হত, আর আসল সারিগুলো তার ভিতরে হারাত।
     *
     * ⚠️ শর্তটা পাঁচটা ভাণ্ডারই দেখে, কেবল `floor` নয়। ⓘ সদ্য আসা কিন্তু
     * কেউ বুঝে নেয়নি এমন মালের `floor` শূন্য অথচ `unplaced` আছে —
     * [[stockSummary]]-তে ঠিক এই ভুলেই মাল "উধাও" দেখাত।
     */
    public static function stockByWarehouse(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'inventory.stock_by_warehouse',
            title: 'inventory::menu.stock_by_warehouse',
            filters: ['date_range', 'branch'],

            /*
             * ⛔ `groupBy` পর্দার দল নয় — ইঞ্জিনের **গোনার** চাবি।
             *
             * ⚠️ কোয়েরিতে SQL `GROUP BY` আছে, আর তখন `null` দিলে ইঞ্জিন
             * `$query->count()` চালাত — যা দলভিত্তিক কোয়েরিতে **প্রতি
             * দলের একটা করে সারি** ফেরত দেয়, মোট দলের সংখ্যা নয়। ⓘ ফল:
             * পাতা-সংখ্যা ভুল, আর শেষ পাতাগুলো অদৃশ্য।
             *
             * ⓘ চাবিটা পণ্য+গুদাম জোড়া, কারণ সারিটা ঐ জোড়ারই।
             */
            groupBy: 'pair_key',
            query: fn (array $f) => DB::table('inv_stock_movements as m')
                ->join('inv_products as p', 'p.id', '=', 'm.product_id')
                ->join('inv_warehouses as w', 'w.id', '=', 'm.warehouse_id')
                ->where('m.company_id', $f['company_id'])
                ->when($f['branch_id'], fn ($q, $b) => $q->where('m.branch_id', $b))

                /*
                 * ⓘ শুরুর তারিখ ধরা হয় না — মজুদ একটা মুহূর্তের অবস্থা,
                 * পরিসরের নয়। [[stockSummary]]-তেও হুবহু একই যুক্তি।
                 */
                ->where('m.trx_date', '<=', $f['to'])

                /*
                 * ⚠️ লাইভে `ONLY_FULL_GROUP_BY` চালু, তাই নির্বাচিত
                 * প্রতিটা অ-সমষ্টি কলাম এখানে থাকতেই হবে। ⛔ একটা বাদ
                 * পড়লে স্থানীয়ভাবে চলত আর লাইভে ৫০০ হত।
                 */
                ->groupBy(
                    'm.product_id', 'p.code', 'p.name_en', 'p.name_bn',
                    'm.warehouse_id', 'w.code', 'w.name_en', 'w.name_bn',
                )
                ->havingRaw('SUM(m.floor_change) <> 0 OR SUM(m.reserved_change) <> 0
                             OR SUM(m.hold_change) <> 0 OR SUM(m.unplaced_change) <> 0
                             OR SUM(m.free_change) <> 0')
                ->orderBy('p.code')
                ->orderBy('w.code')
                ->select([
                    'm.product_id',
                    self::productName(),
                    self::warehouseName(),
                    DB::raw("CONCAT(m.product_id, '-', m.warehouse_id) as pair_key"),
                    DB::raw("'".Product::drillSourceType()."' as party_type_literal"),
                    DB::raw('SUM(m.floor_change) as floor'),
                    DB::raw('SUM(m.reserved_change) as reserved'),
                    DB::raw('SUM(m.hold_change) as hold'),
                    DB::raw('SUM(m.unplaced_change) as unplaced'),

                    /*
                     * ⓘ ফ্রি মাল খরচের স্তরে নেই (ফ্রির দাম নেই), তাই
                     * পরিমাণটা কেবল এখান থেকেই বের হয়।
                     */
                    DB::raw('SUM(m.free_change) as free'),

                    /*
                     * ⛔ `available`-এ `unplaced` নেই, আর থাকবেও না —
                     * বসানো হয়নি এমন মাল বিক্রয়যোগ্য নয়।
                     */
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
                ['key' => 'warehouse_name', 'label' => 'inventory::field.warehouse'],

                /*
                 * ⚠️ `QUANTITY`, `MONEY` নয় — যদিও পাশের পুরনো মজুদ-
                 * রিপোর্টগুলো পরিমাণেও `MONEY` লেখে।
                 *
                 * ⓘ পার্থক্যটা আজ কেবল দশমিক ঘর (৩ বনাম ২), কিন্তু নামটা
                 * মিথ্যা হলে একদিন কেউ টাকার চিহ্ন বা মুদ্রা বসাবে, আর
                 * কার্টনের সংখ্যায় "৳" বসে যাবে।
                 */
                ['key' => 'floor', 'label' => 'inventory::field.floor', 'type' => ReportColumn::QUANTITY],
                ['key' => 'reserved', 'label' => 'inventory::field.reserved', 'type' => ReportColumn::QUANTITY],
                ['key' => 'hold', 'label' => 'inventory::field.hold', 'type' => ReportColumn::QUANTITY],
                ['key' => 'unplaced', 'label' => 'inventory::field.unplaced', 'type' => ReportColumn::QUANTITY],
                ['key' => 'free', 'label' => 'inventory::field.free', 'type' => ReportColumn::QUANTITY],
                ['key' => 'available', 'label' => 'inventory::field.available', 'type' => ReportColumn::QUANTITY],
            ],
        );
    }

    /**
     * কে কবে কেন মজুদ বদলেছে।
     *
     * ── ⭐ "সমন্বয়" মানে যে চলাচলে একটা কারণ লেখা আছে ─────────────────
     * বিক্রয় বা ক্রয়ে মাল নড়ে কাগজের নিয়মে — সেখানে "কেন" প্রশ্নটার
     * উত্তর কাগজটাই। ⓘ কিন্তু গণনার পরে সমন্বয় বা মাল আটকানো — ওগুলো
     * **মানুষের সিদ্ধান্ত**, আর [[StockService::move()]] সেখানে একটা
     * `reason_code_id` লিখতে বাধ্য করে।
     *
     * ⚠️ তাই ছাঁকনিটা কোনো উৎসের নামের তালিকা নয়, `reason_code_id`-র
     * উপস্থিতি। ⓘ নতুন কোনো কারণসহ চলাচল যোগ হলে সে নিজে থেকেই এই
     * রিপোর্টে আসবে — কেউ তালিকা হালনাগাদ করতে ভুলে গেলেও।
     *
     * ── ⛔ কারণের টেবিলে LEFT JOIN, INNER নয় ─────────────────────────
     * কারণ-কোড মাস্টার থেকে মুছে ফেলা যায়। ⚠️ INNER JOIN হলে ঐ কোড
     * ব্যবহার করা **সব পুরনো সারি নীরবে উধাও** হত — আর তখন ইতিহাসটাই
     * মিথ্যা: মাল বদলেছে, অথচ রিপোর্ট বলছে কেউ কিছু বদলায়নি।
     *
     * ⓘ হিসাবের রিপোর্টে খাতের নামে INNER JOIN দিয়ে সারি হারানোটা এই
     * রিপোতে একবার ধরাও পড়েছে — [[reasonName]]-এর মন্তব্য দেখুন।
     * ⚠️ একই কারণে `users`-এও LEFT JOIN: মানুষ কোম্পানি ছাড়ে, সারি থাকে।
     */
    public static function adjustments(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'inventory.adjustments',
            title: 'inventory::menu.adjustments',
            filters: ['date_range', 'branch'],
            query: fn (array $f) => DB::table('inv_stock_movements as m')
                ->join('inv_products as p', 'p.id', '=', 'm.product_id')
                ->join('inv_warehouses as w', 'w.id', '=', 'm.warehouse_id')
                ->leftJoin('mdm_reason_codes as r', 'r.id', '=', 'm.reason_code_id')
                ->leftJoin('users as u', 'u.id', '=', 'm.created_by')
                ->where('m.company_id', $f['company_id'])
                ->when($f['branch_id'], fn ($q, $b) => $q->where('m.branch_id', $b))

                /* ⓘ এটা ঘটনার তালিকা, অবস্থার নয় — তাই পুরো পরিসর। */
                ->whereBetween('m.trx_date', [$f['from'], $f['to']])
                /*
                 * ⛔ ছাঁকনিটা কেবল `reason_code_id` ধরে ছিল, আর সেটা ভাঙা ছিল।
                 *
                 * ⚠️ বিদেশি চাবিটা `nullOnDelete` — কারণ-কোড সত্যি মুছলে
                 * ডাটাবেস চলাচলের সারিতে `reason_code_id` **শূন্য করে দেয়**।
                 * ⓘ তখন সারিটা LEFT JOIN-এর আগেই, **ছাঁকনিতেই** বাদ পড়ত —
                 * অর্থাৎ LEFT JOIN দিয়ে যে বিপদটা ঠেকানোর কথা, সেটাই
                 * অন্য দিক দিয়ে ফিরে আসত।
                 *
                 * ⭐ ধরা পড়েছে মিউটেশনে, ২১ সেপ্টেম্বর ২০২৬।
                 *
                 * ⓘ এখন দুই দিক থেকে: সমন্বয় ও আটকানো সবসময় আসে
                 * (`source_type` খালি হয় না, বদলায়ও না), আর ভবিষ্যতে
                 * কারণসহ নতুন কোনো চলাচল যোগ হলে সেও নিজে থেকেই।
                 */
                ->where(fn ($q) => $q
                    ->whereIn('m.source_type', [StockService::ADJUSTMENT, StockService::HOLD])
                    ->orWhereNotNull('m.reason_code_id'))

                /* ⭐ নতুনটা আগে — ইতিহাস পড়া হয় শেষ থেকে। */
                ->orderByDesc('m.trx_date')
                ->orderByDesc('m.id')
                ->select([
                    'm.trx_date',
                    'm.document_no',
                    'm.source_type',
                    'm.source_id',
                    self::productName(),
                    self::warehouseName(),
                    self::reasonName(),

                    /* ⚠️ মানুষটা মুছে গেলেও সারিটা থাকে — তাই fallback। */
                    DB::raw("COALESCE(u.name, '?') as changed_by"),

                    'm.floor_change',
                    'm.hold_change',
                    'm.narration',
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
                ['key' => 'reason_name', 'label' => 'inventory::field.reason'],
                ['key' => 'changed_by', 'label' => 'inventory::field.changed_by'],
                ['key' => 'floor_change', 'label' => 'inventory::field.floor', 'type' => ReportColumn::QUANTITY],
                ['key' => 'hold_change', 'label' => 'inventory::field.hold', 'type' => ReportColumn::QUANTITY],
                ['key' => 'narration', 'label' => 'inventory::field.narration'],
            ],
        );
    }

    /**
     * পণ্যের নাম — কোড সহ, ব্যবহারকারীর ভাষায়।
     */
    /**
     * ⭐ সংরক্ষিত মাল — কোন কাগজের বিপরীতে, ২৪ সেপ্টেম্বর ২০২৬।
     *
     * ── ⛔ সংখ্যাটা ছিল, কারণটা ছিল না ────────────────────────────────
     * `available = floor − reserved − hold` — হিসাবটা [[StockService]]-এ
     * আগে থেকেই আছে, আর গুদামভিত্তিক রিপোর্টে `reserved` কলামটাও আছে।
     *
     * ⚠️ কিন্তু ওখানে কেবল **সংখ্যা**: *"১২ কার্টন সংরক্ষিত"*। ⛔ কার
     * জন্য, কোন কাগজের বিপরীতে, কবে থেকে — কিছুই নয়। ⓘ ফল: গুদামের
     * লোক দেখতেন মাল আছে অথচ বিক্রি করা যায় না, আর কেন তা জানার কোনো
     * পথ ছিল না। ⚠️ তখন মানুষ সংরক্ষণটা জোর করে ছাড়িয়ে নিত, আর যে
     * ক্রেতার জন্য রাখা ছিল তিনি খালি হাতে ফিরতেন।
     *
     * ⭐ তাই সারিগুলো **উৎস কাগজ ধরে** ভাগ করা — চলাচলের সারিতে
     * `source_type` ও `source_id` আগে থেকেই লেখা থাকে।
     */
    public static function reservedReport(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'inventory.reserved',
            title: 'inventory::menu.reserved_report',
            filters: ['date_range', 'branch'],
            groupBy: 'product_id',
            query: fn (array $f) => DB::table('inv_stock_movements as m')
                ->join('inv_products as p', 'p.id', '=', 'm.product_id')
                ->leftJoin('inv_warehouses as w', 'w.id', '=', 'm.warehouse_id')
                ->where('m.company_id', $f['company_id'])
                ->when($f['branch_id'], fn ($q, $b) => $q->where('m.branch_id', $b))
                ->where('m.trx_date', '<=', $f['to'])
                ->where('m.reserved_change', '<>', 0)
                ->groupBy(
                    'm.product_id', 'p.code', 'p.name_en', 'p.name_bn',
                    'm.warehouse_id', 'w.code', 'w.name_en', 'w.name_bn',
                    'm.source_type', 'm.source_id',
                )

                /*
                 * ⛔ যে সংরক্ষণ ইতিমধ্যে ছেড়ে দেওয়া হয়েছে তার সারি আসে
                 * না। ⓘ ছাড়ার সময় একটা ঋণাত্মক সারি বসে, তাই যোগফল
                 * শূন্য — ⚠️ শর্তটা না থাকলে তালিকাটা গত বছরের প্রতিটা
                 * ছেড়ে দেওয়া সংরক্ষণ নিয়ে ভরে যেত।
                 */
                ->havingRaw('SUM(m.reserved_change) <> 0')
                ->orderBy('p.code')
                ->select([
                    'm.product_id',
                    self::productName(),
                    DB::raw("'".Product::drillSourceType()."' as party_type_literal"),
                    self::warehouseName(),
                    'm.source_type',
                    'm.source_id',
                    DB::raw('SUM(m.reserved_change) as reserved'),
                ]),
            columns: [
                [
                    'key' => 'product_name',
                    'label' => 'inventory::field.product',
                    'type' => ReportColumn::DOCUMENT,
                    'source_type' => 'party_type_literal',
                    'source_id' => 'product_id',
                ],
                ['key' => 'warehouse_name', 'label' => 'inventory::field.warehouse'],

                /*
                 * ⓘ কাগজটা ক্লিক করা যায় — ⚠️ *"কেন আটকে আছে"* প্রশ্নের
                 * উত্তর সংখ্যায় নেই, কাগজটার ভিতরে।
                 */
                [
                    'key' => 'source_id',
                    'label' => 'inventory::field.against',
                    'type' => ReportColumn::DOCUMENT,
                    'source_type' => 'source_type',
                    'source_id' => 'source_id',
                ],
                ['key' => 'reserved', 'label' => 'inventory::field.reserved', 'type' => ReportColumn::QUANTITY],
            ],
        );
    }

    /**
     * ⭐ কী কিনতে হবে, আর কতটা — ২৪ সেপ্টেম্বর ২০২৬।
     *
     * ── ⛔ পুনঃক্রয়ের স্তর বলত কখন, কোনোদিন বলত না কতটা ────────────────
     * ড্যাশবোর্ডে *"এর নিচে নেমেছে"* তালিকা আগে থেকেই আছে, আর সেটা
     * প্রশ্নের প্রথম অর্ধেকের উত্তর দেয়। ⚠️ দ্বিতীয় অর্ধেকটা — **কতটা
     * কিনব** — কোথাও ছিল না, তাই মানুষ আন্দাজে অর্ডার দিতেন।
     *
     * ⓘ আন্দাজ দুই দিকেই ভুল হয়: কম কিনলে দুই সপ্তাহ পরে আবার একই
     * তালিকায়, বেশি কিনলে টাকা গুদামে পড়ে থাকে।
     *
     * ── ⚠️ প্রস্তাবিত পরিমাণটা কীভাবে বের হয় ─────────────────────────
     * ⭐ সর্বোচ্চ মজুদ বলা থাকলে **সর্বোচ্চ − হাতে যা আছে** — কারণ
     * লক্ষ্যটা তো ওটাই। ⓘ না বলা থাকলে একবারের অর্ডারের পরিমাণ
     * (`reorder_qty`), আর সেটাও না থাকলে পুনঃক্রয়ের স্তর পর্যন্ত ভরা।
     *
     * ⛔ কোনোটাই না থাকলে সারিটা আসে, কিন্তু প্রস্তাব খালি — ⚠️ একটা
     * বানানো সংখ্যা বসানোর চেয়ে *"বলা নেই"* বলা ভালো, কারণ বানানো
     * সংখ্যা দিয়েই অর্ডার চলে যেত।
     */
    public static function replenishment(): ReportDefinition
    {
        $available = '(select COALESCE(SUM(m.floor_change - m.reserved_change - m.hold_change), 0)
                       from inv_stock_movements m
                       where m.product_id = p.id and m.company_id = p.company_id)';

        /*
         * ⚠️ `GREATEST(..., 0)` — ⛔ ছাড়া হাতে থাকা মাল সর্বোচ্চের
         * উপরে গেলে প্রস্তাবটা **ঋণাত্মক** হত, আর কেউ ওটা পড়ে ভাবতেন
         * বিক্রি করতে বলা হচ্ছে।
         */
        $suggested = 'CASE
                WHEN p.max_level IS NOT NULL THEN GREATEST(p.max_level - '.$available.", 0)
                WHEN p.reorder_qty IS NOT NULL THEN p.reorder_qty
                WHEN p.reorder_level IS NOT NULL THEN GREATEST(p.reorder_level - ".$available.', 0)
                ELSE NULL
            END';

        return new ReportDefinition(
            key: 'inventory.replenishment',
            title: 'inventory::menu.replenishment',
            filters: ['branch'],

            /*
             * ⛔ `groupBy` নেই, ইচ্ছাকৃতভাবে।
             *
             * ⚠️ কোয়েরিটা কিছুই যোগ করে না — প্রতি পণ্যে এক সারি।
             * ⓘ `groupBy` বসালে গুনতিটা একটা বাড়তি সাব-কোয়েরিতে মোড়া
             * হত, আর রিপোর্টটা এমন একটা দাবি করত যা সত্যি নয়।
             *
             * ⓘ `rankBy`-ও নেই: ওটা *"উপরের কয়টা, আর তারা মোটের কত
             * অংশ"* প্রশ্নের জন্য, আর ঘাটতির ক্ষেত্রে ঐ প্রশ্নটার কোনো
             * মানে হয় না — ⚠️ পাঁচটা পণ্যের ঘাটতি মোট ঘাটতির ষাট
             * শতাংশ, এই বাক্যটা কাউকে কিছুই বলে না।
             */
            query: fn (array $f) => DB::table('inv_products as p')
                ->where('p.company_id', $f['company_id'])
                ->whereNull('p.deleted_at')
                ->where('p.is_active', true)

                /*
                 * ⛔ যে পণ্যে পুনঃক্রয়ের স্তর বলা নেই, সে এই তালিকায়
                 * আসে না। ⚠️ আনলে গোটা পণ্য-তালিকাটাই চলে আসত, আর যে
                 * দশটা সত্যিই ফুরিয়ে আসছে সেগুলো হাজারটার ভিড়ে হারাত।
                 *
                 * ── ⛔ শর্তটা `> 0`, `whereNotNull` নয় ───────────────
                 * ⚠️ প্রথম চেষ্টায় `whereNotNull('p.reorder_level')`
                 * লেখা হয়েছিল, আর সেটা **কিছুই ছাঁকত না**: ঘরটা
                 * `NOT NULL DEFAULT 0`, অর্থাৎ কখনো খালি হয় না।
                 *
                 * ⓘ ফল হত উল্টোটা: স্তরবিহীন প্রতিটা পণ্যের শর্তটা
                 * দাঁড়াত `available <= 0`, তাই **শূন্য মজুদের প্রতিটা
                 * পণ্য** তালিকায় ঢুকে পড়ত — হাজারটা সারি, আর যে দশটা
                 * সত্যিই কিনতে হবে সেগুলো ভিড়ে হারাত।
                 *
                 * ⭐ এখানে শূন্যই *"বলা নেই"*, আর সেটাই ছাঁকনি।
                 */
                ->where('p.reorder_level', '>', 0)
                ->whereRaw($available.' <= p.reorder_level')
                ->orderByRaw('(p.reorder_level - '.$available.') desc')
                ->select([
                    'p.id as product_id',
                    DB::raw("'".Product::drillSourceType()."' as party_type_literal"),
                    self::productNameFrom('p'),
                    DB::raw($available.' as available'),
                    'p.reorder_level',
                    'p.max_level',
                    'p.lead_days',
                    DB::raw('(p.reorder_level - '.$available.') as shortfall'),
                    DB::raw($suggested.' as suggested'),
                ]),
            columns: [
                [
                    'key' => 'product_name',
                    'label' => 'inventory::field.product',
                    'type' => ReportColumn::DOCUMENT,
                    'source_type' => 'party_type_literal',
                    'source_id' => 'product_id',
                ],
                ['key' => 'available', 'label' => 'inventory::field.available', 'type' => ReportColumn::QUANTITY],
                ['key' => 'reorder_level', 'label' => 'inventory::overview.reorder_level', 'type' => ReportColumn::QUANTITY],
                ['key' => 'shortfall', 'label' => 'inventory::field.shortfall', 'type' => ReportColumn::QUANTITY],
                ['key' => 'max_level', 'label' => 'inventory::field.max_level', 'type' => ReportColumn::QUANTITY],

                /*
                 * ⓘ দিনের সংখ্যাটা পাশে থাকে, কারণ *"কতটা"* প্রশ্নের
                 * উত্তরটা একা কিছু বলে না — ⚠️ সাত দিনের সরবরাহ আর
                 * ষাট দিনের সরবরাহে একই ঘাটতির জরুরিত্ব এক নয়।
                 */
                ['key' => 'lead_days', 'label' => 'inventory::field.lead_days'],

                ['key' => 'suggested', 'label' => 'inventory::field.suggested_order', 'type' => ReportColumn::QUANTITY],
            ],
        );
    }

    /**
     * পণ্যের নাম, যে উপনামেই টেবিলটা জোড়া হোক।
     *
     * ⓘ [[productName()]] `p` উপনাম ধরে নেয় আর সেটাই বেশিরভাগ জায়গায়
     * ঠিক; ⚠️ কিন্তু নতুন কোয়েরিতে উপনাম বদলালে ওটা নীরবে ভুল কলাম
     * পড়ত, তাই এখানে উপনামটা হাতে দেওয়া।
     */
    private static function productNameFrom(string $alias): Expression
    {
        $name = app()->getLocale() === 'bn'
            ? "COALESCE(NULLIF({$alias}.name_bn, ''), {$alias}.name_en)"
            : "{$alias}.name_en";

        return DB::raw("CONCAT({$alias}.code, ' - ', {$name}) as product_name");
    }

    private static function productName(): Expression
    {
        $name = app()->getLocale() === 'bn'
            ? "COALESCE(NULLIF(p.name_bn, ''), p.name_en)"
            : 'p.name_en';

        return DB::raw("CONCAT(p.code, ' - ', {$name}) as product_name");
    }

    /**
     * গুদামের নাম — ব্যবহারকারীর ভাষায়।
     *
     * ── ⛔ আগে এটা ছিল না, আর [[stockLedger]] `w.name_en` লিখত ─────────
     * ফলে বাংলায় পড়া ব্যবহারকারী মজুদ খতিয়ানে গুদামের নাম **ইংরেজিতে**
     * দেখতেন, অথচ ঠিক পাশের কলামে পণ্যের নাম বাংলায়। ⚠️ কিছুই ভাঙত না,
     * তাই কেউ অভিযোগও করেনি — এক পাতায় দুই ভাষা।
     *
     * ⓘ [[productName]]-এর হুবহু একই নিয়ম: বাংলা নাম খালি হলে ইংরেজিটা।
     */
    private static function warehouseName(): Expression
    {
        $name = app()->getLocale() === 'bn'
            ? "COALESCE(NULLIF(w.name_bn, ''), w.name_en)"
            : 'w.name_en';

        return DB::raw("{$name} as warehouse_name");
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
