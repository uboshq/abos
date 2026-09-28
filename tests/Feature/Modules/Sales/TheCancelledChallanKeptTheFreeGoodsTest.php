<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\Unit;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * চালান বাতিল হলো — কিন্তু ফ্রি আর উপহারের মাল ফ্রি ভাণ্ডারে ফিরল কি?
 *
 * ── কী ধরা হচ্ছে ────────────────────────────────────────────────────
 * কাউন্টার বিক্রিতে ফ্রি ও উপহারের মাল বেরোয় আলাদা উৎস-নামে —
 * `delivery_challan:free` আর `delivery_challan:gift` ([[DirectSaleService::moveFreeStock()]])।
 * চালান বাতিলে [[DeliveryChallanService::cancel()]] কেবল `delivery_challan`
 * উল্টাত, তাই দামের মাল ফিরত অথচ ফ্রি ও উপহারের মাল চিরকাল "বেরিয়ে
 * গেছে" হয়ে থাকত — আর লট ধরা পণ্যে লটের ফ্রি জেরও কম দেখাত।
 *
 * ⚠️ প্রতিটা প্রত্যাশিত অঙ্ক **হাতে গোনা**, পরীক্ষাধীন কোড থেকে নয়।
 *
 * ── প্রস্তুতি ──────────────────────────────────────────────────────
 *   সাবান (লট ছাড়া)   : ১০০ কেনা @ ৪০ ; বোনাস ফ্রি ২০
 *   বিস্কুট (লট ছাড়া) : ৫০ কেনা @ ২০ ; বোনাস ফ্রি ১০
 *   ওষুধ (লট ধরা)     : লট A ১০০ @ ৩০, ফ্রি ১০ (মেয়াদ আগে)
 *                       লট B  ৫০ @ ৩৬, ফ্রি ১০ (মেয়াদ পরে)
 */
final class TheCancelledChallanKeptTheFreeGoodsTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private Warehouse $warehouse;

    private Supplier $supplier;

    private Product $soap;

    private Product $biscuit;

    private Product $medicine;

    private Batch $lotA;

    private Batch $lotB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->supplier = Supplier::query()->firstOrFail();

        // ⓘ বাকির দেয়াল এই ফাইলের প্রশ্ন নয়
        $this->customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $this->customer->forceFill(['credit_limit' => '1000000'])->save();

        $this->soap = $this->newProduct('TCC-SOAP', 'Cancelled-challan Soap', '60', '40', tracked: false);
        $this->biscuit = $this->newProduct('TCC-BISC', 'Cancelled-challan Biscuit', '30', '20', tracked: false);
        $this->medicine = $this->newProduct('TCC-MED', 'Cancelled-challan Medicine', '50', '30', tracked: true);

        $this->buy($this->soap, qty: '100', free: '20', rate: '40');
        $this->buy($this->biscuit, qty: '50', free: '10', rate: '20');
        $this->lotA = $this->buy($this->medicine, qty: '100', free: '10', rate: '30',
            lot: 'TCC-LOT-A', expiry: now()->addMonths(6)->toDateString());
        $this->lotB = $this->buy($this->medicine, qty: '50', free: '10', rate: '36',
            lot: 'TCC-LOT-B', expiry: now()->addYear()->toDateString());
    }

    public function test_cancelling_the_challan_puts_the_free_and_gift_goods_back_in_the_free_pool(): void
    {
        /*
         * বাকিতে: সাবান ৮ @ ৬০ ; ওষুধ লট A থেকে ১০ @ ৫০ আর ফ্রি ১
         * (১০ × ১০/১০০ = ১) ; বিস্কুট ১টা উপহার।
         */
        $this->post(route('sales.direct.store'), [
            'own_transport' => '1', // ⓘ ধাপ ৫ — নিশ্চিতে পরিবহন লাগে ([[TransportRule]]); এই দাবি অন্য কিছু মাপে
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
            'payment_term' => 'credit',
            'lines' => [
                ['product_id' => $this->soap->id, 'qty' => '8', 'rate' => '60', 'free_qty' => '0'],
                [
                    'product_id' => $this->medicine->id, 'batch_id' => $this->lotA->id,
                    'qty' => '10', 'rate' => '50', 'free_qty' => '1',
                ],
            ],
            'gifts' => [['product_id' => $this->biscuit->id, 'against_product_id' => $this->soap->id, 'qty' => '1']],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $invoice = SalesInvoice::query()->latest('id')->firstOrFail();
        $challan = DeliveryChallan::query()->latest('id')->firstOrFail();

        // বিক্রির পরে, বাতিলের আগে — ফ্রি সত্যিই বেরিয়েছিল (নইলে নিচের দাবি ফাঁপা)
        $this->assertFreeIs($this->biscuit, '9', 'বিক্রির পরে বিস্কুট: ফ্রি ১০ − উপহার ১ = ৯');
        $this->assertFreeIs($this->medicine, '19', 'বিক্রির পরে ওষুধ: ফ্রি ২০ − ১ = ১৯');
        $this->assertSame(0, bccomp($this->lotA->fresh()->freeBalance($this->warehouse), '9', 4),
            'বিক্রির পরে লট A-র ফ্রি ১০ − ১ = ৯ হওয়ার কথা');

        // মালিক যেভাবে করেন: আগে বিল, তারপর চালান — পর্দার দরজা দিয়ে
        $this->post(route('sales.invoice.cancel', $invoice), ['reason' => 'ভুল পার্টি'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->post(route('sales.challan.cancel', $challan), ['reason' => 'ভুল পার্টি'])
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('cancelled', (string) $challan->fresh()->status);

        // ⭐ ফ্রি ভাণ্ডার প্রস্তুতির অবস্থায়: বিস্কুট ৯ + ১ = ১০ ; ওষুধ ১৯ + ১ = ২০
        $this->assertFreeIs($this->biscuit, '10', 'বাতিলের পরে বিস্কুটের উপহার ফ্রি ভাণ্ডারে ফেরেনি');
        $this->assertFreeIs($this->medicine, '20', 'বাতিলের পরে ওষুধের ফ্রি ফ্রি ভাণ্ডারে ফেরেনি');
        $this->assertFreeIs($this->soap, '20', 'সাবানের ফ্রি ছোঁয়াই হয়নি');

        // ⭐ লট ধরে: যে লট থেকে বেরিয়েছিল (A) সেখানেই ফেরে ; B অক্ষত
        $this->assertSame(0, bccomp($this->lotA->fresh()->freeBalance($this->warehouse), '10', 4),
            'লট A-র ফ্রি '.$this->lotA->fresh()->freeBalance($this->warehouse).', হাতে গোনা ৯ + ১ = ১০');
        $this->assertSame(0, bccomp($this->lotB->fresh()->freeBalance($this->warehouse), '10', 4),
            'লট B-র ফ্রি '.$this->lotB->fresh()->freeBalance($this->warehouse).', হাতে গোনা ১০ (ছোঁয়া হয়নি)');

        // দামের মাল আগেও ফিরত — এখনো ফেরে: সাবান ১০০, লট A ১০০
        $this->assertSame(0, bccomp((string) app(StockService::class)->statesFor($this->soap, $this->warehouse)['floor'], '100', 4));
        $this->assertSame(0, bccomp($this->lotA->fresh()->balance($this->warehouse), '100', 4));
    }

    private function assertFreeIs(Product $product, string $expected, string $why): void
    {
        $free = (string) app(StockService::class)->statesFor($product, $this->warehouse)['free'];

        $this->assertSame(0, bccomp($free, $expected, 4), "{$why} — পাওয়া গেল {$free}, হাতে গোনা {$expected}");
    }

    private function newProduct(string $code, string $label, string $salePrice, string $purchasePrice, bool $tracked): Product
    {
        return Product::query()->create([
            'company_id' => CompanyContext::id(),
            'code' => $code,
            'name_en' => $label,
            'unit_id' => Unit::query()->where('code', 'PCS')->value('id'),
            'is_active' => true,
            'sale_price' => $salePrice,
            'purchase_price' => $purchasePrice,
            'track_batch' => $tracked,
        ]);
    }

    /** সরবরাহকারীর বিল — দামের মাল আর বোনাস ফ্রি — তারপর গুদামে বুঝে নেওয়া। */
    private function buy(Product $product, string $qty, string $free, string $rate, ?string $lot = null, ?string $expiry = null): ?Batch
    {
        $bills = app(PurchaseBillService::class);

        $bill = $bills->confirm($bills->create(
            [
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString(),
            ],
            [array_filter([
                'product_id' => $product->id,
                'qty' => $qty,
                'free_qty' => $free,
                'rate' => $rate,
                'batch_no' => $lot,
                'expiry_date' => $expiry,
            ], fn ($v) => $v !== null)],
        ));

        $batch = $lot === null ? null
            : Batch::query()->where('product_id', $product->id)->where('batch_no', $lot)->firstOrFail();

        app(StockService::class)->place(
            product: $product,
            warehouse: $this->warehouse,
            qty: $qty,
            sourceType: PurchaseBill::STOCK_SOURCE,
            sourceId: $bill->id,
            batch: $batch,
            freeQty: $free,
        );

        return $batch;
    }
}
