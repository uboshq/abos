<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\DocumentStatus;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Models\PurchaseOrder;
use App\Modules\Purchase\Models\PurchaseReceipt;
use App\Modules\Purchase\Models\PurchaseReturn;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * ক্রয়ের কাগজের খাতা — রিপোর্ট সেন্টার ধাপ ৬ (মালিক, ১ অক্টোবর ২০২৬)।
 *
 * *"কাগজের খাতা: ক্রয় (আদেশ/গ্রহণ/বিল/ফেরত) — অবস্থা, বয়স, আদেশ→গ্রহণ→বিলের মিল"*।
 *
 * ── একটা খাতায় চার রকম কাগজ ────────────────────────────────────────────
 * প্রতিটা কাগজ এক সারি: তারিখ, ধরন, নম্বর (খুললে কাগজ), সরবরাহকারী, অবস্থা, মোট, বয়স, আর মিল —
 *   আদেশ   → কত শতাংশ এসেছে, কত শতাংশের বিল হয়েছে
 *   গ্রহণ   → কত শতাংশের বিল হয়েছে
 *   বিল    → ১০০% (বিল নিজেই বিল)
 *   ফেরত   → মিলের প্রশ্ন নেই
 * ⓘ "বয়স" কেবল খোলা কাগজের (খসড়া বা নিশ্চিত, বন্ধ নয়) — বন্ধ বা বাতিল কাগজ আর কারও কাজ নয়।
 *
 * ⓘ সব সংখ্যা লাইন থেকে গোনা — [[PurchaseReports::pendingOrders()]]-এর একই নিয়ম: "এসেছে" = মাল-গ্রহণ + আদেশ থেকে
 * সরাসরি বিল, বাতিল বাদ। শাখার দেয়াল প্রতিটা অংশে আলাদা করে ([[ReportEngine::branchWall()]])।
 */
final class PurchaseRegisterReports
{
    public static function registerAll(ReportEngine $engine): void
    {
        $engine->register(self::register());
    }

    public static function register(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'purchase.register',
            permission: 'purchase.report',
            title: 'purchase::register.title',
            filters: ['date_range', 'branch'],
            query: fn (array $f) => DB::query()
                ->fromSub(self::orders($f)
                    ->unionAll(self::receipts($f))
                    ->unionAll(self::bills($f))
                    ->unionAll(self::returns($f)), 'papers')
                ->orderBy('trx_date')
                ->orderBy('kind_order')
                ->orderBy('document_no'),
            columns: [
                ['key' => 'trx_date', 'label' => 'core.print.date', 'type' => ReportColumn::DATE, 'width' => '7rem'],
                ['key' => 'kind', 'label' => 'purchase::register.kind', 'width' => '7rem'],
                [
                    'key' => 'document_no',
                    'label' => 'core.table.document',
                    'type' => ReportColumn::DOCUMENT,
                    'source_type' => 'source_type_literal',
                    'source_id' => 'doc_id',
                ],
                ['key' => 'supplier_name', 'label' => 'purchase::field.supplier'],
                ['key' => 'status_label', 'label' => 'purchase::register.status', 'width' => '7rem'],
                ['key' => 'total', 'label' => 'purchase::field.total', 'type' => ReportColumn::MONEY],
                ['key' => 'age_days', 'label' => 'purchase::register.age_days', 'width' => '6rem'],
                ['key' => 'received_pct', 'label' => 'purchase::register.received_pct', 'type' => ReportColumn::PERCENT],
                ['key' => 'billed_pct', 'label' => 'purchase::register.billed_pct', 'type' => ReportColumn::PERCENT],
            ],
        );
    }

    /** আদেশ — এসেছে % আর বিল % লাইন ধরে */
    private static function orders(array $f): Builder
    {
        $cancelled = DocumentStatus::CANCELLED;

        $ordered = '(select COALESCE(SUM(ol.ordered_qty), 0) from pur_order_lines ol where ol.purchase_order_id = o.id)';

        $received = "((select COALESCE(SUM(rl.received_qty), 0)
                from pur_receipt_lines rl
                join pur_receipts r on r.id = rl.purchase_receipt_id
                join pur_order_lines ol on ol.id = rl.purchase_order_line_id
                where ol.purchase_order_id = o.id and r.status <> '{$cancelled}')
            + (select COALESCE(SUM(bl.qty), 0)
                from pur_bill_lines bl
                join pur_bills b on b.id = bl.purchase_bill_id
                join pur_order_lines ol on ol.id = bl.purchase_order_line_id
                where ol.purchase_order_id = o.id and b.status <> '{$cancelled}'))";

        // ⓘ বিল হয়েছে — আদেশ থেকে সরাসরি, অথবা আদেশের মাল-গ্রহণ থেকে
        $billed = "(select COALESCE(SUM(bl.qty), 0)
                from pur_bill_lines bl
                join pur_bills b on b.id = bl.purchase_bill_id
                left join pur_receipt_lines rl on rl.id = bl.purchase_receipt_line_id
                left join pur_order_lines olr on olr.id = rl.purchase_order_line_id
                left join pur_order_lines old on old.id = bl.purchase_order_line_id
                where (olr.purchase_order_id = o.id or old.purchase_order_id = o.id) and b.status <> '{$cancelled}')";

        return self::paper($f, 'pur_orders as o', 'o', 1, 'order', PurchaseOrder::drillSourceType())
            ->selectRaw("CASE WHEN {$ordered} > 0 THEN ROUND({$received} * 100 / {$ordered}, 0) END as received_pct")
            ->selectRaw("CASE WHEN {$ordered} > 0 THEN ROUND({$billed} * 100 / {$ordered}, 0) END as billed_pct");
    }

    /** মাল-গ্রহণ — বিল % */
    private static function receipts(array $f): Builder
    {
        $cancelled = DocumentStatus::CANCELLED;
        $got = '(select COALESCE(SUM(rl.received_qty), 0) from pur_receipt_lines rl where rl.purchase_receipt_id = r.id)';
        $billed = "(select COALESCE(SUM(bl.qty), 0)
                from pur_bill_lines bl
                join pur_bills b on b.id = bl.purchase_bill_id
                join pur_receipt_lines rl on rl.id = bl.purchase_receipt_line_id
                where rl.purchase_receipt_id = r.id and b.status <> '{$cancelled}')";

        return self::paper($f, 'pur_receipts as r', 'r', 2, 'receipt', PurchaseReceipt::drillSourceType())
            ->selectRaw('NULL as received_pct')
            ->selectRaw("CASE WHEN {$got} > 0 THEN ROUND({$billed} * 100 / {$got}, 0) END as billed_pct");
    }

    private static function bills(array $f): Builder
    {
        return self::paper($f, 'pur_bills as b', 'b', 3, 'bill', PurchaseBill::drillSourceType())
            ->selectRaw('NULL as received_pct')
            ->selectRaw('100 as billed_pct');
    }

    private static function returns(array $f): Builder
    {
        return self::paper($f, 'pur_returns as t', 't', 4, 'return', PurchaseReturn::drillSourceType())
            ->selectRaw('NULL as received_pct')
            ->selectRaw('NULL as billed_pct');
    }

    /**
     * চার অংশের একই মাথা — একই কলাম, একই ক্রমে (UNION-এর শর্ত)।
     */
    private static function paper(array $f, string $table, string $a, int $order, string $kind, string $sourceType): Builder
    {
        $open = implode("', '", [DocumentStatus::DRAFT, DocumentStatus::CONFIRMED]);
        $name = app()->getLocale() === 'bn' ? "COALESCE(NULLIF(s.name_bn, ''), s.name_en)" : 's.name_en';

        $statuses = collect([DocumentStatus::DRAFT, DocumentStatus::CONFIRMED, DocumentStatus::CLOSED, DocumentStatus::CANCELLED])
            ->map(fn (string $s) => "WHEN '{$s}' THEN ".DB::getPdo()->quote((string) __('core.status.'.$s)))
            ->implode(' ');

        return DB::table($table)
            ->join('suppliers as s', 's.id', '=', "{$a}.supplier_id")
            ->where("{$a}.company_id", $f['company_id'])
            ->tap(ReportEngine::branchWall($f, "{$a}.branch_id"))
            ->whereBetween("{$a}.trx_date", [$f['from'], $f['to']])
            ->whereNull("{$a}.deleted_at")
            ->selectRaw("{$a}.trx_date")
            ->selectRaw("{$order} as kind_order")
            ->selectRaw(DB::getPdo()->quote((string) __('purchase::register.kind_'.$kind)).' as kind')
            ->selectRaw("{$a}.document_no")
            ->selectRaw(DB::getPdo()->quote($sourceType).' as source_type_literal')
            ->selectRaw("{$a}.id as doc_id")
            ->selectRaw("{$name} as supplier_name")
            ->selectRaw("CASE {$a}.status {$statuses} ELSE {$a}.status END as status_label")
            ->selectRaw("{$a}.total")
            // ⓘ "আজ" অ্যাপের ঘড়ি থেকে, ডেটাবেসের নয় — UTC ডেটাবেসে রাত ১২টা থেকে ভোর ৬টা বয়স এক দিন কম দেখাত (৬ অক্টোবর ২০২৬)
            ->selectRaw("CASE WHEN {$a}.status IN ('{$open}') THEN DATEDIFF(?, {$a}.trx_date) END as age_days", [\Illuminate\Support\Carbon::today()->toDateString()]);
    }
}
