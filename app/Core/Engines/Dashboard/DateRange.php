<?php

declare(strict_types=1);

namespace App\Core\Engines\Dashboard;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * চার্টের সময়ের লেখা — "১ অক্টো – ৫ অক্টো ২০২৬" (মালিক, ৫ অক্টোবর ২০২৬: *"kobe theke kobe porjonto eta likhbe"*)।
 *
 * ⓘ এক জায়গায় বানানো, যাতে প্রতিটা চার্ট একই রকম করে বলে। একই দিন হলে একটা তারিখ; একই বছর হলে বছর একবার।
 * ⓘ ভাষা পর্দার ভাষা — বাংলায় বাংলা মাস ও অঙ্ক।
 */
final class DateRange
{
    public static function label(CarbonInterface|string $from, CarbonInterface|string $to): string
    {
        $from = Carbon::parse($from)->locale(app()->getLocale());
        $to = Carbon::parse($to)->locale(app()->getLocale());

        if ($from->isSameDay($to)) {
            return self::digits($to->translatedFormat('j M Y'));
        }

        $left = $from->isSameYear($to) ? $from->translatedFormat('j M') : $from->translatedFormat('j M Y');

        return self::digits($left.' – '.$to->translatedFormat('j M Y'));
    }

    /** বাংলা পর্দায় বাংলা অঙ্ক */
    private static function digits(string $text): string
    {
        return app()->getLocale() === 'bn'
            ? strtr($text, ['0' => '০', '1' => '১', '2' => '২', '3' => '৩', '4' => '৪', '5' => '৫', '6' => '৬', '7' => '৭', '8' => '৮', '9' => '৯'])
            : $text;
    }
}
