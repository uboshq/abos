<?php

declare(strict_types=1);

namespace App\Modules\Restaurant\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\DocumentStatus;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Reports\LineNetSale;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;

/**
 * রেস্তোরাঁর রিপোর্ট — আজ একটাই, খাদ্য-খরচ।
 *
 * ── কেন এটা মজুদ থেকে এখানে এল, ১৫ সেপ্টেম্বর ২০২৬ ───────────────────
 * মালিক ছবিতে দাগিয়ে বলেছেন *"cooking, food cost — egolo Restaurant
 * modiule zawar kotha"*। ⭐ কথাটা ঠিক: "এক প্লেট বিরিয়ানিতে কত খরচ"
 * একটা রেস্তোরাঁর প্রশ্ন। মজুদ কেবল বলে চাল কত ছিল আর কত গেল।
 *
 * ⓘ সংজ্ঞাটা [[App\Modules\Inventory\Reports\StockReports]] থেকে হুবহু
 * আনা, কেবল `key` বদলেছে — `inventory.food_cost` → `restaurant.food_cost`।
 */
final class RestaurantReports
{
    /**
     * ⓘ [[App\Core\Module\ModuleDefinition]] প্রতিটা রিপোর্ট-সরবরাহকারীর
     * কাছে ঠিক এই নামের স্ট্যাটিক পদ্ধতিটা চায়, নাহলে মডিউল ঘোষণাই
     * ভেঙে পড়ে (`report provider … needs a static registerAll()`)।
     */
    public static function registerAll(ReportEngine $engine): void
    {
        $engine->register(self::foodCost());
    }

    /**
     * পণ্যের নাম, কোডসহ — ভাষা অনুযায়ী।
     *
     * ⚠️ এই পাঁচ লাইন মজুদের [[StockReports]]-এও আছে, আর সেটা জেনেশুনে।
     *
     * ⓘ ওখানে মেথডটা `private`, তাই বাইরে থেকে ডাকা যায় না। ⛔ আর ওটাকে
     * `public` করা মানে মজুদের ভিতরের একটা SQL-টুকরোকে অন্য মডিউলের
     * নির্ভরতা বানিয়ে ফেলা — তখন মজুদ ঐ লাইনটা আর বদলাতে পারত না, কারণ
     * বদলালে রেস্তোরাঁ ভাঙত।
     *
     * ⭐ পাঁচ লাইনের নকল একটা সীমানার চেয়ে সস্তা।
     */
    private static function dishName(): Expression
    {
        $name = app()->getLocale() === 'bn'
            ? "COALESCE(NULLIF(p.name_bn, ''), p.name_en)"
            : 'p.name_en';

        return DB::raw("CONCAT(p.code, ' - ', {$name}) as product_name");
    }

    /**
     * খাদ্য-খরচ — এক প্লেটে কত গেল, আর বিক্রয়ের কত শতাংশ।
     *
     * ── কেন সংখ্যাটা বিক্রয় থেকেই আসে, রান্না থেকে নয় ────────────────
     * রান্নার কাগজ বলে এক হাঁড়িতে কত গেল। কিন্তু ওই হাঁড়ির সব প্লেট
     * বিক্রি না-ও হতে পারে — সন্ধ্যায় দশ প্লেট নষ্ট হতে পারে।
     *
     * খাদ্য-খরচ মাপা হয় **যা বিক্রি হয়েছে** তার উপরে: বিলের লাইনে
     * বসানো `unit_cost` (যা FIFO স্তর থেকে এসেছে) বনাম ওই লাইনের
     * বিক্রয়মূল্য। নষ্ট হওয়া প্লেট ওখানে নেই, আর থাকাও উচিত নয় —
     * ওটা অন্য প্রশ্ন, আর তার উত্তর মজুদ সমন্বয়ে।
     *
     * ── কেন কেবল রেসিপিওয়ালা পণ্য ───────────────────────────────────
     * চাল বা কোকের বোতলের "খাদ্য-খরচ" মানে কেবল ক্রয়মূল্য, আর ওটা
     * মুনাফার রিপোর্টেই আছে। এই পর্দাটা রান্না করা খাবারের প্রশ্নের
     * উত্তর দেয়, তাই যাদের রেসিপি আছে কেবল তারাই আসে।
     */
    public static function foodCost(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'restaurant.food_cost',
            // ⛔ ওয়েবের দরজা যে চাবি দেখে, সেটাই — সূচি ও ফোন এখান থেকে পড়ে (২৭ সেপ্টেম্বর ২০২৬)
            permission: 'restaurant.report',
            title: 'restaurant::menu.food_cost',
            filters: ['date_range', 'branch'],
            groupBy: 'product_id',
            query: fn (array $f) => self::foodCostQuery($f),
            columns: [
                [
                    'key' => 'product_name',
                    'label' => 'inventory::field.dish',
                    'type' => ReportColumn::DOCUMENT,
                    'source_type' => 'party_type_literal',
                    'source_id' => 'product_id',
                ],
                ['key' => 'sold', 'label' => 'inventory::field.sold', 'type' => ReportColumn::QUANTITY],
                // ⭐ নিট বিক্রি — ভ্যাট বাদে, ফেরত বাদে (২১ অক্টোবর ২০২৬); শতাংশের ভিত এটাই, মোটের সারিতেও (মোট খরচ ÷ মোট নিট)
                ['key' => 'revenue', 'label' => 'inventory::field.net_sales', 'type' => ReportColumn::MONEY],
                ['key' => 'food_cost', 'label' => 'inventory::field.food_cost', 'type' => ReportColumn::MONEY],
                ['key' => 'food_cost_pct', 'label' => 'inventory::field.food_cost_pct', 'type' => ReportColumn::PERCENT, 'ratio' => ['food_cost', 'revenue']],
            ],
        );
    }

    /**
     * ⭐ খাদ্য-খরচের ভিত — কেবল পাকা বিল, নিট বিক্রি (fe, নিট বিক্রি ২য় ভাগ, ২১ অক্টোবর ২০২৬; IFRS 15.47/15.51)।
     *
     * ⛔ আগে `status <> 'cancelled'`: খসড়া (ধরে রাখা, কাউন্টারের অসমাপ্ত) বিলও গোনা হত, মোছা বিলও (`deleted_at` দেখা হত না), আর
     * বিক্রয়মূল্য ছিল সারির ভ্যাটসহ `amount`, বিলের ছাড় বাদ না দিয়ে — অথচ খাদ্য-খরচের % মানে খরচ ÷ **নিট** বিক্রি।
     * ⭐ এখন:
     *   · বিল = পাকা ([[DocumentStatus::POSTED]]), মোছা নয়, বিলের তারিখে; বিক্রি = [[LineNetSale]] (সারি − ভ্যাট ± বিলের ছাড়/গোলের ভাগ)
     *   · ফেরত = পাকা, মোছা নয়, **ফেরতের নিজের তারিখে** আর নিজের শাখায়; বিক্রি থেকে বাদ ফেরতের সারির `amount` (ভ্যাট বাদে, বিলের
     *     ছাড়ের ভাগসহ), পরিমাণ থেকে ফেরতের পরিমাণ, আর খরচ থেকে ফেরত প্লেট × মূল বিলের সারির `unit_cost` — বিলে যে দরে খরচ বসেছিল
     *   · খাদ্য-খরচ % = নিট খরচ ÷ নিট বিক্রি × ১০০
     * ⓘ রেসিপিওয়ালা পণ্য `whereExists` দিয়ে — এক পণ্যের দুই রেসিপি থাকলেও সারি দুইবার গোনা হয় না।
     *
     * @param  array<string, mixed>  $f
     */
    private static function foodCostQuery(array $f): Builder
    {
        $company = (int) $f['company_id'];

        $bills = DB::table('sal_invoice_lines as l')
            ->join('sal_invoices as i', 'i.id', '=', 'l.sales_invoice_id')
            ->where('i.company_id', $company)
            ->tap(ReportEngine::branchWall($f, 'i.branch_id'))
            ->whereBetween('i.trx_date', [$f['from'], $f['to']])
            // খসড়া বা বাতিল বিল গোনা হয় না — ওগুলো বিক্রি নয়; মোছা বিলও নয়
            ->whereIn('i.status', DocumentStatus::POSTED)
            ->whereNull('i.deleted_at')
            ->tap(fn (Builder $q) => LineNetSale::joinBasis($q, $company, $f['from'], $f['to']))
            ->select([
                'l.product_id',
                DB::raw('l.qty as qty'),
                DB::raw(LineNetSale::sql('l', 'i').' as revenue'),
                DB::raw('l.qty * l.unit_cost as food_cost'),
            ]);

        $returns = DB::table('sal_return_lines as rl')
            ->join('sal_returns as rt', 'rt.id', '=', 'rl.sales_return_id')
            ->leftJoin('sal_invoice_lines as ol', 'ol.id', '=', 'rl.sales_invoice_line_id')
            ->where('rt.company_id', $company)
            ->tap(ReportEngine::branchWall($f, 'rt.branch_id'))
            ->whereBetween('rt.trx_date', [$f['from'], $f['to']])
            ->whereIn('rt.status', DocumentStatus::POSTED)
            ->whereNull('rt.deleted_at')
            ->select([
                'rl.product_id',
                DB::raw('0 - rl.qty as qty'),
                DB::raw('0 - rl.amount as revenue'),
                DB::raw('0 - rl.qty * COALESCE(ol.unit_cost, 0) as food_cost'),
            ]);

        return DB::query()->fromSub($bills->unionAll($returns), 'm')
            ->join('inv_products as p', 'p.id', '=', 'm.product_id')
            /*
             * রেসিপি নেই এমন পণ্য বাদ।
             *
             * বাদ না দিলে তালিকায় চাল-ডাল-কোক সবই আসত, আর তাদের
             * "খাদ্য-খরচ" হত ১০০%-এর কাছাকাছি (ক্রয়মূল্য ÷ বিক্রয়মূল্য)।
             * তখন গড়টা অর্থহীন হত।
             *
             * ⓘ `inv_recipes` টেবিলের নামটা `inv_`-ই থেকে গেছে, আর
             * রেসিপি মজুদেই আছে — বিক্রয় ওটা পড়ে বলে সরানো যায়নি।
             */
            ->whereExists(fn (Builder $r) => $r->selectRaw('1')->from('inv_recipes as r')
                ->whereColumn('r.product_id', 'm.product_id')
                ->whereNull('r.deleted_at'))
            ->groupBy('m.product_id', 'p.code', 'p.name_en', 'p.name_bn')
            ->havingRaw('SUM(m.qty) > 0')
            ->orderByRaw('SUM(m.revenue) DESC')
            ->select([
                'm.product_id as product_id',
                self::dishName(),
                DB::raw("'".Product::drillSourceType()."' as party_type_literal"),
                DB::raw('SUM(m.qty) as sold'),
                DB::raw('SUM(m.revenue) as revenue'),
                DB::raw('SUM(m.food_cost) as food_cost'),
                /*
                 * শতাংশটা SQL-এ, PHP-তে নয়।
                 *
                 * রিপোর্ট ইঞ্জিন সারিগুলো সরাসরি ছকে পাঠায়; PHP-তে
                 * হিসাব করলে রপ্তানি ও ছাপায় ঘরটা খালি যেত।
                 *
                 * `NULLIF` — বিক্রয় শূন্য হলে ভাগ করা যায় না। শূন্য
                 * বিক্রয়ে খাদ্য-খরচের শতাংশ বলে কিছু নেই, তাই ঘরটা
                 * খালি থাকে; শূন্য লিখলে ওটা "চমৎকার" বলে পড়া হত।
                 */
                DB::raw('ROUND(SUM(m.food_cost) / NULLIF(SUM(m.revenue), 0) * 100, 2) as food_cost_pct'),
            ]);
    }
}
