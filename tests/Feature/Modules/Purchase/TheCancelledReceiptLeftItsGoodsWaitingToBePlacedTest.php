<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Purchase\Models\PurchaseReceipt;
use App\Modules\Purchase\Services\PurchaseReceiptService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * বাতিল করা চালানের মাল "বসার অপেক্ষায়" থেকে যেত — ২৯ সেপ্টেম্বর ২০২৬।
 *
 * ── কী ভেঙেছিল ──────────────────────────────────────────────────────
 * `confirm()` মাল ঢোকায় `unplaced` ঘরে, লট সহ। কিন্তু `cancel()` মাল
 * তুলত `floor` থেকে, লট ছাড়া — আর `unplaced` ছুঁত না। ফল: না-বসানো
 * মালের চালান বাতিল করলে হয় অন্য লটের তাকের মাল কাটা যেত, নয়তো
 * "যথেষ্ট নেই" বলে থামত; আর বাতিল করা মাল তখনো বসানো ও বেচা যেত।
 */
class TheCancelledReceiptLeftItsGoodsWaitingToBePlacedTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private Warehouse $warehouse;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->supplier = Supplier::query()->firstOrFail();

        $this->product = Product::query()->orderBy('id')->firstOrFail();
        $this->product->forceFill(['track_batch' => true])->save();
    }

    private function receive(string $lot, string $qty): PurchaseReceipt
    {
        $service = app(PurchaseReceiptService::class);

        $receipt = $service->create(
            [
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString(),
            ],
            [[
                'product_id' => $this->product->id,
                'received_qty' => $qty,
                'rate' => '20',
                'batch_no' => $lot,
                'expiry_date' => now()->addYear()->toDateString(),
            ]],
        );

        return $service->confirm($receipt);
    }

    private function place(PurchaseReceipt $receipt, string $lot, string $qty): void
    {
        app(StockService::class)->place(
            product: $this->product,
            warehouse: $this->warehouse,
            qty: $qty,
            sourceType: PurchaseReceipt::STOCK_SOURCE,
            sourceId: $receipt->id,
            batch: $this->lot($lot),
        );
    }

    private function lot(string $lot): Batch
    {
        return Batch::query()->where('product_id', $this->product->id)->where('batch_no', $lot)->firstOrFail();
    }

    /** @return array{floor: string, unplaced: string} */
    private function lotState(string $lot): array
    {
        $row = StockMovement::query()
            ->where('batch_id', $this->lot($lot)->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->select([
                DB::raw('COALESCE(SUM(floor_change), 0) as floor'),
                DB::raw('COALESCE(SUM(unplaced_change), 0) as unplaced'),
            ])
            ->first();

        return ['floor' => (string) $row->floor, 'unplaced' => (string) $row->unplaced];
    }

    private function assertQty(string $expected, string $actual, string $what): void
    {
        $this->assertSame(0, bccomp($actual, $expected, 4), "{$what}: expected {$expected}, got {$actual}");
    }

    /** না-বসানো চালান বাতিল — অপেক্ষার ঘর খালি হয়, অন্য লটের তাক অক্ষত, আর বসানো যায় না। */
    public function test_cancelling_an_unplaced_receipt_empties_the_waiting_bucket_and_leaves_the_shelf_alone(): void
    {
        // পুরনো লট — তাকে বসানো, যাতে ভুল পথটা এখান থেকে কাটতে পারে
        $old = $this->receive('OLD-1', '50');
        $this->place($old, 'OLD-1', '50');

        $stock = app(StockService::class);
        $baseline = $stock->statesFor($this->product, $this->warehouse);

        $new = $this->receive('NEW-1', '40');
        $this->assertQty('40', $this->lotState('NEW-1')['unplaced'], 'NEW-1 unplaced after confirm');

        app(PurchaseReceiptService::class)->cancel($new->fresh(), 'ভুল সরবরাহকারী');

        $this->assertQty('0', $this->lotState('NEW-1')['unplaced'], 'NEW-1 unplaced after cancel');
        $this->assertQty('0', $this->lotState('NEW-1')['floor'], 'NEW-1 floor after cancel');
        $this->assertQty('50', $this->lotState('OLD-1')['floor'], 'OLD-1 floor after cancel');

        $after = $stock->statesFor($this->product, $this->warehouse);
        $this->assertQty($baseline['floor'], $after['floor'], 'product floor');
        $this->assertQty($baseline['unplaced'], $after['unplaced'], 'product unplaced');

        // বাতিল করা মাল আর বসানো যায় না
        $this->assertQty('0', $baseline['unplaced'], 'seeded unplaced (precondition)');
        try {
            $this->place($new, 'NEW-1', '40');
            $this->fail('cancelled goods could still be placed');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('qty', $e->errors());
        }
    }

    /** পুরো বসানো চালান বাতিল — সেই লটের তাক আবার নামে, স্বাভাবিক পথ অক্ষত। */
    public function test_cancelling_a_fully_placed_receipt_takes_the_lot_off_the_shelf(): void
    {
        $new = $this->receive('NEW-2', '40');
        $this->place($new, 'NEW-2', '40');
        $this->assertQty('40', $this->lotState('NEW-2')['floor'], 'NEW-2 floor after placing');

        $before = app(StockService::class)->statesFor($this->product, $this->warehouse);

        app(PurchaseReceiptService::class)->cancel($new->fresh(), 'ভুল সরবরাহকারী');

        $this->assertQty('0', $this->lotState('NEW-2')['floor'], 'NEW-2 floor after cancel');
        $this->assertQty('0', $this->lotState('NEW-2')['unplaced'], 'NEW-2 unplaced after cancel');

        $after = app(StockService::class)->statesFor($this->product, $this->warehouse);
        $this->assertQty(bcsub($before['floor'], '40', 4), $after['floor'], 'product floor');
        $this->assertQty($before['unplaced'], $after['unplaced'], 'product unplaced');
    }

    /** বসানো মাল বেচা হয়ে গেলে বাতিল আগের মতোই আটকায় — ঋণাত্মক তাক নয়। */
    public function test_a_placed_receipt_whose_goods_were_sold_still_cannot_be_cancelled(): void
    {
        $new = $this->receive('NEW-4', '40');
        $this->place($new, 'NEW-4', '40');

        $stock = app(StockService::class);
        $stock->move(
            product: $this->product, warehouse: $this->warehouse,
            sourceType: 'test_issue', sourceId: 1,
            floor: bcmul($stock->floorQty($this->product, $this->warehouse), '-1', 4),
        );

        try {
            app(PurchaseReceiptService::class)->cancel($new->fresh(), 'ফেরত পাঠাব');
            $this->fail('a receipt whose goods were sold was cancelled');
        } catch (ValidationException) {
            $this->assertSame(DocumentStatus::CONFIRMED, $new->fresh()->status);
        }
    }

    /** আংশিক বসানো চালান বাতিল — যেটুকু তাকে সেটুকু তাক থেকে, বাকিটা অপেক্ষার ঘর থেকে। */
    public function test_cancelling_a_partly_placed_receipt_takes_each_part_from_where_it_is(): void
    {
        $old = $this->receive('OLD-3', '50');
        $this->place($old, 'OLD-3', '50');

        $new = $this->receive('NEW-3', '40');
        $this->place($new, 'NEW-3', '15');

        app(PurchaseReceiptService::class)->cancel($new->fresh(), 'ভুল সরবরাহকারী');

        $this->assertQty('0', $this->lotState('NEW-3')['floor'], 'NEW-3 floor after cancel');
        $this->assertQty('0', $this->lotState('NEW-3')['unplaced'], 'NEW-3 unplaced after cancel');
        $this->assertQty('50', $this->lotState('OLD-3')['floor'], 'OLD-3 floor after cancel');
    }
}
