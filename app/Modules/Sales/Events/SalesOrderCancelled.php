<?php

declare(strict_types=1);

namespace App\Modules\Sales\Events;

use App\Core\Events\DomainEvent;
use App\Modules\Sales\Models\SalesOrder;

/**
 * ⭐ বিক্রয় আদেশ বাতিল হলো — কারণসহ (মালিক, ৪ অক্টোবর ২০২৬: আন্তর্জাতিক মান; নকশা "DO বিক্রয় আদেশে মেশানো" §৩.১, §৩.৩)।
 *
 * ⓘ শোনেন abos-86 (`ReleaseTheOrderStock`): নতুন ধারার (`hold_mode = holds`) আদেশের ঘড়িসহ হোল্ড ছাড়া। ⚠️ আজকের নিয়মের
 * (`ledger`) আদেশের সংরক্ষণ সেবা নিজেই ছাড়ে ([[SalesOrderService::cancel()]]) — শ্রোতা তাই `hold_mode` দেখে, দুইবার নয়।
 *
 * ⚠️ ছোটে লেনদেন পাকা হওয়ার **পরে** (`DB::afterCommit`) — থেমে যাওয়া বাতিলে (ফেরানো, বা রোলব্যাক) কিছুই ছোটে না।
 * ⓘ DO-র [[DeliveryOrderCancelled]]-এর ছাঁচ — দুই মডিউলের মাঝে একটাই সংযোগ, সেবা ডাকাডাকি নয়।
 */
final class SalesOrderCancelled extends DomainEvent
{
    public static function from(SalesOrder $order, string $reason): self
    {
        return new self(
            publicId: (string) $order->public_id,
            payload: [
                'sales_order_id' => (int) $order->id,
                'public_id' => (string) $order->public_id,
                'customer_id' => (int) $order->customer_id,
                'hold_mode' => (string) ($order->hold_mode ?? 'ledger'),
                'reason' => $reason,
            ],
            companyId: (int) $order->company_id,
        );
    }
}
