<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

/**
 * শতাংশ ধরে টাকা ভাগ — এক পয়সাও না হারিয়ে। ২০ সেপ্টেম্বর ২০২৬।
 *
 * ── ⛔ কেন সোজা গুণ-ভাগে হয় না ───────────────────────────────────────
 * তিনজনে সমান ভাগ, লাভ ১০০ টাকা: প্রত্যেকে ৩৩.৩৩৩৩, যোগ ৯৯.৯৯৯৯।
 * ⚠️ এক পয়সা কোথাও নেই, আর কেউ জানে না কার। ⓘ ছয় মাস পরে ওটাই খাতায়
 * "অব্যাখ্যাত ফাঁক" হয়ে ফেরে — ঠিক যে জিনিসটা মজুদ নামানোর সময় ধরা
 * পড়েছিল ([[PackRebase]])।
 *
 * ── ⭐ তাই সবচেয়ে বড় ভগ্নাংশ যার, পয়সাটা তার ─────────────────────────
 * প্রত্যেকের প্রাপ্য চার ঘরে **নিচে** নামানো হয়, তারপর যত পয়সা বাকি থাকে
 * তত জনকে এক ধাপ (০.০০০১) করে বাড়তি দেওয়া হয় — যাদের কাটা অংশ সবচেয়ে
 * বড় ছিল, তাদের আগে। ⓘ পদ্ধতিটার চেনা নাম "largest remainder", আর
 * নির্বাচনে আসন ভাগেও এটাই ব্যবহার হয়।
 *
 * ⚠️ সমান ভগ্নাংশ হলে কে আগে, সেটাও ঠিক করা আছে (বড় শতাংশ, তারপর id) —
 * নইলে একই ইনপুটে দুইবার দুই ফল আসত, আর কেউ মেলাতে পারত না।
 *
 * ⓘ শতাংশের যোগ ১০০-র কম হলে বাকিটা কারও নয় (`unallocated`) — ওটা জোর
 * করে কাউকে দেওয়া মানে এমন একটা সিদ্ধান্ত বানানো যা কেউ নেয়নি।
 */
final class ProfitSplit
{
    /**
     * ⭐ ওজন ধরে ভাগ — পুরোটাই বিলি হয়, কিছু পড়ে থাকে না।
     *
     * ── ⛔ কেন এটা আলাদা, ২১ সেপ্টেম্বর ২০২৬ ─────────────────────────────
     * [[byShares()]] শতাংশ নেয়, আর শতাংশের যোগ ১০০-র কম হলে বাকিটা
     * ইচ্ছে করেই কারও নয়। ⓘ কিন্তু কখনো প্রশ্নটা উল্টো: *"এই ১০০% কে কত
     * পাবেন, মূলধনের অনুপাতে?"* — সেখানে পুরোটাই বিলি হতে **হবে**।
     *
     * ⚠️ অডিটে ধরা: তিন অংশীদারের সমান মূলধনে অংশ দাঁড়াত ৩৩.৩৩৩৩ করে,
     * যোগ ৯৯.৯৯৯৯%। ⛔ বাকি ০.০০০১% কারও নয়, কোথাও দেখানোও হয় না —
     * দশ লাখ টাকার লাভে সেটা ৳১.০০।
     *
     * ⓘ ওজন যেকোনো সংখ্যা হতে পারে (টাকা, পরিমাণ, নিট মূলধন); যোগফল
     * ১০০ হওয়ার দরকার নেই। শূন্য বা ঋণাত্মক ওজন ভাগে আসে না।
     *
     * @param  array<int|string, string>  $weights  কার কত ওজন (id → ওজন)
     * @return array<int|string, string> id → ভাগ, আর যোগফল হুবহু $amount
     */
    public function byWeights(string $amount, array $weights): array
    {
        $weights = array_filter($weights, fn ($weight) => bccomp((string) $weight, '0', 6) > 0);

        if ($weights === [] || bccomp($amount, '0', 4) <= 0) {
            return [];
        }

        $total = array_reduce($weights, fn (string $sum, $weight) => bcadd($sum, (string) $weight, 6), '0');

        $floors = [];
        $fractions = [];

        foreach ($weights as $id => $weight) {
            $exact = bcdiv(bcmul($amount, (string) $weight, 10), $total, 8);
            $floors[$id] = bcadd(substr($exact, 0, strpos($exact, '.') + 5), '0', 4);
            $fractions[$id] = bcsub($exact, $floors[$id], 8);
        }

        $given = array_reduce($floors, fn (string $sum, string $one) => bcadd($sum, $one, 4), '0');
        $left = (int) bcmul(bcsub(bcadd($amount, '0', 4), $given, 4), '10000', 0);

        foreach (array_slice($this->order($fractions, $weights), 0, max(0, $left)) as $id) {
            $floors[$id] = bcadd($floors[$id], '0.0001', 4);
        }

        return $floors;
    }

    /**
     * @param  array<int|string, string>  $shares  কার কত শতাংশ (id → %)
     * @return array{amounts: array<int|string, string>, allocated: string, unallocated: string}
     */
    public function byShares(string $amount, array $shares): array
    {
        $shares = array_filter($shares, fn ($share) => $share !== null && bccomp((string) $share, '0', 4) > 0);

        if ($shares === [] || bccomp($amount, '0', 4) <= 0) {
            return ['amounts' => [], 'allocated' => '0.0000', 'unallocated' => bcadd($amount, '0', 4)];
        }

        $totalShare = array_reduce($shares, fn (string $sum, $share) => bcadd($sum, (string) $share, 6), '0');

        // যতটুকু ভাগ হবে — শতাংশের যোগ ১০০-র কম হলে বাকিটা কারও নয়
        $target = bcdiv(bcmul($amount, $totalShare, 8), '100', 4);

        $floors = [];
        $fractions = [];

        foreach ($shares as $id => $share) {
            $exact = bcdiv(bcmul($amount, (string) $share, 8), '100', 8);
            $floors[$id] = bcadd(substr($exact, 0, strpos($exact, '.') + 5), '0', 4);
            $fractions[$id] = bcsub($exact, $floors[$id], 8);
        }

        $given = array_reduce($floors, fn (string $sum, string $one) => bcadd($sum, $one, 4), '0');
        $left = (int) bcmul(bcsub($target, $given, 4), '10000', 0);

        /*
         * বড় ভগ্নাংশ আগে; সমান হলে বড় শতাংশ, তারপর id — তিন ধাপেই বাঁধা,
         * তাই ফল সবসময় একই।
         */
        $order = $this->order($fractions, $shares);

        foreach (array_slice($order, 0, max(0, $left)) as $id) {
            $floors[$id] = bcadd($floors[$id], '0.0001', 4);
        }

        return [
            'amounts' => $floors,
            'allocated' => $target,
            'unallocated' => bcsub(bcadd($amount, '0', 4), $target, 4),
        ];
    }

    /**
     * কে আগে বাড়তি ধাপটা পাবে — বড় ভগ্নাংশ, তারপর বড় ওজন, তারপর id।
     *
     * ⚠️ তিন ধাপেই বাঁধা, তাই একই ইনপুটে সবসময় একই ফল। ⓘ নইলে দুইবার
     * হিসাব করলে দুই রকম কাগজ বেরোত, আর কেউ মেলাতে পারত না।
     *
     * @param  array<int|string, string>  $fractions
     * @param  array<int|string, string>  $weights
     * @return list<int|string>
     */
    private function order(array $fractions, array $weights): array
    {
        $order = array_keys($weights);

        usort($order, function ($a, $b) use ($fractions, $weights) {
            $byFraction = bccomp($fractions[$b], $fractions[$a], 8);

            if ($byFraction !== 0) {
                return $byFraction;
            }

            $byWeight = bccomp((string) $weights[$b], (string) $weights[$a], 6);

            return $byWeight !== 0 ? $byWeight : ((string) $a <=> (string) $b);
        });

        return $order;
    }
}
