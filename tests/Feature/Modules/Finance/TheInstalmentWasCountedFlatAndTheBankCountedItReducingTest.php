<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Modules\Finance\Services\LoanSchedule;
use Tests\TestCase;

/**
 * আমরা সরল সুদে গুনতাম, ব্যাংক গোনে ক্ষয়িষ্ণু জেরে।
 *
 * ── ⓘ মালিক ধরেছেন, ২১ সেপ্টেম্বর ২০২৬ ───────────────────────────────
 * তিনি ব্যাংকের ক্যালকুলেটরের ছবি পাঠিয়েছেন। ⛔ একই ইনপুটে আমাদের ফর্ম
 * বলত ৯৭,৬৭৩.৬১, ব্যাংক বলে ৮৪,৮৯৮.৯৬ — মাসে ১৩ হাজার, তিন বছরে
 * ৪.৬ লাখ টাকার ফারাক।
 *
 * ── ⚠️ কাগজের হার আর গোনার পদ্ধতি এক জিনিস নয় ────────────────────────
 * মঞ্জুরিপত্রে হারটা বার্ষিক ভাষায় লেখা থাকে, কিন্তু কিস্তি গোনা হয়
 * ক্ষয়িষ্ণু জেরে — প্রতি মাসে সুদ বসে ঐ মাসের বকেয়ার উপর।
 *
 * ── ⓘ ব্যাংকের অ্যাপের সাথে দশ টাকার ফারাক কেন ───────────────────────
 * ওরা মাসিক হারটা ষষ্ঠ দশমিকে ছেঁটে নেয়, আমরা পূর্ণ নির্ভুলতায় রাখি।
 * ⛔ পয়সা মেলাতে গিয়ে হার ছাঁটা হয়নি — সেটা ভুলকে নকল করা, আর প্রতিটা
 * ঋণে ফারাকটা অন্য রকম হত।
 */
final class TheInstalmentWasCountedFlatAndTheBankCountedItReducingTest extends TestCase
{
    /**
     * ⭐ মালিকের নিজের ঋণ — ২৫,০০,০০০ · ১৩.৫৫% · ৩৬ কিস্তি।
     */
    public function test_the_instalment_matches_the_banks_calculator(): void
    {
        $this->assertSame('84898.69', LoanSchedule::instalment('2500000', '13.55', 36),
            'কিস্তির অঙ্ক ব্যাংকের সাথে মেলে না।');
    }

    /**
     * ⭐ প্রথম মাসে সুদ বেশি, আসল কম — আর শেষ মাসে জের ঠিক শূন্য।
     */
    public function test_the_schedule_walks_from_interest_to_principal(): void
    {
        $schedule = LoanSchedule::build('2500000', '13.55', 36);

        $first = $schedule['rows'][0];
        $last = $schedule['rows'][35];

        // ⓘ ২৫,০০,০০০ × ১৩.৫৫% ÷ ১২ = ২৮,২২৯.১৭ — মালিকের ছবির সংখ্যাটাই
        $this->assertSame('28229.17', $first['interest']);
        $this->assertSame('56669.52', $first['principal']);
        $this->assertSame('2443330.48', $first['balance']);

        // ⚠️ শেষ মাসে সুদ প্রায় কিছুই না, আর জের শূন্যে নামে
        $this->assertSame('0.00', $last['balance'],
            'শেষ কিস্তির পরেও জের বাকি — খাতায় দুই পয়সার ঋণ চিরকাল থেকে যেত।');

        $this->assertSame(36, count($schedule['rows']));
    }

    /**
     * ⭐ মোট সুদ আর মোট পরিশোধ।
     *
     * ⓘ ব্যাংকের অ্যাপ বলে ৫,৫৬,৩৬২.৫৬; আমাদের পূর্ণ-নির্ভুলতার হিসাবে
     * ৫,৫৬,৩৫২.৮৬ — দশ টাকার ফারাক, আর কারণটা উপরে লেখা।
     */
    public function test_the_totals_add_up(): void
    {
        $schedule = LoanSchedule::build('2500000', '13.55', 36);

        $this->assertSame('556352.86', $schedule['interest_total']);
        $this->assertSame('3056352.86', $schedule['paid_total']);

        // ⚠️ আর সারিগুলোর যোগফল মোটের সাথে মেলে — তালিকাটা নিজেই মেলে
        $principal = '0.00';
        $interest = '0.00';

        foreach ($schedule['rows'] as $row) {
            $principal = bcadd($principal, $row['principal'], 2);
            $interest = bcadd($interest, $row['interest'], 2);
        }

        $this->assertSame('2500000.00', $principal, 'আসলের যোগফল ঋণের সমান নয়।');
        $this->assertSame($schedule['interest_total'], $interest);
    }

    /**
     * ⛔ সুদহীন ঋণে সূত্রটা শূন্য দিয়ে ভাগ করত — সোজা ভাগ হয়।
     */
    public function test_a_loan_without_interest_is_just_divided(): void
    {
        $this->assertSame('10000.00', LoanSchedule::instalment('120000', '0', 12));
    }

    /**
     * ⭐ বাকি সুদ — আগাম শোধের চার্জ এর উপর বসতে পারে।
     *
     * ⓘ সাতটা কিস্তি দেওয়া হলে বাকি ২৯টার সুদের যোগফল।
     */
    public function test_the_interest_still_to_come_can_be_counted(): void
    {
        $schedule = LoanSchedule::build('2500000', '13.55', 36);

        $byHand = '0.00';

        foreach ($schedule['rows'] as $row) {
            if ($row['month'] > 7) {
                $byHand = bcadd($byHand, $row['interest'], 2);
            }
        }

        $this->assertSame($byHand, LoanSchedule::interestLeft('2500000', '13.55', 36, 7));

        // ⚠️ আর সেটা মোট সুদের চেয়ে কম — সাত মাসের সুদ তো দেওয়া হয়ে গেছে
        $this->assertSame(-1, bccomp($byHand, $schedule['interest_total'], 2));
    }
}
