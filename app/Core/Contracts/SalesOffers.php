<?php

declare(strict_types=1);

namespace App\Core\Contracts;

/**
 * ⭐ বিক্রয়ের কাগজে অফার — প্রশ্ন আর বসানো, এক জানালায় (অডিট §১১, ২৯ সেপ্টেম্বর ২০২৬)।
 *
 * ── ⓘ কেন কোরের চুক্তি ─────────────────────────────────────────────────
 * Sales অফার চেনে না, আর চেনা উচিতও নয় — Promotion মডিউল বন্ধ থাকলেও বিক্রি চলে।
 * ⛔ Sales সরাসরি Promotion ডাকলে মডিউলটা বন্ধ করার দিনে প্রতিটা চালান ৫০০ দিত।
 * ⭐ তাই Sales এই চুক্তি ডাকে; Promotion বাঁধে ([[PromotionSalesOffers]]), আর বন্ধ
 * থাকলে খালি উত্তর আসে ([[NoSalesOffers]]) — প্যানেলটাই আঁকা হয় না।
 *
 * ⓘ স্পেক §১০: সিস্টেম নিজে থেকে কিছু বসায় না — `suggest()` কেবল দেখায়, `apply()`
 * ডাকে মানুষের চাপা বোতাম।
 *
 * ⓘ "সারি" মানে ইঞ্জিনের সারি-প্রসঙ্গ: `customer_id`, `party_type_id`, `location_id`,
 * `branch_id`, `warehouse_id`, `product_id`, `category_id`, `brand_id`, `qty`, `value`।
 */
interface SalesOffers
{
    /** অফারের ব্যবস্থা চালু কি না — না হলে পর্দা প্যানেলটাই আঁকে না। */
    public function enabled(): bool;

    /**
     * এই সারিতে কী খাটে, আর কী প্রায় খাটে।
     *
     * ⓘ `billable` = টাকার ছাড় (শতাংশ বা নির্দিষ্ট টাকা) — বিলের সারিতে বসে।
     * ⚠️ বাকিগুলো (মাল, পয়েন্ট, জমা) দেখানো হয় কিন্তু এখান থেকে বসে না।
     *
     * @param  array<string, mixed>  $line
     * @return array{eligible: list<array{id: int, code: string, name: string, kind: string, kind_label: string, worth: string, billable: bool}>, almost: list<array{id: int, code: string, name: string, short_by: string}>}
     */
    public function suggest(array $line): array;

    /**
     * একটা অফার একটা কাগজের সারিতে বসাও — আর কত টাকার ছাড় বসল, তা ফেরাও।
     *
     * ⛔ অফার আর না খাটলে, ছাদ শেষ হলে, বা একই সারিতে আগেই বসানো থাকলে
     * `ValidationException`। ⛔ টাকার ছাড় না হলেও থামে।
     *
     * @param  array<string, mixed>  $line
     */
    public function apply(int $offerId, array $line, string $sourceType, int $sourceId, int $sourceLineId): string;

    /** একটা সারি থেকে একটা অফার তোলা — কাগজটা খসড়া থাকতেই। */
    public function remove(int $offerId, string $sourceType, int $sourceId, int $sourceLineId): void;

    /**
     * কাগজে বসানো অফারগুলো — উল্টানো বাদে।
     *
     * @return list<array{offer_id: int, line_id: int|null, code: string, name: string, worth: string}>
     */
    public function appliedOn(string $sourceType, int $sourceId): array;

    /** কাগজের সব অফার উল্টানো — বাতিলে, আর খসড়া বদলালে (সারিগুলো নতুন করে বসে)। */
    public function reverseAll(string $sourceType, int $sourceId): void;

    /**
     * ⭐ আদেশে কাটা টাকার কুপন — আদেশের বিল পাকা হলে সেই বিলে (পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬, প্রমোশন ১৮)।
     *
     * ⓘ বিল পাকা করার **একই লেনদেনে** ডাকা হয়, বিল খাতায় বসার পরে; ছাড়টা বসে [[CouponPapers::redeemed()]] দিয়ে, বিলের অঙ্কের
     * বেশি নয় (`$room`)।
     *
     * @param  list<int>  $orderIds  বিলটা যে আদেশগুলোর মাল বিল করল
     */
    public function carryOrderCoupons(array $orderIds, int $invoiceId, string $room): void;
}
