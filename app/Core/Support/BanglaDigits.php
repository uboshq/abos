<?php

declare(strict_types=1);

namespace App\Core\Support;

/**
 * বাংলা নকশার কাগজে বাংলা অঙ্ক — মালিক, ১০ অক্টোবর ২০২৬: *"বাংলা নকশার বিলে বাংলা সংখ্যা, ইংরেজি নকশার বিলে ইংরেজি সংখ্যা"*।
 *
 * ⓘ টাকা, তারিখ, নম্বর সব জায়গায় ইংরেজি অঙ্কে সাজানো হয় ([[Money]], [[DateFormat]]); বাংলা নকশা ছাপা শেষে নিজের লেখার
 * অঙ্কগুলো একবারে বদলায় ([[inText()]])। তাই এক কাগজে দুই রকম অঙ্ক থাকে না — প্রতিষ্ঠানের ফোনও বাংলা অঙ্কে
 * ([[OnePaperShouldNotCarryTwoKindsOfDigitsTest]])।
 */
final class BanglaDigits
{
    private const MAP = ['0' => '০', '1' => '১', '2' => '২', '3' => '৩', '4' => '৪', '5' => '৫', '6' => '৬', '7' => '৭', '8' => '৮', '9' => '৯'];

    public static function of(string $text): string
    {
        return strtr($text, self::MAP);
    }

    /**
     * ⚠️ কেবল পড়ার লেখা বদলায়: ট্যাগের ভিতর (CSS-এর মাপ, ছবির ঠিকানা, বারকোডের মান), `<style>`/`<script>` আর
     * `&#2453;`-এর মতো চিহ্ন যেমন আছে তেমন থাকে — নইলে কাগজের নকশা আর বারকোড ভাঙত।
     */
    public static function inText(string $html): string
    {
        return (string) preg_replace_callback(
            '/(<style\b.*?<\/style>|<script\b.*?<\/script>|<[^>]*>|&#?[a-zA-Z0-9]+;)|[0-9]+/su',
            static fn (array $m): string => ($m[1] ?? '') !== '' ? $m[0] : self::of($m[0]),
            $html,
        );
    }
}
