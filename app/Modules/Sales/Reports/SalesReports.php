<?php

declare(strict_types=1);

namespace App\Modules\Sales\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\DocumentStatus;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;

/**
 * বিক্রয়ের চারটা রিপোর্ট।
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
            title: 'sales::menu.pending_orders',
            filters: ['date_range', 'branch'],
            query: fn (array $f) => DB::table('sal_order_lines as ol')
                ->join('sal_orders as o', 'o.id', '=', 'ol.sales_order_id')
                ->join('inv_products as p', 'p.id', '=', 'ol.product_id')
                ->join('customers as cu', 'cu.id', '=', 'o.customer_id')
                ->where('o.company_id', $f['company_id'])
                ->when($f['branch_id'], fn ($q, $b) => $q->where('o.branch_id', $b))
                ->whereBetween('o.trx_date', [$f['from'], $f['to']])
                ->whereNull('o.deleted_at')
                ->where('o.status', DocumentStatus::CONFIRMED)
                ->whereRaw("ol.ordered_qty > {$delivered}")
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
                    DB::raw("ol.ordered_qty - {$delivered} as pending_qty"),
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
                ['key' => 'ordered_qty', 'label' => 'sales::field.ordered', 'type' => ReportColumn::MONEY],
                ['key' => 'delivered_qty', 'label' => 'sales::field.delivered', 'type' => ReportColumn::MONEY],
                ['key' => 'pending_qty', 'label' => 'sales::field.pending', 'type' => ReportColumn::MONEY],
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
            title: 'sales::menu.undelivered',
            filters: ['date_range', 'branch'],
            query: fn (array $f) => DB::table('sal_challan_lines as cl')
                ->join('sal_challans as c', 'c.id', '=', 'cl.delivery_challan_id')
                ->join('inv_products as p', 'p.id', '=', 'cl.product_id')
                ->join('customers as cu', 'cu.id', '=', 'c.customer_id')
                ->where('c.company_id', $f['company_id'])
                ->when($f['branch_id'], fn ($q, $b) => $q->where('c.branch_id', $b))
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
                ['key' => 'delivered_qty', 'label' => 'sales::field.delivered', 'type' => ReportColumn::MONEY],
                ['key' => 'uninvoiced_qty', 'label' => 'sales::field.uninvoiced', 'type' => ReportColumn::MONEY],
                ['key' => 'uninvoiced_value', 'label' => 'sales::field.uninvoiced_value', 'type' => ReportColumn::MONEY],
            ],
        );
    }

    /** কার কাছে কত বেচেছি — বিলের ভিত্তিতে। */
    public static function byCustomer(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'sales.by_customer',
            title: 'sales::menu.by_customer',
            filters: ['date_range', 'branch'],
            groupBy: 'customer_id',
            query: fn (array $f) => DB::table('sal_invoices as i')
                ->join('customers as cu', 'cu.id', '=', 'i.customer_id')
                ->where('i.company_id', $f['company_id'])
                ->when($f['branch_id'], fn ($q, $b) => $q->where('i.branch_id', $b))
                ->whereBetween('i.trx_date', [$f['from'], $f['to']])
                ->whereNull('i.deleted_at')
                /*
                 * খাতায় বসা বিল — খসড়া নয়।
                 *
                 * ── আগে "বাতিল ছাড়া সব" ছিল, আর সেটা ভুল উত্তর দিত ───
                 * তাতে খসড়াও ঢুকত। কাউন্টারে ধরে রাখা একটা বিল — ক্রেতা
                 * টাকা আনতে গেছেন — এই রিপোর্টে তাঁর নামে যোগ হয়ে বসে
                 * থাকত, অথচ হোম পর্দা ওটা গুনত না। একই প্রশ্ন, দুইটা
                 * উত্তর: "এই মাসে করিম সাহেব কত কিনেছেন" ড্যাশবোর্ডে এক,
                 * রিপোর্টে আরেক। আর তার উপর মুনাফা হিসাব হত এমন বিল
                 * ধরে যেটা এখনো বিক্রয়ই নয়।
                 */
                ->whereIn('i.status', DocumentStatus::POSTED)
                ->groupBy('i.customer_id', 'cu.code', 'cu.name_en', 'cu.name_bn')
                ->orderByRaw('SUM(i.total) desc')
                ->select([
                    'i.customer_id',
                    DB::raw("'customer' as source_type_literal"),
                    self::customerName(),
                    DB::raw('COUNT(*) as invoice_count'),
                    DB::raw('SUM(i.subtotal) as subtotal'),
                    DB::raw('SUM(i.discount) as discount'),
                    DB::raw('SUM(i.tax) as tax'),
                    DB::raw('SUM(i.total) as total'),
                    // মুনাফা — বিক্রয় বাদ খরচ; খরচটা বিলের সময় জমা রাখা
                    DB::raw('SUM(i.total - i.tax - i.cost_of_goods) as gross_profit'),
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
            title: 'sales::menu.by_product',
            filters: ['date_range', 'branch'],
            groupBy: 'product_id',
            query: fn (array $f) => DB::table('sal_invoice_lines as il')
                ->join('sal_invoices as i', 'i.id', '=', 'il.sales_invoice_id')
                ->join('inv_products as p', 'p.id', '=', 'il.product_id')
                ->where('i.company_id', $f['company_id'])
                ->when($f['branch_id'], fn ($q, $b) => $q->where('i.branch_id', $b))
                ->whereBetween('i.trx_date', [$f['from'], $f['to']])
                ->whereNull('i.deleted_at')
                // খাতায় বসা বিল — byCustomer()-এর একই কারণে
                ->whereIn('i.status', DocumentStatus::POSTED)
                ->groupBy('il.product_id', 'p.code', 'p.name_en', 'p.name_bn')
                ->orderByRaw('SUM(il.amount - il.qty * il.unit_cost) desc')
                ->select([
                    'il.product_id',
                    DB::raw("'".Product::drillSourceType()."' as source_type_literal"),
                    self::productName(),
                    DB::raw('SUM(il.qty) as qty'),
                    DB::raw('SUM(il.discount) as discount'),
                    DB::raw('SUM(il.amount) as revenue'),
                    DB::raw('SUM(il.qty * il.unit_cost) as cost'),
                    DB::raw('SUM(il.amount - il.qty * il.unit_cost) as gross_profit'),

                    /*
                     * মার্জিন — টাকার অঙ্ক নয়, শতাংশ।
                     *
                     * দুইটাই লাগে: ৫০ টাকা লাভ ২০০ টাকার বিক্রিতে ভালো,
                     * ৫,০০০ টাকার বিক্রিতে নয়। কেবল টাকার অঙ্ক দেখলে বড়
                     * পণ্যগুলো সবসময় উপরে থাকত, আর যে ছোট পণ্যটা সবচেয়ে
                     * বেশি লাভ দিচ্ছে সেটা চোখেই পড়ত না।
                     *
                     * শূন্য বিক্রয়ে ভাগ করা হয় না — শূন্য বিক্রয়ে মার্জিন
                     * বলে কিছু নেই, আর ডাটাবেজ ওখানে NULL দিত।
                     */
                    DB::raw('CASE WHEN SUM(il.amount) = 0 THEN 0
                             ELSE ROUND(SUM(il.amount - il.qty * il.unit_cost) * 100
                                        / SUM(il.amount), 2) END as margin_percent'),
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
                ['key' => 'revenue', 'label' => 'sales::field.revenue', 'type' => ReportColumn::MONEY],

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

    private static function productName(): Expression
    {
        $name = app()->getLocale() === 'bn'
            ? "COALESCE(NULLIF(p.name_bn, ''), p.name_en)"
            : 'p.name_en';

        return DB::raw("CONCAT(p.code, ' - ', {$name}) as product_name");
    }

    private static function customerName(): Expression
    {
        $name = app()->getLocale() === 'bn'
            ? "COALESCE(NULLIF(cu.name_bn, ''), cu.name_en)"
            : 'cu.name_en';

        return DB::raw("{$name} as customer_name");
    }
}
