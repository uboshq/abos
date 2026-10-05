<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
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
use App\Modules\Sales\Services\DeliveryStage;
use App\Modules\Sales\Services\DeliveryStageService;
use App\Modules\Sales\Services\DirectSaleService;
use App\Modules\Sales\Services\OrderProgress;
use App\Modules\Sales\Services\SalesOrderService;
use App\Modules\Sales\Support\SalesOrderStatus as S;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * পরের কিস্তি আটকে থাকত প্রথম কিস্তির গেট পাসের জন্য — bb-র প্রশ্ন, abos-63-এর রায়, ৪ অক্টোবর ২০২৬।
 *
 * ⓘ সুইচ `sales.invoice_at_goods_issue` চালু থাকলে আদেশের প্রথম কিস্তির বিল খসড়াই থাকে যতক্ষণ না গেট পাসে মাল বেরোয়
 * ([[GoodsIssue]])। ⛔ "এক উৎসে একটাই খোলা খসড়া" ([[DirectSaleService::guardSource()]]) সেই বিলটাকেও খসড়া গুনত — তাই গাড়ি
 * না ছাড়া পর্যন্ত একই আদেশের বাকি মাল কাউন্টারে খোলাই যেত না, অথচ আন্তর্জাতিক মানে (SAP-এর একাধিক Outbound Delivery)
 * এক আদেশের কয়েকটা চালান একসাথে চলে।
 * ⭐ এখন গেটের অপেক্ষার বিল সেই নিয়মে গোনা হয় না; হাতে রাখা খসড়া (আসল খসড়া) আগের মতোই থামায়।
 */
final class TheNextLoadWaitedForTheFirstGatePassTest extends TestCase
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

        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();
        app(SettingsService::class)->set('customer.credit_limit_enabled', false);

        $this->customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->where('track_batch', false)->where('is_active', true)
            ->where('sale_price', '>', 0)->orderBy('id')->firstOrFail();

        app(StockService::class)->move(
            product: $this->product, warehouse: $this->warehouse,
            sourceType: StockService::ADJUSTMENT, sourceId: $this->product->id, floor: '50',
        );
    }

    /**
     * ⭐ ১০টার আদেশ, সুইচ চালু: ৪টা গাড়িতে (বিল গেটের অপেক্ষায়) → বাকি ৬টাও এখনই বিক্রি হয় → প্রথম গাড়ির গেট পাসে
     * তার বিল পাকা → আদেশ পুরো চালান।
     */
    public function test_the_next_load_of_an_order_sells_while_the_first_waits_for_the_gate(): void
    {
        app(SettingsService::class)->set('sales.invoice_at_goods_issue', true);
        $order = $this->reservedOrder('10');
        $line = $order->lines->first();

        $first = $this->sell($order, '4', $line->id);
        $firstChallan = $this->challanOf($first);
        $this->assertSame(DocumentStatus::DRAFT, $first->status, 'প্রস্তুতিটাই ভুল — প্রথম বিল গেটের অপেক্ষায় নেই।');
        $this->assertTrue((bool) $firstChallan->issue_at_gate, 'প্রস্তুতিটাই ভুল — চালান গেটে মাল বের করবে না।');

        $second = $this->sell($order, '6', $line->id);
        $this->assertSame('so', $second->counter_source, '⛔ দ্বিতীয় কিস্তি আদেশ মনে রাখেনি।');
        $this->assertSame($firstChallan->fresh()->document_no.'-2', $this->challanOf($second)->document_no,
            '⛔ একই আদেশের পরের চালান আগেরটার নম্বরে -2 পায়নি।');
        $this->assertSame(S::FULL, app(OrderProgress::class)->of($order->fresh(['lines']))['delivery'],
            '⛔ দুই কিস্তির চালানে আদেশ পুরো হয়নি।');

        app(DeliveryStageService::class)->move($firstChallan->fresh(), DeliveryStage::DISPATCHED);
        $this->assertSame(DocumentStatus::CONFIRMED, $first->fresh()->status, '⛔ প্রথম গাড়ির গেট পাসে তার বিল পাকা হয়নি।');
    }

    /**
     * ⛔ নিয়মটা আলগা হয়নি: একই মানুষ, একই আদেশ — হাতে রাখা খসড়া থাকলে পরের বিক্রি আগের মতোই থামে, সুইচ চালু থাকলেও।
     */
    public function test_a_kept_draft_of_the_order_still_blocks_the_next_sale(): void
    {
        app(SettingsService::class)->set('sales.invoice_at_goods_issue', true);
        $order = $this->reservedOrder('10');
        $line = $order->lines->first();

        $kept = $this->sell($order, '4', $line->id, ['save_as_draft' => '1']);
        $this->assertSame(DocumentStatus::DRAFT, $kept->status);

        /*
         * ⓘ বিরতিতে রাখা — তাহলে ক্রেতার "একটাই সক্রিয় খসড়া" ([[DirectSaleService::assertNoOtherOpenDraft()]]) আর থামায় না,
         * আর থামানোর কাজটা একা উৎসের নিয়মের ([[DirectSaleService::guardSource()]])। ⛔ নাহলে উৎসের নিয়ম আলগা হলেও
         * ক্রেতার নিয়ম ঢেকে দিত, আর এই দাবি কিছুই দেখত না।
         */
        app(DirectSaleService::class)->pauseDraft($kept);

        try {
            $this->sell($order, '6', $line->id);
        } catch (ValidationException $e) {
            $this->assertStringContainsString((string) $kept->document_no, (string) ($e->errors()['source'][0] ?? ''),
                '⛔ থামল, কিন্তু উৎসের নিয়মে রাখা খসড়ার নাম ধরে নয়।');

            return;
        }

        $this->fail('⛔ আদেশের রাখা খসড়া থাকতেই আরেকটা বিল হয়ে গেল — "এক উৎসে একটাই খসড়া" ভেঙেছে।');
    }

    /**
     * ⛔ ছাড় কেবল **গেটের অপেক্ষার** বিলের — চালান গেটে বেরোনোর নয় (বিল অন্য কারণে খসড়া, যেমন সইয়ের অপেক্ষা), বা গেটের
     * চালান সম্পাদনার মাঝপথে খসড়ায় ফিরেছে ([[DeliveryChallanService::takeBackForEdit()]]) — দুই ক্ষেত্রেই বিলটা খোলা, পরের বিক্রি থামে।
     *
     * ⓘ অবস্থা দুটো সরাসরি বসানো: প্রথমটায় পৌঁছায় সইয়ের ছক, দ্বিতীয়টায় সম্পাদকের লেনদেনের মাঝখান — দাবি নিয়মের, পথের নয়।
     *
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function stillOpenBills(): array
    {
        return [
            'চালান গেটে বেরোনোর নয়' => [['issue_at_gate' => false]],
            'গেটের চালান খসড়ায় ফিরেছে' => [['status' => DocumentStatus::DRAFT]],
        ];
    }

    /** @param  array<string, mixed>  $state */
    #[\PHPUnit\Framework\Attributes\DataProvider('stillOpenBills')]
    public function test_only_a_bill_waiting_for_the_gate_is_let_through(array $state): void
    {
        app(SettingsService::class)->set('sales.invoice_at_goods_issue', true);
        $order = $this->reservedOrder('10');
        $line = $order->lines->first();

        $first = $this->sell($order, '4', $line->id);
        $this->challanOf($first)->forceFill($state)->save();
        // ⓘ বিরতিতে — ক্রেতার "একটাই সক্রিয় খসড়া" যেন উৎসের নিয়মকে ঢেকে না দেয় (দ্বিতীয় দাবির একই কারণ)
        $first->forceFill(['draft_paused_at' => now()])->save();

        try {
            $this->sell($order, '6', $line->id);
        } catch (ValidationException $e) {
            $this->assertStringContainsString((string) $first->document_no, (string) ($e->errors()['source'][0] ?? ''),
                '⛔ থামল, কিন্তু উৎসের নিয়মে খোলা বিলের নাম ধরে নয়।');

            return;
        }

        $this->fail('⛔ গেটের অপেক্ষার নয় এমন খোলা বিল থাকতেই একই আদেশের আরেকটা বিল হয়ে গেল।');
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    private function reservedOrder(string $qty): SalesOrder
    {
        app(SettingsService::class)->set(SalesOrderService::REPLACES_DO, true);
        app()->forgetInstance(SalesOrderService::class);
        $orders = app(SalesOrderService::class);

        $order = $orders->submit($orders->create(
            ['customer_id' => $this->customer->id, 'warehouse_id' => $this->warehouse->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $this->product->id, 'ordered_qty' => $qty, 'rate' => (string) $this->product->sale_price]],
        )->fresh(['lines']));
        $this->assertSame(S::APPROVED, $order->status, 'প্রস্তুতিটাই ভুল — আদেশ অনুমোদিত হয়নি।');

        return $orders->markConfirmed($order)->fresh(['lines']);
    }

    /** @param  array<string, string>  $extra */
    private function sell(SalesOrder $order, string $qty, int $lineId, array $extra = []): SalesInvoice
    {
        $result = app(DirectSaleService::class)->complete(
            ['customer_id' => $this->customer->id, 'warehouse_id' => $this->warehouse->id, 'deposit' => '0',
                'delivery_mode' => 'send_later', 'vehicle_owner' => 'customer', 'ship_to' => 'কাপ্তান বাজার',
                'ship_date' => now()->addDay()->toDateString(), 'source' => 'so', 'source_id' => $order->id, ...$extra],
            [[
                'product_id' => $this->product->id,
                'qty' => $qty,
                'free_qty' => '0',
                'rate' => (string) $this->product->sale_price,
                'discount_percent' => '0',
                'source_line_id' => $lineId,
            ]],
        );

        return $result['invoice']->fresh();
    }

    private function challanOf(SalesInvoice $invoice): DeliveryChallan
    {
        $id = $invoice->load('lines.challanLine')->lines->first()?->challanLine?->delivery_challan_id;
        $this->assertNotNull($id, '⛔ বিলের সারি কোনো চালানে বাঁধা নয়।');

        return DeliveryChallan::query()->findOrFail($id);
    }
}
