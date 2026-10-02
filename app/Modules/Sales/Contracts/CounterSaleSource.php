<?php

declare(strict_types=1);

namespace App\Modules\Sales\Contracts;

use App\Modules\Sales\Models\SalesInvoice;

/**
 * কাউন্টারের (সরাসরি বিক্রয়ের) পাতা যে কাগজ থেকে লাইন ভরে খোলে — বিক্রয়ের কাজের ধারা, ২ অক্টোবর ২০২৬, ধাপ ঙ+চ।
 *
 * ── ⭐ ধারা ──────────────────────────────────────────────────────────────
 * হিসাবে অনুমোদিত DO → ডিপোর যাচাই (কাউন্টারের লোক/ম্যানেজার, মজুদের বাধা দেখে **কমাতে** পারেন) → "নিশ্চিত"
 * চাপলে সরাসরি বিক্রয়ের পাতা, লাইন আগে থেকে ভরা → খসড়া রাখা বা নিশ্চিত → নিশ্চিতে ইনভয়েস আর চালান একসাথে,
 * আর উৎস "বিল হয়েছে"।
 *
 * ⓘ কাউন্টার কোনো উৎসের ভিতর জানে না — কেবল এই চুক্তি। প্রথম উৎস DeliveryOrder (abos-2c), চাবি 'do'।
 * ⛔ উৎসের লাইন বদলায় না — ডিপোর বদল বিলে বসে, আর বিলই সত্যি; অনুমোদিতের **বেশি** দেওয়া চলে না (সমন্বয়ক,
 * ২ অক্টোবর: বেশি লাগলে DO আবার সুপারভাইজারের কাছে)। সেই পাহারা কাউন্টারের দিকে, `source_line_id` ধরে।
 * ⓘ মজুদের আটকানো ছাড়া (abos-86-এর DeliveryOrderStock::consume) কাউন্টার নিজে ডাকে, [[markInvoiced()]]-এর পাশে —
 * উৎস ডাকে না।
 */
interface CounterSaleSource
{
    /** ঠিকানায় উৎসের চাবি — `?source=do&source_id=12` */
    public static function counterSourceKey(): string;

    /**
     * ⛔ কাউন্টারে খোলার আগে, আর নিশ্চিতের লেনদেনের ভিতরে আবার (তালা দিয়ে)। খোলার মতো অবস্থা না হলে, বা ইতিমধ্যে
     * বিল হয়ে গেলে, `ValidationException` — চাবি `source`।
     */
    public function assertReadyForCounter(): void;

    /**
     * কাউন্টারের পর্দা ভরার জন্য — লট ছাড়া (লট বাছা ডিপোর পাতায়, সরাসরি বিক্রয়ের নিয়মে)।
     *
     * @return array{ref: string, customer_id: int, warehouse_id: int|null, lines: list<array{product_id: int, qty: string, free_qty: string, rate: string, discount_percent: string, source_line_id: int}>}
     */
    public function counterScreen(): array;

    /** ডিপো যাচাইয়ে তোলা — দুইবার ডাকলে কিছুই হয় না। */
    public function enterDepotCheck(): void;

    /**
     * ধাপ চ — বিক্রয় নিশ্চিতের **একই লেনদেনে**, তালা দিয়ে: "বিল হয়েছে", আর কোন বিল তা লেখা। একই বিলে দুইবার ডাকলে
     * কিছুই হয় না; অন্য বিলে ডাকলে `ValidationException` (একটা উৎস একবারই বিল হয়)।
     */
    public function markInvoiced(SalesInvoice $invoice): void;
}
