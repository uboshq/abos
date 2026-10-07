<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Note;
use App\Modules\Accounts\Services\NoteService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Promotion\Models\Promotion;
use App\Modules\Promotion\Models\PromotionApplication;
use App\Modules\Promotion\Models\PromotionBenefit;
use App\Modules\Promotion\Models\PromotionCondition;
use App\Modules\Promotion\Models\PromotionCoupon;
use App\Modules\Promotion\Support\BenefitKind;
use App\Modules\Promotion\Support\ConditionKind;
use App\Modules\Promotion\Support\PromotionCombines;
use App\Modules\Promotion\Support\PromotionStatus;
use App\Modules\Promotion\Support\PromotionType;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\DirectSaleService;
use App\Modules\Sales\Services\SalesInvoiceCancellationService;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Sales\Services\SalesOrderService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * বাতিল বিল তার অফার রেখে দিত — পুরো ERP অডিট, প্রমোশন ⛔১, ৬ অক্টোবর ২০২৬।
 *
 * ⛔ চালানের সাধারণ বাতিলে অফার, উপহার, কুপন আর পয়েন্ট ফিরত; কিন্তু বাতিল-ইনভয়েসের পথে (চালান আর বিল দুটোই উল্টায়) আর
 * আদেশ বাতিলে কিছুই ফিরত না — অফারের বাজেট খরচ হয়েই থাকত, কুপনের ব্যবহার গোনা থাকত। ⭐ এখন ঐ পথগুলোও একই উল্টানো ডাকে।
 */
final class ACancelledBillKeptItsOffersTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Product $biscuit;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->owner->givePermissionTo([Permission::findOrCreate('promotion.coupon', 'web'), Permission::findOrCreate('promotion.apply', 'web')]);
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();
        $this->biscuit = Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        Customer::query()->update(['credit_limit' => '1000000000']);
    }

    public function test_a_cancellation_invoice_gives_the_coupon_back(): void
    {
        $this->couponOffer();
        $bill = app(DirectSaleService::class)->complete(
            ['customer_id' => Customer::query()->where('name_en', 'Rahim Traders')->value('id'), 'warehouse_id' => $this->warehouse->id, 'own_transport' => '1'],
            [['product_id' => $this->biscuit->id, 'qty' => '2', 'rate' => '10', 'free_qty' => '0']],
        )['invoice']->fresh();
        $this->coupon('BACK-1');
        $this->redeem('BACK-1', 'sales_invoice', $bill->id, (int) $bill->lines()->value('id'))->assertOk();
        $this->assertSame(1, $this->used('BACK-1'));

        // ⓘ কুপনের নোট আগে বাতিল — নোট থাকলে বাতিল-ইনভয়েস থামে
        app(NoteService::class)->cancel(Note::query()->where('against_no', $bill->document_no)->firstOrFail(), 'বিল ভুল');
        app(SalesInvoiceCancellationService::class)->request($bill->fresh(), $this->owner, 'ভুল গ্রাহক');

        $this->assertSame(0, $this->used('BACK-1'), '⛔ বাতিল বিলের কুপনের ব্যবহার ফেরেনি।');
        $this->assertSame(0, PromotionApplication::query()->where('source_type', 'sales_invoice')->where('source_id', $bill->id)->whereNull('reversed_at')->count(),
            '⛔ বাতিল বিলের প্রয়োগ খোলা রইল — বাজেট খরচ হয়েই থাকল।');
    }

    public function test_a_cancellation_invoice_takes_back_the_challans_offer(): void
    {
        $offer = $this->tenPercentOffer();
        $challan = app(DeliveryChallanService::class)->create([
            'customer_id' => Customer::query()->value('id'), 'warehouse_id' => $this->warehouse->id, 'trx_date' => now()->toDateString(), 'own_transport' => true,
        ], [['product_id' => $this->biscuit->id, 'delivered_qty' => '10', 'rate' => '10']]);
        $this->post(route('sales.challan.offer.store', $challan), ['line_id' => $challan->lines()->value('id'), 'offer_id' => $offer->id])->assertSessionHasNoErrors();
        $challan = app(DeliveryChallanService::class)->confirm($challan->fresh());
        $bill = $this->billOf($challan);

        app(SalesInvoiceCancellationService::class)->request($bill, $this->owner, 'ভুল দাম');

        $this->assertSame(0, PromotionApplication::query()->where('source_type', DeliveryChallan::drillSourceType())->where('source_id', $challan->id)
            ->whereNull('reversed_at')->count(), '⛔ বাতিল-ইনভয়েসে চালানের অফার খোলা রইল।');
    }

    public function test_a_cancelled_order_gives_the_coupon_back(): void
    {
        $this->couponOffer();
        $orders = app(SalesOrderService::class);
        $order = $orders->confirm($orders->create(
            ['customer_id' => Customer::query()->where('name_en', 'Rahim Traders')->value('id'), 'warehouse_id' => $this->warehouse->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $this->biscuit->id, 'ordered_qty' => '2', 'rate' => '10']],
        ));
        $this->coupon('ORDER-1');
        $this->redeem('ORDER-1', 'sales_order', $order->id, (int) $order->lines()->value('id'))->assertOk();

        $orders->cancel($order->fresh(), 'গ্রাহক নেবেন না');

        $this->assertSame(0, $this->used('ORDER-1'), '⛔ বাতিল আদেশের কুপনের ব্যবহার ফেরেনি।');
    }

    private function billOf(DeliveryChallan $challan): SalesInvoice
    {
        $line = $challan->lines()->firstOrFail();
        $before = SalesInvoice::query()->max('id') ?? 0;
        $this->post(route('sales.invoice.store'), [
            'delivery_challan_id' => $challan->id, 'customer_id' => $challan->customer_id, 'warehouse_id' => $challan->warehouse_id, 'trx_date' => now()->toDateString(),
            'lines' => [['product_id' => $line->product_id, 'delivery_challan_line_id' => $line->id, 'qty' => '10', 'rate' => '10', 'discount' => '0']],
        ])->assertSessionHasNoErrors();

        $draft = SalesInvoice::query()->where('id', '>', $before)->latest('id')->firstOrFail();

        // ⓘ অফারের ছাড়ে মালিকের সই (মালিক, ৫ অক্টোবর ২০২৬) — সই দিয়ে আবার নিশ্চিত
        try {
            return app(SalesInvoiceService::class)->confirm($draft);
        } catch (\App\Core\Engines\Approval\HeldForApproval) {
            $pending = \App\Models\Approval::query()->where('approvable_type', SalesInvoice::class)->where('approvable_id', $draft->id)
                ->where('status', \App\Models\Approval::PENDING)->firstOrFail();
            app(\App\Core\Engines\Approval\ApprovalEngine::class)->approve($pending, $this->owner, 'ঠিক আছে');

            $fresh = $draft->fresh();

            return $fresh->status === \App\Core\Support\DocumentStatus::CONFIRMED ? $fresh : app(SalesInvoiceService::class)->confirm($fresh);
        }
    }

    private function redeem(string $code, string $type, int $id, int $lineId)
    {
        return $this->actingAs($this->owner)->postJson(route('promotion.coupon.redeem'), [
            'code' => $code, 'source_type' => $type, 'source_id' => $id, 'source_line_id' => $lineId,
            'product_id' => $this->biscuit->id, 'qty' => '2', 'value' => '20',
        ]);
    }

    private function used(string $code): int
    {
        return (int) PromotionCoupon::query()->where('code', $code)->value('used_count');
    }

    private function coupon(string $code): void
    {
        (new PromotionCoupon)->forceFill([
            'promotion_id' => Promotion::query()->where('code', 'PROM-CXL-CPN')->value('id'),
            'code' => $code, 'max_uses' => 5, 'used_count' => 0, 'is_active' => true, 'issued_by' => $this->owner->id,
        ])->save();
    }

    private function couponOffer(): void
    {
        $offer = new Promotion(['name_en' => 'Cancel coupon', 'starts_on' => Carbon::today()->subDay(), 'ends_on' => Carbon::today()->addWeek()]);
        $offer->code = 'PROM-CXL-CPN';
        $offer->type = PromotionType::COUPON;
        $offer->status = PromotionStatus::ACTIVE;
        $offer->combines = PromotionCombines::BEST;
        $offer->created_by = $this->owner->id;
        $offer->save();
        PromotionBenefit::query()->create(['promotion_id' => $offer->id, 'kind' => BenefitKind::AMOUNT, 'amount' => '5']);
    }

    private function tenPercentOffer(): Promotion
    {
        $offer = new Promotion(['name_en' => 'Challan offer', 'name_bn' => 'চালানের অফার', 'starts_on' => Carbon::today()->subDay(),
            'ends_on' => Carbon::today()->addWeek(), 'priority' => 0]);
        $offer->code = 'PROM-CXL-CH';
        $offer->type = PromotionType::QUANTITY_SLAB;
        $offer->status = PromotionStatus::ACTIVE;
        $offer->combines = PromotionCombines::BEST;
        $offer->created_by = $this->owner->id;
        $offer->save();
        $condition = PromotionCondition::query()->create(['promotion_id' => $offer->id, 'kind' => ConditionKind::QUANTITY,
            'value_from' => '1', 'value_to' => null, 'step_order' => 0]);
        PromotionBenefit::query()->create(['promotion_id' => $offer->id, 'promotion_condition_id' => $condition->id,
            'kind' => BenefitKind::PERCENT, 'amount' => '10']);

        return $offer;
    }
}
