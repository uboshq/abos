<?php

declare(strict_types=1);

namespace App\Modules\Sales\Integrity;

use App\Core\Contracts\ChecksItsOwnBooks;
use App\Core\Integrity\IntegrityCheck;
use App\Core\Integrity\IntegrityFinding;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Support\Facades\DB;

/**
 * বিক্রয়ের কাগজ নিজের সাথে মেলে কি না।
 *
 * ── কেন মোটটা আলাদা করে রাখা হয়, আর কেন সেটাই ঝুঁকি ─────────────────
 * বিলের `total` ঘরটা লাইনের যোগফল থেকে হিসাব করে বসানো হয়, প্রতিবার
 * নতুন করে গোনা হয় না — নাহলে প্রতিটা তালিকার পাতায় প্রতিটা বিলের সব
 * লাইন টানতে হত। কিন্তু জমানো মানেই বাসি হওয়ার সুযোগ: কেউ SQL দিয়ে
 * একটা লাইন বদলালে, বা কোনো বাগ লাইন লিখে মোট আপডেট না করলে, দুইটা
 * সংখ্যা আলাদা হয়ে যায়।
 *
 * তখন গ্রাহক এক অঙ্ক দেখেন কাগজে, আরেক অঙ্ক ওঠে খাতায় — আর তর্কটা
 * বাধে টাকা চাইতে গিয়ে।
 */
final class SalesChecks implements ChecksItsOwnBooks
{
    /** @return list<IntegrityCheck> */
    public static function checks(): array
    {
        return [
            self::invoiceMatchesItsLines(),
            self::confirmedInvoicesReachedTheLedger(),
        ];
    }

    /**
     * বিলের মোট = লাইনগুলোর `amount`-এর যোগফল।
     *
     * লাইনের `amount` নিজেই (পরিমাণ × দর) − ছাড় + ভ্যাট, আর বিলের
     * `total` তাদের যোগফল ধরে বসানো হয়। এই দুইটা আলাদা হলে কাগজে এক
     * অঙ্ক আর খাতায় আরেক অঙ্ক।
     */
    public static function invoiceMatchesItsLines(): IntegrityCheck
    {
        return new IntegrityCheck(
            key: 'sales.invoice_matches_its_lines',
            label: __('sales::integrity.invoice_total'),
            question: __('sales::integrity.invoice_total_q'),
            whenBroken: __('sales::integrity.invoice_total_broken'),
            permission: 'sales.invoice.view',
            run: function (): array {
                $lines = DB::table('sal_invoice_lines')
                    ->selectRaw('sales_invoice_id, COALESCE(SUM(amount), 0) as line_total')
                    ->groupBy('sales_invoice_id');

                $rows = DB::table('sal_invoices as i')
                    ->joinSub($lines, 'l', 'l.sales_invoice_id', '=', 'i.id')
                    ->where('i.company_id', CompanyContext::id())
                    ->whereNull('i.deleted_at')
                    ->where('i.status', '<>', DocumentStatus::CANCELLED)
                    ->whereRaw('ABS(i.total - l.line_total) > 0.0001')
                    ->limit(100)
                    ->select(['i.id', 'i.document_no', 'i.total', 'l.line_total'])
                    ->get();

                $out = [];

                foreach ($rows as $row) {
                    $out[] = new IntegrityFinding(
                        what: $row->document_no,
                        detail: __('sales::integrity.total_detail', [
                            'stored' => Money::format((string) $row->total, 2),
                            'lines' => Money::format((string) $row->line_total, 2),
                        ]),
                        sourceType: SalesInvoice::drillSourceType(),
                        sourceId: (int) $row->id,
                    );
                }

                return $out;
            },
        );
    }

    /**
     * নিশ্চিত করা প্রতিটা বিল খাতায় পৌঁছেছে।
     *
     * ── কেন এটা সবচেয়ে নীরব ভুল ─────────────────────────────────────
     * নিশ্চিত একটা বিল যার কোনো দাখিলা নেই — মাল বেরিয়ে গেছে, কাগজ
     * ছাপা হয়েছে, গ্রাহক টাকা দেবেন, অথচ আয় ও প্রাপ্য কোথাও বসেনি।
     * বিক্রয়ের তালিকায় বিলটা ঠিকঠাক দেখায়, রেওয়ামিলও মেলে (কারণ
     * কিছুই তো বসেনি), শুধু টাকাটা কোনো হিসাবে নেই।
     */
    public static function confirmedInvoicesReachedTheLedger(): IntegrityCheck
    {
        return new IntegrityCheck(
            key: 'sales.confirmed_invoices_are_posted',
            label: __('sales::integrity.unposted'),
            question: __('sales::integrity.unposted_q'),
            whenBroken: __('sales::integrity.unposted_broken'),
            permission: 'sales.invoice.view',
            run: function (): array {
                $rows = DB::table('sal_invoices as i')
                    ->where('i.company_id', CompanyContext::id())
                    ->whereNull('i.deleted_at')
                    ->whereIn('i.status', DocumentStatus::POSTED)
                    ->whereNotExists(fn ($q) => $q
                        ->select(DB::raw(1))
                        ->from('ledger_entries as le')
                        ->whereColumn('le.source_id', 'i.id')
                        ->where('le.source_type', SalesInvoice::drillSourceType()))
                    ->limit(100)
                    ->select(['i.id', 'i.document_no', 'i.total'])
                    ->get();

                $out = [];

                foreach ($rows as $row) {
                    $out[] = new IntegrityFinding(
                        what: $row->document_no,
                        detail: __('sales::integrity.unposted_detail', [
                            'amount' => Money::format((string) $row->total, 2),
                        ]),
                        sourceType: SalesInvoice::drillSourceType(),
                        sourceId: (int) $row->id,
                    );
                }

                return $out;
            },
        );
    }
}
