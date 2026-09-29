<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Contracts\CouponPapers;
use App\Core\Support\DocumentStatus;
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
