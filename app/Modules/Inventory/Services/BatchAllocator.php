<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * কোন লট থেকে কতটা যাবে — FEFO।
 *
 * ── কেন বাছাইটা কোড করে, মানুষ নয় ───────────────────────────────────
 * কাউন্টারে লাইন থাকে। ড্রপডাউন থেকে ব্যাচ বাছতে বললে ক্যাশিয়ার
 * প্রতিবার প্রথমটাই বাছেন — আর তখন ব্যাচ ধরে রাখা কেবল সাজসজ্জা: মজুদ
 * লট ধরে হিসাব হয়, বিক্রয় হয় না, আর পুরনো পাতাগুলো তাকেই মেয়াদ পার
 * করে। **নিজে থেকে না বাছলে FEFO থাকা আর না থাকা সমান।**
 *
 * ── কেন ভাগ করে দেওয়া হয়, একটা লট বেছে নয় ───────────────────────────
 * পাঁচটা চাইলেন, সবচেয়ে পুরনো লটে আছে তিনটা। তিনটা ওখান থেকে, বাকি
 * দুইটা তার পরেরটা থেকে — কারণ পুরনোটা আগে খালি করাই পুরো উদ্দেশ্য।
 * একটা লট বেছে "যথেষ্ট নেই" বললে ক্যাশিয়ার হাতে অন্য লট বাছতেন, আর
 * আবার সেই প্রথমটাই।
 */
class BatchAllocator
{
    /**
     * এই পণ্যের এতটা কোথা থেকে আসবে।
     *
     * @return list<array{batch: Batch, qty: string}> মেয়াদের ক্রমে
     *
     * @throws ValidationException যথেষ্ট না থাকলে
     */
    public function allocate(
        Product $product,
        Warehouse $warehouse,
        string $wanted,
        ?Carbon $on = null,
    ): array {
        if (bccomp($wanted, '0', 4) <= 0) {
            throw ValidationException::withMessages([
                'qty' => __('inventory::validation.qty_positive'),
            ]);
        }

        $taken = [];
        $left = $wanted;

        foreach ($this->candidates($product, $on) as $batch) {
            if (bccomp($left, '0', 4) <= 0) {
                break;
            }

            $available = $batch->balance($warehouse);

            if (bccomp($available, '0', 4) <= 0) {
                continue;
            }

            // এই লট থেকে যতটা পারা যায়, তার বেশি নয়।
            $take = bccomp($available, $left, 4) >= 0 ? $left : $available;

            $taken[] = ['batch' => $batch, 'qty' => $take];
            $left = bcsub($left, $take, 4);
        }

        if (bccomp($left, '0', 4) > 0) {
            /*
             * পুরোটা না পেলে কিছুই দেওয়া হয় না।
             *
             * আংশিক ফেরত দিলে ডাকা কোড ভাবত কাজ হয়ে গেছে, আর বাকিটা
             * নীরবে হারাত — অথবা লট ছাড়া বেরোত, যেটা ঠিক ওই
             * খুঁজে-বের-করার সুতোটাই ছিঁড়ে দিত যার জন্য ব্যাচ আছে।
             */
            throw ValidationException::withMessages([
                'qty' => __('inventory::validation.batch_short', [
                    'product' => $product->name(),
                    'short' => rtrim(rtrim($left, '0'), '.'),
                ]),
            ]);
        }

        return $taken;
    }

    /**
     * যে লটগুলো থেকে নেওয়া যায় — মেয়াদের ক্রমে, তালা দিয়ে।
     *
     * ── তালা কেন ────────────────────────────────────────────────────
     * দুই কাউন্টার, একটাই পাতা বাকি। দুইজনেই ব্যালেন্স পড়ে ১ দেখেন,
     * দুইজনেই বেচেন, লট −১-এ যায়। ঋণাত্মক লট গোল করার সমস্যা নয়:
     * তখন খুঁজে-বের-করার খাতা বলছে এমন বাক্স থেকে মাল বেরিয়েছে যা
     * আগেই খালি ছিল, আর রিকলে ভুল ক্রেতাকে ফোন যাবে।
     *
     * `lockForUpdate` দ্বিতীয় লেনদেনটাকে অপেক্ষা করায়, তাই সে ০ পড়ে
     * আর ফিরে যায়। সারিটা স্বাভাবিক চোকপয়েন্ট — ওই লটের প্রতিটা
     * চলাচল তার নাম ধরে।
     *
     * @return Collection<int, Batch>
     */
    private function candidates(Product $product, ?Carbon $on)
    {
        return Batch::query()
            ->where('product_id', $product->id)
            ->unexpired($on)
            ->fefo()
            ->lockForUpdate()
            ->get();
    }

    /**
     * বাছাই না করে কেবল দেখা — পর্দায় "কোন ব্যাচ যাবে" দেখানোর জন্য।
     *
     * তালা নেই, আর যথেষ্ট না থাকলেও ব্যতিক্রম নয়: এটা প্রশ্ন, আদেশ নয়।
     * বিক্রয় বসানোর সময় `allocate()` আবার চলে, তালাসহ — কারণ পর্দায়
     * দেখা আর খাতায় বসা এক মুহূর্ত নয়, আর মাঝখানে অন্য কাউন্টার
     * পুরোটা নিয়ে যেতে পারে।
     *
     * @return list<array{batch: Batch, qty: string}>
     */
    public function preview(
        Product $product,
        Warehouse $warehouse,
        string $wanted,
        ?Carbon $on = null,
    ): array {
        $taken = [];
        $left = $wanted;

        $batches = Batch::query()
            ->where('product_id', $product->id)
            ->unexpired($on)
            ->fefo()
            ->get();

        foreach ($batches as $batch) {
            if (bccomp($left, '0', 4) <= 0) {
                break;
            }

            $available = $batch->balance($warehouse);

            if (bccomp($available, '0', 4) <= 0) {
                continue;
            }

            $take = bccomp($available, $left, 4) >= 0 ? $left : $available;
            $taken[] = ['batch' => $batch, 'qty' => $take];
            $left = bcsub($left, $take, 4);
        }

        return $taken;
    }
}
