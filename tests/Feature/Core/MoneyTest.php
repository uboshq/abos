<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Support\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * টাকার অঙ্ক — গোনা থেকে দেখানো পর্যন্ত float ছোঁয়া ছাড়া।
 *
 * ── কেন এই পরীক্ষাগুলো ─────────────────────────────────────────────
 * প্রকল্পের নিয়ম (সেকশন ৮) ছিল "হিসাব bcmath, কখনো float নয়", আর
 * হিসাবগুলো নিয়ম মানত। ভাঙত শেষ ধাপে — `number_format((float) $x, 2)`।
 *
 * নিচের প্রথম পরীক্ষাটাই সেই ফাটলটা দেখায়: একই সংখ্যা float হয়ে গেলে
 * নিচে গোল হয়, bcmath-এ উপরে। এক পয়সা, কিন্তু মোট আর সারি দুই দিকে
 * গেলে যোগ করলে আর মেলে না — আর তখন কোনটা সত্যি বলার উপায় থাকে না।
 */
class MoneyTest extends TestCase
{
    // ── গোল করা ──────────────────────────────────────────────────

    /**
     * ঠিক-মাঝামাঝি অঙ্ক উপরে ওঠে, যেভাবে হাতে গুনলে উঠত।
     *
     * ── কেন এখানে `number_format`-এর সাথে তুলনা করা হচ্ছে না ────────
     * প্রথমে লিখেছিলাম "পুরনো পথ ১২৩৪.৫৬ দিত" — চালিয়ে দেখা গেল সে
     * ১২৩৪.৫৭-ই দেয়। PHP-র `number_format` ভেতরে একটা সংশোধন করে, তাই
     * সে কোন অঙ্কে হোঁচট খাবে তা মান ধরে ধরে বদলায়।
     *
     * অনুমানের উপর দাঁড়ানো একটা পরীক্ষা পরে যেকোনো দিন ভাঙত, আর তখন
     * মনে হত টাকার হিসাব ভেঙেছে। তাই দাবিটা এখন যা সত্যি কেবল তাই:
     * bcmath-এর উত্তরটা **সংজ্ঞা অনুযায়ী** ঠিক, যন্ত্রের উপর নির্ভর করে না।
     */
    public function test_a_half_paisa_goes_up_not_down(): void
    {
        $this->assertSame('1,234.57', Money::format('1234.565'));
        $this->assertSame('1.01', Money::format('1.005'));
    }

    /**
     * float কেন বাদ — ভাষার নিজের সাক্ষ্য।
     *
     * দশমিক ভগ্নাংশ বাইনারিতে হুবহু বসে না। এটা কোনো যন্ত্রের দোষ নয়,
     * তাই এই পরীক্ষাটা সব মেশিনে একই থাকে — আর এটাই সেই কারণ যার জন্য
     * টাকার প্রতিটা ধাপ স্ট্রিং ও bcmath-এ রাখা।
     */
    public function test_this_is_why_float_is_not_used(): void
    {
        $this->assertNotSame(0.3, 0.1 + 0.2);
        $this->assertSame('0.30', Money::format(bcadd('0.1', '0.2', Money::SCALE)));
    }

    /** ঋণাত্মকে গোল শূন্যের দিকে নয়, দূরে — নাহলে ফেরত-বিলে এক পয়সা হারাত। */
    public function test_a_negative_half_goes_away_from_zero(): void
    {
        $this->assertSame('-1,234.57', Money::format('-1234.565'));
    }

    /** শূন্যের কাছাকাছি ঋণাত্মক '-0.00' হয়ে দেখা দেয় না। */
    public function test_a_negative_speck_does_not_show_as_minus_zero(): void
    {
        $this->assertSame('0.00', Money::format('-0.004'));
    }

    /**
     * @param  int|float|string|null  $input
     */
    #[DataProvider('amounts')]
    public function test_it_formats(mixed $input, string $expected): void
    {
        $this->assertSame($expected, Money::format($input));
    }

    /** @return array<string, array{mixed, string}> */
    public static function amounts(): array
    {
        return [
            'শূন্য' => ['0', '0.00'],
            'null মানে শূন্য' => [null, '0.00'],
            'খালি ঘরও শূন্য' => ['', '0.00'],
            'হাজারে কমা' => ['1000', '1,000.00'],
            'লাখ' => ['125000.5', '125,000.50'],
            'কোটি' => ['12345678.9', '12,345,678.90'],
            'তিন অঙ্কের নিচে কমা নেই' => ['999.999', '1,000.00'],
            'ঋণাত্মক' => ['-2500', '-2,500.00'],
        ];
    }

    /** পরিমাণে চার ঘর লাগে — টুকরো বিক্রিতে ০.৫ কেজি ধরনের অঙ্ক। */
    public function test_it_keeps_four_decimals_when_asked(): void
    {
        $this->assertSame('1.2346', Money::format('1.23455', 4));
        $this->assertSame('12', Money::format('12.4', 0));
    }

    // ── হিসাব ────────────────────────────────────────────────────

    /**
     * বড় অঙ্কে যোগফল হুবহু থাকে।
     *
     * float-এ ১৫–১৭টা অঙ্কের পর নির্ভুলতা শেষ। কোটি টাকার উপরে চার
     * দশমিক ধরলে সেই সীমা ছোঁয়া যায়, আর তখন ভুলটা নিঃশব্দে ঢোকে।
     */
    public function test_a_large_sum_stays_exact(): void
    {
        $rows = [['a' => '99999999.9999'], ['a' => '0.0001']];

        $this->assertSame('100000000.0000', Money::sumOf($rows, fn ($r) => $r['a']));
    }

    /** এক পয়সার শত ভাগ যোগ করলে ঠিক এক পয়সা — float-এ হত না। */
    public function test_hundred_small_parts_make_a_whole(): void
    {
        $rows = array_fill(0, 100, '0.0100');

        $this->assertSame('1.0000', Money::sumOf($rows, fn ($r) => $r));
    }

    /**
     * ঢোকার মুখে কেবল নিরাপদ করা হয়, ঘর কাটা হয় না।
     *
     * ঘর কাটলে ১.২৩৪৫৫ এখানেই ১.২৩৪৫ হয়ে যেত, আর গোল করার সিদ্ধান্তটা
     * নেওয়ার আগেই পয়সাটা চলে যেত।
     */
    public function test_it_makes_a_value_safe_without_trimming_it(): void
    {
        $this->assertSame('12', Money::of('12'));
        $this->assertSame('1.23455', Money::of('1.23455'));
        $this->assertSame('0', Money::of(null));
        $this->assertSame('0', Money::of(''));

        // float ঢুকলে সে স্ট্রিং হয়েই বেরোয় — আর কখনো float নয়
        $this->assertSame('12.5000', Money::of(12.5));
    }
}
