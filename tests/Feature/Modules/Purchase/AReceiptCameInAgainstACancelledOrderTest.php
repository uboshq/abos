<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Services\PurchaseOrderService;
use App\Modules\Purchase\Services\PurchaseReceiptService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⛔ বাতিল ক্রয় আদেশের বিপরীতে খসড়া রসিদ পাকা হত (Purchase লাইন-ধরা নিরীক্ষা, ১৩ অক্টোবর ২০২৬; [[PurchaseReceiptService::assertWithinOrder()]])।
 *
 * ⚠️ আদেশ খোলা কি না দেখা হত কেবল রসিদ লেখার সময়; পাকা করার সময় কেবল আদেশের সীমা। আদেশ → খসড়া রসিদ → আদেশ বাতিল → রসিদ পাকা:
 * মাল ঢুকত, দেনা বসত, অথচ আদেশটা বাতিল।
 */
final class AReceiptCameInAgainstACancelledOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_draft_receipt_cannot_be_confirmed_once_its_order_is_cancelled(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $product = Product::query()->whereNull('tax_id')->where('track_batch', false)->where('track_serial', false)
            ->where('qc_required', false)->orderBy('id')->firstOrFail();
        $orders = app(PurchaseOrderService::class);
        $order = $orders->confirm($orders->create(
            ['supplier_id' => Supplier::query()->orderBy('id')->value('id'), 'warehouse_id' => $warehouse->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $product->id, 'ordered_qty' => '10', 'rate' => '100']],
        )->fresh());

        $receipts = app(PurchaseReceiptService::class);
        $receipt = $receipts->create(
            ['purchase_order_id' => $order->id, 'warehouse_id' => $warehouse->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $product->id, 'purchase_order_line_id' => $order->lines()->firstOrFail()->id, 'received_qty' => '10', 'rate' => '100']],
        );

        $orders->cancel($order->fresh(), 'সরবরাহকারী দিতে পারবেন না');

        try {
            $receipts->confirm($receipt->fresh());
            $this->fail('⛔ বাতিল আদেশের বিপরীতে রসিদ পাকা হলো — মাল ঢুকল, দেনা বসল।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('purchase_order_id', $e->errors(), 'অন্য কারণে থামল।');
        }

        $this->assertSame(DocumentStatus::DRAFT, $receipt->fresh()->status);
    }
}
