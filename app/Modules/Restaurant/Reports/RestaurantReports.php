<?php

declare(strict_types=1);

namespace App\Modules\Restaurant\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Modules\Inventory\Models\Product;
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
            title: 'restaurant::menu.food_cost',
            filters: ['date_range', 'branch'],
            groupBy: 'product_id',
            query: fn (array $f) => DB::table('sal_invoice_lines as l')
                ->join('sal_invoices as i', 'i.id', '=', 'l.sales_invoice_id')
                ->join('inv_products as p', 'p.id', '=', 'l.product_id')
                /*
                 * `join`, `leftJoin` নয় — রেসিপি নেই এমন পণ্য বাদ।
                 *
                 * বাদ না দিলে তালিকায় চাল-ডাল-কোক সবই আসত, আর তাদের
                 * "খাদ্য-খরচ" হত ১০০%-এর কাছাকাছি (ক্রয়মূল্য ÷ বিক্রয়মূল্য)।
                 * তখন গড়টা অর্থহীন হত।
                 *
                 * ⓘ `inv_recipes` টেবিলের নামটা `inv_`-ই থেকে গেছে, আর
                 * রেসিপি মজুদেই আছে — বিক্রয় ওটা পড়ে বলে সরানো যায়নি।
                 */
                ->join('inv_recipes as r', function ($join) {
                    $join->on('r.product_id', '=', 'l.product_id')
                        ->whereNull('r.deleted_at');
                })
                ->where('i.company_id', $f['company_id'])
                ->when($f['branch_id'], fn ($q, $b) => $q->where('i.branch_id', $b))
                ->whereBetween('i.trx_date', [$f['from'], $f['to']])
                // বাতিল বিল গোনা হয় না — ওগুলো ঘটেইনি
                ->where('i.status', '<>', 'cancelled')
                ->groupBy('l.product_id', 'p.code', 'p.name_en', 'p.name_bn')
                ->havingRaw('SUM(l.qty) > 0')
                ->orderByRaw('SUM(l.amount) DESC')
                ->select([
                    'l.product_id',
                    self::dishName(),
                    DB::raw("'".Product::drillSourceType()."' as party_type_literal"),
                    DB::raw('SUM(l.qty) as sold'),
                    DB::raw('SUM(l.amount) as revenue'),
                    DB::raw('SUM(l.qty * l.unit_cost) as food_cost'),
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
                    DB::raw('ROUND(SUM(l.qty * l.unit_cost) / NULLIF(SUM(l.amount), 0) * 100, 2) as food_cost_pct'),
                ]),
            columns: [
                [
                    'key' => 'product_name',
                    'label' => 'inventory::field.dish',
                    'type' => ReportColumn::DOCUMENT,
                    'source_type' => 'party_type_literal',
                    'source_id' => 'product_id',
                ],
                ['key' => 'sold', 'label' => 'inventory::field.sold', 'type' => ReportColumn::QUANTITY],
                ['key' => 'revenue', 'label' => 'inventory::field.revenue', 'type' => ReportColumn::MONEY],
                ['key' => 'food_cost', 'label' => 'inventory::field.food_cost', 'type' => ReportColumn::MONEY],
                ['key' => 'food_cost_pct', 'label' => 'inventory::field.food_cost_pct', 'type' => ReportColumn::PERCENT],
            ],
        );
    }
}
