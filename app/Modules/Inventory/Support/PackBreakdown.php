<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

/**
 * ১৯৩ পিস নয় — **৮ কার্টন ১ পিস**। ২০ সেপ্টেম্বর ২০২৬।
 *
 * ── ⛔ কেন এটা লাগল ───────────────────────────────────────────────────
 * মালিকের কথা: *"১ কার্টন = ৬ পিস, ৮ পিস, ১২ পিস, ২৪, ৪৮, ৭২ — এরকম
 * বিভিন্ন টাইপের হইতে পারে… যখন যে আকৃতির মন চায় সেই আকৃতির সেই
 * কোম্পানি তৈরি করে, আমার কি হাত আছে ভাই।"*
 *
 * ⓘ মাপটা পণ্যভেদে আলাদা — সেটা আগেই হয়েছে। ⚠️ কিন্তু পর্দা এখনো
 * গুনতিটা **ভিত্তি এককে** দেখায়, আর ডিপোর মালিক মনে মনে কার্টনেই
 * গোনেন। "১৯৩ পিস" পড়ে গুদামে গিয়ে মেলানো যায় না; "৮ কার্টন ১ পিস"
 * পড়ে যায়।
 *
 * ── ⭐ অঙ্কটা এখানে, ডাটাবেস ছাড়া ───────────────────────────────────
 * সিঁড়িটা ([[PackConversion::ladder()]]) সারি থেকে আসে, কিন্তু ভাগটা
 * শুদ্ধ অঙ্ক। ⓘ আলাদা রাখায় এটা ডাটাবেস ছাড়াই মাপা যায়, আর ভাঙলে
 * সাথে সাথে ধরা পড়ে।
 *
 * ⚠️ bcmath, float নয় — ০.১ + ০.২ যেখানে ০.৩ হয় না, সেখানে মজুদ
 * গোনা যায় না ([[MoneyIsNeverAFloatTest]] একই কারণে লেখা)।
 */
final class PackBreakdown
{
    /**
     * ভিত্তি এককের একটা পরিমাণকে বড় প্যাক থেকে ছোট প্যাকে ভাঙা।
     *
     * ⛔ ঋণাত্মক মজুদও দেখাতে হয় (কেউ না থাকা মাল বেচে ফেলেছেন), তাই
     * চিহ্নটা আলাদা করে রাখা হয় আর প্রতিটা ধাপে ফিরিয়ে দেওয়া হয় না —
     * "−৮ কার্টন −১ পিস" পড়তে গেলে মানুষ থমকান।
     *
     * @param  list<array{unit: mixed, factor: string}>  $ladder  বড় থেকে ছোট
     * @return list<array{unit: mixed, qty: string}> যে ধাপে শূন্য, সে ধাপ আসে না
     */
    public static function split(string $qty, array $ladder, bool $negative = false): array
    {
        $left = bcadd($qty, '0', 6);
        $negative = $negative || bccomp($left, '0', 6) < 0;

        if ($negative) {
            $left = bcmul($left, '-1', 6);
        }

        $out = [];
        $last = null;

        foreach ($ladder as $step) {
            $factor = bcadd((string) $step['factor'], '0', 6);

            if (bccomp($factor, '0', 6) <= 0) {
                continue;
            }

            $last = $step;

            /*
             * ⓘ `bcdiv(…, 0)` ভাগফলের দশমিক ফেলে দেয় — অর্থাৎ নিচে
             * নামানো, আর ঠিক সেটাই চাই: ১৯৩ ÷ ২৪ = ৮ কার্টন, বাকিটা
             * পরের ধাপে।
             */
            $whole = bcdiv($left, $factor, 0);

            if (bccomp($whole, '0', 0) > 0) {
                $out[] = ['unit' => $step['unit'], 'qty' => $whole];
                $left = bcsub($left, bcmul($whole, $factor, 6), 6);
            }
        }

        /*
         * ⚠️ যা ভাগ হয়নি তা হারিয়ে যেতে পারে না। ⓘ ভগ্নাংশে বিক্রি হয়
         * এমন পণ্যে (কেজি, লিটার) শেষে কিছু পড়ে থাকে — সেটা সবচেয়ে ছোট
         * ধাপেই বসে, দশমিক সহ। ⛔ ফেলে দিলে যোগফল মিলত না, আর পর্দা
         * চুপচাপ কম দেখাত।
         */
        if ($last !== null && bccomp($left, '0', 6) !== 0) {
            $out[] = ['unit' => $last['unit'], 'qty' => self::tidy($left)];
        }

        // কিছুই না থাকলে "০" বলা হয়, ফাঁকা নয় — ফাঁকা ঘর মানে "জানা নেই"
        if ($out === [] && $last !== null) {
            $out[] = ['unit' => $last['unit'], 'qty' => '0'];
        }

        if ($negative) {
            foreach ($out as $i => $step) {
                $out[$i]['qty'] = '-'.$step['qty'];

                break;   // চিহ্নটা কেবল প্রথম ধাপে
            }
        }

        return $out;
    }

    /** শেষের অপ্রয়োজনীয় শূন্যগুলো ফেলে দেওয়া — ২.৫০০০০০ নয়, ২.৫। */
    private static function tidy(string $value): string
    {
        return str_contains($value, '.')
            ? rtrim(rtrim($value, '0'), '.')
            : $value;
    }
}
