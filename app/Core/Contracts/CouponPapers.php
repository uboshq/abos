<?php

declare(strict_types=1);

namespace App\Core\Contracts;

/**
 * কুপন যে কাগজের সারিতে খাটে — সেই সারিটা কী (গভীর অডিট, ২৯ সেপ্টেম্বর ২০২৬)।
 *
 * ── ⛔ কেন লাগল ─────────────────────────────────────────────────────────
 * কুপন ভাঙানোর দরজা ([[PromotionCouponController::redeem()]]) পরিমাণ, অঙ্ক, ক্রেতা আর কাগজের নম্বর — সবই
 * অনুরোধ থেকে নিত, কাগজটা আদৌ আছে কি না না দেখে। একটা বানানো কাগজের নম্বরে কুপনের ব্যবহার, অফারের বাজেট
 * আর ফ্রি মালের পাওনা — সব খরচ হয়ে যেত।
 *
 * ── ⓘ কেন চুক্তি ───────────────────────────────────────────────────────
 * প্রমোশন বিক্রয়কে চেনে না (উল্টোটা চুক্তি দিয়ে — [[SalesOffers]]); কাগজগুলো বিক্রয়ের, তাই উত্তরও বিক্রয়ের
 * ([[SalesCouponPapers]])। ⛔ কেউ বাঁধা না থাকলে দরজা কুপন ভাঙায়ই না — ভুল বন্ধ দিকে।
 */
interface CouponPapers
{
    /**
     * এই কোম্পানির একটা **পাকা** কাগজের একটা সারি — নাহলে `null`।
     *
     * @return array{customer_id: ?int, branch_id: ?int, warehouse_id: ?int, product_id: int, qty: string, value: string}|null
     */
    public function line(string $sourceType, int $sourceId, int $sourceLineId): ?array;
}
