<?php

declare(strict_types=1);

namespace App\Modules\Sales\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\DocumentStatus;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesReturn;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * বিক্রয়ের কাগজের খাতা — রিপোর্ট সেন্টার ধাপ ৬, বিক্রয়ের অর্ধেক (মালিক, ১ অক্টোবর ২০২৬)।
 *
 * *"কাগজের খাতা: বিক্রয় (ইনভয়েস/অর্ডার/সরাসরি/ফেরত) — অবস্থা, বয়স, …মিল"*। ক্রয়ের অর্ধেক [[PurchaseRegisterReports]]।
 *
 * ── একটা খাতায় চার রকম কাগজ ────────────────────────────────────────────
 * প্রতিটা কাগজ এক সারি: তারিখ, ধরন, নম্বর (খুললে কাগজ), ক্রেতা, অবস্থা, মোট, বয়স, আর মিল —
 *   অর্ডার    → কত শতাংশ ডেলিভারি হয়েছে (চালান), কত শতাংশের বিল হয়েছে
 *   ইনভয়েস   → ১০০% বিল (বিল নিজেই বিল)
 *   সরাসরি    → কাউন্টারের বিক্রি — ইনভয়েসই, পর্দার ছবি থাকে (`counter_screen` / `counter_draft`); ১০০% বিল
 *   ফেরত     → মিলের প্রশ্ন নেই
 * ⓘ "বয়স" কেবল খোলা কাগজের (খসড়া বা নিশ্চিত) — বন্ধ বা বাতিল কাগজ আর কারও কাজ নয়।
 * ⓘ সংখ্যা লাইন থেকে, বাতিল চালান/বিল বাদ; শাখার দেয়াল প্রতিটা অংশে আলাদা করে ([[ReportEngine::branchWall()]])।
 */
final class SalesRegisterReports
{
    public static function registerAll(ReportEngine $engine): void
    {
        $engine->register(self::register());
    }

    public static function register(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'sales.register',
            permission: 'sales.report',
            title: 'sales::register.title',
            filters: ['date_range', 'branch'],
            query: fn (array $f) => DB::query()
                ->fromSub(self::orders($f)
                    ->unionAll(self::invoices($f))
                    ->unionAll(self::returns($f)), 'papers')
                ->orderBy('trx_date')
                ->orderBy('kind_order')
                ->orderBy('document_no'),
            columns: [
                ['key' => 'trx_date', 'label' => 'core.print.date', 'type' => ReportColumn::DATE, 'width' => '7rem'],
                ['key' => 'kind', 'label' => 'sales::register.kind', 'width' => '7rem'],
                [
                    'key' => 'document_no',
                    'label' => 'core.table.document',
                    'type' => ReportColumn::DOCUMENT,
                    'source_type' => 'source_type_literal',
                    'source_id' => 'doc_id',
                ],
                ['key' => 'customer_name', 'label' => 'sales::field.customer'],
                ['key' => 'status_label', 'label' => 'sales::register.status', 'width' => '7rem'],
                ['key' => 'total', 'label' => 'sales::field.total', 'type' => ReportColumn::MONEY],
                ['key' => 'age_days', 'label' => 'sales::register.age_days', 'width' => '6rem'],
                ['key' => 'delivered_pct', 'label' => 'sales::register.delivered_pct', 'type' => ReportColumn::PERCENT],
                ['key' => 'invoiced_pct', 'label' => 'sales::register.invoiced_pct', 'type' => ReportColumn::PERCENT],
            ],
        );
    }

    /** অর্ডার — ডেলিভারি % (চালানের লাইন) আর বিল % (সেই চালান-লাইনের বিল) */
    private static function orders(array $f): Builder
    {
        $cancelled = DocumentStatus::CANCELLED;

        // ⭐ "আর দেওয়া হবে না" অংশ বাদ — বাকিটা বন্ধ করা আদেশ যা গেছে তাতেই ১০০% (নকশার ধাপ ৭)
        $ordered = '(select COALESCE(SUM(ol.ordered_qty - ol.rejected_qty), 0) from sal_order_lines ol where ol.sales_order_id = o.id)';

        $delivered = "(select COALESCE(SUM(cl.delivered_qty), 0)
                from sal_challan_lines cl
                join sal_challans c on c.id = cl.delivery_challan_id
                join sal_order_lines ol on ol.id = cl.sales_order_line_id
                where ol.sales_order_id = o.id and c.status <> '{$cancelled}')";

        $invoiced = "(select COALESCE(SUM(il.qty), 0)
                from sal_invoice_lines il
                join sal_invoices i on i.id = il.sales_invoice_id
                join sal_challan_lines cl on cl.id = il.delivery_challan_line_id
                join sal_order_lines ol on ol.id = cl.sales_order_line_id
                where ol.sales_order_id = o.id and i.status <> '{$cancelled}')";

        return self::paper($f, 'sal_orders as o', 'o', 1, DB::getPdo()->quote((string) __('sales::register.kind_order')), SalesOrder::drillSourceType())
            ->selectRaw("CASE WHEN {$ordered} > 0 THEN ROUND({$delivered} * 100 / {$ordered}, 0) END as delivered_pct")
            ->selectRaw("CASE WHEN {$ordered} > 0 THEN ROUND({$invoiced} * 100 / {$ordered}, 0) END as invoiced_pct");
    }

    /** ইনভয়েস আর সরাসরি বিক্রি — একই টেবিল; কাউন্টারের পর্দার ছবি থাকলে "সরাসরি" */
    private static function invoices(array $f): Builder
    {
        $kind = 'CASE WHEN i.counter_screen IS NOT NULL OR i.counter_draft IS NOT NULL THEN '
            .DB::getPdo()->quote((string) __('sales::register.kind_direct'))
            .' ELSE '.DB::getPdo()->quote((string) __('sales::register.kind_invoice')).' END';

        return self::paper($f, 'sal_invoices as i', 'i', 2, $kind, SalesInvoice::drillSourceType())
            ->selectRaw('NULL as delivered_pct')
            ->selectRaw('100 as invoiced_pct');
    }

    private static function returns(array $f): Builder
    {
        return self::paper($f, 'sal_returns as t', 't', 3, DB::getPdo()->quote((string) __('sales::register.kind_return')), SalesReturn::drillSourceType())
            ->selectRaw('NULL as delivered_pct')
            ->selectRaw('NULL as invoiced_pct');
    }

    /**
     * তিন অংশের একই মাথা — একই কলাম, একই ক্রমে (UNION-এর শর্ত)।
     *
     * @param  string  $kind  ধরনের SQL — উদ্ধৃত লেখা, বা CASE
     */
    private static function paper(array $f, string $table, string $a, int $order, string $kind, string $sourceType): Builder
    {
        $open = implode("', '", [DocumentStatus::DRAFT, DocumentStatus::CONFIRMED]);
        $name = app()->getLocale() === 'bn' ? "COALESCE(NULLIF(c.name_bn, ''), c.name_en)" : 'c.name_en';

        $statuses = collect([DocumentStatus::DRAFT, DocumentStatus::CONFIRMED, DocumentStatus::CLOSED, DocumentStatus::CANCELLED])
            ->map(fn (string $s) => "WHEN '{$s}' THEN ".DB::getPdo()->quote((string) __('core.status.'.$s)))
            ->implode(' ');

        return DB::table($table)
            ->join('customers as c', 'c.id', '=', "{$a}.customer_id")
            ->where("{$a}.company_id", $f['company_id'])
            ->tap(ReportEngine::branchWall($f, "{$a}.branch_id"))
            ->whereBetween("{$a}.trx_date", [$f['from'], $f['to']])
            ->whereNull("{$a}.deleted_at")
            ->selectRaw("{$a}.trx_date")
            ->selectRaw("{$order} as kind_order")
            ->selectRaw("{$kind} as kind")
            ->selectRaw("{$a}.document_no")
            ->selectRaw(DB::getPdo()->quote($sourceType).' as source_type_literal')
            ->selectRaw("{$a}.id as doc_id")
            ->selectRaw("{$name} as customer_name")
            ->selectRaw("CASE {$a}.status {$statuses} ELSE {$a}.status END as status_label")
            ->selectRaw("{$a}.total")
            // ⓘ "আজ" অ্যাপের ঘড়ি থেকে, ডেটাবেসের নয় — UTC ডেটাবেসে রাত ১২টা থেকে ভোর ৬টা বয়স এক দিন কম দেখাত (৬ অক্টোবর ২০২৬)
            ->selectRaw("CASE WHEN {$a}.status IN ('{$open}') THEN DATEDIFF(?, {$a}.trx_date) END as age_days", [\Illuminate\Support\Carbon::today()->toDateString()]);
    }
}
