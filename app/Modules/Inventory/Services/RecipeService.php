<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Recipe;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * একটা খাবার বিক্রি বা রান্না হলে তার উপকরণগুলো কমায়।
 *
 * ── কেন এটা `StockService`-এর উপরে বসে, তার পাশে নয় ─────────────────
 * স্টক কমানোর পথ এই প্রকল্পে একটাই — `StockService::issue()`। সে ব্যাচ
 * বাছে, FIFO স্তর টানে, মুভমেন্ট লেখে, খতিয়ানে পাঠায়।
 *
 * রেসিপির জন্য আলাদা একটা পথ বানানো সহজ হত, আর সেটাই হত সবচেয়ে বড় ভুল:
 * তখন উপকরণ কমত এক নিয়মে আর বাকি সব কমত আরেক নিয়মে, আর দুইটার একটায়
 * ব্যাচের হিসাব বা FIFO-র দর বাদ পড়ত। ৭ আগস্ট ২০২৬-এ ঠিক ওই ধরনের
 * দ্বৈততাই ধরা পড়েছিল — মাল ঢুকত এক দামে, বেরোত আরেক দামে।
 *
 * তাই এখানে কোনো স্টকের অঙ্ক নেই। এই সেবা কেবল **কতটা, কীসের** ঠিক
 * করে; কমানোটা `StockService`-এরই কাজ।
 *
 * ── কেন `to_order` আর `batch` একই সেবায় ─────────────────────────────
 * দুইটার তফাত কেবল **কখন** ডাকা হবে, কী ঘটবে তাতে নয়। অর্ডারে-রান্নায়
 * বিক্রির সময় ডাকা হয়; হাঁড়িতে-রান্নায় উৎপাদনের সময়। ভেতরের কাজ এক:
 * রেসিপির লাইনগুলো ধরে উপকরণ কমানো।
 */
final class RecipeService
{
    public function __construct(
        private readonly StockService $stock,
    ) {}

    /**
     * এই পণ্যের রেসিপি, যদি থাকে ও সচল থাকে।
     *
     * নিষ্ক্রিয় রেসিপি ফেরত দেওয়া হয় না: ওটা ইতিহাসের জন্য রাখা, আজকের
     * বিক্রির জন্য নয়।
     */
    public function forProduct(Product $product): ?Recipe
    {
        return Recipe::query()
            ->with('lines.product')
            ->where('product_id', $product->id)
            ->where('is_active', true)
            ->first();
    }

    /**
     * এই খাবারটা বিক্রি হলে উপকরণ কমবে কি না।
     *
     * ── কেন এটা আলাদা একটা প্রশ্ন ────────────────────────────────────
     * হাঁড়িতে-রান্না খাবারের বিক্রিতে উপকরণ কমে **না** — ওগুলো রান্নার
     * সময়ই কমে গেছে। বিক্রিতে কমে তৈরি খাবারটা নিজেই, আর সেটা সাধারণ
     * পণ্যের মতোই।
     *
     * দুইবার কমালে চাল দুইবার খরচ হত: একবার হাঁড়ি চড়ানোর সময়, আরেকবার
     * প্রতি প্লেট বিক্রির সময়। এক সপ্তাহে স্টক শূন্যে নেমে যেত যদিও
     * বস্তা গুদামেই আছে।
     */
    public function consumesOnSale(Product $product): bool
    {
        return $this->forProduct($product)?->isMadeToOrder() === true;
    }

    /**
     * এতগুলো খাবারের জন্য যা যা কমবে — কমানোর আগে।
     *
     * ── কেন হিসাবটা কমানো থেকে আলাদা ─────────────────────────────────
     * একই হিসাব দুই জায়গায় লাগে: কমানোর সময়, আর খরচের রিপোর্টে "এই
     * প্লেটে কত টাকার মাল গেল" দেখাতে। দুইবার লিখলে একদিন একটা বদলাত
     * আর অন্যটা থেকে যেত, আর রিপোর্ট বলত এক কথা স্টক বলত আরেক।
     *
     * @return list<array{product: Product, qty: string}>
     */
    public function needsFor(Recipe $recipe, string $servings): array
    {
        $needs = [];

        foreach ($recipe->lines as $line) {
            $qty = bcmul($recipe->perUnit($line), $servings, 6);

            /*
             * শূন্য পরিমাণ বাদ — কমানোর কিছু নেই।
             *
             * এটা ঘটে যখন কেউ একটা লাইনে ০ লিখে রাখেন ("পরে ঠিক করব")।
             * শূন্যের জন্য মুভমেন্ট লিখলে খতিয়ানে অর্থহীন সারি জমত।
             */
            if (bccomp($qty, '0', 6) <= 0) {
                continue;
            }

            $needs[] = ['product' => $line->product, 'qty' => $qty];
        }

        return $needs;
    }

    /**
     * উপকরণগুলো গুদাম থেকে কমিয়ে দেয়।
     *
     * ── কেন গোটাটা একটা লেনদেনে ──────────────────────────────────────
     * একটা বিরিয়ানিতে চাল কমল, মাংস কমল, তারপর তেলের সারিতে ব্যর্থ হলো।
     * লেনদেন ছাড়া চাল-মাংস কমেই থাকত আর তেল অক্ষত — অর্থাৎ স্টক এমন
     * একটা অবস্থায় বসত যা কোনো বাস্তব ঘটনার সাথে মেলে না, আর কেউ জানত
     * না কোনটা কমেছে কোনটা কমেনি।
     *
     * @return list<array{product: Product, qty: string}> যা যা কমল
     */
    public function consume(
        Recipe $recipe,
        string $servings,
        Warehouse $warehouse,
        string $sourceType,
        int $sourceId,
        Carbon|string|null $date = null,
        ?string $documentNo = null,
    ): array {
        $needs = $this->needsFor($recipe, $servings);

        if ($needs === []) {
            return [];
        }

        return DB::transaction(function () use (
            $needs, $warehouse, $sourceType, $sourceId, $date, $documentNo
        ) {
            foreach ($needs as $need) {
                $this->stock->issue(
                    product: $need['product'],
                    warehouse: $warehouse,
                    sourceType: $sourceType,
                    sourceId: $sourceId,
                    qty: $need['qty'],
                    date: $date,
                    documentNo: $documentNo,
                );
            }

            return $needs;
        });
    }
}
