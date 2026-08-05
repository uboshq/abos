<?php

declare(strict_types=1);

namespace App\Core\Support;

/**
 * টাকার অঙ্ক কথায় — "চার লক্ষ বাইশ হাজার সাতশত টাকা মাত্র"।
 *
 * ── কেন এটা দরকার ────────────────────────────────────────────────────
 * বাংলাদেশে বিল ও চেকে অঙ্কের পাশে কথায় লেখা রেওয়াজ, আর তার কারণটা
 * ব্যবহারিক: ১০,০০০-এর আগে একটা কলম চালিয়ে ১,১০,০০০ বানিয়ে ফেলা যায়,
 * কিন্তু "দশ হাজার টাকা মাত্র" লেখা থাকলে যায় না। কাগজটা তখন নিজেই
 * নিজের পাহারা।
 *
 * ── কেন ইংরেজি লাইব্রেরি দিয়ে হয় না ──────────────────────────────────
 * সংখ্যার দল আলাদা। ইংরেজিতে হাজারে হাজারে ভাগ হয় (thousand, million,
 * billion), বাংলায় প্রথম হাজারের পর দুই-অঙ্কে (হাজার, লক্ষ, কোটি)।
 *
 *     1,00,000  = এক লক্ষ            ইংরেজিতে one hundred thousand
 *     1,00,00,000 = এক কোটি          ইংরেজিতে ten million
 *
 * PHP-র NumberFormatter::SPELLOUT বাংলা লোকেলেও ইংরেজি ছক ধরে ভাঙে, তাই
 * "এক লক্ষ"-এর জায়গায় "একশত হাজার" আসত — যা কেউ চেকে লেখে না।
 *
 * পয়সা আলাদা করে বলা হয়, কারণ "সাতশত টাকা পঁচিশ পয়সা" আর "সাতশত
 * পঁচিশ টাকা" দুইটা সম্পূর্ণ আলাদা অঙ্ক।
 */
final class AmountInWords
{
    /** @var array<int, string> */
    private const BN_ONES = [
        0 => '', 1 => 'এক', 2 => 'দুই', 3 => 'তিন', 4 => 'চার', 5 => 'পাঁচ',
        6 => 'ছয়', 7 => 'সাত', 8 => 'আট', 9 => 'নয়', 10 => 'দশ',
        11 => 'এগারো', 12 => 'বারো', 13 => 'তেরো', 14 => 'চৌদ্দ', 15 => 'পনেরো',
        16 => 'ষোলো', 17 => 'সতেরো', 18 => 'আঠারো', 19 => 'উনিশ', 20 => 'বিশ',
        21 => 'একুশ', 22 => 'বাইশ', 23 => 'তেইশ', 24 => 'চব্বিশ', 25 => 'পঁচিশ',
        26 => 'ছাব্বিশ', 27 => 'সাতাশ', 28 => 'আটাশ', 29 => 'ঊনত্রিশ', 30 => 'ত্রিশ',
        31 => 'একত্রিশ', 32 => 'বত্রিশ', 33 => 'তেত্রিশ', 34 => 'চৌত্রিশ', 35 => 'পঁয়ত্রিশ',
        36 => 'ছত্রিশ', 37 => 'সাঁইত্রিশ', 38 => 'আটত্রিশ', 39 => 'ঊনচল্লিশ', 40 => 'চল্লিশ',
        41 => 'একচল্লিশ', 42 => 'বিয়াল্লিশ', 43 => 'তেতাল্লিশ', 44 => 'চুয়াল্লিশ', 45 => 'পঁয়তাল্লিশ',
        46 => 'ছেচল্লিশ', 47 => 'সাতচল্লিশ', 48 => 'আটচল্লিশ', 49 => 'ঊনপঞ্চাশ', 50 => 'পঞ্চাশ',
        51 => 'একান্ন', 52 => 'বায়ান্ন', 53 => 'তিপ্পান্ন', 54 => 'চুয়ান্ন', 55 => 'পঞ্চান্ন',
        56 => 'ছাপ্পান্ন', 57 => 'সাতান্ন', 58 => 'আটান্ন', 59 => 'ঊনষাট', 60 => 'ষাট',
        61 => 'একষট্টি', 62 => 'বাষট্টি', 63 => 'তেষট্টি', 64 => 'চৌষট্টি', 65 => 'পঁয়ষট্টি',
        66 => 'ছেষট্টি', 67 => 'সাতষট্টি', 68 => 'আটষট্টি', 69 => 'ঊনসত্তর', 70 => 'সত্তর',
        71 => 'একাত্তর', 72 => 'বাহাত্তর', 73 => 'তিয়াত্তর', 74 => 'চুয়াত্তর', 75 => 'পঁচাত্তর',
        76 => 'ছিয়াত্তর', 77 => 'সাতাত্তর', 78 => 'আটাত্তর', 79 => 'ঊনআশি', 80 => 'আশি',
        81 => 'একাশি', 82 => 'বিরাশি', 83 => 'তিরাশি', 84 => 'চুরাশি', 85 => 'পঁচাশি',
        86 => 'ছিয়াশি', 87 => 'সাতাশি', 88 => 'আটাশি', 89 => 'ঊননব্বই', 90 => 'নব্বই',
        91 => 'একানব্বই', 92 => 'বিরানব্বই', 93 => 'তিরানব্বই', 94 => 'চুরানব্বই', 95 => 'পঁচানব্বই',
        96 => 'ছিয়ানব্বই', 97 => 'সাতানব্বই', 98 => 'আটানব্বই', 99 => 'নিরানব্বই',
    ];

    /** @var array<int, string> */
    private const EN_ONES = [
        0 => '', 1 => 'one', 2 => 'two', 3 => 'three', 4 => 'four', 5 => 'five',
        6 => 'six', 7 => 'seven', 8 => 'eight', 9 => 'nine', 10 => 'ten',
        11 => 'eleven', 12 => 'twelve', 13 => 'thirteen', 14 => 'fourteen',
        15 => 'fifteen', 16 => 'sixteen', 17 => 'seventeen', 18 => 'eighteen',
        19 => 'nineteen',
    ];

    /** @var array<int, string> */
    private const EN_TENS = [
        2 => 'twenty', 3 => 'thirty', 4 => 'forty', 5 => 'fifty',
        6 => 'sixty', 7 => 'seventy', 8 => 'eighty', 9 => 'ninety',
    ];

    /**
     * একটা অঙ্ক কথায়।
     *
     * @param  string  $amount  bcmath-বান্ধব স্ট্রিং — float নয়, কারণ
     *                          এখানেও টাকার নিয়ম একই (সেকশন ৩.২)
     */
    public static function of(string $amount, ?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();

        $negative = bccomp($amount, '0', 4) < 0;
        $amount = $negative ? bcmul($amount, '-1', 4) : bcadd($amount, '0', 4);

        // পয়সা আলাদা — দুই দশমিক ঘর, আর পঞ্চম ঘর থেকে রাউন্ড নয় বরং
        // ছেঁটে ফেলা: কাগজে যা ছাপা আছে কথাতেও ঠিক তা-ই থাকতে হবে
        [$whole, $fraction] = self::split($amount);

        $words = $locale === 'bn'
            ? self::bengali($whole, $fraction)
            : self::english($whole, $fraction);

        return $negative
            ? __('core.print.minus', [], $locale).' '.$words
            : $words;
    }

    /**
     * @return array{0: string, 1: int}
     */
    private static function split(string $amount): array
    {
        $rounded = bcadd($amount, '0', 2);
        $parts = explode('.', $rounded);

        return [$parts[0], (int) ($parts[1] ?? 0)];
    }

    private static function bengali(string $whole, int $paisa): string
    {
        $words = self::bengaliNumber($whole);

        $out = $words === ''
            ? 'শূন্য টাকা'
            : $words.' টাকা';

        if ($paisa > 0) {
            $out .= ' '.self::bengaliNumber((string) $paisa).' পয়সা';
        }

        return $out.' মাত্র';
    }

    /**
     * ভারতীয়-বাংলা দল: কোটি · লক্ষ · হাজার · শত · একক।
     *
     * ডান দিক থেকে প্রথমে তিন অঙ্ক (শত সহ), তারপর দুই-দুই করে — এটাই
     * ইংরেজি ছকের সাথে আসল পার্থক্য।
     */
    private static function bengaliNumber(string $number): string
    {
        $number = ltrim($number, '0');

        if ($number === '') {
            return '';
        }

        // এক কোটির উপরে হলে বাঁ দিকের অংশটা আবার একই নিয়মে — "একশত কোটি"
        if (strlen($number) > 9) {
            $crorePart = substr($number, 0, strlen($number) - 7);
            $rest = substr($number, -7);

            return trim(self::bengaliNumber($crorePart).' কোটি '.self::bengaliNumber($rest));
        }

        $n = (int) $number;
        $parts = [];

        foreach ([10000000 => 'কোটি', 100000 => 'লক্ষ', 1000 => 'হাজার', 100 => 'শত'] as $unit => $name) {
            if ($n >= $unit) {
                $count = intdiv($n, $unit);
                $n %= $unit;

                // "একশত" একসাথে, "এক শত" নয় — কাগজে ওভাবেই লেখা হয়
                $parts[] = $name === 'শত'
                    ? self::BN_ONES[$count].$name
                    : self::bengaliNumber((string) $count).' '.$name;
            }
        }

        if ($n > 0) {
            $parts[] = self::BN_ONES[$n];
        }

        return trim(implode(' ', array_filter($parts)));
    }

    private static function english(string $whole, int $paisa): string
    {
        $words = self::englishNumber($whole);

        $out = ($words === '' ? 'zero' : $words).' taka';

        if ($paisa > 0) {
            $out .= ' and '.self::englishNumber((string) $paisa).' paisa';
        }

        return ucfirst($out).' only';
    }

    /**
     * ইংরেজিতেও লক্ষ-কোটি — ইচ্ছাকৃত।
     *
     * কাগজটা বাংলাদেশে পড়া হয়, আর এখানে ইংরেজি বিলেও "lakh"/"crore"
     * লেখাই স্বাভাবিক। "hundred thousand" লিখলে স্থানীয় কেউ দুইবার
     * গুনতে বসতেন।
     */
    private static function englishNumber(string $number): string
    {
        $number = ltrim($number, '0');

        if ($number === '') {
            return '';
        }

        if (strlen($number) > 9) {
            $crorePart = substr($number, 0, strlen($number) - 7);
            $rest = substr($number, -7);

            return trim(self::englishNumber($crorePart).' crore '.self::englishNumber($rest));
        }

        $n = (int) $number;
        $parts = [];

        foreach ([10000000 => 'crore', 100000 => 'lakh', 1000 => 'thousand', 100 => 'hundred'] as $unit => $name) {
            if ($n >= $unit) {
                $count = intdiv($n, $unit);
                $n %= $unit;
                $parts[] = self::englishNumber((string) $count).' '.$name;
            }
        }

        if ($n > 0) {
            $parts[] = self::englishTwoDigits($n);
        }

        return trim(implode(' ', array_filter($parts)));
    }

    private static function englishTwoDigits(int $n): string
    {
        if ($n < 20) {
            return self::EN_ONES[$n];
        }

        $tens = self::EN_TENS[intdiv($n, 10)];
        $ones = $n % 10;

        return $ones === 0 ? $tens : $tens.'-'.self::EN_ONES[$ones];
    }
}
