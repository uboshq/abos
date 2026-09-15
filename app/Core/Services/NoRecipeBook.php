<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Contracts\RecipeBook;

/**
 * রান্নাঘর নেই এমন কোম্পানির রেসিপির বই — খালি, আর সেটাই সঠিক উত্তর।
 *
 * ── ⛔ কেন এই ক্লাসটা না থাকলে ডিপো থেমে যেত ─────────────────────────
 * [[RecipeBook]] চুক্তিটা বাঁধে রেস্তোরাঁ মডিউল। কিন্তু বেশিরভাগ ডিপোতে
 * রেস্তোরাঁ মডিউলই বন্ধ — সেখানে কেউ চুক্তিটা বাঁধে না।
 *
 * ⚠️ বাঁধন না পেলে কনটেইনার ছুঁড়ত, আর [[App\Modules\Sales\Services\SalesInvoiceService]]
 * ওটা ইনজেক্ট করে বলে **প্রতিটা বিক্রয় বিল কাটা বন্ধ হয়ে যেত** — যে
 * ডিপো জীবনে একটা রেসিপিও লেখেনি তার ওখানেও।
 *
 * ⓘ ঠিক এই ফাঁদটা মালিক একবার নিজে ধরেছেন, অন্য চেহারায়: *"ভাড়া না
 * বসলে অনুমোদন হবে না → স্টকে ঢুকবে না → বিক্রি হবে না।"* একটা ঐচ্ছিক
 * জিনিস বাধ্যতামূলক হয়ে গেলে শেকলের শেষ মাথায় রোজকার কাজটা থেমে যায়।
 *
 * ── ⭐ তাই ডিফল্টটা "না" বলে, "জানি না" বলে না ──────────────────────
 * `consumesOnSale()` সবসময় `false` — অর্থাৎ বিক্রয় রেসিপির পথে ঢোকেই
 * না, আর বাকি দুইটা পদ্ধতি কোনোদিন ডাকাই হয় না। ⓘ তবু তারা খালি
 * অ্যারে ফেরত দেয়, ছোঁড়ে না: কেউ সরাসরি ডাকলে "কিছু লাগে না" উত্তরটাই
 * সত্যি, আর একটা ব্যতিক্রম ঐ সত্যটাকে দুর্ঘটনা বানিয়ে দিত।
 */
final class NoRecipeBook implements RecipeBook
{
    public function consumesOnSale(int $productId): bool
    {
        return false;
    }

    /**
     * @return list<array{product_id: int, qty: string}>
     */
    public function needsFor(int $dishId, string $servings): array
    {
        return [];
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
        return [];
    }

    /**
     * ⭐ নিজের স্টকটাই ফেরত — "আমি কিছু বদলাইনি"।
     *
     * ⛔ শূন্য ফেরত দিলে রান্নাঘরহীন ডিপোর **প্রতিটা পণ্য** পর্দায়
     * "স্টক নেই" দেখাত, আর কাউন্টারে কিছুই বেচা যেত না। ⓘ আসল সেবাটাও
     * রেসিপি না পেলে ঠিক এটাই করে, তাই আচরণ দুই জায়গায় এক।
     */
    public function sellableQty(int $productId, string $ownStock, ?int $warehouseId = null): string
    {
        return $ownStock;
    }
}
