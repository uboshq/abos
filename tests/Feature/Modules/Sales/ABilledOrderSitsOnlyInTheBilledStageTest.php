<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\OrderTracking;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Sales\Services\SalesOrderService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ আংশিক গিয়ে বিল হওয়া আদেশ "আংশিক" তালিকাতেও ছিল — ১৩ অক্টোবর ২০২৬, Sales লাইন-ধরা নিরীক্ষা ([[OrderTracking]])।
 *
 * ⓘ ট্যাবের গোনা ([[OrderTracking::stageOf()]]) বিলকে আগে ধরে — আদেশটা কেবল "বিল হয়েছে"-তে; অথচ তালিকার ছাঁকনি "আংশিক"-এ বিল
 * দেখত না, তাই একই আদেশ দুই তালিকায় আর গোনার সাথে মিলত না।
 */
final class ABilledOrderSitsOnlyInTheBilledStageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_stage_list_agrees_with_the_stage_count(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $customer = Customer::query()->orderBy('id')->firstOrFail();
        $product = Product::query()->where('track_batch', false)->orderBy('id')->firstOrFail();
        $head = ['customer_id' => $customer->id, 'warehouse_id' => $warehouse->id, 'trx_date' => now()->toDateString()];

        $orders = app(SalesOrderService::class);
        $order = $orders->confirm($orders->create($head,
            [['product_id' => $product->id, 'ordered_qty' => '10', 'rate' => '100']]))->load('lines');

        $challans = app(DeliveryChallanService::class);
        $challan = $challans->confirm($challans->create([...$head, 'sales_order_id' => $order->id, 'own_transport' => true], [[
            'product_id' => $product->id, 'delivered_qty' => '6', 'rate' => '100', 'sales_order_line_id' => $order->lines->first()->id,
        ]]))->load('lines');

        app(SalesInvoiceService::class)->create($head, [[
            'product_id' => $product->id, 'qty' => '6', 'rate' => '100', 'delivery_challan_line_id' => $challan->lines->first()->id,
        ]]);

        $tracking = app(OrderTracking::class);
        $in = fn (string $stage) => $tracking->query(null, $stage, (int) $customer->id)->pluck('id')->map(fn ($id) => (int) $id)->contains((int) $order->id);

        $this->assertTrue($in(OrderTracking::BILLED), 'প্রস্তুতিটাই ভুল — আদেশটা "বিল হয়েছে"-তে নেই।');
        $this->assertFalse($in(OrderTracking::PARTIAL), '⛔ বিল হওয়া আদেশ "আংশিক" তালিকাতেও');
        $this->assertFalse($in(OrderTracking::PLACED));

        // ⭐ প্রতিটা ধাপের তালিকা আর তার গোনা একই কথা বলে
        $counts = $tracking->counts(null, (int) $customer->id);
        foreach (OrderTracking::STAGES as $stage) {
            $this->assertSame($counts[$stage], $tracking->query(null, $stage, (int) $customer->id)->count(), "⛔ {$stage}: তালিকা আর গোনা আলাদা");
        }
    }
}
