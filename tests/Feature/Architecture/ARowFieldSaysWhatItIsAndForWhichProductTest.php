<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * ⛔ লাইনের সম্পাদকের সারির ঘরে নাম ছিল না — ওয়েব-মান ধাপ ২, ২০ অক্টোবর ২০২৬ (WCAG 2.2 §1.3.1, §4.1.2)।
 *
 * <p>ক্রয়-বিক্রয়ের লাইন, ফেরত, চুক্তি, চাহিদা, দরপত্র, স্থানান্তর, POS, প্যাক, রেসিপি, পরিশোধ, আদায় আর মজুদের কাগজ — `x-for`-এর
 * প্রতিটা সারির পরিমাণ, দর, ছাড়ের ঘর নামহীন: স্ক্রিন-রিডার কেবল "সংখ্যা, সম্পাদনযোগ্য" বলত, কোন পণ্যের কোন ঘর তা নয়।
 *
 * <p>ছাঁচ: ঘরের স্থির `aria-label` কলামের নামে (সার্ভারের পাতাতেই থাকে), আর চলমান পাতায় `:aria-labelledby` = নিজে + সারির পণ্যের ঘর —
 * নাম দাঁড়ায় "পরিমাণ · পণ্যের নাম"। ⓘ কেবল স্ট্রিং জোড়া, তাই CSP-র Alpine-এ চলে।
 *
 * <p>নিয়ম: `<template x-for>`-এর ভেতরে (ভেতরের template-সহ) প্রতিটা দেখা যায় এমন ঘর (input, select, textarea; hidden, বোতাম,
 * checkbox, radio বাদে) একটা স্থির `aria-label`/`aria-labelledby`/`title` পায়, বা `<label>`-এর ভেতরে থাকে।
 */
final class ARowFieldSaysWhatItIsAndForWhichProductTest extends TestCase
{
    private const FIELD = '/<(input|select|textarea)\b((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>/s';

    public function test_every_field_inside_a_repeated_row_carries_a_name(): void
    {
        $rows = 0;
        $wrong = [];

        exec('git -C '.escapeshellarg(base_path()).' ls-tree -r --name-only HEAD app resources 2>&1', $lines, $code);
        $this->assertSame(0, $code, 'গিটের তালিকা পাওয়া গেল না');

        foreach ($lines as $relative) {
            if (! str_ends_with($relative, '.blade.php') || ! is_file(base_path($relative))) {
                continue;
            }

            // ⓘ মন্তব্যের ভেতরের "<input …>" লেখা ঘর নয় — একই দৈর্ঘ্যের ফাঁকা দিয়ে মোছা, যাতে অবস্থান না সরে
            $source = (string) preg_replace_callback('/\{\{--.*?--\}\}/s',
                fn (array $m) => str_repeat(' ', strlen($m[0])), (string) file_get_contents(base_path($relative)));

            foreach (self::rowTemplates($source) as $block) {
                $rows++;
                preg_match_all(self::FIELD, $block, $fields, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

                foreach ($fields as $f) {
                    $attrs = $f[2][0];

                    if (preg_match('/type="(hidden|submit|button|checkbox|radio)"/', $attrs) === 1
                        || preg_match('/(?<![:\w-])(aria-label|aria-labelledby|title)\s*=/', $attrs) === 1) {
                        continue;
                    }

                    $before = substr($block, 0, $f[0][1]);
                    if (substr_count($before, '<label') > substr_count($before, '</label>')) {
                        continue;
                    }

                    $wrong[] = $relative.': '.preg_replace('/\s+/', ' ', substr($f[0][0], 0, 90));
                }
            }
        }

        $this->assertGreaterThan(20, $rows, 'মাত্র '.$rows.'টা সারির ছাঁচ চোখে পড়ল — খোঁজা ভেঙেছে কি না দেখুন।');
        $this->assertSame([], $wrong, "⛔ সারির এই ঘরগুলোর নাম নেই (কলামের নামে স্থির aria-label দিন):\n  ".implode("\n  ", $wrong));
    }

    public function test_the_purchase_line_names_each_box_by_column_and_product(): void
    {
        $source = (string) file_get_contents(base_path('app/Modules/Purchase/Resources/views/components/line-editor.blade.php'));

        $this->assertStringContainsString(":id=\"'pl-' + i + '-product'\"", $source, '⛔ সারির পণ্যের ঘরের id নেই — নামের দ্বিতীয় অর্ধেক হারায়');
        $this->assertMatchesRegularExpression(
            '/aria-label="\{\{ __\(\'purchase::field\.quantity\'\) \}\}" :id="\'pl-\' \+ i \+ \'-qty\'" :aria-labelledby="\'pl-\' \+ i \+ \'-qty\' \+ \' \' \+ \'pl-\' \+ i \+ \'-product\'"/',
            $source, '⛔ পরিমাণের ঘর কলাম আর পণ্য দুটো দিয়ে নাম পায় না');
    }

    /**
     * বাইরের `<template x-for>`-গুলোর ভেতরটা — ভেতরের template গুনে শেষ খোঁজা।
     *
     * @return list<string>
     */
    private static function rowTemplates(string $s): array
    {
        $out = [];
        $offset = 0;

        while (preg_match('/<template\s+x-for=/', $s, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $start = $m[0][1];
            $depth = 1;
            $j = $start + 9;

            while ($depth > 0) {
                $open = strpos($s, '<template', $j);
                $close = strpos($s, '</template>', $j);

                if ($close === false) {
                    break 2;
                }

                if ($open !== false && $open < $close) {
                    $depth++;
                    $j = $open + 9;
                } else {
                    $depth--;
                    $j = $close + 11;
                }
            }

            $out[] = substr($s, $start, $j - $start);
            $offset = $j;
        }

        return $out;
    }
}
