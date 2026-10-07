<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Support\CompanyContext;
use App\Models\ApprovalFlow;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Models\PurchaseReturn;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Purchase\Services\PurchaseReceiptService;
use App\Modules\Purchase\Services\PurchaseReturnService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ফেরত দেওয়া লট তবু পুরো দেখাত — Inventory অডিট গ৯, ৪ অক্টোবর ২০২৬।
 *
 * ⛔ ক্রয় ফেরত মাল বের করত লট ছাড়া: লট A-র ১০টা কিনে ৪টা ফেরত দিলে পণ্যের মোট মজুদ কমত, কিন্তু লট A তবু ১০ দেখাত —
 * পরে লট A থেকে ১০টা বিক্রি হয়ে যেত (বাস্তবে ৬), আর রিকলের খাতা ভুল লটের মাল দেখাত।
 * ⭐ এখন ফেরত লট ধরে বেরোয়: বিলের সারির লট থেকে; বিল না বললে আগে-মেয়াদ ক্রমে; বাতিলে ঠিক সেই লটে ফেরে।
 */
final class TheReturnedLotStillShowedFullTest extends TestCase
{
    use RefreshDatabase;

    private Supplier $supplier;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->supplier = Supplier::query()->orderBy('id')->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->orderBy('id')->firstOrFail();
        $this->product->forceFill(['track_batch' => true])->save();

        // ⓘ সইয়ের ছক এই পরীক্ষার বিষয় নয়
        ApprovalFlow::query()->where('module', 'purchase')->delete();
    }

    /** ⭐ বিলের লট থেকেই — আগে-মেয়াদের অন্য লট থাকলেও; বাতিলে সেই লটেই ফেরে */
    public function test_a_return_against_a_bill_takes_the_bills_lot_and_cancelling_puts_it_back(): void
    {
        $billA = $this->bill('LOT-A', now()->addYear()->toDateString());
        $this->bill('LOT-B', now()->addMonth()->toDateString()); // আগে মেয়াদ — FEFO হলে এটাই বাছা হত

        $return = $this->returns()->confirm($this->returnOf('4', $billA));

        $this->assertSame('6', $this->lot('LOT-A'), '⛔ বিলের লট A থেকে ৪টা কমেনি — ফেরত লট ছাড়া বেরিয়েছে।');
        $this->assertSame('10', $this->lot('LOT-B'), '⛔ ফেরত অন্য লট (B) থেকে কেটেছে।');

        $this->returns()->cancel($return, 'ভুল ফেরত');
        $this->assertSame('10', $this->lot('LOT-A'), '⛔ বাতিলে মাল লট A-তে ফেরেনি।');
        $this->assertSame('10', $this->lot('LOT-B'));
    }

    /** ⭐ বিল না বললে আগে-মেয়াদের লট থেকে; বাতিলে সেই লটেই ফেরে */
    public function test_a_return_without_a_bill_takes_the_earliest_expiring_lot(): void
    {
        $this->bill('LOT-A', now()->addYear()->toDateString());
        $this->bill('LOT-B', now()->addMonth()->toDateString());

        // ⓘ সবচেয়ে আগে-মেয়াদের লটটা খালি — বাছাই তাকে নিঃশব্দে পেরিয়ে যায়, শূন্যের চলাচল লেখে না
        $this->bill('LOT-0', now()->addWeek()->toDateString());
        app(StockService::class)->move(
            product: $this->product, warehouse: $this->warehouse, sourceType: StockService::ADJUSTMENT, sourceId: $this->product->id,
            batch: Batch::query()->where('product_id', $this->product->id)->where('batch_no', 'LOT-0')->firstOrFail(), unplaced: '-10',
        );

        $return = $this->returns()->confirm($this->returnOf('13', null));
        $this->assertSame(0, StockMovement::query()->where('source_type', PurchaseReturn::STOCK_SOURCE)->where('source_id', $return->id)
            ->where('floor_change', 0)->where('unplaced_change', 0)->count(), '⛔ খালি লটের জন্য শূন্যের চলাচল লেখা হয়েছে।');

        $this->assertSame('0', $this->lot('LOT-B'), '⛔ আগে-মেয়াদের লট B আগে খালি হয়নি।');
        $this->assertSame('7', $this->lot('LOT-A'), '⛔ বাকি ৩টা পরের লট A থেকে আসেনি।');

        $this->returns()->cancel($return, 'ভুল ফেরত');
        $this->assertSame(['10', '10'], [$this->lot('LOT-A'), $this->lot('LOT-B')], '⛔ বাতিলে লটগুলো আগের মতো হয়নি।');
    }

    /** ⛔ বিলের লটে কম থাকলে থামে, লট ধরে বলে — অন্য লটে (B-তে ১০টা আছে) গড়ায় না */
    public function test_a_bills_lot_that_is_short_stops_the_return_and_does_not_spill_into_another_lot(): void
    {
        $billA = $this->bill('LOT-A', now()->addYear()->toDateString());
        $this->bill('LOT-B', now()->addMonth()->toDateString());

        $lotA = Batch::query()->where('product_id', $this->product->id)->where('batch_no', 'LOT-A')->firstOrFail();
        app(StockService::class)->move(
            product: $this->product, warehouse: $this->warehouse, sourceType: StockService::ADJUSTMENT,
            sourceId: $this->product->id, batch: $lotA, unplaced: '-7',
        );
        $this->assertSame('3', $this->lot('LOT-A'), 'প্রস্তুতিটাই ভুল।');

        $draft = $this->returnOf('4', $billA);
        $this->assertStringContainsString('LOT-A', implode(' ', $this->returns()->whatWouldStopTheConfirm($draft->fresh())),
            '⛔ নিশ্চিতের আগের সারাংশ লটের কমতি বলেনি — দরজা থামাবে, অথচ সারাংশ "সব ঠিক" দেখায়।');

        try {
            $this->returns()->confirm($draft);
            $this->fail('⛔ লট A-তে ৩টা থাকতে ৪টা ফেরত গেল।');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('LOT-A', implode(' ', $e->errors()['lines'] ?? []), '⛔ থামল, কিন্তু লটের নাম ধরে নয়।');
        }

        $this->assertSame(['3', '10'], [$this->lot('LOT-A'), $this->lot('LOT-B')], '⛔ থামা ফেরতেও কোনো লট নড়েছে।');
    }

    /** ⭐ লট-ধরা শুরুর আগের লটহীন মালও ফেরত যায় — লটগুলোর পরে, শেষে */
    public function test_stock_from_before_lots_goes_back_last(): void
    {
        app(StockService::class)->move(
            product: $this->product, warehouse: $this->warehouse, sourceType: StockService::ADJUSTMENT,
            sourceId: $this->product->id, floor: '5',
        );
        $this->bill('LOT-B', now()->addMonth()->toDateString());
        $before = $this->unlotted(); // ⓘ ডেমোর পণ্যে আগে থেকেই লটহীন মাল আছে

        $this->returns()->confirm($this->returnOf('12', null));

        $this->assertSame('0', $this->lot('LOT-B'), '⛔ লট B আগে খালি হয়নি।');
        $this->assertSame(0, bccomp(bcsub($before, '2', 4), $this->unlotted(), 4), '⛔ বাকি ২টা লটহীন মাল থেকে যায়নি: '.$this->unlotted());
    }

    /** ⭐ মাল-গ্রহণ (GRN) থেকে কাটা বিলের সারিতে লট নম্বর থাকে না — লট আসে তার মাল-গ্রহণ সারি থেকে */
    public function test_a_bill_made_from_a_receipt_names_the_receipts_lot(): void
    {
        $receipts = app(PurchaseReceiptService::class);
        $receipt = $receipts->confirm($receipts->create(
            ['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $this->product->id, 'received_qty' => '10', 'rate' => '50',
                'batch_no' => 'LOT-R', 'expiry_date' => now()->addYear()->toDateString()]],
        ));
        $this->bill('LOT-B', now()->addMonth()->toDateString()); // আগে মেয়াদ

        $bills = app(PurchaseBillService::class);
        $fromReceipt = $bills->confirm($bills->fromReceipt($receipt))->load('lines');

        $this->returns()->confirm($this->returnOf('4', $fromReceipt));

        $this->assertSame(['6', '10'], [$this->lot('LOT-R'), $this->lot('LOT-B')], '⛔ মাল-গ্রহণের লট R থেকে কাটেনি।');
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    private function unlotted(): string
    {
        return (string) StockMovement::query()->where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)->whereNull('batch_id')
            ->sum(DB::raw('floor_change + unplaced_change'));
    }

    private function returns(): PurchaseReturnService
    {
        return app(PurchaseReturnService::class);
    }

    private function bill(string $lot, string $expiry): PurchaseBill
    {
        $service = app(PurchaseBillService::class);

        return $service->confirm($service->create(
            ['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $this->product->id, 'qty' => '10', 'rate' => '50', 'batch_no' => $lot, 'expiry_date' => $expiry]],
        ))->load('lines');
    }

    private function returnOf(string $qty, ?PurchaseBill $bill): PurchaseReturn
    {
        return $this->returns()->create(
            ['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $this->product->id, 'purchase_bill_line_id' => $bill?->lines->first()->id, 'qty' => $qty]],
        );
    }

    private function lot(string $no): string
    {
        $batch = Batch::query()->where('product_id', $this->product->id)->where('batch_no', $no)->firstOrFail();

        return rtrim(rtrim(bcadd($batch->balance($this->warehouse), '0', 4), '0'), '.') ?: '0';
    }
}
