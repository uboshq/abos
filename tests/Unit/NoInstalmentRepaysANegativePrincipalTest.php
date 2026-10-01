<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Accounts\Services\LoanSchedule;
use PHPUnit\Framework\TestCase;

/**
 * কোনো কিস্তির আসল ঋণাত্মক নয় — abos-63-এর তালিকা (abos-bb-র নিরীক্ষার বাকি), ২ অক্টোবর ২০২৬।
 *
 * ⛔ ফ্ল্যাট সূচিতে প্রতিটা কিস্তির ভাগ পয়সায় উপরে গোল হত — অনেক কিস্তির ছোট ঋণে (৳১, ১৫০ কিস্তি → ০.০১ করে)
 * আগের কিস্তিগুলো আসলের বেশি নিয়ে নিত, আর শেষ কিস্তির আসল দাঁড়াত −০.৪৯: কিস্তি দিলে ঋণ **বাড়ত**।
 * ⭐ এখন প্রতিটা কিস্তি যা বাকি তার বেশি নেয় না, আর যোগফল ঠিক আসলের সমান।
 */
final class NoInstalmentRepaysANegativePrincipalTest extends TestCase
{
    /** @return iterable<string, array{string, string, int, string}> */
    public static function loans(): iterable
    {
        yield 'tiny flat, many months' => ['1', '0', 150, LoanSchedule::FLAT];
        yield 'tiny flat with interest' => ['2', '12', 300, LoanSchedule::FLAT];
        yield 'tiny reducing, many months' => ['1', '0', 150, LoanSchedule::REDUCING];
        yield 'ordinary reducing' => ['500000', '11.5', 60, LoanSchedule::REDUCING];
    }

    /** @dataProvider loans */
    #[\PHPUnit\Framework\Attributes\DataProvider('loans')]
    public function test_every_instalment_repays_zero_or_more_and_the_total_is_the_loan(string $principal, string $rate, int $months, string $method): void
    {
        $rows = LoanSchedule::build(principal: $principal, annualRate: $rate, months: $months, firstDueOn: '2026-10-01', method: $method);

        $sum = '0';

        foreach ($rows as $row) {
            $this->assertGreaterThanOrEqual(0, bccomp($row['principal'], '0', 4),
                "⛔ কিস্তি {$row['no']}-এর আসল {$row['principal']} — ঋণাত্মক; কিস্তি দিলে ঋণ বাড়ত।");
            $this->assertGreaterThanOrEqual(0, bccomp($row['interest'], '0', 4), "⛔ কিস্তি {$row['no']}-এর সুদ ঋণাত্মক।");
            $sum = bcadd($sum, $row['principal'], 4);
        }

        $this->assertSame(0, bccomp($sum, $principal, 4), "⛔ কিস্তির আসলের যোগফল {$sum}, ঋণ {$principal}।");
    }
}
