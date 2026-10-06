<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\Events\SalesOrderLineRejected;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\CreditExposure;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\OrderProgress;
use App\Modules\Sales\Services\OrderTracking;
use App\Modules\Sales\Services\SalesOrderService;
use App\Modules\Sales\Support\SalesOrderStatus as S;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * এক লাইনের বাকিটা "আর দেওয়া হবে না" — আর খোলা পরিমাণের প্রতিটা পাঠক তা মানে (নকশা "DO বিক্রয় আদেশে মেশানো", ধাপ ৭)।
 *
 * ⓘ SAP-এর reason for rejection: ডিপো কম দিলে বাকিটা আদেশে খোলা থাকে (সমন্বয়কের উত্তর ৩), যতক্ষণ না কেউ কারণ লিখে বলেন "আর
 * দেওয়া হবে না"। তখন সেই অংশ আর পাওনা নয়: লাইনের বাকি, চালানের পাহারা, অর্ডারের ট্যাব, খোলা আদেশের রিপোর্ট, বিক্রয়
 * খাতার শতাংশ আর বাকির সীমার খোলা-আদেশের ভাগ — সবাই বাদ দেয়; আর আদেশ নিজে যে মাল ধরেছিল তা ছাড়ে।
 */
final class AnOrderLineIsClosedShortTest extends TestCase
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
        app(\App\Core\Services\SettingsService::class)->set('sales.reserve_on_order', true); // ⓘ এই দাবির প্রশ্নে আদেশে ধরা আছে — ডিফল্ট এখন চালানে (মালিক, ৬ অক্টোবর ২০২৬)
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(SettingsService::class)->set('sales.screen_orders', true);

        $this->customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        Customer::query()->whereKey($this->customer->id)->update(['credit_limit' => '100000000']);
        $this->customer = $this->customer->fresh();

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->where('track_batch', false)->where('is_active', true)
            ->where('sale_price', '>', 0)->orderBy('id')->firstOrFail();

        app(StockService::class)->move(
            product: $this->product, warehouse: $this->warehouse,
            sourceType: StockService::ADJUSTMENT, sourceId: $this->product->id, floor: '50',
        );
    }

    /**
     * ⭐ ১০টার আদেশ, ৪টা গেছে: কারণ ছাড়া, খোলার বেশি, আর খসড়া আদেশে "না"; ৩টা বন্ধ → বাকি ৩, ধরা মাল ৩টা কম, ঘটনা একবার;
     * আর ৩টা → লাইন বন্ধ, পুরো চালান, ধরা মাল আগের জায়গায়, আর কিছু পাঠানো বা বন্ধ করা যায় না।
     */
    public function test_closing_part_of_a_line_short_takes_it_off_the_open_quantity_and_releases_its_stock(): void
    {
        Event::fake([SalesOrderLineRejected::class]);
        $orders = app(SalesOrderService::class);
        $reserved = fn (): string => (string) app(StockService::class)->statesFor($this->product, $this->warehouse)['reserved'];
        $before = $reserved();

        $order = $orders->confirm($orders->create($this->header(), [$this->row('10')])->fresh(['lines']));
        $line = $order->fresh(['lines'])->lines->first();
        $this->deliver($order, $line->id, '4');
        $this->assertSame(0, bccomp(bcadd($before, '6', 4), $reserved(), 4), 'প্রস্তুতিটাই ভুল — ৬টা ধরা থাকার কথা।');

        $this->assertStringContainsString('⛔', $this->refused(fn () => $orders->rejectRemainder($line->fresh(), '3', '  '), 'reject_reason'),
            '⛔ কারণ ছাড়াই বাকিটা বন্ধ হলো।');
        $this->assertStringContainsString('⛔', $this->refused(fn () => $orders->rejectRemainder($line->fresh(), '7', 'কারণ'), 'reject_qty'),
            '⛔ খোলা ৬-এর বেশি বন্ধ হলো।');
        $draft = $orders->create($this->header(), [$this->row('2')]);
        $this->assertStringContainsString('⛔', $this->refused(fn () => $orders->rejectRemainder($draft->fresh(['lines'])->lines->first(), '1', 'কারণ'), 'status'),
            '⛔ খসড়া আদেশের লাইন বন্ধ হলো।');
        Event::assertNotDispatched(SalesOrderLineRejected::class);

        // ── ৩টা "আর দেওয়া হবে না"
        $orders->rejectRemainder($line->fresh(), '3', 'ডিলার ৩টা কম নেবেন');
        $l = $line->fresh();
        $this->assertSame(0, bccomp('3', (string) $l->rejected_qty, 4));
        $this->assertSame('ডিলার ৩টা কম নেবেন', $l->reject_reason);
        $this->assertSame(0, bccomp('3', $l->pendingQty(), 4), '⛔ লাইনের বাকি থেকে বন্ধ অংশ বাদ যায়নি।');
        $this->assertSame(S::LINE_OPEN, $l->line_status);
        $this->assertSame(0, bccomp(bcadd($before, '3', 4), $reserved(), 4), '⛔ বন্ধ অংশের ধরা মাল ছাড়া হয়নি।');
        $this->assertSame(S::PARTIAL, $order->fresh()->delivery_status);
        Event::assertDispatchedTimes(SalesOrderLineRejected::class, 1);
        Event::assertDispatched(SalesOrderLineRejected::class, fn (SalesOrderLineRejected $e) => $e->payload['sales_order_line_id'] === (int) $line->id
            && bccomp((string) $e->payload['qty'], '3', 4) === 0 && $e->payload['hold_mode'] === S::HOLD_LEDGER);

        $row = collect($this->report('sales.pending_orders'))->firstWhere('document_no', $order->document_no);
        $this->assertNotNull($row, 'প্রস্তুতিটাই ভুল — খোলা আদেশের রিপোর্টে আদেশ নেই।');
        $this->assertSame(0, bccomp('3', (string) $row['pending_qty'], 4), '⛔ খোলা আদেশের রিপোর্ট বন্ধ অংশও পাওনা দেখাচ্ছে।');

        // ── বাকি ৩টাও — লাইন বন্ধ, আদেশ পুরো চালান
        $orders->rejectRemainder($line->fresh(), '3', 'বাকিটাও নয়');
        $l = $line->fresh();
        $this->assertSame(S::LINE_CLOSED, $l->line_status, '⛔ কিছু গিয়ে বাকিটা বন্ধ — লাইন "বন্ধ" নয়।');
        $this->assertSame('ডিলার ৩টা কম নেবেন · বাকিটাও নয়', $l->reject_reason, '⛔ আগের কারণ হারিয়েছে।');
        $this->assertSame(0, bccomp('0', $l->pendingQty(), 4));
        $this->assertSame(0, bccomp($before, $reserved(), 4), '⛔ পুরোটা বন্ধ, অথচ ধরা মাল আগের জায়গায় ফেরেনি।');
        $this->assertSame(S::FULL, app(OrderProgress::class)->of($order->fresh(['lines']))['delivery']);

        $this->assertNull(collect($this->report('sales.pending_orders'))->firstWhere('document_no', $order->document_no),
            '⛔ সব বন্ধ, তবু আদেশ খোলা আদেশের রিপোর্টে।');
        $register = collect($this->report('sales.register'))->first(fn ($r) => $r['document_no'] === $order->document_no);
        $this->assertSame(100, (int) ($register['delivered_pct'] ?? 0), '⛔ বিক্রয় খাতা বন্ধ অংশকেও না-যাওয়া গুনছে।');

        $ids = fn (string $tab) => collect($this->get(route('sales.order.index', ['tab' => $tab]))->viewData('orders')->items())->pluck('id')->all();
        $this->assertContains($order->id, $ids(OrderTracking::LIST_HISTORY), '⛔ বাকিটা বন্ধ করা আদেশ ইতিহাসে নেই।');
        $this->assertNotContains($order->id, $ids(OrderTracking::LIST_PARTIAL), '⛔ বাকিটা বন্ধ করা আদেশ এখনো "আংশিক" ট্যাবে।');

        // ── আর কিছু পাঠানো বা বন্ধ করা যায় না
        $this->assertNotEmpty($this->refused(fn () => $this->deliver($order, $line->id, '1'), 'lines'),
            '⛔ বন্ধ অংশের মাল চালানে পাঠানো গেল।');
        $this->assertStringContainsString('⛔', $this->refused(fn () => $orders->rejectRemainder($line->fresh(), '1', 'আবার'), 'reject_qty'));
    }

    /**
     * ⭐ নতুন ধারার আদেশে বন্ধ অংশ বাকির সীমার খোলা-আদেশের ভাগ থেকেও সরে (সমন্বয়কের উত্তর ৪-এর হিসাব)।
     */
    public function test_the_closed_part_no_longer_counts_against_the_credit_limit(): void
    {
        app(SettingsService::class)->set(SalesOrderService::REPLACES_DO, true);
        app()->forgetInstance(SalesOrderService::class);
        $orders = app(SalesOrderService::class);
        $credit = app(CreditExposure::class);

        $base = $credit->pending($this->customer->fresh());
        $order = $orders->markConfirmed($orders->submit($orders->create($this->header(), [$this->row('10')])->fresh(['lines'])));
        $this->assertSame(S::CONFIRMED, $order->status, 'প্রস্তুতিটাই ভুল — আদেশ সংরক্ষিত নয়।');
        $line = $order->fresh(['lines'])->lines->first();
        $unit = bcdiv((string) $line->amount, (string) $line->ordered_qty, 4);

        $this->assertSame(0, bccomp(bcadd($base, (string) $line->amount, 4), $credit->pending($this->customer->fresh()), 2),
            'প্রস্তুতিটাই ভুল — খোলা আদেশ সীমায় গোনা হচ্ছে না।');

        $orders->rejectRemainder($line, '4', 'চার নয়');

        $this->assertSame(0, bccomp(bcadd($base, bcmul($unit, '6', 4), 4), $credit->pending($this->customer->fresh()), 2),
            '⛔ "আর দেওয়া হবে না" অংশও বাকির সীমায় গোনা হচ্ছে।');
    }

    /**
     * ⭐ নতুন ধারার আদেশও নিজের ধরা মাল ছাড়ে — বাকি বন্ধে আর বন্ধে (abos-86 আদেশের মাল মজুদের খাতায় একই উৎসে ধরেন, 60ac3abf)।
     *
     * ⛔ মজুদ কম থাকায় যতটা ধরা গেছে তার বেশি বাকি বন্ধ হলে, পরের চালান এমন মাল ছাড়ত যা আদেশ আর ধরে না — অন্য কাগজের ধরা
     * মাল খেয়ে Reserved আগের জায়গার নিচে নামত। বন্ধে না ছাড়লে বন্ধ আদেশের মাল চিরকাল আটকে থাকত।
     */
    public function test_a_new_flow_order_releases_what_it_held_on_reject_and_on_close(): void
    {
        app(SettingsService::class)->set(SalesOrderService::REPLACES_DO, true);
        app()->forgetInstance(SalesOrderService::class);
        $orders = app(SalesOrderService::class);
        $stock = app(StockService::class);
        $reserved = fn (): string => (string) $stock->statesFor($this->product, $this->warehouse)['reserved'];

        // ── মজুদ কম: যতটা আছে ততটাই ধরা, বাকিটা ব্যাক অর্ডার
        $before = $reserved();
        $held = $stock->availableQty($this->product, $this->warehouse);
        $short = $orders->submit($orders->create($this->header(), [$this->row(bcadd($held, '5', 4))])->fresh(['lines']));
        $this->assertSame(S::CONFIRMED, $short->fresh()->status, 'প্রস্তুতিটাই ভুল — আদেশ মাল ধরে সংরক্ষিত হয়নি।');
        $this->assertSame(0, bccomp(bcadd($before, $held, 4), $reserved(), 4), 'প্রস্তুতিটাই ভুল — যতটা আছে ততটা ধরা হয়নি।');

        // ⓘ ৪টা রেখে বাকি সব বন্ধ — ধরা সবটা ছাড়ে
        $line = $short->fresh(['lines'])->lines->first();
        $orders->rejectRemainder($line, bcadd($held, '1', 4), 'বাকিটা আর নয়');
        $this->assertSame(0, bccomp($before, $reserved(), 4), '⛔ নতুন ধারার আদেশের বাকি বন্ধে ধরা মাল ছাড়া হয়নি।');

        // ⓘ খোলা ৪টার চালান — আদেশ আর কিছু ধরে না, তাই কিছুই ছাড়ে না
        $this->deliver($short, $line->id, '4');
        $this->assertSame(0, bccomp($before, $reserved(), 4), '⛔ চালান এমন মাল ছেড়েছে যা আদেশ আর ধরে না — অন্য কাগজের ধরা মাল খেয়েছে।');

        // ── বন্ধে: ১০টা ধরা, ৪টা গেছে, বাকি ৬টা বন্ধে ছাড়ে
        $stock->move(product: $this->product, warehouse: $this->warehouse,
            sourceType: StockService::ADJUSTMENT, sourceId: $this->product->id, floor: '20');
        $base = $reserved();
        $full = $orders->submit($orders->create($this->header(), [$this->row('10')])->fresh(['lines']));
        $this->assertSame(S::CONFIRMED, $full->fresh()->status);
        $this->deliver($full, $full->fresh(['lines'])->lines->first()->id, '4');
        $this->assertSame(0, bccomp(bcadd($base, '6', 4), $reserved(), 4), 'প্রস্তুতিটাই ভুল — ৬টা ধরা থাকার কথা।');

        $orders->close($full->fresh(), 'বাকি নেবেন না');
        $this->assertSame(0, bccomp($base, $reserved(), 4), '⛔ নতুন ধারার আদেশ বন্ধ, অথচ বাকি ৬টা এখনো আদেশের নামে ধরা।');
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

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

    private function deliver(SalesOrder $order, int $lineId, string $qty): void
    {
        $challans = app(DeliveryChallanService::class);
        $paper = $challans->create([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'sales_order_id' => $order->id,
            'trx_date' => now()->toDateString(),
        ], [[
            'product_id' => $this->product->id,
            'sales_order_line_id' => $lineId,
            'delivered_qty' => $qty,
            'rate' => (string) $this->product->sale_price,
        ]]);

        $challans->confirm($paper->fresh(['lines']));
    }

    /** @return list<array<string, mixed>> */
    private function report(string $key): array
    {
        return app(ReportEngine::class)->run($key, ['from' => now()->subMonth()->toDateString(), 'to' => now()->toDateString()], perPage: 500)->rows;
    }

    private function refused(callable $act, string $field): string
    {
        try {
            $act();
        } catch (ValidationException $e) {
            return (string) ($e->errors()[$field][0] ?? '');
        }

        $this->fail("⛔ কাজটা থামার কথা ছিল ('{$field}')।");
    }
}
