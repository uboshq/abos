<?php

declare(strict_types=1);

namespace App\Modules\Sales\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\DealerScope;
use App\Core\Support\DocumentStatus;
use App\Modules\Sales\Services\DealerOwnership;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ বিক্রয়কর্মী ধরে বিক্রি — বিক্রয় পরিকল্পনা সংস্করণ ২ §৯ (গ), ৬ অক্টোবর ২০২৬ (মালিকের আদেশ "Sales মডিউলের কাজ শেষ দাও",
 * সমন্বয়কের মারফত)।
 *
 * ── ⭐ কার বিক্রি — লক্ষ্যের হুবহু একই নিয়ম ─────────────────────────────────────
 * ডিলারের দেয়াল চালু থাকলে **বিলের দিনে ডিলারটা যাঁর নামে বাঁধা** ([[DealerOwnership::boundOn()]]); বন্ধ থাকলে বিল যিনি
 * কেটেছেন — [[SalesTargetService::achievedByUser()]] যেভাবে গোনে। ⓘ তাই "বিক্রি" ঘরটা লক্ষ্যের স্কোরবোর্ডের অর্জনের হুবহু
 * সমান; দুই পর্দা কখনো আলাদা কথা বলে না। বাঁধনহীন ডিলারের বিক্রি একটা আলাদা সারিতে ("কারও বাঁধা নয়") — লুকানো নয়, কারণ
 * ঠিক ওগুলোই বাঁধতে হবে।
 *
 * ── অঙ্ক ──────────────────────────────────────────────────────────────────────
 * খাতায় বসা বিলের লাইন, ভ্যাট বাদ (ভ্যাট সরকারের টাকা, কারও বিক্রি নয়)। ফেরত একই নিয়মে কার ঘরে: দেয়াল চালু থাকলে ফেরতের
 * দিনে বাঁধা জন; বন্ধ থাকলে আসল বিল যিনি কেটেছিলেন (বিল না থাকলে ফেরত যিনি বসিয়েছেন)। নিট = বিক্রি − ফেরত।
 */
final class SalespersonReports
{
    public const KEY = 'sales.by_salesperson';

    public static function registerAll(ReportEngine $engine): void
    {
        $engine->register(self::bySalesperson());
    }

    private static function bySalesperson(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::KEY,
            // ⓘ বিক্রয়ের বাকি রিপোর্টগুলোর একই চাবি — ওয়েবের দরজা যেটা দেখে
            permission: 'sales.report',
            title: 'sales::salesperson_report.title',
            filters: ['date_range', 'branch'],
            rankBy: 'net_sales',
            query: function (array $f): Builder {
                $bound = app(DealerScope::class)->switchOn();

                $sales = DB::table('sal_invoices as i')
                    ->join('sal_invoice_lines as il', 'il.sales_invoice_id', '=', 'i.id')
                    ->where('i.company_id', $f['company_id'])
                    ->whereNull('i.deleted_at')
                    ->whereIn('i.status', DocumentStatus::POSTED)
                    ->whereBetween('i.trx_date', [$f['from'], $f['to']])
                    ->tap(ReportEngine::branchWall($f, 'i.branch_id'))
                    ->tap(ReportEngine::dealerWall($f, 'i.customer_id'))
                    ->when(
                        $bound,
                        fn ($q) => $q->selectSub(DealerOwnership::boundOn('i.customer_id', 'i.trx_date', 'i.company_id'), 'seller_id'),
                        fn ($q) => $q->selectRaw('i.created_by as seller_id'),
                    )
                    ->selectRaw('i.customer_id as customer_id, i.id as invoice_id, il.amount - il.tax as sold, 0 as returned');

                $returns = DB::table('sal_returns as r')
                    ->join('sal_return_lines as rl', 'rl.sales_return_id', '=', 'r.id')
                    ->leftJoin('sal_invoices as ri', 'ri.id', '=', 'r.sales_invoice_id')
                    ->where('r.company_id', $f['company_id'])
                    ->whereNull('r.deleted_at')
                    ->whereIn('r.status', DocumentStatus::POSTED)
                    ->whereBetween('r.trx_date', [$f['from'], $f['to']])
                    ->tap(ReportEngine::branchWall($f, 'r.branch_id'))
                    ->tap(ReportEngine::dealerWall($f, 'r.customer_id'))
                    ->when(
                        $bound,
                        fn ($q) => $q->selectSub(DealerOwnership::boundOn('r.customer_id', 'r.trx_date', 'r.company_id'), 'seller_id'),
                        fn ($q) => $q->selectRaw('COALESCE(ri.created_by, r.created_by) as seller_id'),
                    )
                    ->selectRaw('r.customer_id as customer_id, NULL as invoice_id, 0 as sold, rl.amount - rl.tax as returned');

                $nobody = DB::getPdo()->quote((string) __('sales::salesperson_report.nobody'));

                // ⓘ ভিতরে সারি-প্রতি বিক্রেতা, বাইরে যোগ — ONLY_FULL_GROUP_BY-তে নিরাপদ
                return DB::query()->fromSub($sales->unionAll($returns), 'x')
                    ->leftJoin('users as u', 'u.id', '=', 'x.seller_id')
                    ->groupBy('x.seller_id', 'u.name')
                    ->selectRaw("x.seller_id as seller_id, COALESCE(u.name, {$nobody}) as salesperson, "
                        .'COUNT(DISTINCT x.customer_id) as dealer_count, COUNT(DISTINCT x.invoice_id) as invoice_count, '
                        .'SUM(x.sold) as sales, SUM(x.returned) as returns, SUM(x.sold) - SUM(x.returned) as net_sales')
                    ->orderByRaw('SUM(x.sold) - SUM(x.returned) DESC')
                    ->orderBy('x.seller_id');
            },
            columns: [
                ['key' => 'salesperson', 'label' => 'sales::salesperson_report.salesperson'],
                ['key' => 'dealer_count', 'label' => 'sales::salesperson_report.dealers', 'type' => ReportColumn::QUANTITY, 'total' => false],
                ['key' => 'invoice_count', 'label' => 'sales::salesperson_report.invoices', 'type' => ReportColumn::QUANTITY],
                ['key' => 'sales', 'label' => 'sales::salesperson_report.sales', 'type' => ReportColumn::MONEY],
                ['key' => 'returns', 'label' => 'sales::salesperson_report.returns', 'type' => ReportColumn::MONEY],
                ['key' => 'net_sales', 'label' => 'sales::salesperson_report.net_sales', 'type' => ReportColumn::MONEY],
            ],
        );
    }
}
