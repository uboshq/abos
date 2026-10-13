<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\DeliveryChallanLine;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Sales\Services\SalesOrderService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ চালানের সারির "বিল বাকি" দেখার মানুষ ধরে বদলাত — ১৩ অক্টোবর ২০২৬, Sales লাইন-ধরা নিরীক্ষা ([[DeliveryChallanLine::invoicedQty()]])।
 *
 * ⓘ ৬টার চালান, তার পুরো বিল অন্য শাখায় কাটা। এক শাখা দেখা মানুষের চোখে বিলটা ছাঁকা পড়ত, তাই সারিটা "৬ বিল বাকি" দেখাত — আর
 * বিলের ফর্ম আবার ৬ ভরত। এখন সবার চোখে ০।
 */
final class TheChallanLineIsBilledTheSameForEveryViewerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_bill_in_another_branch_still_bills_the_line(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $here = (int) $company->defaultBranch()?->id;
        CompanyContext::set($company->id, $here);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);

        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $customer = Customer::query()->orderBy('id')->firstOrFail();
        $product = Product::query()->where('track_batch', false)->orderBy('id')->firstOrFail();
        $head = ['customer_id' => $customer->id, 'warehouse_id' => $warehouse->id, 'trx_date' => now()->toDateString()];

        $orders = app(SalesOrderService::class);
        $order = $orders->confirm($orders->create($head,
            [['product_id' => $product->id, 'ordered_qty' => '6', 'rate' => '100']]))->load('lines');

        $challans = app(DeliveryChallanService::class);
        $challan = $challans->confirm($challans->create([...$head, 'sales_order_id' => $order->id, 'own_transport' => true], [[
            'product_id' => $product->id, 'delivered_qty' => '6', 'rate' => '100', 'sales_order_line_id' => $order->lines->first()->id,
        ]]))->load('lines');
        $lineId = (int) $challan->lines->first()->id;

        $invoice = app(SalesInvoiceService::class)->create($head, [[
            'product_id' => $product->id, 'qty' => '6', 'rate' => '100', 'delivery_challan_line_id' => $lineId,
        ]]);

        $this->assertSame('0.0000', DeliveryChallanLine::query()->findOrFail($lineId)->uninvoicedQty(), 'প্রস্তুতিটাই ভুল — বিলটা সারিতে গোনা হলো না।');

        // ⓘ বিলটা অন্য শাখায়, আর দেখার মানুষ কেবল এই শাখা দেখেন
        $there = (int) Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('id', '<>', $here)->orderBy('id')->value('id');
        $invoice->forceFill(['branch_id' => $there])->saveQuietly();
        $owner->forceFill(['view_all_branches' => false])->save();
        app(DataScope::class)->forget();
        $this->actingAs($owner->fresh());

        $this->assertSame('0.0000', DeliveryChallanLine::query()->findOrFail($lineId)->uninvoicedQty(),
            '⛔ অন্য শাখায় কাটা বিল এই মানুষের চোখে নেই — সারিটা আবার "বিল বাকি" দেখাল');
    }
}
