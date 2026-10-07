<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\SettingsService;
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
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * লাইভের লটে কাউন্টার থামল — মালিক নিজে দেখলেন, ৪ অক্টোবর ২০২৬ (UB, গুদাম ৩)।
 *
 *   ১. POPS লট "809" (তাকে ১৪৪, ফ্রি ৬) আর Calsomilk (৬২৪, ফ্রি ২৬): ফ্রিসহ বিক্রি হয় না। ফ্রি বসানো হয়েছিল
 *      PBL-1014-এর হারানো ফ্রি ফেরাতে, `purchase_bill:free` উৎসে, তারপর গুদামে তোলা।
 *   ২. Milk Marie Premium-এর তিন লট (২৩, ৮১, ৮২): "লটে পরিমাণ কম"।
 *
 * ⭐ সংখ্যাগুলো লাইভের হুবহু।
 */
final class TheCounterRefusedTheLiveLotsTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private Warehouse $warehouse;

    private Supplier $supplier;

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
        $this->customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $this->customer->forceFill(['credit_limit' => '10000000'])->save();
    }

    /** ১ — PBL-1014-এর পথ: বিলে ফ্রি ০, পরে হাতে ফ্রি বসানো লটের সারিতে; ২৪টায় ১ ফ্রি চলে, ১৪৪-এ ৬ */
    public function test_a_lot_whose_free_was_put_back_by_hand_still_sells_with_its_free(): void
    {
        $pops = $this->newProduct('LIVE-POPS', 'POPS');
        $lot = $this->lotFreedByHand($pops, '809', '144', '6');

        $this->assertSame(0, bccomp('6', $lot->fresh()->freeBalance($this->warehouse), 4), 'প্রস্তুতি: লটে ফ্রি ৬ তাকে নেই।');

        $this->sell($pops, $lot, '24', '1')->assertSessionHasNoErrors();
        $this->sell($pops, $lot, '120', '5')->assertSessionHasNoErrors();

        $this->assertSame(0, bccomp('0', $lot->fresh()->freeBalance($this->warehouse), 4), '⛔ ফ্রি বের হয়নি।');
    }

    /**
     * ⭐ সুইচ `sales.free_by_lot_ratio` (মালিক, ৪ অক্টোবর ২০২৬, সংস্করণ ২): চালু (ডিফল্ট) — ১২টায় ১ ফ্রি থামে, প্রতি ২৪-এ ১;
     * বন্ধ — একই বিক্রি চলে, কিন্তু ফ্রি-ভাণ্ডারের বেশি নয়। ⓘ একই মানুষ, একই লট, কেবল সুইচ বদলায়।
     */
    public function test_the_lot_ratio_binds_the_free_only_while_its_switch_is_on(): void
    {
        $pops = $this->newProduct('LIVE-POPS', 'POPS');
        $lot = $this->lotFreedByHand($pops, '809', '144', '6');

        $this->assertTrue((bool) app(SettingsService::class)->get('sales.free_by_lot_ratio', true), 'ডিফল্ট চালু নয় — লাইভে হঠাৎ বেশি ফ্রি যেত।');
        $this->sell($pops, $lot, '12', '1')->assertSessionHasErrors('lines');
        $this->assertSame('1', $this->askedFree($pops, $lot, '24')['allowed'] ?? null, 'চালু অবস্থায় পর্দা ২৪-এ ১ ফ্রি বলে না।');

        app(SettingsService::class)->set('sales.free_by_lot_ratio', false);

        $this->assertFalse($this->askedFree($pops, $lot, '24')['known'], '⛔ সুইচ বন্ধ, তবু পর্দা লটের অনুপাত বলছে।');
        $this->sell($pops, $lot, '12', '1')->assertSessionHasNoErrors();
        $this->assertSame(0, bccomp('5', $lot->fresh()->freeBalance($this->warehouse), 4), '⛔ বন্ধ সুইচে ১ ফ্রি বের হয়নি।');

        $this->sell($pops, $lot, '12', '6')->assertSessionHasErrors();
        $this->assertSame(0, bccomp('5', $lot->fresh()->freeBalance($this->warehouse), 4), '⛔ ফ্রি-ভাণ্ডারের বেশি ফ্রি বেরিয়েছে।');
    }

    /** ১ — একই, Calsomilk-এর সংখ্যায় (৬২৪, ২৬) */
    public function test_calsomilk_sells_its_free_at_the_lots_own_ratio(): void
    {
        $milk = $this->newProduct('LIVE-CALSO', 'Calsomilk');
        $lot = $this->lotFreedByHand($milk, '809', '648', '27');

        $this->sell($milk, $lot, '48', '2')->assertSessionHasNoErrors();
    }

    /** ২ — তিন লট ২৩/৮১/৮২: ৮১-র লট থেকে ৫০ চলে; ২৩-এর লট থেকে ৫০ হয় না, আর বার্তা লটের নম্বর আর ২৩ বলে */
    public function test_milk_marie_sells_from_the_lot_that_has_enough(): void
    {
        $marie = $this->newProduct('LIVE-MARIE', 'Milk Marie Premium');
        // ⓘ লাইভে তিন লটেরই মেয়াদ খালি
        $small = $this->lot($marie, 'OM-10178', '23', null);
        $mid = $this->lot($marie, 'OM-238', '81', null);
        $this->lot($marie, 'OM-619', '82', null);

        $this->sell($marie, $mid, '50', '0')->assertSessionHasNoErrors();

        $this->sell($marie, $small, '50', '0')->assertSessionHasErrors('qty');
    }

    /**
     * ⭐ ছাপায় এক লাইন — মালিক, ৪ অক্টোবর ২০২৬: *"print e ek line dekhabe"*। ২৩-এর লট থেকে ২৩, ৮১-র লট থেকে ২৭ —
     * কার্টে দুই সারি, কিন্তু বিল (classic, থার্মাল একই সারি পড়ে) আর চালানে Milk Marie একবার, ৫০টা, নিচে "OM-10178 · OM-238"।
     * ⓘ লটের সুইচ বন্ধ করলে লাইন একটাই থাকে, কেবল লটের লেখা যায়।
     */
    public function test_the_paper_shows_one_line_per_product_with_its_lots_under_it(): void
    {
        $marie = $this->newProduct('LIVE-MARIE', 'Milk Marie Premium');
        $small = $this->lot($marie, 'OM-10178', '23', null);
        $mid = $this->lot($marie, 'OM-238', '81', null);

        $this->post(route('sales.direct.store'), [
            'own_transport' => '1',
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
            'payment_term' => 'credit',
            'lines' => [
                ['product_id' => $marie->id, 'batch_id' => $small->id, 'qty' => '23', 'rate' => '50', 'free_qty' => '0'],
                ['product_id' => $marie->id, 'batch_id' => $mid->id, 'qty' => '27', 'rate' => '50', 'free_qty' => '0'],
            ],
        ])->assertSessionHasNoErrors();

        $invoice = \App\Modules\Sales\Models\SalesInvoice::query()->latest('id')->firstOrFail();
        $challan = DeliveryChallan::query()->latest('id')->firstOrFail();
        $this->assertSame(2, $invoice->lines()->count(), 'প্রস্তুতি: কার্টে দুই সারি নয়।');

        // ⓘ কোড আর লট দুই সুইচই চালু — কাগজ কী দেখায় সেটাই মাপা
        app(SettingsService::class)->set('sales.print.show.product_code', true);
        app(SettingsService::class)->set('sales.print.show.lot', true);

        $rows = $this->printed('classicItems', $invoice)['rows'];
        $this->assertCount(1, $rows, '⛔ বিলের কাগজে একই পণ্য দুই লাইনে।');
        $this->assertSame('50 Pcs', $rows[0]['qty']);
        $this->assertSame('2,500.00', $rows[0]['amount']);
        $this->assertSame('OM-10178 · OM-238', $rows[0]['lot'], '⛔ লাইনের নিচে দুই লট নেই।');
        $this->assertSame('LIVE-MARIE', $rows[0]['code']);

        $challanRows = $this->printed('productLines', $challan->lines()->with(['product.unit', 'batch'])->get(), 'delivered_qty', [$marie->id => 'x']);
        $this->assertCount(1, $challanRows, '⛔ চালানের কাগজে একই পণ্য দুই লাইনে।');
        $this->assertSame('50', $challanRows[0]['qty']);
        $this->assertSame('OM-10178 · OM-238', $challanRows[0]['note']);

        app(SettingsService::class)->set('sales.print.show.lot', false);
        $rows = $this->printed('classicItems', $invoice->fresh())['rows'];
        $this->assertCount(1, $rows);
        $this->assertSame('', $rows[0]['lot'], '⛔ লটের সুইচ বন্ধ, তবু কাগজে লট।');
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /** ছাপার নিয়ন্ত্রকের সারি বানানোর ধাপ — কাগজ যা আঁকে ঠিক সেটাই */
    private function printed(string $method, mixed ...$args): array
    {
        $controller = app(\App\Modules\Sales\Http\Controllers\SalesPrintController::class);

        return (new \ReflectionMethod($controller, $method))->invoke($controller, ...$args);
    }

    private function sell(Product $product, Batch $lot, string $qty, string $free)
    {
        $before = DeliveryChallan::query()->count();

        $response = $this->post(route('sales.direct.store'), [
            'own_transport' => '1',
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
            'payment_term' => 'credit',
            'lines' => [['product_id' => $product->id, 'batch_id' => $lot->id, 'qty' => $qty, 'rate' => '50', 'free_qty' => $free]],
        ]);

        if (session('errors') === null) {
            $this->assertSame($before + 1, DeliveryChallan::query()->count(), 'বিক্রি বলল ঠিক আছে, অথচ চালান নেই।');
        }

        return $response;
    }

    /** @return array<string, mixed> পর্দা যা জিজ্ঞেস করে ([[DirectSaleController::freeAllowed()]]) */
    private function askedFree(Product $product, Batch $lot, string $qty): array
    {
        return (array) $this->getJson(route('sales.direct.free_allowed', [
            'product_id' => $product->id, 'qty' => $qty, 'batch_id' => $lot->id, 'warehouse_id' => $this->warehouse->id,
        ]))->assertOk()->json('data');
    }

    private function newProduct(string $code, string $label): Product
    {
        return Product::query()->create([
            'company_id' => CompanyContext::id(),
            'code' => $code,
            'name_en' => $label,
            'unit_id' => Unit::query()->where('code', 'PCS')->value('id'),
            'is_active' => true,
            'sale_price' => '50',
            'purchase_price' => '40',
            'track_batch' => true,
        ]);
    }

    /** সাধারণ লট — বিলে কেনা, তাকে তোলা */
    private function lot(Product $product, string $no, string $qty, ?string $expiry, string $free = '0'): Batch
    {
        $bills = app(PurchaseBillService::class);

        $bill = $bills->confirm($bills->create(
            ['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id, 'trx_date' => now()->toDateString()],
            [array_filter(['product_id' => $product->id, 'qty' => $qty, 'free_qty' => $free, 'rate' => '40', 'batch_no' => $no, 'expiry_date' => $expiry], fn ($v) => $v !== null)],
        ));

        $batch = Batch::query()->where('product_id', $product->id)->where('batch_no', $no)->firstOrFail();

        app(StockService::class)->place(
            product: $product, warehouse: $this->warehouse, qty: $qty,
            sourceType: PurchaseBill::STOCK_SOURCE, sourceId: $bill->id, batch: $batch, freeQty: $free,
        );

        return $batch;
    }

    /**
     * PBL-1014-এর হুবহু পথ: বিলে ফ্রি ০ (সম্পাদনায় হারানো), তারপর সমন্বয়কের হাতে বসানো —
     * `purchase_bill:free` উৎসে unplaced_free +ফ্রি ([[PurchaseBillService::bringInFree()]]-এর একই পথ), তারপর গুদামে তোলা।
     */
    private function lotFreedByHand(Product $product, string $no, string $qty, string $free): Batch
    {
        $batch = $this->lot($product, $no, $qty, null);
        $bill = PurchaseBill::query()->latest('id')->firstOrFail();

        app(StockService::class)->move(
            product: $product,
            warehouse: $this->warehouse,
            sourceType: PurchaseBill::STOCK_SOURCE.':free',
            sourceId: $bill->id,
            date: now()->toDateString(),
            documentNo: $bill->document_no,
            unplacedFree: $free,
            batch: $batch,
        );

        app(StockService::class)->place(
            product: $product, warehouse: $this->warehouse, qty: '0',
            sourceType: PurchaseBill::STOCK_SOURCE, sourceId: $bill->id, batch: $batch, freeQty: $free,
        );

        return $batch;
    }
}
