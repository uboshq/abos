<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Http\Controllers\Api\AuthController;
use App\Models\Approval;
use App\Models\Company;
use App\Models\SyncChange;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\Models\CreditDecision;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\SalesOrderService;
use App\Modules\Sales\Services\SalesPrice;
use App\Modules\Sales\Support\SalesOrderStatus as S;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⛔ সীমা পার করা বিক্রয় আদেশ জমাই হয় না — ৪২২, বাংলা কারণসহ (fe-র বিক্রয়ের ফাঁক ২৮, ২০ অক্টোবর ২০২৬)।
 *
 * ⓘ মালিকের নিয়ম: বাকির সীমা পরম। আন্তর্জাতিক রীতি SAP SD-র আদেশে বাকির যাচাই। ⚠️ আগে জমার যাচাই কেবল আদেশটাকে
 * `credit_held`-এ বসিয়ে রাখত, আর টাকা এলে সে নিজে সইয়ে চলে যেত; এখন জমাই ফেরে — আদেশ খসড়া, মাল ধরা নেই, সই চাওয়া নেই।
 * ⓘ ব্যবহৃত সীমা দেয়ালের নিজের অঙ্ক ([[CreditExposure::check()]]): খাতা, বিল না হওয়া চালান, খসড়া বিল, DO, খোলা আদেশ, চেক।
 *
 * ⓘ সৎ ছাড় দুটো, বিলের পথের হুবহু: কোম্পানির সীমার সুইচ বন্ধ, আর চলতি অস্থায়ী সীমা। দরজা তিনটা: ওয়েবের "নিশ্চিত",
 * ফোনের আদেশ-দরজা (বানাও+জমা, আর আলাদা জমা), আর ফোনের অফলাইন সিঙ্ক — তিনটাই একই কথা বলে।
 */
final class AnOrderPastTheCreditLimitIsRefusedAtSubmitTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Customer $buyer;

    private Warehouse $warehouse;

    private Product $product;

    private string $rate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        $settings = app(SettingsService::class);
        $settings->set('customer.credit_limit_enabled', true);
        $settings->set(SalesOrderService::REPLACES_DO, true);
        // ⓘ আদেশে মাল ধরা চালু — নইলে "মাল ধরা হয়নি" দাবিটা অন্ধ হত
        $settings->set('sales.reserve_on_order', true);

        $this->buyer = Customer::query()->create(['code' => 'G28-BUY', 'name_en' => 'Gap 28 Buyer', 'name_bn' => 'ফাঁক ২৮-এর ক্রেতা', 'is_active' => true]);

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->where('track_batch', false)->where('is_active', true)
            ->where('sale_price', '>', 0)->orderBy('id')->firstOrFail();
        // ⓘ ফোনের দরজা যে দাম বসায়, ওয়েবের খসড়াও সেই দামে — তিন দরজার আদেশ একই টাকার
        $this->rate = (string) app(SalesPrice::class)->for($this->buyer, $this->product)->price;

        app(StockService::class)->move(
            product: $this->product, warehouse: $this->warehouse,
            sourceType: StockService::ADJUSTMENT, sourceId: $this->product->id, floor: '50',
        );
    }

    /** ⭐ সীমার ভিতরে — জমা হয়, সই নেই বলে অনুমোদিত, মাল ধরা পড়ে; সাক্ষ্যে "হ্যাঁ" */
    public function test_an_order_within_the_limit_is_submitted_through_the_web(): void
    {
        $this->limit('100000000');
        $order = $this->draft('10');
        $before = $this->reserved();

        $this->post(route('sales.order.confirm', $order))->assertRedirect()->assertSessionHasNoErrors();

        $fresh = $order->fresh();
        $this->assertSame(S::CONFIRMED, $fresh->status, '⛔ সীমার ভিতরের আদেশ জমা হয়ে এগোয়নি।');
        $this->assertNotNull($fresh->submitted_at);
        $this->assertSame(0, bccomp(bcadd($before, '10', 4), $this->reserved(), 4), 'দাবির ভিত্তি নেই — সীমার ভিতরের আদেশও মাল ধরেনি।');
        $this->assertSame(['sales_order', 'pass', 'within_limit'], $this->decision($order, 'pass'));
    }

    /** ⛔ সীমার বাইরে — ৪২২, বাংলায় সীমা·ব্যবহৃত·এই আদেশ·কত বেশি; আদেশ খসড়া, মাল ধরা নেই, সই চাওয়া নেই; সাক্ষ্যে "না" */
    public function test_an_order_past_the_limit_is_refused_with_the_reason_and_stays_a_draft(): void
    {
        $order = $this->draft('10');
        $total = bcadd((string) $order->total, '0', 4);
        $half = bcdiv($total, '2', 4);
        $this->limit($half);

        // ⓘ ফিক্সচার: সুইচ চালু, ক্রেতার আর কোনো দায় নেই, সীমা আদেশের অর্ধেক
        $this->assertTrue(app(\App\Modules\Sales\Services\CreditExposure::class)->isOn(), 'দৃশ্যটাই ভুল — সীমার সুইচ বন্ধ।');
        $this->assertSame(0, bccomp('0', (string) app(\App\Modules\Sales\Services\CreditExposure::class)->positionOf($this->buyer->fresh())['exposure'], 4),
            'দৃশ্যটাই ভুল — ক্রেতার আগেই দায় আছে।');
        $this->assertSame(1, bccomp($total, $half, 4));
        $before = $this->reserved();

        $reason = $this->postJson(route('sales.order.confirm', $order))->assertStatus(422)->json('errors.customer_id.0');

        $this->assertSame($this->expected($total, $half, '0'), $reason, '⛔ কারণটা সীমা, ব্যবহৃত, এই আদেশ আর কত বেশি বলে না।');
        $this->assertStringContainsString($this->buyer->fresh()->nameWithPoint(), (string) $reason);

        $fresh = $order->fresh();
        $this->assertSame(S::DRAFT, $fresh->status, '⛔ সীমা পার করা আদেশ জমা হয়ে গেল।');
        $this->assertNull($fresh->submitted_at);
        $this->assertNull($fresh->credit_held_at, '⛔ আদেশ আগের মতো "সীমায় আটকে"-তে বসল — ফেরানো হয়নি।');
        $this->assertSame(0, bccomp($before, $this->reserved(), 4), '⛔ ফেরানো আদেশ মাল ধরে রাখল।');
        $this->assertNull($this->approvalOf($order), '⛔ ফেরানো আদেশের সই চাওয়া হলো।');
        $this->assertSame(['sales_order', 'stop', 'over_limit'], $this->decision($order, 'stop'));

        // ⓘ সাধারণ ফর্মেও একই — পাতায় ফিরে গ্রাহকের ঘরে কারণ
        $this->post(route('sales.order.confirm', $order))->assertRedirect()->assertSessionHasErrors(['customer_id' => $reason]);
        $this->assertSame(S::DRAFT, $order->fresh()->status);
    }

    /** ⭐ একই আদেশ, একই মানুষ: সুইচ চালুতে ফেরে, কোম্পানি সুইচ বন্ধ করলে জমা হয় */
    public function test_the_company_switch_off_lets_the_same_order_through(): void
    {
        $this->limit('0');
        $order = $this->draft('10');

        $this->postJson(route('sales.order.confirm', $order))->assertStatus(422);
        $this->assertSame(S::DRAFT, $order->fresh()->status, 'দৃশ্যটাই ভুল — সুইচ চালুতেও আদেশ ফেরেনি।');

        app(SettingsService::class)->set('customer.credit_limit_enabled', false);

        $this->post(route('sales.order.confirm', $order))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(S::CONFIRMED, $order->fresh()->status, '⛔ সুইচ বন্ধেও সীমা আদেশ আটকাল।');
    }

    /** ⭐ চলতি অস্থায়ী সীমা ঢাকলে জমা হয়; মেয়াদ পেরোনো অস্থায়ী সীমা ঢাকে না */
    public function test_a_live_temporary_limit_covers_the_order_and_an_ended_one_does_not(): void
    {
        $this->limit('0');
        $order = $this->draft('10');

        Customer::query()->whereKey($this->buyer->id)->update(['temp_credit_limit' => '100000000', 'temp_credit_limit_until' => now()->subDay()->toDateString()]);
        $this->postJson(route('sales.order.confirm', $order))->assertStatus(422);
        $this->assertSame(S::DRAFT, $order->fresh()->status, '⛔ শেষ হয়ে যাওয়া অস্থায়ী সীমা আদেশ ছেড়ে দিল।');

        Customer::query()->whereKey($this->buyer->id)->update(['temp_credit_limit_until' => now()->addDays(3)->toDateString()]);
        $this->post(route('sales.order.confirm', $order))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(S::CONFIRMED, $order->fresh()->status, '⛔ চলতি অস্থায়ী সীমা গোনা হয়নি।');
    }

    /** ⛔ ফোনের আদেশ-দরজা — বানাও+জমা আর আলাদা জমা দুটোই একই ৪২২, ওয়েবের একই কথায়; খসড়া পড়ে থাকে না */
    public function test_the_phone_order_door_gives_the_same_422_and_the_same_reason(): void
    {
        $web = $this->draft('10');
        $total = bcadd((string) $web->total, '0', 4);
        $half = bcdiv($total, '2', 4);
        $this->limit($half);
        $webReason = $this->postJson(route('sales.order.confirm', $web))->assertStatus(422)->json('errors.customer_id.0');
        $this->assertSame($this->expected($total, $half, '0'), $webReason, 'দৃশ্যটাই ভুল — ওয়েবও ফেরেনি।');

        $this->phone($this->seller());
        $before = SalesOrder::query()->count();
        $body = ['customer' => (string) $this->buyer->public_id, 'lines' => [['product' => (string) $this->product->public_id, 'qty' => '10']]];

        $phoneReason = $this->postJson('/api/v1/sales/orders', $body + ['submit' => true])->assertStatus(422)->json('errors.customer_id.0');
        $this->assertSame($webReason, $phoneReason, '⛔ ফোনের বানাও+জমা অন্য কথা বলে, বা সীমা দেখে না।');
        $this->assertSame($before, SalesOrder::query()->count(), '⛔ ফেরানো জমা খসড়া রেখে গেল।');

        $id = (string) $this->postJson('/api/v1/sales/orders', $body)->assertCreated()->json('id');
        $this->assertSame($webReason, $this->postJson("/api/v1/sales/orders/{$id}/submit")->assertStatus(422)->json('errors.customer_id.0'),
            '⛔ ফোনের আলাদা জমা-দরজা সীমা দেখে না।');
        $this->assertSame(S::DRAFT, SalesOrder::query()->where('public_id', $id)->firstOrFail()->status);
    }

    /** ⛔ ফোনের অফলাইন সিঙ্ক — সারিটা "ফেরানো", একই কারণসহ; নীরবে নেওয়া নয়, আদেশ বসে না। সীমা কুলোলে একই সারি বসে */
    public function test_the_offline_sync_answer_carries_the_refusal(): void
    {
        $web = $this->draft('10');
        $total = bcadd((string) $web->total, '0', 4);
        $half = bcdiv($total, '2', 4);
        $this->limit($half);
        $webReason = $this->postJson(route('sales.order.confirm', $web))->assertStatus(422)->json('errors.customer_id.0');

        $this->phone($this->seller());
        $before = SalesOrder::query()->count();

        $out = $this->push('g28-1')->assertOk()->json('outcomes.0');
        $this->assertSame(SyncChange::REJECTED, $out['status'] ?? null, '⛔ সিঙ্ক সীমা পার করা আদেশ নীরবে নিল: '.json_encode($out, JSON_UNESCAPED_UNICODE));
        $this->assertSame($webReason, $out['message'] ?? null, '⛔ সিঙ্কের উত্তরে ফেরানোর কারণ নেই।');
        $this->assertSame($before, SalesOrder::query()->count(), '⛔ ফেরানো সিঙ্ক আদেশ রেখে গেল।');

        $this->limit('100000000');
        $out = $this->push('g28-2')->assertOk()->json('outcomes.0');
        $this->assertSame(SyncChange::APPLIED, $out['status'] ?? null, 'দৃশ্যটাই ভুল — সীমা কুলোলেও সিঙ্ক ফেরায়: '.json_encode($out, JSON_UNESCAPED_UNICODE));
        $this->assertNotSame(S::DRAFT, SalesOrder::query()->where('public_id', $out['entityId'])->firstOrFail()->status);
    }

    /**
     * ⭐ পুরনো পথ (সুইচ `sales.orders_replace_do` বন্ধ, ডিফল্ট) — সীমার ভিতরে "নিশ্চিত" চলে, মাল ধরা পড়ে, সাক্ষ্যে "হ্যাঁ"
     * (fe-র উত্তর, ফাঁক ২৮: সীমা পুরনো পথেও পরম)।
     */
    public function test_on_the_old_path_an_order_within_the_limit_is_confirmed(): void
    {
        $this->oldPath();
        $this->limit('100000000');
        $order = $this->draft('10');
        $before = $this->reserved();

        $this->post(route('sales.order.confirm', $order))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(S::CONFIRMED, $order->fresh()->status, '⛔ পুরনো পথে সীমার ভিতরের আদেশ নিশ্চিত হয়নি।');
        $this->assertSame(S::HOLD_LEDGER, $order->fresh()->hold_mode, 'দৃশ্যটাই ভুল — আদেশ পুরনো পথে যায়নি।');
        $this->assertSame(0, bccomp(bcadd($before, '10', 4), $this->reserved(), 4), 'দাবির ভিত্তি নেই — পুরনো পথ মাল ধরেনি।');
        $this->assertSame(['sales_order', 'pass', 'within_limit'], $this->decision($order, 'pass'));
    }

    /** ⛔ পুরনো পথ — সীমার বাইরে "নিশ্চিত" ৪২২, জমার একই কথায়; খসড়াই থাকে, মাল ধরা নেই, সই চাওয়া নেই; সাক্ষ্যে "না" */
    public function test_on_the_old_path_an_order_past_the_limit_is_refused_with_the_same_reason(): void
    {
        $this->oldPath();
        $order = $this->draft('10');
        $total = bcadd((string) $order->total, '0', 4);
        $half = bcdiv($total, '2', 4);
        $this->limit($half);
        $before = $this->reserved();

        $reason = $this->postJson(route('sales.order.confirm', $order))->assertStatus(422)->json('errors.customer_id.0');

        $this->assertSame($this->expected($total, $half, '0'), $reason, '⛔ পুরনো পথ সীমা দেখে না, বা অন্য কথা বলে।');
        $this->assertSame(S::DRAFT, $order->fresh()->status, '⛔ পুরনো পথে সীমা পার করা আদেশ নিশ্চিত হলো।');
        $this->assertSame(0, bccomp($before, $this->reserved(), 4), '⛔ ফেরানো আদেশ মাল ধরে রাখল।');
        $this->assertNull($this->approvalOf($order), '⛔ ফেরানো আদেশের সই চাওয়া হলো — সীমা সইয়ের আগে।');
        $this->assertSame(['sales_order', 'stop', 'over_limit'], $this->decision($order, 'stop'));

        $this->post(route('sales.order.confirm', $order))->assertRedirect()->assertSessionHasErrors(['customer_id' => $reason]);
        $this->assertSame(S::DRAFT, $order->fresh()->status);
    }

    /** ⭐ পুরনো পথ — একই আদেশ: সুইচ বন্ধে চলে; চলতি অস্থায়ী সীমায় চলে */
    public function test_on_the_old_path_the_switch_off_and_a_live_temporary_limit_let_it_through(): void
    {
        $this->oldPath();
        $this->limit('0');
        $first = $this->draft('5');
        $second = $this->draft('5');

        $this->postJson(route('sales.order.confirm', $first))->assertStatus(422);
        $this->assertSame(S::DRAFT, $first->fresh()->status, 'দৃশ্যটাই ভুল — পুরনো পথে সীমা ০-তেও আদেশ ফেরেনি।');

        app(SettingsService::class)->set('customer.credit_limit_enabled', false);
        $this->post(route('sales.order.confirm', $first))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(S::CONFIRMED, $first->fresh()->status, '⛔ সুইচ বন্ধেও পুরনো পথে সীমা আটকাল।');

        app(SettingsService::class)->set('customer.credit_limit_enabled', true);
        $this->postJson(route('sales.order.confirm', $second))->assertStatus(422);
        Customer::query()->whereKey($this->buyer->id)->update(['temp_credit_limit' => '100000000', 'temp_credit_limit_until' => now()->addDays(3)->toDateString()]);
        $this->post(route('sales.order.confirm', $second))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(S::CONFIRMED, $second->fresh()->status, '⛔ পুরনো পথে চলতি অস্থায়ী সীমা গোনা হয়নি।');
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    private function expected(string $order, string $limit, string $used): string
    {
        return (string) __('sales::order_status.over_credit_limit', [
            'customer' => $this->buyer->fresh()->nameWithPoint(),
            'limit' => Money::format($limit),
            'used' => Money::format($used),
            'order' => Money::format($order),
            'short' => Money::format(bcsub(bcadd($used, $order, 4), $limit, 4)),
        ]);
    }

    /** ⓘ পুরনো পথ — DO বিক্রয় আদেশে মেশে না (ডিফল্ট); মাল ধরা আদেশেই (setUp-এর `sales.reserve_on_order`) */
    private function oldPath(): void
    {
        app(SettingsService::class)->set(SalesOrderService::REPLACES_DO, false);
        app()->forgetInstance(SalesOrderService::class);
    }

    private function limit(string $limit): void
    {
        Customer::query()->whereKey($this->buyer->id)->update(['credit_limit' => $limit]);
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
            'rate' => $this->rate,
        ]]);
    }

    private function reserved(): string
    {
        return (string) app(StockService::class)->statesFor($this->product, $this->warehouse)['reserved'];
    }

    /** @return list<string> */
    private function decision(SalesOrder $order, string $result): array
    {
        $row = CreditDecision::query()->where('paper_type', 'sal_orders')->where('paper_id', $order->id)->where('result', $result)->sole();

        return [(string) $row->stage, (string) $row->result, (string) $row->reason];
    }

    private function approvalOf(SalesOrder $order): ?Approval
    {
        return Approval::query()->where('approvable_type', SalesOrder::class)->where('approvable_id', $order->id)->first();
    }

    private function seller(): User
    {
        $user = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);
        CompanyContext::forCompany($this->company->id, fn () => $user->givePermissionTo(array_map(
            fn (string $k) => Permission::findOrCreate($k, 'web'), ['sales.order.create', 'sales.order.view'])));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    private function phone(User $user): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user, [AuthController::APP, AuthController::ACCESS]);
    }

    private function push(string $changeId): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/sync/sales/push?deviceId=g28-phone', [[
            'changeId' => $changeId,
            'entityType' => 'SalesOrder',
            'entityId' => (string) Str::uuid(),
            'operation' => 'CREATE',
            'payloadJson' => json_encode([
                'customerId' => (string) $this->buyer->public_id,
                'trxDate' => now()->toDateString(),
                'lines' => [['productId' => (string) $this->product->public_id, 'qty' => '10']],
            ], JSON_THROW_ON_ERROR),
            'clientVersion' => 1,
        ]]);
    }
}
