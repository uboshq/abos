<?php

declare(strict_types=1);

namespace Tests\Unit\Finance;

use App\Modules\Finance\Services\ProfitSplit;
use PHPUnit\Framework\TestCase;

/**
 * ⭐ লাভ ভাগ করলে এক পয়সাও হারায় না — ২০ সেপ্টেম্বর ২০২৬।
 *
 * ⓘ ডেটাবেস ছাড়া পরীক্ষা: অঙ্কটাই এখানে পুরো বিষয়, আর সেটা কোনো সারি
 * ছাড়াই মাপা যায়। ⚠️ তিনজনে সমান ভাগে ১০০ টাকা — সোজা গুণ-ভাগে যোগফল
 * ৯৯.৯৯৯৯, আর হারানো পয়সাটা কেউ খুঁজে পায় না।
 */
final class NobodyLosesThePaisaWhenProfitIsSharedTest extends TestCase
{
    private ProfitSplit $split;

    protected function setUp(): void
    {
        parent::setUp();
        $this->split = new ProfitSplit;
    }

    /** সব ভাগের যোগ। */
    private function sum(array $amounts): string
    {
        return array_reduce($amounts, fn (string $s, string $a) => bcadd($s, $a, 4), '0');
    }

    /**
     * ⭐ তিনজনে সমান — যোগফল হুবহু ১০০, আর পয়সাটা একজনই পান।
     */
    public function test_three_equal_shares_still_add_up_to_the_whole(): void
    {
        $out = $this->split->byShares('100', [7 => '33.3333', 8 => '33.3333', 9 => '33.3334']);

        $this->assertSame('100.0000', $this->sum($out['amounts']));
        $this->assertSame('100.0000', $out['allocated']);
        $this->assertSame('0.0000', $out['unallocated']);
    }

    /**
     * ⭐ ঠিক ততজনই এক ধাপ বেশি পান, যত পয়সা বাকি ছিল।
     */
    public function test_the_leftover_paisa_goes_to_the_biggest_fraction(): void
    {
        // ১০০ ÷ ৩ = ৩৩.৩৩৩৩৩৩… — তিনজনেই সমান ৩৩.৩৩৩৩%, বাকি ০.০০০১
        $out = $this->split->byShares('100', [1 => '33.3333', 2 => '33.3333', 3 => '33.3333']);

        $amounts = $out['amounts'];
        sort($amounts);

        $this->assertSame(['33.3333', '33.3333', '33.3333'], $amounts,
            'কারও ভাগ বদলে গেছে — শতাংশের যোগ ১০০-র কম, তাই বাড়তি কিছু দেওয়ার কথা নয়।');

        $this->assertSame('99.9999', $out['allocated']);
        $this->assertSame('0.0001', $out['unallocated'],
            'যার অংশ কেউ নেয়নি, সেটা চুপচাপ কাউকে দেওয়া হয়েছে।');
    }

    /**
     * ⭐ যেখানে সত্যিই পয়সা বাকি পড়ে — আর সেগুলো হাতবদল হয়।
     *
     * ── ⛔ কেন এই কেসটা আলাদা করে লাগল ──────────────────────────────────
     * উপরের কেসগুলোয় ভাগশেষ **শূন্য**: ১০০ টাকা ৩৩.৩৩৩৩/৩৩.৩৩৩৩/৩৩.৩৩৩৪
     * ভাগে ভাগ করলে চার ঘরেই হুবহু মেলে। ⚠️ তাই largest-remainder লুপটা
     * পুরো মুছে ফেললেও পরীক্ষাগুলো সবুজ থাকত — মিউটেশনে ধরা পড়েছে
     * (২০ সেপ্টেম্বর ২০২৬)। ⓘ অর্থাৎ যে কারণে ক্লাসটা লেখা, ঠিক সেটাই
     * অপরীক্ষিত ছিল।
     *
     * ⭐ ৯৯৯.৯৯ টাকায় প্রত্যেকের প্রাপ্য চার ঘরের বাইরে যায়: নিচে নামিয়ে
     * যোগ করলে ৯৯৯.৯৮৯৮, অর্থাৎ **দুই পয়সা** কারও হাতে পড়েনি।
     */
    public function test_the_two_paisa_left_over_are_actually_handed_out(): void
    {
        $out = $this->split->byShares('999.99', [1 => '33.3333', 2 => '33.3333', 3 => '33.3334']);

        $this->assertSame('999.9900', $this->sum($out['amounts']),
            'নিচে নামানো ভাগগুলোর যোগ ৯৯৯.৯৮৯৮ — দুই পয়সা কেউ পায়নি।');

        /*
         * ⓘ কে পেল সেটাও বাঁধা: ১ ও ২-এর কাটা অংশ বড় (০.০০০০৬৬৬৭),
         * ৩-এর ছোট (০.০০০০৬৬৬৬)। ⚠️ ১ ও ২ সমান, তাই ছোট id আগে — নইলে
         * একই ইনপুটে দুইবার দুই রকম কাগজ হত।
         */
        $this->assertSame('333.3297', $out['amounts'][1]);
        $this->assertSame('333.3297', $out['amounts'][2]);
        $this->assertSame('333.3306', $out['amounts'][3], 'সবচেয়ে ছোট ভগ্নাংশ যার, বাড়তি পয়সা তার পাওয়ার কথা নয়।');
    }

    /**
     * ⭐ অসম ভাগেও যোগফল মেলে — ৫০/৩০/২০-তে ৯৯৯.৯৯ টাকা।
     */
    public function test_uneven_shares_add_up_to_the_paisa(): void
    {
        $out = $this->split->byShares('999.99', [1 => '50', 2 => '30', 3 => '20']);

        $this->assertSame('999.9900', $this->sum($out['amounts']));
        $this->assertSame('499.9950', $out['amounts'][1]);
    }

    /**
     * ⓘ শতাংশের যোগ ১০০-র কম — বাকিটা কারও নয়, আর সেটা আলাদা করে বলা হয়।
     */
    public function test_what_nobody_owns_is_named_not_given_away(): void
    {
        $out = $this->split->byShares('1000', [1 => '60', 2 => '30']);

        $this->assertSame('900.0000', $this->sum($out['amounts']));
        $this->assertSame('900.0000', $out['allocated']);
        $this->assertSame('100.0000', $out['unallocated']);
    }

    /**
     * ⛔ লোকসানে বা কারও অংশ না থাকলে কিছু ভাগ হয় না।
     */
    public function test_a_loss_or_no_shares_splits_nothing(): void
    {
        $this->assertSame([], $this->split->byShares('-500', [1 => '100'])['amounts']);
        $this->assertSame([], $this->split->byShares('500', [])['amounts']);
        $this->assertSame([], $this->split->byShares('500', [1 => '0'])['amounts']);
    }

    /**
     * ⭐ একই ইনপুটে সবসময় একই ফল — নইলে দুইবার হিসাব করলে দুই রকম কাগজ।
     */
    public function test_the_same_input_always_gives_the_same_answer(): void
    {
        $first = $this->split->byShares('100', [5 => '33.3333', 6 => '33.3333', 7 => '33.3334']);
        $again = $this->split->byShares('100', [7 => '33.3334', 5 => '33.3333', 6 => '33.3333']);

        ksort($first['amounts']);
        ksort($again['amounts']);

        $this->assertSame($first['amounts'], $again['amounts']);
    }
}
