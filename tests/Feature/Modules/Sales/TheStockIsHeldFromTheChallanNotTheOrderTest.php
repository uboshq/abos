<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Module\ModuleRegistry;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\SalesOrderService;
use App\Modules\Sales\Support\SalesOrderStatus;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * মাল ধরা শুরু ডিপোর চালানে, আদেশে নয় — মালিক, ৬ অক্টোবর ২০২৬: *"না, ডিপোতে কনফার্ম করার পরই, মানে ডেলিভারি চালান থেকে
 * স্টক ব্লক হবে"*।
 *
 * দাবি — একই মানুষ, একই পণ্য:
 *   ডিফল্টে (`sales.reserve_on_order` বন্ধ) আদেশ নিশ্চিত হলে কিছু ধরা হয় না, পাতায় কেবল কত আছে আর কত চাই;
 *   নতুন ধারায় অনুমোদিত আদেশও কিছু না ধরে নিশ্চিত হয়;
 *   দুটো আদেশ একই তাকের পুরোটা চাইলে দুটোই নিশ্চিত হয়, কিন্তু চালান কেবল তাকের মাল পর্যন্তই দেয়;
 *   সুইচ চালু করলে আগের মতো আদেশেই ধরা।
 */
final class TheStockIsHeldFromTheChallanNotTheOrderTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        app(StandardChart::class)->install();
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        // ⓘ বাকির সীমা এই দাবির প্রশ্ন নয়
        app(SettingsService::class)->set('customer.credit_limit_enabled', false);

        $this->customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail();
    }

    public function test_by_default_a_confirmed_order_holds_nothing_and_shows_what_is_there(): void
    {
        $default = collect(app(ModuleRegistry::class)->all())->flatMap(fn ($m) => $m->settings)
            ->firstWhere('key', 'sales.reserve_on_order')['default'] ?? null;
        $this->assertFalse($default, '⛔ ডিফল্টে আদেশই মাল ধরে — মালিকের নিয়মে ধরা শুরু চালানে।');

        $order = $this->confirmed('10');
        $this->assertSame(0, bccomp('0', $this->heldBy($order), 4), '⛔ ডিফল্টে আদেশ নিশ্চিত হয়েই মাল ধরল।');

        $have = app(StockService::class)->availableQty($this->product, $this->warehouse);
        $this->get(route('sales.order.show', $order))->assertOk()
            ->assertSee('data-atp', false)
            ->assertSee(__('sales::order_status.box_atp'));
        $this->assertSame(0, bccomp($have, app(StockService::class)->availableQty($this->product, $this->warehouse), 4),
            '⛔ পাতা দেখতেই খালি মাল কমল।');

        // ⭐ একই মানুষ, সুইচ চালু — আগের মতো আদেশেই ধরা, আর ATP-র ঘর নেই
        app(SettingsService::class)->set('sales.reserve_on_order', true);
        $held = $this->confirmed('10');
        $this->assertSame(0, bccomp('10', $this->heldBy($held), 4), 'সুইচ চালু, তবু আদেশ মাল ধরল না।');
        $this->get(route('sales.order.show', $held))->assertOk()->assertDontSee('data-atp', false);
    }

    public function test_in_the_new_flow_an_approved_order_is_confirmed_without_holding(): void
    {
        app(SettingsService::class)->set(SalesOrderService::REPLACES_DO, true);
        \App\Models\ApprovalFlow::query()->where('module', 'sales')->where('action', SalesOrderService::APPROVAL_ACTION)->delete();

        $order = app(SalesOrderService::class)->submit($this->draft('10'));

        $this->assertSame(SalesOrderStatus::CONFIRMED, $order->fresh()->status, '⛔ অনুমোদিত আদেশ নিশ্চিত হলো না।');
        $this->assertSame(0, bccomp('0', $this->heldBy($order), 4), '⛔ নতুন ধারায় অনুমোদনেই মাল ধরা হলো।');
    }

    public function test_two_orders_for_the_whole_shelf_both_confirm_and_the_challans_give_only_the_shelf(): void
    {
        $shelf = bcadd(app(StockService::class)->availableQty($this->product, $this->warehouse), '0', 4);
        $first = $this->confirmed($shelf);
        $second = $this->confirmed($shelf);

        $this->challanFor($first, $shelf);
        $this->assertSame(1, bccomp($shelf, app(StockService::class)->availableQty($this->product, $this->warehouse), 4),
            'প্রস্তুতিটাই ভুল — প্রথম চালানে মাল আটকাল না।');

        $this->expectException(ValidationException::class);
        $this->challanFor($second, $shelf);
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    private function draft(string $qty): SalesOrder
    {
        return app(SalesOrderService::class)->create([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
        ], [['product_id' => $this->product->id, 'ordered_qty' => $qty, 'rate' => '10']]);
    }

    private function confirmed(string $qty): SalesOrder
    {
        return app(SalesOrderService::class)->confirm($this->draft($qty)->fresh(['lines']))->fresh(['lines']);
    }

    private function heldBy(SalesOrder $order): string
    {
        return bcadd((string) StockMovement::query()->where('source_type', SalesOrder::STOCK_SOURCE)
            ->where('source_id', $order->id)->where('document_no', $order->document_no)->sum('reserved_change'), '0', 4);
    }

    private function challanFor(SalesOrder $order, string $qty): object
    {
        $line = $order->fresh(['lines'])->lines->first();
        $challans = app(DeliveryChallanService::class);
        $paper = $challans->create([
            'customer_id' => $order->customer_id, 'warehouse_id' => $this->warehouse->id,
            'sales_order_id' => $order->id, 'trx_date' => now()->toDateString(),
        ], [['product_id' => $line->product_id, 'sales_order_line_id' => $line->id, 'delivered_qty' => $qty, 'rate' => (string) $line->rate]]);

        return $challans->confirm($paper->fresh(['lines']));
    }
}
