<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\Money;
use App\Modules\Sales\Http\Controllers\SalesPrintController;
use App\Modules\Sales\Models\SalesInvoice;
use ReflectionMethod;
use Tests\TestCase;

/**
 * ⛔ ভাড়া মোটে থাকলে কাগজেও নিজের সারিতে থাকে (১৩ অক্টোবর ২০২৬, Sales লাইন-ধরা নিরীক্ষা; [[SalesPrintController::totals()]])।
 *
 * ⓘ ১,০০০ টাকার মাল, ভাড়া ৫০, মোট ১,০৫০। আগে চলতি বিল, খসড়া, বাতিল আর টাকাসহ চালানে "উপমোট ১,০০০ · মোট ১,০৫০" —
 * মাঝের ৫০ টাকার কোনো সারি নেই, যোগ মেলে না।
 */
final class TheFreightHasItsOwnRowOnPaperTest extends TestCase
{
    public function test_freight_in_the_total_has_its_own_row(): void
    {
        $rows = $this->totals(['subtotal' => '1000', 'discount' => '0', 'tax' => '0', 'freight_charge' => '50', 'total' => '1050']);
        $this->assertSame(Money::format('50'), $rows['sales::print.classic.freight'] ?? null, '⛔ মোটে ভাড়া আছে, কাগজে সারি নেই — যোগ মেলে না');
        $this->assertSame(Money::format('1050'), $rows['core.print.total']);

        // ⓘ ভ্যাট উপরে আর ভাড়া একসাথে — দুইটাই নিজের সারিতে, ভাড়া ভ্যাট হয়ে বসে না
        $both = $this->totals(['subtotal' => '1000', 'discount' => '0', 'tax' => '150', 'freight_charge' => '50', 'total' => '1200']);
        $this->assertSame([Money::format('150'), Money::format('50')], [$both['core.print.tax'] ?? null, $both['sales::print.classic.freight'] ?? null]);

        $none = $this->totals(['subtotal' => '1000', 'discount' => '0', 'tax' => '0', 'freight_charge' => '0', 'total' => '1000']);
        $this->assertArrayNotHasKey('sales::print.classic.freight', $none, 'ভাড়া শূন্য — সারি থাকার কথা নয়');
    }

    /** @param  array<string, string>  $figures
     * @return array<string, string> */
    private function totals(array $figures): array
    {
        return (new ReflectionMethod(SalesPrintController::class, 'totals'))
            ->invoke(app(SalesPrintController::class), (new SalesInvoice)->forceFill($figures));
    }
}
