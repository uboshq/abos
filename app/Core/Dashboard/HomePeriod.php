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

    /** ⓘ হোমের ভেতরে কি না — বাইরে (মডিউলের নিজের পাতা) চার্ট নিজের সময় রাখে */
    private static bool $active = false;

    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public static function during(string $period, Closure $work): mixed
    {
        [$before, $wasActive] = [self::$current, self::$active];
        self::$current = in_array($period, Widget::PERIODS, true) ? $period : 'today';
        self::$active = true;

        try {
            return $work();
        } finally {
            [self::$current, self::$active] = [$before, $wasActive];
        }
    }

    public static function current(): string
    {
        return self::$current;
    }

    /**
     * ⭐ হোমে বাছা সময় — হোমের বাইরে `null` (মালিক, ৫ অক্টোবর ২০২৬: *"aj select kora dekhacche ekhon porjonto? keno"*)।
     * ⓘ যে চার্ট সময় মানতে পারে (এ মাসের আয়-ব্যয়), সে এটা দেখে; না থাকলে নিজের সময়।
     */
    public static function chosen(): ?string
    {
        return self::$active ? self::$current : null;
    }

    /**
     * বাছা সময়ের প্রথম আর শেষ দিন — আজ, এ মাস (১ তারিখ থেকে আজ), এ বছর (অর্থবছরের শুরু থেকে আজ)।
     *
     * @return array{0: string, 1: string}
     */
    public static function window(string $period): array
    {
        $today = \Illuminate\Support\Carbon::today();

        return match ($period) {
            'month' => [$today->copy()->startOfMonth()->toDateString(), $today->toDateString()],
            'year' => [auth()->user()?->currentCompany?->currentFinancialYear()?->starts_on?->toDateString()
                ?? $today->copy()->startOfYear()->toDateString(), $today->toDateString()],
            default => [$today->toDateString(), $today->toDateString()],
        };
    }
}
