<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\SalesReturnBillGuard;
use Tests\TestCase;

/**
 * ⛔ লট-ধরা পণ্যের ফেরতের সারিতে দাম আর ফ্রি দুটোই শূন্য হলে লট খোঁজা `array_intersect()`-কে শূন্য প্যারামিটারে ডাকত —
 * PHP 8-এ ArgumentCountError, পর্দায় ৫০০ (phpstan L1, ২২ অক্টোবর ২০২৬)। ⓘ আজ [[SalesReturnBillGuard::bind()]] এমন সারি আগেই বাদ দেয়,
 * তাই লাইভে পৌঁছায় না; কিন্তু নিয়মটা ডাকনেওয়ালার ভরসায় থাকত। এখন লট-খোঁজা নিজেই বলে: ফেরত কিছু নেই তো লট নেই।
 */
final class AReturnLineWithNothingNeedsNoLotTest extends TestCase
{
    public function test_a_lot_tracked_line_returning_nothing_asks_for_no_lot(): void
    {
        $product = (new Product)->forceFill(['id' => 1, 'track_batch' => true]);
        $this->assertTrue((bool) $product->track_batch, 'প্রস্তুতিটাই ভুল — পণ্যটা লট ধরে না');

        $lotFor = new \ReflectionMethod(SalesReturnBillGuard::class, 'lotFor');

        try {
            $lot = $lotFor->invoke(app(SalesReturnBillGuard::class), new SalesInvoice, [], $product, [], '0', '0', 1);
        } catch (\ArgumentCountError $e) {
            $this->fail('⛔ শূন্য ফেরতের সারিতে লট খুঁজতে গিয়ে array_intersect() শূন্য প্যারামিটারে ডাকা হলো — ৫০০: '.$e->getMessage());
        }

        $this->assertNull($lot, '⛔ কিছু ফেরত নেই, তবু লট বসল');
    }
}
