<?php

declare(strict_types=1);

namespace App\Core\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * ⭐ তারিখ এক ধরনে — যে কোনো Carbon (বা লেখা তারিখ) ঢুকলে ভেতরে সবসময় `Illuminate\Support\Carbon`।
 *
 * ⓘ কেন: মডেলের 'date'/'datetime' cast-কে larastan ধরে `Carbon\Carbon` (মূল শ্রেণি, ইচ্ছাকৃত — [[ModelCastHelper::getDateType()]]),
 *   অথচ খাতা, মজুদ আর নম্বর-সিরিজের দরজা চাইত তার উপশ্রেণি `Illuminate\Support\Carbon`। চলার সময় মানটা উপশ্রেণিই, তাই কিছু ভাঙত না —
 *   কিন্তু PHPStan প্রতিটা ডাকে ১৪৫টা "ভুল ধরন" দেখাত, আর আসল ভুল সেই ভিড়ে হারাত (২৮ নভেম্বর ২০২৬)।
 * ⭐ দরজাগুলো এখন `CarbonInterface` নেয় (চওড়া, কিছু বাদ পড়ে না) আর ঢোকার মুখে এখানে এক ধরনে আনে — ভিতরের হিসাব হুবহু আগের মতো।
 */
final class Dates
{
    /** ⓘ null থাকলে null; লেখা তারিখ Carbon::parse()-এ, যেমন আগে হত */
    public static function carbon(CarbonInterface|string|null $date): ?Carbon
    {
        return match (true) {
            $date === null => null,
            $date instanceof Carbon => $date,
            $date instanceof CarbonInterface => Carbon::instance($date),
            default => Carbon::parse($date),
        };
    }

    /** ⓘ null-হীন তারিখ (মডেলের cast থেকে) → Illuminate\Support\Carbon; একই শ্রেণি হলে সেই বস্তুটাই, কপি নয় */
    public static function of(CarbonInterface $date): Carbon
    {
        return $date instanceof Carbon ? $date : Carbon::instance($date);
    }

    /**
     * ⓘ কেবল অন্য ধরনের Carbon বদলায়; লেখা তারিখ আর null যেমন ছিল তেমনই — যে দরজা লেখা তারিখ ভিতরে নিজেই পড়ে,
     *   তার আচরণ এক অক্ষরও বদলায় না।
     */
    public static function sameKind(CarbonInterface|string|null $date): Carbon|string|null
    {
        return $date instanceof CarbonInterface && ! $date instanceof Carbon ? Carbon::instance($date) : $date;
    }
}
