<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Services\PurchaseOrderService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⛔ দাম না বসিয়েই ক্রয় আদেশ পাকা হত — ৳০-র আদেশ সরবরাহকারীর কাছে (Purchase লাইন-ধরা নিরীক্ষা, ১৩ অক্টোবর ২০২৬; মালিকের "শূন্য
 * দাম নয়", ২৩ সেপ্টেম্বর ২০২৬; [[PurchaseOrderService::confirm()]])।
 *
 * ⚠️ চাহিদা থেকে বানানো আদেশ দাম ০ রেখে আসে; পর্দা ০ আটকায়, সেবা আটকাত না। ⭐ এখন পাকা করার সময় থামে, দাম বসালে চলে।
 */
final class AnOrderWentOutAtZeroTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_order_with_a_line_at_zero_cannot_be_confirmed_until_its_price_is_in(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $orders = app(PurchaseOrderService::class);
        $head = ['supplier_id' => Supplier::query()->orderBy('id')->value('id'),
            'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'), 'trx_date' => now()->toDateString()];
        $product = Product::query()->orderBy('id')->value('id');
        $order = $orders->create($head, [['product_id' => $product, 'ordered_qty' => '10', 'rate' => '0']])->fresh();

        try {
            $orders->confirm($order);
            $this->fail('⛔ ৳০-র সারি নিয়ে ক্রয় আদেশ পাকা হলো।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('lines', $e->errors(), 'অন্য কারণে থামল।');
        }
        $this->assertSame(DocumentStatus::DRAFT, $order->fresh()->status);

        $order = $orders->update($order->fresh(), $head, [['product_id' => $product, 'ordered_qty' => '10', 'rate' => '100']]);
        $this->assertSame(DocumentStatus::CONFIRMED, $orders->confirm($order->fresh())->status, 'দাম বসানোর পরেও পাকা হলো না।');
    }
}
