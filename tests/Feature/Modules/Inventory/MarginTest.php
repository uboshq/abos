<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Modules\Inventory\Support\Margin;
use PHPUnit\Framework\TestCase;

/**
 * ক্রয়, বিক্রয়, মার্কআপ, মার্জিন — সূত্রটা এক জায়গায়, আর পাঁচটা ফাঁদই আটকানো।
 *
 * ফাঁদগুলো NEXUS-এর PricingEngine যেখানে পড়েছিল সেখান থেকে নেওয়া — কোড
 * নয়, শুধু গর্তের তালিকা। প্রতিটার আলাদা পরীক্ষা, বিশেষ করে মার্জিন ১০০।
 *
 * DB লাগে না — বিশুদ্ধ হিসাব, তাই PHPUnit\TestCase (Laravel bootstrap নয়)।
 */
class MarginTest extends TestCase
{
    /** ১০০ টাকার মাল ১৫০-এ: মার্কআপ ৫০%, মার্জিন ৩৩.৩৩% — দুইটা আলাদা। */
    public function test_markup_and_margin_are_not_the_same_number(): void
    {
        $this->assertSame('50.0000', Margin::markup('100', '150'));
        $this->assertSame('33.3333', Margin::margin('100', '150'));
    }

    public function test_sale_comes_back_from_either_percentage(): void
    {
        // ক্রয় ১০০, বিক্রয় ১২৫ → মার্কআপ ২৫%, মার্জিন ২০% (দুইটাই ভাগশেষহীন)
        $this->assertSame('25.0000', Margin::markup('100', '125'));
        $this->assertSame('20.0000', Margin::margin('100', '125'));
        $this->assertSame('125.0000', Margin::saleFromMarkup('100', '25'));
        $this->assertSame('125.0000', Margin::saleFromMargin('100', '20'));
    }

    /** ⚠️ শূন্য আর null আলাদা — "লাভ নেই" ≠ "হিসাব করা যায় না"। */
    public function test_no_profit_is_zero_not_uncomputable(): void
    {
        // ক্রয় ১০০, বিক্রয় ১০০ → লাভ নেই, তাই ০% — null নয়
        $this->assertSame('0.0000', Margin::markup('100', '100'));
        $this->assertSame('0.0000', Margin::margin('100', '100'));
    }

    /** ফাঁদ ১ — ক্রয় শূন্য হলে মার্কআপ অসীম, তাই null। */
    public function test_trap1_zero_cost_has_no_markup(): void
    {
        $this->assertNull(Margin::markup('0', '150'));
        $this->assertNull(Margin::markup('', '150'));
        $this->assertNull(Margin::markup(null, '150'));
    }

    /** ফাঁদ ২ — বিক্রয় শূন্য হলে মার্জিন অসীম, তাই null। */
    public function test_trap2_zero_sale_has_no_margin(): void
    {
        $this->assertNull(Margin::margin('100', '0'));
        $this->assertNull(Margin::margin('100', ''));
        $this->assertNull(Margin::margin('100', null));
    }

    /** ফাঁদ ৩ — মার্কআপ −১০০%-এ দর শূন্য, নিচে ঋণাত্মক; কোনোটাই দর নয়। */
    public function test_trap3_markup_at_or_below_minus_hundred_has_no_price(): void
    {
        $this->assertNull(Margin::saleFromMarkup('100', '-100'));
        $this->assertNull(Margin::saleFromMarkup('100', '-150'));
        // ঠিক উপরে এখনো দর আছে — সীমাটা সঠিক জায়গায়
        $this->assertSame('1.0000', Margin::saleFromMarkup('100', '-99'));
    }

    /**
     * ★ ফাঁদ ৪ — মার্জিন ১০০%-এ হর শূন্য (অসীম), উপরে ঋণাত্মক।
     *
     * সবচেয়ে বেশি ঘটে: কেউ "১০০% লাভ" ভেবে মার্জিনে ১০০ বসান।
     */
    public function test_trap4_margin_at_or_above_hundred_is_infinite(): void
    {
        $this->assertNull(Margin::saleFromMargin('100', '100'));
        $this->assertNull(Margin::saleFromMargin('100', '150'));
        // ৯৯%-এ এখনো একটা (বিশাল) দর আছে — সীমাটা ঠিক ১০০-এ
        $this->assertSame('10000.0000', Margin::saleFromMargin('100', '99'));
    }

    /**
     * ফাঁদ ৫ — এগুলো প্রদর্শিত মান; সংরক্ষিত সত্য কেবল ক্রয় ও বিক্রয় দর।
     *
     * তাই round-trip হুবহু নাও মিলতে পারে (৩৩.৩৩% → ১৩৩.৩৩ → আবার ৩৩.৩৩%
     * নয়)। এখানে শুধু নিশ্চিত করা হয় সংখ্যাটা কাছাকাছি ও সসীম — helper
     * নিজে "সত্য কোনটা" ঠিক করে না, সেটা ফর্মের কাজ।
     */
    public function test_trap5_round_trip_stays_finite_and_close(): void
    {
        $sale = Margin::saleFromMarkup('100', '33.3333');
        $this->assertNotNull($sale);
        // ১৩৩.৩৩-এর কাছাকাছি, অসীম বা বিস্ফোরিত নয়
        $this->assertSame(0, bccomp($sale, '133', 0));
    }

    public function test_non_numeric_input_is_never_a_number(): void
    {
        $this->assertNull(Margin::markup('abc', '150'));
        $this->assertNull(Margin::saleFromMargin('100', 'x'));
    }
}
