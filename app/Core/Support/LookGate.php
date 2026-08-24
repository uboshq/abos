<?php

declare(strict_types=1);

namespace App\Core\Support;

/**
 * কালি আর জমিনের প্রতিটা জোড়া পড়া যায় কি না — থিম ইঞ্জিনের ধাপ ২।
 *
 * ── কেন একটা গেট, একটা সতর্কবার্তা নয় ────────────────────────────────
 * ধাপ ৩-এ মানুষ পর্দা থেকে রূপ বানাবেন, আর রঙের সবচেয়ে সাধারণ ভুলটা
 * হলো **কম কনট্রাস্ট**: হালকা ধূসর জমিনে হালকা ধূসর লেখা। বানানোর সময়
 * বড় পর্দায় ওটা ঠিকই লাগে; কাউন্টারের সস্তা মনিটরে দুপুরের আলোয় লাগে
 * না।
 *
 * সতর্কবার্তা দিলে মানুষ ওটা পেরিয়ে যান — কারণ তাঁর পর্দায় তো পড়াই
 * যাচ্ছে। তাই এটা একটা **গেট**: পাশ না করলে রূপটা প্রকাশ হয় না।
 *
 * ── কোন জোড়াগুলো, আর কেন এগুলোই ──────────────────────────────────────
 * প্রতিটা কালির টোকেনের একটা নির্দিষ্ট জমিন আছে, আর সেই জোড়াটাই
 * মানুষ চোখে দেখে। ছকের কালি ছকের মাথায় বসে, টপবারের কালি টপবারে,
 * ব্যাজের কালি ব্যাজের নিজের জমিনে।
 *
 * ভুল জোড়া মেলালে গেটটা মিথ্যা বলত — যেমন টপবারের সাদা কালিকে পাতার
 * সাদা জমিনের সাথে মেলালে প্রতিটা রূপই ফেল করত, অথচ পর্দায় কিছুই ভুল
 * নেই।
 *
 * ── সীমা কেন ৪.৫:১ ───────────────────────────────────────────────────
 * WCAG AA, সাধারণ লেখার জন্য। হিসাবের খাতা ছোট লেখায় পড়া হয়, আর
 * ৩:১ (বড় লেখার সীমা) ওখানে যথেষ্ট নয়।
 *
 * ফিকে কালি (`--color-ink-muted`) একই সীমায় ধরা হয় ইচ্ছাকৃতভাবে:
 * "কম গুরুত্বপূর্ণ" মানে "পড়া না গেলেও চলবে" নয় — কলামের শিরোনাম ও
 * সাহায্যের লাইন ওই রঙেই বসে।
 */
final class LookGate
{
    /** WCAG AA, সাধারণ লেখা। */
    public const AA = 4.5;

    /**
     * কোন কালি কোন জমিনে বসে।
     *
     * প্রতিটা সারি একটা সত্যিকারের জোড়া — পর্দায় ওই দুইটা রং সত্যিই
     * পাশাপাশি বসে। অনুমান করা জোড়া এখানে নেই।
     *
     * @var array<string, string>
     */
    private const PAIRS = [
        '--color-ink' => '--color-surface-app',
        '--color-ink-body' => '--color-surface-card',
        '--color-ink-muted' => '--color-surface-card',
        '--color-link' => '--color-surface-card',
        '--color-topbar-ink' => '--color-topbar',
        '--color-topbar-ink-muted' => '--color-topbar',
        /*
         * চলতি ট্যাবের কালি বসে **বাছাইয়ের জমিনে**, বারের জমিনে নয়।
         *
         * ── প্রথম লেখায় এটা ভুল ছিল ──────────────────────────────
         * `--color-topnav-ink` বারের রঙের সাথে মেলানো হয়েছিল, আর
         * তাতে ক্লাসিক ১.৬৮ ও NetSuite ১.৩৫ পেয়ে ফেল করল — অথচ
         * পর্দায় দুইটাই দিব্যি পড়া যায়।
         *
         * markup বলে দেয় আসল জোড়াটা কী: চলতি ট্যাব
         * `bg-(--color-topnav-selected) text-(--color-topnav-ink)`,
         * আর বাকিগুলো `text-(--color-topnav-ink-muted)` বারের উপর।
         * ক্লাসিকের #1A1A1A অ্যাম্বারের (#E08C1A) উপর ৯:১ — ওটাই
         * তার চিহ্ন।
         *
         * ভুল জোড়া মেলানো গেট সঠিক রূপ আটকায়, আর তখন গেটটাই বন্ধ
         * করে দেওয়া হয়।
         */
        '--color-topnav-ink' => '--color-topnav-selected',
        '--color-topnav-ink-muted' => '--color-topnav',
        '--color-table-head-ink' => '--color-table-head',
        '--color-footer-ink' => '--color-footer',
        '--color-badge-success-ink' => '--color-badge-success-bg',
        '--color-badge-pending-ink' => '--color-badge-pending-bg',
        '--color-badge-warning-ink' => '--color-badge-warning-bg',
        '--color-badge-danger-ink' => '--color-badge-danger-bg',
        '--color-badge-info-ink' => '--color-badge-info-bg',
        '--color-badge-draft-ink' => '--color-badge-draft-bg',
    ];

    /**
     * একটা রূপের যে জোড়াগুলো সীমা পার করেনি — খালি মানে পাশ।
     *
     * ── কেন তালিকা, প্রথম ব্যর্থতায় থামা নয় ─────────────────────────
     * একটা রূপে ছয়টা জোড়া খারাপ হতে পারে, আর মানুষটা একবারেই সব ঠিক
     * করতে চান। এক-এক করে বললে ছয়বার সেভ করতে হত, আর প্রতিবার একটা
     * নতুন সমস্যা জানতে হত।
     *
     * @param  array<string, string>  $tokens
     * @return list<array{ink: string, on: string, ratio: float}>
     */
    public static function failures(array $tokens): array
    {
        $bad = [];

        foreach (self::PAIRS as $ink => $ground) {
            /*
             * রূপটা যেটা বলেনি সেটা মাপা হয় না।
             *
             * একটা রূপ সব টোকেন বলে না — যা বলে না তা ডিফল্ট থেকে আসে,
             * আর ডিফল্টগুলো আগেই যাচাই করা। এখানে ডিফল্ট টেনে এনে মাপলে
             * গেটটা রূপের দোষে নয়, ডিফল্টের দোষে আটকাত।
             */
            if (! isset($tokens[$ink], $tokens[$ground])) {
                continue;
            }

            $a = self::rgb($tokens[$ink]);
            $b = self::rgb($tokens[$ground]);

            if ($a === null || $b === null) {
                continue;   // স্বচ্ছ বা গ্রেডিয়েন্ট — মাপার মতো কিছু নেই
            }

            $ratio = self::contrast($a, $b);

            if ($ratio < self::AA) {
                $bad[] = ['ink' => $ink, 'on' => $ground, 'ratio' => round($ratio, 2)];
            }
        }

        return $bad;
    }

    /**
     * মানুষের পড়ার মতো বাক্যে।
     *
     * @param  array<string, string>  $tokens
     * @return list<string>
     */
    public static function complaints(array $tokens): array
    {
        return array_map(
            fn (array $f) => __('core.look.too_faint', [
                'ink' => $f['ink'],
                'on' => $f['on'],
                'ratio' => number_format($f['ratio'], 2),
                'need' => number_format(self::AA, 1),
            ]),
            self::failures($tokens),
        );
    }

    /**
     * দুইটা রঙের কনট্রাস্ট — WCAG-এর সূত্রে।
     *
     * @param  array{0: int, 1: int, 2: int}  $a
     * @param  array{0: int, 1: int, 2: int}  $b
     */
    public static function contrast(array $a, array $b): float
    {
        $one = self::luminance($a) + 0.05;
        $two = self::luminance($b) + 0.05;

        return $one > $two ? $one / $two : $two / $one;
    }

    /** @param array{0: int, 1: int, 2: int} $rgb */
    private static function luminance(array $rgb): float
    {
        $channels = array_map(static function (int $value): float {
            $c = $value / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, $rgb);

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    /**
     * একটা CSS রং থেকে তিনটা সংখ্যা — না পারলে null।
     *
     * ── কেন `transparent`-এ null ─────────────────────────────────────
     * স্বচ্ছ জমিনের কোনো নিজের রং নেই; তার নিচে যা আছে সেটাই দেখা যায়।
     * ওটাকে কালো বা সাদা ধরে নিলে গেটটা এমন কিছু নিয়ে রায় দিত যা সে
     * জানে না — আর সেটা ভুল রায় হত।
     *
     * @return array{0: int, 1: int, 2: int}|null
     */
    public static function rgb(string $value): ?array
    {
        $value = trim($value);

        if (preg_match('/^#([0-9a-fA-F]{3})$/', $value, $m) === 1) {
            [$r, $g, $b] = str_split($m[1]);

            return [(int) hexdec($r.$r), (int) hexdec($g.$g), (int) hexdec($b.$b)];
        }

        if (preg_match('/^#([0-9a-fA-F]{6})/', $value, $m) === 1) {
            return [
                (int) hexdec(substr($m[1], 0, 2)),
                (int) hexdec(substr($m[1], 2, 2)),
                (int) hexdec(substr($m[1], 4, 2)),
            ];
        }

        if (preg_match('/^rgba?\(\s*(\d+)[\s,]+(\d+)[\s,]+(\d+)/', $value, $m) === 1) {
            return [(int) $m[1], (int) $m[2], (int) $m[3]];
        }

        return null;
    }
}
