<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Accounts\Services\LoanSchedule;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * কিস্তির অঙ্ক।
 *
 * ── এই ফাইলটা কেন আছে ───────────────────────────────────────────────
 * এখানকার ভুল কোনো ত্রুটিবার্তা দেয় না। প্রতিটা কিস্তিতে সুদ আর আসলের
 * ভাগটা একটু ভুল হয়, ব্যাংকের কাগজের সাথে মেলে না, আর বছরশেষে
 * লাভ-লোকসানে ভুল সুদ বসে থাকে।
 *
 * সবচেয়ে জরুরি পরীক্ষাটা প্রথমেই: একই হারে দুই পদ্ধতিতে দুইটা আলাদা
 * টাকা আসে, আর পার্থক্যটা ছোট নয়।
 */
class LoanScheduleTest extends TestCase
{
    /** @param list<array{no:int,due_date:string,principal:string,interest:string}> $rows */
    private function sum(array $rows, string $key): string
    {
        return array_reduce($rows, fn (string $c, array $r) => bcadd($c, $r[$key], 4), '0');
    }

    // ── দুই পদ্ধতি এক জিনিস নয় ────────────────────────────────────────

    public function test_flat_costs_far_more_than_reducing_at_the_same_rate(): void
    {
        $reducing = LoanSchedule::build('1200000', '12', 36, '2026-09-01', LoanSchedule::REDUCING);
        $flat = LoanSchedule::build('1200000', '12', 36, '2026-09-01', LoanSchedule::FLAT);

        $r = $this->sum($reducing, 'interest');
        $f = $this->sum($flat, 'interest');

        /*
         * ফ্ল্যাটের অঙ্কটা হাতে গোনা যায়, তাই ওটাই আসল পাহারা:
         * ১২,০০,০০০ × ১২% × ৩ বছর = ৪,৩২,০০০।
         */
        $this->assertSame(0, bccomp($f, '432000', 4));

        // কমতি জেরে ২,৩৪,৮৫৮ — কারণ বকেয়া প্রতি মাসে কমে
        $this->assertSame(0, bccomp($r, '234858.1831', 4));

        /*
         * পার্থক্যটাই আসল কথা: একই "১২%" শুনে দুইজন দুইটা আলাদা টাকা
         * দেয়, আর তফাতটা এই ঋণে ১,৯৭,১৪১ টাকা — আসলের ষোলো শতাংশ।
         *
         * একটা পদ্ধতি ধরে নিলে সংখ্যাটা নীরবে ভুল হত, আর ব্যাংকের
         * কাগজের সাথে মেলাতে গিয়ে কেউ বুঝতে পারত না গরমিলটা কোথায়।
         */
        $this->assertSame(0, bccomp(bcsub($f, $r, 4), '197141.8169', 4));
    }

    // ── যোগফল সবসময় আসলের সমান ───────────────────────────────────────

    public function test_the_principal_always_adds_back_to_the_loan(): void
    {
        foreach ([LoanSchedule::REDUCING, LoanSchedule::FLAT] as $method) {
            foreach ([[1200000, 36], [500000, 60], [75000, 7], [1000000, 1]] as [$amount, $months]) {
                $rows = LoanSchedule::build((string) $amount, '11.5', $months, '2026-09-01', $method);

                $this->assertSame(0, bccomp($this->sum($rows, 'principal'), (string) $amount, 4),
                    "{$method}: {$amount} over {$months} months did not add back");

                $this->assertCount($months, $rows);
            }
        }
    }

    /**
     * শেষ কিস্তিতে বাকিটা বসে — পয়সা ঝুলে থাকে না।
     *
     * EMI দশমিকের পরে কাটা, তাই ষাট মাস ধরে সামান্য গরমিল জমে। শেষে
     * বাকিটা বসিয়ে না দিলে ঋণ শোধ হওয়ার পরেও খাতায় দুই-চার পয়সা পড়ে
     * থাকত, আর কেউ বুঝত না কেন।
     */
    public function test_nothing_is_left_hanging_after_the_last_instalment(): void
    {
        $rows = LoanSchedule::build('999999', '13.75', 60, '2026-09-01');

        $this->assertSame(0, bccomp($this->sum($rows, 'principal'), '999999', 4));
    }

    // ── কমতি জেরের আকার ──────────────────────────────────────────────

    public function test_interest_falls_and_principal_rises_month_by_month(): void
    {
        $rows = LoanSchedule::build('1200000', '12', 36, '2026-09-01', LoanSchedule::REDUCING);

        $this->assertSame(1, bccomp($rows[0]['interest'], $rows[35]['interest'], 4),
            'প্রথম মাসের সুদ শেষ মাসের চেয়ে বেশি হওয়ার কথা');

        $this->assertSame(-1, bccomp($rows[0]['principal'], $rows[35]['principal'], 4),
            'প্রথম মাসের আসল শেষ মাসের চেয়ে কম হওয়ার কথা');

        // প্রথম মাসের সুদ = ১২,০০,০০০ × ১% = ১২,০০০
        $this->assertSame(0, bccomp($rows[0]['interest'], '12000', 4));
    }

    public function test_flat_charges_the_same_interest_every_month(): void
    {
        $rows = LoanSchedule::build('1200000', '12', 36, '2026-09-01', LoanSchedule::FLAT);

        // ৪,৩২,০০০ ÷ ৩৬ = ১২,০০০ প্রতি মাসে
        $this->assertSame(0, bccomp($rows[0]['interest'], '12000', 4));
        $this->assertSame(0, bccomp($rows[17]['interest'], $rows[0]['interest'], 4));
    }

    // ── বিনা সুদের ঋণ ────────────────────────────────────────────────

    /**
     * আত্মীয়ের বিনা সুদের ঋণ — বাস্তবে খুবই সাধারণ।
     *
     * EMI-র সূত্রে সুদ শূন্য দিলে উপরে ও নিচে দুইটাই শূন্য হয়ে যায়,
     * অর্থাৎ শূন্য দিয়ে ভাগ। ক্ষেত্রটা আলাদা করে ধরা না থাকলে ঋণ
     * বসানোর মুহূর্তেই থেমে যেত।
     */
    public function test_a_loan_with_no_interest_still_builds(): void
    {
        $rows = LoanSchedule::build('120000', '0', 12, '2026-09-01');

        $this->assertSame(0, bccomp($this->sum($rows, 'interest'), '0', 4));
        $this->assertSame(0, bccomp($this->sum($rows, 'principal'), '120000', 4));
        $this->assertSame(0, bccomp($rows[0]['principal'], '10000', 4));
    }

    // ── তারিখ ────────────────────────────────────────────────────────

    /**
     * ৩১ তারিখে শুরু হওয়া ঋণ ফেব্রুয়ারিতে গিয়ে মার্চে চলে যায় না।
     *
     * addMonth() ৩১ জানুয়ারিতে এক মাস যোগ করে ৩ মার্চ দেয় (ফেব্রুয়ারি
     * ২৮ পেরিয়ে)। তাতে একটা মাসের কিস্তি নীরবে হারিয়ে যেত, আর সূচির
     * বাকি তারিখগুলোও সরে যেত।
     */
    public function test_a_month_end_start_does_not_skip_february(): void
    {
        $rows = LoanSchedule::build('120000', '10', 4, '2026-01-31');

        $this->assertSame('2026-01-31', $rows[0]['due_date']);
        $this->assertSame('2026-02-28', $rows[1]['due_date']);
        $this->assertSame('2026-03-28', $rows[2]['due_date']);
    }

    // ── CC-র সুদ ─────────────────────────────────────────────────────

    /**
     * CC-তে সুদ প্রতিদিনের বকেয়ার উপর, মাসশেষের অঙ্কে নয়।
     *
     * মাসের ২৯ দিন দশ লাখ টেনে রেখে শেষ দিনে শোধ করে দিলে মাসশেষের
     * বকেয়া শূন্য — কিন্তু টাকাটা সারা মাস ব্যাংকের কাছ থেকেই ছিল, আর
     * ব্যাংক পুরো মাসের সুদই নেবে।
     */
    public function test_cc_interest_follows_the_days_not_the_month_end(): void
    {
        $heldAllMonth = array_fill(0, 30, '1000000');
        $paidOnTheLastDay = array_merge(array_fill(0, 29, '1000000'), ['0']);

        $a = LoanSchedule::interestOnDailyBalance($heldAllMonth, '12');
        $b = LoanSchedule::interestOnDailyBalance($paidOnTheLastDay, '12');

        // প্রায় সমান — একদিনের তফাত মাত্র, শূন্য নয়
        $this->assertSame(1, bccomp($b, bcmul($a, '0.9', 4), 4));

        // ১০ লাখ × ৩০ দিন × ১২% ÷ ৩৬৫ = ৯,৮৬৩.০১
        $this->assertSame(0, bccomp($a, '9863.0137', 4));
    }

    public function test_an_untouched_cc_costs_nothing(): void
    {
        $this->assertSame(0, bccomp(
            LoanSchedule::interestOnDailyBalance(array_fill(0, 31, '0'), '12'),
            '0',
            4,
        ));
    }

    // ── অসম্ভব ঋণ ────────────────────────────────────────────────────

    public function test_a_loan_of_nothing_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        LoanSchedule::build('0', '12', 12, '2026-09-01');
    }

    public function test_a_loan_with_no_months_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        LoanSchedule::build('100000', '12', 0, '2026-09-01');
    }
}
