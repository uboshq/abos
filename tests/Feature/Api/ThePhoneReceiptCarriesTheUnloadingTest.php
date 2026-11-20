<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Engines\Sync\PushedChange;
use App\Core\Engines\Sync\SyncRejection;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Models\PurchaseOrder;
use App\Modules\Purchase\Models\PurchaseReceipt;
use App\Modules\Purchase\Services\PurchaseOrderService;
use App\Modules\Purchase\Sync\GoodsReceiptSync;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\HiresTheLabour;
use Tests\TestCase;

/**
 * ⭐ ফোনের মাল গ্রহণেও আনলোডিং — মালিক, ১৮ নভেম্বর ২০২৬ ("ওকে"; ২০ নভেম্বর ২০২৬ দাবি; [[GoodsReceiptSync::unloadingFrom()]])।
 *
 * ⓘ ট্রাকের পাশে দাঁড়িয়ে যিনি গোনেন, তিনিই জানেন কে নামাল আর কত নিল। ফোন টিল আর ঠিকাদার পাঠায় public_id-তে; রসিদ খসড়াই
 *   থাকে — বাছাই সেখানে বসে, পাকা ওয়েবে।
 *
 * দাবি: বাকি + ঠিকাদার + সারির হাতের দর খসড়ায় বসে; অচেনা ঠিকাদার — কারণসহ ফেরত, কোনো রসিদ নয়; আনলোডিং না পাঠালে আগের মতো।
 */
final class ThePhoneReceiptCarriesTheUnloadingTest extends TestCase
{
    use HiresTheLabour;
    use RefreshDatabase;

    private User $owner;

    private Product $product;

    private PurchaseOrder $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();

        $this->product = Product::query()->where('track_batch', false)->where('track_serial', false)->orderBy('id')->firstOrFail();
        $orders = app(PurchaseOrderService::class);
        $order = $orders->create(
            ['supplier_id' => Supplier::query()->orderBy('id')->firstOrFail()->id, 'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id,
                'trx_date' => now()->toDateString()],
            [['product_id' => $this->product->id, 'ordered_qty' => '20', 'rate' => '100']],
        );
        $this->order = $orders->confirm(PurchaseOrder::query()->findOrFail($order->id));
    }

    public function test_the_phone_unloading_is_stamped_on_the_draft_receipt(): void
    {
        $contractor = $this->labourContractor();

        $publicId = $this->receive([
            'unloading' => ['mode' => 'due', 'amount' => '64.50', 'contractor' => (string) $contractor->public_id],
        ], '2.5');

        $receipt = PurchaseReceipt::query()->where('public_id', $publicId)->firstOrFail();

        $this->assertSame(DocumentStatus::DRAFT, $receipt->status, 'ফোনের রসিদ খসড়া থাকার কথা।');
        $this->assertSame(['due', 0, $contractor->id], [$receipt->unload_mode, bccomp((string) $receipt->unload_amount, '64.50', 4), (int) $receipt->unload_contractor_id],
            '⛔ ফোনের আনলোডিং রসিদে বসেনি।');
        $this->assertSame('2.5000', bcadd((string) $receipt->lines()->firstOrFail()->unload_rate, '0', 4), '⛔ ফোনের সারির হাতের দর হারাল।');
        $this->assertSame(0, bccomp((string) $receipt->lines()->firstOrFail()->unload_cost, '64.50', 4), 'এক সারিতে পুরো মজুরির ভাগ বসেনি।');
    }

    public function test_an_unknown_contractor_is_sent_back_and_nothing_is_written(): void
    {
        $count = PurchaseReceipt::query()->count();

        try {
            $this->receive(['unloading' => ['mode' => 'due', 'amount' => '50', 'contractor' => (string) Str::uuid()]]);
            $this->fail('⛔ অচেনা ঠিকাদারের নামে ফোনের রসিদ বসল।');
        } catch (SyncRejection) {
            // ⓘ কারণসহ ফেরত — ফোন দেখায়
        }

        $this->assertSame($count, PurchaseReceipt::query()->count(), '⛔ ফেরত যাওয়া ফোনের রসিদ থেকে গেল।');
    }

    /** ⛔ ফোন থেকেও কেবল "হাম্মালি ঠিকাদার" — মালের সরবরাহকারী পাঠালে কারণসহ ফেরত, কিছুই লেখা হয় না (সমন্বয়ক, ২০ নভেম্বর ২০২৬) */
    public function test_a_supplier_who_is_not_a_labour_contractor_is_sent_back(): void
    {
        $mill = $this->plainSupplier();
        $count = PurchaseReceipt::query()->count();

        try {
            $this->receive(['unloading' => ['mode' => 'due', 'amount' => '50', 'contractor' => (string) $mill->public_id]]);
            $this->fail('⛔ ঠিকাদার নন এমন সরবরাহকারীর নামে ফোনের রসিদ বসল।');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertSame([__('supplier::labour.not_a_contractor')], $e->errors()['unload_contractor_id'] ?? null);
        } catch (SyncRejection) {
            // ⓘ সিঙ্কের নিজের ফেরতেও চলে — থামাটাই দাবি
        }

        $this->assertSame($count, PurchaseReceipt::query()->count(), '⛔ ফেরত যাওয়া ফোনের রসিদ থেকে গেল।');
    }

    public function test_an_old_phone_without_the_unloading_still_writes_its_draft(): void
    {
        $receipt = PurchaseReceipt::query()->where('public_id', $this->receive([]))->firstOrFail();

        $this->assertNull($receipt->unload_mode, 'আনলোডিং না পাঠানো রসিদে কিছু বাছা হয়ে গেল।');
    }

    /** @param  array<string, mixed>  $extra */
    private function receive(array $extra, ?string $unloadRate = null): string
    {
        $line = ['productId' => (string) $this->product->public_id, 'receivedQty' => '10'];

        if ($unloadRate !== null) {
            $line['unloadRate'] = $unloadRate;
        }

        return app(GoodsReceiptSync::class)->apply($this->owner, PushedChange::fromArray([
            'changeId' => (string) Str::uuid(), 'entityType' => 'GoodsReceipt', 'operation' => 'CREATE', 'entityId' => (string) Str::uuid(),
            'payloadJson' => json_encode(['orderId' => (string) $this->order->public_id, 'receivedOn' => now()->toDateString(),
                'lines' => [$line], ...$extra]),
        ]));
    }
}
