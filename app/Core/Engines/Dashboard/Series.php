<?php

declare(strict_types=1);

namespace App\Core\Engines\Dashboard;

use App\Core\Support\Money;
use InvalidArgumentException;

/**
 * সময়ের সাথে দুইটা রেখা — যেমন মাসে মাসে ঢোকা আর বেরোনো।
 *
 * ── কেন দুইটা মান, একটা নয় ───────────────────────────────────────────
 * একটা রেখা প্রায়ই সমান থাকে আর চোখে কিছুই বলে না। দুইটা পাশাপাশি
 * রাখলে **ব্যবসাটা দেখা যায়**: কোন মাসে বেশি এসেছে, কোন মাসে বেশি
 * গেছে, আর ফারাকটা বাড়ছে না কমছে।
 *
 * ── কেন প্রতিটা ধাপ তালিকায় থাকতেই হয় ───────────────────────────────
 * কেবল যেসব মাসে সারি আছে সেগুলো দেখালে ফাঁকা মাসগুলো **উধাও** হত,
 * আর সাতটা বারের বদলে পাঁচটা দেখে কেউ ভাবতেন ব্যবসা সাত মাস চলেনি।
 * তাই শূন্য মাসও একটা ধাপ, শূন্য মান নিয়ে।
 */
final class Series
{
    /**
     * ⓘ ঐচ্ছিক: `firstNote`/`secondNote` বারের মাথায় লেখা, `firstTitle`/`secondTitle` মাউস রাখলে (১ অক্টোবর ২০২৬)।
     *
     * @param  list<array{label: string, first: string, second: string, firstNote?: string, secondNote?: string, firstTitle?: string, secondTitle?: string}>  $points
     */
    /** ⓘ সময়ের ধারার তিন আন্তর্জাতিক রূপ — রেখা, ভরা রেখা, পাশাপাশি স্তম্ভ */
    public const CHARTS = ['line', 'area', 'bars'];

    /**
     * ⭐ চার্টের প্রতিটা লেখার মাপ, পিক্সেলে — অক্ষ, নামের তালিকা, মান, ডোনাটের মাঝখান (মালিকের মান: Fiori + Material 3 +
     * WCAG 2.2 AA, ২০ অক্টোবর ২০২৬)। ⛔ আগে SVG-র ভিতরে ৯.৫ "একক" লেখা হত, আর SVG চওড়ায় ছোট হলে লেখাও ছোট হত —
     * হোমে প্রায় ৮px। এখন লেখা HTML-এ, আসল পিক্সেলে, কখনো এর নিচে নয়।
     */
    public const TEXT_PX = 12;

    /**
     * ⓘ আঁকার জায়গার আন্দাজি চওড়া (পিক্সেলে, ১০৮০p-তে সবচেয়ে সরু অবস্থায়) — কতগুলো মান পাশাপাশি ধরে তা আঁচ করতে
     * ([[fits()]])। হোমের ছোট কার্ড, মডিউলের দুই ঘরের চার্ট, পুরো সারির চার্ট।
     */
    public const PLOT_PX = ['compact' => 300, 'normal' => 640, 'wide' => 1000];

    public function __construct(
        public readonly string $label,
        public readonly array $points,
        public readonly string $firstLabel,
        public readonly string $secondLabel,
        /** ⭐ চার্টের ধরন — `line` (ডিফল্ট), `area` বা `bars` (মালিক, ৪ অক্টোবর ২০২৬: "vino rokomer graph") */
        public readonly string $chart = 'line',
        /**
         * ⭐ কোন তারিখ থেকে কোন তারিখ — মালিক, ৫ অক্টোবর ২০২৬: *"kobe theke kobe porjonto eta likhbe"*।
         * ⓘ [[DateRange::label()]] দিয়ে বানানো লেখা ("১ অক্টো – ৫ অক্টো ২০২৬"); চার্টের নিচে বসে। `null` মানে সময়ের নয় (যেমন তালিকা কতটা ভরা)।
         */
        public readonly ?string $range = null,
        /**
         * ⭐ মানগুলো টাকা — তখন মাউস বা কিবোর্ডে দেখানো পুরো অঙ্কের পরে "টাকা" (২০ অক্টোবর ২০২৬: "14,52,300.00 টাকা")।
         * ⓘ গোনা (কাগজ কয়টা, কুপন কয়টা) বা পরিমাণ হলে `false` — সেখানে "টাকা" লেখা ভুল হত।
         */
        public readonly bool $money = false,
    ) {
        if (! in_array($chart, self::CHARTS, true)) {
            throw new InvalidArgumentException("Series '{$label}' asks for an unknown chart '{$chart}'.");
        }

        if ($points === []) {
            throw new InvalidArgumentException("Series '{$label}' has no points, so it can only draw an empty box.");
        }

        foreach ($points as $point) {
            foreach (['label', 'first', 'second'] as $key) {
                if (! array_key_exists($key, $point)) {
                    throw new InvalidArgumentException("Series '{$label}' has a point missing '{$key}'.");
                }
            }
        }
    }

    /**
     * সবচেয়ে বড় মান — বারগুলোর উচ্চতা এর সাপেক্ষে।
     *
     * শূন্য হলে ১ ফেরে: সব মান শূন্য হলে ভাগ করার সময় শূন্য দিয়ে ভাগ
     * হত, আর পর্দাটা ৫০০ দিত — অথচ "কিছুই নড়েনি" একটা বৈধ অবস্থা।
     */
    public function peak(): float
    {
        $peak = 0.0;

        foreach ($this->points as $point) {
            $peak = max($peak, (float) $point['first'], (float) $point['second']);
        }

        return $peak > 0 ? $peak : 1.0;
    }

    /**
     * ⭐ দণ্ডের মাথার মান — মালিক, ৪ অক্টোবর ২০২৬: *"sob chart ei velue dio"*।
     *
     * ⓘ পুরো অঙ্ক দণ্ডের মাথায় ধরে না ("1,23,45,678.00"), তাই হাজার/লাখ/কোটিতে এক দশমিক: 14.5 লাখ।
     * ⭐ একক পুরো শব্দে — "হাজার", "লাখ", "কোটি", "হা/লা/কো" নয় (২০ অক্টোবর ২০২৬); শব্দগুলো `core.dashboard.short_*`-এ।
     * ⓘ পুরো অঙ্কটা দণ্ডের title-এ থেকে যায়; এটা কেবল চোখে পড়ার জন্য — হিসাবে কোথাও ব্যবহার হয় না।
     */
    public static function short(int|float|string|null $value): string
    {
        $v = (float) str_replace(',', '', (string) $value);
        $abs = abs($v);

        foreach ([[1e7, 'crore'], [1e5, 'lakh'], [1e3, 'thousand']] as [$size, $unit]) {
            if ($abs >= $size) {
                $n = round($v / $size, 1);

                return rtrim(rtrim(number_format($n, 1, '.', ''), '0'), '.').' '.__('core.dashboard.short_'.$unit);
            }
        }

        return rtrim(rtrim(number_format(round($v, 1), 1, '.', ''), '0'), '.');
    }

    /**
     * ⭐ পুরো অঙ্ক — মাউস রাখলে বা কিবোর্ডে এলে যা দেখায়: লাখ-কোটির কমা, ইংরেজি অঙ্ক ([[Money::format()]])।
     * ⓘ দশমিক থাকলে দুই ঘর ("1452300.0000" → "14,52,300.00"), না থাকলে পূর্ণসংখ্যা ("12" → "12")। অঙ্ক না হলে যেমন আছে।
     */
    public static function full(int|float|string|null $value): string
    {
        $plain = str_replace(',', '', trim((string) $value));

        if ($plain === '' || ! is_numeric($plain)) {
            return (string) $value;
        }

        return Money::format($plain, str_contains($plain, '.') ? 2 : 0);
    }

    /**
     * ⓘ সবগুলো লেখা কি নিজের ঘরে পাশাপাশি ধরে — না ধরলে কেবল সবচেয়ে বড়টার মান দেখায়, বাকিগুলো মাউস/কিবোর্ডে
     * (২০ অক্টোবর ২০২৬: খাড়া বা ঘেঁষাঘেঁষি লেখা নয়)। আন্দাজ ইচ্ছে করে বড়: প্রতি অক্ষর ০.৬৫ em, দুই পাশে ৪px ফাঁক।
     *
     * @param  list<string>  $texts
     */
    public static function fits(array $texts, float $slotPx): bool
    {
        foreach ($texts as $text) {
            if (self::widthPx($text) > $slotPx) {
                return false;
            }
        }

        return true;
    }

    /**
     * ⓘ অক্ষের নাম কয়টা পরপর লেখা যায় — সব না ধরলে প্রতি ২য়/৩য়…টা, শেষেরটা (আজ) সবসময় ([[fits()]]-এর একই আন্দাজ)।
     *
     * @param  list<string>  $texts
     */
    public static function every(array $texts, float $slotPx): int
    {
        $widest = 0.0;

        foreach ($texts as $text) {
            $widest = max($widest, self::widthPx($text));
        }

        return max(1, (int) ceil($widest / max(1.0, $slotPx)));
    }

    private static function widthPx(string $text): float
    {
        return mb_strlen($text) * self::TEXT_PX * 0.65 + 8;
    }
}
