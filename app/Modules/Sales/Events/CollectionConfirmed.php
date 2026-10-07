<?php

declare(strict_types=1);

namespace App\Modules\Sales\Events;

use App\Core\Events\DomainEvent;
use App\Modules\Sales\Models\Collection;

/**
 * ⭐ একটা আদায় পাকা হলো — ২ অক্টোবর ২০২৬ (বিক্রয়ের কাজের ধারা, ধাপ গ)।
 *
 * ⓘ গ্রাহকের টাকা এল, তাই তাঁর টাকার জন্য আটকে থাকা ডেলিভারি অর্ডার আবার যাচাই হয়। ⚠️ আদায় খাতায় বসে
 * নিজের পথে ([[CollectionService::confirm()]] → PostingEngine), রসিদ ভাউচারের পথে নয় — তাই [[VoucherPosted]]
 * এখানে ছোটে না, আর আলাদা ঘটনা লাগে।
 *
 * ⚠️ ছোটে লেনদেন পাকা হওয়ার **পরে** — আগে ছুটলে শ্রোতা এমন টাকা দেখত যা রোলব্যাকে মুছে যেতে পারে।
 */
final class CollectionConfirmed extends DomainEvent
{
    public static function from(Collection $collection): self
    {
        return new self(
            publicId: (string) $collection->public_id,

            payload: [
                'collection_id' => (int) $collection->id,
                'customer_id' => $collection->customer_id !== null ? (int) $collection->customer_id : null,
                'amount' => (string) $collection->amount,
            ],
        );
    }
}
