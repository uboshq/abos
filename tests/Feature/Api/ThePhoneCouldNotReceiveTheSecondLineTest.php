<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Engines\Sync\PushedChange;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Models\PurchaseOrder;
use App\Modules\Purchase\Services\PurchaseOrderService;
use App\Modules\Purchase\Sync\GoodsReceiptSync;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ⛔ আদেশের দ্বিতীয় সারির মাল ফোন থেকে নেওয়া যেত না (Api/Sync নিরীক্ষা, ১৪ অক্টোবর ২০২৬; [[GoodsReceiptSync::apply()]])।
 *
 * ⚠️ একই পণ্য আদেশে দুই সারিতে (৫ + ৫) — ফোনের প্রতিটা গ্রহণ প্রথম সারিতে বসত; প্রথমটা ভরলে বাকি ৫ "আদেশের বেশি" বলে থামত।
 * ⭐ এখন জায়গা থাকা সারিতে।
 */
final class ThePhoneCouldNotReceiveTheSecondLineTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_second_receipt_lands_on_the_order_line_that_still_has_room(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);
        app(StandardChart::class)->install();

        $product = Product::query()->where('track_batch', false)->where('track_serial', false)->orderBy('id')->firstOrFail();
        $orders = app(PurchaseOrderService::class);
        $order = $orders->create(
            ['supplier_id' => Supplier::query()->orderBy('id')->firstOrFail()->id, 'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id,
                'trx_date' => now()->toDateString()],
            [['product_id' => $product->id, 'ordered_qty' => '5', 'rate' => '100'], ['product_id' => $product->id, 'ordered_qty' => '5', 'rate' => '100']],
        );
        $order = $orders->confirm(PurchaseOrder::query()->findOrFail($order->id));

        $receive = fn (string $qty) => app(GoodsReceiptSync::class)->apply($owner, PushedChange::fromArray([
            'changeId' => (string) Str::uuid(), 'entityType' => 'GoodsReceipt', 'operation' => 'CREATE', 'entityId' => (string) Str::uuid(),
            'payloadJson' => json_encode(['orderId' => (string) $order->public_id, 'receivedOn' => now()->toDateString(),
                'lines' => [['productId' => (string) $product->public_id, 'receivedQty' => $qty]]]),
        ]));

        $receive('5');
        $receive('3');

        $lines = $order->fresh()->lines()->orderBy('id')->get();
        $this->assertSame(0, bccomp('5', $lines[0]->receivedQty(), 4), 'প্রথম সারি');
        $this->assertSame(0, bccomp('3', $lines[1]->receivedQty(), 4), '⛔ দ্বিতীয় গ্রহণ দ্বিতীয় সারিতে বসেনি');
    }
}
