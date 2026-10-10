<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Promotion;

use App\Core\Contracts\SalesOffers;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Note;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Promotion\Models\Promotion;
use App\Modules\Promotion\Models\PromotionBenefit;
use App\Modules\Promotion\Models\PromotionCoupon;
use App\Modules\Promotion\Models\PromotionCouponRedemption;
use App\Modules\Promotion\Support\BenefitKind;
use App\Modules\Promotion\Support\PromotionCombines;
use App\Modules\Promotion\Support\PromotionStatus;
use App\Modules\Promotion\Support\PromotionType;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Sales\Services\SalesOrderService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * ⛔ আদেশে কাটা টাকার কুপন বিলের খাতায় পৌঁছায় — পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬ (প্রমোশন ১৮; [[CouponDesk::carryToBill()]])।
 *
 * ⓘ বিলে কুপন কাটলে ক্রেডিট নোট বসত, আদেশে কাটলে কিছুই না — আর আদেশ বিল হওয়ার পরেও না। কুপন খরচ, অফারের বাজেট খরচ, অথচ গ্রাহকের
 * পাওনা পুরোটাই। এখন আদেশের বিল পাকা হলে কুপনের ছাড় সেই বিলের ক্রেডিট নোট — একবারই।
 */
final class TheOrderCouponReachesTheBillTest extends TestCase
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
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->owner->givePermissionTo([Permission::findOrCreate('promotion.coupon', 'web'), Permission::findOrCreate('promotion.apply', 'web')]);
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();
        app(SettingsService::class)->set('sales.screen_orders', true);

        $this->customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        Customer::query()->whereKey($this->customer->id)->update(['credit_limit' => '100000000']);
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->where('track_batch', false)->where('is_active', true)->where('sale_price', '>', 0)->orderBy('id')->firstOrFail();
        app(StockService::class)->move(product: $this->product, warehouse: $this->warehouse, sourceType: StockService::ADJUSTMENT, sourceId: $this->product->id, floor: '50');

        $offer = new Promotion(['name_en' => 'Order coupon', 'starts_on' => Carbon::today()->subDay(), 'ends_on' => Carbon::today()->addWeek()]);
        $offer->code = 'PROM-ORDER-1';
        $offer->type = PromotionType::COUPON;
        $offer->status = PromotionStatus::ACTIVE;
        $offer->combines = PromotionCombines::BEST;
        $offer->created_by = $this->owner->id;
        $offer->save();
        PromotionBenefit::query()->create(['promotion_id' => $offer->id, 'kind' => BenefitKind::AMOUNT, 'amount' => '25']);

        (new PromotionCoupon)->forceFill(['promotion_id' => $offer->id, 'code' => 'ORDER-25', 'max_uses' => 5, 'used_count' => 0,
            'is_active' => true, 'issued_by' => $this->owner->id])->save();
    }

    public function test_a_money_coupon_cut_on_an_order_becomes_the_bills_credit_note_once(): void
    {
        $order = $this->confirmedOrder('4');
        $line = $order->lines->first();

        $this->postJson(route('promotion.coupon.redeem'), [
            'code' => 'ORDER-25', 'source_type' => 'sales_order', 'source_id' => $order->id, 'source_line_id' => $line->id,
            'product_id' => $this->product->id, 'qty' => '4', 'value' => bcmul('4', (string) $this->product->sale_price, 4),
        ])->assertOk();
        $this->assertSame(0, Note::query()->count(), 'আদেশে কাটলে তখনই খাতায় কিছু নয় — আয় তখনো খাতায় নেই');

        $bill = $this->billFor($order, (int) $line->id, '4');

        $note = Note::query()->where('against_no', $bill->document_no)->first();
        $this->assertNotNull($note, '⛔ আদেশের কুপন বিল পাকা হওয়ার পরেও খাতায় পৌঁছাল না');
        $this->assertSame(DocumentStatus::CONFIRMED, $note->status);
        $this->assertSame(Note::CREDIT, $note->direction);
        $this->assertSame((int) $this->customer->id, (int) $note->party_id);
        $this->assertSame(0, bccomp((string) $note->total, '25', 4), '⛔ নোট কুপনের অঙ্কে নয়');
        $this->assertSame(0, bccomp('25', (string) PromotionCouponRedemption::query()->sole()->carried_amount, 4));

        // ⓘ একই আদেশের পরের কোনো বিল আর এই ছাড় পায় না — একবারই
        app(SalesOffers::class)->carryOrderCoupons([(int) $order->id], (int) $bill->id, '1000');
        $this->assertSame(1, Note::query()->count(), '⛔ একই কুপনের ছাড় দুইবার খাতায়');
    }

    private function confirmedOrder(string $qty): SalesOrder
    {
        $orders = app(SalesOrderService::class);

        return $orders->confirm($orders->create(['customer_id' => $this->customer->id, 'warehouse_id' => $this->warehouse->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $this->product->id, 'ordered_qty' => $qty, 'rate' => (string) $this->product->sale_price]])->fresh(['lines']))->fresh(['lines']);
    }

    private function billFor(SalesOrder $order, int $lineId, string $qty): SalesInvoice
    {
        $challans = app(DeliveryChallanService::class);
        $challan = $challans->confirm($challans->create(['customer_id' => $this->customer->id, 'warehouse_id' => $this->warehouse->id,
            'sales_order_id' => $order->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $this->product->id, 'sales_order_line_id' => $lineId, 'delivered_qty' => $qty, 'rate' => (string) $this->product->sale_price]])->fresh(['lines']));

        $invoices = app(SalesInvoiceService::class);
        $draft = $invoices->create(['customer_id' => $this->customer->id, 'warehouse_id' => $this->warehouse->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $this->product->id, 'qty' => $qty, 'rate' => (string) $this->product->sale_price,
                'delivery_challan_line_id' => $challan->fresh(['lines'])->lines->first()->id]]);

        return $invoices->confirm($draft->fresh(['lines']))->fresh();
    }
}
