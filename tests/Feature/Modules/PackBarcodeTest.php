<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Modules\Inventory\Services\PackBarcode;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * এক স্ক্যানে লট আর মেয়াদ — নইলে FEFO অলঙ্কার।
 *
 * ড্রপডাউন থেকে লট বাছতে বললে ব্যস্ত ক্যাশিয়ার প্রতিবার প্রথমটাই
 * বাছেন। প্যাকেটের গায়ের ২ডি বারকোডই একমাত্র উপায় যাতে সঠিক লটটা
 * নিজে থেকে বসে।
 *
 * সবচেয়ে জরুরি দুইটা পরীক্ষা সেগুলো যেখানে সরল `strptime` ভুল করত:
 * DD শূন্য, আর শতকহীন বছর।
 */
class PackBarcodeTest extends TestCase
{
    /** আসল স্ক্যানে GTIN সবসময় থাকে, তাই পরীক্ষাগুলোও তাই ধরে। */
    private const GTIN = '0108901234567890';

    private function read(string $scanned): array
    {
        return app(PackBarcode::class)->read($scanned);
    }

    // ── সাধারণ স্ক্যান ────────────────────────────────────────────

    public function test_one_scan_gives_product_expiry_and_lot(): void
    {
        $pack = $this->read('01089012345678901726123110ABC123');

        $this->assertSame('08901234567890', $pack['gtin']);
        $this->assertSame('2026-12-31', $pack['expiry_date']->toDateString());
        $this->assertSame('ABC123', $pack['batch_no']);
        $this->assertTrue($pack['is_gs1']);
    }

    public function test_a_symbology_prefix_is_stripped(): void
    {
        $this->assertSame('08901234567890', $this->read(']d2'.self::GTIN)['gtin']);
    }

    public function test_a_separator_closes_a_variable_length_field(): void
    {
        $pack = $this->read(self::GTIN.'10ABC123'.PackBarcode::SEPARATOR.'21SN0001');

        $this->assertSame('ABC123', $pack['batch_no']);
        $this->assertSame('SN0001', $pack['serial_no']);
    }

    public function test_field_order_does_not_matter(): void
    {
        $pack = $this->read('10ABC123'.PackBarcode::SEPARATOR.self::GTIN.'17261231');

        $this->assertSame('ABC123', $pack['batch_no']);
        $this->assertSame('2026-12-31', $pack['expiry_date']->toDateString());
    }

    // ── যে দুইটা নিয়ম strptime জানে না ────────────────────────────

    /**
     * DD = ০০ মানে ওই মাসের শেষ দিন।
     *
     * GS1-এর নিয়ম, আর এটা গোল করার সূক্ষ্মতা নয়: ২৬১২০০ লেখা প্যাকেট
     * ৩১ ডিসেম্বর পর্যন্ত ভালো। ১ তারিখ ধরলে এক মাসের বেচার মতো ওষুধ
     * কাউন্টারে ফিরিয়ে দেওয়া হত; ভুল ধরলে প্যাকেটটাই বেচা যেত না।
     */
    public function test_day_zero_means_the_last_day_of_that_month(): void
    {
        $this->assertSame('2026-12-31', $this->read(self::GTIN.'17261200')['expiry_date']->toDateString());
    }

    /** ফেব্রুয়ারিতে অধিবর্ষ জানা থাকে — নাহলে চার বছরে একবার একদিন আগে মেয়াদ শেষ। */
    public function test_day_zero_in_february_knows_about_leap_years(): void
    {
        $this->assertSame('2028-02-29', $this->read(self::GTIN.'17280200')['expiry_date']->toDateString());
        $this->assertSame('2027-02-28', $this->read(self::GTIN.'17270200')['expiry_date']->toDateString());
    }

    /**
     * শতকহীন বছর — পঞ্চাশ বছরের জানালা।
     *
     * "২০" + YY লিখলে ২০৫১ পর্যন্ত চলত, তারপর প্রতিটা মেয়াদ নব্বই বছর
     * আগের হয়ে যেত — অর্থাৎ দোকানের সব প্যাকেট একসাথে মেয়াদোত্তীর্ণ।
     */
    public function test_a_two_digit_year_uses_the_fifty_year_window(): void
    {
        $this->assertSame('2049-12-31', $this->read(self::GTIN.'17491231')['expiry_date']->toDateString());
        $this->assertSame('1999-12-31', $this->read(self::GTIN.'17991231')['expiry_date']->toDateString());
    }

    // ── যা GS1 নয় ─────────────────────────────────────────────────

    /**
     * সাধারণ EAN-13 GS1 হিসেবে পড়া হয় না।
     *
     * ফার্মেসির অ-ওষুধ মালের প্রায় সবটারই একটা আছে। GS1 ধরে পড়লে তার
     * প্রথম দুই অঙ্ক একটা AI হয়ে যেত আর উত্তরটা হত আত্মবিশ্বাসী ও ভুল
     * — কোনো উত্তর না পাওয়ার চেয়ে খারাপ, কারণ কিছুই ভাঙা দেখায় না।
     */
    public function test_a_plain_ean13_is_not_read_as_gs1(): void
    {
        $pack = $this->read('8901234567890');

        $this->assertFalse($pack['is_gs1']);
        $this->assertNull($pack['gtin']);
        $this->assertNull($pack['batch_no']);
    }

    public function test_an_empty_scan_is_not_an_error(): void
    {
        $this->assertFalse($this->read('')['is_gs1']);
    }

    // ── যেখানে থামে, আন্দাজ করে না ────────────────────────────────

    public function test_an_unknown_identifier_stops_rather_than_guessing(): void
    {
        $this->expectException(ValidationException::class);

        $this->read('9901234567');
    }

    public function test_a_truncated_fixed_field_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->read('01089012345');
    }

    public function test_a_month_of_thirteen_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->read(self::GTIN.'17261331');
    }

    /** ৩১ ফেব্রুয়ারি। নীরবে ২৮-এ সরালে কারও ওষুধের মেয়াদ বদলে যেত। */
    public function test_a_day_that_does_not_exist_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->read(self::GTIN.'17260231');
    }

    // ── মাঠের সবচেয়ে সাধারণ ভুল সেটিং ────────────────────────────

    /**
     * স্ক্যানার FNC1 না পাঠালে ভুলটা **দেখা যায়**।
     *
     * লট নম্বরের শেষে মেয়াদটা লেগে থাকে, আর অপারেটর বোঝেন স্ক্যানার
     * সেট করতে হবে। বিশ অক্ষরে কেটে দিলে একটা বিশ্বাসযোগ্য লট নম্বর
     * তৈরি হত যেটা বাক্সের গায়েরটা নয় — আর কেউ কোনোদিন টের পেত না।
     */
    public function test_a_scanner_without_the_separator_is_visibly_wrong(): void
    {
        $pack = $this->read(self::GTIN.'10ABC12317261231');

        $this->assertSame('ABC12317261231', $pack['batch_no']);
        $this->assertNull($pack['expiry_date']);
    }
}
