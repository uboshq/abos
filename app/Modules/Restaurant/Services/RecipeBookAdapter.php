<?php

declare(strict_types=1);

namespace App\Modules\Restaurant\Services;

use App\Core\Contracts\RecipeBook;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;

/**
 * [[RecipeBook]] চুক্তির আসল বাস্তবায়ন — [[RecipeService]]-এর উপর একটা পাতলা আবরণ।
 *
 * ── ⭐ কেন এই ক্লাসটা লাগল, ১৫ সেপ্টেম্বর ২০২৬ ──────────────────────
 * মালিকের সিদ্ধান্ত: রেসিপি রান্নাঘরে যাবে। কিন্তু বিক্রয় রেসিপি পড়ে,
 * আর বিক্রয় রেস্তোরাঁর উপর দাঁড়াতে পারে না — তাই মাঝখানে কোরের একটা
 * চুক্তি বসেছে, আর এটা তার বাস্তবায়ন।
 *
 * ── ⛔ কেন এটা **আজই** দরকার, রেসিপি সরানোর আগেই ────────────────────
 * বিক্রয়কে চুক্তিতে সরানোর পর কেউ আসল বাস্তবায়নটা না বাঁধলে সে
 * [[App\Core\Services\NoRecipeBook]] পেত — আর তখন **প্রতিটা
 * মেড-টু-অর্ডার খাবারের বিক্রয়যোগ্য শূন্য** হয়ে যেত, উপকরণও কাটা হত না।
 *
 * ⚠️ পরিকল্পনায় এই ধাপটা পরে রাখা ছিল, আর পরীক্ষায় সাথে সাথেই ধরা
 * পড়েছে: আটটা দাবি লাল, সবগুলোরই বার্তা *"বিক্রয়যোগ্য আছে ০"*। ⓘ
 * অর্থাৎ "ইনজেকশন বদলাও, আচরণ অপরিবর্তিত" কথাটা বাঁধন ছাড়া সম্ভব নয়।
 *
 * ── ⓘ কেন এটা এখন মজুদে, আর কবে সরবে ────────────────────────────────
 * [[RecipeService]] এখনো মজুদে, তাই তাকে যে চেনে সেও মজুদেই থাকতে হবে।
 * রেসিপি রেস্তোরাঁয় গেলে এই ফাইলটাও তার সাথে যাবে, আর বাঁধনের ঘোষণাটা
 * `Inventory/module.php` থেকে `Restaurant/module.php`-এ সরবে।
 *
 * ⭐ বিক্রয়ের কোডে তখন **একটা অক্ষরও বদলাবে না** — সেটাই চুক্তিটার পুরো
 * উদ্দেশ্য।
 */
final class RecipeBookAdapter implements RecipeBook
{
    public function __construct(private readonly RecipeService $recipes) {}

    public function consumesOnSale(int $productId): bool
    {
        $product = Product::query()->find($productId);

        return $product !== null && $this->recipes->consumesOnSale($product);
    }

    /**
     * @return list<array{product_id: int, qty: string}>
     */
    public function needsFor(int $dishId, string $servings): array
    {
        $recipe = $this->recipeOf($dishId);

        if ($recipe === null) {
            return [];
        }

        return array_map(
            fn (array $need) => [
                'product_id' => (int) $need['product']->id,
                'qty' => (string) $need['qty'],
            ],
            $this->recipes->needsFor($recipe, $servings),
        );
    }

    /**
     * @return list<array{product_id: int, qty: string}>
     */
    public function consume(
        int $dishId,
        string $servings,
        int $warehouseId,
        string $sourceType,
        int $sourceId,
        ?string $date = null,
        ?string $documentNo = null,
    ): array {
        $recipe = $this->recipeOf($dishId);
        $warehouse = Warehouse::query()->find($warehouseId);

        /*
         * ⚠️ চুপচাপ ফিরে আসা — আর এটা ইচ্ছাকৃত।
         *
         * ⓘ ডাকার জায়গা ([[App\Modules\Sales\Services\SalesInvoiceService]])
         * এর আগেই দুইটা শর্ত দেখে নিয়েছে: রেসিপি সম্পূর্ণ কি না, আর
         * গুদাম আছে কি না। ⛔ এখানে আবার ছুঁড়লে একই ভুলের দুইটা বার্তা
         * হত, আর দ্বিতীয়টা ব্যবহারকারীর ভাষায় নয়।
         */
        if ($recipe === null || $warehouse === null) {
            return [];
        }

        return array_map(
            fn (array $taken) => [
                'product_id' => (int) $taken['product']->id,
                'qty' => (string) $taken['qty'],
            ],
            $this->recipes->consume(
                recipe: $recipe,
                servings: $servings,
                warehouse: $warehouse,
                sourceType: $sourceType,
                sourceId: $sourceId,
                date: $date,
                documentNo: $documentNo,
            ),
        );
    }

    public function sellableQty(int $productId, string $ownStock, ?int $warehouseId = null): string
    {
        $product = Product::query()->find($productId);

        if ($product === null) {
            return $ownStock;
        }

        return $this->recipes->sellableQty(
            $product,
            $ownStock,
            $warehouseId === null ? null : Warehouse::query()->find($warehouseId),
        );
    }

    /**
     * খাবারটার চলতি রেসিপি — না থাকলে `null`।
     *
     * ⓘ তিনটা পদ্ধতিই এটা চায়, তাই এক জায়গায়। পণ্যটা না পাওয়া গেলেও
     * `null` — মুছে ফেলা পণ্যের রেসিপি খোঁজা অর্থহীন।
     */
    private function recipeOf(int $dishId): mixed
    {
        $product = Product::query()->find($dishId);

        return $product === null ? null : $this->recipes->forProduct($product);
    }
}
