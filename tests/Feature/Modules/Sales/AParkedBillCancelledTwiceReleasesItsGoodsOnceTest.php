<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\Services\PosService;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * কাউন্টারে ধরে রাখা বিল দুইবার বাতিল করলে ধরা মাল একবারই ছাড়ে — ৩০ সেপ্টেম্বর ২০২৬।
 *
 * ── ⛔ কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * [[SalesInvoiceService::cancel()]] "আগেই বাতিল কি না" দেখত হাতে ধরা মডেলে, আর ধরা মাল ছাড়ত
 * ([[ParkedStockReservation::release()]]) **লেনদেনের বাইরে**, কোনো তালা ছাড়া। দুইবার চাপ দিলে দুইটা অনুরোধই
 * "খসড়া" দেখত, দুইবার মাল ছাড়ত — `reserved` শূন্যের নিচে নামত, আর বিক্রয়যোগ্য মাল আসলের চেয়ে বেশি দেখাত:
 * কাউন্টার এমন মাল বেচত যা অন্য বিলের জন্য ধরা।
 *
 * ── ⓘ কেন দুইটা মডেল, দুই সংযোগ নয় ─────────────────────────────────────
 * প্রথম লেনদেনের `status` বদলটাই বিলের সারিতে তালা বসায় — তাই দ্বিতীয় সংযোগ দিয়ে সারি ছুঁয়ে দেখলে সারাই
 * ছাড়াও সবুজ হত (চূড়ান্ত অডিট ⛔৫-এর বিদেশি-চাবির তালার সেই একই ফাঁদ)। ⓘ আসল ভুলটা হলো পুরনো মডেল দেখে
 * সিদ্ধান্ত নেওয়া; দুইটা আলাদা মডেল ঠিক সেটাই বানায়, আর সারাইয়ের পরে দ্বিতীয়টা তালা-পড়া সারি দেখে ফেরে।
 */
final class AParkedBillCancelledTwiceReleasesItsGoodsOnceTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->product = Product::query()->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
    }

    public function test_a_second_cancel_of_a_parked_bill_releases_nothing(): void
    {
        $stock = app(StockService::class);
        $reservedBefore = $stock->reservedQty($this->product, $this->warehouse);

        $parked = app(PosService::class)->park(
            ['warehouse_id' => $this->warehouse->id],
            [['product_id' => $this->product->id, 'qty' => '2', 'rate' => '100']],
        );

        $this->assertSame(0, bccomp(bcsub($stock->reservedQty($this->product, $this->warehouse), $reservedBefore, 4), '2', 4),
            'দৃশ্যটাই বানানো যায়নি — ধরে রাখা বিল ২টা মাল ধরেনি।');

        // ⓘ দুইবার চাপ — একই বিলের দুইটা মডেল, দুটোই খসড়া
        $first = $parked->fresh();
        $second = $parked->fresh();

        app(SalesInvoiceService::class)->cancel($first, 'first click');

        try {
            app(SalesInvoiceService::class)->cancel($second, 'second click');
            $this->fail('দ্বিতীয় বাতিল থামেনি — ধরা মাল দুইবার ছেড়েছে।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }

        $this->assertSame(0, bccomp($stock->reservedQty($this->product, $this->warehouse), $reservedBefore, 4),
            '⛔ ধরা মাল আগের অবস্থায় ফেরার কথা ('.$reservedBefore.'), আছে '
            .$stock->reservedQty($this->product, $this->warehouse).' — ছাড়া হয়েছে দুইবার।');
    }
}
