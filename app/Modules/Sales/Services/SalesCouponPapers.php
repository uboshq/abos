<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Contracts\CouponPapers;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Modules\Accounts\Models\Note;
use App\Modules\Accounts\Services\NoteService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;

/**
 * কুপনের কাগজ — বিক্রয়ের পাকা বিল আর আদেশ, সারি ধরে ([[CouponPapers]], গভীর অডিট, ২৯ সেপ্টেম্বর ২০২৬)।
 *
 * ⓘ কোম্পানির (আর শাখার) পরিধি মডেলের নিজের স্কোপ বসায় — অন্য কোম্পানির কাগজ এখানে `null`। পরিমাণ আর অঙ্ক
 * সারি থেকে: অঙ্ক = পরিমাণ × দর, ছাড়ের আগে — অফারের যোগ্যতা এই মাপেই ([[ChallanOffers]] একই মাপ নেয়)।
 */
final class SalesCouponPapers implements CouponPapers
{
    public function line(string $sourceType, int $sourceId, int $sourceLineId): ?array
    {
        return match ($sourceType) {
            'sales_invoice' => $this->invoiceLine($sourceId, $sourceLineId),
            'sales_order' => $this->orderLine($sourceId, $sourceLineId),
            default => null,
        };
    }

    /**
     * ⭐ পাকা বিলে কুপনের টাকার ছাড় — গ্রাহকের ক্রেডিট নোট, ঐ বিলের বিপরীতে (পুরো ERP অডিট ⛔১০, ৬ অক্টোবর ২০২৬)।
     *
     * ⓘ বিল আগেই খাতায়, তাই ছাড়টা পরের কাগজে: Dr দেওয়া ছাড় (৫৩০০) / Cr প্রাপ্য (গ্রাহক) — [[NoteService]]-এর পথেই, তাই
     * নোটের সই, বিলের বাকি জায়গার সীমা ([[\App\Core\Contracts\NoteTarget]]) আর বাতিল-ইনভয়েসের পাহারা সবই খাটে। ভ্যাট ০ —
     * কুপনের অঙ্কটাই ছাড়। ⓘ মাল বা পয়েন্টের কুপনে টাকার ছাড় নেই, কিছু বসে না। ⓘ আদেশে কুপন: আয় এখনো খাতায় নেই, তাই
     * এখানে কিছু নয়।
     */
    public function redeemed(string $sourceType, int $sourceId, string $kind, string $worth, string $code): void
    {
        if ($sourceType !== 'sales_invoice' || ! in_array($kind, ['percent', 'amount', 'credit'], true)
            || ! is_numeric($worth) || bccomp($worth, '0', 4) <= 0) {
            return;
        }

        // ⓘ দরজা আগেই কাগজ যাচাই করে ([[line()]]); পাকা বিল না থাকলে খাতায় বসানোর কিছু নেই
        $invoice = SalesInvoice::query()->whereIn('status', DocumentStatus::POSTED)->find($sourceId);

        if ($invoice === null) {
            return;
        }
        $discount = StandardChart::find(StandardChart::DISCOUNT_GIVEN);
        $notes = app(NoteService::class);

        /*
         * ⛔ বিলের নিজের শাখায় — পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬ (বিক্রয় ২; [[ACouponNoteLandsInTheBillsBranchTest]])। ⓘ নোট শাখা নেয়
         * প্রসঙ্গ থেকে ([[NoteService::create()]]); কুপন কাটা মানুষের হেডারে অন্য শাখা থাকলে ছাড়টা সেই শাখার খাতায় বসত, আর বিলের
         * শাখায় পাওনা কমত না।
         */
        CompanyContext::inBranch($invoice->branch_id === null ? null : (int) $invoice->branch_id, function () use ($notes, $invoice, $discount, $worth, $code): void {
            $note = $notes->create([
                'direction' => Note::CREDIT,
                'party_kind' => Note::KIND_CUSTOMER,
                'party_id' => (int) $invoice->customer_id,
                'other_account_id' => $discount?->id,
                'trx_date' => now()->toDateString(),
                'amount' => bcadd($worth, '0', 4),
                'tax_amount' => '0',
                'reason' => 'agreed_discount',
                'against_no' => (string) $invoice->document_no,
                'narration' => __('sales::message.coupon_note', ['code' => $code]),
            ]);

            $notes->confirm($note);
        });
    }

    /** @return array{customer_id: ?int, branch_id: ?int, warehouse_id: ?int, product_id: int, qty: string, value: string}|null */
    private function invoiceLine(int $invoiceId, int $lineId): ?array
    {
        $invoice = SalesInvoice::query()->where('status', DocumentStatus::CONFIRMED)->find($invoiceId);
        $line = $invoice?->lines()->whereKey($lineId)->first();

        return $line === null ? null : $this->shape($invoice, (int) $line->product_id, (string) $line->qty, (string) $line->rate);
    }

    /** @return array{customer_id: ?int, branch_id: ?int, warehouse_id: ?int, product_id: int, qty: string, value: string}|null */
    private function orderLine(int $orderId, int $lineId): ?array
    {
        $order = SalesOrder::query()->where('status', DocumentStatus::CONFIRMED)->find($orderId);
        $line = $order?->lines()->whereKey($lineId)->first();

        return $line === null ? null : $this->shape($order, (int) $line->product_id, (string) $line->ordered_qty, (string) $line->rate);
    }

    /** @return array{customer_id: ?int, branch_id: ?int, warehouse_id: ?int, product_id: int, qty: string, value: string} */
    private function shape(SalesInvoice|SalesOrder $paper, int $productId, string $qty, string $rate): array
    {
        return [
            'customer_id' => $paper->customer_id !== null ? (int) $paper->customer_id : null,
            'branch_id' => $paper->branch_id !== null ? (int) $paper->branch_id : null,
            'warehouse_id' => $paper->warehouse_id !== null ? (int) $paper->warehouse_id : null,
            'product_id' => $productId,
            'qty' => bcadd($qty, '0', 4),
            'value' => bcmul($qty, $rate, 4),
        ];
    }
}
