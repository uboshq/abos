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
use App\Modules\Sales\Events\CollectionConfirmed;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\SalesOrderService;
use App\Modules\Sales\Support\SalesOrderStatus as S;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * অনুমোদিত বিক্রয় আদেশ নিজের মাল ধরে নিশ্চিত হয়; বাকির সীমায় আটকে থাকা আদেশ টাকা এলে এগোয় — SO+DO মেশানোর নকশা,
 * ধাপ ৪ (abos-bb-র 85a1846f, ৪ অক্টোবর ২০২৬)।
 *
 * ⛔ এই শ্রোতা ছাড়া নতুন ধারার আদেশ "অনুমোদিত"-তেই থেমে থাকত, মাল কেউ ধরত না — আর সুইচটা চালু করাই যেত না।
 */
final class AnApprovedOrderHoldsItsStockAndIsConfirmedTest extends TestCase
{
    use RefreshDatabase;

    private Customer $buyer;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        app(\App\Core\Services\SettingsService::class)->set('sales.reserve_on_order', true); // ⓘ এই দাবির প্রশ্নে আদেশে ধরা আছে — ডিফল্ট এখন চালানে (মালিক, ৬ অক্টোবর ২০২৬)
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $settings = app(SettingsService::class);
        $settings->set('customer.credit_limit_enabled', true);
        $settings->set(SalesOrderService::REPLACES_DO, true);

        $this->buyer = Customer::query()->create(['code' => 'OS4-BUY', 'name_en' => 'Step Four Buyer', 'name_bn' => 'ধাপ চারের ক্রেতা', 'is_active' => true]);
        Customer::query()->whereKey($this->buyer->id)->update(['credit_limit' => '100000000']);

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->where('track_batch', false)->where('is_active', true)
            ->where('sale_price', '>', 0)->orderBy('id')->firstOrFail();

        app(StockService::class)->move(
            product: $this->product, warehouse: $this->warehouse,
            sourceType: StockService::ADJUSTMENT, sourceId: $this->product->id, floor: '50',
        );
    }

    public function test_an_approved_order_holds_its_stock_and_is_confirmed(): void
    {
        $before = $this->reserved();

        $order = app(SalesOrderService::class)->confirm($this->draft('6')->fresh(['lines']))->fresh();

        $this->assertSame(S::CONFIRMED, $order->status, '⛔ অনুমোদিত আদেশ "অনুমোদিত"-তেই থেমে রইল — কেউ নিশ্চিত করল না।');
        $this->assertSame(0, bccomp(bcadd($before, '6', 4), $this->reserved(), 4), '⛔ অনুমোদিত আদেশের মাল ধরা হয়নি।');
        $this->assertSame(0, bccomp('6', app(SalesOrderService::class)->heldByThisOrder($order)[(int) $this->product->id] ?? '0', 4),
            '⛔ ধরা মাল আদেশের নিজের উৎসে বসেনি — চালান আর বাতিল তা খুঁজে পাবে না।');
    }

    public function test_short_stock_holds_what_there_is_and_the_order_still_goes_on(): void
    {
        $available = app(StockService::class)->availableQty($this->product, $this->warehouse);
        $want = bcadd($available, '7', 4);

        $order = app(SalesOrderService::class)->confirm($this->draft($want)->fresh(['lines']))->fresh();

        $this->assertSame(S::CONFIRMED, $order->status, '⛔ মাল কম বলে সই হয়ে যাওয়া আদেশ আটকে গেল।');
        $this->assertSame(0, bccomp($available, app(SalesOrderService::class)->heldByThisOrder($order)[(int) $this->product->id] ?? '0', 4),
            '⛔ যতটা ছিল ততটা ধরা হয়নি — বা তার বেশি ধরা হয়েছে।');
        $this->assertSame(0, bccomp(app(StockService::class)->availableQty($this->product, $this->warehouse), '0', 4),
            '⛔ পাওয়া মাল ঋণাত্মক হলো বা থেকে গেল।');
    }

    public function test_a_credit_held_order_moves_on_when_money_arrives(): void
    {
        Customer::query()->whereKey($this->buyer->id)->update(['credit_limit' => '1']);
        $order = app(SalesOrderService::class)->confirm($this->draft('2')->fresh(['lines']))->fresh();
        $this->assertSame(S::CREDIT_HELD, $order->status, 'প্রস্তুতিটাই ভুল — আদেশ বাকির সীমায় আটকায়নি।');

        Customer::query()->whereKey($this->buyer->id)->update(['credit_limit' => '100000000']);
        event(new CollectionConfirmed(publicId: 'step-four', payload: ['customer_id' => (int) $this->buyer->id], companyId: (int) CompanyContext::id()));

        $this->assertSame(S::CONFIRMED, $order->fresh()->status, '⛔ টাকা আসার পরেও আটকে থাকা আদেশ এগোয়নি।');
        $this->assertSame(0, bccomp('2', app(SalesOrderService::class)->heldByThisOrder($order->fresh())[(int) $this->product->id] ?? '0', 4));
    }

    /**
     * ⭐ খোলা নিশ্চিত আদেশ বাকির সীমায় গোনা — একবার, দুবার নয় (উত্তর ৪, [[CreditExposure::openOrders()]])।
     * ⓘ আংশিক চালানের পরে আদেশের বাকি অংশ + চালানের অংশ = আদেশের পুরো দাম; চালানের অংশ আবার আদেশে নয়।
     */
    public function test_an_open_order_counts_against_the_limit_once(): void
    {
        $exposure = app(\App\Modules\Sales\Services\CreditExposure::class);
        $before = $exposure->pending($this->buyer->fresh());

        $order = app(SalesOrderService::class)->confirm($this->draft('6')->fresh(['lines']))->fresh(['lines']);
        $value = (string) $order->lines->first()->amount;

        $this->assertSame(0, bccomp(bcadd($before, $value, 4), $exposure->pending($this->buyer->fresh()), 2),
            '⛔ নিশ্চিত আদেশ বাকির সীমায় গোনা হয়নি — সীমার বাইরে আদেশ নেওয়া যেত।');

        $line = $order->lines->first();
        $challans = app(\App\Modules\Sales\Services\DeliveryChallanService::class);
        $paper = $challans->create([
            'customer_id' => $order->customer_id,
            'warehouse_id' => $this->warehouse->id,
            'sales_order_id' => $order->id,
            'trx_date' => now()->toDateString(),
        ], [[
            'product_id' => $line->product_id,
            'sales_order_line_id' => $line->id,
            'delivered_qty' => '4',
            'rate' => (string) $line->rate,
        ]]);
        $challans->confirm($paper->fresh(['lines']));

        $this->assertSame(0, bccomp(bcadd($before, $value, 4), $exposure->pending($this->buyer->fresh()), 2),
            '⛔ আংশিক চালানের পরে একই বিক্রি দুবার গোনা হলো (আদেশে আর চালানে), বা কম গোনা হলো।');
    }

    private function reserved(): string
    {
        return (string) app(StockService::class)->statesFor($this->product, $this->warehouse)['reserved'];
    }

    private function draft(string $qty): SalesOrder
    {
        return app(SalesOrderService::class)->create([
            'customer_id' => $this->buyer->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
        ], [[
            'product_id' => $this->product->id,
            'ordered_qty' => $qty,
            'rate' => (string) $this->product->sale_price,
        ]]);
    }
}
