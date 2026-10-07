<?php

declare(strict_types=1);

namespace App\Modules\Sales\Events;

use App\Core\Events\DomainEvent;
use App\Modules\Sales\Models\DeliveryOrder;

/**
 * সুপারভাইজারের শেষ স্তর পেরোল — DO এখন `supervisor_approved` (২ অক্টোবর ২০২৬)।
 *
 * ⓘ শোনেন abos-86: হিসাবের স্বয়ংক্রিয় যাচাই (সীমা/জমা কুলোলে accounts_approved, নাহলে accounts_held) আর
 * মজুদের নরম সংরক্ষণ। ⓘ ছোটে লেনদেন পাকা হওয়ার পরে — ফিরিয়ে নেওয়া অনুমোদনের খবর যায় না।
 */
final class DeliveryOrderSupervisorApproved extends DomainEvent
{
    public static function from(DeliveryOrder $order): self
    {
        return new self(
            publicId: (string) $order->public_id,
            payload: [
                'delivery_order_id' => (int) $order->id,
                'public_id' => (string) $order->public_id,
                'customer_id' => (int) $order->customer_id,
                'total' => (string) $order->total,
            ],
            companyId: (int) $order->company_id,
        );
    }
}
