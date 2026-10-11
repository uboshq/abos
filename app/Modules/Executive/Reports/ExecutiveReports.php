<?php

declare(strict_types=1);

namespace App\Modules\Executive\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\DocumentStatus;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;

/**
 * মালিকের কেন্দ্রের রিপোর্ট — কেন্দ্রীয় [[ReportEngine]]-এর উপরে, দ্বিতীয় ইঞ্জিন নয়।
 */
final class ExecutiveReports
{
    public const PROFIT_BY_CUSTOMER = 'executive.profit_by_customer';

    /** ⓘ রিপোর্টের দরজার চাবি — [[ExecutiveReportController]] ঠিক এটাই চায় */
    public const PERMISSION = 'executive.view';

    /** খরচ দেখার চাবি — বিক্রয়ের মার্জিনের একই চাবি ([[MarginGuard::COST_KEY]]) */
    public const COST_KEY = 'sales.cost.view';

    public static function registerAll(ReportEngine $engine): void
    {
        $engine->register(self::profitByCustomer());
    }

    /**
     * ক্রেতা ধরে লাভ — প্রতিটা বিলের সারির **আসল** FIFO খরচ, আন্দাজ নয়।
     *
     * ── ⓘ খরচ কোথা থেকে ───────────────────────────────────────────────────
     * বিল পাকা হওয়ার মুহূর্তে প্রতিটা সারি তার খরচ FIFO স্তর থেকে টানে আর সারিতেই লিখে রাখে
     * (`sal_invoice_lines.unit_cost`, [[SalesInvoiceService::takeCostFromLayers()]]); কোন স্তর থেকে কত টানা
     * হলো তার খাতা `inv_cost_layer_uses`। ⭐ এখানে সেই লেখা খরচই যোগ হয় — মার্জিন রিপোর্ট আর পণ্য ধরে
     * বিক্রির রিপোর্ট যেটা পড়ে, ঠিক সেটা।
     *
     * ── ⚠️ ফেরত বাদ যায় ──────────────────────────────────────────────────
     * ফেরতের মাল ফেরতের নিজের লেখা খরচে স্তরে ফেরে (`sal_returns.cost_of_goods`)। বাদ না দিলে যে ক্রেতা
     * অর্ধেক মাল ফেরত দেন তিনি খাতার চেয়ে দ্বিগুণ লাভের দেখাতেন।
     *
     * ⓘ লোকসানি ক্রেতা আগে — মালিকের প্রশ্নটাই "কার কাছে বেচে লোকসান"।
     */
    public static function profitByCustomer(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::PROFIT_BY_CUSTOMER,
            permission: self::PERMISSION,
            title: 'executive::analysis.profit_by_customer',
            filters: ['date_range', 'branch'],
            rankBy: 'net_sales',
            query: function (array $f) {
                // ⓘ খাতায় বসা বিল — খসড়ার খরচ এখনো টানাই হয়নি
                $sold = DB::table('sal_invoice_lines as il')
                    ->join('sal_invoices as i', 'i.id', '=', 'il.sales_invoice_id')
                    ->where('i.company_id', $f['company_id'])
                    ->tap(ReportEngine::branchWall($f, 'i.branch_id'))
                    ->tap(ReportEngine::dealerWall($f, 'i.customer_id'))
                    ->whereBetween('i.trx_date', [$f['from'], $f['to']])
                    ->whereNull('i.deleted_at')
                    ->whereIn('i.status', DocumentStatus::POSTED)
                    ->groupBy('i.customer_id')
                    ->select('i.customer_id')
                    ->selectRaw('COUNT(DISTINCT i.id) as bills')
                    // ⓘ বিক্রয় = `amount − tax` — মার্জিন রিপোর্টের হুবহু সংজ্ঞা: ভ্যাট সরকারের টাকা
                    ->selectRaw('SUM(il.amount - il.tax) as revenue')
                    ->selectRaw('SUM(il.qty * il.unit_cost) as cost');

                $returned = DB::table('sal_returns as r')
                    ->where('r.company_id', $f['company_id'])
                    ->tap(ReportEngine::branchWall($f, 'r.branch_id'))
                    ->tap(ReportEngine::dealerWall($f, 'r.customer_id'))
                    ->whereBetween('r.trx_date', [$f['from'], $f['to']])
                    ->whereNull('r.deleted_at')
                    ->whereIn('r.status', DocumentStatus::POSTED)
                    ->groupBy('r.customer_id')
                    ->select('r.customer_id')
                    ->selectRaw('SUM(r.total - r.tax) as returned')
                    ->selectRaw('SUM(r.cost_of_goods) as returned_cost');

                $net = '(COALESCE(s.revenue, 0) - COALESCE(x.returned, 0))';
                $cost = '(COALESCE(s.cost, 0) - COALESCE(x.returned_cost, 0))';

                return DB::table('customers as cu')
                    ->leftJoinSub($sold, 's', 's.customer_id', '=', 'cu.id')
                    ->leftJoinSub($returned, 'x', 'x.customer_id', '=', 'cu.id')
                    ->where('cu.company_id', $f['company_id'])
                    ->where(fn ($q) => $q->whereNotNull('s.customer_id')->orWhereNotNull('x.customer_id'))
                    ->orderByRaw("{$net} - {$cost} asc")
                    ->orderBy('cu.code')
                    ->select([
                        'cu.id as customer_id',
                        DB::raw("'customer' as source_type_literal"),
                        self::customerName(),
                        DB::raw('COALESCE(s.bills, 0) as bills'),
                        DB::raw('COALESCE(s.revenue, 0) as revenue'),
                        DB::raw('COALESCE(x.returned, 0) as returned'),
                        DB::raw("{$net} as net_sales"),
                        DB::raw("{$cost} as cost"),
                        DB::raw("{$net} - {$cost} as gross_profit"),
                        DB::raw("CASE WHEN {$net} <= 0 THEN NULL ELSE ROUND(({$net} - {$cost}) * 100 / {$net}, 2) END as margin_percent"),
                    ]);
            },
            columns: [
                [
                    'key' => 'customer_name',
                    'label' => 'executive::analysis.col_customer',
                    'type' => ReportColumn::DOCUMENT,
                    'source_type' => 'source_type_literal',
                    'source_id' => 'customer_id',
                ],
                ['key' => 'bills', 'label' => 'executive::analysis.col_bills', 'total' => false],
                ['key' => 'revenue', 'label' => 'executive::analysis.col_revenue', 'type' => ReportColumn::MONEY],
                ['key' => 'returned', 'label' => 'executive::analysis.col_returned', 'type' => ReportColumn::MONEY],
                ['key' => 'net_sales', 'label' => 'executive::analysis.col_net_sales', 'type' => ReportColumn::MONEY],

                /*
                 * ⛔ খরচ, লাভ আর শতাংশ — তিনটাই খরচের চাবির পেছনে (মার্জিন রিপোর্টের একই নিয়ম):
                 * বিক্রয় আর লাভ জানা থাকলে খরচ এক বিয়োগেই বেরোয়।
                 */
                ['key' => 'cost', 'label' => 'executive::analysis.col_cost', 'type' => ReportColumn::MONEY, 'permission' => self::COST_KEY],
                ['key' => 'gross_profit', 'label' => 'executive::analysis.col_profit', 'type' => ReportColumn::MONEY, 'permission' => self::COST_KEY],
                ['key' => 'margin_percent', 'label' => 'executive::analysis.col_margin', 'type' => ReportColumn::PERCENT,
                    'total' => false, 'permission' => self::COST_KEY],
            ],
        );
    }

    private static function customerName(): Expression
    {
        $name = app()->getLocale() === 'bn'
            ? "COALESCE(NULLIF(cu.name_bn, ''), cu.name_en)"
            : 'cu.name_en';

        return DB::raw("{$name} as customer_name");
    }
}
