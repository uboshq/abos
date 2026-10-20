<?php

declare(strict_types=1);

namespace App\Modules\Sales\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\DocumentStatus;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Metrics\SalesMetrics;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;

/**
 * বিক্রয়ের পাঁচটা রিপোর্ট।
 *
 * সাব-কোয়েরিগুলো কাঁচা SQL, প্লেসহোল্ডার ছাড়া — ক্রয়ের রিপোর্টে এই ভুলটা
 * একবার হয়েছিল: বিল্ডারের `?`-গুলো SELECT অংশে গিয়ে বাকি সব বাইন্ডিং এক
 * ঘর সরিয়ে দিয়েছিল, আর তৈরি হয়েছিল `status <> 51`। অবস্থার মানটা ধ্রুবক,
 * ব্যবহারকারীর ইনপুট নয়, তাই সরাসরি বসানোই নিরাপদ ও পরিষ্কার।
 */
final class SalesReports
{
    public static function registerAll(ReportEngine $engine): void
    {
        $engine->register(self::pendingOrders());
        $engine->register(self::undelivered());
        $engine->register(self::byCustomer());
        $engine->register(self::byProduct());
        $engine->register(self::byBrand());
        $engine->register(MonthlySalesReport::definition());
        // ⭐ আদায়ের সূচি — রিপোর্ট সেন্টার ধাপ ৪ (২ অক্টোবর ২০২৬)
        $engine->register(CollectionDueReport::definition());
    }

    /** যে অর্ডারগুলোর মাল এখনো পুরো যায়নি। */
    public static function pendingOrders(): ReportDefinition
    {
        $cancelled = DocumentStatus::CANCELLED;

        $delivered = "(select COALESCE(SUM(cl2.delivered_qty), 0)
                from sal_challan_lines cl2
                join sal_challans c2 on c2.id = cl2.delivery_challan_id
                where cl2.sales_order_line_id = ol.id
                  and c2.status <> '{$cancelled}')";

        return new ReportDefinition(
            key: 'sales.pending_orders',
            // ⛔ ওয়েবের দরজা যে চাবি দেখে, সেটাই — সূচি ও ফোন এখান থেকে পড়ে (২৭ সেপ্টেম্বর ২০২৬)
            permission: 'sales.report',
            title: 'sales::menu.pending_orders',
            filters: ['date_range', 'branch'],
            query: fn (array $f) => DB::table('sal_order_lines as ol')
                ->join('sal_orders as o', 'o.id', '=', 'ol.sales_order_id')
                ->join('inv_products as p', 'p.id', '=', 'ol.product_id')
                ->join('customers as cu', 'cu.id', '=', 'o.customer_id')
                ->where('o.company_id', $f['company_id'])
                ->tap(ReportEngine::branchWall($f, 'o.branch_id'))
                ->tap(ReportEngine::dealerWall($f, 'o.customer_id'))
                ->whereBetween('o.trx_date', [$f['from'], $f['to']])
                ->whereNull('o.deleted_at')
                ->where('o.status', DocumentStatus::CONFIRMED)
                // ⭐ "আর দেওয়া হবে না" অংশ বাদ (নকশা "DO বিক্রয় আদেশে মেশানো", ধাপ ৭)
                ->whereRaw("ol.ordered_qty - ol.rejected_qty > {$delivered}")
                ->orderBy('o.trx_date')
                ->orderBy('o.document_no')
                ->select([
                    'o.trx_date',
                    'o.document_no',
                    DB::raw("'".SalesOrder::drillSourceType()."' as source_type_literal"),
                    'o.id as order_id',
                    self::customerName(),
                    self::productName(),
                    'ol.ordered_qty',
                    DB::raw("{$delivered} as delivered_qty"),
                    DB::raw("ol.ordered_qty - ol.rejected_qty - {$delivered} as pending_qty"),
                ]),
            columns: [
                ['key' => 'trx_date', 'label' => 'core.print.date', 'type' => ReportColumn::DATE, 'width' => '7rem'],
                [
                    'key' => 'document_no',
                    'label' => 'core.table.document',
                    'type' => ReportColumn::DOCUMENT,
                    'source_type' => 'source_type_literal',
                    'source_id' => 'order_id',
                ],
                ['key' => 'customer_name', 'label' => 'sales::field.customer'],
                ['key' => 'product_name', 'label' => 'sales::field.product'],
                ['key' => 'ordered_qty', 'label' => 'sales::field.ordered', 'type' => ReportColumn::QUANTITY],
                ['key' => 'delivered_qty', 'label' => 'sales::field.delivered', 'type' => ReportColumn::QUANTITY],
                ['key' => 'pending_qty', 'label' => 'sales::field.pending', 'type' => ReportColumn::QUANTITY],
            ],
        );
    }

    /**
     * মাল গেছে, বিল হয়নি।
     *
     * ডিপোর গাড়ি সকালে মাল দিয়ে আসে, বিল কাটা হয় পরে। এই তালিকাটা না
     * থাকলে কোন চালানের বিল কাটতে ভুলে যাওয়া হয়েছে তা কেউ জানত না, আর
     * ওই মালের টাকা কোনোদিন চাওয়াই হত না।
     */
    public static function undelivered(): ReportDefinition
    {
        $cancelled = DocumentStatus::CANCELLED;

        $invoiced = "(select COALESCE(SUM(il.qty), 0)
                from sal_invoice_lines il
                join sal_invoices i on i.id = il.sales_invoice_id
                where il.delivery_challan_line_id = cl.id
                  and i.status <> '{$cancelled}')";

        return new ReportDefinition(
            key: 'sales.uninvoiced',
            // ⛔ ওয়েবের দরজা যে চাবি দেখে, সেটাই — সূচি ও ফোন এখান থেকে পড়ে (২৭ সেপ্টেম্বর ২০২৬)
            permission: 'sales.report',
            title: 'sales::menu.undelivered',
            filters: ['date_range', 'branch'],
            query: fn (array $f) => DB::table('sal_challan_lines as cl')
                ->join('sal_challans as c', 'c.id', '=', 'cl.delivery_challan_id')
                ->join('inv_products as p', 'p.id', '=', 'cl.product_id')
                ->join('customers as cu', 'cu.id', '=', 'c.customer_id')
                ->where('c.company_id', $f['company_id'])
                ->tap(ReportEngine::branchWall($f, 'c.branch_id'))
                ->tap(ReportEngine::dealerWall($f, 'c.customer_id'))
                ->whereBetween('c.trx_date', [$f['from'], $f['to']])
                ->whereNull('c.deleted_at')
                ->where('c.status', DocumentStatus::CONFIRMED)
                ->whereRaw("cl.delivered_qty > {$invoiced}")
                ->orderBy('c.trx_date')
                ->orderBy('c.document_no')
                ->select([
                    'c.trx_date',
                    'c.document_no',
                    DB::raw("'".DeliveryChallan::drillSourceType()."' as source_type_literal"),
                    'c.id as challan_id',
                    self::customerName(),
                    self::productName(),
                    'cl.delivered_qty',
                    DB::raw("cl.delivered_qty - {$invoiced} as uninvoiced_qty"),
                    DB::raw("(cl.delivered_qty - {$invoiced}) * cl.rate as uninvoiced_value"),
                ]),
            columns: [
                ['key' => 'trx_date', 'label' => 'core.print.date', 'type' => ReportColumn::DATE, 'width' => '7rem'],
                [
                    'key' => 'document_no',
                    'label' => 'core.table.document',
                    'type' => ReportColumn::DOCUMENT,
                    'source_type' => 'source_type_literal',
                    'source_id' => 'challan_id',
                ],
                ['key' => 'customer_name', 'label' => 'sales::field.customer'],
                ['key' => 'product_name', 'label' => 'sales::field.product'],
                ['key' => 'delivered_qty', 'label' => 'sales::field.delivered', 'type' => ReportColumn::QUANTITY],
                ['key' => 'uninvoiced_qty', 'label' => 'sales::field.uninvoiced', 'type' => ReportColumn::QUANTITY],
                ['key' => 'uninvoiced_value', 'label' => 'sales::field.uninvoiced_value', 'type' => ReportColumn::MONEY],
            ],
        );
    }

    /** কার কাছে কত বেচেছি — বিলের ভিত্তিতে। */
    public static function byCustomer(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'sales.by_customer',
            // ⛔ ওয়েবের দরজা যে চাবি দেখে, সেটাই — সূচি ও ফোন এখান থেকে পড়ে (২৭ সেপ্টেম্বর ২০২৬)
            permission: 'sales.report',
            title: 'sales::menu.by_customer',
            filters: ['date_range', 'branch'],
            groupBy: 'customer_id',

            /*
             * "সবচেয়ে বড়" মানে এখানে মোট বিক্রয়।
             *
             * এটাই অবদান % ও Top N চালু করে — "প্রথম দশজন ক্রেতা মোট
             * বিক্রয়ের ৬৮%"। এক ক্রেতা একাই ৪০% হলে সেটা একটা ঝুঁকি,
             * আর ওই সংখ্যাটা তালিকা দেখে বোঝা যায় না।
             */
            rankBy: 'net_sales',

            /*
             * ⭐ নিট বিক্রি — VAT আর ফেরত বাদে (IFRS 15; fe, ২০ অক্টোবর ২০২৬)। বিলের সারি আর ফেরতের সারি এক যোগফলে:
             * বিলের নিট [[SalesMetrics::billNetSql()]], ফেরতের নিট [[SalesMetrics::returnNetSql()]] **ফেরতের নিজের তারিখে** —
             * হোম, ফোন আর মালিকের ছকের নিট বিক্রির একই সংজ্ঞা। ⓘ যে ক্রেতার এই সময়ে কেবল ফেরত, তিনিও সারি পান — নইলে যোগফল
             * নিটের সাথে মিলত না। ⓘ মুনাফাও নিট: ফেরতের নিট বাদ, ফেরত আসা মালের খরচ (`cost_of_goods`) ফেরত।
             * ⓘ দাম, ছাড়, VAT, মোট — বিলের নিজের ঘর, আগের মতো।
             */
            query: fn (array $f) => DB::query()->fromSub(self::customerBills($f)->unionAll(self::customerReturns($f)), 'x')
                ->join('customers as cu', 'cu.id', '=', 'x.customer_id')
                ->groupBy('x.customer_id', 'cu.code', 'cu.name_en', 'cu.name_bn')
                ->orderByRaw('SUM(x.sold) - SUM(x.returned) desc')
                ->select([
                    'x.customer_id',
                    DB::raw("'customer' as source_type_literal"),
                    self::customerName(),
                    DB::raw('SUM(x.n) as invoice_count'),
                    DB::raw('SUM(x.subtotal) as subtotal'),
                    DB::raw('SUM(x.discount) as discount'),
                    DB::raw('SUM(x.tax) as tax'),
                    DB::raw('SUM(x.total) as total'),
                    DB::raw('SUM(x.returned) as returned'),
                    DB::raw('SUM(x.sold) - SUM(x.returned) as net_sales'),
                    // মুনাফা — নিট বিক্রি বাদ খরচ; ⛔ বিলে যোগ করা ভাড়া খাতায় ভাড়ার আয়, বিক্রি নয় — লাভে নয় (১৩ অক্টোবর ২০২৬)
                    DB::raw('SUM(x.profit) as gross_profit'),
                ]),
            columns: [
                [
                    'key' => 'customer_name',
                    'label' => 'sales::field.customer',
                    'type' => ReportColumn::DOCUMENT,
                    'source_type' => 'source_type_literal',
                    'source_id' => 'customer_id',
                ],
                ['key' => 'invoice_count', 'label' => 'sales::field.invoice_count'],
                ['key' => 'subtotal', 'label' => 'sales::field.subtotal', 'type' => ReportColumn::MONEY],
                ['key' => 'discount', 'label' => 'sales::field.discount', 'type' => ReportColumn::MONEY],
                ['key' => 'tax', 'label' => 'sales::field.tax', 'type' => ReportColumn::MONEY],
                ['key' => 'total', 'label' => 'sales::field.total', 'type' => ReportColumn::MONEY],
                ['key' => 'returned', 'label' => 'sales::field.returned_net', 'type' => ReportColumn::MONEY],
                ['key' => 'net_sales', 'label' => 'sales::field.net_sales', 'type' => ReportColumn::MONEY],
                /*
                 * মুনাফা — অনুমতির পেছনে।
                 *
                 * ── কেন পুরো রিপোর্ট নয়, কেবল এই কলামটা ─────────────
                 * "কে কত কিনছে" প্রশ্নটা বিক্রয়কর্মীর রোজকার কাজ, তাই
                 * রিপোর্টটা তাঁর দরকার। কিন্তু ওই একই সারিতে কত লাভ
                 * হলো সেটা তাঁর জানার কথা নয় — জানলে দরকষাকষিতে সেটাই
                 * ব্যবহার হয়, আর ক্রয়মূল্য প্রতিযোগীর কাছে পৌঁছানোর
                 * সবচেয়ে সহজ পথ ওটাই।
                 *
                 * পুরো রিপোর্ট আটকালে হয় তাঁর কাজ বন্ধ, নয় মুনাফা ফাঁস।
                 * কলাম ধরে আড়াল করলে দুইটার কোনোটাই ঘটে না।
                 */
                ['key' => 'gross_profit', 'label' => 'sales::field.gross_profit', 'type' => ReportColumn::MONEY,
                    'permission' => 'sales.cost.view'],
            ],
        );
    }

    /**
     * কোন পণ্যে কত লাভ — লাইনের ভিত্তিতে।
     *
     * ── কেন এটা "কে কত কিনছে"-র চেয়ে আলাদা প্রশ্ন ────────────────────
     * ক্রেতা ধরে মুনাফা বলে **কে** লাভজনক; পণ্য ধরে বলে **কী** লাভজনক।
     * দ্বিতীয়টা ছাড়া সিদ্ধান্তগুলো নেওয়া যায় না: কোন পণ্যের দর বাড়াতে
     * হবে, কোনটা তাকের জায়গা নিচ্ছে অথচ কিছু দিচ্ছে না, কোনটার ছাড়
     * পুরো মার্জিনটাই খেয়ে ফেলেছে।
     *
     * ── কেন খরচটা লাইনে জমানো থাকে, আজকের দর থেকে নয় ────────────────
     * `unit_cost` বিলের সময় বসে যায়। আজকের ক্রয়মূল্য দিয়ে গুনলে গত
     * মাসের মুনাফা এই মাসে বদলে যেত — দাম বাড়লে পুরনো বিক্রয়ে হঠাৎ
     * লোকসান দেখাত, অথচ ওই মালটা সস্তায় কেনাই ছিল।
     *
     * ── ঋণাত্মক মুনাফা লুকানো হয় না ─────────────────────────────────
     * নিচেই সাজানো, কিন্তু বাদ নয়। যে পণ্যে লোকসান হচ্ছে সেটাই সবচেয়ে
     * জরুরি সারি, আর সেটাই সবচেয়ে সহজে চোখ এড়ায়।
     */
    public static function byProduct(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'sales.by_product',
            // ⛔ ওয়েবের দরজা যে চাবি দেখে, সেটাই — সূচি ও ফোন এখান থেকে পড়ে (২৭ সেপ্টেম্বর ২০২৬)
            permission: 'sales.report',
            title: 'sales::menu.by_product',
            filters: ['date_range', 'branch'],
            groupBy: 'product_id',

            // "সবচেয়ে বড়" মানে এখানে মুনাফা, বিক্রয় নয় — রিপোর্টটার
            // প্রশ্নই তো "কোনটা আসলে টাকা দেয়"
            rankBy: 'gross_profit',
            /*
             * ⭐ নিট — VAT আর ফেরত বাদে (IFRS 15; fe, ২০ অক্টোবর ২০২৬): বিলের সারির নিট ([[LineNetSale]]) বাদ ফেরতের সারির নিট
             * (`rl.amount`, আগেই VAT বাদে), ফেরতের নিজের তারিখে; খরচ থেকে ফেরত আসা মালের খরচ বাদ ([[lineUnion()]])।
             */
            query: fn (array $f) => DB::query()->fromSub(self::lineUnion($f), 'x')
                ->join('inv_products as p', 'p.id', '=', 'x.product_id')
                ->groupBy('x.product_id', 'p.code', 'p.name_en', 'p.name_bn')
                ->orderByRaw('SUM(x.revenue) - SUM(x.returned) - SUM(x.cost) desc')
                ->select([
                    'x.product_id',
                    DB::raw("'".Product::drillSourceType()."' as source_type_literal"),
                    self::productName(),
                    DB::raw('SUM(x.qty) as qty'),
                    DB::raw('SUM(x.discount) as discount'),

                    /*
                     * বিক্রয় — ভ্যাট বাদ দিয়ে, ফেরত বাদ দিয়ে।
                     *
                     * ভ্যাটটা কখনোই আমাদের আয় নয়, ওটা সরকারের টাকা যা আমরা কেবল আদায় করে জমা দিই; না বাদ দিলে ৫% ভ্যাটওয়ালা
                     * পণ্যকে ৫% বেশি লাভজনক দেখাত। ⛔ বিলের ছাড়ের ভাগও সারিতে ([[LineNetSale]])। ⭐ ফেরত বাদ — ফেরত আসা মাল বিক্রি নয়।
                     */
                    DB::raw('SUM(x.returned) as returned'),
                    DB::raw('SUM(x.revenue) - SUM(x.returned) as revenue'),
                    DB::raw('SUM(x.cost) as cost'),
                    DB::raw('SUM(x.revenue) - SUM(x.returned) - SUM(x.cost) as gross_profit'),

                    // মার্জিন — শতাংশ, নিট বিক্রিতে; শূন্য বিক্রয়ে ভাগ করা হয় না (ডাটাবেজ ওখানে NULL দিত)
                    DB::raw('CASE WHEN SUM(x.revenue) - SUM(x.returned) = 0 THEN 0
                             ELSE ROUND((SUM(x.revenue) - SUM(x.returned) - SUM(x.cost)) * 100
                                        / (SUM(x.revenue) - SUM(x.returned)), 2) END as margin_percent'),
                ]),
            columns: [
                [
                    'key' => 'product_name',
                    'label' => 'sales::field.product',
                    'type' => ReportColumn::DOCUMENT,
                    'source_type' => 'source_type_literal',
                    'source_id' => 'product_id',
                ],
                ['key' => 'qty', 'label' => 'sales::field.quantity', 'type' => ReportColumn::QUANTITY],
                ['key' => 'discount', 'label' => 'sales::field.discount', 'type' => ReportColumn::MONEY],
                ['key' => 'returned', 'label' => 'sales::field.returned_net', 'type' => ReportColumn::MONEY],
                ['key' => 'revenue', 'label' => 'sales::field.net_sales', 'type' => ReportColumn::MONEY],

                /*
                 * ক্রয়মূল্য, মুনাফা ও মার্জিন — তিনটাই অনুমতির পেছনে।
                 *
                 * বিক্রয়কর্মী দেখবেন কোন পণ্য কত বিকোচ্ছে; কত লাভ হচ্ছে
                 * নয়। মার্জিনটাও ঢাকতে হয়, কারণ শতাংশ আর বিক্রয় জানা
                 * থাকলে ক্রয়মূল্য এক ভাগেই বেরিয়ে আসে — একটা ঢেকে
                 * অন্যটা খোলা রাখা মানে কিছুই না ঢাকা।
                 */
                ['key' => 'cost', 'label' => 'sales::field.cost', 'type' => ReportColumn::MONEY,
                    'permission' => 'sales.cost.view'],
                ['key' => 'gross_profit', 'label' => 'sales::field.gross_profit', 'type' => ReportColumn::MONEY,
                    'permission' => 'sales.cost.view'],
                ['key' => 'margin_percent', 'label' => 'sales::field.margin_percent',
                    'permission' => 'sales.cost.view'],
            ],
        );
    }

    /**
     * ব্র্যান্ড ধরে বিক্রয় ও মুনাফা।
     *
     * ── কেন এটা পণ্যভিত্তিক রিপোর্টের চেয়ে আলাদা প্রশ্ন ──────────────
     * দুইশো পণ্যের তালিকায় কোনটা বাড়ছে আর কোনটা কমছে তা চোখে পড়ে না,
     * কিন্তু বিশটা ব্র্যান্ডে পড়ে। আর দরকষাকষিটাও হয় ব্র্যান্ড ধরে:
     * "এই কোম্পানির মাল আমরা বছরে কত তুলি" — সেই সংখ্যাটা ছাড়া
     * পরিবেশক ছাড় চাইতে বসতে পারেন না।
     *
     * ── কেন এই রিপোর্টের আগে ব্র্যান্ডকে সারি বানাতে হলো ────────────
     * `brand` ছিল মুক্ত লেখার একটা ঘর, তাই একই ব্র্যান্ড কয়েক বানানে
     * বসত ("Nestle", "nestle", "নেসলে")। এই রিপোর্টটা তখন এক ব্র্যান্ডকে
     * চার সারিতে ভাগ করে দেখাত, প্রতিটার অঙ্ক আসলের এক-চতুর্থাংশ, আর
     * কোনো সারিই সত্যি নয় — অথচ পর্দাটা নিখুঁত দেখাত।
     *
     * ── ব্র্যান্ড ছাড়া পণ্যগুলোও থাকে ───────────────────────────────
     * `leftJoin`, আর ব্র্যান্ডহীনগুলো একটা সারিতে জড়ো হয়। বাদ দিলে
     * এই রিপোর্টের যোগফল আর "মোট বিক্রয়" আলাদা হত, আর সেই দুইটা সংখ্যা
     * মেলাতে গিয়ে কেউ ভাবত কোথাও হিসাব ভুল।
     */
    public static function byBrand(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'sales.by_brand',
            // ⛔ ওয়েবের দরজা যে চাবি দেখে, সেটাই — সূচি ও ফোন এখান থেকে পড়ে (২৭ সেপ্টেম্বর ২০২৬)
            permission: 'sales.report',
            title: 'sales::menu.by_brand',
            filters: ['date_range', 'branch'],
            groupBy: 'brand_id',

            // "সবচেয়ে বড়" মানে এখানে বিক্রয় — দরকষাকষির সংখ্যাটা ওটাই
            rankBy: 'revenue',
            // ⭐ নিট — VAT আর ফেরত বাদে, পণ্য ধরে রিপোর্টের একই সারি ([[lineUnion()]]; fe, ২০ অক্টোবর ২০২৬)
            query: fn (array $f) => DB::query()->fromSub(self::lineUnion($f), 'x')
                ->join('inv_products as p', 'p.id', '=', 'x.product_id')
                ->leftJoin('mdm_brands as b', 'b.id', '=', 'p.brand_id')
                ->groupBy('p.brand_id', 'b.name_en', 'b.name_bn')
                ->orderByRaw('SUM(x.revenue) - SUM(x.returned) desc')
                ->select([
                    'p.brand_id',
                    self::brandName(),
                    DB::raw('COUNT(DISTINCT x.product_id) as product_count'),
                    DB::raw('SUM(x.qty) as qty'),

                    // ভ্যাট বাদ — ওটা সরকারের টাকা, আমাদের আয় নয়; ফেরত বাদ — ফেরত আসা মাল বিক্রি নয়
                    DB::raw('SUM(x.returned) as returned'),
                    DB::raw('SUM(x.revenue) - SUM(x.returned) as revenue'),
                    DB::raw('SUM(x.cost) as cost'),
                    DB::raw('SUM(x.revenue) - SUM(x.returned) - SUM(x.cost) as gross_profit'),
                ]),
            columns: [
                ['key' => 'brand_name', 'label' => 'inventory::field.brand'],
                ['key' => 'product_count', 'label' => 'sales::field.product_count'],
                ['key' => 'qty', 'label' => 'sales::field.quantity', 'type' => ReportColumn::QUANTITY],
                ['key' => 'returned', 'label' => 'sales::field.returned_net', 'type' => ReportColumn::MONEY],
                ['key' => 'revenue', 'label' => 'sales::field.net_sales', 'type' => ReportColumn::MONEY],

                // ক্রয়মূল্য ও মুনাফা অনুমতির পেছনে — নিয়ম ২৪
                ['key' => 'cost', 'label' => 'sales::field.cost', 'type' => ReportColumn::MONEY,
                    'permission' => 'sales.cost.view'],
                ['key' => 'gross_profit', 'label' => 'sales::field.gross_profit', 'type' => ReportColumn::MONEY,
                    'permission' => 'sales.cost.view'],
            ],
        );
    }

    /**
     * ব্র্যান্ডের নাম — ব্র্যান্ড না থাকলে "ব্র্যান্ড ছাড়া"।
     *
     * খালি ঘর রাখলে সারিটা নামহীন দেখাত, আর ক্লিক করেও কিছু হত না। যে
     * পণ্যগুলোর ব্র্যান্ড বসানো হয়নি, সেটা জানাটাও একটা কাজ — ওই সারিটা
     * বড় হলে বোঝা যায় মাস্টার ডেটা অসম্পূর্ণ।
     */
    private static function brandName(): Expression
    {
        $name = app()->getLocale() === 'bn'
            ? "COALESCE(NULLIF(b.name_bn, ''), b.name_en)"
            : 'b.name_en';

        /*
         * লেখাটা ড্রাইভারকে দিয়েই উদ্ধৃত করানো, বাইন্ডিং নয়।
         *
         * SELECT অংশে `?` বসালে বাকি সব বাইন্ডিং এক ঘর সরে যায় — এই
         * ফাইলের মাথায় লেখা ভুলটা ঠিক তাই ছিল। আর হাতে উদ্ধৃতি বসানো
         * মানে বাংলা লেখায় একটা apostrophe থাকলেই SQL ভাঙত।
         */
        $none = DB::getPdo()->quote(__('sales::message.no_brand'));

        return DB::raw("COALESCE({$name}, {$none}) as brand_name");
    }

    private static function productName(): Expression
    {
        $name = app()->getLocale() === 'bn'
            ? "COALESCE(NULLIF(p.name_bn, ''), p.name_en)"
            : 'p.name_en';

        return DB::raw("CONCAT(p.code, ' - ', {$name}) as product_name");
    }

    /**
     * ক্রেতা ধরে রিপোর্টের বিলের সারি — খাতায় বসা বিল, খসড়া নয় (আগে "বাতিল ছাড়া সব" ছিল; তাতে কাউন্টারে ধরে রাখা খসড়াও
     * ঢুকত আর হোম পর্দার সাথে মিলত না)।
     *
     * @param  array<string, mixed>  $f
     */
    private static function customerBills(array $f): \Illuminate\Database\Query\Builder
    {
        return DB::table('sal_invoices as i')
            ->where('i.company_id', $f['company_id'])
            ->tap(ReportEngine::branchWall($f, 'i.branch_id'))
            ->tap(ReportEngine::dealerWall($f, 'i.customer_id'))
            ->whereBetween('i.trx_date', [$f['from'], $f['to']])
            ->whereNull('i.deleted_at')
            ->whereIn('i.status', DocumentStatus::POSTED)
            ->selectRaw('i.customer_id as customer_id, 1 as n, i.subtotal as subtotal, i.discount as discount, i.tax as tax, '
                .'i.total as total, '.SalesMetrics::billNetSql('i').' as sold, 0 as returned, '
                .'('.SalesMetrics::billNetSql('i').' - i.cost_of_goods) as profit');
    }

    /**
     * ক্রেতা ধরে রিপোর্টের ফেরতের সারি — খাতায় বসা ফেরত, **ফেরতের তারিখে**, একই দুই দেয়াল।
     *
     * @param  array<string, mixed>  $f
     */
    private static function customerReturns(array $f): \Illuminate\Database\Query\Builder
    {
        return DB::table('sal_returns as r')
            ->where('r.company_id', $f['company_id'])
            ->tap(ReportEngine::branchWall($f, 'r.branch_id'))
            ->tap(ReportEngine::dealerWall($f, 'r.customer_id'))
            ->whereBetween('r.trx_date', [$f['from'], $f['to']])
            ->whereNull('r.deleted_at')
            ->whereIn('r.status', DocumentStatus::POSTED)
            ->selectRaw('r.customer_id as customer_id, 0 as n, 0 as subtotal, 0 as discount, 0 as tax, 0 as total, 0 as sold, '
                .SalesMetrics::returnNetSql('r').' as returned, '
                .'(r.cost_of_goods - '.SalesMetrics::returnNetSql('r').') as profit');
    }

    /**
     * ⭐ পণ্য আর ব্র্যান্ড ধরে রিপোর্টের সারি — বিলের সারি আর ফেরতের সারি এক তালিকায় (fe, ২০ অক্টোবর ২০২৬)।
     *
     *   বিলের সারি:  qty = বিক্রির পরিমাণ, revenue = [[LineNetSale]] (VAT আর বিলের ছাড়ের ভাগ বাদে), cost = পরিমাণ × `unit_cost`
     *   ফেরতের সারি: qty = − ফেরতের পরিমাণ, returned = `rl.amount` (আগেই VAT বাদে, বিলের ছাড়ের ভাগসহ —
     *               [[SalesReturnService::shareOfTheBill()]]), cost = − ফেরতের খরচের ভাগ (ফেরতের `cost_of_goods`, সারির টাকার অনুপাতে)
     *
     * ⓘ ফেরত **নিজের তারিখে**, একই দুই দেয়াল (শাখা, বিক্রয়কর্মীর ডিলার)। ⓘ সব ফেরতের সারির `amount`-এর যোগ = ফেরতের
     * `total − tax` = [[SalesMetrics::returnNetSql()]], তাই রিপোর্টের নিট = হোমের নিট (বিলে যোগ করা ভাড়া ছাড়া)।
     * ⚠️ বিনা-দামের (ফ্রি) ফেরত সারি খরচের ভাগ পায় না — দাম শূন্য বলে অনুপাতও শূন্য।
     *
     * @param  array<string, mixed>  $f
     */
    private static function lineUnion(array $f): \Illuminate\Database\Query\Builder
    {
        $bills = DB::table('sal_invoice_lines as il')
            ->join('sal_invoices as i', 'i.id', '=', 'il.sales_invoice_id')
            // ⛔ বিলের মাথার ছাড় আর রাউন্ডিংয়ের ভাগ সারিতে ([[LineNetSale]], ১৩ অক্টোবর ২০২৬)
            ->tap(fn ($q) => LineNetSale::joinBasis($q, (int) $f['company_id'], $f['from'], $f['to']))
            ->where('i.company_id', $f['company_id'])
            ->tap(ReportEngine::branchWall($f, 'i.branch_id'))
            ->tap(ReportEngine::dealerWall($f, 'i.customer_id'))
            ->whereBetween('i.trx_date', [$f['from'], $f['to']])
            ->whereNull('i.deleted_at')
            // খাতায় বসা বিল — byCustomer()-এর একই কারণে
            ->whereIn('i.status', DocumentStatus::POSTED)
            ->selectRaw('il.product_id as product_id, il.qty as qty, il.discount as discount, '
                .LineNetSale::sql().' as revenue, 0 as returned, il.qty * il.unit_cost as cost');

        $returns = DB::table('sal_return_lines as rl')
            ->join('sal_returns as r', 'r.id', '=', 'rl.sales_return_id')
            ->where('r.company_id', $f['company_id'])
            ->tap(ReportEngine::branchWall($f, 'r.branch_id'))
            ->tap(ReportEngine::dealerWall($f, 'r.customer_id'))
            ->whereBetween('r.trx_date', [$f['from'], $f['to']])
            ->whereNull('r.deleted_at')
            ->whereIn('r.status', DocumentStatus::POSTED)
            ->selectRaw('rl.product_id as product_id, 0 - rl.qty as qty, 0 as discount, 0 as revenue, rl.amount as returned, '
                .'0 - COALESCE(r.cost_of_goods * rl.amount / NULLIF(r.subtotal, 0), 0) as cost');

        return $bills->unionAll($returns);
    }
    private static function customerName(): Expression
    {
        $name = app()->getLocale() === 'bn'
            ? "COALESCE(NULLIF(cu.name_bn, ''), cu.name_en)"
            : 'cu.name_en';

        return DB::raw("{$name} as customer_name");
    }
}
