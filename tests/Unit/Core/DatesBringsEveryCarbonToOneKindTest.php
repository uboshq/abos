<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Support\Dates;
use Carbon\Carbon as BaseCarbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ⭐ খাতা, মজুদ আর নম্বর-সিরিজের দরজা এখন যে কোনো Carbon নেয় — ভিতরে সবসময় একই ধরন, একই মুহূর্ত (২৮ নভেম্বর ২০২৬)।
 * ⓘ দাবি: ধরন `Illuminate\Support\Carbon`, তারিখ-সময় আর সময়-অঞ্চল অক্ষত; লেখা তারিখ `sameKind()`-এ লেখাই থাকে।
 */
final class DatesBringsEveryCarbonToOneKindTest extends TestCase
{
    /** @return iterable<string, array{0: \Carbon\CarbonInterface|string}> */
    public static function dates(): iterable
    {
        yield 'base Carbon' => [BaseCarbon::parse('2026-11-28 13:45:10', 'Asia/Dhaka')];
        yield 'immutable' => [CarbonImmutable::parse('2026-02-28 00:00:00', 'Asia/Dhaka')];
        yield 'illuminate' => [Carbon::parse('2027-06-30 23:59:59', 'Asia/Dhaka')];
        yield 'leap day' => [BaseCarbon::parse('2028-02-29 09:00:00', 'UTC')];
        yield 'text' => ['2026-12-31'];
    }

    #[DataProvider('dates')]
    public function test_every_date_comes_out_as_the_same_kind_and_the_same_moment(\Carbon\CarbonInterface|string $in): void
    {
        $out = Dates::carbon($in);

        $this->assertInstanceOf(Carbon::class, $out);
        $expected = is_string($in) ? Carbon::parse($in) : $in;
        $this->assertSame($expected->format('Y-m-d H:i:s e'), $out->format('Y-m-d H:i:s e'), '⛔ রূপান্তরে মুহূর্ত বা অঞ্চল বদলাল');
    }

    #[DataProvider('dates')]
    public function test_same_kind_changes_only_a_foreign_carbon(\Carbon\CarbonInterface|string $in): void
    {
        $out = Dates::sameKind($in);

        if (is_string($in)) {
            $this->assertSame($in, $out, '⛔ লেখা তারিখ বদলে গেল — দরজার ভিতরের পড়া বদলাত');

            return;
        }

        $this->assertInstanceOf(Carbon::class, $out);
        $this->assertSame($in->format('Y-m-d H:i:s e'), $out->format('Y-m-d H:i:s e'));
        if ($in instanceof Carbon) {
            $this->assertSame($in, $out, '⛔ একই ধরনের Carbon-এর কপি হল — বাইরের বদল ভিতরে পৌঁছাত না');
        }
    }

    public function test_null_stays_null(): void
    {
        $this->assertNull(Dates::carbon(null));
        $this->assertNull(Dates::sameKind(null));
    }
}
