<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\SalesOrderService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * চালান আদেশের নামে কেবল ততটাই মাল ছাড়ে যতটা আদেশ নিজে ধরে আছে — আর বাতিলে ঠিক ততটাই ফেরায় (SO+DO নকশার ধাপ ৫,
 * abos-bb-র পাওয়া ভুল, ৪ অক্টোবর ২০২৬)।
 *
 * ⛔ আগে চালান ধরে নিত আদেশের "অর্ডার − আগে ডেলিভার" পুরোটাই ধরা আছে:
 *  · সুইচ বন্ধে নিশ্চিত হওয়া আদেশ কিছুই ধরে না, অথচ তার চালান অন্য আদেশের ধরা মাল ছেড়ে দিত;
 *  · চালান বাতিলে পুরো `delivered_qty` আবার ধরা পড়ত — যা কোনোদিন ছাড়াই হয়নি তা-ও।
 */
final class AChallanReleasesOnlyWhatItsOrderHeldTest extends TestCase
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
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(SettingsService::class)->set('sales.screen_orders', true);
        app(SettingsService::class)->set('sales.reserve_on_order', true);

        $this->customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        Customer::query()->whereKey($this->customer->id)->update(['credit_limit' => '100000000']);
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

        $this->product = Product::query()->where('track_batch', false)->where('is_active', true)
            ->where('sale_price', '>', 0)->orderBy('id')->firstOrFail();

        app(StockService::class)->move(
            product: $this->product, warehouse: $this->warehouse,
            sourceType: StockService::ADJUSTMENT, sourceId: $this->product->id, floor: '50',
        );
    }

    public function test_a_challan_of_an_order_that_held_nothing_releases_nothing(): void
    {
        // ⓘ অন্য আদেশ ৫টা ধরে — এটাই যেন কেউ না ছাড়ে
        $this->order('5');
        $base = $this->reserved();

        app(SettingsService::class)->set('sales.reserve_on_order', false);
        $quiet = $this->order('3');
        $this->assertSame(0, bccomp($base, $this->reserved(), 4), 'প্রস্তুতিটাই ভুল — সুইচ বন্ধেও মাল ধরা হয়েছে।');

        $challan = $this->deliver($quiet, '1');
        $this->assertSame(0, bccomp($base, $this->reserved(), 4),
            '⛔ কিছু না ধরা আদেশের চালান অন্য আদেশের ধরা মাল ছেড়ে দিয়েছে।');

        app(DeliveryChallanService::class)->cancel($challan->fresh(['lines']), 'ভুল চালান');
        $this->assertSame(0, bccomp($base, $this->reserved(), 4),
            '⛔ কিছু না ছাড়া চালানের বাতিল নতুন করে মাল ধরল।');
    }

    public function test_a_second_challan_releases_only_what_is_still_held_and_its_cancel_returns_just_that(): void
    {
        $base = $this->reserved();
        $order = $this->order('10');
        $this->assertSame(0, bccomp(bcadd($base, '10', 4), $this->reserved(), 4), 'প্রস্তুতিটাই ভুল — আদেশ ১০টা ধরেনি।');

        $this->deliver($order, '6');
        $this->assertSame(0, bccomp(bcadd($base, '4', 4), $this->reserved(), 4), 'প্রথম চালান ধরা ৬টা ছাড়েনি।');

        $second = $this->deliver($order, '4');
        $this->assertSame(0, bccomp($base, $this->reserved(), 4), 'দ্বিতীয় চালান অবশিষ্ট ধরা ৪টা ছাড়েনি।');

        app(DeliveryChallanService::class)->cancel($second->fresh(['lines']), 'ফেরত');
        $this->assertSame(0, bccomp(bcadd($base, '4', 4), $this->reserved(), 4),
            '⛔ বাতিলে ঠিক ছাড়া ৪টাই ফেরার কথা।');
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    private function reserved(): string
    {
        return (string) app(StockService::class)->statesFor($this->product, $this->warehouse)['reserved'];
    }

    private function order(string $qty): SalesOrder
    {
        $orders = app(SalesOrderService::class);
        $draft = $orders->create([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
        ], [[
            'product_id' => $this->product->id,
            'ordered_qty' => $qty,
            'rate' => (string) $this->product->sale_price,
        ]]);

        return $orders->confirm($draft->fresh(['lines']))->fresh(['lines']);
    }

    private function deliver(SalesOrder $order, string $qty): DeliveryChallan
    {
        $line = $order->fresh(['lines'])->lines->first();
        $challans = app(DeliveryChallanService::class);

        $paper = $challans->create([
            'customer_id' => $order->customer_id,
            'warehouse_id' => $this->warehouse->id,
            'sales_order_id' => $order->id,
            'trx_date' => now()->toDateString(),
        ], [[
            'product_id' => $line->product_id,
            'sales_order_line_id' => $line->id,
            'delivered_qty' => $qty,
            'rate' => (string) $line->rate,
        ]]);

        return $challans->confirm($paper->fresh(['lines']))->fresh(['lines']);
    }
}
