<?php

declare(strict_types=1);

namespace Tests\Unit\Dashboard;

use App\Core\Dashboard\Widget;
use App\Core\Engines\Dashboard\WatchList;
use PHPUnit\Framework\TestCase;

/**
 * ⭐ "নজরে রাখুন" — কেবল করণীয়, আর শূন্য নয়; বাংলা অঙ্কের "০"-ও শূন্য (মালিকের রঙিন নমুনা, ৩১ অক্টোবর ২০২৬)। ওয়েবের পাতা আর
 * ফোনের ড্যাশবোর্ড (ড্যা-৭) দুটোই এই এক নিয়মে ([[WatchList]])।
 */
final class TheWatchListShowsOnlyWhatNeedsDoingTest extends TestCase
{
    public function test_only_todo_rows_with_something_to_do(): void
    {
        $row = fn (string $group, string $label, string $value) => new Widget(
            group: $group, label: $label, value: $value, href: '/x', permission: 'x.view');

        $kept = WatchList::of([
            $row('todo', 'তারিখ পেরোনো বিল', '14'),
            $row('todo', 'শূন্য ইংরেজি', '0'),
            $row('todo', 'শূন্য বাংলা', '০'),
            $row('todo', 'বাংলা তিন', '৩'),
            $row('todo', 'টাকা শূন্য', '৳ 0.00'),
            $row('today', 'আজকের বিক্রি', '8,45,600'),
        ])->map(fn (Widget $w) => $w->label)->all();

        $this->assertSame(['তারিখ পেরোনো বিল', 'বাংলা তিন'], $kept,
            '⛔ "নজরে রাখুন"-এ শূন্যের সারি, বা করণীয় নয় এমন সারি — ওয়েব আর ফোনে শূন্য আসে না।');
    }
}
