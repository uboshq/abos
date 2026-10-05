<?php

declare(strict_types=1);

namespace App\Core\Dashboard;

use Closure;

/**
 * হোমের বাছা সময় — আজ, এ মাস, না এ বছর — মূল সূচকের জন্য (মালিক, ৫ অক্টোবর ২০২৬: হোমের পরিকল্পনা ২)।
 *
 * ⓘ মডিউলের `widgets()`-এ কোনো আর্গুমেন্ট যায় না; তাই [[HomeFilter]]-এর মতো, সময়টা কেবল হোমের সংখ্যা
 * গোনার সময়টুকু চালু থাকে ([[during()]])। বাইরে সবসময় "আজ"।
 * ⓘ সময় বদলায় কেবল প্রবাহের সূচক (বিক্রি, আদায়, ক্রয়); জের (বকেয়া, দেনা, মজুদ) সবসময় এখনকার।
 */
final class HomePeriod
{
    private static string $current = 'today';

    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public static function during(string $period, Closure $work): mixed
    {
        $before = self::$current;
        self::$current = in_array($period, Widget::PERIODS, true) ? $period : 'today';

        try {
            return $work();
        } finally {
            self::$current = $before;
        }
    }

    public static function current(): string
    {
        return self::$current;
    }
}
