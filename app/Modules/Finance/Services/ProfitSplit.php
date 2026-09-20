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
        $order = array_keys($shares);

        usort($order, function ($a, $b) use ($fractions, $shares) {
            $byFraction = bccomp($fractions[$b], $fractions[$a], 8);

            if ($byFraction !== 0) {
                return $byFraction;
            }

            $byShare = bccomp((string) $shares[$b], (string) $shares[$a], 6);

            return $byShare !== 0 ? $byShare : ((string) $a <=> (string) $b);
        });

        foreach (array_slice($order, 0, max(0, $left)) as $id) {
            $floors[$id] = bcadd($floors[$id], '0.0001', 4);
        }

        return [
            'amounts' => $floors,
            'allocated' => $target,
            'unallocated' => bcsub(bcadd($amount, '0', 4), $target, 4),
        ];
    }
}
