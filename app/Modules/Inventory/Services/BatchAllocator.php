<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
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
        return $this->take($product, $warehouse, $wanted, $on, free: false);
    }

    /**
     * ফ্রি ভাণ্ডার থেকে — একই লট, একই মেয়াদের ক্রম, আলাদা হিসাব।
     *
     * ── কেন এটাও লট ধরে ─────────────────────────────────────────────
     * ফ্রি কার্টনের গায়েও ব্যাচ নম্বর ছাপা, আর মেয়াদোত্তীর্ণ ফ্রি ওষুধ
     * বিক্রি করা ওষুধের চেয়ে এক চুলও কম বিপজ্জনক নয়। রিকল হলে যাঁরা
     * ফ্রি পেয়েছেন তাঁদেরও ফোন করতে হবে — নাহলে তালিকাটা দেখে মনে হবে
     * সবাই ধরা পড়েছে, অথচ কয়েকজন বাদ।
     *
     * ── কেন কম পড়ার বার্তা আলাদা ────────────────────────────────────
     * "লট ধরা শুরুর আগের মাল" কথাটা ফ্রি ভাণ্ডারে খাটে না — ওই
     * ভাণ্ডারটাই নতুন, তার আগে কিছু ছিল না। তাই সেখানে সোজা কথাটাই
     * বলা হয়: এত কম পড়ছে।
     *
     * @return list<array{batch: Batch, qty: string}> মেয়াদের ক্রমে
     */
    public function allocateFree(
        Product $product,
        Warehouse $warehouse,
        string $wanted,
        ?Carbon $on = null,
    ): array {
        return $this->take($product, $warehouse, $wanted, $on, free: true);
    }

    /**
     * দুই ভাণ্ডারের একই বাছাই — কেবল কোন ঘরটা গোনা হবে তার তফাত।
     *
     * @return list<array{batch: Batch, qty: string}>
     */
    private function take(
        Product $product,
        Warehouse $warehouse,
        string $wanted,
        ?Carbon $on,
        bool $free,
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

            $available = $free ? $batch->freeBalance($warehouse) : $batch->balance($warehouse);

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
                'qty' => $free
                    ? __('inventory::validation.free_batch_short', [
                        'product' => $product->name(),
                        'short' => rtrim(rtrim($left, '0'), '.'),
                    ])
                    : $this->shortMessage($product, $warehouse, $left),
            ]);
        }

        return $taken;
    }

    /**
     * কেন কম পড়ল — দুইটা কারণ, দুইটা আলাদা বার্তা।
     *
     * ── কেন আলাদা ───────────────────────────────────────────────────
     * "লটে যথেষ্ট নেই" সত্যি কথা, কিন্তু দোকানি যখন পর্দায় ১২০ পিস
     * দেখছেন তখন ওই বাক্যটা পড়ে তিনি হিসাবকেই ভুল ভাববেন — আর ভাবাটাই
     * স্বাভাবিক, কারণ মাল সত্যিই তাকে আছে।
     *
     * তফাতটা হলো ওই মাল লট ধরা শুরু হওয়ার আগের। ব্যবস্থার পক্ষে জানার
     * উপায় নেই ওগুলো কোন লটের, আর ধরে নিয়ে বসিয়ে দিলে রিকলের খাতা
     * মিথ্যা হত। তাই বেচা হয় না — কিন্তু কারণটা বলা হয়, আর করণীয়ও।
     */
    private function shortMessage(Product $product, Warehouse $warehouse, string $short): string
    {
        $untracked = (string) StockMovement::query()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $warehouse->id)
            ->whereNull('batch_id')
            ->sum('floor_change');

        if (bccomp($untracked, '0', 4) > 0) {
            return __('inventory::validation.batch_untracked_stock', [
                'product' => $product->name(),
                'qty' => rtrim(rtrim($untracked, '0'), '.'),
            ]);
        }

        return __('inventory::validation.batch_short', [
            'product' => $product->name(),
            'short' => rtrim(rtrim($short, '0'), '.'),
        ]);
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
