<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * কিস্তির সূচি — দুই পদ্ধতিতে, আর দুইটা এক নয়।
 *
 * ── কেন এটা আলাদা ফাইলে, আর ডাটাবেজ ছোঁয় না ────────────────────────
 * এখানকার ভুল কোনো ত্রুটিবার্তা দেয় না। শুধু প্রতিটা কিস্তিতে সুদ আর
 * আসলের ভাগটা একটু ভুল হয়, ব্যাংকের কাগজের সাথে মেলে না, আর বছরশেষে
 * লাভ-লোকসানে ভুল সুদ বসে থাকে।
 *
 * বিশুদ্ধ ফাংশন বলে এর পরীক্ষা লেখা যায় — সার্ভিসের ভেতরে থাকলে
 * প্রতিটা পরীক্ষায় একটা ঋণ, একটা কোম্পানি আর একটা খাত বানাতে হত।
 *
 * ── দুইটা পদ্ধতি, আর কেন দুইটাই দরকার ───────────────────────────────
 * **কমতি জের (reducing)** — প্রতি মাসে যতটা বাকি, তার উপর সুদ। কিস্তি
 * একই থাকে, কিন্তু শুরুতে সুদ বেশি আর আসল কম; শেষে উল্টো। বাংলাদেশে
 * বেশিরভাগ ব্যাংক এটাই করে।
 *
 * **ফ্ল্যাট** — পুরো আসলের উপর সারা মেয়াদের সুদ একবারে গুনে কিস্তিতে
 * সমান ভাগ। প্রতিটা কিস্তিতে সুদ একই।
 *
 * একই ১০% সুদে দুইটা সম্পূর্ণ আলাদা টাকা আসে — ফ্ল্যাট প্রায় দ্বিগুণ
 * বেশি। মালিক জানেন না ব্যাংক কোনটা করে (২০২৬-০৮-০৯), তাই দুইটাই আছে
 * আর কাগজ দেখে মিলিয়ে বেছে নেওয়া যায়। একটা ধরে নিলে সংখ্যাটা নীরবে
 * ভুল হত।
 *
 * ── সব অঙ্ক bcmath-এ ────────────────────────────────────────────────
 * float-এ ০.১ + ০.২ ≠ ০.৩। কিস্তির সূচিতে ওই ভগ্নাংশগুলো ষাটবার যোগ
 * হয়ে শেষ কিস্তিতে পয়সার গরমিল হয়ে দাঁড়াত।
 */
final class LoanSchedule
{
    public const REDUCING = 'reducing';

    public const FLAT = 'flat';

    /**
     * পুরো সূচি — প্রতিটা কিস্তির তারিখ, আসল আর সুদ।
     *
     * @return list<array{no:int, due_date:string, principal:string, interest:string}>
     */
    public static function build(
        string $principal,
        string $annualRate,
        int $months,
        Carbon|string $firstDueOn,
        string $method = self::REDUCING,
    ): array {
        if ($months < 1) {
            throw new RuntimeException('A loan needs at least one instalment.');
        }

        if (bccomp($principal, '0', 4) <= 0) {
            throw new RuntimeException('A loan needs a positive amount.');
        }

        $due = $firstDueOn instanceof Carbon ? $firstDueOn->copy() : Carbon::parse($firstDueOn);

        return $method === self::FLAT
            ? self::flat($principal, $annualRate, $months, $due)
            : self::reducing($principal, $annualRate, $months, $due);
    }

    /**
     * কমতি জের — কিস্তি এক, ভেতরের ভাগ বদলায়।
     *
     * EMI = P × i × (1+i)^n / ((1+i)^n − 1), যেখানে i মাসিক সুদের হার।
     *
     * সুদ শূন্য হলে সূত্রটা শূন্য দিয়ে ভাগ করত (উপরে ও নিচে দুইটাই শূন্য
     * হয়ে যায়), তাই ওই ক্ষেত্রটা আলাদা করে ধরা — আত্মীয়ের বিনা সুদের
     * ঋণ বাস্তবে খুবই সাধারণ।
     */
    private static function reducing(string $principal, string $annualRate, int $months, Carbon $due): array
    {
        $monthlyRate = bcdiv($annualRate, '1200', 10);

        $emi = bccomp($monthlyRate, '0', 10) === 0
            ? bcdiv($principal, (string) $months, 4)
            : self::emi($principal, $monthlyRate, $months);

        $rows = [];
        $left = $principal;

        for ($n = 1; $n <= $months; $n++) {
            $interest = self::round(bcmul($left, $monthlyRate, 10));
            $part = bcsub($emi, $interest, 4);

            /*
             * শেষ কিস্তিতে যা বাকি, পুরোটাই।
             *
             * EMI-টা দশমিকের পরে কাটা, তাই ষাট মাস ধরে প্রতিবার সামান্য
             * করে গরমিল জমে। শেষে বাকিটা বসিয়ে দিলে যোগফল ঠিক আসলের
             * সমান হয় — নইলে ঋণ শোধ হওয়ার পরেও খাতায় দুই-চার পয়সা
             * ঝুলে থাকত, আর কেউ বুঝত না কেন।
             */
            if ($n === $months || bccomp($part, $left, 4) > 0) {
                $part = $left;
            }

            $rows[] = [
                'no' => $n,
                'due_date' => $due->toDateString(),
                'principal' => $part,
                'interest' => $interest,
            ];

            $left = bcsub($left, $part, 4);
            $due = $due->copy()->addMonthNoOverflow();
        }

        return $rows;
    }

    /** ফ্ল্যাট — সুদ প্রতি কিস্তিতে সমান, আসলও সমান। */
    private static function flat(string $principal, string $annualRate, int $months, Carbon $due): array
    {
        $years = bcdiv((string) $months, '12', 10);
        $totalInterest = self::round(bcdiv(bcmul(bcmul($principal, $annualRate, 10), $years, 10), '100', 10));

        $perPrincipal = self::round(bcdiv($principal, (string) $months, 10));
        $perInterest = self::round(bcdiv($totalInterest, (string) $months, 10));

        $rows = [];
        $principalLeft = $principal;
        $interestLeft = $totalInterest;

        for ($n = 1; $n <= $months; $n++) {
            // শেষ কিস্তিতে বাকিটা, একই কারণে — ভাগের গরমিল জমতে দেওয়া হয় না
            $p = $n === $months ? $principalLeft : $perPrincipal;
            $i = $n === $months ? $interestLeft : $perInterest;

            $rows[] = [
                'no' => $n,
                'due_date' => $due->toDateString(),
                'principal' => $p,
                'interest' => $i,
            ];

            $principalLeft = bcsub($principalLeft, $p, 4);
            $interestLeft = bcsub($interestLeft, $i, 4);
            $due = $due->copy()->addMonthNoOverflow();
        }

        return $rows;
    }

    /**
     * CC-র মাসিক সুদ — প্রতিদিনের বকেয়ার গড়ের উপর।
     *
     * ── কেন গড়, মাসের শেষের অঙ্ক নয় ────────────────────────────────
     * CC-তে টাকা ওঠানামা করে। মাসের ২৮ দিন পুরো সীমা টেনে রেখে শেষ
     * দুইদিন শোধ করে দিলে মাসশেষের বকেয়া প্রায় শূন্য — অথচ ব্যাংক
     * পুরো মাসের সুদই নেবে, কারণ টাকাটা সারা মাস তার কাছ থেকেই ছিল।
     *
     * তাই দিনগুলোর বকেয়া যোগ করে দিন দিয়ে ভাগ। ব্যাংকও তাই করে।
     *
     * @param  array<string, string>  $dailyBalances  তারিখ => সেদিনের বকেয়া
     */
    public static function interestOnDailyBalance(array $dailyBalances, string $annualRate): string
    {
        $days = count($dailyBalances);

        if ($days === 0) {
            return '0.0000';
        }

        $sum = '0';

        foreach ($dailyBalances as $balance) {
            $sum = bcadd($sum, $balance, 10);
        }

        // যোগফল × হার ÷ ১০০ ÷ ৩৬৫ — দিনভিত্তিক, মাসভিত্তিক নয়, কারণ
        // মাসগুলো সমান দৈর্ঘ্যের নয় আর ব্যাংকও দিন গোনে
        return self::round(bcdiv(bcdiv(bcmul($sum, $annualRate, 10), '100', 10), '365', 10));
    }

    /** EMI-র সূত্র, bcmath-এ। */
    private static function emi(string $principal, string $monthlyRate, int $months): string
    {
        $onePlus = bcadd('1', $monthlyRate, 10);

        $power = '1';
        for ($n = 0; $n < $months; $n++) {
            $power = bcmul($power, $onePlus, 10);
        }

        $top = bcmul(bcmul($principal, $monthlyRate, 10), $power, 10);
        $bottom = bcsub($power, '1', 10);

        return self::round(bcdiv($top, $bottom, 10));
    }

    /** চার দশমিক, আর অর্ধেক উপরের দিকে — টাকার হিসাব যেভাবে হয়। */
    private static function round(string $value): string
    {
        $factor = '10000';
        $scaled = bcmul($value, $factor, 10);
        $half = bccomp($scaled, '0', 10) < 0 ? '-0.5' : '0.5';

        return bcdiv(bcadd($scaled, $half, 10), $factor, 4);
    }
}
