<?php

declare(strict_types=1);

namespace App\Modules\Sales\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\DocumentStatus;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\MarginGuard;
use Illuminate\Support\Facades\DB;

/**
 * মার্জিন রিপোর্ট — বিলের প্রতিটা সারি: দর, ছাড়, খরচ, মার্জিন%, সীমার নিচে কি না।
 *
 * ── ⭐ কেন সারি ধরে, পণ্য ধরে নয় ──────────────────────────────────────
 * পণ্যভিত্তিক রিপোর্ট ([[SalesReports::byProduct()]]) গড় দেখায় — একশো
 * ভালো বিক্রির মাঝে একটা খরচের নিচের বিক্রি গড়ে গলে যায়। ⓘ মালিকের
 * ৩.৮২% মার্জিনে ঠিক ঐ একটা সারিই খুঁজতে হয়: কোন বিলে, কোন গ্রাহককে,
 * কত ছাড়ে।
 *
 * ── ⓘ খরচ কোথা থেকে ───────────────────────────────────────────────────
 * সারির `unit_cost` — নিশ্চিত করার মুহূর্তে FIFO স্তর থেকে টানা **আসল**
 * খরচ ([[SalesInvoiceService::takeCostFromLayers()]])। ⭐ দেয়ালের সংখ্যাটা
 * আনুমানিক ([[MarginGuard]]); এখানে যা দেখা যায় তা খাতার সংখ্যা।
 *
 * ── ⚠️ "সীমার নিচে" আজকের সীমায় মাপা ─────────────────────────────────
 * সীমাটা সেটিং, আর পুরনো বিল তখনকার সীমায় কাটা হয়েছিল। ⓘ রিপোর্ট
 * আজকের সীমা দিয়ে মাপে — প্রশ্নটা "আজকের নিয়মে কোনগুলো খারাপ ছিল"।
 */
final class MarginReport
{
    public const KEY = 'sales.margin';

    /** রিপোর্টের দরজার চাবি — সূচি ও ফোনও এখান থেকে পড়ে। */
    public const PERMISSION = 'sales.margin.report';

    public static function registerAll(ReportEngine $engine): void
    {
        $engine->register(self::definition());
    }

    public static function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::KEY,
            permission: self::PERMISSION,
            title: 'sales::margin.report_title',
            filters: ['date_range', 'branch'],
            query: function (array $f) {
                /*
                 * ⓘ বিক্রয় = `amount − tax` — পণ্যভিত্তিক রিপোর্টের হুবহু সংজ্ঞা:
                 * ভ্যাট সরকারের টাকা, দামের ভেতরের হোক বা বাইরের।
                 */
                $net = '(il.amount - il.tax)';
                $cost = '(il.qty * il.unit_cost)';

                /*
                 * ⚠️ সীমাটা সংখ্যা হিসেবে বসে, বাইন্ডিং নয় — SELECT-এ `?` বসালে
                 * বাকি বাইন্ডিং এক ঘর সরে যায় ([[SalesReports]]-এর মাথার ভুলটা)।
                 * ⓘ bcadd দিয়ে পরিষ্কার করা: সেটিংয়ে যা-ই লেখা থাকুক, SQL-এ
                 * কেবল একটা দশমিক সংখ্যা যায়।
                 */
                $floor = self::floor();

                $yes = DB::getPdo()->quote(__('sales::margin.yes'));

                return DB::table('sal_invoice_lines as il')
                    ->join('sal_invoices as i', 'i.id', '=', 'il.sales_invoice_id')
                    ->join('inv_products as p', 'p.id', '=', 'il.product_id')
                    ->join('customers as cu', 'cu.id', '=', 'i.customer_id')
                    ->where('i.company_id', $f['company_id'])
                    ->tap(ReportEngine::branchWall($f, 'i.branch_id'))
                ->tap(ReportEngine::dealerWall($f, 'i.customer_id'))
                    ->whereBetween('i.trx_date', [$f['from'], $f['to']])
                    ->whereNull('i.deleted_at')
                    // খাতায় বসা বিল — খসড়ার খরচ এখনো টানাই হয়নি (শূন্য)
                    ->whereIn('i.status', DocumentStatus::POSTED)
                    ->orderBy('i.trx_date')
                    ->orderBy('i.document_no')
                    ->orderBy('il.line_no')
                    ->select([
                        'i.trx_date',
                        'i.document_no',
                        DB::raw("'".SalesInvoice::drillSourceType()."' as source_type_literal"),
                        'i.id as invoice_id',
                        self::name('cu', 'customer_name'),
                        self::productName(),
                        'il.qty',
                        'il.rate',
                        'il.discount',
                        DB::raw("{$net} as net"),
                        DB::raw("{$cost} as cost"),
                        DB::raw("{$net} - {$cost} as margin"),
                        DB::raw("CASE WHEN {$net} <= 0 THEN NULL
                                 ELSE ROUND(({$net} - {$cost}) * 100 / {$net}, 2) END as margin_percent"),

                        /*
                         * ⭐ দেয়ালের হুবহু নিয়ম ([[MarginGuard::isBelow()]]): ভাগ ছাড়া,
                         * ঠিক সীমায় থাকা সারি নিচে নয়, আর শূন্য বিক্রয়ে খরচ থাকলেই নিচে।
                         */
                        DB::raw("CASE
                                 WHEN {$net} <= 0 AND {$cost} > 0 THEN {$yes}
                                 WHEN {$net} > 0 AND ({$net} - {$cost}) * 100 < {$floor} * {$net} THEN {$yes}
                                 ELSE '' END as below_floor"),
                    ]);
            },
            columns: [
                ['key' => 'trx_date', 'label' => 'core.table.date', 'type' => ReportColumn::DATE],
                [
                    'key' => 'document_no',
                    'label' => 'core.table.document',
                    'type' => ReportColumn::DOCUMENT,
                    'source_type' => 'source_type_literal',
                    'source_id' => 'invoice_id',
                ],
                ['key' => 'customer_name', 'label' => 'sales::margin.col_customer'],
                ['key' => 'product_name', 'label' => 'sales::margin.col_product'],
                ['key' => 'qty', 'label' => 'sales::margin.col_qty', 'type' => ReportColumn::QUANTITY],
                ['key' => 'rate', 'label' => 'sales::margin.col_rate', 'type' => ReportColumn::MONEY, 'total' => false],
                ['key' => 'discount', 'label' => 'sales::margin.col_discount', 'type' => ReportColumn::MONEY],
                ['key' => 'net', 'label' => 'sales::margin.col_net', 'type' => ReportColumn::MONEY],

                /*
                 * ⛔ খরচ, মার্জিন, শতাংশ আর "সীমার নিচে" — চারটাই খরচের চাবির পেছনে।
                 *
                 * ⓘ বিক্রয় আর মার্জিন% জানা থাকলে খরচ এক ভাগেই বেরোয়; আর
                 * "সীমার নিচে" পতাকাটাও বলে দেয় খরচ দরের কাছাকাছি কোথায়।
                 * একটা ঢেকে অন্যটা খোলা রাখা মানে কিছুই না ঢাকা (নিয়ম ২৪)।
                 */
                ['key' => 'cost', 'label' => 'sales::margin.col_cost', 'type' => ReportColumn::MONEY,
                    'permission' => MarginGuard::COST_KEY],
                ['key' => 'margin', 'label' => 'sales::margin.col_margin', 'type' => ReportColumn::MONEY,
                    'permission' => MarginGuard::COST_KEY],
                ['key' => 'margin_percent', 'label' => 'sales::margin.col_margin_percent',
                    'type' => ReportColumn::PERCENT, 'total' => false, 'permission' => MarginGuard::COST_KEY],
                ['key' => 'below_floor', 'label' => 'sales::margin.col_below',
                    'permission' => MarginGuard::COST_KEY],
            ],
        );
    }

    /**
     * আজকের সীমা — দেয়াল যেভাবে পড়ে ঠিক সেভাবেই ([[MarginGuard::floor()]]),
     * আর তার ফল সবসময় একটা পরিষ্কার দশমিক সংখ্যা, তাই SQL-এ বসানো নিরাপদ।
     */
    private static function floor(): string
    {
        return app(MarginGuard::class)->floor();
    }

    private static function productName(): \Illuminate\Database\Query\Expression
    {
        $name = app()->getLocale() === 'bn'
            ? "COALESCE(NULLIF(p.name_bn, ''), p.name_en)"
            : 'p.name_en';

        return DB::raw("CONCAT(p.code, ' - ', {$name}) as product_name");
    }

    private static function name(string $alias, string $as): \Illuminate\Database\Query\Expression
    {
        $name = app()->getLocale() === 'bn'
            ? "COALESCE(NULLIF({$alias}.name_bn, ''), {$alias}.name_en)"
            : "{$alias}.name_en";

        return DB::raw("{$name} as {$as}");
    }
}
