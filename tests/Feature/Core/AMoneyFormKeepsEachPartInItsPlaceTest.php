<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * ⭐ টাকার ফর্মের ছাঁচ [[ui.money-form]] — আদায়, রসিদ আর পরিশোধ ভাউচার, সরবরাহকারী-পরিশোধ একই দুই কলামে (মালিক আর fe, ২৪ অক্টোবর
 * ২০২৬; cb-র পর্দাগুলো এতে বসবে)। প্রতিটা অংশ নিজের জায়গায়: সারাংশ-পট্টি (কেবল দিলে) সবার আগে, তারপর বাঁ কলাম, তারপর আটকানো ডান
 * কলাম — এই তিনটা দুই-কলামের গ্রিডের ভিতরে; নিচের পট্টি গ্রিডের বাইরে, শেষে। ⓘ আদায়ের পাতায় এর মাপ আর CSS
 * [[TheCollectionFitsTheOwnersScreenTest]]-এ।
 */
class AMoneyFormKeepsEachPartInItsPlaceTest extends TestCase
{
    private function draw(bool $strip, bool $bar): string
    {
        return Blade::render(
            '<x-ui.money-form data-probe="1">'
            .($strip ? '<x-slot:strip><i>STRIP-PART</i></x-slot:strip>' : '')
            .'<i>LEFT-PART</i>'
            .'<x-slot:right><i>RIGHT-PART</i></x-slot:right>'
            .($bar ? '<x-slot:bar><i>BAR-PART</i></x-slot:bar>' : '')
            .'</x-ui.money-form>'
        );
    }

    /** কোন ঘরের ভিতরে — ঘরের খোলা থেকে পরের অংশের আগে পর্যন্ত */
    private function between(string $html, string $from, string $to): string
    {
        $start = strpos($html, $from);
        $end = strpos($html, $to, $start === false ? 0 : $start);

        return $start === false || $end === false ? '' : substr($html, $start, $end - $start);
    }

    public function test_each_part_sits_in_its_own_place_in_order(): void
    {
        $html = $this->draw(strip: true, bar: true);

        $this->assertStringContainsString('data-money-form', $html, '⛔ ছাঁচ নিজেকে চিহ্নিত করে না');
        $this->assertStringContainsString('data-probe="1"', $html, '⛔ পাতার দেওয়া attribute ছাঁচে পৌঁছায় না');

        $at = array_map(fn (string $part) => strpos($html, $part), ['STRIP-PART', 'LEFT-PART', 'RIGHT-PART', 'BAR-PART']);
        $this->assertNotContains(false, $at, '⛔ চার অংশের কোনোটা ছাঁচে আঁকা হয়নি — পট্টি, বাঁ, ডান, নিচের বার');
        $sorted = $at;
        sort($sorted);
        $this->assertSame($sorted, $at, '⛔ অংশগুলো ক্রমে নয় — পট্টি, বাঁ কলাম, ডান কলাম, নিচের বার');

        $this->assertStringContainsString('STRIP-PART', $this->between($html, 'data-summary-strip', 'data-left-column'), '⛔ পট্টির লেখা পট্টির ঘরে নয়');
        $this->assertStringContainsString('LEFT-PART', $this->between($html, 'data-left-column', 'data-right-column'), '⛔ বাঁ কলামের লেখা বাঁ কলামে নয়');
        $this->assertStringContainsString('RIGHT-PART', $this->between($html, 'data-right-column', 'BAR-PART'), '⛔ ডান কলামের লেখা ডান কলামে নয়');

        // ⓘ নিচের বার গ্রিডের বাইরে — গ্রিডের ভিতরে থাকলে দুই কলামে সেটা একটা কলামের ঘর হত
        $grid = $this->between($html, '<div class="grid gap-4', 'BAR-PART');
        $this->assertNotSame('', $grid, 'প্রস্তুতিটাই ভুল — গ্রিড বা নিচের বার খুঁজে পাওয়া যায়নি');
        $this->assertSame(substr_count($grid, '<div'), substr_count($grid, '</div>'), '⛔ নিচের বার দুই-কলামের গ্রিডের ভিতরে — একটা কলামের ঘর হয়ে যায়');
    }

    public function test_the_strip_is_drawn_only_when_given(): void
    {
        $html = $this->draw(strip: false, bar: false);

        $this->assertStringNotContainsString('data-summary-strip', $html, '⛔ পট্টি না দিলেও ফাঁকা আটকানো পট্টি আঁকা হলো');
        $this->assertStringContainsString('LEFT-PART', $html, 'প্রস্তুতিটাই ভুল — ছাঁচ আঁকা হয়নি');
        $this->assertMatchesRegularExpression('/data-right-column[^>]*min-\[1366px\]:sticky/s', $html, '⛔ ডান কলাম আটকানো নয়');
    }
}
