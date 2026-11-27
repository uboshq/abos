<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Accounts\Services\LoanSchedule;
use App\Modules\Sales\Services\CustomerTargetService;
use Carbon\Carbon as BaseCarbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ⭐ টাকার দরজাগুলো যে কোনো Carbon নেয়, আর ফল একই — ২৮ নভেম্বর ২০২৬ (abos-b1, PHPStan argument.type)।
 *
 * ⛔ কী ঝুঁকি ছিল: [[CustomerTargetService::bankDays()]] গুনত `for ($d = $from->copy(); $d->lte($to); $d->addDay())`।
 * ⚠️ `CarbonImmutable`-এ `addDay()` নতুন বস্তু দেয়, `$d` বদলায় না — তাই লুপ কখনো থামত না। ঘোষিত ধরন (Illuminate Carbon)
 *   সেটা আটকাত, কিন্তু মডেলের cast-এর ধরন নিয়ে PHPStan ৩০টা দরজায় নালিশ করছিল। ⭐ দরজা এখন `CarbonInterface` নেয় আর
 *   ঢোকার মুখে [[Dates::of()]]-এ এক ধরনে আনে — এই পরীক্ষা দেখে যে চওড়া দরজায় immutable এলেও গোনা থামে, আর একই।
 */
final class DatesOfAnyKindCountTheSameTest extends TestCase
{
    /** @return iterable<string, array{0: class-string<\Carbon\CarbonInterface>}> */
    public static function kinds(): iterable
    {
        yield 'illuminate' => [Carbon::class];
        yield 'base Carbon' => [BaseCarbon::class];
        yield 'immutable' => [CarbonImmutable::class];
    }

    /** @param class-string<\Carbon\CarbonInterface> $kind */
    #[DataProvider('kinds')]
    public function test_bank_days_are_the_same_for_every_kind_of_date(string $kind): void
    {
        // রবি ২৯ নভেম্বর ২০২৬ থেকে শনি ৫ ডিসেম্বর — রবি–বৃহস্পতি ৫ দিন; তারপর গোটা ডিসেম্বর (৩১ দিন, শুক্র-শনি ৮টা বাদে ২৩)
        $this->assertSame(5, (new CustomerTargetService)->bankDays($kind::parse('2026-11-29'), $kind::parse('2026-12-05')));
        $this->assertSame(23, (new CustomerTargetService)->bankDays($kind::parse('2026-12-01'), $kind::parse('2026-12-31')));
    }

    /** @param class-string<\Carbon\CarbonInterface> $kind */
    #[DataProvider('kinds')]
    public function test_a_loan_schedule_is_the_same_for_every_kind_of_first_due_date(string $kind): void
    {
        $expected = LoanSchedule::build('120000', '12', 12, Carbon::parse('2027-01-15'));
        $actual = LoanSchedule::build('120000', '12', 12, $kind::parse('2027-01-15'));

        $this->assertEquals($expected, $actual, '⛔ প্রথম কিস্তির তারিখের ধরন বদলালে কিস্তির ছক বদলাল');
    }
}
