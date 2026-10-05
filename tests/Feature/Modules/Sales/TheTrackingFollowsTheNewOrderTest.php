<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\Notification;
use App\Models\User;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\DirectSaleService;
use App\Modules\Sales\Services\SalesOrderService;
use App\Modules\Sales\Services\SaleTracking;
use App\Modules\Sales\Services\TrackingNotices;
use App\Modules\Sales\Support\SalesOrderStatus as S;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ ট্র্যাকিং নতুন ধারার আদেশ চেনে — DO+SO মেশানো, নকশার ধাপ ১১ (৫ অক্টোবর ২০২৬; [[SaleTracking]], [[TrackingNotices]])।
 *
 * ⭐ দাবি:
 *   জমা দেওয়া আদেশ "অনুমোদনের অপেক্ষায়", আর এখনকার স্তরের অনুমোদনকারী একটা খবর পান — অচেনা কর্মী পান না;
 *   সীমায় আটকানো আদেশ নিজের ধাপে ("বাকির সীমায় আটকে", কালো), মালিক একবারই খবর পান — আবার যাচাইয়ে নয়;
 *   সুইচ বন্ধে খসড়া আদেশ আজকের মতো "অর্ডার এসেছে", চালুতে "খসড়া";
 *   আংশিক চালানের আদেশ চালানের ধাপ দেখায়, বাকিটা "পরে যাবে" পতাকায় — সবটা গেলে পতাকা নামে;
 *   চালানের দাগে তার আদেশের ঘটনাও আসে।
 */
final class TheTrackingFollowsTheNewOrderTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $clerk;

    private Customer $customer;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->clerk = $this->staff(['sales.order.view', 'sales.order.create']);
        $this->actingAs($this->clerk);

        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();
        app(SettingsService::class)->set('customer.credit_limit_enabled', true);
        app(SettingsService::class)->set(SalesOrderService::REPLACES_DO, true);

        $this->customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $this->customer->forceFill(['credit_limit' => '100000000'])->save();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->where('track_batch', false)->where('is_active', true)
            ->where('sale_price', '>', 0)->orderBy('id')->firstOrFail();

        app(StockService::class)->move(
            product: $this->product, warehouse: $this->warehouse,
            sourceType: StockService::ADJUSTMENT, sourceId: $this->product->id, floor: '50',
        );
    }

    public function test_a_submitted_order_waits_for_approval_and_only_its_approver_is_told(): void
    {
        $supervisor = $this->staff(['sales.order.view', 'approval.decide']);
        $stranger = $this->staff(['sales.order.view', 'approval.decide']);
        $this->flow($supervisor);

        $order = $this->submitted('4');
        $this->assertSame(S::AWAITING_APPROVAL, $order->status, 'প্রস্তুতিটাই ভুল — সইয়ের অপেক্ষা নেই।');

        $row = $this->rowOf($order);
        $this->assertSame('approval', $row['step']);
        $this->assertSame('pending', $row['category']);

        $this->assertSame(1, $this->told($supervisor, TrackingNotices::ORDER_AWAITS), '⛔ অনুমোদনকারী জানলেন না যে আদেশ তাঁর অপেক্ষায়।');
        $this->assertSame(0, $this->told($stranger, TrackingNotices::ORDER_AWAITS), '⛔ যিনি সই দিতে পারেন না তিনিও খবর পেলেন।');

        $story = app(SaleTracking::class)->story($order->fresh());
        $this->assertSame('approval', $story['step']);
        $this->assertContains(__('sales::tracking.submitted'), array_column($story['events'], 'text'));
        $this->assertContains(__('sales::tracking.sent_for_signature'), array_column($story['events'], 'text'));
    }

    public function test_an_order_held_at_the_limit_has_its_own_step_and_the_owner_is_told_once(): void
    {
        $this->customer->forceFill(['credit_limit' => '1'])->save();

        $order = $this->submitted('4');
        $this->assertSame(S::CREDIT_HELD, $order->status, 'প্রস্তুতিটাই ভুল — সীমায় আটকায়নি।');

        $row = $this->rowOf($order);
        $this->assertSame('credit_hold', $row['step']);
        $this->assertSame('hold', $row['category'], 'আটকে থাকা কালো রঙে — মালিকের নয় রঙের "hold"');
        $this->assertContains(__('sales::tracking.credit_held'), array_column(app(SaleTracking::class)->story($order->fresh())['events'], 'text'));

        $this->assertSame(1, $this->told($this->owner, TrackingNotices::ORDER_HELD), '⛔ মালিক জানলেন না যে আদেশ সীমায় আটকে।');
        app(SalesOrderService::class)->recheckCredit($order->fresh());
        $this->assertSame(1, $this->told($this->owner, TrackingNotices::ORDER_HELD), '⛔ প্রতিটা আবার-যাচাইয়ে নতুন খবর — এক রাতে শত খবর যেত।');
    }

    public function test_a_draft_reads_as_today_with_the_switch_off_and_as_a_draft_with_it_on(): void
    {
        $draft = app(SalesOrderService::class)->create($this->header(), [$this->row('2')]);

        $this->assertSame('draft', $this->rowOf($draft)['step']);
        app(SettingsService::class)->set(SalesOrderService::REPLACES_DO, false);
        $this->assertSame('ordered', $this->rowOf($draft)['step'], '⛔ সুইচ বন্ধেও খসড়া আদেশের ধাপ বদলে গেল — আজকের ট্র্যাকিং বদলানোর কথা নয়।');
    }

    public function test_a_partly_sent_order_shows_its_challan_with_the_rest_to_follow(): void
    {
        $this->actingAs($this->owner);
        $this->customer->forceFill(['credit_limit' => '100000000'])->save();
        $orders = app(SalesOrderService::class);
        $order = $orders->markConfirmed($orders->submit($orders->create($this->header(), [$this->row('10')])->fresh(['lines'])))->fresh(['lines']);
        $this->assertSame(S::CONFIRMED, $order->status, 'প্রস্তুতিটাই ভুল — আদেশ সংরক্ষিত হয়নি।');
        $line = $order->lines->first();

        $challan = $this->challanOf($this->sell($order, '4', $line->id));
        $row = collect(app(SaleTracking::class)->list(null, null, (int) $this->customer->id)['rows'])
            ->firstWhere('id', (string) $challan->public_id);
        $this->assertNotNull($row);
        $this->assertTrue($row['back_order'], '⛔ ৪ গেল ১০-এর মধ্যে, অথচ "বাকি পরে যাবে" নেই।');

        $story = app(SaleTracking::class)->story($challan->fresh());
        $this->assertTrue($story['back_order']);
        $this->assertContains(__('sales::tracking.ordered', ['no' => $order->document_no]), array_column($story['events'], 'text'),
            '⛔ চালানের দাগে তার আদেশের ঘটনা নেই।');

        $rest = $this->challanOf($this->sell($order->fresh(['lines']), '6', $line->id));
        $after = collect(app(SaleTracking::class)->list(null, null, (int) $this->customer->id)['rows'])
            ->whereIn('id', [(string) $challan->public_id, (string) $rest->public_id]);
        $this->assertFalse($after->contains('back_order', true), '⛔ সবটা গেল, তবু "বাকি পরে যাবে" রয়ে গেল।');
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    private function submitted(string $qty): SalesOrder
    {
        $orders = app(SalesOrderService::class);

        return $orders->submit($orders->create($this->header(), [$this->row($qty)])->fresh(['lines']))->fresh();
    }

    /** @return array<string, mixed> */
    private function rowOf(SalesOrder $order): array
    {
        $row = collect(app(SaleTracking::class)->list(null, null, (int) $this->customer->id)['rows'])
            ->firstWhere('id', (string) $order->public_id);
        $this->assertNotNull($row, 'আদেশটা ট্র্যাকিংয়ের তালিকায়ই নেই।');

        return $row;
    }

    private function told(User $user, string $type): int
    {
        return Notification::query()->where('user_id', $user->id)->where('type', $type)->count();
    }

    /** @return array<string, mixed> */
    private function header(): array
    {
        return ['customer_id' => $this->customer->id, 'warehouse_id' => $this->warehouse->id, 'trx_date' => now()->toDateString()];
    }

    /** @return array<string, mixed> */
    private function row(string $qty): array
    {
        return ['product_id' => $this->product->id, 'ordered_qty' => $qty, 'rate' => (string) $this->product->sale_price];
    }

    private function sell(SalesOrder $order, string $qty, int $lineId): SalesInvoice
    {
        $result = app(DirectSaleService::class)->complete(
            ['customer_id' => $this->customer->id, 'warehouse_id' => $this->warehouse->id, 'deposit' => '0',
                'own_transport' => '1', 'source' => 'so', 'source_id' => $order->id],
            [[
                'product_id' => $this->product->id,
                'qty' => $qty,
                'free_qty' => '0',
                'rate' => (string) $this->product->sale_price,
                'discount_percent' => '0',
                'source_line_id' => $lineId,
            ]],
        );

        $invoice = $result['invoice']->fresh();
        $this->assertSame(DocumentStatus::CONFIRMED, $invoice->status, 'প্রস্তুতিটাই ভুল — বিক্রি পাকা হয়নি।');

        return $invoice;
    }

    private function challanOf(SalesInvoice $invoice): DeliveryChallan
    {
        $id = $invoice->load('lines.challanLine')->lines->first()?->challanLine?->delivery_challan_id;
        $this->assertNotNull($id, 'প্রস্তুতিটাই ভুল — বিলের সারি কোনো চালানে বাঁধা নয়।');

        return DeliveryChallan::query()->findOrFail($id);
    }

    private function flow(User $supervisor): void
    {
        $flow = ApprovalFlow::query()->create([
            'company_id' => $this->company->id, 'code' => 'ZQ-SO', 'module' => 'sales',
            'action' => SalesOrderService::APPROVAL_ACTION, 'document_type' => 'SalesOrder', 'is_active' => true,
        ]);
        ApprovalFlowStep::query()->create([
            'approval_flow_id' => $flow->id, 'level' => 1, 'step_name' => 'এরিয়া ম্যানেজার',
            'approver_type' => 'user', 'approver_id' => $supervisor->id,
        ]);
        app()->forgetInstance(ApprovalEngine::class);
    }

    /** @param  list<string>  $keys */
    private function staff(array $keys): User
    {
        $user = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);
        foreach ($keys as $key) {
            CompanyContext::forCompany($this->company->id,
                fn () => $user->givePermissionTo(Permission::findOrCreate($key, 'web')));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }
}
