<?php

declare(strict_types=1);

namespace App\Core\Engines\Sync;

use Illuminate\Support\Carbon;

/**
 * একটা রেকর্ড, ফোনে নামার পথে।
 *
 * ── কেন মডেল নয়, এই ছোট বস্তুটা ─────────────────────────────────────
 * হ্যান্ডলার সরাসরি Eloquent মডেল ফেরত দিলে যা হত: `toArray()` **সব
 * কলাম** পাঠাত — `purchase_price`, `unit_cost`, `cost_of_goods` সহ।
 * অর্থাৎ পর্দায় যে তালা [[FieldSecurity]] বসায়, **ফোন দিয়ে সেটা
 * ঘুরে খোলা যেত**।
 *
 * এই বস্তুটা হ্যান্ডলারকে বাধ্য করে ঠিক করতে **কী পাঠানো হবে**, আর
 * সেই সিদ্ধান্তটা এক জায়গায় দেখা যায়।
 *
 * ── `updatedAt` কেন বাধ্যতামূলক ─────────────────────────────────────
 * ওয়াটারমার্ক এটার উপর দাঁড়ানো। ফোনও এটা ক্যাশে রাখে, যাতে একটা পর্দা
 * বলতে পারে "দাম ৩ দিন আগের" — বাসি সংখ্যাকে আজকের বলে দেখানোর বদলে।
 */
final class SyncRecord
{
    public function __construct(
        public readonly string $entityType,
        public readonly string $entityId,
        /** @var array<string, mixed> */
        public readonly array $payload,
        public readonly Carbon $updatedAt,
    ) {}

    /**
     * তারের উপর যে আকারে যায় — `reference_sync.dart` ঠিক এই চারটা ঘর
     * পড়ে, আর একটাও অনুপস্থিত থাকলে রেকর্ডটা নীরবে বাদ দেয়।
     *
     * @return array{entityType: string, entityId: string, payloadJson: string, updatedAt: string}
     */
    public function toArray(): array
    {
        return [
            'entityType' => $this->entityType,
            'entityId' => $this->entityId,

            /*
             * JSON স্ট্রিং, নেস্টেড অবজেক্ট নয় — ইচ্ছাকৃত।
             *
             * ফোনের কিউ আর ক্যাশ দুইটাই payload কে অস্বচ্ছ স্ট্রিং
             * হিসেবে রাখে। তাতে আজকের অ্যাপ যে ঘরগুলো চেনে না সেগুলোও
             * অক্ষত থেকে যায়, আর পুরনো বিল্ড নতুন সার্ভারের উত্তরে
             * ভাঙে না।
             */
            'payloadJson' => json_encode($this->payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),

            /*
             * ISO-8601, UTC-তে। `toIso8601String()` অফসেট সহ লেখে, আর
             * Dart-এর `DateTime.tryParse` সেটা ঠিকই পড়ে `.toLocal()`
             * করার জন্য। অফসেট ছাড়া পাঠালে ফোন ওটাকে স্থানীয় সময়
             * ধরত — ছয় ঘণ্টার ভুল, যা দেখতে সম্ভাব্য বলেই কেউ ধরত না।
             */
            'updatedAt' => $this->updatedAt->toIso8601String(),
        ];
    }
}
