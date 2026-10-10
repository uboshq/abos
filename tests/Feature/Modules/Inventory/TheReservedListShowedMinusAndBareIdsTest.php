<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\SalesOrderService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * সংরক্ষিত মালের তালিকায় "−২", "−৫" আর কাগজের নম্বরের বদলে আইডি — পাতা-ঝাড়ু, ধাপ ০ (১০ অক্টোবর ২০২৬)।
 *
 * ⓘ কারণ: আদেশ ধরে নিজের নামে, তার চালান ছাড়ে চালানের নামে — সারি ছিল চলাচলের উৎস ধরে, তাই চালানের সারি ঋণাত্মক।
 * ⭐ এখন সারি যে কাগজ মাল ধরে আছে তার নামে, তার নম্বরে; যোগফল মজুদের "অর্ডারে ধরা"-র সমান।
 */
final class TheReservedListShowedMinusAndBareIdsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_list_shows_what_each_paper_still_holds_under_its_number(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(SettingsService::class)->set('sales.screen_orders', true);
        app(SettingsService::class)->set('sales.reserve_on_order', true);

        $customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        Customer::query()->whereKey($customer->id)->update(['credit_limit' => '100000000']);
        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $product = Product::query()->where('track_batch', false)->where('is_active', true)->where('sale_price', '>', 0)->orderBy('id')->firstOrFail();
        app(StockService::class)->move(product: $product, warehouse: $warehouse, sourceType: StockService::ADJUSTMENT, sourceId: $product->id, floor: '50');

        $orders = app(SalesOrderService::class);
        $order = fn (string $qty) => $orders->confirm($orders->create(
            ['customer_id' => $customer->id, 'warehouse_id' => $warehouse->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $product->id, 'ordered_qty' => $qty, 'rate' => (string) $product->sale_price]],
        )->fresh(['lines']))->fresh(['lines']);
        $order('1');   // ⓘ আরেকটা আদেশ আগে — আদেশ আর চালানের আইডি যেন এক না হয়
        $order = $order('10');

        $challans = app(DeliveryChallanService::class);
        $challan = $challans->confirm($challans->create(
            ['customer_id' => $customer->id, 'warehouse_id' => $warehouse->id, 'sales_order_id' => $order->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $product->id, 'sales_order_line_id' => $order->lines->first()->id, 'delivered_qty' => '6', 'rate' => (string) $product->sale_price]],
        )->fresh(['lines']));
        $this->assertNotSame($order->id, $challan->id, 'দৃশ্যটাই বানানো যায়নি — আদেশ আর চালানের আইডি এক।');

        $rows = collect(app(ReportEngine::class)->run('inventory.reserved', ['to' => now()->toDateString()], perPage: 1000)->rows)
            ->filter(fn ($r) => (int) $r['product_id'] === $product->id && in_array($r['warehouse_name'], [$warehouse->name_bn, $warehouse->name_en], true));

        $mine = $rows->first(fn ($r) => $r['holder_type'] === SalesOrder::STOCK_SOURCE && (int) $r['holder_id'] === $order->id);
        $this->assertNotNull($mine, '⛔ আদেশটা তালিকায় নেই।');
        $this->assertSame(0, bccomp((string) $mine['reserved'], '4', 4), '⛔ আদেশ ১০ ধরে ৬ দিয়েছে — ধরা থাকার কথা ৪।');
        $this->assertSame($order->document_no, $mine['holder_no'], '⛔ কাগজের নম্বরের বদলে অন্য কিছু।');

        $this->assertSame([], $rows->filter(fn ($r) => bccomp((string) $r['reserved'], '0', 4) < 0)->values()->all(), '⛔ তালিকায় ঋণাত্মক ধরা।');
        $this->assertSame(0, bccomp(
            $rows->reduce(fn ($s, $r) => bcadd($s, (string) $r['reserved'], 4), '0'),
            (string) app(StockService::class)->statesFor($product, $warehouse)['reserved'], 4,
        ), '⛔ তালিকার যোগফল মজুদের "অর্ডারে ধরা"-র সাথে মেলে না।');

        $this->get(route('inventory.report.show', 'reserved'))->assertOk()->assertSee($order->document_no);
    }
}
