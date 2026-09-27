<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Services;

use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\StockMovement;

/**
 * এই পণ্যের শেষ যে লটটা ঢুকেছিল — নম্বর, মেয়াদ, ছাপা দাম।
 *
 * ── কেন দরকার ────────────────────────────────────────────────────────
 * মালিকের সিদ্ধান্ত: নতুন পণ্য লট ধরে চলে। ⓘ তাই কাউন্টারে প্রায় প্রতিটা
 * সারিতে লট নম্বর লাগে, আর মিল সাধারণত একই লট কয়েক চালানে পাঠায়।
 * ⭐ গতবারের লটটা আগে থেকে বসানো থাকলে মানুষটা কেবল Enter চাপেন — আর
 * লট বদলালে লেখাটা বদলে দেন।
 *
 * ── "শেষ" মানে কোনটা ─────────────────────────────────────────────────
 * শেষ যে **ঢোকার চলাচলে** (তাক বা বসানোর অপেক্ষা বাড়ল) লটটা ছিল — লট
 * সারিটা কবে জন্মেছিল তা নয়। ⚠️ একই লটে মাল দ্বিতীয়বার এলে নতুন লট সারি
 * হয় না ([[BatchService::receive]]), তাই জন্মের তারিখ ধরলে পুরনো লটটাই
 * "শেষ" দেখাত যদিও গতকাল অন্য লট ঢুকেছে।
 *
 * ⓘ কোনো চলাচল না থাকলে (খোলা মজুদের আগেই লট বসানো) সবচেয়ে নতুন
 * লট সারিটাই উত্তর।
 *
 * ⚠️ দুইটা মডেলই কোম্পানির ছাঁকনির নিচে, আর ব্যবহারকারীর গুদামের
 * দেয়ালও মানে — অন্য গুদামের লট প্রস্তাব হয়ে পর্দায় আসে না।
 */
final class LastLotFor
{
    /**
     * একটা পণ্যের শেষ লট — না থাকলে null।
     *
     * @return array{batch_no: string, expiry_date: string, mrp: string}|null
     */
    public function product(int $productId): ?array
    {
        return $this->products([$productId])[$productId] ?? null;
    }

    /**
     * অনেক পণ্যের শেষ লট একসাথে — পর্দার পুরো তালিকার জন্য।
     *
     * ⓘ তিনটা কোয়েরি, পণ্যের সংখ্যা যা-ই হোক। ⛔ পণ্য ধরে ধরে জিজ্ঞেস
     * করলে দুই হাজার পণ্যের তালিকায় দুই হাজার কোয়েরি হত, প্রতিবার পাতা
     * খুলতে।
     *
     * @param  list<int>  $productIds
     * @return array<int, array{batch_no: string, expiry_date: string, mrp: string}>
     */
    public function products(array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));

        if ($productIds === []) {
            return [];
        }

        /*
         * ⚠️ কেবল `MAX(id)` আর দলের কলাম — লাইভের ONLY_FULL_GROUP_BY
         * অন্য কিছু বাছতে দেয় না।
         */
        $lastMove = StockMovement::query()
            ->whereIn('product_id', $productIds)
            ->whereNotNull('batch_id')
            ->whereRaw('(floor_change + unplaced_change) > 0')
            ->groupBy('product_id')
            ->selectRaw('product_id, MAX(id) as last_id')
            ->pluck('last_id', 'product_id');

        $batchOfMove = $lastMove->isEmpty()
            ? collect()
            : StockMovement::query()->whereIn('id', $lastMove->values())->pluck('batch_id', 'product_id');

        $lots = [];

        if ($batchOfMove->isNotEmpty()) {
            $batches = Batch::query()->whereIn('id', $batchOfMove->values())->get()->keyBy('id');

            foreach ($batchOfMove as $productId => $batchId) {
                $batch = $batches->get((int) $batchId);

                if ($batch !== null) {
                    $lots[(int) $productId] = $this->shape($batch);
                }
            }
        }

        $missing = array_values(array_diff($productIds, array_keys($lots)));

        if ($missing !== []) {
            // ⓘ চলাচল নেই — সবচেয়ে নতুন লট সারিটাই (পণ্য প্রতি একটা, সব লট টেনে নয়)
            $newest = Batch::query()
                ->whereIn('product_id', $missing)
                ->groupBy('product_id')
                ->selectRaw('MAX(id) as last_id')
                ->pluck('last_id');

            if ($newest->isNotEmpty()) {
                Batch::query()->whereIn('id', $newest)->get()->each(function (Batch $batch) use (&$lots): void {
                    $lots[(int) $batch->product_id] = $this->shape($batch);
                });
            }
        }

        return $lots;
    }

    /** @return array{batch_no: string, expiry_date: string, mrp: string} */
    private function shape(Batch $batch): array
    {
        return [
            'batch_no' => (string) $batch->batch_no,
            'expiry_date' => $batch->expiry_date?->toDateString() ?? '',
            'mrp' => $batch->mrp === null ? '' : rtrim(rtrim((string) $batch->mrp, '0'), '.'),
        ];
    }
}
