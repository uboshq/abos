<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * তাকে আছে, অথচ বেচা যায় না — যে মালের লট জানা নেই।
 *
 * ── ⛔ যে ফাঁদটা এখানে ছিল ───────────────────────────────────────────
 * একটা পণ্যে লট ধরা চালু করলে [[BatchAllocator]] কেবল লট-ওয়ালা সারি
 * দেখে। ⚠️ তার আগে ঢোকা মালের সারিতে `batch_id` খালি, তাই ওগুলো
 * মজুদের যোগফলে থাকে অথচ বাছাইয়ে আসে না — **তাকে আছে, খাতায় আছে,
 * বিক্রয়ে নেই**।
 *
 * ⓘ [[BatchAllocator]] ঠিক এই অবস্থাটা চিনে আলাদা বার্তা দেয়, আর
 * করণীয়ও বলে। ⛔ কিন্তু বার্তাটা পাঠাত খোলা মজুদের পর্দায়, আর ঐ দরজা
 * **দুইবার বন্ধ**: ওখানে লটের কোনো ঘরই নেই, আর
 * [[OpeningStockService]]-র `stillOpen()` কোনো চলাচল থাকলেই ফিরিয়ে দেয়
 * — আটকে থাকা মালের তো চলাচল আছেই।
 *
 * ⚠️ অর্থাৎ ত্রুটিবার্তাটা এমন একটা দরজার নাম বলত যেটা খোলে না। এই
 * ক্লাসটা সেই দরজা।
 *
 * ── ⓘ কেন এটা খোলা মজুদ নয় ─────────────────────────────────────────
 * খোলা মজুদ মাল **আনে** — পরিমাণ বাড়ে, স্তরে দাম বসে, খতিয়ানে সম্পদ
 * বসে। ⛔ এখানে মাল আনা হচ্ছে না, মালটা আগে থেকেই তাকে। বাড়তি সারি
 * বসালে গুদামে মাল দ্বিগুণ হত আর ব্যালেন্স শিটে মজুদ দ্বিগুণ।
 *
 * ⭐ তাই এটা একটা **নাম বসানো**, আনা নয়: লট ছাড়া ঘর থেকে ততটা বাদ, আর
 * ঠিক ততটা লটের ঘরে যোগ। ⓘ পরিমাণ অপরিবর্তিত, দাম অপরিবর্তিত,
 * খতিয়ান অস্পর্শিত — FIFO-র স্তরগুলোও তাই ক্রমেই থাকে।
 */
final class StrandedStock
{
    /** লট বসানোর উৎস — ড্রিল-ডাউনে চেনা যায়। */
    public const SOURCE_TYPE = 'lot_assignment';

    public const DOCUMENT_NO = 'LOT';

    public function __construct(private readonly StockService $stock) {}

    /**
     * এই পণ্যের কতটা মাল লট ছাড়া পড়ে আছে — বিক্রয়ের ঘরে।
     *
     * ⚠️ যোগটা `floor_change`-এর, বসেনি-ঘরের নয়। ⓘ বসেনি-ঘরের মাল
     * এখনো বিক্রয়যোগ্যই নয়, তাই সে আটকে নেই — সে বসার অপেক্ষায়, আর
     * বসানোর পর্দাই তাকে লট দেবে।
     */
    public function onFloor(Product $product, Warehouse $warehouse): string
    {
        return $this->untracked($product, $warehouse, 'floor_change');
    }

    /** আর ফ্রি ভাণ্ডারে কতটা। */
    public function inFreePool(Product $product, Warehouse $warehouse): string
    {
        return $this->untracked($product, $warehouse, 'free_change');
    }

    /**
     * আটকে থাকা মালকে একটা লট দেওয়া।
     *
     * ── ⚠️ কেন দুইটা সারি, একটা সম্পাদনা নয় ─────────────────────────
     * পুরনো সারির `batch_id` ঘরে লিখে দেওয়া সবচেয়ে সহজ, আর সবচেয়ে
     * খারাপ: ⛔ ঐ সারিটা একটা **ঘটনার স্মৃতি** — অমুক দিন অমুক চালানে
     * মাল ঢুকেছিল, আর তখন লট জানা ছিল না। ⓘ ওটা বদলে দিলে খাতা বলত
     * লটটা সেদিনই জানা ছিল, যা মিথ্যা, আর রিকলের তদন্তে ঐ মিথ্যাটাই
     * সবচেয়ে দামি সারি।
     *
     * ⭐ তাই আজকের তারিখে দুইটা নতুন সারি: লট ছাড়া ঘর থেকে বিয়োগ, লটের
     * ঘরে যোগ। ⓘ যোগফল শূন্য, তাই মজুদ এক চুলও নড়ে না, অথচ কবে নামটা
     * বসানো হলো তা খাতায় থেকে যায়।
     *
     * @return list<StockMovement> প্রথমে বিয়োগ, তারপর যোগ
     *
     * @throws ValidationException
     */
    public function giveItALot(
        Product $product,
        Warehouse $warehouse,
        Batch $batch,
        string $qty,
        string $freeQty = '0',
        Carbon|string|null $date = null,
        ?string $narration = null,
    ): array {
        $this->assertSane($product, $warehouse, $batch, $qty, $freeQty);

        return DB::transaction(function () use ($product, $warehouse, $batch, $qty, $freeQty, $date, $narration) {
            $why = $narration ?? __('inventory::message.lot_assigned_narration', ['lot' => $batch->batch_no]);

            /*
             * ⚠️ ক্রমটা গুরুত্বপূর্ণ — আগে বিয়োগ, তারপর যোগ।
             *
             * ⓘ উল্টো করলে মাঝের মুহূর্তে গুদামে মাল বেশি দেখাত। ⛔
             * লেনদেনের ভিতরে বলে বাইরে থেকে দেখা যেত না, কিন্তু নিয়মটা
             * লেনদেনের উপর নির্ভর করে থাকা আর নিজে ঠিক থাকা এক নয়।
             */
            $rows = [];

            $rows[] = $this->stock->move(
                product: $product, warehouse: $warehouse,
                sourceType: self::SOURCE_TYPE, sourceId: $batch->id,
                floor: bcmul($qty, '-1', 4),
                date: $date, documentNo: self::DOCUMENT_NO, narration: $why,
                free: bcmul($freeQty, '-1', 4),
            );

            $rows[] = $this->stock->move(
                product: $product, warehouse: $warehouse,
                sourceType: self::SOURCE_TYPE, sourceId: $batch->id,
                floor: $qty,
                date: $date, documentNo: self::DOCUMENT_NO, narration: $why,
                free: $freeQty,
                batch: $batch,
            );

            return $rows;
        });
    }

    /**
     * এই ঘরে লট ছাড়া কতটা — ধনাত্মক না হলে শূন্য।
     *
     * ⚠️ যোগফলটা ঋণাত্মক হতে পারে, আর সেটা ভুল নয়: ⓘ লট ধরা চালু
     * হওয়ার আগে বেরোনো মালের সারিগুলোও লট ছাড়া। ⛔ ঋণাত্মক সংখ্যাটা
     * ফেরত দিলে ডাকা কোড ওটাকে "এতটা বসানো যায়" পড়ত, আর তুলনাটা
     * উল্টে যেত।
     */
    private function untracked(Product $product, Warehouse $warehouse, string $column): string
    {
        $sum = (string) StockMovement::query()
            ->forProduct($product->id)
            ->inWarehouse($warehouse->id)
            ->whereNull('batch_id')
            ->selectRaw("COALESCE(SUM({$column}), 0) as total")
            ->value('total');

        return bccomp($sum, '0', 4) > 0 ? bcadd($sum, '0', 4) : '0';
    }

    /** @throws ValidationException */
    private function assertSane(
        Product $product,
        Warehouse $warehouse,
        Batch $batch,
        string $qty,
        string $freeQty,
    ): void {
        /*
         * ⛔ লটটা এই পণ্যেরই হতে হবে।
         *
         * ⚠️ আলাদা পণ্যের লট বসালে রিকলের খাতা **উল্টো দিকে** মিথ্যা
         * বলত: যে লটের ফোন করার কথা নয় তার ক্রেতাদের ফোন যেত, আর যাঁদের
         * যাওয়ার কথা তাঁরা বাদ পড়তেন।
         */
        if ($batch->product_id !== $product->id) {
            throw ValidationException::withMessages([
                'batch_id' => __('inventory::validation.lot_of_another_product', [
                    'lot' => $batch->batch_no,
                    'product' => $product->name(),
                ]),
            ]);
        }

        if (bccomp($qty, '0', 4) < 0 || bccomp($freeQty, '0', 4) < 0) {
            throw ValidationException::withMessages([
                'qty' => __('inventory::validation.lot_needs_qty'),
            ]);
        }

        if (bccomp($qty, '0', 4) <= 0 && bccomp($freeQty, '0', 4) <= 0) {
            throw ValidationException::withMessages([
                'qty' => __('inventory::validation.lot_needs_qty'),
            ]);
        }

        /*
         * ⛔ যতটা লট ছাড়া পড়ে আছে, তার বেশি নয়।
         *
         * ⚠️ [[StockService]]-র নিজের পাহারা এটা ধরত **না**: সে মোট
         * ফ্লোর দেখে, আর মোট ফ্লোরে লট-ওয়ালা মালও গোনা। ⓘ তাই বেশি
         * বসালে লট-ওয়ালা মাল আবার নতুন লটে চলে যেত — একই কার্টন দুই
         * লটে, আর রিকলে দুইবার।
         */
        $floor = $this->onFloor($product, $warehouse);

        if (bccomp($qty, $floor, 4) > 0) {
            throw ValidationException::withMessages([
                'qty' => __('inventory::validation.lot_over_untracked', [
                    'product' => $product->name(),
                    'warehouse' => $warehouse->name(),
                    'have' => rtrim(rtrim($floor, '0'), '.'),
                ]),
            ]);
        }

        $free = $this->inFreePool($product, $warehouse);

        if (bccomp($freeQty, $free, 4) > 0) {
            throw ValidationException::withMessages([
                'free_qty' => __('inventory::validation.lot_over_untracked_free', [
                    'product' => $product->name(),
                    'warehouse' => $warehouse->name(),
                    'have' => rtrim(rtrim($free, '0'), '.'),
                ]),
            ]);
        }
    }
}
