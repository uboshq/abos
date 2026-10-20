<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Dashboard\ActivityRegistry;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\Location;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\GatePass;
use App\Modules\Sales\Models\PrintJob;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\CommissionClaimService;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\DeliveryOrderService;
use App\Modules\Sales\Services\DeliveryStage;
use App\Modules\Sales\Services\DeliveryStageService;
use App\Modules\Sales\Services\DirectSaleService;
use App\Modules\Sales\Services\PrintQueue;
use App\Modules\Sales\Services\SalesQuotationService;
use App\Modules\Sales\Support\DeliveryOrderStatus;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⭐ নম্বরের পাশে গ্রাহক আর পয়েন্ট — মালিক, ১৯ অক্টোবর ২০২৬: *"অনেক জায়গায় তালিকায় শুধু ভাউচার নম্বর দেওয়া। সেখানে নম্বরের
 * পাশে গ্রাহকের নাম আর পয়েন্টের নাম দিলে সহজে বোঝা যায়।"* (আর ২৮ সেপ্টেম্বরের স্থায়ী নিয়ম: গ্রাহকের পরে সবসময় পয়েন্ট)।
 *
 * ⭐ দাবি: উদ্ধৃতি, ডেলিভারি, ডেলিভারির মাপকাঠি (দেরির তালিকা), ডিপোর যাচাই, গেট পাস, কমিশন আর ট্র্যাকিং — প্রতিটা তালিকার
 * সারিতে কাগজের নম্বর আর একই সারিতে দোকানের পয়েন্ট (বিল/আদেশ/চালানের মতো আলাদা কলামে); ছাপার সারিতে নম্বরের পাশে
 * "নাম · পয়েন্ট" (আগে কোনো পক্ষই ছিল না); হোমের "সদ্য যা হলো" আর বিক্রয় ড্যাশবোর্ডের "সবচেয়ে বড় বকেয়া"-তেও পয়েন্ট।
 * ⓘ দোকানটা রুটে বসে — পয়েন্ট তার মা জায়গার নাম ([[Customer::pointName()]]), রুটের নাম নয়।
 * ⚠️ পয়েন্ট আনে গোটা পাতার এক প্রশ্ন — সারি বাড়লে গ্রাহক/জায়গার প্রশ্ন বাড়ে না।
 */
final class TheSalesListsNameThePointTest extends TestCase
{
    use RefreshDatabase;

    private const POINT = 'কারওয়ান বাজার';

    private Company $company;

    private User $owner;

    private Customer $shop;

    private Product $product;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        app(StandardChart::class)->install();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        $settings = app(SettingsService::class);
        $settings->set('customer.credit_limit_enabled', false);
        $settings->set('sales.commission_max_amount', 100000);
        $settings->set('sales.commission_max_percent', 60);

        $point = $this->place('PT-NP', 'Kawran Bazar', self::POINT, Location::POINT);
        $route = $this->place('RT-NP', 'Route 7', 'রুট ৭', Location::ROUTE, $point);
        $this->shop = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $this->shop->forceFill(['location_id' => $route->id])->save();
        $this->product = Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
    }

    public function test_the_quotation_list_names_the_point_in_the_row_and_does_not_ask_per_row(): void
    {
        $first = $this->quotation();
        $html = $this->get(route('sales.quotation.index'))->assertOk()->getContent();
        $this->assertPointInRow($html, (string) $first->document_no, 'উদ্ধৃতির তালিকা');
        $this->assertStringNotContainsString('রুট ৭', $this->rowWith($html, (string) $first->document_no), '⛔ উদ্ধৃতির সারিতে পয়েন্টের জায়গায় রুটের নাম বসল।');

        $few = $this->partyQueries(fn () => $this->get(route('sales.quotation.index'))->assertOk());
        for ($i = 0; $i < 19; $i++) {
            $this->quotation();
        }
        $many = $this->partyQueries(fn () => $this->get(route('sales.quotation.index'))->assertOk());

        $this->assertLessThanOrEqual($few, $many, "⛔ উদ্ধৃতির তালিকা: সারি বাড়তেই গ্রাহক/জায়গার প্রশ্ন বাড়ল ({$few} → {$many}) — সারি ধরে প্রশ্ন।");
        $this->assertLessThanOrEqual(3, $many, "⛔ উদ্ধৃতির তালিকা: বিশ সারিতে গ্রাহক/জায়গার {$many}টা প্রশ্ন।");
    }

    public function test_the_delivery_the_late_list_the_gate_pass_and_tracking_name_the_point(): void
    {
        $challan = $this->dispatchedChallan();
        $pass = GatePass::query()->where('delivery_challan_id', $challan->id)->firstOrFail();
        $today = now()->toDateString();

        $this->assertPointInRow($this->get(route('sales.delivery.index'))->assertOk()->getContent(), (string) $challan->document_no, 'ডেলিভারির তালিকা');
        $this->assertPointInRow(
            $this->get(route('sales.delivery_performance.index', ['from' => $today, 'to' => $today]))->assertOk()->getContent(),
            (string) $challan->document_no, 'ডেলিভারির দেরির তালিকা');
        $this->assertPointInRow($this->get(route('sales.gate_pass.index'))->assertOk()->getContent(), (string) $pass->document_no, 'গেট পাসের তালিকা');
        $this->assertPointInRow(
            $this->get(route('sales.tracking.index', ['from' => $today, 'to' => $today]))->assertOk()->getContent(),
            (string) ($challan->sale_no ?: $challan->document_no), 'ডেলিভারি ট্র্যাকিং');
    }

    public function test_the_depot_check_and_the_commission_name_the_point(): void
    {
        $orders = app(DeliveryOrderService::class);
        $order = $orders->create(['customer_id' => $this->shop->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $this->product->id, 'qty' => '2']], $this->owner);
        // ⓘ হিসাবের সই পেরোনো DO — ডিপোর যাচাইয়ের তালিকায় যেটা বসে (ADeliveryOrderIsItsOwnPaperTest-এর একই ধাপ)
        $order->forceFill(['status' => DeliveryOrderStatus::ACCOUNTS_APPROVED])->save();
        $this->assertPointInRow($this->get(route('sales.direct.depot_check'))->assertOk()->getContent(), (string) $order->document_no, 'ডিপোর যাচাই');

        $claim = app(CommissionClaimService::class)->create([
            'customer_id' => $this->shop->id, 'supplier_id' => Supplier::query()->firstOrFail()->id,
            'sales_invoice_id' => $this->bill()->id, 'rate_percent' => '5', 'trx_date' => now()->toDateString(),
        ]);
        $this->assertPointInRow($this->get(route('sales.commission.index'))->assertOk()->getContent(), (string) $claim->document_no, 'কমিশনের তালিকা');
    }

    public function test_the_print_queue_names_the_customer_and_point_beside_the_number_in_one_batch(): void
    {
        $queue = app(PrintQueue::class);
        $bill = $this->bill();
        $queue->queue(PrintJob::INVOICE, (int) $bill->id, 'a4', (string) $bill->document_no);
        // ⓘ গেট পাসের নিজের গ্রাহক নেই — তার চালানের গ্রাহক
        $pass = GatePass::query()->where('delivery_challan_id', $this->dispatchedChallan()->id)->firstOrFail();
        $queue->queue(PrintJob::GATE_PASS, (int) $pass->id, 'a4', (string) $pass->document_no);

        $html = $this->get(route('sales.print_queue.index'))->assertOk()->getContent();
        foreach ([(string) $bill->document_no => 'ছাপার সারি (বিল)', (string) $pass->document_no => 'ছাপার সারি (গেট পাস)'] as $no => $where) {
            $row = $this->rowWith($html, $no);
            $this->assertStringContainsString(e($this->shop->name().' · '.self::POINT), $row, "⛔ {$where}: নম্বরের পাশে গ্রাহক আর পয়েন্ট নেই।");
            $this->assertStringContainsString('data-party', $row, "⛔ {$where}: পক্ষ ভাগের ঘরে বসেনি (<x-ui.party-cell>)।");
        }

        $few = $this->partyQueries(fn () => $this->get(route('sales.print_queue.index'))->assertOk());
        for ($i = 0; $i < 20; $i++) {
            $more = $this->plainBill('INV-NP-'.$i);
            $queue->queue(PrintJob::INVOICE, (int) $more->id, 'a4', (string) $more->document_no);
        }
        $many = $this->partyQueries(fn () => $this->get(route('sales.print_queue.index'))->assertOk());

        $this->assertLessThanOrEqual($few, $many, "⛔ ছাপার সারি: সারি বাড়তেই গ্রাহক/জায়গার প্রশ্ন বাড়ল ({$few} → {$many})।");
        $this->assertLessThanOrEqual(3, $many, "⛔ ছাপার সারি: বাইশ সারিতে গ্রাহক/জায়গার {$many}টা প্রশ্ন।");
    }

    public function test_the_home_feed_and_the_sales_dashboard_name_the_point(): void
    {
        $bill = $this->bill();
        Collection::query()->create([
            'branch_id' => $this->company->defaultBranch()?->id, 'document_no' => 'COL-NP-1', 'customer_id' => $this->shop->id,
            'account_id' => Account::query()->postable()->firstOrFail()->id,
            'trx_date' => now()->toDateString(), 'amount' => '100', 'status' => DocumentStatus::CONFIRMED,
        ]);

        $feed = collect(app(ActivityRegistry::class)->forUser($this->owner, 50));
        foreach ([(string) $bill->document_no => 'বিল', 'COL-NP-1' => 'আদায়'] as $no => $what) {
            $row = $feed->first(fn ($h) => str_starts_with($h->title, $no.' '));
            $this->assertNotNull($row, "দৃশ্যটাই ভুল — হোমের তালিকায় {$what} {$no} নেই।");
            // ⓘ ১৯ অক্টোবর ২০২৬ থেকে শাখাও — "নাম · পয়েন্ট · শাখা" (মালিকের স্থায়ী নিয়ম; [[Customer::nameWithPoint()]])
            $this->assertSame($this->shop->fresh()->load(Customer::PLACE)->nameWithPoint(), $row->subtitle, "⛔ হোমের \"সদ্য যা হলো\"-তে {$what}ের নিচে পয়েন্ট আর শাখা নেই।");
            $this->assertStringStartsWith($this->shop->name().' · '.self::POINT, $row->subtitle, "⛔ হোমের \"সদ্য যা হলো\"-তে {$what}ের নিচে পয়েন্ট নেই।");
        }

        $this->assertPointInRow($this->get(route('module.dashboard', 'sales'))->assertOk()->getContent(), (string) $bill->document_no, 'বিক্রয় ড্যাশবোর্ডের বড় বকেয়া');
    }

    /**
     * ⭐ বিল, আদেশ, চালান, DO, আদায় আর ফেরতের তালিকা — রুটে বসা দোকানে মা পয়েন্টের নাম, রুটের নাম নয় (আগে ওয়েবের এই তালিকাগুলো
     * `location->name()` পড়ত, তাই রুটের দোকানে "Route 7"/"রুট ৭" বসত; ফোন পড়ত [[Customer::pointName()]] — দুই জায়গায় দুই কথা)।
     * ফোনের সারিতেও একই পয়েন্ট।
     */
    public function test_the_bill_order_challan_do_collection_and_return_lists_name_the_point_not_the_route_on_web_and_phone(): void
    {
        $bill = $this->bill();
        $challans = app(DeliveryChallanService::class);
        $challan = $challans->confirm($challans->create([
            'customer_id' => $this->shop->id, 'warehouse_id' => $this->warehouse->id, 'trx_date' => now()->toDateString(),
        ], [['product_id' => $this->product->id, 'delivered_qty' => '1', 'rate' => '10']]));
        Collection::query()->create([
            'branch_id' => $this->company->defaultBranch()?->id, 'document_no' => 'COL-NP-2', 'customer_id' => $this->shop->id,
            'account_id' => Account::query()->postable()->firstOrFail()->id,
            'trx_date' => now()->toDateString(), 'amount' => '100', 'status' => DocumentStatus::CONFIRMED,
        ]);
        $return = app(\App\Modules\Sales\Services\SalesReturnService::class)->create([
            'customer_id' => $this->shop->id, 'warehouse_id' => $this->warehouse->id, 'sales_invoice_id' => $bill->id,
            'reason_code_id' => \App\Modules\MasterData\Models\ReasonCode::query()
                ->inContext(\App\Modules\MasterData\Models\ReasonCode::SALES_RETURN)->value('id'),
            'trx_date' => now()->toDateString(),
        ], [['product_id' => $this->product->id, 'sales_invoice_line_id' => $bill->lines()->value('id'), 'qty' => '1']]);

        // ⓘ আদেশ আর DO ফোনের দরজায় লেখা — ফোনের নিজের পথ (EveryPhoneDoorNamesTheShopsPointTest-এর একই)
        $this->phone();
        $body = ['customer' => (string) $this->shop->public_id, 'lines' => [['product' => (string) $this->product->public_id, 'qty' => '2']]];
        $orderId = $this->postJson('/api/v1/sales/orders', $body)->assertCreated()->json('id');
        $doId = $this->postJson('/api/v1/sales/delivery-orders', $body)->assertCreated()->json('id');
        $order = \App\Modules\Sales\Models\SalesOrder::query()->where('public_id', $orderId)->firstOrFail();
        $do = \App\Modules\Sales\Models\DeliveryOrder::query()->where('public_id', $doId)->firstOrFail();

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->owner);
        foreach ([
            'sales.invoice.index' => [(string) $bill->document_no, 'বিলের তালিকা'],
            'sales.order.index' => [(string) $order->document_no, 'আদেশের তালিকা'],
            'sales.challan.index' => [(string) $challan->document_no, 'চালানের তালিকা'],
            'sales.delivery_order.index' => [(string) $do->document_no, 'DO-র তালিকা'],
            'sales.collection.index' => ['COL-NP-2', 'আদায়ের তালিকা'],
            'sales.return.index' => [(string) $return->document_no, 'ফেরতের তালিকা'],
        ] as $list => [$no, $where]) {
            $html = $this->get(route($list))->assertOk()->getContent();
            $this->assertPointInRow($html, $no, $where);
            foreach ($this->rowsWith($html, $no) as $row) {
                $this->assertStringNotContainsString('রুট ৭', $row, "⛔ {$where}: পয়েন্টের জায়গায় রুটের নাম।");
                $this->assertStringNotContainsString('Route 7', $row, "⛔ {$where}: পয়েন্টের জায়গায় রুটের নাম।");
            }
        }

        $this->phone();
        $today = now()->toDateString();
        $range = '?from='.$today.'&to='.$today;
        $phoneRows = [
            'বিল' => [collect($this->getJson('/api/v1/sales/invoices'.$range)->assertOk()->json('rows'))->firstWhere('no', $bill->document_no), 'point'],
            'চালান' => [collect($this->getJson('/api/v1/sales/challans'.$range)->assertOk()->json('rows'))->firstWhere('no', $challan->document_no), 'point'],
            'আদায়' => [collect($this->getJson('/api/v1/sales/collections'.$range)->assertOk()->json('rows'))->firstWhere('no', 'COL-NP-2'), 'customer_point'],
            'ফেরতের বিল' => [collect($this->getJson('/api/v1/sales/returns/setup')->assertOk()->json('invoices'))->firstWhere('no', $bill->document_no), 'customer_point'],
            'আদেশ' => [collect($this->getJson('/api/v1/sales/orders')->assertOk()->json('orders'))->firstWhere('id', $orderId), 'customer.point'],
            'DO' => [collect($this->getJson('/api/v1/sales/delivery-orders')->assertOk()->json('orders'))->firstWhere('id', $doId), 'customer.point'],
        ];
        foreach ($phoneRows as $what => [$row, $key]) {
            $this->assertNotNull($row, "দৃশ্যটাই ভুল — ফোনের {$what}-এর তালিকায় সারিটা নেই।");
            $this->assertSame(self::POINT, data_get($row, $key), "⛔ ফোনের {$what}-এর সারিতে মা পয়েন্ট নেই (রুট, বা কিছুই না)।");
        }
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    private function phone(): void
    {
        $this->app['auth']->forgetGuards();
        \Laravel\Sanctum\Sanctum::actingAs($this->owner->fresh(), [\App\Http\Controllers\Api\AuthController::APP]);
    }

    private function assertPointInRow(string $html, string $no, string $where): void
    {
        $rows = $this->rowsWith($html, $no);
        $this->assertNotSame([], array_filter($rows, fn (string $row) => str_contains($row, self::POINT)),
            "⛔ {$where}: {$no}-এর সারিতে দোকানের পয়েন্ট নেই।");
    }

    /** কাগজের নম্বরের সারিটা — `<tr>` থেকে `</tr>`; পাতায় একটাই তালিকা যেখানে */
    private function rowWith(string $html, string $no): string
    {
        return $this->rowsWith($html, $no)[0];
    }

    /** @return list<string> নম্বরটা যে যে সারিতে (ড্যাশবোর্ডে একই বিল একাধিক তালিকায় থাকতে পারে) */
    private function rowsWith(string $html, string $no): array
    {
        preg_match_all('~<tr\b(?:(?!</tr>).)*?'.preg_quote(e($no), '~').'(?:(?!</tr>).)*</tr>~su', $html, $match);
        $this->assertNotSame([], $match[0], "দৃশ্যটাই ভুল — তালিকায় {$no}-এর সারি নেই।");

        return $match[0];
    }

    /** পাতা আঁকতে গ্রাহক আর জায়গার টেবিলে কয়টা প্রশ্ন গেল */
    private function partyQueries(callable $open): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $open();
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        return count(array_filter($log, fn (array $q) => preg_match('~\bfrom\s+[`"]?(customers|mdm_locations)[`"]?(\s|$)~i', $q['query']) === 1));
    }

    private function quotation(): \App\Modules\Sales\Models\SalesQuotation
    {
        return app(SalesQuotationService::class)->create([
            'customer_id' => $this->shop->id, 'trx_date' => now()->toDateString(),
            'valid_until' => now()->addDays(7)->toDateString(), 'header_discount' => '0',
        ], [['product_id' => $this->product->id, 'qty' => '1', 'rate' => '10']]);
    }

    /** আজকের চালান, প্রতিশ্রুত দিন গতকাল, রওনা হয়েছে, পৌঁছায়নি — দেরির তালিকায়, আর রওনায় গেট পাস */
    private function dispatchedChallan(): DeliveryChallan
    {
        $challans = app(DeliveryChallanService::class);
        $challan = $challans->create([
            'customer_id' => $this->shop->id, 'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(), 'own_transport' => true,
        ], [['product_id' => $this->product->id, 'delivered_qty' => '1', 'rate' => '10']]);
        $challan->forceFill(['ship_date' => now()->subDay()->toDateString()])->save();
        $challan = $challans->confirm($challan);
        app(DeliveryStageService::class)->move($challan->fresh(), DeliveryStage::DISPATCHED);

        return $challan->fresh();
    }

    /** কাউন্টারের আসল বিক্রি — বিল, বাকি থাকে; ⓘ বড় অঙ্ক, যাতে ড্যাশবোর্ডের "সবচেয়ে বড় বকেয়া"-র আটটায় ওঠে */
    private function bill(): SalesInvoice
    {
        return app(DirectSaleService::class)->complete(
            ['customer_id' => $this->shop->id, 'warehouse_id' => $this->warehouse->id, 'own_transport' => '1',
                DirectSaleService::REPEAT_FIELD => '1'],
            [['product_id' => $this->product->id, 'qty' => '10', 'rate' => '90000', 'free_qty' => '0']],
        )['invoice'];
    }

    /** ছাপার সারি ভরাতে হালকা বিল — EveryPhoneDoorNamesTheShopsPointTest-এর একই */
    private function plainBill(string $no): SalesInvoice
    {
        return SalesInvoice::query()->create([
            'branch_id' => $this->company->defaultBranch()?->id, 'document_no' => $no, 'customer_id' => $this->shop->id,
            'trx_date' => now()->toDateString(), 'due_on' => now()->addDays(30)->toDateString(),
            'subtotal' => '500', 'discount' => '0', 'tax' => '0', 'total' => '500', 'status' => DocumentStatus::CONFIRMED,
        ]);
    }

    private function place(string $code, string $en, ?string $bn, string $level, ?Location $parent = null): Location
    {
        return Location::query()->create([
            'company_id' => $this->company->id, 'parent_id' => $parent?->id, 'code' => $code,
            'name_en' => $en, 'name_bn' => $bn, 'level' => $level, 'is_active' => true,
        ]);
    }
}
