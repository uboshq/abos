<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Support\Collection;

/**
 * এই কাগজে কোন পণ্যের কোন লট গেছে — ছাপার জন্য।
 *
 * ── কেন লাইন থেকে নয়, চলাচল থেকে ─────────────────────────────────────
 * বিক্রয়ের লাইনে কোনো লট নেই, আর থাকার কথাও নয়: একটা লাইন একাধিক লট
 * থেকে পূরণ হতে পারে (পুরনোটায় তিনটা, পরেরটায় দুইটা)। লটটা লেখা আছে
 * চলাচলের সারিতে — যেখানে সিদ্ধান্তটা সত্যিই হয়েছিল।
 *
 * ── কেন একবারে সব, লাইন ধরে ধরে নয় ───────────────────────────────────
 * ছাপার কন্ট্রোলার লাইনগুলোর উপর লুপ চালায়। প্রতিটা লাইনে একটা কোয়েরি
 * দিলে বিশ লাইনের বিলে বিশটা কোয়েরি — মজুদের পর্দায় ঠিক এই ভুলটা একবার
 * করে শোধরানো হয়েছে। তাই পুরো কাগজের লটগুলো এক কোয়েরিতে ওঠে, তারপর
 * পণ্য ধরে ভাগ হয়।
 */
final class IssuedLots
{
    /**
     * @return array<int, string> পণ্যের আইডি → "B1 · 06/2027, B2 · 11/2027"
     */
    public function forDocument(string $sourceType, int $sourceId): array
    {
        return StockMovement::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->whereNotNull('batch_id')
            ->with('batch')
            ->get()
            ->groupBy('product_id')
            ->map(function (Collection $rows) {
                /*
                 * একই লট দুইবার এলে একবারই লেখা হয়।
                 *
                 * সাধারণত আসে না, কিন্তু একই চালানে একই পণ্য দুই লাইনে
                 * থাকলে (দুই দরে, বা ফ্রি সহ) একই লট দুইবার বেরোতে পারে —
                 * আর কাগজে "B1 · B1" দেখে ক্রেতা ভাবতেন দুইটা আলাদা লট।
                 */
                return $rows
                    ->map(fn (StockMovement $row) => $row->batch?->label())
                    ->filter()
                    ->unique()
                    ->implode(', ');
            })
            ->filter(fn (string $label) => $label !== '')
            ->all();
    }
}
