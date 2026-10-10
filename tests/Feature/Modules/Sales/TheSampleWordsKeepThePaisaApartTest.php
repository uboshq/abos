<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Modules\Sales\Http\Controllers\SalesPrintController;
use ReflectionMethod;
use Tests\TestCase;

/**
 * ⛔ নমুনা-ধাঁচের বিলে কথায় অঙ্ক — পয়সা থাকলে টাকা আর পয়সা আলাদা থাকে (পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬, ছাপা ১৫;
 * [[SalesPrintController::sampleWords()]])।
 *
 * ⓘ "taka" সবসময় কেটে ফেলা হত: ১০০.০৫ ছাপা হত "One Hundred And Five Paisa" — পড়তে একশো পাঁচ পয়সা।
 */
final class TheSampleWordsKeepThePaisaApartTest extends TestCase
{
    public function test_paisa_keeps_taka_and_a_round_amount_stays_as_before(): void
    {
        $this->assertSame('One Hundred Taka And Five Paisa (BDT)', $this->words('100.05'), '⛔ টাকা আর পয়সার সীমা হারাল');
        $this->assertSame('One Thousand Two Hundred Fifty Taka And Fifty Paisa (BDT)', $this->words('1250.50'));
        $this->assertSame('One Thousand Two Hundred Fifty (BDT)', $this->words('1250'), 'গোল অঙ্ক আগের মতোই');
    }

    private function words(string $amount): string
    {
        return (new ReflectionMethod(SalesPrintController::class, 'sampleWords'))->invoke(app(SalesPrintController::class), $amount);
    }
}
