<?php

declare(strict_types=1);

namespace App\Modules\Sales\Events;

use App\Core\Events\DomainEvent;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderLine;

/**
 * ⭐ আদেশের এক লাইনের বাকিটা "আর দেওয়া হবে না" (SAP-এর reason for rejection; নকশা "DO বিক্রয় আদেশে মেশানো" §১.৪, ধাপ ৭)।
 *
 * ⓘ ধরা মাল সেবা নিজেই ছাড়ে, দুই ধারাতেই ([[SalesOrderService::rejectRemainder()]]; নতুন ধারার আদেশও নিজের মাল মজুদের খাতায়
 * একই উৎসে ধরে — abos-86, 60ac3abf)। ⚠️ তাই শ্রোতা মাল ছাড়ে না — দুইবার হত; ঘটনাটা কেবল জানানোর (নোটিশ, রিপোর্ট)।
 *
 * ⚠️ ছোটে লেনদেন পাকা হওয়ার **পরে** (`DB::afterCommit`) — থেমে যাওয়া কাজে কিছুই ছোটে না।
 */
final class SalesOrderLineRejected extends DomainEvent
{
    public static function from(SalesOrder $order, SalesOrderLine $line, string $qty, string $reason): self
    {
        return new self(
            publicId: (string) $order->public_id,
            payload: [
                'sales_order_id' => (int) $order->id,
                'sales_order_line_id' => (int) $line->id,
                'product_id' => (int) $line->product_id,
                'qty' => $qty,
                'reason' => $reason,
                'hold_mode' => (string) ($order->hold_mode ?? 'ledger'),
            ],
            companyId: (int) $order->company_id,
        );
    }
}
