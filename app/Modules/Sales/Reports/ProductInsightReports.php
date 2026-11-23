<?php

declare(strict_types=1);

namespace App\Modules\Sales\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Modules\Inventory\Models\Product;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ পণ্য ধরে বিশ্লেষণ — মালিক, ২৩ নভেম্বর ২০২৬ (রোডম্যাপ §৩; অনুমোদিত নমুনা claude.ai artifact 37BW47dwWD88uuYN9AryA9 সংস্করণ ২)।
 *
 *   ৬. পণ্যের ABC — নিট বিক্রির ৮০% পর্যন্ত A, ৯৫% পর্যন্ত B, বাকি C: কোন ক'টা পণ্য থেকে বেশিরভাগ বিক্রি আসে।
 *
 * ⛔ দ্বিতীয় কোনো গণনা নয় — সারি পণ্যভিত্তিক মুনাফার রিপোর্টের ([[SalesReports::lineUnion()]]): বিলের লাইনের নিট (ভ্যাট বাদ, বিলের ছাড়ের
 * ভাগসহ) বাদ ফেরত, ফেরতের দিনে। তাই এখানকার মোট নিট বিক্রি পণ্যভিত্তিক মুনাফার মোটের সমান।
 */
final class ProductInsightReports
{
    public const ABC = 'sales.product_abc';

    public static function registerAll(ReportEngine $engine): void
    {
        $engine->register(self::abc());
    }

    /** পণ্যের নাম পড়ার ভাষায় — কোডসহ ([[SalesReports]]-এর একই রূপ) */
    public static function productName(string $table = 'p'): string
    {
        $name = app()->getLocale() === 'bn' ? "COALESCE(NULLIF({$table}.name_bn, ''), {$table}.name_en)" : "{$table}.name_en";

        return "CONCAT({$table}.code, ' - ', {$name})";
    }

    /**
     * প্রতি পণ্যের এক সারি — `product_id, product, qty, sales, returned, net_sales`।
     *
     * @param  array<string, mixed>  $f
     */
    public static function rows(array $f): Builder
    {
        return DB::query()->fromSub(SalesReports::lineUnion($f), 'x')
            ->join('inv_products as p', 'p.id', '=', 'x.product_id')
            ->groupBy('x.product_id', 'p.code', 'p.name_en', 'p.name_bn')
            ->selectRaw('x.product_id as product_id, '.self::productName().' as product, SUM(x.qty) as qty, '
                .'SUM(x.revenue) as sales, SUM(x.returned) as returned, SUM(x.revenue) - SUM(x.returned) as net_sales');
    }

    /** পণ্যভিত্তিক মুনাফার রিপোর্ট — এই রিপোর্টগুলোর অঙ্কের উৎস, একই সময় আর শাখায় */
    public static function door(): array
    {
        $asked = ['slug' => '=by-product', 'from' => '@from', 'to' => '@to', 'branch_id' => '@branch_id'];

        return ['route' => 'sales.report.show', 'params' => $asked, 'total' => $asked, 'label' => 'sales::product_insight.drill_product'];
    }

    private static function abc(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::ABC,
            permission: 'sales.report',
            title: 'sales::product_insight.abc_title',
            filters: ['date_range', 'branch'],
            query: function (array $f): Builder {
                // ⓘ ভিতরে নিট বিক্রি আছে এমন পণ্য, বাইরে ক্রম, অংশ আর জমা অংশ — জানালার যোগ গোটা ফলের উপর, পাতার উপর নয়
                $ranked = DB::query()->fromSub(self::rows($f)->havingRaw('SUM(x.revenue) - SUM(x.returned) > 0'), 'n')
                    ->selectRaw('n.*, SUM(n.net_sales) OVER () as all_net, '
                        .'SUM(n.net_sales) OVER (ORDER BY n.net_sales DESC, n.product_id) as running, '
                        .'ROW_NUMBER() OVER (ORDER BY n.net_sales DESC, n.product_id) as sl');

                return DB::query()->fromSub($ranked, 'a')
                    ->selectRaw('a.sl, a.product, '.DB::getPdo()->quote(Product::drillSourceType()).' as source_type, a.product_id as source_id, '
                        .'a.qty, a.net_sales, ROUND(a.net_sales * 100 / a.all_net, 2) as share, ROUND(a.running * 100 / a.all_net, 2) as cumulative, '
                        // ⓘ শ্রেণি ঠিক হয় পণ্যের **আগের** জমা অংশ দেখে — যে পণ্য ৮০% পার করায় সেটাও A ([[CustomerInsightReports]]-এর একই সীমা)
                        .'CASE WHEN (a.running - a.net_sales) * 100 / a.all_net < '.CustomerInsightReports::A_UP_TO." THEN 'A'"
                        .' WHEN (a.running - a.net_sales) * 100 / a.all_net < '.CustomerInsightReports::B_UP_TO." THEN 'B' ELSE 'C' END as class")
                    ->orderBy('a.sl');
            },
            columns: [
                ['key' => 'sl', 'label' => 'sales::customer_insight.sl', 'type' => ReportColumn::QUANTITY, 'total' => false, 'width' => '4rem'],
                ['key' => 'product', 'label' => 'sales::field.product', 'type' => ReportColumn::DOCUMENT,
                    'source_type' => 'source_type', 'source_id' => 'source_id'],
                ['key' => 'qty', 'label' => 'sales::field.quantity', 'type' => ReportColumn::QUANTITY],
                ['key' => 'net_sales', 'label' => 'sales::area_sales.net_sales', 'type' => ReportColumn::MONEY, 'drill' => self::door()],
                ['key' => 'share', 'label' => 'sales::customer_insight.share', 'type' => ReportColumn::PERCENT, 'width' => '6rem'],
                ['key' => 'cumulative', 'label' => 'sales::customer_insight.cumulative', 'type' => ReportColumn::PERCENT, 'width' => '7rem'],
                ['key' => 'class', 'label' => 'sales::customer_insight.class', 'width' => '5rem'],
            ],
        );
    }
}
