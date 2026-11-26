<?php

declare(strict_types=1);

namespace App\Core\Contracts;

/**
 * ⭐ এক পণ্যের শেষ পোস্ট হওয়া ক্রয়ের খরচ — সরাসরি বিক্রয়ের "এই লাইন"-এর "ক্রয়মূল্য" (মালিক, ২৬ নভেম্বর ২০২৬)।
 *
 * ⓘ ক্রয় বিক্রয়কে চেনে, উল্টো নয় — তাই বিক্রয় কেবল এই চুক্তি চেনে, ক্রয় নিজের বিলের সারি থেকে উত্তর দেয়
 * ([[\App\Modules\Purchase\Services\PurchaseLastCost]])। ক্রয় বন্ধ থাকলে [[\App\Core\Services\NoLastPurchaseCost]] — "এখনো কোনো ক্রয় নেই"।
 */
interface LastPurchaseCost
{
    /**
     * প্রতি এককের দর, ছাড়, আনলোডিং, মোট; ফ্রি ধরে প্রতি একক; বিলের নম্বর, তারিখ, সরবরাহকারী, নোট। পোস্ট হওয়া ক্রয় না থাকলে null।
     *
     * @return array{rate: string, discount: string, unload: string, total: string, qty: string, free: string, withFree: ?string, billNo: string, supplierBillNo: string, date: string, supplier: string, notes: list<string>}|null
     */
    public function of(int $productId): ?array;
}
