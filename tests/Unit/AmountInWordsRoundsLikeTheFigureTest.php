<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Support\AmountInWords;
use App\Core\Support\Money;
use Tests\TestCase;

/**
 * ⛔ কথায় লেখা অঙ্ক ঘরের অঙ্কের মতোই গোল হয় — এক পয়সা কম নয় (পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬, ছাপা ১১; [[AmountInWords::of()]])।
 *
 * ⓘ ঘরে ১০.০০৫ ছাপা হয় "10.01" ([[Money::format()]]), অথচ কথায় আগে "দশ টাকা মাত্র" — শেষ পয়সাটা কেটে ফেলা হত।
 */
final class AmountInWordsRoundsLikeTheFigureTest extends TestCase
{
    public function test_half_a_paisa_rounds_up_in_words_as_in_the_figure(): void
    {
        $this->assertSame('10.01', Money::format('10.005'));
        $this->assertSame('দশ টাকা এক পয়সা মাত্র', AmountInWords::of('10.005', 'bn'));
        $this->assertStringContainsString('one', AmountInWords::of('10.005', 'en'));
        $this->assertSame('এক টাকা মাত্র', AmountInWords::of('0.995', 'bn'), '⛔ ০.৯৯৫ গোল হয়ে এক টাকা');
        $this->assertSame('দশ টাকা মাত্র', AmountInWords::of('10.004', 'bn'));
        $this->assertStringEndsWith('দশ টাকা এক পয়সা মাত্র', AmountInWords::of('-10.005', 'bn'));
    }
}
