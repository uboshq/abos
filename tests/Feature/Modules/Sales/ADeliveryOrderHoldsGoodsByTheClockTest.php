<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Events\VoucherPosted;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\Events\DeliveryOrderCancelled;
use App\Modules\Sales\Events\DeliveryOrderSupervisorApproved;
use App\Modules\Sales\Models\DeliveryOrder;
use App\Modules\Sales\Models\DeliveryOrderStockHold;
use App\Modules\Sales\Services\DeliveryOrderStock;
use App\Modules\Sales\Support\DeliveryOrderStatus;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ডেলিভারি অর্ডার — হিসাবের অনুমোদন আর মালের ঘড়ি। বিক্রয়ের কাজের ধারা, ধাপ গ + ঘ (৩ অক্টোবর ২০২৬;
 * [[DeliveryOrderAccounts]], [[DeliveryOrderStock]])।
 *
 * মালিকের নিয়ম: *"24h er jonno korakori atkabe, baki 2din dekhabe but bikroy cholbe"*; হিসাবে অনুমোদিত হলে বিল পর্যন্ত কড়া;
 * মজুদ কম হলে *"za ache ta atkabe"*। ⓘ নতুন গুদাম, নতুন গ্রাহক — ডেমোর কিছু নেই, তাই অঙ্কগুলো হুবহু।
 * পণ্য: ২০টা গুদামে; DO: ১০ × ১০০ = ১,০০০।
 */
final class ADeliveryOrderHoldsGoodsByTheClockTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Customer $customer;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $this->customer = Customer::query()->create(['code' => 'DO-CLOCK', 'name_en' => 'DO Clock', 'name_bn' => 'DO Clock', 'is_active' => true]);

        $home = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->warehouse = tap($home->replicate(['public_id']), fn (Warehouse $w) => $w->forceFill([
            'code' => 'DO-WH', 'name_en' => 'DO WH', 'name_bn' => 'DO WH', 'is_default' => false,
        ])->save());

        $this->product = Product::query()->where('track_batch', false)->where('is_active', true)->orderBy('id')->firstOrFail();

        app(StockService::class)->move(
            product: $this->product, warehouse: $this->warehouse,
            sourceType: StockService::ADJUSTMENT, sourceId: $this->product->id, floor: '20',
        );
    }

    public function test_money_that_fits_approves_it_and_holds_the_goods_until_billed_with_no_clock(): void
    {
        $this->limit('10000');
        $order = $this->approvedBySupervisor('10');

        $this->assertSame(DeliveryOrderStatus::ACCOUNTS_APPROVED, $order->fresh()->status, '⛔ সীমায় কুলোয়, অথচ হিসাবে অনুমোদিত হয়নি।');
        $this->assertAvailable('10', '⛔ অনুমোদিত DO-র ১০টা আটকানো হয়নি।');

        $this->travel(80)->hours();
        app(DeliveryOrderStock::class)->expireOld();

        $this->assertAvailable('10', '⛔ হিসাবে অনুমোদিত DO-র মাল ৮০ ঘণ্টায় ছেড়ে দিয়েছে — বিল পর্যন্ত কড়া থাকার কথা।');
    }

    public function test_money_short_holds_hard_for_24_hours_shows_until_72_then_lets_go(): void
    {
        $this->limit('0');
        $order = $this->approvedBySupervisor('10');

        $this->assertSame(DeliveryOrderStatus::ACCOUNTS_HELD, $order->fresh()->status, '⛔ সীমা ০, টাকা নেই — অথচ আটকায়নি।');
        $this->assertSame(0, bccomp((string) $order->fresh()->accounts_short, '1000', 4), '⛔ কত কম, তা ভুল।');

        // ০–২৪ ঘণ্টা: কড়া — অন্য কেউ বেচতে পারে না
        $this->travel(23)->hours();
        app(DeliveryOrderStock::class)->expireOld();
        $this->assertAvailable('10', '⛔ ২৩ ঘণ্টায় কড়া আটকানো উঠে গেছে।');

        // ২৪–৭২ ঘণ্টা: দেখায়, বিক্রি চলে
        $this->travel(2)->hours();
        app(DeliveryOrderStock::class)->expireOld();
        $this->assertAvailable('0', '⛔ ২৫ ঘণ্টায়ও বিক্রি থামিয়ে রেখেছে।');
        $this->assertSame(1, $this->openHolds($order, DeliveryOrderStockHold::SOFT), '⛔ ২৫ ঘণ্টায় সংরক্ষণটা আর দেখায় না।');

        // ৭২ ঘণ্টা: ছাড়
        $this->travel(48)->hours();
        app(DeliveryOrderStock::class)->expireOld();
        $this->assertSame(0, $this->openHolds($order), '⛔ ৭৩ ঘণ্টায়ও মাল ছাড়েনি।');
        $this->assertSame('expired', DeliveryOrderStockHold::query()->where('delivery_order_id', $order->id)->value('release_reason'));
        $this->assertAllTwentyFree();
    }

    public function test_money_coming_in_rechecks_and_holds_again_after_the_goods_were_let_go(): void
    {
        $this->limit('0');
        $order = $this->approvedBySupervisor('10');

        $this->travel(73)->hours();
        app(DeliveryOrderStock::class)->expireOld();
        $this->assertSame(0, $this->openHolds($order), 'প্রস্তুতিটাই ভুল — ৭৩ ঘণ্টায় ছাড় হয়নি।');

        // টাকা এল — অগ্রিম ১,০০০, রসিদ ভাউচারের ঘটনা দিয়ে
        $this->advance('1000');
        event(new VoucherPosted(publicId: 'test', payload: ['type' => 'receipt', 'document_no' => 'T', 'party_type' => 'customer', 'party_id' => $this->customer->id]));

        $this->assertSame(DeliveryOrderStatus::ACCOUNTS_APPROVED, $order->fresh()->status, '⛔ টাকা এল, অথচ DO আবার যাচাই হয়নি।');
        $this->assertAvailable('10', '⛔ টাকা আসার পরে মাল আবার আটকানো হয়নি।');
    }

    public function test_short_stock_holds_what_there_is_and_says_order_less(): void
    {
        $this->limit('100000');
        $order = $this->approvedBySupervisor('26');

        $this->assertSame(DeliveryOrderStatus::ACCOUNTS_APPROVED, $order->fresh()->status);
        $this->assertAvailable('20', '⛔ যতটা আছে (২০) ততটা আটকানোর কথা।');

        $warnings = collect($order->fresh()->accounts_warnings ?? []);
        $short = $warnings->firstWhere('kind', 'stock_short');
        $this->assertNotNull($short, '⛔ মজুদ কম, অথচ "অর্ডার কমান" সতর্কবার্তা নেই।');
        $this->assertSame(0, bccomp((string) $short['available'], '20', 4));
    }

    public function test_cancelling_lets_the_goods_go(): void
    {
        $this->limit('10000');
        $order = $this->approvedBySupervisor('10');
        $this->assertAvailable('10', 'প্রস্তুতিটাই ভুল।');

        event(DeliveryOrderCancelled::from($order->fresh(), 'cancelled'));

        $this->assertSame(0, $this->openHolds($order), '⛔ বাতিল DO-র মাল আটকেই আছে।');
        $this->assertAllTwentyFree();
    }

    // ── সহায়ক ───────────────────────────────────────────────────────────

    private function approvedBySupervisor(string $qty): DeliveryOrder
    {
        $total = bcmul($qty, '100', 4);

        $order = DeliveryOrder::query()->create([
            'company_id' => $this->company->id,
            'branch_id' => $this->company->defaultBranch()?->id,
            'document_no' => 'DO-T-'.random_int(1000, 99999),
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
            'status' => DeliveryOrderStatus::SUPERVISOR_APPROVED,
            'subtotal' => $total,
            'total' => $total,
        ]);

        $order->lines()->create(['product_id' => $this->product->id, 'qty' => $qty, 'rate' => '100', 'line_total' => $total]);

        event(DeliveryOrderSupervisorApproved::from($order->fresh()));

        return $order;
    }

    private function assertAvailable(string $held, string $why): void
    {
        $available = app(StockService::class)->availableQty($this->product, $this->warehouse);

        $this->assertSame(0, bccomp($available, bcsub('20', $held, 4), 4), $why." (বেচার মতো {$available})");
    }

    private function assertAllTwentyFree(): void
    {
        $this->assertAvailable('0', '⛔ ছাড়ের পরে সব ২০টা বেচার মতো হওয়ার কথা — খাতায় "সংরক্ষিত" রয়ে গেছে।');
    }

    private function openHolds(DeliveryOrder $order, ?string $kind = null): int
    {
        return DeliveryOrderStockHold::query()->open()->where('delivery_order_id', $order->id)
            ->when($kind !== null, fn ($q) => $q->where('kind', $kind))->count();
    }

    private function limit(string $amount): void
    {
        $this->customer->forceFill(['credit_limit' => $amount])->save();
    }

    private function advance(string $amount): void
    {
        $money = DB::table('accounts')->where('company_id', $this->company->id)->where('money_kind', 'cash')->where('is_group', false)->orderBy('id')->value('id');

        app(PostingEngine::class)->post(
            sourceType: 'test:do-clock', sourceId: random_int(1, 9_999_999), trxDate: now()->toDateString(),
            lines: [
                ['account_id' => $money, 'debit' => $amount],
                ['account_id' => StandardChart::find(StandardChart::RECEIVABLE)->id, 'credit' => $amount, 'party_type' => 'customer', 'party_id' => $this->customer->id],
            ],
            branchId: $this->company->defaultBranch()?->id,
        );
    }
}
