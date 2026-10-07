<?php

declare(strict_types=1);

namespace App\Core\Engines\Sync;

use RuntimeException;

/**
 * "এই বদলটা নেওয়া যাবে না, আর এই তার কারণ।"
 *
 * ── কেন নিজের ব্যতিক্রম, `ValidationException` নয় ───────────────────
 * `ValidationException` ঘরের নাম ধরে কথা বলে — "এই ঘরটা ভুল"। সিঙ্কের
 * প্রত্যাখ্যান প্রায়ই কোনো ঘরের দোষ নয়: দোকান ক্রেডিট সীমা পেরিয়েছে,
 * মাসটা বন্ধ, পণ্যটা আর বিক্রির তালিকায় নেই। ঘরের নামে ওগুলো বসালে
 * ফোনের পর্দায় একটা ভুল ঘরে লাল দাগ পড়ত।
 *
 * দুইটাই ধরা হয় আর একইভাবে দেখানো হয় ([[SyncService::applyOne()]]),
 * তাই মডিউল যেটা স্বাভাবিক সেটাই ছুঁড়তে পারে।
 *
 * ── বার্তাটা বাংলায় ────────────────────────────────────────────────
 * এটা লগ নয়, **UI**। বার্তাটা `sync_changes.message`-এ বসে, ফোনে যায়,
 * আর সেলসম্যান সেটা পড়ে সিদ্ধান্ত নেন — আবার লিখবেন, না অফিসে ফোন
 * করবেন। ইংরেজিতে লিখলে তিনি কিছুই বুঝতেন না, আর "০টা অপেক্ষমাণ" দেখে
 * ধরে নিতেন সব ঠিক আছে।
 */
final class SyncRejection extends RuntimeException
{
    /**
     * @param  bool  $isConflict  সার্ভারে নতুনতর একটা বদল আছে — অর্থাৎ
     *                            এটা নিয়মভঙ্গ নয়, দুই পাশের দ্বন্দ্ব।
     *                            ফোন দুইটাকেই একইভাবে ধরে রাখে, কিন্তু
     *                            দ্বন্দ্বের একটা সারি
     *                            [[SyncConflict]]-এ বসে যাতে মানুষ
     *                            দুইটা রূপ পাশাপাশি দেখে সিদ্ধান্ত নিতে
     *                            পারেন।
     */
    public function __construct(
        string $message,
        public readonly bool $isConflict = false,
        /** @var array<string, mixed>|null সার্ভারে এখন যা আছে — দ্বন্দ্বের সারিতে বসে */
        public readonly ?array $serverSnapshot = null,
    ) {
        parent::__construct($message);
    }

    /**
     * সার্ভারে নতুনতর বদল আছে — ফোনেরটা চাপা দেওয়া হবে না।
     *
     * @param  array<string, mixed>|null  $serverSnapshot
     */
    public static function conflict(string $message, ?array $serverSnapshot = null): self
    {
        return new self($message, isConflict: true, serverSnapshot: $serverSnapshot);
    }
}
