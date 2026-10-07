<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

/**
 * কিস্তির অঙ্ক আর মাসে মাসে তার ভাঙা — ক্ষয়িষ্ণু জেরে।
 *
 * ── ⛔ কী ভুল ছিল, ২১ সেপ্টেম্বর ২০২৬ ─────────────────────────────────
 * আগে সরল সুদে গোনা হত (`আসল × হার × বছর`), অর্থাৎ পুরো আসলের উপর পুরো
 * মেয়াদ ধরে সুদ। ⚠️ মালিক ব্যাংকের ক্যালকুলেটরের ছবি দিয়ে ধরেছেন:
 * ২৫,০০,০০০ · ১৩.৫৫% · ৩৬ কিস্তিতে আমাদের পর্দা বলত ৯৭,৬৭৩.৬১, আর
 * ব্যাংক বলে ৮৪,৮৯৮.৯৬ — মাসে ১৩ হাজার, তিন বছরে ৪.৬ লাখ টাকার ভুল।
 *
 * ── ⓘ কাগজের হার আর গোনার পদ্ধতি এক জিনিস নয় ─────────────────────────
 * মঞ্জুরিপত্রে হারটা বার্ষিক ভাষায় লেখা থাকে, কিন্তু কিস্তি **গোনা** হয়
 * ক্ষয়িষ্ণু জেরে: প্রতি মাসে সুদ বসে ঐ মাসের বকেয়ার উপর, আর বকেয়া
 * প্রতি কিস্তিতে কমে। তাই প্রথম মাসে সুদ বেশি, শেষ মাসে প্রায় কিছুই না।
 *
 *     EMI = P × r × (1+r)^n ÷ ((1+r)^n − 1),  r = বার্ষিক হার ÷ 12 ÷ 100
 *
 * ── ⚠️ ব্যাংকের অ্যাপের সাথে দশ টাকার পার্থক্য কেন ──────────────────
 * ওরা মাসিক হারটা ষষ্ঠ দশমিকে ছেঁটে নেয়, আমরা পূর্ণ নির্ভুলতায় রাখি।
 * ⓘ ফলে ৩৬ মাসে মোট সুদে ~৯.৬৯ টাকার ফারাক হয়। ⛔ পয়সা মেলাতে গিয়ে
 * হারটা ছাঁটবেন না — সেটা ভুলকে নকল করা, আর প্রতিটা ঋণে অন্য রকম ভুল হবে।
 */
final class LoanSchedule
{
    /** ⓘ ভিতরের হিসাব বারো দশমিকে — টাকার ঘরে দুই, কিন্তু পথে নয় */
    private const SCALE = 12;

    /**
     * মাসিক কিস্তির অঙ্ক।
     *
     * ⓘ হার শূন্য হলে সোজা ভাগ — সুদহীন ঋণ বিরল, কিন্তু সূত্রটা তখন
     * শূন্য দিয়ে ভাগ করত।
     */
    public static function instalment(string $principal, string $annualRate, int $months): string
    {
        if ($months < 1 || bccomp($principal, '0', 4) <= 0) {
            return '0.0000';
        }

        $r = self::monthly($annualRate);

        if (bccomp($r, '0', self::SCALE) <= 0) {
            return self::round(bcdiv($principal, (string) $months, self::SCALE));
        }

        $growth = bcpow(bcadd('1', $r, self::SCALE), (string) $months, self::SCALE);

        $top = bcmul(bcmul($principal, $r, self::SCALE), $growth, self::SCALE);
        $bottom = bcsub($growth, '1', self::SCALE);

        return self::round(bcdiv($top, $bottom, self::SCALE));
    }

    /**
     * মাসে মাসে — আসল, সুদ, আর বাকি জের।
     *
     * ── ⭐ শেষ কিস্তিতে অবশিষ্টটা শুষে নেওয়া হয় ─────────────────────
     * প্রতিটা মাসে দুই দশমিকে গোল করা হয়, তাই ছত্রিশ মাসে কয়েক পয়সার
     * ফারাক জমে। ⚠️ শেষ কিস্তিতে জেরটাই আসল ধরা হয়, তাই জের **ঠিক
     * শূন্যে** নামে — নাহলে খাতায় দুই পয়সার একটা ঋণ চিরকাল পড়ে থাকত।
     *
     * @return array{rows: list<array{month: int, principal: string, interest: string, balance: string}>,
     *     instalment: string, interest_total: string, paid_total: string}
     */
    public static function build(string $principal, string $annualRate, int $months): array
    {
        $instalment = self::instalment($principal, $annualRate, $months);
        $r = self::monthly($annualRate);

        $balance = bcadd($principal, '0', self::SCALE);
        $rows = [];
        $interestTotal = '0.00';

        for ($month = 1; $month <= $months; $month++) {
            $interest = self::round(bcmul($balance, $r, self::SCALE));

            $due = $month === $months
                ? bcadd(self::round($balance), $interest, 2)
                : $instalment;

            $paidPrincipal = bcsub($due, $interest, 2);
            $balance = bcsub($balance, $paidPrincipal, self::SCALE);

            /* ⚠️ শেষ মাসে জেরটা হুবহু শূন্য — উপরের শোষণের ফল */
            if ($month === $months) {
                $balance = '0';
            }

            $interestTotal = bcadd($interestTotal, $interest, 2);

            $rows[] = [
                'month' => $month,
                'principal' => $paidPrincipal,
                'interest' => $interest,
                'balance' => self::round($balance),
            ];
        }

        return [
            'rows' => $rows,
            'instalment' => $instalment,
            'interest_total' => $interestTotal,
            'paid_total' => bcadd($principal, $interestTotal, 2),
        ];
    }

    /**
     * বাকি কিস্তিগুলোর সুদের অংশ — আগাম শোধের চার্জ এর উপর বসতে পারে।
     *
     * ⓘ কয়টা কিস্তি দেওয়া হয়ে গেছে, সেটা খাতা থেকে গোনা হয়
     * ([[BankFacilityService::instalmentStanding]]) — এখানে কেবল বাকিগুলো যোগ।
     */
    public static function interestLeft(string $principal, string $annualRate, int $months, int $paid): string
    {
        $left = '0.00';

        foreach (self::build($principal, $annualRate, $months)['rows'] as $row) {
            if ($row['month'] > $paid) {
                $left = bcadd($left, $row['interest'], 2);
            }
        }

        return $left;
    }

    /** বার্ষিক হার → মাসিক ভগ্নাংশ। */
    private static function monthly(string $annualRate): string
    {
        return bcdiv(bcdiv($annualRate, '12', self::SCALE), '100', self::SCALE);
    }

    /** টাকার ঘরে দুই দশমিক — গোল করা, কাটা নয়। */
    private static function round(string $value): string
    {
        return bcadd($value, bccomp($value, '0', self::SCALE) < 0 ? '-0.005' : '0.005', 2);
    }
}
