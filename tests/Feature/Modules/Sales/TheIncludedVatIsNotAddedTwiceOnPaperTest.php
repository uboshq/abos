<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\Money;
use App\Modules\Sales\Http\Controllers\SalesPrintController;
use App\Modules\Sales\Models\SalesInvoice;
use ReflectionMethod;
use Tests\TestCase;

/**
 * ⛔ দামের ভিতরের ভ্যাট কাগজে "ভ্যাট (দামের ভিতরে)" — মোটে আবার যোগ হওয়ার মতো দেখায় না (পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬, ছাপা ১৪;
 * [[SalesPrintController::totals()]])।
 *
 * ⓘ ১,১৫০ টাকার মাল, ভ্যাট ১৫০ দামের ভিতরে, মোট ১,১৫০। আগে কাগজে "উপমোট ১,১৫০ · ভ্যাট ১৫০ · মোট ১,১৫০" — যোগ মেলে না, ক্রেতা ভাবেন
 * মোট ভুল বা ভ্যাট নেওয়া হয়নি।
 */
final class TheIncludedVatIsNotAddedTwiceOnPaperTest extends TestCase
{
    public function test_included_vat_has_its_own_label_and_added_vat_keeps_the_old_one(): void
    {
        $inclusive = $this->totals(['subtotal' => '1150', 'discount' => '0', 'tax' => '150', 'total' => '1150']);
        $this->assertArrayNotHasKey('core.print.tax', $inclusive, '⛔ ভিতরের ভ্যাট সাধারণ "ভ্যাট" সারি হয়ে বসল — যোগ মেলে না');
        $this->assertSame(Money::format('150'), $inclusive['core.print.tax_included'] ?? null);
        $this->assertSame(Money::format('1150'), $inclusive['core.print.total']);

        $exclusive = $this->totals(['subtotal' => '1000', 'discount' => '0', 'tax' => '150', 'total' => '1150']);
        $this->assertSame(Money::format('150'), $exclusive['core.print.tax'] ?? null, 'বাইরের ভ্যাট আগের মতোই "ভ্যাট"');
        $this->assertArrayNotHasKey('core.print.tax_included', $exclusive);

        // ⓘ মিশ্র — এক সারিতে ভিতরে ১০০, আরেকটায় উপরে ৫০
        $mixed = $this->totals(['subtotal' => '2000', 'discount' => '0', 'tax' => '150', 'total' => '2050']);
        $this->assertSame([Money::format('50'), Money::format('100')], [$mixed['core.print.tax'] ?? null, $mixed['core.print.tax_included'] ?? null]);
    }

    /** @param  array<string, string>  $figures
     * @return array<string, string> */
    private function totals(array $figures): array
    {
        return (new ReflectionMethod(SalesPrintController::class, 'totals'))
            ->invoke(app(SalesPrintController::class), (new SalesInvoice)->forceFill($figures));
    }
}
