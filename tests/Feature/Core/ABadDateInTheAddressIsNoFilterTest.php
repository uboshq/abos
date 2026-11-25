<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\ListFilter\Facets;
use App\Core\Support\DateFormat;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ⛔ ঠিকানায় তারিখ নয় এমন লেখা — চিপ নয়, ঘরে নয়, ৫০০ নয় (1.3.12-এর রান: `?date=abc`-তে হাজিরার পাতা ৫০০)।
 *
 * ⓘ কেন্দ্রীয় ছাঁকনির সব তারিখ-জাতীয় অংশে এক নিয়ম: একদিন, মাস, পরিসর।
 */
final class ABadDateInTheAddressIsNoFilterTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function nonsense(): array
    {
        return ['word' => ['abc'], 'no such day' => ['2026-02-30'], 'tail' => ['2026-10-05x'], 'month 13' => ['2026-13'], 'short month' => ['2026-1'],
            'newline' => ["2026-10-05\n"], 'empty' => ['']];
    }

    #[DataProvider('nonsense')]
    public function test_a_day_and_a_month_take_only_a_real_one(string $raw): void
    {
        $this->assertNull(DateFormat::plainDay($raw));
        $this->assertNull(DateFormat::plainMonth($raw));

        $request = Request::create('/x', 'GET', ['date' => $raw, 'month' => $raw]);
        $this->assertNull(Facets::day('date', 'Day', '2026-11-25')->read($request));
        $this->assertNull(Facets::month('month', 'Month', '2026-11')->read($request));
    }

    public function test_a_range_never_breaks_on_a_word(): void
    {
        $this->assertNull(Facets::dates('Date')->read(Request::create('/x', 'GET', ['from' => 'abc', 'to' => 'zz-zz'])));
    }

    public function test_a_real_day_and_month_still_filter_and_make_their_chip(): void
    {
        $request = Request::create('/x', 'GET', ['date' => '2026-10-05', 'month' => '2026-10-05']);

        $day = Facets::day('date', 'Day');
        $this->assertSame(['date' => '2026-10-05'], $day->read($request));
        $this->assertSame('Day: '.DateFormat::format('2026-10-05'), $day->chips($day->read($request))[0]['text']);

        // ⓘ মাসের ঘরে পুরো তারিখ এলে তার মাস (পুরোনো লিংক)
        $month = Facets::month('month', 'Month');
        $this->assertSame(['month' => '2026-10'], $month->read($request));
        $this->assertNull(DateFormat::plainDay(['2026-10-05']));
    }
}
