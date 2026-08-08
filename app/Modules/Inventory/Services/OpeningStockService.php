<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Accounts\Services\OpeningBalanceService;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * শুরুর দিন তাকে যা ছিল — তিন জায়গায়, একসাথে, নাহলে কোথাও নয়।
 *
 * ── এই ক্লাসটা কেন আলাদা ────────────────────────────────────────────
 * পুরনো হিসাব থেকে ABOS-এ আসার দিন খোলা মজুদ বসাতে তিনটা কাজ করতে হয়,
 * আর তিনটাই করতে হয়:
 *
 *   ১. গুদামে পরিমাণ ঢোকে       → StockService
 *   ২. স্তরে দাম বসে             → CostLayerService  (নইলে প্রথম বিক্রয়েই
 *                                   FIFO জিজ্ঞেস করবে "কত দামে এসেছিল?"
 *                                   আর কোনো উত্তর থাকবে না)
 *   ৩. খতিয়ানে সম্পদ বসে         → OpeningBalanceService
 *
 * তিনটার যেকোনো একটা বাদ পড়লে দুইটা সংখ্যা আলাদা হয়ে যায়, আর কোনটা
 * সত্যি তা বলার উপায় থাকে না। ঠিক এটাই ঘটেছিল: তৃতীয়টা ছিল না, তাই
 * ডিপোর তাকে ৮,৪০,০০০ টাকার মাল থাকত আর ব্যালেন্স শিটে মজুদ শূন্য।
 *
 * তাই তিনটাকে একটা লেনদেনে বেঁধে একটা জায়গায় রাখা হল — যাতে ভবিষ্যতে
 * কেউ নতুন পথ লিখতে গিয়ে একটা ধাপ ভুলে না যায়।
 */
final class OpeningStockService
{
    /** খোলা মজুদের চলাচল ও স্তর এই ধরনেই বসে। */
    public const SOURCE_TYPE = 'opening';

    public const DOCUMENT_NO = 'OPENING';

    public function __construct(
        private readonly StockService $stock,
        private readonly CostLayerService $layers,
        private readonly OpeningBalanceService $opening,
    ) {}

    /**
     * এক পণ্য, এক গুদাম — পরিমাণ ও দর।
     *
     * @throws ValidationException
     */
    public function bringIn(
        Product $product,
        Warehouse $warehouse,
        string $qty,
        string $unitCost,
        Carbon|string|null $date = null,
        ?string $narration = null,
    ): StockMovement {
        $this->assertSane($product, $warehouse, $qty, $unitCost);

        return DB::transaction(function () use ($product, $warehouse, $qty, $unitCost, $date, $narration) {
            $movement = $this->stock->move(
                product: $product,
                warehouse: $warehouse,
                sourceType: self::SOURCE_TYPE,
                sourceId: $product->id,
                floor: $qty,
                date: $date,
                documentNo: self::DOCUMENT_NO,
                narration: $narration ?? __('inventory::message.opening_narration'),
            );

            /*
             * স্তরের ও খতিয়ানের উৎস চলাচলের id, পণ্যের নয়।
             *
             * পণ্যের id দিলে একই পণ্য দুই গুদামে থাকলে দ্বিতীয় দাখিলাটা
             * বাতিল হত — পোস্টিং ইঞ্জিন একই উৎসে দুইবার বসতে দেয় না।
             * সিডারে ঠিক তাই হয়েছিল: নেত্রকোনার ৪০ বস্তা চাল, ১,৩৬,০০০
             * টাকা, নীরবে বাদ। স্তরেও একই কারণ — একই উৎস হলে একটা গুদামের
             * খোলা মজুদ বাতিল করলে অন্যটার স্তরও উঠে যেত।
             */
            $this->layers->receive(
                product: $product,
                qty: $qty,
                unitCost: $unitCost,
                sourceType: self::SOURCE_TYPE,
                sourceId: $movement->id,
                documentNo: self::DOCUMENT_NO,
                date: $date,
            );

            $this->opening->forInventory(
                sourceId: $movement->id,
                documentNo: self::DOCUMENT_NO,
                amount: bcmul($qty, $unitCost, 4),
                date: $date,
            );

            return $movement;
        });
    }

    /** এই পণ্যের এই গুদামে খোলা মজুদ আগেই বসেছে কি না। */
    public function exists(Product $product, Warehouse $warehouse): bool
    {
        return StockMovement::query()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $warehouse->id)
            ->where('source_type', self::SOURCE_TYPE)
            ->exists();
    }

    /**
     * খোলা মজুদ এখনো বসানো যায় কি না।
     *
     * ── কেন লেনদেন শুরু হলে আর নয় ───────────────────────────────────
     * FIFO স্তর টানে বসার ক্রমে (`orderBy('id')`), তারিখে নয়। তাই আজ
     * বসানো খোলা মজুদের স্তরটা সারির **শেষে** গিয়ে দাঁড়াত — অথচ শুরুর
     * দিনের মালই সবার আগে বেরোনোর কথা।
     *
     * ফল হত নীরব আর মারাত্মক: গত মাসের বিক্রয়গুলো ইতিমধ্যেই পরের চালানের
     * দামে খরচ লিখে ফেলেছে, আর খোলা মজুদের সস্তা মালটা তাকে পড়ে থেকে
     * মুনাফাকে বছরের শেষে গিয়ে এলোমেলো করত। কোনো ত্রুটিবার্তা নেই,
     * শুধু ভুল লাভ-ক্ষতি।
     *
     * তাই নিয়মটা সহজ: এই পণ্যের এই গুদামে কোনো নড়াচড়া হয়ে থাকলে খোলা
     * মজুদের সময় পেরিয়ে গেছে। ভুল হলে সমন্বয়ের পর্দা আছে — সেটা কারণ
     * চায়, চিহ্ন রাখে, আর FIFO-কেও সঠিক ক্রমে জানায়।
     */
    public function stillOpen(Product $product, Warehouse $warehouse): bool
    {
        return ! StockMovement::query()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $warehouse->id)
            ->exists();
    }

    /** @throws ValidationException */
    private function assertSane(Product $product, Warehouse $warehouse, string $qty, string $unitCost): void
    {
        if (bccomp($qty, '0', 4) <= 0) {
            throw ValidationException::withMessages([
                'qty' => __('inventory::message.opening_needs_qty'),
            ]);
        }

        /*
         * দর শূন্য হতে পারে না।
         *
         * শূন্য দরের মাল ব্যালেন্স শিটে কোনো সম্পদ নয়, অথচ তাকে আছে —
         * আর বেচলে খরচ শূন্য, অর্থাৎ পুরো বিক্রয়মূল্যটাই মুনাফা। সংখ্যাটা
         * কেউ হাতে না লিখলে ধরাই পড়ত না।
         */
        if (bccomp($unitCost, '0', 4) <= 0) {
            throw ValidationException::withMessages([
                'unit_cost' => __('inventory::message.opening_needs_cost'),
            ]);
        }

        if ($this->exists($product, $warehouse)) {
            throw ValidationException::withMessages([
                'product_id' => __('inventory::message.opening_already_done', [
                    'product' => $product->name(),
                    'warehouse' => $warehouse->name(),
                ]),
            ]);
        }

        if (! $this->stillOpen($product, $warehouse)) {
            throw ValidationException::withMessages([
                'product_id' => __('inventory::message.opening_too_late', [
                    'product' => $product->name(),
                    'warehouse' => $warehouse->name(),
                ]),
            ]);
        }
    }
}
