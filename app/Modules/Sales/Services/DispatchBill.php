<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Support\DocumentStatus;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ মাল বের হলেই বিল — মালিকের সিদ্ধান্ত, ২৯ সেপ্টেম্বর ২০২৬: *"ডেলিভারি বের হলেই ইনভয়েজ"*।
 *
 * ── কী ছিল ───────────────────────────────────────────────────────────
 * অফিসের DO প্রবাহে চালান নিশ্চিত হত, গাড়ি বেরোত, আর বিলটা কেউ পরে হাতে বানাত — বা
 * ভুলে যেত। মাল দোকানে, অথচ খাতায় প্রাপ্য নেই।
 *
 * ── ⭐ এখন ───────────────────────────────────────────────────────────
 * রওনার মুহূর্তে, গেট পাসের সাথে একই লেনদেনে ([[DeliveryStageService::write()]]), চালানের
 * সারি থেকে বিল বানিয়ে নিশ্চিত হয় — একই বিক্রির নম্বরে ([[SaleNumber]])। ⛔ বিল না হলে
 * (যেমন বাকির দেয়াল) রওনাও হয় না, আর বার্তাটা ঠিক বিলের।
 *
 * ⓘ আগে থেকে বিল থাকলে (কাউন্টারের বিক্রি, বা কেউ হাতে বানিয়েছেন) দ্বিতীয় বিল নয় —
 * পৌঁছায়নি থেকে আবার রওনাতেও নয়।
 */
final class DispatchBill
{
    public function __construct(private readonly SalesInvoiceService $invoices) {}

    public function forDispatch(DeliveryChallan $challan): ?SalesInvoice
    {
        if ($challan->status !== DocumentStatus::CONFIRMED || $this->alreadyBilled($challan)) {
            return null;
        }

        $challan->loadMissing('lines');

        $invoice = $this->invoices->create([
            'customer_id' => $challan->customer_id,
            'warehouse_id' => $challan->warehouse_id,
            'branch_id' => $challan->branch_id,
            'trx_date' => now()->toDateString(),
            'narration' => $challan->narration,
        ], $challan->lines->map(fn ($line) => [
            'product_id' => $line->product_id,
            'delivery_challan_line_id' => $line->id,
            'qty' => (string) $line->delivered_qty,
            'rate' => (string) $line->rate,
            'discount' => $this->lineDiscount($line),
            // ⓘ প্যাকটা কেবল লেখা হিসেবে যায় — unit_id দিলে বিলে দ্বিতীয়বার ভাগ হত ([[DirectSaleService::invoiceLines()]])
            'entered_qty' => $line->entered_qty,
            'entered_unit_id' => $line->entered_unit_id,
        ])->values()->all());

        return $this->invoices->confirm($invoice);
    }

    /** বাতিল নয় এমন কোনো বিলে এই চালানের কোনো সারি আছে কি না */
    private function alreadyBilled(DeliveryChallan $challan): bool
    {
        return DB::table('sal_invoice_lines as il')
            ->join('sal_challan_lines as cl', 'cl.id', '=', 'il.delivery_challan_line_id')
            ->join('sal_invoices as i', 'i.id', '=', 'il.sales_invoice_id')
            ->where('i.company_id', $challan->company_id)
            ->where('cl.delivery_challan_id', $challan->id)
            ->where('i.status', '<>', DocumentStatus::CANCELLED)
            ->exists();
    }

    /** শতাংশ থেকে টাকা — কাউন্টারের হুবহু ([[DirectSaleService::lineDiscount()]]) */
    private function lineDiscount(object $line): string
    {
        $percent = (string) ($line->discount_percent ?? '0');

        if (bccomp($percent, '0', 4) <= 0) {
            return '0';
        }

        return bcdiv(bcmul(bcmul((string) $line->delivered_qty, (string) $line->rate, 4), $percent, 4), '100', 4);
    }
}
