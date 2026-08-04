<?php

declare(strict_types=1);

namespace App\Core\Support;

/**
 * ব্যবহারকারী নিজের পর্দার জন্য যে রংটা বাছতে পারে।
 *
 * **নির্দিষ্ট তালিকা, কালার হুইল নয়।** মুক্ত পিকার দিলে কেউ এমন হলুদ বেছে
 * নেবে যাতে সাদা লেখা পড়া যায় না — আর যে বোতামটা তখন পড়া যাবে না, সেটাই
 * ইনভয়েস সেভ করার বোতাম। এখানকার প্রতিটা মান সেকশন ১৪.৭-এর মতো করে
 * যাচাই করা: প্রতিটার ৬০০ ধাপ সাদা লেখা নিয়ে AA পাশ করে।
 *
 * **কোম্পানির নয়, ব্যক্তির।** এক ডিপোর একটা কম্পিউটারে দিনে তিনজন বসে;
 * যে নীল আর সবুজের পার্থক্য বোঝে না, তাকে অন্যজনকে অনুরোধ করতে হবে না।
 * সেভ হয় ব্যবহারকারীর রেকর্ডে (সেকশন ১৫.১৫) — সেশনে নয়, তাই অন্য
 * ডিভাইসেও একই পর্দা।
 *
 * মানগুলো "R G B" ত্রিক হিসেবে, hex নয়: rgb(var(--brand-500) / <alpha>)
 * এই রূপটাই চায়, আর সেটাই bg-brand-500/20 কাজ করতে দেয়।
 */
final class Accent
{
    public const DEFAULT = 'blue';

    /**
     * @return array<string, array{label: string, swatch: string, scale: array<string, string>}>
     */
    public static function all(): array
    {
        return [
            'blue' => [
                'label' => 'core.accent.blue',
                'swatch' => '#2563eb',
                'scale' => [
                    '50' => '239 246 255', '100' => '219 234 254', '500' => '59 130 246',
                    '600' => '37 99 235', '700' => '13 71 161', '900' => '11 19 43',
                ],
            ],
            'teal' => [
                'label' => 'core.accent.teal',
                'swatch' => '#0f766e',
                'scale' => [
                    '50' => '240 253 250', '100' => '204 251 241', '500' => '20 184 166',
                    // Tailwind-এর teal-600 (13 148 136) সাদা লেখা নিয়ে ৩.৭৪ —
                    // AA পাশ করে না। এক ধাপ গাঢ় করে teal-700 বসানো হয়েছে,
                    // আর ৭০০ হয়েছে teal-800। টেস্টটা এটাই ধরেছিল।
                    '600' => '15 118 110', '700' => '17 94 89', '900' => '4 47 46',
                ],
            ],
            'indigo' => [
                'label' => 'core.accent.indigo',
                'swatch' => '#4f46e5',
                'scale' => [
                    '50' => '238 242 255', '100' => '224 231 255', '500' => '99 102 241',
                    '600' => '79 70 229', '700' => '67 56 202', '900' => '30 27 75',
                ],
            ],
            'violet' => [
                'label' => 'core.accent.violet',
                'swatch' => '#7c3aed',
                'scale' => [
                    '50' => '245 243 255', '100' => '237 233 254', '500' => '139 92 246',
                    '600' => '124 58 237', '700' => '109 40 217', '900' => '46 16 101',
                ],
            ],
            'emerald' => [
                'label' => 'core.accent.emerald',
                'swatch' => '#047857',
                'scale' => [
                    '50' => '236 253 245', '100' => '209 250 229', '500' => '16 185 129',
                    // teal-এর মতোই: Tailwind-এর emerald-600 (5 150 105) সাদা
                    // লেখায় ৩.৭৭। সেকশন ১৪.৭-এ Success বাটনেও ঠিক এই কারণেই
                    // #047857 বসাতে হয়েছিল — সবুজে ৬০০ ধাপটা কখনোই যথেষ্ট
                    // গাঢ় নয়।
                    '600' => '4 120 87', '700' => '6 95 70', '900' => '6 78 59',
                ],
            ],
            'slate' => [
                'label' => 'core.accent.slate',
                'swatch' => '#334155',
                'scale' => [
                    '50' => '248 250 252', '100' => '241 245 249', '500' => '100 116 139',
                    '600' => '71 85 105', '700' => '51 65 85', '900' => '15 23 42',
                ],
            ],
        ];
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function exists(string $key): bool
    {
        return isset(self::all()[$key]);
    }

    /** @return array{label: string, swatch: string, scale: array<string, string>} */
    public static function get(string $key): array
    {
        return self::all()[$key] ?? self::all()[self::DEFAULT];
    }

    /**
     * <html> ট্যাগে বসানোর জন্য CSS ভেরিয়েবল।
     *
     * সার্ভারেই তৈরি হয় — JavaScript-এ করলে প্রথম রঙে পাতা এঁকে তারপর
     * বদলাত, আর প্রতিটা পাতা লোডে একবার করে ঝলকানি দেখা যেত।
     */
    public static function styleFor(string $key): string
    {
        $css = '';

        foreach (self::get($key)['scale'] as $step => $rgb) {
            $css .= "--accent-{$step}:{$rgb};";
        }

        return $css;
    }
}
