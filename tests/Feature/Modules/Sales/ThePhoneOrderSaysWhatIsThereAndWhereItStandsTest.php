<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\DeliveryStage;
use App\Modules\Sales\Services\DeliveryStageService;
use App\Modules\Sales\Services\OrderProgress;
use App\Modules\Sales\Services\SalesOrderService;
use App\Modules\Sales\Support\SalesOrderStatus;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ⭐ ফোনের বিক্রয় আদেশ, নতুন নিয়মে (সমন্বয়ক, ৬ অক্টোবর ২০২৬; a6-এর 63dec29d আর 9a2999a2):
 *
 *   · মাল ধরা চালানে, আদেশে নয় — ফোনের আদেশেও "মজুদ এখন — ধরা হয়নি": আছে, চাই, কম; ওয়েবের পাতার একই উৎস
 *     ([[SalesOrderService::availableToPromise()]]); সুইচ চালু (আদেশেই ধরা) হলে ঘরটা নেই
 *   · তালিকায় ব্যাক অর্ডার আর সীমায় আটকানো — ওয়েবের চিপের একই গোনা ([[OrderProgress]]) আর একই কথা
 *   · নতুন নম্বর: চালানের নিজের CHA-…, বিক্রির S-… — ফোন দুটোই পায়; খোঁজায় S-নম্বরে নতুন আর পুরনো দুই কাগজই মেলে
 */
final class ThePhoneOrderSaysWhatIsThereAndWhereItStandsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

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
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        // ⓘ বাকির সীমা এই দাবির প্রশ্ন নয়
        app(SettingsService::class)->set('customer.credit_limit_enabled', false);

        $this->customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail();
    }

    public function test_the_phone_order_shows_what_is_there_what_is_wanted_and_what_is_short_as_the_web_does(): void
    {
        $shelf = bcadd(app(StockService::class)->availableQty($this->product, $this->warehouse), '0', 4);
        $order = $this->confirmed(bcadd($shelf, '5', 4));
        $this->phone();

        $atp = $this->getJson('/api/v1/sales/orders/'.$order->public_id)->assertOk()->json('atp');
        $this->assertCount(1, $atp, '⛔ ফোনের আদেশে "মজুদ এখন" নেই।');
        $this->assertSame([$this->product->name(), $shelf, bcadd($shelf, '5', 4), '5.0000'],
            [$atp[0]['product'], $atp[0]['have'], $atp[0]['want'], $atp[0]['short']], '⛔ আছে/চাই/কম ভুল।');

        $web = app(SalesOrderService::class)->availableToPromise($order->fresh(['lines.product', 'warehouse']));
        $this->assertSame([$web[(int) $this->product->id]['have'], $web[(int) $this->product->id]['want']], [$atp[0]['have'], $atp[0]['want']],
            '⛔ ফোন আর ওয়েবের পাতা আলাদা কথা বলে।');
        $this->assertNull($this->getJson('/api/v1/sales/orders')->json('orders.0.atp'), 'তালিকায় প্রতিটা আদেশের মজুদ গোনা হচ্ছে।');

        $this->assertFalse($atp[0]['enough']);

        // ⛔ SR-এর ফোনে মজুদের সংখ্যা নয় (মালিক, ১ অক্টোবর ২০২৬) — একই আদেশ, মজুদ দেখার চাবি ছাড়া: কেবল "কুলোয় না"
        $sr = User::factory()->create(['is_active' => true, 'current_company_id' => $this->owner->current_company_id]);
        $sr->companies()->attach($this->owner->current_company_id, ['is_active' => true]);
        $sr->givePermissionTo('sales.order.view');
        $this->assertFalse($sr->fresh()->can('inventory.stock.view'), 'প্রস্তুতিটাই ভুল — SR-এর মজুদ দেখার চাবি আছে।');
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($sr->fresh(), [AuthController::APP]);
        $blind = $this->getJson('/api/v1/sales/orders/'.$order->public_id)->assertOk()->json('atp.0');
        $this->assertArrayNotHasKey('have', $blind, '⛔ মজুদ দেখার চাবি ছাড়া SR-এর ফোনে মজুদের সংখ্যা গেল।');
        $this->assertArrayNotHasKey('short', $blind, '⛔ "কম কত" থেকে মজুদ বের করা যায় — চাবি ছাড়া নয়।');
        $this->assertSame([bcadd($shelf, '5', 4), false], [$blind['want'], $blind['enough']]);
        $this->phone();

        // ⭐ একই মানুষ, সুইচ চালু — আদেশেই ধরা, তাই ঘরটা নেই
        app(SettingsService::class)->set('sales.reserve_on_order', true);
        $held = $this->confirmed('2');
        $this->assertSame([], $this->getJson('/api/v1/sales/orders/'.$held->public_id)->assertOk()->json('atp'),
            '⛔ আদেশ যখন মাল ধরে, তখনও "ধরা হয়নি" দেখায়।');
    }

    public function test_the_list_carries_the_back_order_and_the_credit_hold_chips_from_the_webs_own_count(): void
    {
        // ⓘ তাকের পুরো মালের চেয়ে অনেক বেশি — ব্যাক অর্ডার মাপা হয় তাকের আসল মালে, খালিটায় নয় ([[OrderProgress]])
        $back = $this->confirmed('100000');
        $this->challanFor($back, '2');
        $whole = $this->confirmed('1');
        $held = $this->confirmed('1');
        DB::table('sal_orders')->where('id', $held->id)->update(['status' => SalesOrderStatus::CREDIT_HELD, 'credit_short' => '500']);
        $this->phone();

        $rows = collect($this->getJson('/api/v1/sales/orders')->assertOk()->json('orders'))->keyBy('id');
        $web = app(OrderProgress::class)->compute(SalesOrder::query()->whereKey([$back->id, $whole->id, $held->id])->get());

        $this->assertTrue($web[(int) $back->id]['back'], 'প্রস্তুতিটাই ভুল — ওয়েবে এটা ব্যাক অর্ডার নয়।');
        $this->assertTrue($rows[$back->public_id]['back_order'], '⛔ ফোনের তালিকায় ব্যাক অর্ডার নেই।');
        $this->assertFalse($rows[$whole->public_id]['back_order'], '⛔ ব্যাক অর্ডার নয় এমন আদেশে চিপ।');
        $this->assertContains(__('sales::order_status.back_order'), array_column($rows[$back->public_id]['chips'], 'label'));
        $this->assertContains(SalesOrderStatus::deliveryLabel($web[(int) $back->id]['delivery']), array_column($rows[$back->public_id]['chips'], 'label'),
            '⛔ চালানের অগ্রগতি ওয়েবের চিপের কথায় নয়।');

        $this->assertTrue($rows[$held->public_id]['credit_held'], '⛔ সীমায় আটকানো আদেশ ফোনে চেনা যায় না।');
        $this->assertSame('500.00', $rows[$held->public_id]['credit_short']);
        $this->assertSame(['label' => SalesOrderStatus::label(SalesOrderStatus::CREDIT_HELD), 'tone' => 'danger'],
            array_intersect_key($rows[$held->public_id]['chips'][0], ['label' => 1, 'tone' => 1]));
        $this->assertFalse($rows[$whole->public_id]['credit_held']);
    }

    public function test_the_phone_gets_the_papers_own_number_and_the_sale_number_and_finds_old_and_new_by_the_sale_number(): void
    {
        $order = $this->confirmed('3');
        $new = $this->challanFor($order, '1');
        $this->assertStringStartsWith('CHA-', (string) $new->document_no, 'প্রস্তুতিটাই ভুল — নতুন চালানে নিজের নম্বর নেই।');
        $this->assertStringStartsWith('S-', (string) $new->sale_no);

        $old = $this->challanFor($order, '1');
        DB::table('sal_challans')->where('id', $old->id)->update(['document_no' => 'S-7700', 'sale_no' => null]);
        app(DeliveryStageService::class)->move($new, DeliveryStage::DISPATCHED);
        $this->phone();

        $found = fn (string $q) => collect($this->getJson('/api/v1/sales/tracking?q='.urlencode($q))->assertOk()->json('rows'))
            ->where('kind', 'challan')->pluck('document_no')->all();
        $this->assertContains($new->document_no, $found($new->sale_no), '⛔ নতুন চালান বিক্রির নম্বরে মেলেনি।');
        $this->assertContains($new->document_no, $found($new->document_no), '⛔ নতুন চালান নিজের নম্বরে মেলেনি।');
        $this->assertContains('S-7700', $found('S-7700'), '⛔ পুরনো চালান তার S-নম্বরে মেলেনি।');

        $row = collect($this->getJson('/api/v1/sales/deliveries')->assertOk()->json('rows'))->firstWhere('document_no', $new->document_no);
        $this->assertNotNull($row, '⛔ আজকের ডেলিভারিতে নতুন নম্বরের চালান নেই।');
        $this->assertSame($new->sale_no, $row['sale_no'], '⛔ ফোন বিক্রির নম্বর পায় না।');
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    private function confirmed(string $qty): SalesOrder
    {
        $draft = app(SalesOrderService::class)->create([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
        ], [['product_id' => $this->product->id, 'ordered_qty' => $qty, 'rate' => '10']]);

        return app(SalesOrderService::class)->confirm($draft->fresh(['lines']))->fresh(['lines']);
    }

    private function challanFor(SalesOrder $order, string $qty): DeliveryChallan
    {
        $line = $order->fresh(['lines'])->lines->first();
        $challans = app(DeliveryChallanService::class);
        $paper = $challans->create([
            'customer_id' => $order->customer_id, 'warehouse_id' => $this->warehouse->id,
            'sales_order_id' => $order->id, 'trx_date' => now()->toDateString(),
        ], [['product_id' => $line->product_id, 'sales_order_line_id' => $line->id, 'delivered_qty' => $qty, 'rate' => (string) $line->rate]]);

        return $challans->confirm($paper->fresh(['lines']))->fresh();
    }

    private function phone(): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->owner->fresh(), [AuthController::APP]);
    }
}
