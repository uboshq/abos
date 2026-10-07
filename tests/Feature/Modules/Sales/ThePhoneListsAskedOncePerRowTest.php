<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\DeliveryOrder;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\DeliveryOrderService;
use App\Modules\Sales\Services\DeliveryStage;
use App\Modules\Sales\Services\DeliveryStageService;
use App\Modules\Sales\Support\DeliveryOrderStatus;
use App\Modules\Sales\Support\SalesOrderStatus;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⚠️ ফোনের তালিকা প্রতিটা সারিতে আলাদা ডাক দিত — পুরো ERP অডিট, ৬ অক্টোবর ২০২৬, ফোন ⚠️১৭।
 *
 * আদেশ, DO আর দরপত্রের তালিকা প্রতিটা সারির পণ্য আর শেষ অনুমোদন আলাদা করে আনত; ডেলিভারির তালিকা প্রতিটা চালানের গাড়ি আর
 * বাহক; কাউন্টারের শুরু প্রতিটা পণ্যে দুই ডাক। পঞ্চাশ সারির পাতায় শ'খানেক ডাক — দুর্বল নেটে ফোন বসে থাকে।
 *
 * ⭐ দাবি: সারি বাড়লে ডাক বাড়ে না; আর একবারে আনা অনুমোদন ঠিক সারিতেই বসে (সইকারী তালিকাতেই `approval_id` পান)।
 */
final class ThePhoneListsAskedOncePerRowTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Customer $shop;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        app(SettingsService::class)->set('customer.credit_limit_enabled', false);
        $this->shop = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $this->product = Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail();
    }

    public function test_the_order_the_delivery_order_and_the_quotation_lists_ask_the_same_number_of_times_for_two_rows_or_eight(): void
    {
        $this->phone($this->owner);
        $body = ['customer' => (string) $this->shop->public_id, 'lines' => [
            ['product' => (string) $this->product->public_id, 'qty' => '2'],
            ['product' => (string) Product::query()->where('name_en', 'Premium Tea 250gm')->value('public_id'), 'qty' => '1'],
        ]];

        $this->write($body, 2);
        $few = $this->asked();
        $this->write($body, 6);
        $many = $this->asked();

        $this->assertSame(8, $many['orders'][1], 'আটটা আদেশ তালিকায় আসার কথা।');
        foreach (['orders', 'delivery-orders', 'quotations'] as $list) {
            $this->assertSame($few[$list][0], $many[$list][0], "⛔ {$list}: দুই সারিতে {$few[$list][0]} ডাক, আট সারিতে {$many[$list][0]} — প্রতি সারিতে আলাদা ডাক।");
        }
    }

    public function test_the_signer_gets_the_approval_on_the_list_row_and_the_writer_does_not(): void
    {
        $supervisor = User::factory()->create(['is_active' => true, 'current_company_id' => $this->owner->current_company_id]);
        $supervisor->companies()->attach($this->owner->current_company_id, ['is_active' => true]);
        CompanyContext::forCompany((int) $this->owner->current_company_id, fn () => $supervisor->givePermissionTo(
            array_map(fn (string $k) => Permission::findOrCreate($k, 'web'), ['sales.do.view', 'approval.decide'])));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $flow = ApprovalFlow::create(['company_id' => $this->owner->current_company_id, 'module' => 'sales',
            'action' => DeliveryOrderService::APPROVAL_ACTION, 'is_active' => true]);
        ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => 'user', 'approver_id' => $supervisor->id]);

        $service = app(DeliveryOrderService::class);
        $papers = collect(range(1, 3))->map(fn () => $service->submit($service->create(
            ['customer_id' => $this->shop->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $this->product->id, 'qty' => '2']], $this->owner), $this->owner));
        $draft = $service->create(['customer_id' => $this->shop->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $this->product->id, 'qty' => '1']], $this->owner);

        $this->phone($supervisor);
        $rows = collect($this->getJson('/api/v1/sales/delivery-orders')->assertOk()->json('orders'))->keyBy('id');
        foreach ($papers as $paper) {
            $this->assertSame(DeliveryOrderStatus::SUPERVISOR_PENDING, $paper->status, 'ছক থাকতেও সইয়ের অপেক্ষা নেই।');
            $show = $this->getJson('/api/v1/sales/delivery-orders/'.$paper->public_id)->assertOk()->json('approval_id');
            $this->assertNotNull($show, 'খোলা DO-তে সইকারীর approval_id নেই।');
            $this->assertSame($show, $rows[(string) $paper->public_id]['approval_id'], '⛔ তালিকার সারিতে অন্য কাগজের অনুমোদন, বা নেই।');
        }
        $this->assertNull($rows[(string) $draft->public_id]['approval_id'], '⛔ খসড়ায় অনুমোদন।');

        $this->phone($this->owner);
        $mine = collect($this->getJson('/api/v1/sales/delivery-orders')->json('orders'))->keyBy('id');
        $this->assertFalse($mine[(string) $papers[0]->public_id]['awaiting_me'], '⛔ লেখক নিজের কাগজে সইকারী।');
    }

    public function test_the_delivery_run_asks_the_same_number_of_times_for_one_challan_on_the_way_or_four(): void
    {
        $carrier = Supplier::query()->firstOrFail();
        $this->dispatched($carrier);
        $this->phone($this->owner);
        $few = $this->tally('/api/v1/sales/deliveries', 'rows');
        $this->dispatched($carrier);
        $this->dispatched($carrier);
        $this->dispatched($carrier);
        $this->phone($this->owner);
        $many = $this->tally('/api/v1/sales/deliveries', 'rows');

        $this->assertSame([1, 4], [$few[1], $many[1]]);
        $this->assertSame($few[0], $many[0], "⛔ ডেলিভারির তালিকা: এক চালানে {$few[0]} ডাক, চারটায় {$many[0]}।");
        // ⓘ বাহকের নাম সম্পর্ক দিয়ে আসে — ছাপা আর পাতার কথা একই থাকে
        $this->assertSame((string) $carrier->name(), DeliveryChallan::query()->latest('id')->firstOrFail()->transportLabel());
    }

    public function test_the_counter_setup_asks_the_same_number_of_times_for_one_product_with_lots_or_four(): void
    {
        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $products = Product::query()->active()->orderBy('id')->take(4)->get();
        $first = $this->lotsFor($products->first(), $warehouse);
        $this->phone($this->owner);
        // ⓘ প্রথম ডাকে সেটিং আর চাবির ক্যাশ ভরে — দুই দিকেই একবার আগে, যাতে গোনা কেবল লটের
        $this->getJson('/api/v1/sales/direct/setup?warehouse='.$warehouse->public_id)->assertOk();
        $few = $this->tally('/api/v1/sales/direct/setup?warehouse='.$warehouse->public_id, 'lots');

        foreach ($products->slice(1) as $product) {
            $this->lotsFor($product, $warehouse);
        }
        $this->phone($this->owner);
        $answer = $this->getJson('/api/v1/sales/direct/setup?warehouse='.$warehouse->public_id)->assertOk();
        $many = $this->tally('/api/v1/sales/direct/setup?warehouse='.$warehouse->public_id, 'lots');

        $this->assertSame([1, 4], [$few[1], $many[1]]);
        $this->assertSame($few[0], $many[0], "⛔ কাউন্টারের শুরু: এক পণ্যে {$few[0]} ডাক, চারটায় {$many[0]} — প্রতি পণ্যে আলাদা ডাক।");
        // ⓘ একবারে আনা public_id ঠিক পণ্যে, ঠিক লটে বসে
        $this->assertSame([(string) $first[1]->public_id, (string) $first[0]->public_id],
            array_column($answer->json('lots')[(string) $products->first()->public_id], 'id'), '⛔ লটের public_id অন্য পণ্যের, বা হারাল।');
    }

    /** @return array{0: Batch, 1: Batch} দেরিতে আর আগে মেয়াদ — FEFO-তে আগেরটা প্রথমে */
    private function lotsFor(Product $product, Warehouse $warehouse): array
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->owner);
        $product->update(['track_batch' => true]);
        $lots = [];
        foreach (['2029-01-01', '2028-01-01'] as $n => $expiry) {
            $lot = Batch::query()->create(['company_id' => $product->company_id, 'product_id' => $product->id,
                'batch_no' => 'LOT-'.$product->id.'-'.$n, 'expiry_date' => $expiry]);
            app(StockService::class)->move(product: $product, warehouse: $warehouse, sourceType: 'opening', sourceId: $lot->id,
                floor: '10', date: now()->toDateString(), documentNo: 'TEST-LOT-'.$product->id.'-'.$n, batch: $lot);
            $lots[] = $lot;
        }

        return [$lots[0], $lots[1]];
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $body */
    private function write(array $body, int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            $order = $this->postJson('/api/v1/sales/orders', $body)->assertCreated()->json('id');
            // ⓘ সইয়ের অপেক্ষার সারিও — শেষ অনুমোদন খোঁজা হয় এদেরই
            SalesOrder::query()->where('public_id', $order)->update(['status' => SalesOrderStatus::AWAITING_APPROVAL]);
            $do = $this->postJson('/api/v1/sales/delivery-orders', $body)->assertCreated()->json('id');
            DeliveryOrder::query()->where('public_id', $do)->update(['status' => DeliveryOrderStatus::SUPERVISOR_PENDING]);
            $this->postJson('/api/v1/sales/quotations', $body)->assertCreated();
        }
    }

    /** @return array<string, array{0: int, 1: int}> */
    private function asked(): array
    {
        return [
            'orders' => $this->tally('/api/v1/sales/orders', 'orders'),
            'delivery-orders' => $this->tally('/api/v1/sales/delivery-orders', 'orders'),
            'quotations' => $this->tally('/api/v1/sales/quotations', 'quotations'),
        ];
    }

    /** @return array{0: int, 1: int} ডাকের সংখ্যা, সারির সংখ্যা */
    private function tally(string $url, string $key): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $rows = count((array) $this->getJson($url)->assertOk()->json($key));
        $asked = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$asked, $rows];
    }

    private function dispatched(Supplier $carrier): void
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->owner);
        $service = app(DeliveryChallanService::class);
        $challan = $service->confirm($service->create([
            'customer_id' => $this->shop->id,
            'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id,
            'trx_date' => now()->toDateString(),
        ], [['product_id' => $this->product->id, 'delivered_qty' => '1', 'rate' => '10']]));
        DeliveryChallan::query()->whereKey($challan->id)->update(['carrier_id' => $carrier->id, 'carrier_name' => null]);
        app(DeliveryStageService::class)->move($challan->fresh(), DeliveryStage::DISPATCHED);
    }

    private function phone(User $user): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh(), [AuthController::APP]);
    }
}
