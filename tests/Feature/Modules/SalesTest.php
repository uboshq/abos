<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\CustomerService;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\CollectionService;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Sales\Services\SalesOrderService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Sales — Phase 8।
 *
 * প্ল্যানে এই ধাপের শেষ হওয়ার শর্ত: **Order → Challan → Invoice →
 * Collection**। এখানকার পরীক্ষাগুলো ওই পুরো পথটাই হাঁটে, আর তার সাথে
 * সেই জায়গাগুলো যেখানে ভুল হলে মাল বা টাকা হারিয়ে যেত।
 *
 * এই মডিউলেই Inventory-র Reserved অবস্থাটা প্রথম লেখক পেল — এতদিন চারটা
 * অবস্থা ঘোষিত ছিল কিন্তু তৃতীয়টায় কেউ কিছু লিখত না।
 */
class SalesTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private Customer $customer;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->user);

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->firstOrFail();

        $this->customer = Customer::query()->first() ?? app(CustomerService::class)
            ->create([
                'name_en' => 'Test Customer',
                'credit_limit' => 0,
                'credit_days' => 0,
            ]);
    }

    private function orders(): SalesOrderService
    {
        return app(SalesOrderService::class);
    }

    private function challans(): DeliveryChallanService
    {
        return app(DeliveryChallanService::class);
    }

    private function invoices(): SalesInvoiceService
    {
        return app(SalesInvoiceService::class);
    }

    private function collections(): CollectionService
    {
        return app(CollectionService::class);
    }

    private function stock(): StockService
    {
        return app(StockService::class);
    }

    private function balanceOf(string $code): string
    {
        $account = Account::query()->where('code', $code)->firstOrFail();

        return LedgerEntry::query()->where('account_id', $account->id)->get()->reduce(
            fn (string $sum, LedgerEntry $e) => bcadd($sum, bcsub((string) $e->debit, (string) $e->credit, 4), 4),
            '0',
        );
    }

    private function makeOrder(string $qty = '10', string $rate = '200'): SalesOrder
    {
        return $this->orders()->create(
            ['customer_id' => $this->customer->id, 'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString()],
            [['product_id' => $this->product->id, 'ordered_qty' => $qty, 'rate' => $rate]],
        );
    }

    private function makeChallan(?SalesOrder $order, string $qty = '10', string $rate = '200'): DeliveryChallan
    {
        return $this->challans()->create(
            [
                'customer_id' => $this->customer->id,
                'warehouse_id' => $this->warehouse->id,
                'sales_order_id' => $order?->id,
                'trx_date' => now()->toDateString(),
            ],
            [[
                'product_id' => $this->product->id,
                'delivered_qty' => $qty,
                'rate' => $rate,
                'sales_order_line_id' => $order?->lines->first()->id,
            ]],
        );
    }

    private function makeInvoice(?DeliveryChallan $challan, string $qty = '10', string $rate = '200'): SalesInvoice
    {
        return $this->invoices()->create(
            ['customer_id' => $this->customer->id, 'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString()],
            [[
                'product_id' => $this->product->id,
                'qty' => $qty,
                'rate' => $rate,
                'delivery_challan_line_id' => $challan?->lines->first()->id,
            ]],
        );
    }

    // ── প্ল্যানের শেষ হওয়ার শর্ত ────────────────────────────────────────

    /**
     * Order → Challan → Invoice → Collection, আর প্রতিটা ধাপে ঠিক যা
     * হওয়ার কথা তা-ই হয়।
     */
    public function test_the_whole_path_from_order_to_collection(): void
    {
        // ডেমো পণ্যটার কিছু মাল আগে থেকেই আটকানো, তাই শুরুর অবস্থাটা ধরে
        // নিয়ে তুলনা করা হয় — ধ্রুবক সংখ্যা ধরে নিলে ডেমো বদলালেই ভাঙত
        $before = $this->stock()->statesFor($this->product, $this->warehouse);

        // ── অর্ডার: মাল ধরা পড়ে, তাকেই থাকে ──
        $order = $this->orders()->confirm($this->makeOrder('10', '200'));

        $states = $this->stock()->statesFor($this->product, $this->warehouse);

        $this->assertSame(0, bccomp($states['floor'], $before['floor'], 4), 'অর্ডারে তাকের মাল নড়ে না');
        $this->assertSame(0, bccomp($states['reserved'], bcadd($before['reserved'], '10', 4), 4));
        $this->assertSame(0, bccomp($states['available'], bcsub($before['available'], '10', 4), 4));

        $floorBefore = $before['floor'];

        $this->assertSame(0, LedgerEntry::query()
            ->where('source_type', SalesOrder::drillSourceType())->count(),
            'অর্ডারে খতিয়ানে কিছু বসার কথা নয়');

        // ── চালান: মাল নামে, ধরা ছাড়ে ──
        $challan = $this->challans()->confirm($this->makeChallan($order, '10', '200'));

        $states = $this->stock()->statesFor($this->product, $this->warehouse);

        $this->assertSame(0, bccomp($states['floor'], bcsub($floorBefore, '10', 4), 4));
        $this->assertSame(0, bccomp($states['reserved'], $before['reserved'], 4), 'ধরাটা ছেড়ে যেতে হবে');
        $this->assertSame(0, LedgerEntry::query()
            ->where('source_type', DeliveryChallan::drillSourceType())->count(),
            'মাল বেরোনো মানে বিক্রি নয় — খতিয়ানে কিছু বসে না');

        // ── বিল: আয়, পাওনা আর খরচ ──
        $invoice = $this->invoices()->confirm($this->makeInvoice($challan, '10', '200'));

        $this->assertSame(0, bccomp($this->balanceOf(StandardChart::RECEIVABLE), '2000', 4));
        $this->assertSame(0, bccomp($this->balanceOf(StandardChart::SALES), '-2000', 4));

        // খরচ = ১০ × পণ্যের ক্রয়মূল্য
        $cost = bcmul('10', (string) $this->product->purchase_price, 4);
        $this->assertSame(0, bccomp($this->balanceOf(StandardChart::COST_OF_GOODS_SOLD), $cost, 4));
        $this->assertSame(0, bccomp($this->balanceOf(StandardChart::INVENTORY), bcmul($cost, '-1', 4), 4));

        // ── আদায়: পাওনা কমে ──
        $collection = $this->collections()->create(
            ['customer_id' => $this->customer->id, 'trx_date' => now()->toDateString(), 'amount' => '2000'],
            [['sales_invoice_id' => $invoice->id, 'amount' => '2000']],
        );

        $this->collections()->confirm($collection);

        $this->assertSame(0, bccomp($this->balanceOf(StandardChart::RECEIVABLE), '0', 4));
        $this->assertSame(0, bccomp($this->balanceOf(StandardChart::CASH_IN_HAND), '2000', 4));
        $this->assertSame(0, bccomp($invoice->fresh()->dueAmount(), '0', 4));
    }

    /** গ্রাহকের বকেয়া নিজে থেকেই মেলে — খতিয়ান থেকে গোনা হয়। */
    public function test_the_customer_outstanding_reconciles_by_itself(): void
    {
        $before = $this->customer->fresh()->outstanding();

        $challan = $this->challans()->confirm($this->makeChallan(null, '5', '300'));
        $this->invoices()->confirm($this->makeInvoice($challan, '5', '300'));

        $after = $this->customer->fresh()->outstanding();

        $this->assertSame(0, bccomp(bcsub($after, $before, 4), '1500', 4));
    }

    // ── Reserved অবস্থা ─────────────────────────────────────────────────

    /**
     * ধরে রাখা মাল তাকেই থাকে।
     *
     * সরিয়ে ফেললে গুদামে দাঁড়িয়ে গোনা মানুষ বেশি পেতেন আর খাতা কম বলত,
     * অথচ কেউ কিছু সরায়নি।
     */
    public function test_reserving_does_not_take_stock_off_the_floor(): void
    {
        $before = $this->stock()->floorQty($this->product, $this->warehouse);

        $this->orders()->confirm($this->makeOrder('10'));

        $this->assertSame(0, bccomp($this->stock()->floorQty($this->product, $this->warehouse), $before, 4));
    }

    /**
     * বিক্রয়যোগ্য মালের বেশি অর্ডার নেওয়া যায় না।
     */
    public function test_more_than_available_cannot_be_ordered(): void
    {
        $available = $this->stock()->availableQty($this->product, $this->warehouse);

        $this->expectException(ValidationException::class);

        $this->orders()->confirm($this->makeOrder(bcadd($available, '1', 4)));
    }

    /** সুইচ খুললে রাস্তায় থাকা মালের অর্ডারও নেওয়া যায়। */
    public function test_the_setting_allows_selling_beyond_stock(): void
    {
        app(SettingsService::class)->set('sales.allow_negative_stock', true);

        $available = $this->stock()->availableQty($this->product, $this->warehouse);

        $order = $this->orders()->confirm($this->makeOrder(bcadd($available, '5', 4)));

        $this->assertSame(DocumentStatus::CONFIRMED, $order->status);
    }

    /**
     * অর্ডার বাতিল হলে ধরে রাখা মাল ছেড়ে দেওয়া হয়।
     *
     * না ছাড়লে ওই মালটা চিরকাল ধরা থেকে যেত — কেউ বেচতে পারত না, আর
     * কেন পারছে না তার কোনো ব্যাখ্যাও থাকত না।
     */
    public function test_cancelling_an_order_releases_the_hold(): void
    {
        $order = $this->orders()->confirm($this->makeOrder('10'));

        $this->orders()->cancel($order, 'গ্রাহক বাতিল করেছেন');

        $this->assertSame(0, bccomp($this->stock()->reservedQty($this->product, $this->warehouse), '0', 4));
    }

    /**
     * আংশিক ডেলিভারির পর অর্ডার বাতিল করলে বাকিটুকুই ছাড়ে।
     *
     * পুরোটা ছাড়লে Reserved ঋণাত্মক হয়ে যেত — যা গেছে তার ধরা চালানই
     * ছেড়ে দিয়েছে, আর দ্বিতীয়বার ছাড়ার কিছু নেই।
     */
    public function test_cancelling_after_partial_delivery_releases_only_the_rest(): void
    {
        $order = $this->orders()->confirm($this->makeOrder('10'));

        $this->challans()->confirm($this->makeChallan($order, '4'));

        $this->assertSame(0, bccomp($this->stock()->reservedQty($this->product, $this->warehouse), '6', 4));

        $this->orders()->cancel($order, 'বাকিটা লাগবে না');

        $this->assertSame(0, bccomp($this->stock()->reservedQty($this->product, $this->warehouse), '0', 4));
    }

    // ── চালান ───────────────────────────────────────────────────────────

    public function test_a_challan_can_go_out_without_an_order(): void
    {
        $challan = $this->challans()->confirm($this->makeChallan(null, '3'));

        $this->assertSame(DocumentStatus::CONFIRMED, $challan->status);
        $this->assertNull($challan->sales_order_id);
    }

    /** চালান বাতিল হলে মাল স্টকে ফেরে। */
    public function test_cancelling_a_challan_brings_the_goods_back(): void
    {
        $before = $this->stock()->floorQty($this->product, $this->warehouse);

        $challan = $this->challans()->confirm($this->makeChallan(null, '4'));

        $this->challans()->cancel($challan, 'ভুল ঠিকানায় গিয়েছিল');

        $this->assertSame(0, bccomp($this->stock()->floorQty($this->product, $this->warehouse), $before, 4));
    }

    public function test_a_challan_that_is_already_invoiced_cannot_be_cancelled(): void
    {
        $challan = $this->challans()->confirm($this->makeChallan(null, '4'));
        $this->invoices()->confirm($this->makeInvoice($challan, '4'));

        $this->expectException(ValidationException::class);

        $this->challans()->cancel($challan, 'ভুল হয়েছিল');
    }

    // ── বিল ─────────────────────────────────────────────────────────────

    /**
     * চালান ছাড়া বিল কাটলে বিলই মাল নামায় — কাউন্টার বিক্রি।
     */
    public function test_a_counter_sale_moves_the_stock_on_the_invoice(): void
    {
        $before = $this->stock()->floorQty($this->product, $this->warehouse);

        $this->invoices()->confirm($this->makeInvoice(null, '2', '250'));

        $this->assertSame(0, bccomp(
            $this->stock()->floorQty($this->product, $this->warehouse),
            bcsub($before, '2', 4),
            4,
        ));
    }

    /** চালান ধরে বিল হলে মাল দ্বিতীয়বার নামে না। */
    public function test_an_invoice_against_a_challan_does_not_move_stock_twice(): void
    {
        $before = $this->stock()->floorQty($this->product, $this->warehouse);

        $challan = $this->challans()->confirm($this->makeChallan(null, '4'));
        $this->invoices()->confirm($this->makeInvoice($challan, '4'));

        $this->assertSame(0, bccomp(
            $this->stock()->floorQty($this->product, $this->warehouse),
            bcsub($before, '4', 4),
            4,
        ));
    }

    public function test_the_same_challan_line_cannot_be_invoiced_twice(): void
    {
        $challan = $this->challans()->confirm($this->makeChallan(null, '4'));
        $this->invoices()->confirm($this->makeInvoice($challan, '4'));

        $this->expectException(ValidationException::class);

        $this->makeInvoice($challan, '1');
    }

    /**
     * খরচ বিলের সময়ের ক্রয়মূল্যে জমা থাকে।
     *
     * পরে ক্রয়মূল্য বদলালেও গত মাসের মুনাফা বদলায় না — নাহলে বন্ধ করা
     * হিসাব আজ অন্যরকম দেখাত।
     */
    public function test_the_cost_is_frozen_at_the_time_of_the_invoice(): void
    {
        $invoice = $this->invoices()->confirm($this->makeInvoice(null, '2', '250'));

        $costThen = (string) $invoice->fresh()->cost_of_goods;

        $this->product->forceFill(['purchase_price' => '9999'])->save();

        $this->assertSame(0, bccomp((string) $invoice->fresh()->cost_of_goods, $costThen, 4));
    }

    public function test_an_invoice_with_money_against_it_cannot_be_cancelled(): void
    {
        $invoice = $this->invoices()->confirm($this->makeInvoice(null, '2', '250'));

        $this->collections()->confirm($this->collections()->create(
            ['customer_id' => $this->customer->id, 'trx_date' => now()->toDateString(), 'amount' => '100'],
            [['sales_invoice_id' => $invoice->id, 'amount' => '100']],
        ));

        $this->expectException(ValidationException::class);

        $this->invoices()->cancel($invoice, 'ভুল বিল');
    }

    // ── আদায় ────────────────────────────────────────────────────────────

    /**
     * এক আদায় কয়েকটা বিলে ভাগ হয়।
     */
    public function test_one_collection_can_settle_several_invoices(): void
    {
        $first = $this->invoices()->confirm($this->makeInvoice(null, '2', '100'));
        $second = $this->invoices()->confirm($this->makeInvoice(null, '3', '100'));

        $this->collections()->confirm($this->collections()->create(
            ['customer_id' => $this->customer->id, 'trx_date' => now()->toDateString(), 'amount' => '500'],
            [
                ['sales_invoice_id' => $first->id, 'amount' => '200'],
                ['sales_invoice_id' => $second->id, 'amount' => '300'],
            ],
        ));

        $this->assertSame(0, bccomp($first->fresh()->dueAmount(), '0', 4));
        $this->assertSame(0, bccomp($second->fresh()->dueAmount(), '0', 4));
    }

    /** বিলের বকেয়ার চেয়ে বেশি বসানো যায় না। */
    public function test_more_than_the_invoice_due_cannot_be_allocated(): void
    {
        $invoice = $this->invoices()->confirm($this->makeInvoice(null, '2', '100'));

        $this->expectException(ValidationException::class);

        $this->collections()->create(
            ['customer_id' => $this->customer->id, 'trx_date' => now()->toDateString(), 'amount' => '500'],
            [['sales_invoice_id' => $invoice->id, 'amount' => '300']],
        );
    }

    /** ভাগের যোগফল আদায়ের চেয়ে বেশি হতে পারে না। */
    public function test_the_allocation_cannot_exceed_the_collection(): void
    {
        $first = $this->invoices()->confirm($this->makeInvoice(null, '2', '100'));
        $second = $this->invoices()->confirm($this->makeInvoice(null, '3', '100'));

        $this->expectException(ValidationException::class);

        $this->collections()->create(
            ['customer_id' => $this->customer->id, 'trx_date' => now()->toDateString(), 'amount' => '250'],
            [
                ['sales_invoice_id' => $first->id, 'amount' => '200'],
                ['sales_invoice_id' => $second->id, 'amount' => '300'],
            ],
        );
    }

    /**
     * টাকা কেবল নগদ বা ব্যাংক জাতীয় খাতে জমা হয়।
     *
     * যেকোনো খাত নিতে দিলে কেউ ভুল করে "বিক্রয়" খাতে আদায় বসাত, আর তখন
     * আয় দুইবার গোনা হত।
     */
    public function test_money_cannot_land_in_a_non_money_account(): void
    {
        $sales = Account::query()->where('code', StandardChart::SALES)->firstOrFail();

        $this->expectException(ValidationException::class);

        $this->collections()->create(
            ['customer_id' => $this->customer->id, 'account_id' => $sales->id,
                'trx_date' => now()->toDateString(), 'amount' => '100'],
            [],
        );
    }

    public function test_cancelling_a_collection_puts_the_receivable_back(): void
    {
        $invoice = $this->invoices()->confirm($this->makeInvoice(null, '2', '100'));

        $collection = $this->collections()->confirm($this->collections()->create(
            ['customer_id' => $this->customer->id, 'trx_date' => now()->toDateString(), 'amount' => '200'],
            [['sales_invoice_id' => $invoice->id, 'amount' => '200']],
        ));

        $this->collections()->cancel($collection, 'চেক ফেরত এসেছে');

        $this->assertSame(0, bccomp($this->balanceOf(StandardChart::RECEIVABLE), '200', 4));
        $this->assertSame(0, bccomp($this->balanceOf(StandardChart::CASH_IN_HAND), '0', 4));
    }

    // ── ধারের সীমা ──────────────────────────────────────────────────────

    /**
     * ধারের সীমা পেরোলে অর্ডার আটকায়।
     */
    public function test_an_order_past_the_credit_limit_is_blocked(): void
    {
        $this->customer->forceFill(['credit_limit' => '500'])->save();

        $salesman = User::query()->where('email', 'sales@abos.test')->firstOrFail();
        $this->actingAs($salesman);

        $this->expectException(ValidationException::class);

        $this->orders()->confirm($this->makeOrder('10', '200'));
    }

    /** অনুমতি থাকলে সীমা পার করানো যায় — সেটাই অনুমোদনের জায়গা। */
    public function test_someone_with_the_permission_can_go_past_the_limit(): void
    {
        $this->customer->forceFill(['credit_limit' => '500'])->save();

        $order = $this->orders()->confirm($this->makeOrder('10', '200'));

        $this->assertSame(DocumentStatus::CONFIRMED, $order->status);
    }

    // ── টেন্যান্ট ও অনুমতি ──────────────────────────────────────────────

    public function test_one_company_never_sees_another_companys_sales(): void
    {
        $order = $this->makeOrder();

        $other = Company::query()->where('code', 'FMART')->firstOrFail();
        CompanyContext::set($other->id, $other->defaultBranch()?->id);

        $this->assertNull(SalesOrder::query()->find($order->id));
        $this->assertSame(0, SalesOrder::query()->count());
    }

    public function test_a_user_without_the_permission_cannot_reach_any_screen(): void
    {
        $stranger = User::factory()->create();
        $stranger->companies()->attach($this->company, ['is_active' => true]);
        $stranger->forceFill(['current_company_id' => $this->company->id])->save();

        $order = $this->makeOrder();

        foreach ([
            route('sales.order.index'),
            route('sales.order.show', $order),
            route('sales.challan.index'),
            route('sales.invoice.index'),
            route('sales.collection.index'),
            route('sales.report.show', 'uninvoiced'),
        ] as $url) {
            $this->actingAs($stranger)->get($url)->assertForbidden();
        }
    }

    // ── পর্দা ও রিপোর্ট ─────────────────────────────────────────────────

    public function test_creating_an_order_through_the_screen_works_end_to_end(): void
    {
        $this->actingAs($this->user)
            ->post(route('sales.order.store'), [
                'customer_id' => $this->customer->id,
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString(),
                'lines' => [
                    ['product_id' => $this->product->id, 'ordered_qty' => '3', 'rate' => '150'],
                ],
            ])
            ->assertRedirect();

        $order = SalesOrder::query()->latest('id')->firstOrFail();

        $this->assertStringStartsWith('SO-', $order->document_no);
        $this->assertSame(0, bccomp((string) $order->total, '450', 4));
    }

    public function test_the_uninvoiced_report_shows_what_went_out_unbilled(): void
    {
        $this->challans()->confirm($this->makeChallan(null, '6', '120'));

        $result = app(ReportEngine::class)->run('sales.uninvoiced', [
            'from' => now()->subYear()->toDateString(),
            'to' => now()->addDay()->toDateString(),
        ]);

        $this->assertCount(1, $result->rows);

        $row = (array) $result->rows[0];

        $this->assertSame(0, bccomp((string) $row['uninvoiced_qty'], '6', 4));
        $this->assertSame(0, bccomp((string) $row['uninvoiced_value'], '720', 4));
    }
}
