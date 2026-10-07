<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\ApprovalFlow;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\CostLayer;
use App\Modules\Inventory\Models\CostLayerUse;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Models\PurchaseReturn;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Purchase\Services\PurchaseReturnService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ক্রয় ফেরত — বাতিলে দামের স্তর, দুইবার খাতায় বসা, আর অন্যের বিলের লাইন।
 * ২৯ সেপ্টেম্বর ২০২৬।
 *
 * ⛔ (১) বাতিলে মাল আর খাতা ফিরত, কিন্তু স্তরে যা কমেছিল তা ফিরত না —
 *       ফলে পরের বিক্রয়ে ঐ মালের দাম "স্তরে নেই" বলে অন্য দামে বেরোত।
 * ⛔ (২) পুরনো কপি হাতে থাকলে একই ফেরত দুইবার খাতায় বসত।
 * ⛔ (৩) অন্য সরবরাহকারীর বা খসড়া বিলের লাইন ধরে ফেরত লেখা যেত।
 */
class TheCancelledReturnKeptTheCostLayerTest extends TestCase
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

        // ⓘ সইয়ের ছক এই পরীক্ষার বিষয় নয় — বিল আর ফেরত এক ধাপেই খাতায় বসুক
        ApprovalFlow::query()->where('module', 'purchase')->delete();
    }

    /** (১) বাতিল করলে স্তরটা আগের জায়গায় ফেরে, আর পুরো দশটাই বিলের দরে বেরোয়। */
    public function test_cancelling_a_return_gives_the_cost_layer_back(): void
    {
        $bill = $this->confirmedBill($this->supplier, '10', '50');
        $layer = CostLayer::query()
            ->where('source_type', PurchaseBill::STOCK_SOURCE)
            ->where('source_id', $bill->id)
            ->where('product_id', $this->product->id)
            ->firstOrFail();
        $this->assertSame(0, bccomp('10', (string) $layer->qty_remaining, 4));

        $return = $this->returns()->confirm($this->draft($bill, '4'));
        $this->assertSame(0, bccomp('6', (string) $layer->fresh()->qty_remaining, 4),
            'ফেরতে স্তর থেকে চারটা কমেনি — পরীক্ষাটা ভুল জিনিস মাপছে।');

        $this->returns()->cancel($return, 'ভুল ফেরত');

        $this->assertSame(0, bccomp('10', (string) $layer->fresh()->qty_remaining, 4),
            'ফেরত বাতিলের পরও স্তরে '.$layer->fresh()->qty_remaining.' — চারটা ফেরেনি।');

        $net = (string) (CostLayerUse::query()
            ->where('cost_layer_id', $layer->id)
            ->where('source_id', $return->id)
            ->where('source_type', 'like', PurchaseReturn::STOCK_SOURCE.'%')
            ->sum('qty') ?: '0');
        $this->assertSame(0, bccomp('0', $net, 4), 'ফেরতের স্তর-টান বাতিলের পরও নিট '.$net.'।');

        // পুরো দশটা এখন বিলের স্তর থেকেই, ৫০ দরে বেরোয়
        $taken = app(CostLayerService::class)->issueFromSource(
            product: $this->product,
            qty: '10',
            fromSourceType: PurchaseBill::STOCK_SOURCE,
            fromSourceId: $bill->id,
            sourceType: 'test_sale',
            sourceId: 1,
        );
        $this->assertSame(0, bccomp('500', $taken['cost'], 4), 'দশটার দাম '.$taken['cost'].', হওয়ার কথা ৫০০।');
        $this->assertCount(1, $taken['uses']);
        $this->assertSame((int) $layer->id, (int) $taken['uses'][0]->cost_layer_id);
    }

    /** (২) পুরনো কপি দিয়ে দ্বিতীয়বার confirm — থামে, মাল একবারই নড়ে। */
    public function test_a_stale_copy_cannot_confirm_the_return_twice(): void
    {
        $bill = $this->confirmedBill($this->supplier, '10', '50');
        $draft = $this->draft($bill, '2');
        $stale = PurchaseReturn::query()->findOrFail($draft->id);

        $before = $this->onHand();
        $this->returns()->confirm($draft);

        try {
            $this->returns()->confirm($stale);
            $this->fail('একই ফেরত দ্বিতীয়বার খাতায় বসল।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }

        $this->assertSame(0, bccomp(bcsub($before, '2', 4), $this->onHand(), 4),
            'মাল একবারের বেশি গুদাম ছাড়ল: '.$this->onHand());
    }

    /** (২) পুরনো কপি দিয়ে দ্বিতীয়বার cancel — থামে, মাল একবারই ফেরে। */
    public function test_a_stale_copy_cannot_cancel_the_return_twice(): void
    {
        $bill = $this->confirmedBill($this->supplier, '10', '50');
        $confirmed = $this->returns()->confirm($this->draft($bill, '2'));
        $stale = PurchaseReturn::query()->findOrFail($confirmed->id);

        $before = $this->onHand();
        $this->returns()->cancel($confirmed, 'প্রথম বাতিল');

        try {
            $this->returns()->cancel($stale, 'দ্বিতীয় বাতিল');
            $this->fail('একই ফেরত দ্বিতীয়বার বাতিল হলো।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }

        $this->assertSame(0, bccomp(bcadd($before, '2', 4), $this->onHand(), 4),
            'বাতিলে মাল একবারের বেশি ফিরল: '.$this->onHand());
    }

    /** (৩) অন্য সরবরাহকারীর বিলের লাইন ধরে ফেরত — থামে। */
    public function test_a_return_cannot_use_another_suppliers_bill_line(): void
    {
        $other = Supplier::query()->whereKeyNot($this->supplier->id)->orderBy('id')->firstOrFail();
        $theirs = $this->confirmedBill($other, '10', '50');

        $this->expectException(ValidationException::class);

        $this->returns()->create(
            ['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString()],
            [['product_id' => $this->product->id, 'purchase_bill_line_id' => $theirs->lines->first()->id, 'qty' => '2']],
        );
    }

    /** (৩) খসড়া বিলের লাইন ধরে ফেরত — থামে। */
    public function test_a_return_cannot_use_a_draft_bills_line(): void
    {
        $service = app(PurchaseBillService::class);
        $draftBill = $service->create(
            ['supplier_id' => $this->supplier->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $this->product->id, 'qty' => '10', 'rate' => '50']],
        )->load('lines');
        $this->assertSame(DocumentStatus::DRAFT, $draftBill->status);

        $this->expectException(ValidationException::class);

        $this->returns()->create(
            ['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString()],
            [['product_id' => $this->product->id, 'purchase_bill_line_id' => $draftBill->lines->first()->id, 'qty' => '2']],
        );
    }

    /** (৩) ফেরতে বিল বলা থাকলে লাইনটা সেই বিলেরই হতে হবে। */
    public function test_a_return_cannot_use_a_line_from_a_different_bill_than_named(): void
    {
        $named = $this->confirmedBill($this->supplier, '10', '50');
        $otherBill = $this->confirmedBill($this->supplier, '10', '50');

        $this->expectException(ValidationException::class);

        $this->returns()->create(
            ['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id,
                'purchase_bill_id' => $named->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $this->product->id, 'purchase_bill_line_id' => $otherBill->lines->first()->id, 'qty' => '2']],
        );
    }

    /** (৩) ঠিক বিলের ঠিক লাইন — চলে। নিষেধগুলো যেন সব দরজা বন্ধ না করে। */
    public function test_the_right_bill_line_still_works(): void
    {
        $bill = $this->confirmedBill($this->supplier, '10', '50');

        $return = $this->returns()->confirm($this->draft($bill, '2', withBillId: true));

        $this->assertSame(DocumentStatus::CONFIRMED, $return->status);
    }

    // ── সহায়ক ───────────────────────────────────────────────────────────

    private function returns(): PurchaseReturnService
    {
        return app(PurchaseReturnService::class);
    }

    private function draft(PurchaseBill $bill, string $qty, bool $withBillId = false): PurchaseReturn
    {
        return $this->returns()->create(
            ['supplier_id' => $bill->supplier_id, 'warehouse_id' => $this->warehouse->id,
                'purchase_bill_id' => $withBillId ? $bill->id : null,
                'trx_date' => now()->toDateString()],
            [['product_id' => $this->product->id, 'purchase_bill_line_id' => $bill->lines->first()->id, 'qty' => $qty]],
        );
    }

    private function confirmedBill(Supplier $supplier, string $qty, string $rate): PurchaseBill
    {
        $service = app(PurchaseBillService::class);

        return $service->confirm(
            $service->create(
                ['supplier_id' => $supplier->id, 'trx_date' => now()->toDateString()],
                [['product_id' => $this->product->id, 'qty' => $qty, 'rate' => $rate]],
            )
        )->load('lines');
    }

    private function onHand(): string
    {
        return app(StockService::class)->statesFor($this->product, $this->warehouse)['on_hand'];
    }
}
