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
use App\Modules\Sales\Services\CounterSaleSources;
use App\Modules\Sales\Services\DirectSaleService;
use App\Modules\Sales\Services\OrderProgress;
use App\Modules\Sales\Services\SalesOrderService;
use App\Modules\Sales\Support\SalesOrderStatus as S;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * বিক্রয় আদেশ কাউন্টারে খোলে — অংশে অংশে (নকশা "DO বিক্রয় আদেশে মেশানো", ধাপ ৬; মালিক, ৪ অক্টোবর ২০২৬: আন্তর্জাতিক মান)।
 *
 * ⓘ সংরক্ষিত আদেশ ডিপোর যাচাইয়ের তালিকায় আসে, "যাচাই করে বিক্রয়ে খুলুন" চাপলে কাউন্টারে খোলে, আর ডিপো কম দিলে বাকিটা
 * আদেশে খোলা থাকে (সমন্বয়কের উত্তর ৩) — পরের বার কাউন্টারে কেবল সেই বাকিটাই। প্রতিটা চালান আদেশে বাঁধা, তাই অগ্রগতি
 * ([[OrderProgress]]) চালান আর বিল দেখে; খোলার বেশি দেওয়া যায় না; সব চলে গেলে আর খোলে না।
 * ⓘ আজকের নিয়মের আদেশ কাউন্টারে খোলে না — সে চালান কাটে নিজের পাতা থেকে।
 */
final class AnOrderOpensAtTheCounterPartByPartTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

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
        $this->actingAs($this->owner);

        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();

        /* ⓘ সীমা এখানে বিষয় নয় — সে যেন ভুল কারণে থামাতে না পারে */
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
     * ⭐ ১০টার আদেশ: ডিপোর তালিকায় → কাউন্টারে খোলে → ৪টা বিক্রি (আংশিক চালান ও বিল, চালান আদেশে বাঁধা, বাকি ৬ খোলা) →
     * আবার তালিকায় → ৭টা চাইলে "না" → ৬টা (পুরো) → আর খোলে না, তালিকা থেকেও সরে।
     */
    public function test_an_order_sells_at_the_counter_part_by_part(): void
    {
        $order = $this->reservedOrder('10');
        $line = $order->lines->first();

        $list = $this->get(route('sales.direct.depot_check'))->assertOk();
        $this->assertContains($order->id, collect($list->viewData('salesOrders')->items())->pluck('id')->all(),
            '⛔ সংরক্ষিত আদেশ ডিপোর যাচাইয়ের তালিকায় নেই।');

        $this->post(route('sales.direct.depot_check.open'), ['source' => 'so', 'source_id' => $order->id])
            ->assertRedirect(route('sales.direct.create', ['source' => 'so', 'source_id' => $order->id]));
        $this->assertNotNull($order->fresh()->depot_check_at, '⛔ ডিপোর চিহ্ন বসেনি।');
        $this->assertSame(S::CONFIRMED, $order->fresh()->status, '⛔ ডিপোর যাচাই আদেশের অবস্থা বদলেছে — ওটা চিহ্ন, অবস্থা নয়।');

        // ── প্রথম অংশ: ৪টা
        $first = $this->sell($order, '4', $line->id);
        $challan = $this->challanOf($first);
        $this->assertSame($order->id, (int) $challan->sales_order_id, '⛔ কাউন্টারের চালান আদেশে বাঁধা নয়।');
        $this->assertSame($line->id, (int) $challan->lines->first()->sales_order_line_id, '⛔ চালানের লাইন আদেশের লাইনে বাঁধা নয়।');
        $this->assertSame('so', $first->counter_source);

        $p = app(OrderProgress::class)->of($order->fresh(['lines']));
        $this->assertSame([S::PARTIAL, S::PARTIAL], [$p['delivery'], $p['billing']], '⛔ অগ্রগতি চালান আর বিল দেখেনি।');
        $this->assertSame(S::CONFIRMED, $order->fresh()->status, '⛔ আংশিক বিলে আদেশ বন্ধ হয়ে গেছে — বাকিটা খোলা থাকার কথা।');
        $this->assertNull($order->fresh()->depot_check_at, '⛔ বিক্রির পরে ডিপোর চিহ্ন থেকে গেছে।');
        $this->assertSame(S::PARTIAL, $order->fresh()->delivery_status, '⛔ অগ্রগতির ঘর তাজা হয়নি।');

        $screen = $order->fresh()->counterScreen();
        $this->assertSame(0, bccomp('6', (string) $screen['lines'][0]['qty'], 4), '⛔ পরের বার কাউন্টারে বাকি ৬টা নয়।');

        $this->assertContains($order->id, collect($this->get(route('sales.direct.depot_check'))->viewData('salesOrders')->items())->pluck('id')->all(),
            '⛔ আংশিক চালানের পরে আদেশ আবার ডিপোর তালিকায় আসেনি।');

        // ── খোলার বেশি নয়
        $this->assertNotEmpty($this->refused(fn () => $this->sell($order, '7', $line->id), 'lines'), '⛔ খোলা ৬-এর বেশি ৭টা বিক্রি হলো।');

        // ── দ্বিতীয় অংশ: বাকি ৬টা
        $this->sell($order, '6', $line->id);
        $p = app(OrderProgress::class)->of($order->fresh(['lines']));
        $this->assertSame([S::FULL, S::FULL], [$p['delivery'], $p['billing']]);

        $this->assertStringContainsString('⛔', $this->refused(fn () => $order->fresh()->assertReadyForCounter(), 'source'),
            '⛔ সব চলে যাওয়ার পরেও আদেশ কাউন্টারে খোলে।');
        $this->assertNotContains($order->id, collect($this->get(route('sales.direct.depot_check'))->viewData('salesOrders')->items())->pluck('id')->all(),
            '⛔ পুরো চালানের আদেশ এখনো ডিপোর তালিকায়।');
    }

    /**
     * ⭐ আজকের নিয়মের আদেশ (সুইচ বন্ধে সংরক্ষিত) কাউন্টারে খোলে না; আর রেজিস্ট্রি দুই কাগজই চেনে।
     */
    public function test_a_ledger_order_does_not_open_at_the_counter_and_both_papers_are_known(): void
    {
        $orders = app(SalesOrderService::class);
        $ledger = $orders->confirm($orders->create($this->header(), [$this->row('3')])->fresh(['lines']));
        $this->assertSame(S::CONFIRMED, $ledger->status);

        $this->assertStringContainsString('⛔', $this->refused(fn () => $ledger->fresh()->assertReadyForCounter(), 'source'),
            '⛔ আজকের নিয়মের আদেশ কাউন্টারে খুলেছে — তার মাল ধরা মজুদের খাতায়, হোল্ডে নয়।');

        $sources = app(CounterSaleSources::class);
        $this->assertTrue($sources->knows('so'), '⛔ কাউন্টার বিক্রয় আদেশ চেনে না।');
        $this->assertTrue($sources->knows('do'), '⛔ খোলা DO-র পথ হারিয়েছে।');
        $this->assertNull(app(\App\Modules\Sales\Models\DeliveryOrder::class)->orderLink(), '⛔ DO নিজেকে আদেশ বলছে।');
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /** নতুন ধারায় জমা, সই ছাড়া অনুমোদিত, তারপর (abos-86-এর হোল্ডের জায়গায়) সংরক্ষিত */
    private function reservedOrder(string $qty): SalesOrder
    {
        app(SettingsService::class)->set(SalesOrderService::REPLACES_DO, true);
        app()->forgetInstance(SalesOrderService::class);
        $orders = app(SalesOrderService::class);

        $order = $orders->submit($orders->create($this->header(), [$this->row($qty)])->fresh(['lines']));
        $this->assertSame(S::APPROVED, $order->status, 'প্রস্তুতিটাই ভুল — আদেশ অনুমোদিত হয়নি।');

        return $orders->markConfirmed($order)->fresh(['lines']);
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
        $this->assertNotNull($id, '⛔ বিলের সারি কোনো চালানে বাঁধা নয়।');

        return DeliveryChallan::query()->with('lines')->findOrFail($id);
    }

    private function refused(callable $act, string $field): string
    {
        try {
            $act();
        } catch (ValidationException $e) {
            return (string) ($e->errors()[$field][0] ?? implode(' ', array_map(fn ($m) => implode(' ', (array) $m), $e->errors())));
        }

        $this->fail("⛔ কাজটা থামার কথা ছিল ('{$field}')।");
    }
}
