<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Models\PurchaseReceipt;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Purchase\Services\PurchaseReceiptService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * চালান থেকে বানানো বিলে ফ্রি ছিল না — সমন্বয়কের নির্দেশ, ৩ অক্টোবর ২০২৬ (65a6e912-এর পরে)।
 *
 * ── ⛔ কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * [[PurchaseBillService::fromReceipt()]] চালানের সারি থেকে পরিমাণ আর দর নিত — ফ্রি নয়। মাল হারাত না
 * (ফ্রি চালানেই মজুদে ঢোকে), কিন্তু বিলের পাতা আর ছাপা বলত "ফ্রি নেই"।
 *
 * ⭐ দুইটা দিক একসাথে: বিলের সারিতে ফ্রি **দেখায়**, আর বিল ফ্রি মজুদ **দ্বিতীয়বার তোলে না** —
 * ফ্রি একবারই ঢোকে, চালানের `:free` উৎসে।
 */
final class TheBillFromAReceiptForgotTheFreeGoodsTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private Supplier $supplier;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();

        $this->product = Product::query()->where('track_batch', false)->whereNotNull('unit_id')->firstOrFail();
        $this->supplier = Supplier::query()->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
    }

    /** ⭐ চালান নিশ্চিত → বিল আপনা থেকে: বিলের সারিতে ৫ ফ্রি, পাতায় দেখায়, মজুদে ফ্রি একবারই */
    public function test_the_bill_shows_the_free_goods_and_does_not_bring_them_in_twice(): void
    {
        $receipt = $this->aReceipt();

        $this->post(route('purchase.receipt.confirm', $receipt))->assertSessionHasNoErrors();

        $bill = $this->billOf($receipt);
        $this->assertSame(DocumentStatus::CONFIRMED, $bill->status);

        $this->assertSame(0, bccomp('5', (string) $bill->lines->firstOrFail()->free_qty, 4),
            '⛔ চালানের ৫ ফ্রি বিলের সারিতে আসেনি — বিলের পাতা আর ছাপা "ফ্রি নেই" বলত।');

        $this->get(route('purchase.bill.show', $bill))->assertOk()->assertSee(__('purchase::field.free_qty'));

        $this->assertFreeEnteredOnce($receipt, $bill);
    }

    /** ⭐ আগের বিলে ২ ফ্রি উঠে গেছে — বাকি অংশের বিলে ৩, পুরো ৫ আবার নয় */
    public function test_a_second_bill_for_the_rest_carries_only_the_free_not_yet_billed(): void
    {
        $receipt = app(PurchaseReceiptService::class)->confirm($this->aReceipt());
        $line = $receipt->lines()->firstOrFail();

        app(PurchaseBillService::class)->create(
            ['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $this->product->id, 'qty' => '4', 'free_qty' => '2', 'rate' => '100', 'purchase_receipt_line_id' => $line->id]],
        );

        $rest = app(PurchaseBillService::class)->fromReceipt($receipt->fresh());

        $this->assertNotNull($rest);
        $restLine = $rest->lines()->firstOrFail();
        $this->assertSame(0, bccomp('6', (string) $restLine->qty, 4));
        $this->assertSame(0, bccomp('3', (string) $restLine->free_qty, 4),
            "⛔ বাকি অংশের বিলে ফ্রি {$restLine->free_qty} — থাকার কথা ৩ (৫ − আগের বিলের ২)।");
    }

    /** ⓘ বাতিল বিলের ফ্রি গোনে না — বাতিলের পরে নতুন বিলে পুরো ৫ ফেরে */
    public function test_a_cancelled_bill_gives_its_free_back_to_the_next_bill(): void
    {
        $receipt = $this->aReceipt();
        $this->post(route('purchase.receipt.confirm', $receipt))->assertSessionHasNoErrors();

        app(PurchaseBillService::class)->cancel($this->billOf($receipt), 'ভুল বিল');

        $again = app(PurchaseBillService::class)->fromReceipt($receipt->fresh());

        $this->assertNotNull($again);
        $this->assertSame(0, bccomp('5', (string) $again->lines()->firstOrFail()->free_qty, 4),
            '⛔ বাতিল বিলের ফ্রি এখনো "উঠে গেছে" ধরা হচ্ছে।');
    }

    private function aReceipt(): PurchaseReceipt
    {
        return app(PurchaseReceiptService::class)->create(
            ['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $this->product->id, 'received_qty' => '10', 'free_qty' => '5', 'rate' => '100']],
        );
    }

    private function billOf(PurchaseReceipt $receipt): PurchaseBill
    {
        return PurchaseBill::query()
            ->where('status', '<>', DocumentStatus::CANCELLED)
            ->whereHas('lines', fn ($q) => $q->whereIn('purchase_receipt_line_id', $receipt->lines()->pluck('id')))
            ->with('lines')
            ->latest('id')
            ->firstOrFail();
    }

    /** ফ্রি মজুদ ঢুকেছে কেবল চালানের `:free` উৎসে — বিলের কোনো উৎসে একটুও নয় */
    private function assertFreeEnteredOnce(PurchaseReceipt $receipt, PurchaseBill $bill): void
    {
        $fromBill = StockMovement::query()
            ->where('source_type', 'like', PurchaseBill::STOCK_SOURCE.'%')
            ->where('source_id', $bill->id)
            ->count();

        $this->assertSame(0, $fromBill,
            "⛔ চালান থেকে বানানো বিল নিজে মজুদের {$fromBill}টা সারি লিখেছে — ফ্রি/মাল দুবার ঢুকল।");

        $fromReceipt = (string) StockMovement::query()
            ->where('source_type', PurchaseReceipt::STOCK_SOURCE.':free')
            ->where('source_id', $receipt->id)
            ->sum('unplaced_free_change');

        $this->assertSame(0, bccomp('5', $fromReceipt, 4), "⛔ চালানের ফ্রি মজুদ {$fromReceipt} — থাকার কথা ৫।");
    }
}
