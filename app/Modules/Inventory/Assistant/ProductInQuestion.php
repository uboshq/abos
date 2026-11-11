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

        foreach (Product::query()->where('is_active', true)->get(['id', 'name_en', 'name_bn', 'code']) as $p) {
            foreach ([$p->name_bn, $p->name_en, $p->code] as $name) {
                $name = self::plain((string) $name);

                if (mb_strlen($name) < 2) {
                    continue;
                }

                $score = str_contains($text, $name) ? 1000 + mb_strlen($name) : 0;

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

    private static function plain(string $text): string
    {
        $text = class_exists(\Normalizer::class) ? (string) \Normalizer::normalize($text, \Normalizer::FORM_C) : $text;

        return mb_strtolower(trim($text));
    }
}
