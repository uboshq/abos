<?php

declare(strict_types=1);

namespace App\Core\Contracts;

/**
 * একটা বিক্রির লাইনে কয়টা ফ্রি — অর্ডারের পর্দার "ফ্রি" ঘরের জন্য (মালিক, ১ অক্টোবর ২০২৬)।
 *
 * ⓘ Sales অফারের মডিউল চেনে না, এই চুক্তি চেনে; Promotion চালু থাকলে সে বাঁধে, না থাকলে
 * কোরের খালি বাস্তবায়ন ([[NoFreeGoodsOffers]]) — কোনো অফার নেই, পর্দা ঘরটা শূন্য দেখায়।
 * ⚠️ এটা কেবল **দেখানোর** হিসাব — মাল দেওয়া বা খাতায় বসানো এখান দিয়ে হয় না।
 */
interface FreeGoodsOffers
{
    /**
     * @param  array<string, mixed>  $line  customer_id, party_type_id, location_id, branch_id, warehouse_id,
     *                                      product_id, category_id, brand_id, qty, value
     * @return list<array{promotion_id: int, text: string, buy_qty: ?string, free_qty: string,
     *                    gift_product_id: ?int, gift_name: ?string}>
     */
    public function forLine(array $line): array;
}
