<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\PosService;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * রেখে দেওয়া বিল আবার খুলে শেষ করলে সংরক্ষণ চিরকাল থেকে যেত — অডিট গ১২, ৪ অক্টোবর ২০২৬।
 *
 * ── ⛔ যা ভাঙা ছিল ───────────────────────────────────────────────────
 * বিল রেখে দিলে মাল সংরক্ষিত হয় ([[ParkedStockReservation]])। ফিরিয়ে আনলে (`resume`) `parked_at` মুছে যায় —
 * আর নিশ্চিত ও বাতিল দুই জায়গাই সংরক্ষণ ছাড়ত কেবল `parked_at` থাকলে। ফলে মাল বিক্রি হয়ে গেলেও "সংরক্ষিত"
 * দেখাত, আর অন্য আদেশ মাল পেত না। ⚠️ আর ফিরিয়ে এনে সারি বদলালে ছাড়া হত আজকের সারি গুনে, ধরা অঙ্ক নয়।
 *
 * ── ⭐ এই ফাইল যা পাহারা দেয় ─────────────────────────────────────────
 *   ১. ফিরিয়ে এনে নিশ্চিত করলে সংরক্ষণ পুরো ফেরে
 *   ২. ফিরিয়ে এনে সারি বদলে নিশ্চিত করলেও ফেরে ঠিক যতটা ধরা ছিল — কম বা বেশি নয়
 *   ৩. ফিরিয়ে এনে বাতিল করলেও ফেরে
 *
 * ⓘ ফিরিয়ে আনার মুহূর্তে সংরক্ষণ **থাকে** — মালিকের নিয়ম *"যতক্ষণ না cancel করছি"*, পাহারা
 * [[PosParkedBillTest::test_picking_the_bill_back_up_does_not_free_the_goods()]]।
 */
final class AResumedBillKeptItsGoodsForeverTest extends TestCase
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

        // ⓘ যথেষ্ট মাল তাকে — বিক্রির পাহারা যেন এই প্রশ্নে না আসে
        app(StockService::class)->move(
            product: $this->product,
            warehouse: $this->warehouse,
            sourceType: 'test_opening',
            sourceId: $this->product->id,
            floor: '50',
        );
    }

    public function test_confirming_a_resumed_bill_frees_its_reservation(): void
    {
        $before = $this->reserved();

        $invoice = app(PosService::class)->resume($this->park('2'));
        // ⓘ নগদ গ্রাহক — পুরো টাকা কাউন্টারে, যাতে বাকির সীমা এই প্রশ্নে না আসে
        app(SalesInvoiceService::class)->confirm($invoice->fresh(['lines.product']), '200');

        $this->assertSame(0, bccomp($this->reserved(), $before, 4),
            'ফিরিয়ে এনে বেচা বিলের মাল এখনো "সংরক্ষিত" — অন্য আদেশ এই মাল কোনোদিন পাবে না।');
    }

    public function test_an_edited_resumed_bill_frees_exactly_what_was_reserved(): void
    {
        $before = $this->reserved();

        $invoice = app(PosService::class)->resume($this->park('2'));

        // ⓘ ক্রেতা ফিরে এসে ২-এর বদলে ৩ নিলেন
        $invoice = app(SalesInvoiceService::class)->update(
            $invoice,
            ['customer_id' => $invoice->customer_id, 'warehouse_id' => $this->warehouse->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $this->product->id, 'qty' => '3', 'rate' => '100']],
        );

        app(SalesInvoiceService::class)->confirm($invoice->fresh(['lines.product']), '300');

        $this->assertSame(0, bccomp($this->reserved(), $before, 4),
            'সংরক্ষণ ফিরেছে আজকের সারি গুনে (৩), অথচ ধরা ছিল ২ — "সংরক্ষিত" এখন '.$this->reserved());
    }

    public function test_cancelling_a_resumed_bill_frees_its_reservation(): void
    {
        $before = $this->reserved();

        $invoice = app(PosService::class)->resume($this->park('2'));
        app(SalesInvoiceService::class)->cancel($invoice->fresh(), 'ক্রেতা ফেরেননি');

        $this->assertSame(0, bccomp($this->reserved(), $before, 4),
            'ফিরিয়ে আনা বিল বাতিলের পরেও মাল আটকে আছে।');
    }

    private function park(string $qty): SalesInvoice
    {
        return app(PosService::class)->park(
            ['warehouse_id' => $this->warehouse->id],
            [['product_id' => $this->product->id, 'qty' => $qty, 'rate' => '100']],
        );
    }

    private function reserved(): string
    {
        return app(StockService::class)->reservedQty($this->product, $this->warehouse);
    }
}
