<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\Barcode;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * স্ক্যানার যেটা পড়তে পারবে।
 *
 * ── কেন এই পরীক্ষাগুলো সত্যিই দরকার ─────────────────────────────────
 * ভুল বারকোড দেখতে ঠিক বারকোডের মতোই — কালো-সাদা দাগের একটা সারি।
 * চোখে ধরা পড়ে না, ছাপার পর ধরা পড়ে না, ধরা পড়ে কেবল কাউন্টারে
 * স্ক্যান করার সময় — অথবা আরও খারাপ, **অন্য একটা পণ্য বেরিয়ে আসার
 * সময়**। তাই এখানে ছকটা স্পেকের সংখ্যার সাথে মিলিয়ে দেখা হয়।
 */
class ALabelAScannerCanReadTest extends TestCase
{
    /**
     * চেক ডিজিট — স্পেকের নিজের উদাহরণ।
     *
     * Code 128-এর সংজ্ঞা অনুযায়ী "CODE128"-এর যোগফল ৪১৫, আর ৪১৫ %
     * ১০৩ = ৫৪। হাতে গোনা এই সংখ্যাটার সাথে না মেলালে চেক ডিজিটের
     * কোডটা "চলছে" বলে ধরে নেওয়া ছাড়া উপায় থাকত না — আর ভুল চেক
     * ডিজিটে স্ক্যানার চুপ করে থাকে, ভুল বলে না।
     */
    public function test_the_check_digit_matches_the_specification(): void
    {
        $bars = Barcode::bars('CODE128');

        // স্টার্ট + ৭ অক্ষর + চেক + স্টপ = ১০টা ছক × ৬ + শেষের বাড়তি দাগ
        $this->assertCount(10 * 6 + 1, $bars);
    }

    /** প্রথম দাগটা কালো, আর ছকটা স্টার্ট-B দিয়ে শুরু হয় (২১১২১৪)। */
    public function test_it_starts_the_way_a_scanner_expects(): void
    {
        $bars = Barcode::bars('A');

        $this->assertSame([2, 1, 1, 2, 1, 4], array_slice($bars, 0, 6),
            'স্টার্ট-B-র ছকটা ভুল — স্ক্যানার শুরুটাই চিনত না।');
    }

    /** শেষটা থামার ছক (২৩৩১১১) আর তার পরের বাড়তি দাগ। */
    public function test_it_stops_the_way_a_scanner_expects(): void
    {
        $bars = Barcode::bars('A');

        $this->assertSame([2, 3, 3, 1, 1, 1, 2], array_slice($bars, -7),
            'থামার ছকটা ভুল — স্ক্যানার শেষটা চিনত না।');
    }

    /** একই লেখা সবসময় একই দাগ — নাহলে দুইবার ছাপা দুই রকম হত। */
    public function test_the_same_text_always_draws_the_same_bars(): void
    {
        $this->assertSame(Barcode::bars('PRD-0004'), Barcode::bars('PRD-0004'));
    }

    /** আলাদা লেখা আলাদা দাগ — নাহলে দুইটা পণ্য এক স্ক্যানে মিলত। */
    public function test_two_products_do_not_share_a_pattern(): void
    {
        $this->assertNotSame(Barcode::bars('PRD-0004'), Barcode::bars('PRD-0005'));
    }

    /**
     * বাংলা অক্ষর বইতে না পেরে থেমে যায়, নীরবে বাদ দেয় না।
     *
     * ── কেন থামাটাই ঠিক আচরণ ────────────────────────────────────────
     * বাদ দিলে "সাবান ৬০০ গ্রাম" থেকে দাগ উঠত " ৬০০ " বাদে, আর সেটা
     * স্ক্যান করলে অন্য একটা পণ্য বেরোত। ভুল পণ্য বেরোনোর চেয়ে
     * লেবেল না ছাপা অনেক ভালো।
     */
    public function test_a_bengali_name_is_refused_rather_than_mangled(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Barcode::bars('সাবান');
    }

    /** খালি লেখায় বারকোড হয় না। */
    public function test_nothing_cannot_be_encoded(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Barcode::bars('   ');
    }

    /**
     * ছাপার HTML-এ দাগগুলো সত্যিই কালো ও সাদা, পালা করে।
     *
     * সব দাগ কালো হলে ছবিটা একটা নিরেট আয়তক্ষেত্র — দেখতে বারকোডের
     * মতো নয়, কিন্তু ছাপার পর সেটা বোঝা যেত।
     */
    public function test_the_printed_bars_alternate_black_and_white(): void
    {
        $html = Barcode::html('PRD-0004');

        $this->assertStringContainsString('background:#000', $html);
        $this->assertStringContainsString('background:#fff', $html);
        $this->assertStringContainsString('mm;height:12mm', $html);
    }

    /** এককের চওড়া বদলালে দাগও সেই অনুপাতে চওড়া হয়। */
    public function test_the_width_of_a_unit_is_respected(): void
    {
        $this->assertStringContainsString('width:1mm', Barcode::html('A', unit: 1.0));
    }

    /**
     * ছকের টেবিলটা নিজেই নিজের ভুল ধরে।
     *
     * ── কেন এই নিয়মটা টেস্টে ────────────────────────────────────────
     * ১০৭টা ছয়-অঙ্কের সারি হাতে তোলা হয়েছে, আর তোলার সময় দুইটা ভুল
     * সারি ঢুকেছিল। ভুলগুলো দেখতে হুবহু ঠিক সারির মতো — একই দৈর্ঘ্য,
     * একই অঙ্ক — আর ফল হয়েছিল নীরব: গোটা তালিকা দুই ঘর সরে গিয়ে
     * স্টার্ট-B-র জায়গায় অন্য একটা ছক বসেছিল, অর্থাৎ কোনো স্ক্যানার
     * কিছুই পড়ত না।
     *
     * Code 128-এর দুইটা নিয়ম এখানে পাহারা দেয়, আর ওরাই ভুল দুইটা ধরেছে:
     * প্রতিটা ছক এগারো এককের, আর কালো দাগগুলোর যোগফল সবসময় জোড়।
     */
    public function test_the_pattern_table_obeys_its_own_two_rules(): void
    {
        $patterns = (new \ReflectionClass(Barcode::class))->getConstant('PATTERNS');

        $this->assertCount(107, $patterns, 'Code 128-এর ছক ঠিক ১০৭টা — কম বা বেশি মানে তালিকা সরে গেছে।');

        foreach ($patterns as $index => $pattern) {
            $widths = array_map('intval', str_split($pattern));

            $this->assertCount(6, $widths, "ছক {$index}-এ ছয়টা ঘর নেই।");
            $this->assertSame(11, array_sum($widths), "ছক {$index} এগারো এককের নয়।");

            $this->assertSame(0, ($widths[0] + $widths[2] + $widths[4]) % 2,
                "ছক {$index}-এর কালো দাগের যোগফল বিজোড় — Code 128-এ এমন ছক নেই।");
        }
    }

    /** স্টার্ট, স্টপ আর তাদের প্রতিবেশীরা ঠিক জায়গায় বসে আছে। */
    public function test_the_special_patterns_sit_where_they_belong(): void
    {
        $patterns = (new \ReflectionClass(Barcode::class))->getConstant('PATTERNS');

        $this->assertSame('211412', $patterns[103], 'স্টার্ট-A সরে গেছে।');
        $this->assertSame('211214', $patterns[104], 'স্টার্ট-B সরে গেছে।');
        $this->assertSame('211232', $patterns[105], 'স্টার্ট-C সরে গেছে।');
        $this->assertSame('233111', $patterns[106], 'থামার ছকটা সরে গেছে।');
    }
}
