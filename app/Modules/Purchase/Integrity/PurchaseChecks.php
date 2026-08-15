<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Integrity;

use App\Core\Contracts\ChecksItsOwnBooks;
use App\Core\Integrity\IntegrityCheck;
use App\Core\Integrity\IntegrityFinding;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Modules\Purchase\Models\PurchaseBill;
use Illuminate\Support\Facades\DB;

/**
 * ক্রয়ের কাগজ নিজের সাথে মেলে কি না।
 *
 * বিক্রয়ের দিকের একই দুইটা প্রশ্ন, আর একই কারণে: মোটটা জমানো থাকে,
 * প্রতিবার নতুন করে গোনা হয় না — তাই বাসি হওয়ার সুযোগ আছে। এদিকে
 * ভুলটা আরও দামি, কারণ সরবরাহকারীকে বেশি টাকা দিয়ে ফেলা টাকা ফেরত
 * চাওয়ার চেয়ে সহজ।
 */
final class PurchaseChecks implements ChecksItsOwnBooks
{
    /** @return list<IntegrityCheck> */
    public static function checks(): array
    {
        return [
            self::billMatchesItsLines(),
            self::confirmedBillsReachedTheLedger(),
        ];
    }

    /** বিলের মোট = লাইনগুলোর যোগফল। */
    public static function billMatchesItsLines(): IntegrityCheck
    {
        return new IntegrityCheck(
            key: 'purchase.bill_matches_its_lines',
            label: __('purchase::integrity.bill_total'),
            question: __('purchase::integrity.bill_total_q'),
            whenBroken: __('purchase::integrity.bill_total_broken'),
            permission: 'purchase.bill.view',
            run: function (): array {
                $lines = DB::table('pur_bill_lines')
                    ->selectRaw('purchase_bill_id, COALESCE(SUM(amount), 0) as line_total')
                    ->groupBy('purchase_bill_id');

                $rows = DB::table('pur_bills as b')
                    ->joinSub($lines, 'l', 'l.purchase_bill_id', '=', 'b.id')
                    ->where('b.company_id', CompanyContext::id())
                    ->whereNull('b.deleted_at')
                    ->where('b.status', '<>', DocumentStatus::CANCELLED)
                    ->whereRaw('ABS(b.total - l.line_total) > 0.0001')
                    ->limit(100)
                    ->select(['b.id', 'b.document_no', 'b.total', 'l.line_total'])
                    ->get();

                $out = [];

                foreach ($rows as $row) {
                    $out[] = new IntegrityFinding(
                        what: $row->document_no,
                        detail: __('purchase::integrity.total_detail', [
                            'stored' => Money::format((string) $row->total, 2),
                            'lines' => Money::format((string) $row->line_total, 2),
                        ]),
                        sourceType: PurchaseBill::drillSourceType(),
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
     * দাখিলা ছাড়া একটা নিশ্চিত ক্রয় বিল মানে সরবরাহকারীর প্রদেয় কম
     * দেখাচ্ছে — অর্থাৎ কোম্পানি নিজেকে যতটা ঋণী ভাবছে, বাস্তবে তার
     * চেয়ে বেশি ঋণী। মাস শেষে হিসাব মেলানোর সময় সরবরাহকারীর কাগজ আর
     * আমাদের কাগজ আলাদা কথা বলে।
     */
    public static function confirmedBillsReachedTheLedger(): IntegrityCheck
    {
        return new IntegrityCheck(
            key: 'purchase.confirmed_bills_are_posted',
            label: __('purchase::integrity.unposted'),
            question: __('purchase::integrity.unposted_q'),
            whenBroken: __('purchase::integrity.unposted_broken'),
            permission: 'purchase.bill.view',
            run: function (): array {
                $rows = DB::table('pur_bills as b')
                    ->where('b.company_id', CompanyContext::id())
                    ->whereNull('b.deleted_at')
                    ->whereIn('b.status', DocumentStatus::POSTED)
                    ->whereNotExists(fn ($q) => $q
                        ->select(DB::raw(1))
                        ->from('ledger_entries as le')
                        ->whereColumn('le.source_id', 'b.id')
                        ->where('le.source_type', PurchaseBill::drillSourceType()))
                    ->limit(100)
                    ->select(['b.id', 'b.document_no', 'b.total'])
                    ->get();

                $out = [];

                foreach ($rows as $row) {
                    $out[] = new IntegrityFinding(
                        what: $row->document_no,
                        detail: __('purchase::integrity.unposted_detail', [
                            'amount' => Money::format((string) $row->total, 2),
                        ]),
                        sourceType: PurchaseBill::drillSourceType(),
                        sourceId: (int) $row->id,
                    );
                }

                return $out;
            },
        );
    }
}
