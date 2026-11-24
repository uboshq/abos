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

    public const TARGET = 'sales.target_sales_product';

    public static function registerAll(ReportEngine $engine): void
    {
        $engine->register(self::abc());
        $engine->register(self::priceRealisation());
        $engine->register(self::target());
    }

    /**
     * ⭐ ৪. লক্ষ্য বনাম বিক্রয় — পণ্য ধরে: পণ্যের মাসিক লক্ষ্যের (`sal_product_targets`, [[ProductTargetService]]) পরিমাণ আর টাকা, তার
     * পাশে বিক্রির পরিমাণ আর নিট বিক্রি, দুই অর্জনের হার (মালিক, ২৩ নভেম্বর ২০২৬ — "হ্যাঁ, পণ্য ধরে লক্ষ্য চাই")।
     *
     * ⓘ লক্ষ্য — বাছা সময় যে মাসগুলো ছোঁয় তাদের যোগ (ডিলারের লক্ষ্যের রিপোর্টের একই নিয়ম)। সারিতে আসে যে পণ্যের লক্ষ্য আছে বা বিক্রি
     * আছে; লক্ষ্য নেই এমন পণ্যের হারের ঘর খালি।
     * ⓘ লক্ষ্য গোটা কোম্পানির — শাখা বাছলে বিক্রি সেই শাখার, লক্ষ্য তবু পুরো কোম্পানির (শাখা ধরে পণ্যের লক্ষ্য বসানো হয় না)।
     * ⓘ পরিমাণের ঘর মোটে যোগ হয় না — ভিন্ন পণ্যের একক ভিন্ন; টাকার হার মোটে মোট ÷ মোট।
     */
    private static function target(): ReportDefinition
    {
        $money = fn (string $key, string $label): array => ['key' => $key, 'label' => $label, 'type' => ReportColumn::MONEY, 'drill' => self::door()];

        return new ReportDefinition(
            key: self::TARGET,
            permission: 'sales.report',
            title: 'sales::product_target.report_title',
            filters: ['date_range', 'branch'],
            groupBy: 'product_id',
            rankBy: 'net_sales',
            query: function (array $f): Builder {
                $sold = DB::query()->fromSub(self::rows($f), 'n')
                    ->selectRaw('n.product_id as product_id, n.product as product, n.qty as qty, n.net_sales as net_sales, 0 as target_qty, 0 as target');
                $targets = DB::table('sal_product_targets as pt')
                    ->join('inv_products as p', 'p.id', '=', 'pt.product_id')
                    ->where('pt.company_id', $f['company_id'])
                    ->whereBetween('pt.month', [
                        \Illuminate\Support\Carbon::parse((string) $f['from'])->startOfMonth()->toDateString(),
                        \Illuminate\Support\Carbon::parse((string) $f['to'])->startOfMonth()->toDateString(),
                    ])
                    ->selectRaw('pt.product_id as product_id, '.self::productName().' as product, 0 as qty, 0 as net_sales, pt.qty as target_qty, pt.amount as target');

                return DB::query()->fromSub($sold->unionAll($targets), 'x')
                    ->groupBy('x.product_id', 'x.product')
                    ->selectRaw('x.product_id as product_id, '.DB::getPdo()->quote(Product::drillSourceType()).' as source_type, x.product as product, '
                        .'SUM(x.target_qty) as target_qty, SUM(x.qty) as qty, '
                        .'CASE WHEN SUM(x.target_qty) > 0 THEN ROUND(SUM(x.qty) * 100 / SUM(x.target_qty), 2) END as qty_achieved, '
                        .'SUM(x.target) as target, SUM(x.net_sales) as net_sales, '
                        .'CASE WHEN SUM(x.target) > 0 THEN ROUND(SUM(x.net_sales) * 100 / SUM(x.target), 2) END as achieved, '
                        .'SUM(x.net_sales) - SUM(x.target) as gap')
                    ->orderByRaw('SUM(x.net_sales) DESC')->orderBy('x.product_id');
            },
            columns: [
                ['key' => 'product', 'label' => 'sales::field.product', 'type' => ReportColumn::DOCUMENT,
                    'source_type' => 'source_type', 'source_id' => 'product_id'],
                ['key' => 'target_qty', 'label' => 'sales::product_target.target_qty', 'type' => ReportColumn::QUANTITY, 'total' => false],
                ['key' => 'qty', 'label' => 'sales::product_target.sold_qty', 'type' => ReportColumn::QUANTITY, 'total' => false],
                ['key' => 'qty_achieved', 'label' => 'sales::product_target.qty_achieved', 'type' => ReportColumn::PERCENT, 'width' => '7rem'],
                $money('target', 'sales::product_target.target_amount'),
                $money('net_sales', 'sales::area_sales.net_sales'),
                ['key' => 'achieved', 'label' => 'sales::product_target.amount_achieved', 'type' => ReportColumn::PERCENT, 'ratio' => ['net_sales', 'target'], 'width' => '7rem'],
                $money('gap', 'sales::customer_insight.gap'),
            ],
            chart: ['type' => 'pair', 'label' => 'product', 'series' => ['target', 'net_sales'], 'title' => 'sales::customer_insight.chart_target'],
        );
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
            chart: ['type' => 'bars', 'label' => 'product', 'series' => ['lost'], 'title' => 'sales::product_insight.lost'],
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
            chart: ['type' => 'bars', 'label' => 'product', 'series' => ['net_sales'], 'title' => 'sales::customer_insight.chart_abc'],
        );
    }
}
