<?php

declare(strict_types=1);

namespace App\Core\Support;

use RuntimeException;

/**
 * একটা QR কোড, নিজেরাই বানানো — বাইরের কোনো সেবা বা লাইব্রেরি ছাড়া।
 *
 * ── ⛔ কেন নিজেরা লিখতে হলো, ২৮ সেপ্টেম্বর ২০২৬ ──────────────────────
 * দুই ধাপের লগইন চালু হওয়ার পর মালিক ফোনের অ্যাপে চাবিটা **হাতে
 * লিখতে** গিয়ে ভুল করেছেন (O আর 0, I আর 1 — বত্রিশ অক্ষরে ওটা
 * প্রায় অনিবার্য), আর কোড মেলেনি।
 *
 * ⚠️ vendor-এ কোনো QR লাইব্রেরি নেই, আর লাইভের ডিপ্লয় `composer`
 * চালায় না — তাই একটা প্যাকেজ যোগ করলে সেটা লাইভে পৌঁছাতই না।
 * ⛔ আর বাইরের কোনো QR-সেবা ব্যবহার করা মানে **গোপন চাবিটা
 * ইন্টারনেটে পাঠানো**, যা এই গোটা তালাটার উল্টো।
 *
 * ⓘ তাই [[Barcode]]-এর মতোই: স্পেক পড়ে, নিজেরা।
 *
 * ── ⓘ কতটুকু বানানো হয়েছে, আর কতটুকু নয় ─────────────────────────────
 * ⭐ বাইট মোড · EC স্তর M · সংস্করণ ১–১০ · আটটা মাস্ক, জরিমানা গুনে।
 * ⓘ একটা `otpauth://` ঠিকানা ~১৫০ বাইটের কম, আর সংস্করণ ১০ ধরে ২১৩
 * বাইট — তাই এটুকুতেই কুলায়, আর যা লাগে না তা লেখা হয়নি।
 *
 * ⛔ সংখ্যা/অক্ষর মোড নেই, কাঞ্জি নেই, EC স্তর L/Q/H নেই। ⚠️ ওগুলো
 * লিখলে কোড বাড়ত আর একটাও ব্যবহার হত না — আর অব্যবহৃত কোড মানে
 * অপরীক্ষিত কোড।
 *
 * ── ⚠️ ভুল হলে কীভাবে ধরা পড়ে ───────────────────────────────────────
 * ⛔ একটা QR ভুল হলে সে দেখতে **ঠিক QR-এর মতোই** লাগে — কেবল ফোন
 * পড়তে পারে না। ⓘ তাই পরীক্ষায় আউটপুটটা একটা **স্বাধীন** এনকোডারের
 * (পাইথনের `qrcode`) ম্যাট্রিক্সের সাথে ঘর ধরে ধরে মেলানো হয়।
 */
final class QrCode
{
    /** ⓘ EC স্তর M — স্পেকের ফরম্যাট-বিটে `00`। */
    private const EC_LEVEL_BITS = 0b00;

    /**
     * সংস্করণ => [মোট কোডওয়ার্ড, প্রতি ব্লকে EC, দল১ ব্লক, দল১ ডেটা, দল২ ব্লক, দল২ ডেটা]
     *
     * ⛔ সংখ্যাগুলো স্পেকের ছক (ISO/IEC 18004, সারণি ৯), আবিষ্কারের নয়।
     * ⚠️ একটা ভুল সংখ্যা মানে একটা QR যা দেখতে নিখুঁত আর পড়া যায় না।
     *
     * @var array<int, array{0: int, 1: int, 2: int, 3: int, 4: int, 5: int}>
     */
    private const BLOCKS_M = [
        1 => [26, 10, 1, 16, 0, 0],
        2 => [44, 16, 1, 28, 0, 0],
        3 => [70, 26, 1, 44, 0, 0],
        4 => [100, 18, 2, 32, 0, 0],
        5 => [134, 24, 2, 43, 0, 0],
        6 => [172, 16, 4, 27, 0, 0],
        7 => [196, 18, 4, 31, 0, 0],
        8 => [242, 22, 2, 38, 2, 39],
        9 => [292, 22, 3, 36, 2, 37],
        10 => [346, 26, 4, 43, 1, 44],
    ];

    /** সংস্করণ => বাইট মোডে কত বাইট ধরে (EC স্তর M)। */
    private const BYTE_CAPACITY_M = [
        1 => 14, 2 => 26, 3 => 42, 4 => 62, 5 => 84,
        6 => 106, 7 => 122, 8 => 152, 9 => 180, 10 => 213,
    ];

    /**
     * সারিবদ্ধ করার ছকের কেন্দ্রগুলো।
     *
     * ⓘ সংস্করণ ১-এ কোনোটাই নেই — ওটাই একমাত্র ব্যতিক্রম, আর ভুলে
     * একটা বসিয়ে দিলে ফাইন্ডারের উপর পড়ত।
     *
     * @var array<int, list<int>>
     */
    private const ALIGNMENT = [
        1 => [],
        2 => [6, 18],
        3 => [6, 22],
        4 => [6, 26],
        5 => [6, 30],
        6 => [6, 34],
        7 => [6, 22, 38],
        8 => [6, 24, 42],
        9 => [6, 26, 46],
        10 => [6, 28, 50],
    ];

    /** সংস্করণ => শেষে কয়টা অবশিষ্ট বিট (স্পেক, সারণি ১)। */
    private const REMAINDER_BITS = [
        1 => 0, 2 => 7, 3 => 7, 4 => 7, 5 => 7, 6 => 7,
        7 => 0, 8 => 0, 9 => 0, 10 => 0,
    ];

    /**
     * একটা লেখার জন্য মডিউলের ছক — `true` মানে কালো ঘর।
     *
     * @return list<list<bool>>
     */
    public static function matrix(string $text): array
    {
        $version = self::versionFor($text);
        $size = 17 + 4 * $version;

        $codewords = self::codewords($text, $version);

        [$matrix, $reserved] = self::skeleton($version, $size);

        self::placeData($matrix, $reserved, $codewords, $size, $version);

        $best = null;
        $bestPenalty = null;

        for ($mask = 0; $mask < 8; $mask++) {
            $candidate = self::applyMask($matrix, $reserved, $size, $mask);
            self::placeFormat($candidate, $size, $mask);

            if ($version >= 7) {
                self::placeVersion($candidate, $size, $version);
            }

            $penalty = self::penalty($candidate, $size);

            if ($bestPenalty === null || $penalty < $bestPenalty) {
                $bestPenalty = $penalty;
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * ছকটা একটা SVG হয়ে — পাতায় সরাসরি বসানোর জন্য।
     *
     * ⓘ ঘরগুলো একটাই `<path>`-এ, হাজারটা `<rect>`-এ নয়: ছোট HTML, আর
     * ব্রাউজারেও দ্রুত। ⭐ কোনো ইনলাইন `style` নেই, তাই CSP-তে কিছু
     * খুলতে হয় না — `fill` একটা সাধারণ অ্যাট্রিবিউট।
     */
    public static function svg(string $text, int $scale = 6, int $quiet = 4): string
    {
        $matrix = self::matrix($text);
        $size = count($matrix);
        $side = ($size + $quiet * 2) * $scale;

        $path = '';

        foreach ($matrix as $y => $row) {
            foreach ($row as $x => $on) {
                if ($on) {
                    $path .= sprintf('M%d %dh%dv%dh-%dz',
                        ($x + $quiet) * $scale, ($y + $quiet) * $scale,
                        $scale, $scale, $scale);
                }
            }
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d" '
            .'role="img" shape-rendering="crispEdges">'
            .'<rect width="%d" height="%d" fill="#ffffff"/>'
            .'<path d="%s" fill="#000000"/></svg>',
            $side, $side, $side, $side, $side, $side, $path,
        );
    }

    /** কোন সংস্করণে কুলাবে — সবচেয়ে ছোটটা। */
    public static function versionFor(string $text): int
    {
        $length = strlen($text);

        foreach (self::BYTE_CAPACITY_M as $version => $capacity) {
            if ($length <= $capacity) {
                return $version;
            }
        }

        throw new RuntimeException(
            'QR: '.$length.' bytes is more than version 10 holds at level M ('
            .self::BYTE_CAPACITY_M[10].').'
        );
    }

    // ── ১ · লেখা থেকে কোডওয়ার্ড ─────────────────────────────────────────

    /** @return list<int> */
    private static function codewords(string $text, int $version): array
    {
        [$total, $ecPerBlock, $g1Blocks, $g1Data, $g2Blocks, $g2Data] = self::BLOCKS_M[$version];

        $bits = '0100';                                   // বাইট মোড
        $bits .= str_pad(decbin(strlen($text)), $version >= 10 ? 16 : 8, '0', STR_PAD_LEFT);

        foreach (str_split($text) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $dataCodewords = $g1Blocks * $g1Data + $g2Blocks * $g2Data;
        $capacityBits = $dataCodewords * 8;

        // থামার চিহ্ন — চারটা শূন্য, তবে জায়গা যতটুকু আছে
        $bits .= str_repeat('0', min(4, $capacityBits - strlen($bits)));

        // বাইটের সীমায় টেনে তোলা
        if (strlen($bits) % 8 !== 0) {
            $bits .= str_repeat('0', 8 - strlen($bits) % 8);
        }

        /*
         * ⓘ বাকি জায়গা স্পেকের দুইটা ভরাট বাইটে, পালা করে — 0xEC, 0x11।
         * ⚠️ শূন্য দিয়ে ভরলে অনেক স্ক্যানার পড়ে, কিন্তু স্পেক এটাই বলে,
         * আর মাস্কের জরিমানাও এই বৈচিত্র্য ধরে হিসাব করা।
         */
        $pad = ['11101100', '00010001'];
        $i = 0;

        while (strlen($bits) < $capacityBits) {
            $bits .= $pad[$i++ % 2];
        }

        $data = [];

        foreach (str_split($bits, 8) as $byte) {
            $data[] = bindec($byte);
        }

        // ── ব্লকে ভাগ, প্রতিটার নিজের EC ──────────────────────────────
        $blocks = [];
        $ecBlocks = [];
        $at = 0;

        foreach ([[$g1Blocks, $g1Data], [$g2Blocks, $g2Data]] as [$count, $size]) {
            for ($b = 0; $b < $count; $b++) {
                $block = array_slice($data, $at, $size);
                $at += $size;

                $blocks[] = $block;
                $ecBlocks[] = self::errorCorrection($block, $ecPerBlock);
            }
        }

        /*
         * ⭐ সাজানোটা **আড়াআড়ি** — প্রতিটা ব্লকের প্রথম বাইট, তারপর
         * প্রতিটার দ্বিতীয়… ⓘ এতেই একটা ছেঁড়া বা নোংরা জায়গা একটামাত্র
         * ব্লকের বদলে সবগুলোয় ছড়িয়ে পড়ে, আর প্রতিটাই সারিয়ে নেওয়া যায়।
         */
        $out = [];

        for ($i = 0; $i < max(array_map('count', $blocks)); $i++) {
            foreach ($blocks as $block) {
                if (isset($block[$i])) {
                    $out[] = $block[$i];
                }
            }
        }

        for ($i = 0; $i < $ecPerBlock; $i++) {
            foreach ($ecBlocks as $block) {
                $out[] = $block[$i];
            }
        }

        if (count($out) !== $total) {
            throw new RuntimeException(
                'QR: built '.count($out).' codewords, version '.$version.' wants '.$total.'.'
            );
        }

        return $out;
    }

    // ── ২ · Reed–Solomon, GF(256) ────────────────────────────────────────

    /** @var list<int>|null */
    private static ?array $exp = null;

    /** @var list<int>|null */
    private static ?array $log = null;

    private static function tables(): void
    {
        if (self::$exp !== null) {
            return;
        }

        /*
         * ⓘ QR-এর নিজের আদিম বহুপদী 0x11D, আর জেনারেটর ২।
         * ⚠️ অন্য কোনো বহুপদী নিলে গণিতটা চলত, কিন্তু ফলটা অন্য কোড —
         * আর সেটা দেখতে নিখুঁত QR, পড়া যায় না।
         */
        self::$exp = array_fill(0, 512, 0);
        self::$log = array_fill(0, 256, 0);

        $x = 1;

        for ($i = 0; $i < 255; $i++) {
            self::$exp[$i] = $x;
            self::$log[$x] = $i;

            $x <<= 1;

            if ($x & 0x100) {
                $x ^= 0x11D;
            }
        }

        for ($i = 255; $i < 512; $i++) {
            self::$exp[$i] = self::$exp[$i - 255];
        }
    }

    private static function multiply(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }

        self::tables();

        return self::$exp[self::$log[$a] + self::$log[$b]];
    }

    /**
     * একটা ব্লকের EC বাইটগুলো।
     *
     * @param  list<int>  $block
     * @return list<int>
     */
    private static function errorCorrection(array $block, int $count): array
    {
        self::tables();

        // জেনারেটর বহুপদী: (x − α⁰)(x − α¹)…
        $generator = [1];

        for ($i = 0; $i < $count; $i++) {
            $next = array_fill(0, count($generator) + 1, 0);

            foreach ($generator as $j => $coefficient) {
                $next[$j] ^= $coefficient;
                $next[$j + 1] ^= self::multiply($coefficient, self::$exp[$i]);
            }

            $generator = $next;
        }

        $remainder = array_merge($block, array_fill(0, $count, 0));

        for ($i = 0; $i < count($block); $i++) {
            $lead = $remainder[$i];

            if ($lead === 0) {
                continue;
            }

            foreach ($generator as $j => $coefficient) {
                $remainder[$i + $j] ^= self::multiply($coefficient, $lead);
            }
        }

        return array_slice($remainder, count($block), $count);
    }

    // ── ৩ · ছকের কাঠামো ─────────────────────────────────────────────────

    /**
     * ফাইন্ডার, টাইমিং, সারিবদ্ধকরণ — আর কোন ঘরগুলো সংরক্ষিত।
     *
     * @return array{0: list<list<bool>>, 1: list<list<bool>>}
     */
    private static function skeleton(int $version, int $size): array
    {
        $matrix = array_fill(0, $size, array_fill(0, $size, false));
        $reserved = array_fill(0, $size, array_fill(0, $size, false));

        // তিন কোণের ফাইন্ডার, আর তাদের ফাঁকা বেড়া
        foreach ([[0, 0], [$size - 7, 0], [0, $size - 7]] as [$x, $y]) {
            for ($dy = -1; $dy <= 7; $dy++) {
                for ($dx = -1; $dx <= 7; $dx++) {
                    $px = $x + $dx;
                    $py = $y + $dy;

                    if ($px < 0 || $py < 0 || $px >= $size || $py >= $size) {
                        continue;
                    }

                    $inner = $dx >= 0 && $dx <= 6 && $dy >= 0 && $dy <= 6;
                    $on = $inner && (
                        $dx === 0 || $dx === 6 || $dy === 0 || $dy === 6
                        || ($dx >= 2 && $dx <= 4 && $dy >= 2 && $dy <= 4)
                    );

                    $matrix[$py][$px] = $on;
                    $reserved[$py][$px] = true;
                }
            }
        }

        // টাইমিং — ষষ্ঠ সারি ও ষষ্ঠ কলাম, এক ঘর পরপর
        for ($i = 8; $i < $size - 8; $i++) {
            $on = $i % 2 === 0;

            $matrix[6][$i] = $on;
            $reserved[6][$i] = true;
            $matrix[$i][6] = $on;
            $reserved[$i][6] = true;
        }

        // সারিবদ্ধকরণের ছক — ফাইন্ডারের ঘরে নয়
        $centres = self::ALIGNMENT[$version];

        foreach ($centres as $cy) {
            foreach ($centres as $cx) {
                if (self::insideFinder($cx, $cy, $size)) {
                    continue;
                }

                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $on = abs($dx) === 2 || abs($dy) === 2 || ($dx === 0 && $dy === 0);

                        $matrix[$cy + $dy][$cx + $dx] = $on;
                        $reserved[$cy + $dy][$cx + $dx] = true;
                    }
                }
            }
        }

        // সবসময় কালো একটা ঘর
        $matrix[$size - 8][8] = true;
        $reserved[$size - 8][8] = true;

        // ফরম্যাটের ঘরগুলো সরিয়ে রাখা
        for ($i = 0; $i < 9; $i++) {
            if ($i !== 6) {
                $reserved[8][$i] = true;
                $reserved[$i][8] = true;
            }
        }

        for ($i = 0; $i < 8; $i++) {
            $reserved[8][$size - 1 - $i] = true;
            $reserved[$size - 1 - $i][8] = true;
        }

        // সংস্করণের ঘরগুলো (৭ থেকে)
        if ($version >= 7) {
            for ($i = 0; $i < 6; $i++) {
                for ($j = 0; $j < 3; $j++) {
                    $reserved[$size - 11 + $j][$i] = true;
                    $reserved[$i][$size - 11 + $j] = true;
                }
            }
        }

        return [$matrix, $reserved];
    }

    private static function insideFinder(int $cx, int $cy, int $size): bool
    {
        return ($cx <= 8 && $cy <= 8)
            || ($cx <= 8 && $cy >= $size - 9)
            || ($cx >= $size - 9 && $cy <= 8);
    }

    /**
     * ডেটা বসানো — ডান-নিচ থেকে, দুই কলাম করে, সাপের মতো।
     *
     * @param  list<list<bool>>  $matrix
     * @param  list<list<bool>>  $reserved
     * @param  list<int>  $codewords
     */
    private static function placeData(array &$matrix, array $reserved, array $codewords, int $size, int $version): void
    {
        $bits = '';

        foreach ($codewords as $byte) {
            $bits .= str_pad(decbin($byte), 8, '0', STR_PAD_LEFT);
        }

        $bits .= str_repeat('0', self::REMAINDER_BITS[$version]);

        $at = 0;
        $upward = true;

        for ($right = $size - 1; $right > 0; $right -= 2) {
            // ষষ্ঠ কলামটা টাইমিং — ওটা পেরিয়ে যেতে হয়
            if ($right === 6) {
                $right--;
            }

            for ($step = 0; $step < $size; $step++) {
                $y = $upward ? $size - 1 - $step : $step;

                foreach ([$right, $right - 1] as $x) {
                    if ($reserved[$y][$x]) {
                        continue;
                    }

                    $matrix[$y][$x] = ($bits[$at] ?? '0') === '1';
                    $at++;
                }
            }

            $upward = ! $upward;
        }
    }

    // ── ৪ · মাস্ক ও জরিমানা ─────────────────────────────────────────────

    /**
     * @param  list<list<bool>>  $matrix
     * @param  list<list<bool>>  $reserved
     * @return list<list<bool>>
     */
    private static function applyMask(array $matrix, array $reserved, int $size, int $mask): array
    {
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if ($reserved[$y][$x]) {
                    continue;
                }

                $flip = match ($mask) {
                    0 => ($y + $x) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($y + $x) % 3 === 0,
                    4 => (intdiv($y, 2) + intdiv($x, 3)) % 2 === 0,
                    5 => ($y * $x) % 2 + ($y * $x) % 3 === 0,
                    6 => (($y * $x) % 2 + ($y * $x) % 3) % 2 === 0,
                    7 => ((($y + $x) % 2) + (($y * $x) % 3)) % 2 === 0,
                    default => false,
                };

                if ($flip) {
                    $matrix[$y][$x] = ! $matrix[$y][$x];
                }
            }
        }

        return $matrix;
    }

    /**
     * চারটা জরিমানার নিয়ম — যেটার যোগফল সবচেয়ে কম, সেই মাস্ক।
     *
     * ⓘ নিয়মগুলো স্পেকের: টানা একরঙা, ২×২ চৌকো, ফাইন্ডারের মতো ছক,
     * আর কালো-সাদার অনুপাত।
     *
     * @param  list<list<bool>>  $m
     */
    private static function penalty(array $m, int $size): int
    {
        $score = 0;

        // নিয়ম ১ — টানা পাঁচ বা তার বেশি একরঙা
        for ($pass = 0; $pass < 2; $pass++) {
            for ($a = 0; $a < $size; $a++) {
                $run = 1;

                for ($b = 1; $b < $size; $b++) {
                    $here = $pass === 0 ? $m[$a][$b] : $m[$b][$a];
                    $prev = $pass === 0 ? $m[$a][$b - 1] : $m[$b - 1][$a];

                    if ($here === $prev) {
                        $run++;

                        continue;
                    }

                    if ($run >= 5) {
                        $score += 3 + ($run - 5);
                    }

                    $run = 1;
                }

                if ($run >= 5) {
                    $score += 3 + ($run - 5);
                }
            }
        }

        // নিয়ম ২ — ২×২ একরঙা চৌকো
        for ($y = 0; $y < $size - 1; $y++) {
            for ($x = 0; $x < $size - 1; $x++) {
                if ($m[$y][$x] === $m[$y][$x + 1]
                    && $m[$y][$x] === $m[$y + 1][$x]
                    && $m[$y][$x] === $m[$y + 1][$x + 1]) {
                    $score += 3;
                }
            }
        }

        // নিয়ম ৩ — ফাইন্ডারের মতো ১:১:৩:১:১ ছক, দুই দিকেই
        $patterns = [
            [true, false, true, true, true, false, true, false, false, false, false],
            [false, false, false, false, true, false, true, true, true, false, true],
        ];

        for ($pass = 0; $pass < 2; $pass++) {
            for ($a = 0; $a < $size; $a++) {
                for ($b = 0; $b <= $size - 11; $b++) {
                    foreach ($patterns as $pattern) {
                        $hit = true;

                        for ($k = 0; $k < 11; $k++) {
                            $cell = $pass === 0 ? $m[$a][$b + $k] : $m[$b + $k][$a];

                            if ($cell !== $pattern[$k]) {
                                $hit = false;

                                break;
                            }
                        }

                        if ($hit) {
                            $score += 40;
                        }
                    }
                }
            }
        }

        // নিয়ম ৪ — কালোর অনুপাত ৫০% থেকে কত দূরে
        $dark = 0;

        foreach ($m as $row) {
            foreach ($row as $cell) {
                if ($cell) {
                    $dark++;
                }
            }
        }

        $percent = $dark * 100 / ($size * $size);
        $score += (int) (abs($percent - 50) / 5) * 10;

        return $score;
    }

    // ── ৫ · ফরম্যাট ও সংস্করণের তথ্য ────────────────────────────────────

    /** @param  list<list<bool>>  $m */
    private static function placeFormat(array &$m, int $size, int $mask): void
    {
        $bits = self::formatBits($mask);

        for ($i = 0; $i < 15; $i++) {
            /*
             * ⛔ সবচেয়ে উঁচু বিটটা **আগে** বসে — অর্থাৎ `14 - $i`।
             *
             * ⚠️ প্রথমে সরাসরি `$i` লিখেছিলাম। ⓘ তাতে কেবল সেই
             * বিটগুলো ভুল হত যেগুলোর উল্টো জোড়ার মান আলাদা — বাকিগুলো
             * কাকতালীয়ভাবে মিলে যেত। ⛔ ফলে পার্থক্যটা ছিল মাত্র দুই জোড়া
             * ঘরে — আর ঠিক ওই ঘরগুলোই স্ক্যানারকে বলে কোন মাস্ক খুলতে হবে।
             */
            $on = ($bits >> (14 - $i)) & 1 ? true : false;

            // বাঁ-উপরের L
            if ($i < 6) {
                $m[8][$i] = $on;
            } elseif ($i === 6) {
                $m[8][7] = $on;
            } elseif ($i === 7) {
                $m[8][8] = $on;
            } elseif ($i === 8) {
                $m[7][8] = $on;
            } else {
                $m[14 - $i][8] = $on;
            }

            /*
             * ⭐ আর তার নকল — নিচের-বাঁ কোণে সাতটা, উপরের-ডানে আটটা।
             *
             * ⛔ প্রথম লেখায় দুইটা দিক **উল্টো** ছিল আর ভাগটাও এক ঘর
             * সরে ছিল (`$i < 8`)। ⚠️ ফল: ছকের আর সবকিছু নিখুঁত, কেবল
             * ৮ নম্বর সারি-কলামের কয়েকটা ঘর আলাদা — আর ঐ ঘরগুলোই
             * স্ক্যানারকে বলে কোন মাস্ক খুলতে হবে।
             *
             * ⓘ স্বাধীন এনকোডারের সাথে মিলিয়ে ধরা পড়েছে: ১৬৮১ ঘরের
             * মাত্র ৪টা আলাদা, আর চারটাই (৫,৮)(৭,৮)(৮,৫)(৮,৭)।
             * ⛔ চোখে দেখে এটা কোনোদিন ধরা পড়ত না।
             */
            if ($i < 7) {
                $m[$size - 1 - $i][8] = $on;
            } else {
                $m[8][$size - 15 + $i] = $on;
            }
        }
    }

    private static function formatBits(int $mask): int
    {
        $data = (self::EC_LEVEL_BITS << 3) | $mask;
        $rest = $data << 10;

        for ($i = 4; $i >= 0; $i--) {
            if ($rest & (1 << ($i + 10))) {
                $rest ^= 0b10100110111 << $i;
            }
        }

        return (($data << 10) | $rest) ^ 0b101010000010010;
    }

    /** @param  list<list<bool>>  $m */
    private static function placeVersion(array &$m, int $size, int $version): void
    {
        $rest = $version << 12;

        for ($i = 5; $i >= 0; $i--) {
            if ($rest & (1 << ($i + 12))) {
                $rest ^= 0b1111100100101 << $i;
            }
        }

        $bits = ($version << 12) | $rest;

        for ($i = 0; $i < 18; $i++) {
            $on = ($bits >> $i) & 1 ? true : false;

            $m[$size - 11 + $i % 3][intdiv($i, 3)] = $on;
            $m[intdiv($i, 3)][$size - 11 + $i % 3] = $on;
        }
    }
}
