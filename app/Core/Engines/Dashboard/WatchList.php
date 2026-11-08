<?php

declare(strict_types=1);

namespace App\Core\Engines\Dashboard;

use App\Core\Dashboard\Widget;
use Illuminate\Support\Collection;

/**
 * ⭐ "নজরে রাখুন" — কোন সারি আসে: কেবল করণীয় দল (`todo`), আর শূন্য নয় (মালিকের রঙিন নমুনা, ৩১ অক্টোবর ২০২৬; পরিকল্পনা §৪.৪)।
 *
 * ⓘ এক জায়গায় — ওয়েবের পাতা (module-v2.blade.php) আর ফোনের উত্তর ([[DashboardApiController]], ড্যা-৭) একই নিয়মে; আগে নিয়মটা
 * পাতার ভেতরে লেখা ছিল, ফোনে আলাদা করে লিখলে একদিন দুই জায়গা আলাদা বলত।
 * ⓘ সংখ্যা বাংলা অঙ্কেও আসতে পারে ("৩") — পড়ার আগে ইংরেজি অঙ্কে।
 */
final class WatchList
{
    /**
     * @param  iterable<Widget>  $reminders
     * @return Collection<int, Widget>
     */
    public static function of(iterable $reminders): Collection
    {
        return collect($reminders)
            ->filter(fn (Widget $item) => $item->group === 'todo' && self::count((string) $item->value) > 0)
            ->values();
    }

    private static function count(string $value): int
    {
        $latin = strtr($value, ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']);

        return (int) preg_replace('/\D/', '', $latin);
    }
}
