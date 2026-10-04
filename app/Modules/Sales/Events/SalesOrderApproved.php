<?php

declare(strict_types=1);

namespace App\Modules\Sales\Events;

use App\Core\Events\DomainEvent;
use App\Modules\Sales\Models\SalesOrder;

/**
 * ⭐ বিক্রয় আদেশের শেষ সই হলো (বা ছক নেই) — নতুন ধারা, `hold_mode = holds` (নকশা "DO বিক্রয় আদেশে মেশানো" §৩.১, ধাপ ৩)।
 *
 * ⓘ বাকির যাচাই জমার মুহূর্তেই হয়ে গেছে (সমন্বয়কের উত্তর ১, ৪ অক্টোবর ২০২৬) — আদেশ এখন `approved`।
 * ⓘ শোনেন abos-86 (`HoldAndCheckTheOrder`, নকশার ধাপ ৪): ঘড়িসহ মাল আটকানো, তারপর
 * [[SalesOrderService::markConfirmed()]] → `confirmed`। ⚠️ সেই শ্রোতা আসার আগে আদেশ `approved`-এ থামে — আর ধাপ ৫-এর
 * আগে কোনো কোম্পানিতে সুইচ চালু নয় (নকশার ক্রমের নিয়ম)।
 *
 * ⚠️ ছোটে লেনদেন পাকা হওয়ার **পরে** (`DB::afterCommit`)।
 */
final class SalesOrderApproved extends DomainEvent
{
    public static function from(SalesOrder $order): self
    {
        return new self(
            publicId: (string) $order->public_id,
            payload: [
                'sales_order_id' => (int) $order->id,
                'public_id' => (string) $order->public_id,
                'customer_id' => (int) $order->customer_id,
                'total' => (string) $order->total,
                'hold_mode' => (string) ($order->hold_mode ?? 'ledger'),
            ],
            companyId: (int) $order->company_id,
        );
    }
}
