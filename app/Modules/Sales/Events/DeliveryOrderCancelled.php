<?php

declare(strict_types=1);

namespace App\Modules\Sales\Events;

use App\Core\Events\DomainEvent;
use App\Modules\Sales\Models\DeliveryOrder;

/**
 * DO আর এগোবে না — সুপারভাইজার ফেরালেন (`rejected`) বা কেউ বাতিল করলেন (`cancelled`) — ২ অক্টোবর ২০২৬।
 *
 * ⓘ শোনেন abos-86: ধরে রাখা মজুদ ছাড়া। দুই মডিউলের মাঝে একটাই সংযোগ — সেবা ডাকাডাকি নয়।
 */
final class DeliveryOrderCancelled extends DomainEvent
{
    public static function from(DeliveryOrder $order, string $reason): self
    {
        return new self(
            publicId: (string) $order->public_id,
            payload: [
                'delivery_order_id' => (int) $order->id,
                'public_id' => (string) $order->public_id,
                'customer_id' => (int) $order->customer_id,
                'total' => (string) $order->total,
                'reason' => $reason,
            ],
            companyId: (int) $order->company_id,
        );
    }
}
