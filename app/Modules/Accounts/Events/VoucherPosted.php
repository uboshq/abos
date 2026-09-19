<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Events;

use App\Core\Events\DomainEvent;
use App\Modules\Accounts\Models\Voucher;

/**
 * একটা ভাউচার খাতায় বসেছে — লেনদেন পাকা হওয়ার পরে।
 *
 * ── কেন এটা লাগল, ১৯ সেপ্টেম্বর ২০২৬ ─────────────────────────────────
 * মালিক: *"capital theke asle eta auto boslei to valo hoy"*। ⓘ রসিদ
 * ভাউচারে মালিকের মূলধন নিলে টাকা খাতায় ঠিকই বসত, কিন্তু "মূলধন ও
 * বিনিয়োগ" পাতা জানত না — ওটা কেবল নিজের পর্দার রেকর্ড দেখায়। ⚠️ Finance
 * যেন Accounts-এর সেবার ভেতরে হাত না দিয়ে এটা জানতে পারে, সেজন্য ঘোষণা।
 *
 * ⓘ শুধু পরিচয় আর ধরন বয়ে নেয়; শ্রোতা ভাউচারটা নিজে পড়ে নেয়।
 */
final class VoucherPosted extends DomainEvent
{
    public static function from(Voucher $voucher): self
    {
        return new self(
            publicId: (string) $voucher->public_id,

            payload: [
                'type' => (string) $voucher->type,
                'document_no' => (string) $voucher->document_no,
                'party_type' => $voucher->party_type,
                'party_id' => $voucher->party_id !== null ? (int) $voucher->party_id : null,
            ],
        );
    }
}
