<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ⛔ বীমা, ব্যাংক চার্জ আর গাড়ি-শ্রমিকের পর্দায় তারিখের মাস ইংরেজিতে ("12 Oct 2026") — বাংলা পর্দাতেও (লাইন-ধরে নিরীক্ষা, ১৩ অক্টোবর
 * ২০২৬; আগের ধাপ [[TheMonthNameWasInEnglishOnABengaliScreenTest]])।
 *
 * ⓘ `format('d M Y')` ভাষা মানে না; প্রকল্পের নিয়ম `translatedFormat()`।
 */
final class TheInsuranceDatesWereInEnglishTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function views(): array
    {
        return [
            'insurance page' => ['insurance/show.blade.php'],
            'insurance renewal' => ['insurance/partials/renewal.blade.php'],
            'bank charges' => ['bank-charge/index.blade.php'],
            'carrier and labour' => ['carrier-labour/index.blade.php'],
        ];
    }

    #[DataProvider('views')]
    public function test_the_view_prints_its_dates_in_the_screens_language(string $view): void
    {
        $source = (string) file_get_contents(base_path('app/Modules/Finance/Resources/views/'.$view));

        $this->assertDoesNotMatchRegularExpression("/->format\('[^']*M[^']*'\)/", $source,
            "⛔ {$view} মাসের নাম ভাষা না মেনে ছাপে — বাংলা পর্দায় ইংরেজি মাস");
    }
}
