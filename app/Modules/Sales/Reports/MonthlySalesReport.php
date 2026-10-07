<?php

declare(strict_types=1);

namespace App\Modules\Sales\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\DocumentStatus;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Sales\Models\Collection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * মাসওয়ারি বিক্রয় — মালিকের নির্দেশ, ১ অক্টোবর ২০২৬ (*"ekhoni lagbe"*)।
 *
 * ── এক সারি = এক মাস ─────────────────────────────────────────────────────
 *   বিল        পাকা বিলের সংখ্যা
 *   মোট বিক্রি   ছাড়ের আগে (`subtotal` — দর × পরিমাণ)
 *   ছাড়        লাইনের ছাড় + বিলের ছাড়
 *   ফেরত       পাকা ফেরতের মোট
 *   নিট বিক্রি   বিলের মোট − ফেরতের মোট
 *   আদায়       গ্রাহকের নামে টাকা আসা — আদায় আর রসিদ ভাউচার (কাউন্টারের জমাও), খাতা থেকে,
 *              উল্টে দেওয়াগুলো বাদ ([[SalesCustomerTrade::lastPayment()]]-এর একই উৎস)
 *   বাকি যোগ    নিট বিক্রি − আদায় (ঋণাত্মক মানে সেই মাসে পুরনো বাকি কমেছে)
 *   মোট লাভ     (মোট − ভ্যাট − বিক্রিত মালের খরচ) বিলে, ফেরতে উল্টো — `sales.cost.view`-এর পেছনে
 *
 * ── ⚠️ পণ্য/ব্র্যান্ড/ক্যাটাগরি বাছলে ────────────────────────────────────────
 * হিসাব লাইন থেকে, বিলের মাথা থেকে নয় — নাহলে একটা পণ্য বাছলেও গোটা বিলের অঙ্ক আসত।
 * ⓘ বিলের ছাড় তখন লাইনে ভাগ হয় না (কোন লাইনের ভাগ কত, কাগজে লেখা নেই), আর আদায় পণ্যে ভাগ হয়
 * না — তাই আদায় ও বাকি-যোগ তখন খালি। ⛔ অনুমানে ভাগ করা হয় না।
 *
 * ⓘ "সব শাখা"-য় ইঞ্জিন নিজেই শাখা ধরে পাশাপাশি ভাগ করে ([[ReportEngine::branchPlan()]])।
 */
final class MonthlySalesReport
{
    public static function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'sales.monthly',
            permission: 'sales.report',
            title: 'sales::monthly.title',
            filters: ['date_range', 'branch', 'customer_id', 'salesman_id', 'location_id', 'product_id', 'brand_id', 'category_id'],
            groupBy: 'month',
            query: function (array $f) {
                $byLine = ! empty($f['product_id']) || ! empty($f['brand_id']) || ! empty($f['category_id']);

                $rows = $byLine
                    ? self::invoiceLines($f)->unionAll(self::returnLines($f))
                    : self::invoices($f)->unionAll(self::returns($f))->unionAll(self::payments($f));

                return DB::query()->fromSub($rows, 'x')
                    ->groupBy('x.month')
                    ->orderBy('x.month')
                    ->selectRaw('x.month')
                    ->selectRaw('COUNT(DISTINCT x.invoice_id) as bills')
                    ->selectRaw('SUM(x.gross) as gross')
                    ->selectRaw('SUM(x.discount) as discount')
                    ->selectRaw('SUM(x.returned) as returned')
                    ->selectRaw('SUM(x.sold) - SUM(x.returned) as net_sales')
                    ->selectRaw($byLine ? 'NULL as collected' : 'SUM(x.collected) as collected')
                    ->selectRaw($byLine ? 'NULL as due_added' : 'SUM(x.sold) - SUM(x.returned) - SUM(x.collected) as due_added')
                    ->selectRaw('SUM(x.profit) as gross_profit');
            },
            columns: [
                ['key' => 'month', 'label' => 'sales::monthly.month', 'width' => '7rem'],
                ['key' => 'bills', 'label' => 'sales::monthly.bills'],
                ['key' => 'gross', 'label' => 'sales::monthly.gross', 'type' => ReportColumn::MONEY],
                ['key' => 'discount', 'label' => 'sales::monthly.discount', 'type' => ReportColumn::MONEY],
                ['key' => 'returned', 'label' => 'sales::monthly.returned', 'type' => ReportColumn::MONEY],
                ['key' => 'net_sales', 'label' => 'sales::monthly.net_sales', 'type' => ReportColumn::MONEY],
                ['key' => 'collected', 'label' => 'sales::monthly.collected', 'type' => ReportColumn::MONEY],
                ['key' => 'due_added', 'label' => 'sales::monthly.due_added', 'type' => ReportColumn::MONEY],
                ['key' => 'gross_profit', 'label' => 'sales::monthly.gross_profit', 'type' => ReportColumn::MONEY,
                    'permission' => 'sales.cost.view'],
            ],
        );
    }

    /** পাকা বিল, মাথা থেকে */
    private static function invoices(array $f): Builder
    {
        return self::walled(DB::table('sal_invoices as d'), $f)
            ->whereIn('d.status', DocumentStatus::POSTED)
            ->selectRaw("DATE_FORMAT(d.trx_date, '%Y-%m') as month, d.id as invoice_id")
            ->selectRaw('d.subtotal as gross, d.discount + d.bill_discount as discount')
            ->selectRaw('d.total as sold, 0 as returned, 0 as collected')
            ->selectRaw('d.total - d.tax - d.cost_of_goods as profit');
    }

    /** পাকা ফেরত, মাথা থেকে — লাভ উল্টো */
    private static function returns(array $f): Builder
    {
        return self::walled(DB::table('sal_returns as d'), $f)
            ->whereIn('d.status', DocumentStatus::POSTED)
            ->selectRaw("DATE_FORMAT(d.trx_date, '%Y-%m') as month, NULL as invoice_id")
            ->selectRaw('0 as gross, 0 as discount, 0 as sold, d.total as returned, 0 as collected')
            ->selectRaw('-(d.total - d.tax - d.cost_of_goods) as profit');
    }

    /** গ্রাহকের নামে টাকা আসা — খাতা থেকে, উল্টে দেওয়া বাদ */
    private static function payments(array $f): Builder
    {
        $sources = [Collection::drillSourceType(), Voucher::SOURCE_TYPES[Voucher::RECEIPT]];

        return DB::table('ledger_entries as le')
            ->join('customers as cu', 'cu.id', '=', 'le.party_id')
            ->where('le.company_id', $f['company_id'])
            ->tap(ReportEngine::branchWall($f, 'le.branch_id'))
                ->tap(ReportEngine::dealerWall($f, 'le.party_id'))
            ->where('le.party_type', 'customer')
            ->whereIn('le.source_type', $sources)
            ->where('le.credit', '>', 0)
            ->whereBetween('le.trx_date', [$f['from'], $f['to']])
            ->whereNotExists(fn ($q) => $q->from('ledger_entries as rev')
                ->whereColumn('rev.source_id', 'le.source_id')
                ->whereRaw("rev.source_type = CONCAT(le.source_type, ':reversal')")
                ->whereColumn('rev.id', '>', 'le.id'))
            ->tap(fn ($q) => self::whoFilters($q, $f, 'le.party_id'))
            ->selectRaw("DATE_FORMAT(le.trx_date, '%Y-%m') as month, NULL as invoice_id")
            ->selectRaw('0 as gross, 0 as discount, 0 as sold, 0 as returned, le.credit as collected, 0 as profit');
    }

    /** পণ্য ধরে — বিলের লাইন */
    private static function invoiceLines(array $f): Builder
    {
        return self::walled(DB::table('sal_invoice_lines as l')->join('sal_invoices as d', 'd.id', '=', 'l.sales_invoice_id'), $f)
            ->whereIn('d.status', DocumentStatus::POSTED)
            ->tap(fn ($q) => self::productFilters($q, $f))
            ->selectRaw("DATE_FORMAT(d.trx_date, '%Y-%m') as month, d.id as invoice_id")
            ->selectRaw('l.qty * l.rate as gross, l.discount as discount')
            ->selectRaw('l.amount as sold, 0 as returned, 0 as collected')
            ->selectRaw('l.amount - l.tax - l.qty * l.unit_cost as profit');
    }

    /** পণ্য ধরে — ফেরতের লাইন; খরচ মূল বিলের লাইনের */
    private static function returnLines(array $f): Builder
    {
        return self::walled(DB::table('sal_return_lines as l')->join('sal_returns as d', 'd.id', '=', 'l.sales_return_id'), $f)
            ->leftJoin('sal_invoice_lines as il', 'il.id', '=', 'l.sales_invoice_line_id')
            ->whereIn('d.status', DocumentStatus::POSTED)
            ->tap(fn ($q) => self::productFilters($q, $f))
            ->selectRaw("DATE_FORMAT(d.trx_date, '%Y-%m') as month, NULL as invoice_id")
            ->selectRaw('0 as gross, 0 as discount, 0 as sold, l.amount as returned, 0 as collected')
            ->selectRaw('-(l.amount - l.tax - l.qty * COALESCE(il.unit_cost, 0)) as profit');
    }

    /** কোম্পানি, শাখার দেয়াল, তারিখ, মুছে-ফেলা বাদ, আর কার বিক্রি */
    private static function walled(Builder $q, array $f): Builder
    {
        return $q->where('d.company_id', $f['company_id'])
            ->tap(ReportEngine::branchWall($f, 'd.branch_id'))
                ->tap(ReportEngine::dealerWall($f, 'd.customer_id'))
            ->whereNull('d.deleted_at')
            ->whereBetween('d.trx_date', [$f['from'], $f['to']])
            ->tap(fn ($q) => self::whoFilters($q, $f, 'd.customer_id'));
    }

    /**
     * গ্রাহক, এলাকা/পয়েন্ট, বিক্রয়কর্মী — গ্রাহকের এলাকা ধরে।
     *
     * ⓘ বিক্রয়কর্মী মানে এলাকার দায়িত্বে যিনি (`mdm_locations.assigned_to`) — "বিক্রয়কর্মী কেবল নিজের
     * ডিলার" নিয়মের একই উৎস। এলাকা তিন ধাপ পর্যন্ত ওপরে মেলানো হয় (পয়েন্ট → এরিয়া → জেলা)।
     */
    private static function whoFilters(Builder $q, array $f, string $customerColumn): void
    {
        $q->when(! empty($f['customer_id']), fn ($w) => $w->where($customerColumn, (int) $f['customer_id']));

        foreach (['location_id' => 'id', 'salesman_id' => 'assigned_to'] as $filter => $column) {
            if (empty($f[$filter])) {
                continue;
            }

            $want = (int) $f[$filter];

            $q->whereExists(fn ($e) => $e->from('customers as fc')
                ->leftJoin('mdm_locations as l1', 'l1.id', '=', 'fc.location_id')
                ->leftJoin('mdm_locations as l2', 'l2.id', '=', 'l1.parent_id')
                ->leftJoin('mdm_locations as l3', 'l3.id', '=', 'l2.parent_id')
                ->whereColumn('fc.id', $customerColumn)
                ->where(fn ($m) => $m->where("l1.{$column}", $want)
                    ->orWhere("l2.{$column}", $want)
                    ->orWhere("l3.{$column}", $want)));
        }
    }

    private static function productFilters(Builder $q, array $f): void
    {
        $q->join('inv_products as p', 'p.id', '=', 'l.product_id')
            ->when(! empty($f['product_id']), fn ($w) => $w->where('l.product_id', (int) $f['product_id']))
            ->when(! empty($f['brand_id']), fn ($w) => $w->where('p.brand_id', (int) $f['brand_id']))
            ->when(! empty($f['category_id']), fn ($w) => $w->where('p.category_id', (int) $f['category_id']));
    }
}
