<?php

declare(strict_types=1);

namespace App\Modules\Sales\Reports;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * বিলের সারির নিট বিক্রি — সারির অঙ্ক − ভ্যাট, তার উপর বিলের মাথার ছাড় আর গোল-সংশোধনের ভাগ।
 *
 * ⛔ ১৩ অক্টোবর ২০২৬, Sales লাইন-ধরা নিরীক্ষা ([[ABillDiscountReachesEveryLineSummedSaleTest]])। ⓘ বিলের ছাড় (`bill_discount`) আর
 * রাউন্ডিং কেবল বিলের মাথায় বসে, সারিতে নয়; সারি যোগ করা রিপোর্ট (পণ্য, ব্র্যান্ড, মার্জিন, টার্গেট, রুট) তাই ৫০০ ছাড়ের
 * ১০,০০০ টাকার বিলকে ১০,০০০ দেখাত, অথচ খাতায় বিক্রি ৯,৫০০। ⭐ এখন ভাগটা ফেরত যেভাবে করে ঠিক সেভাবে
 * ([[SalesReturnService::shareOfTheBill()]]): ভ্যাটের আগের দাম (পরিমাণ × দর − সারির ছাড়) ধরে — সারিগুলো যোগ করলে হুবহু
 * বিলের মোট − ভ্যাট − ভাড়া, মানে খাতার বিক্রি ([[SalesInvoiceService::postToLedger()]])।
 *
 * ⓘ ভাগের ভিত বিল-প্রতি একটা উপ-কোয়েরি (`ib`), কোম্পানি আর তারিখে বাঁধা — যোগফলের ভিতরে কেবল `ib.basis` পড়া হয়, তাই
 * ONLY_FULL_GROUP_BY-তে নিরাপদ।
 */
final class LineNetSale
{
    /**
     * বিল-প্রতি ভাগের ভিত `ib` জোড়া — `$invoice` বিলের টেবিলের নাম (যেমন `i`)।
     */
    public static function joinBasis(Builder $q, int $companyId, string $from, string $to, string $invoice = 'i'): Builder
    {
        return $q->leftJoinSub(
            DB::table('sal_invoice_lines as bl')
                ->join('sal_invoices as bi', 'bi.id', '=', 'bl.sales_invoice_id')
                ->where('bi.company_id', $companyId)
                ->whereBetween('bi.trx_date', [$from, $to])
                ->groupBy('bl.sales_invoice_id')
                ->selectRaw('bl.sales_invoice_id, SUM(bl.qty * bl.rate - bl.discount) as basis'),
            'ib',
            'ib.sales_invoice_id',
            '=',
            "{$invoice}.id",
        );
    }

    /**
     * সারির নিট বিক্রি — SQL অংশ, [[joinBasis()]]-এর পরে। ⓘ ভিত শূন্য হলে ভাগ নেই (ফেরতের নিয়মের মতো)।
     */
    public static function sql(string $line = 'il', string $invoice = 'i'): string
    {
        return "(({$line}.amount - {$line}.tax)"
            ." + ({$invoice}.rounding_amount - {$invoice}.bill_discount)"
            ." * COALESCE(({$line}.qty * {$line}.rate - {$line}.discount) / NULLIF(ib.basis, 0), 0))";
    }
}
