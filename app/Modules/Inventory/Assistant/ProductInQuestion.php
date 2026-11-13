<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Assistant;

use App\Modules\Inventory\Models\Product;

/**
 * ⓘ প্রশ্নের ভেতরের পণ্য — মডেল নাম দিলে সেটা, নইলে প্রশ্নে যে পণ্যের নাম (বাংলা বা ইংরেজি) আছে; কয়েকটা মিললে সবচেয়ে লম্বা নাম
 *   ("চিনি ৫০ কেজি" "চিনি"-র চেয়ে নিশ্চিত)। কোনোটা না মিললে null — আন্দাজ নয়।
 */
final class ProductInQuestion
{
    /** @param  array<string, mixed>  $args */
    public static function find(array $args): ?Product
    {
        if (is_numeric($args['product_id'] ?? null)) {
            return Product::query()->whereKey((int) $args['product_id'])->first();
        }

        $text = self::plain((string) ($args['product'] ?? $args['q'] ?? ''));

        if (mb_strlen($text) < 2) {
            return null;
        }

        /*
         * ⓘ নামের শব্দ ধরে মেলানো — "সয়াবিন তেল কত আছে" প্রশ্নে "সয়াবিন তেল ৫ লিটার" পণ্য: নামের যত শব্দ (৩ অক্ষর বা বেশি) প্রশ্নে আছে,
         *   তাদের মোট দৈর্ঘ্য যার বেশি সেটা; পুরো নাম বা কোড মিললে সবার আগে। বাংলার যুক্ত অক্ষর এক রূপে (NFC) মেলানো।
         */
        $best = null;
        $bestScore = 0;
        // ⓘ মডেলের আলাদা করা নামের শব্দ (২ অক্ষর বা বেশি, সংখ্যা নয়) — পুরো প্রশ্ন হলে খালি, তখন আগের নিয়ম
        $asked = trim((string) ($args['product'] ?? '')) === '' ? [] : self::words($text);

        foreach (Product::query()->where('is_active', true)->get(['id', 'name_en', 'name_bn', 'code']) as $p) {
            foreach ([$p->name_bn, $p->name_en, $p->code] as $name) {
                $name = self::plain((string) $name);

                if (mb_strlen($name) < 2) {
                    continue;
                }

                $score = str_contains($text, $name) ? 1000 + mb_strlen($name) : 0;

                /*
                 * ⛔ মডেল পণ্যের নামটা আলাদা করে দিলে, সেই নামের প্রতিটা শব্দ পণ্যের নামে থাকা চাই — একটা শব্দ মিললেই নয়
                 *   (১২ নভেম্বর ২০২৬: "চিনির গুঁড়া" প্রশ্নে "গুঁড়া দুধ" উঠে আসছিল)। "চিনির" → "চিনি" — শব্দের শুরু মিললেই চলে।
                 */
                if ($score < 1000 && $asked !== [] && ! self::coversEvery($asked, $name)) {
                    continue;
                }

                // ⓘ সব শব্দ মিলেছে — ছোট নামও ("চা") গণ্য; নিচের শব্দ-গোনা কেবল ৩ অক্ষর বা বেশি দেখে
                if ($asked !== []) {
                    $score += array_sum(array_map('mb_strlen', $asked));
                }

                foreach (preg_split('/\s+/u', $name) ?: [] as $word) {
                    if (mb_strlen($word) >= 3 && ! is_numeric($word) && str_contains($text, $word)) {
                        $score += mb_strlen($word);
                    }
                }

                if ($score > $bestScore) {
                    [$best, $bestScore] = [$p, $score];
                }
            }
        }

        return $best;
    }

    /**
     * ⭐ পণ্য না পেলে কী বলা — নাম দেওয়া থাকলে সেই নামটাই ("'চিনি' নামে কোনো পণ্য পাইনি"), নাম না থাকলে "কোন পণ্য?"
     *   (১২ নভেম্বর ২০২৬: ডেমোতে চিনিই নেই, অথচ উত্তর বলছিল "বুঝিনি" — মানুষ ভাবতেন প্রশ্ন বোঝেনি)।
     *
     * @param  array<string, mixed>  $args
     */
    public static function notFound(array $args): string
    {
        $asked = trim((string) ($args['product'] ?? $args['q'] ?? ''));

        return mb_strlen($asked) >= 2
            ? (string) __('inventory::assistant.no_such_product', ['name' => mb_substr($asked, 0, 60)])
            : (string) __('inventory::assistant.no_product');
    }

    /** @return list<string> */
    private static function words(string $text): array
    {
        return array_values(array_filter(preg_split('/[\s,।\-\/]+/u', $text) ?: [],
            fn (string $w): bool => mb_strlen($w) >= 2 && ! is_numeric($w)));
    }

    /**
     * প্রতিটা চাওয়া শব্দ নামের কোনো শব্দ দিয়ে শুরু হয় কি না — "চিনির" ⊃ "চিনি", "sugar" = "sugar".
     *
     * @param  list<string>  $asked
     */
    private static function coversEvery(array $asked, string $name): bool
    {
        $have = self::words($name);

        foreach ($asked as $want) {
            $hit = false;

            foreach ($have as $word) {
                // ⓘ কেবল এক দিকে — চাওয়া শব্দে বিভক্তি থাকতে পারে ("চিনির" ⊃ "চিনি"); উল্টো দিকে "চা" "চাল"-এ মিলে যেত
                if (str_starts_with($want, $word)) {
                    $hit = true;

                    break;
                }
            }

            if (! $hit) {
                return false;
            }
        }

        return true;
    }

    private static function plain(string $text): string
    {
        $text = class_exists(\Normalizer::class) ? (string) \Normalizer::normalize($text, \Normalizer::FORM_C) : $text;

        return mb_strtolower(trim($text));
    }
}
