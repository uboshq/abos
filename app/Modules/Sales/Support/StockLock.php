<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support;

use App\Modules\Inventory\Models\Product;

/**
 * মজুদ গুনে ধরার আগে পণ্যের সারিতে তালা — অডিট ম১৮, ৬ অক্টোবর ২০২৬।
 *
 * ⛔ "কতটা খালি" ([[StockService::availableQty()]]) তালা ছাড়া পড়া হত, তারপর ধরা লেখা হত। দুটো আদেশ একসাথে নিশ্চিত হলে
 * দুটোই একই খালি মাল দেখত, আর দুটোই পুরোটা ধরত — তাকের চেয়ে বেশি ধরা, পরে একজনের চালান খালি হাতে ফিরত।
 * ⭐ প্রতিটা পণ্যের সারি `FOR UPDATE` — যে পরে আসে সে আগেরজনের লেনদেন শেষ হওয়া পর্যন্ত দাঁড়ায়, তারপর নতুন অঙ্ক পড়ে।
 * ⓘ আইডির ক্রমে, যাতে দুই আদেশ একে অপরের উল্টো ক্রমে তালা চেয়ে আটকে না থাকে। ⚠️ লেনদেনের ভিতরেই ডাকতে হবে।
 */
final class StockLock
{
    /** @param  iterable<int|string|null>  $productIds */
    public static function products(iterable $productIds): void
    {
        $ids = [];

        foreach ($productIds as $id) {
            if ($id !== null && (int) $id > 0) {
                $ids[(int) $id] = true;
            }
        }

        if ($ids === []) {
            return;
        }

        $ids = array_keys($ids);
        sort($ids);

        Product::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get(['id']);
    }
}
