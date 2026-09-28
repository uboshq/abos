<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Services;

use App\Core\Support\DocumentStatus;
use App\Modules\Purchase\Models\PurchaseBillLine;
use App\Modules\Purchase\Models\PurchaseOrderLine;
use App\Modules\Purchase\Models\PurchaseReceiptLine;

/**
 * একটা আদেশের সারির বিপরীতে কত মাল ইতিমধ্যে ঢোকানো হয়েছে — দুই পথ মিলিয়ে।
 *
 * ── ⛔ কেন এই ক্লাসটা লাগল — ২৭ সেপ্টেম্বর ২০২৬ ───────────────────
 * আদেশের মাল দুই পথে ঢোকে:
 *   ১. মাল গ্রহণ (GRN) — [[PurchaseReceiptService::confirm()]]
 *   ২. আদেশ ধরে সরাসরি বিল — [[PurchaseBillService]]-এর direct লাইন
 *
 * ⚠️ দুই পাহারাই কেবল **নিজের** পথ গুনত। ফলে ৫০-এর আদেশে GRN (অনুমোদনের
 * অপেক্ষায়) আর আদেশ-ধরা বিল দুইটাই ৫০ করে ঢোকাত: গুদামে ১০০, ১১২০-এ
 * দ্বিগুণ, সরবরাহকারীর দেনা দ্বিগুণ। মাপা:
 * `TheBillAndTheReceiptBothBroughtTheGoodsTest`।
 *
 * ⭐ তাই "কত ঢুকেছে" প্রশ্নের উত্তর এখন **একটাই জায়গায়** — দুই সেবা এটাই
 * ডাকে, আর কেউ নিজে গোনে না।
 *
 * ⓘ বাতিল কাগজ বাদ, খসড়া (অনুমোদনের অপেক্ষাসহ) গোনা হয় — খসড়াটা এখনো
 * মাল ঢোকায়নি ঠিক, কিন্তু ঢোকানোর দাবি রেখেছে; না গুনলে দুইটা খসড়া
 * পাশাপাশি পাশ হয়ে যেত আর দুইটাই পরে নিশ্চিত হত।
 */
final class OrderLineIntake
{
    /** মাল গ্রহণের পথে — বাতিল নয় এমন সব GRN (খসড়া/অপেক্ষমাণ/নিশ্চিত)। */
    public function onReceipts(PurchaseOrderLine $line, ?int $exceptReceiptId = null): string
    {
        $sum = PurchaseReceiptLine::query()
            ->where('purchase_order_line_id', $line->id)
            ->when($exceptReceiptId !== null, fn ($q) => $q->where('purchase_receipt_id', '<>', $exceptReceiptId))
            ->whereHas('receipt', fn ($q) => $q->where('status', '<>', DocumentStatus::CANCELLED))
            ->sum('received_qty');

        return bcadd((string) ($sum ?: '0'), '0', 4);
    }

    /**
     * আদেশ ধরে সরাসরি বিলের পথে — বাতিল নয় এমন সব বিল।
     *
     * ⓘ বিলের সারিতে `purchase_order_line_id` কেবল তখনই বসে যখন চালানের
     * সারি নেই ([[PurchaseBillService]]-এর `replaceLines()`), তবু শর্তটা
     * এখানে স্পষ্ট লেখা — GRN থেকে আসা বিল মাল ঢোকায় না, তাকে গোনা যায় না।
     */
    public function onOrderBills(PurchaseOrderLine $line, ?int $exceptBillId = null): string
    {
        $sum = PurchaseBillLine::query()
            ->where('purchase_order_line_id', $line->id)
            ->whereNull('purchase_receipt_line_id')
            ->when($exceptBillId !== null, fn ($q) => $q->where('purchase_bill_id', '<>', $exceptBillId))
            ->whereHas('bill', fn ($q) => $q->where('status', '<>', DocumentStatus::CANCELLED))
            ->sum('qty');

        return bcadd((string) ($sum ?: '0'), '0', 4);
    }

    /** দুই পথ মিলিয়ে — এই সারির বিপরীতে যা ঢুকেছে বা ঢোকার দাবি রেখেছে। */
    public function takenIn(PurchaseOrderLine $line, ?int $exceptReceiptId = null, ?int $exceptBillId = null): string
    {
        return bcadd($this->onReceipts($line, $exceptReceiptId), $this->onOrderBills($line, $exceptBillId), 4);
    }

    /**
     * বাতিল নয় এমন কোনো GRN এই সারির উপর আছে কি না — থাকলে আদেশ ধরে বিল নয়,
     * বিল হবে ঐ GRN থেকে।
     */
    public function openReceiptNo(PurchaseOrderLine $line): ?string
    {
        $receiptLine = PurchaseReceiptLine::query()
            ->with('receipt')
            ->where('purchase_order_line_id', $line->id)
            ->whereHas('receipt', fn ($q) => $q->where('status', '<>', DocumentStatus::CANCELLED))
            ->orderBy('id')
            ->first();

        return $receiptLine === null ? null : (string) $receiptLine->receipt->document_no;
    }

    /**
     * সারিগুলো তালাবদ্ধ — লেনদেনের ভিতরে ডাকতে হয়।
     *
     * ⚠️ দুই কাউন্টার একই মুহূর্তে (একটা GRN নিশ্চিত, আরেকটা আদেশ-ধরা বিল)
     * গুনলে দুইজনেই "এখনো ০" দেখে পাশ করে যেত। ⓘ একই আদেশ-সারি দুই পথেই
     * তালা নেয়, তাই দ্বিতীয়জন প্রথমজনের লেখা দেখে তবেই গোনে।
     *
     * @param  list<int>  $ids
     */
    public function lock(array $ids): void
    {
        $ids = array_values(array_unique(array_filter($ids)));

        if ($ids === []) {
            return;
        }

        PurchaseOrderLine::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get();
    }
}
