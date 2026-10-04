<?php

declare(strict_types=1);

namespace App\Modules\Sales\Events;

use App\Core\Events\DomainEvent;
use App\Modules\Sales\Models\SalesOrder;

/**
 * ⭐ বিক্রয় আদেশ বন্ধ হলো — পুরো বিলের পরে, বা কারণসহ কম রেখে (মালিক, ৪ অক্টোবর ২০২৬; [[SalesOrderService::close()]])।
 *
 * ⓘ শোনেন abos-86 (`ReleaseTheOrderStock`): নতুন ধারার (`hold_mode = holds`) আদেশের বাকি হোল্ড ছাড়া। ⚠️ আজকের নিয়মের
 * (`ledger`) আদেশের সংরক্ষণ সেবা নিজেই ছাড়ে — শ্রোতা তাই `hold_mode` দেখে, দুইবার নয়।
 *
 * ⓘ `reason` খালি মানে পুরো বিলের পরে বন্ধ — কিছু ছাড়ার নেই, তবু ঘটনা ছোটে (শ্রোতা নিজে দেখে)।
 * ⚠️ ছোটে লেনদেন পাকা হওয়ার **পরে** (`DB::afterCommit`) — থেমে যাওয়া বন্ধে কিছুই ছোটে না।
 */
final class SalesOrderClosed extends DomainEvent
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
