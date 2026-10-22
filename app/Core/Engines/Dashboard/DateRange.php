<?php

declare(strict_types=1);

namespace App\Core\Engines\Dashboard;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * চার্টের সময়ের লেখা — "1 অক্টো – 5 অক্টো 2026" (মালিক, ৫ অক্টোবর ২০২৬: *"kobe theke kobe porjonto eta likhbe"*)।
 *
 * ⓘ এক জায়গায় বানানো, যাতে প্রতিটা চার্ট একই রকম করে বলে। একই দিন হলে একটা তারিখ; একই বছর হলে বছর একবার।
 * ⭐ মাস আর দিনের নাম পর্দার ভাষায়, অঙ্ক সবসময় ইংরেজিতে (মালিকের নিয়ম "খ", ২০ অক্টোবর ২০২৬) — হোমের মাথার তারিখের
 * ("মঙ্গলবার, 20 অক্টোবর 2026") একই নিয়ম। ⛔ আগে বাংলায় অঙ্কও বাংলা হত, তাই এক পর্দায় দুই রকম অঙ্ক দেখা যেত।
 */
final class DateRange
{
    public static function label(CarbonInterface|string $from, CarbonInterface|string $to): string
    {
        $from = Carbon::parse($from)->locale(app()->getLocale());
        $to = Carbon::parse($to)->locale(app()->getLocale());

        if ($from->isSameDay($to)) {
            return self::latin($to->translatedFormat('j M Y'));
        }

        $left = $from->isSameYear($to) ? $from->translatedFormat('j M') : $from->translatedFormat('j M Y');

        return self::latin($left.' – '.$to->translatedFormat('j M Y'));
    }

    /** ⓘ পাহারা: কোনো ভাষার অনুবাদ অঙ্ক বদলালেও লেখায় ইংরেজি অঙ্ক থাকে (নিয়ম "খ") */
    private static function latin(string $text): string
    {
        return strtr($text, ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']);
    }
}
