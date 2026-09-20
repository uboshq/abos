<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory;

use App\Modules\Inventory\Support\PackBreakdown;
use PHPUnit\Framework\TestCase;

/**
 * ⭐ মালিক কার্টনে গোনেন, পিসে নয় — ২০ সেপ্টেম্বর ২০২৬।
 *
 * ⓘ ডাটাবেস ছাড়া পরীক্ষা: ভাগটাই এখানে পুরো বিষয়, আর সেটা কোনো সারি
 * ছাড়াই মাপা যায়। ⚠️ সবচেয়ে জরুরি দাবিটা একটাই — **ভেঙে যোগ করলে
 * আবার একই সংখ্যা**; নইলে পর্দা চুপচাপ কম বা বেশি দেখাবে।
 */
final class TheOwnerCountsInCartonsNotPiecesTest extends TestCase
{
    /** চেনা সিঁড়ি: ১ কার্টন = ২৪ পিস। */
    private function ctn24(): array
    {
        return [
            ['unit' => 'CTN', 'factor' => '24'],
            ['unit' => 'PCS', 'factor' => '1'],
        ];
    }

    /** তিন স্তর: ১ কার্টন = ১২ বাক্স, ১ বাক্স = ২৪ পিস। */
    private function threeSteps(): array
    {
        return [
            ['unit' => 'CTN', 'factor' => '288'],
            ['unit' => 'BOX', 'factor' => '24'],
            ['unit' => 'PCS', 'factor' => '1'],
        ];
    }

    /** ভাগগুলো আবার যোগ করলে কত। */
    private function rebuild(array $steps, array $ladder): string
    {
        $factors = [];

        foreach ($ladder as $one) {
            $factors[$one['unit']] = $one['factor'];
        }

        $sum = '0';

        foreach ($steps as $step) {
            $sum = bcadd($sum, bcmul($step['qty'], $factors[$step['unit']], 6), 6);
        }

        return $sum;
    }

    public function test_one_hundred_ninety_three_pieces_are_eight_cartons_and_one(): void
    {
        $out = PackBreakdown::split('193', $this->ctn24());

        $this->assertSame([
            ['unit' => 'CTN', 'qty' => '8'],
            ['unit' => 'PCS', 'qty' => '1'],
        ], $out);
    }

    /** ⭐ ঠিক মাপে মিললে ছোট ধাপটা আসে না — "৮ কার্টন ০ পিস" কেউ বলে না। */
    public function test_an_exact_carton_says_nothing_about_pieces(): void
    {
        $this->assertSame(
            [['unit' => 'CTN', 'qty' => '8']],
            PackBreakdown::split('192', $this->ctn24()),
        );
    }

    /** ⭐ কার্টনের মাপ পণ্যভেদে আলাদা — একই সংখ্যা, অন্য উত্তর। */
    public function test_the_same_quantity_reads_differently_at_another_carton_size(): void
    {
        $six = [['unit' => 'CTN', 'factor' => '6'], ['unit' => 'PCS', 'factor' => '1']];

        $this->assertSame([
            ['unit' => 'CTN', 'qty' => '32'],
            ['unit' => 'PCS', 'qty' => '1'],
        ], PackBreakdown::split('193', $six));
    }

    /** ⭐ তিন স্তরেও ঠিক — কার্টন, বাক্স, তারপর পিস। */
    public function test_three_steps_fill_from_the_biggest_down(): void
    {
        $out = PackBreakdown::split('700', $this->threeSteps());

        $this->assertSame([
            ['unit' => 'CTN', 'qty' => '2'],
            ['unit' => 'BOX', 'qty' => '5'],
            ['unit' => 'PCS', 'qty' => '4'],
        ], $out, '২×২৮৮ + ৫×২৪ + ৪ = ৭০০');

        $this->assertSame(0, bccomp($this->rebuild($out, $this->threeSteps()), '700', 6));
    }

    /**
     * ⭐⭐ যা ভাঙা হলো, যোগ করলে আবার তাই — সবচেয়ে জরুরি দাবি।
     *
     * ⚠️ একটা ধাপ ফেলে দিলে বা ভগ্নাংশ হারালে পর্দা চুপচাপ কম দেখাত,
     * আর গুদাম মেলাতে গিয়ে কেউ বুঝত না কোথায় গেল।
     */
    public function test_nothing_is_lost_in_the_breaking(): void
    {
        foreach (['0', '1', '23', '24', '25', '193', '288', '999', '10000'] as $qty) {
            $out = PackBreakdown::split($qty, $this->threeSteps());

            $this->assertSame(
                0,
                bccomp($this->rebuild($out, $this->threeSteps()), $qty, 6),
                "{$qty} ভেঙে আবার যোগ করলে মেলেনি।",
            );
        }

        /*
         * ⛔ উপরের সংখ্যাগুলো সবই পূর্ণ, আর সিঁড়ির ছোট ধাপটা ১ — তাই
         * কখনো কিছু বাকিই পড়ে না, আর "কিছু হারায় না" দাবিটা আসলে
         * পরীক্ষাই হত না। ⚠️ মিউটেশনে ধরা পড়েছে (২০ সেপ্টেম্বর ২০২৬):
         * বাকিটুকু ফেলে দেওয়ার শাখাটা মুছে দিলেও উপরের লুপ সবুজ থাকত।
         *
         * ⭐ ভগ্নাংশেই ঐ শাখাটা চলে — কেজি, লিটার, বস্তা।
         */
        $sacks = [['unit' => 'BAG', 'factor' => '25'], ['unit' => 'KG', 'factor' => '1']];

        foreach (['0.5', '24.25', '25.001', '193.75', '999.999'] as $qty) {
            $out = PackBreakdown::split($qty, $sacks);

            $this->assertSame(
                0,
                bccomp($this->rebuild($out, $sacks), $qty, 6),
                "{$qty} ভেঙে আবার যোগ করলে মেলেনি — ভগ্নাংশটা হারিয়েছে।",
            );
        }
    }

    /** ⭐ ভগ্নাংশ হারায় না — কেজি-লিটারে শেষ টুকরাটা দশমিকেই বসে। */
    public function test_a_fraction_survives_at_the_smallest_step(): void
    {
        $out = PackBreakdown::split('50.5', [
            ['unit' => 'BAG', 'factor' => '25'],
            ['unit' => 'KG', 'factor' => '1'],
        ]);

        $this->assertSame([
            ['unit' => 'BAG', 'qty' => '2'],
            ['unit' => 'KG', 'qty' => '0.5'],
        ], $out);
    }

    /** ⛔ শূন্য মানে "শূন্য", ফাঁকা নয় — ফাঁকা ঘর পড়ে মনে হয় জানা নেই। */
    public function test_zero_says_zero(): void
    {
        $this->assertSame([['unit' => 'PCS', 'qty' => '0']], PackBreakdown::split('0', $this->ctn24()));
    }

    /**
     * ⛔ ঋণাত্মক মজুদও দেখাতে হয় — কেউ না থাকা মাল বেচে ফেলেছেন।
     *
     * ⓘ চিহ্নটা কেবল প্রথম ধাপে: "−৮ কার্টন ১ পিস" পড়া যায়,
     * "−৮ কার্টন −১ পিস" পড়তে গিয়ে মানুষ থমকান।
     */
    public function test_a_negative_stock_keeps_its_sign_once(): void
    {
        $this->assertSame([
            ['unit' => 'CTN', 'qty' => '-8'],
            ['unit' => 'PCS', 'qty' => '1'],
        ], PackBreakdown::split('-193', $this->ctn24()));
    }

    /** ⛔ কোনো প্যাক না থাকলেও ভাঙা যায় — তখন কেবল ভিত্তি একক। */
    public function test_a_product_with_no_pack_still_reads(): void
    {
        $this->assertSame(
            [['unit' => 'PCS', 'qty' => '193']],
            PackBreakdown::split('193', [['unit' => 'PCS', 'factor' => '1']]),
        );
    }
}
