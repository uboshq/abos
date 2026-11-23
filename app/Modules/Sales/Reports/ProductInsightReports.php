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

    public const PRICE = 'sales.price_realisation';

    public static function registerAll(ReportEngine $engine): void
    {
        $engine->register(self::abc());
        $engine->register(self::priceRealisation());
    }

    /**
     * ⭐ ১১. দামের প্রাপ্তি — তালিকার দামে কত হত আর আসলে কত এল, পণ্য ধরে (মালিক, ২৩ নভেম্বর ২০২৬)।
     *
     * ⓘ তালিকার দাম বিলের লাইনে লেখা থাকে না; থাকে আসল দর (`rate`) আর সেটা প্রমিত দামের চেয়ে কত % সরে আছে (`price_variance`,
     * ঋণাত্মক মানে কমে বিক্রি)। তাই তালিকার দাম = দর ÷ (১ + সরণ%) — বিলের দিনের প্রমিত দাম, আজকেরটা নয়। সরণ লেখা নেই এমন লাইনে
     * দরটাই তালিকার দাম ধরা হয় (প্রাপ্তি ১০০%)।
     *   তালিকার দামে হত  Σ পরিমাণ × তালিকার দাম
     *   আসলে হয়েছে      Σ পরিমাণ × দর − লাইনের ছাড় (ভ্যাট আগে; বিলের মাথার ছাড় এখানে নয় — সেটা পণ্যের দামের সিদ্ধান্ত নয়)
     *   প্রাপ্তি          আসলে ÷ তালিকার দামে; "কম এসেছে" দুইয়ের তফাত
     * ⓘ ফেরত এখানে নেই — প্রশ্নটা দামের, পরিমাণের নয়।
     */
    private static function priceRealisation(): ReportDefinition
    {
        $money = fn (string $key, array $more = []): array => ['key' => $key, 'label' => 'sales::product_insight.'.$key,
            'type' => ReportColumn::MONEY, 'drill' => self::door(), ...$more];

        return new ReportDefinition(
            key: self::PRICE,
            permission: 'sales.report',
            title: 'sales::product_insight.price_title',
            filters: ['date_range', 'branch'],
            groupBy: 'product_id',
            rankBy: 'lost',
            query: function (array $f): Builder {
                // ⓘ সরণ −১০০% হলে ভাগ শূন্যে — তখন দরটাই (অমন লাইন ভুল তথ্য, রিপোর্ট ভাঙে না)
                $list = 'il.qty * il.rate / COALESCE(NULLIF(1 + COALESCE(il.price_variance, 0) / 100, 0), 1)';
                $real = '(il.qty * il.rate - il.discount)';

                return DB::table('sal_invoice_lines as il')
                    ->join('sal_invoices as i', 'i.id', '=', 'il.sales_invoice_id')
                    ->join('inv_products as p', 'p.id', '=', 'il.product_id')
                    ->where('i.company_id', $f['company_id'])->whereNull('i.deleted_at')
                    ->whereIn('i.status', \App\Core\Support\DocumentStatus::POSTED)
                    ->whereBetween('i.trx_date', [$f['from'], $f['to']])
                    ->tap(ReportEngine::branchWall($f, 'i.branch_id'))
                    ->tap(ReportEngine::dealerWall($f, 'i.customer_id'))
                    ->groupBy('il.product_id', 'p.code', 'p.name_en', 'p.name_bn')
                    ->selectRaw('il.product_id as product_id, '.DB::getPdo()->quote(Product::drillSourceType()).' as source_type, '
                        .self::productName().' as product, SUM(il.qty) as qty, '
                        ."CASE WHEN SUM(il.qty) > 0 THEN ROUND(SUM({$list}) / SUM(il.qty), 2) END as list_price, "
                        ."CASE WHEN SUM(il.qty) > 0 THEN ROUND(SUM({$real}) / SUM(il.qty), 2) END as avg_price, "
                        ."SUM({$list}) as at_list, SUM({$real}) as realised, "
                        ."CASE WHEN SUM({$list}) > 0 THEN ROUND(SUM({$real}) * 100 / SUM({$list}), 2) END as realisation, "
                        ."SUM({$list}) - SUM({$real}) as lost")
                    ->orderByRaw("SUM({$list}) - SUM({$real}) DESC")->orderBy('il.product_id');
            },
            columns: [
                ['key' => 'product', 'label' => 'sales::field.product', 'type' => ReportColumn::DOCUMENT,
                    'source_type' => 'source_type', 'source_id' => 'product_id'],
                ['key' => 'qty', 'label' => 'sales::field.quantity', 'type' => ReportColumn::QUANTITY],
                // ⓘ দামের ঘর যোগ হয় না (চাবিতে `price` — [[ReportColumn]]-এর নিয়ম); মোটের সারিতে খালি
                $money('list_price'),
                $money('avg_price'),
                ['key' => 'realisation', 'label' => 'sales::product_insight.realisation', 'type' => ReportColumn::PERCENT, 'ratio' => ['realised', 'at_list'], 'width' => '7rem'],
                $money('at_list'),
                $money('realised'),
                $money('lost'),
            ],
        );
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
