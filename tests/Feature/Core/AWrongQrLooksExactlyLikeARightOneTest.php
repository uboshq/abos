<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Support\QrCode;
use RuntimeException;
use Tests\TestCase;

/**
 * একটা ভুল QR দেখতে ঠিক QR-এর মতোই লাগে।
 *
 * ── ⛔ কেন এটা লিখতে হলো, ২৮ সেপ্টেম্বর ২০২৬ ─────────────────────────
 * দুই ধাপের লগইন চালু হওয়ার পর মালিক বত্রিশ অক্ষরের চাবিটা ফোনে
 * **হাতে লিখতে** গিয়ে ভুল করেছেন (O আর 0, I আর 1), আর কোড মেলেনি।
 *
 * ⓘ vendor-এ কোনো QR লাইব্রেরি নেই, লাইভের ডিপ্লয় `composer` চালায়
 * না, আর বাইরের কোনো QR-সেবায় পাঠানো মানে **গোপন চাবিটা ইন্টারনেটে
 * পাঠানো** — যা এই গোটা তালাটার উল্টো। ⭐ তাই [[QrCode]] নিজেদের লেখা।
 *
 * ── ⚠️ এই কোডে ভুল কীভাবে ধরা পড়ে ───────────────────────────────────
 * ⛔ চোখে দেখে নয়। একটা বিট ভুল বসলে ছবিটা হুবহু QR-এর মতোই দেখায়,
 * কেবল ফোন পড়তে পারে না।
 *
 * ⓘ লেখার সময় ঠিক তাই ঘটেছে — দুইবার। প্রথমে ফরম্যাট-বিটের দুইটা
 * নকল উল্টো কোণে বসেছিল, তারপর বিটগুলো উল্টো ক্রমে। ⚠️ দ্বিতীয়বারের
 * চিহ্ন ছিল ১৬৮১ ঘরের মাত্র **৪টা** — আর ঠিক ঐ চারটা ঘরই স্ক্যানারকে
 * বলে কোন মাস্ক খুলতে হবে।
 *
 * ── ⭐ তাই দাবিটা বাইরের সাক্ষী ধরে ──────────────────────────────────
 * `tests/Fixtures/qr/`-এর ছকগুলো **আমাদের কোডের নয়** — পাইথনের
 * সুপরিচিত `qrcode` লাইব্রেরি থেকে তোলা, ঘর ধরে ধরে। ⓘ নিজের কোড
 * দিয়ে ফিকশ্চার বানালে দাবিটা কেবল বলত *"কোড নিজের সাথে একমত"*।
 *
 * ── ⓘ আর একটা যাচাই, এই সুইটের বাইরে ────────────────────────────────
 * ⭐ নয়টা আলাদা লেখার QR **OpenCV-এর ডিকোডার** দিয়ে পড়ে হুবহু মূল
 * লেখাটাই ফিরে এসেছে (৯/৯) — অর্থাৎ ফোনও পড়বে। ⚠️ ওটা সুইটে রাখা
 * হয়নি, কারণ OpenCV এই প্রকল্পের নির্ভরতা নয়; সংখ্যাটা কমিট-বার্তায়।
 */
final class AWrongQrLooksExactlyLikeARightOneTest extends TestCase
{
    // ── ⭐ বাইরের এনকোডারের সাথে ঘর ধরে মিল ──────────────────────────

    public function test_the_owners_own_setup_uri_matches_an_independent_encoder(): void
    {
        [$text, $expected] = $this->fixture('otpauth-v6');

        $this->assertSame($expected, $this->rows(QrCode::matrix($text)), implode(PHP_EOL, [
            'মালিকের পর্দায় যে QR ছাপা হয়, সেটা স্বতন্ত্র এনকোডারের সাথে মেলে না।',
            '',
            '⛔ একটা ঘর ভুল হলেও ছবিটা দেখতে নিখুঁত QR — ধরা পড়বে কেবল',
            'মালিক স্ক্যান করতে গিয়ে ব্যর্থ হলে, আর ততক্ষণে তিনি আটকে গেছেন।',
        ]));
    }

    /**
     * ⓘ একটা সংস্করণ মিললে বলা যায় না গণিতটা সব মাপে চলে — ব্লকের ভাগ,
     * সারিবদ্ধকরণের ছক আর অবশিষ্ট বিট সংস্করণভেদে বদলায়।
     *
     * ⚠️ সংস্করণ ৮ আর ১০-এ **দুইটা দলে** ব্লক ভাগ হয় (আলাদা মাপের), আর
     * সংস্করণ ৭ থেকে ছকে সংস্করণের নিজের তথ্যও বসে — ওগুলো ছোট
     * সংস্করণে কখনো চলেই না।
     */
    public function test_every_other_version_matches_as_well(): void
    {
        foreach (['bytes-v2', 'bytes-v8', 'bytes-v10'] as $name) {
            [$text, $expected] = $this->fixture($name);

            $this->assertSame($expected, $this->rows(QrCode::matrix($text)),
                "`{$name}` স্বতন্ত্র এনকোডারের সাথে মেলে না।");
        }
    }

    // ── ⓘ গঠন ────────────────────────────────────────────────────────

    public function test_the_three_finder_squares_are_where_a_scanner_looks(): void
    {
        /*
         * ⓘ স্ক্যানার আগে এই তিনটা চৌকো খুঁজে ছবিটা সোজা করে। ⛔ এগুলো
         * ভুল হলে বাকি সব নিখুঁত হলেও কিছুই পড়া যায় না।
         */
        [$text] = $this->fixture('otpauth-v6');

        $m = QrCode::matrix($text);
        $size = count($m);

        foreach ([[0, 0], [$size - 7, 0], [0, $size - 7]] as [$x, $y]) {
            $this->assertTrue($m[$y][$x], 'ফাইন্ডারের কোণা কালো নয়।');
            $this->assertTrue($m[$y + 3][$x + 3], 'ফাইন্ডারের ভিতরের চৌকো কালো নয়।');
            $this->assertFalse($m[$y + 1][$x + 1], 'ফাইন্ডারের সাদা বলয়টা নেই।');
        }
    }

    public function test_a_longer_uri_moves_up_a_version_instead_of_losing_bytes(): void
    {
        /*
         * ⚠️ সংস্করণ বাছাই ভুল হলে লেখার শেষাংশ নীরবে কাটা যেত, আর QR
         * তবু বৈধ থাকত — কেবল অ্যাপে ভুল চাবি বসত, আর প্রতিটা কোড
         * ভুল আসত।
         */
        $this->assertSame(1, QrCode::versionFor(str_repeat('a', 14)));
        $this->assertSame(2, QrCode::versionFor(str_repeat('a', 15)));
        $this->assertSame(6, QrCode::versionFor(str_repeat('a', 106)));
        $this->assertSame(7, QrCode::versionFor(str_repeat('a', 107)));
        $this->assertSame(10, QrCode::versionFor(str_repeat('a', 213)));
    }

    public function test_more_than_it_can_hold_is_refused_rather_than_truncated(): void
    {
        /*
         * ⭐ থামাটাই সৎ উত্তর। ⛔ চুপচাপ কেটে দিলে পর্দায় একটা সুন্দর QR
         * থাকত যা ভুল চাবি বহন করে — আর সেটা ধরা পড়ত কেবল মালিক লগইন
         * করতে না পারলে।
         */
        $this->expectException(RuntimeException::class);

        QrCode::versionFor(str_repeat('a', 214));
    }

    public function test_the_svg_carries_a_quiet_border_and_no_inline_style(): void
    {
        [$text] = $this->fixture('otpauth-v6');

        $svg = QrCode::svg($text, scale: 4, quiet: 4);

        $this->assertStringStartsWith('<svg ', $svg);
        $this->assertStringContainsString('</svg>', $svg);

        /*
         * ⓘ শান্ত কিনারাটা অলংকার নয় — ওটা ছাড়া স্ক্যানার প্রান্তের
         * ঘরগুলো খুঁজে পায় না। ৪১ ঘর + দুই পাশে ৪ = ৪৯, গুণ ৪ = ১৯৬।
         */
        $this->assertStringContainsString('width="196"', $svg);

        /* ⛔ কোনো ইনলাইন style বা script নেই — CSP-তে কিছু খুলতে হয় না */
        $this->assertStringNotContainsString('style=', $svg);
        $this->assertStringNotContainsString('<script', $svg);
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /**
     * একটা বাইরের ছক — লেখা আর সারিগুলো।
     *
     * @return array{0: string, 1: list<string>}
     */
    private function fixture(string $name): array
    {
        $path = base_path("tests/Fixtures/qr/{$name}.txt");

        $this->assertFileExists($path, "বাইরের ছকটাই নেই: {$name}");

        $text = null;
        $rows = [];

        foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
            if (str_starts_with($line, '#') || $line === '') {
                continue;
            }

            if (str_starts_with($line, 'text: ')) {
                $text = substr($line, 6);

                continue;
            }

            $rows[] = $line;
        }

        $this->assertNotNull($text, "ছকটায় কোনো লেখা নেই: {$name}");

        /*
         * ⚠️ ফাইলটা খালি হলে নিচের `assertSame` দুইটা খালি তালিকা মিলিয়ে
         * সবুজ হত — আর দাবিটা কিছুই না দেখে পাস করত।
         */
        $this->assertGreaterThan(20, count($rows), "ছকটা খুব ছোট — পড়া কি ভেঙেছে? {$name}");

        return [$text, $rows];
    }

    /**
     * @param  list<list<bool>>  $matrix
     * @return list<string>
     */
    private function rows(array $matrix): array
    {
        return array_map(
            fn (array $row) => implode('', array_map(fn (bool $cell) => $cell ? '1' : '0', $row)),
            $matrix,
        );
    }
}
