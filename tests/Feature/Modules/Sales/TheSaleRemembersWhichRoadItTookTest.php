<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\MasterData\Models\SalesChannel;
use App\Modules\MasterData\Services\SalesChannelDefaults;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Reports\SalesChannelReports;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Sales\Services\SalesOrderService;
use App\Modules\Sales\Services\SalesReturnService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * বিক্রয়টা মনে রাখে কোন পথে গিয়েছিল — NEXUS §২৮।
 *
 * ── ⭐ কেন এই পরীক্ষাগুলো ────────────────────────────────────────────
 * পথটা গ্রাহকের সারি থেকে join করে পড়লে একজন গ্রাহককে "খুচরা" থেকে
 * "ডিলার" করামাত্র তাঁর পুরনো সব বিক্রি ডিলার-পথে সরে যেত, আর পথের
 * রিপোর্টের গত মাসগুলো নীরবে বদলাত। ⛔ কোনো কিছু লাল হত না — সংখ্যাগুলো
 * দেখতে দিব্যি ঠিক।
 *
 * ── যা দাবি করা হচ্ছে ─────────────────────────────────────────────────
 *   ⓵ গ্রাহকের পথ বদলালে পুরনো বিল পুরনো পথেই থাকে; নতুন বিল নতুন পথে
 *   ⓶ চালান আদেশের পথ বয়, ফেরত বিলের পথ বয় — মাঝপথে গ্রাহক বদলালেও
 *   ⓷ রিপোর্টের যোগফল = বিলগুলোর যোগফল; ফেরত নিজের সারিতে; পথহীন বিল
 *      হারায় না
 *   ⓸ অন্য কোম্পানির প্রসঙ্গে রিপোর্ট খালি
 *   ⓹ রিপোর্টের দরজা — একই মানুষ, চাবি ছাড়া ৪০৩, চাবিসহ ২০০
 *
 * ⚠️ ⓵–⓸ WIRING-এর উপর দাঁড়ায় (চারটা মডেলে `CarriesTheSalesChannel`,
 * রিপোর্টের নিবন্ধন), ⓹ SalesReportController-এর `by-channel` স্লাগের উপর।
 */
final class TheSaleRemembersWhichRoadItTookTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Customer $customer;

    private Warehouse $warehouse;

    private Product $product;

    private SalesChannel $retail;

    private SalesChannel $dealer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        // ⓘ বারবার ডাকা নিরাপদ — ওয়্যারিং থাকলে এটা কিছুই যোগ করে না
        app(SalesChannelDefaults::class)->installMissing();

        $this->customer = Customer::query()->orderBy('id')->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->orderBy('id')->firstOrFail();

        $this->retail = SalesChannel::query()->where('code', 'RETAIL')->firstOrFail();
        $this->dealer = SalesChannel::query()->where('code', 'DEALER')->firstOrFail();

        $this->moveCustomerTo($this->retail);
    }

    // ── ⓵ ইতিহাস বদলায় না ───────────────────────────────────────────────

    public function test_moving_the_customer_to_another_channel_does_not_move_the_old_bills(): void
    {
        $before = $this->confirmedInvoice('2', '100');

        $this->assertSame($this->retail->id, (int) $before->channel_id,
            'বিলে গ্রাহকের সেদিনের পথ বসেনি।');

        $this->moveCustomerTo($this->dealer);

        $this->assertSame($this->retail->id, (int) $before->fresh()->channel_id,
            '⛔ গ্রাহকের পথ বদলাতেই পুরনো বিলের পথ বদলে গেছে — ইতিহাস নতুন করে লেখা হয়েছে।');

        $after = $this->confirmedInvoice('1', '100');

        $this->assertSame($this->dealer->id, (int) $after->channel_id,
            'নতুন বিলে গ্রাহকের নতুন পথ বসেনি।');

        // রিপোর্টেও দুইটা আলাদা সারি — পুরনোটা খুচরায়, নতুনটা ডিলারে
        $rows = $this->reportRows();

        $this->assertSame(0, bccomp((string) $rows[$this->retail->id]['net_sales'], '200', 2));
        $this->assertSame(0, bccomp((string) $rows[$this->dealer->id]['net_sales'], '100', 2));
    }

    /**
     * পথটা ফর্ম থেকে আসে না — কেউ পাঠালেও গ্রাহকের পথই বসে।
     *
     * ⛔ বিপজ্জনক ইনপুট: `channel_id` সরাসরি তৈরির ডেটায়।
     */
    public function test_a_channel_sent_with_the_bill_is_ignored_in_favour_of_the_customers(): void
    {
        $invoice = app(SalesInvoiceService::class)->create(
            [
                'customer_id' => $this->customer->id,
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString(),
                'channel_id' => $this->dealer->id,
            ],
            [['product_id' => $this->product->id, 'qty' => '1', 'rate' => '100']],
        );

        $this->assertSame($this->retail->id, (int) $invoice->fresh()->channel_id);
    }

    /** খসড়া এখনো বিক্রি নয় — গ্রাহক বদলালে পথও বদলায়; নিশ্চিত বিলে কখনো নয়। */
    public function test_only_a_draft_follows_a_change_of_customer(): void
    {
        $other = Customer::query()->whereKeyNot($this->customer->id)->orderBy('id')->firstOrFail();
        $other->forceFill(['channel_id' => $this->dealer->id])->save();

        $draft = app(SalesInvoiceService::class)->create(
            [
                'customer_id' => $this->customer->id,
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString(),
            ],
            [['product_id' => $this->product->id, 'qty' => '1', 'rate' => '100']],
        );

        $draft->forceFill(['customer_id' => $other->id])->save();
        $this->assertSame($this->dealer->id, (int) $draft->fresh()->channel_id,
            'খসড়ার গ্রাহক বদলালেও পথ পুরনো গ্রাহকের রয়ে গেছে।');

        $posted = $this->confirmedInvoice('1', '100');
        $posted->forceFill(['customer_id' => $other->id])->save();

        $this->assertSame($this->retail->id, (int) $posted->fresh()->channel_id,
            '⛔ নিশ্চিত বিলের পথ নতুন করে লেখা হয়েছে।');
    }

    // ── ⓶ পরের কাগজ আগের কাগজের পথ বয় ─────────────────────────────────

    public function test_a_challan_carries_its_orders_channel_even_after_the_customer_moves(): void
    {
        $orders = app(SalesOrderService::class);

        $order = $orders->confirm($orders->create(
            [
                'customer_id' => $this->customer->id,
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString(),
            ],
            [['product_id' => $this->product->id, 'ordered_qty' => '3', 'rate' => '100']],
        ))->load('lines');

        $this->assertSame($this->retail->id, (int) $order->channel_id);

        $this->moveCustomerTo($this->dealer);

        $challan = app(DeliveryChallanService::class)->create(
            [
                'sales_order_id' => $order->id,
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString(),
            ],
            [[
                'product_id' => $this->product->id,
                'sales_order_line_id' => $order->lines->first()->id,
                'delivered_qty' => '3',
                'rate' => '100',
            ]],
        );

        $this->assertSame($this->retail->id, (int) $challan->channel_id,
            '⛔ আদেশ খুচরা পথে, অথচ তার চালান ডিলার পথে — একটা বিক্রি দুই পথে ভাগ হয়েছে।');
    }

    public function test_a_return_carries_its_invoices_channel_even_after_the_customer_moves(): void
    {
        $invoice = $this->confirmedInvoice('5', '100');

        $this->moveCustomerTo($this->dealer);

        $return = $this->confirmedReturn($invoice, '2');

        $this->assertSame($this->retail->id, (int) $return->channel_id,
            '⛔ খুচরায় বেচা মালের ফেরত ডিলার-পথে গোনা হচ্ছে।');
    }

    // ── ⓷ রিপোর্টের যোগফল ─────────────────────────────────────────────

    public function test_the_report_adds_up_to_the_bills_and_keeps_returns_on_their_own_column(): void
    {
        $first = $this->confirmedInvoice('4', '150');
        $this->moveCustomerTo($this->dealer);
        $this->confirmedInvoice('2', '300');
        $this->confirmedReturn($first, '1');

        // ⓘ পথহীন একটা বিলও — হারালে যোগফল মিলত না
        $this->customer->forceFill(['channel_id' => null])->save();
        $this->confirmedInvoice('1', '50');

        // একটা খসড়া — গোনা হবে না
        app(SalesInvoiceService::class)->create(
            ['customer_id' => $this->customer->id, 'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString()],
            [['product_id' => $this->product->id, 'qty' => '1', 'rate' => '10']],
        );

        $result = $this->report();

        $posted = SalesInvoice::query()->whereIn('status', DocumentStatus::POSTED)->get();

        $expectedNet = $posted->reduce(fn (string $s, SalesInvoice $i) => bcadd($s, bcsub((string) $i->total, (string) $i->tax, 4), 4), '0');
        $expectedDiscount = $posted->reduce(fn (string $s, SalesInvoice $i) => bcadd($s, (string) $i->discount, 4), '0');
        $expectedGross = $posted->reduce(fn (string $s, SalesInvoice $i) => bcadd($s, (string) $i->subtotal, 4), '0');
        $expectedQty = $posted->reduce(fn (string $s, SalesInvoice $i) => bcadd($s, (string) $i->lines()->sum('qty'), 4), '0');

        $this->assertSame(0, bccomp($result->totals['net_sales'], $expectedNet, 2),
            "রিপোর্টের বিক্রয় {$result->totals['net_sales']}, বিলগুলোর {$expectedNet}।");
        $this->assertSame(0, bccomp($result->totals['discount'], $expectedDiscount, 2));
        $this->assertSame(0, bccomp($result->totals['gross'], $expectedGross, 2));
        $this->assertSame(0, bccomp($result->totals['qty'], $expectedQty, 2));

        $returns = SalesReturn::query()->whereIn('status', DocumentStatus::POSTED)->get();
        $expectedReturns = $returns->reduce(fn (string $s, SalesReturn $r) => bcadd($s, bcsub((string) $r->total, (string) $r->tax, 4), 4), '0');

        $this->assertSame(1, $returns->count());
        $this->assertSame(0, bccomp($result->totals['returns_value'], $expectedReturns, 2));

        $rows = $this->reportRows();

        // ফেরত খুচরার সারিতে — ফেরতের দিনে গ্রাহক ডিলার ছিলেন, তবু
        $this->assertSame(0, bccomp((string) $rows[$this->retail->id]['returned_qty'], '1', 4));
        $this->assertSame(0, bccomp((string) $rows[$this->dealer->id]['returned_qty'], '0', 4));

        // পথহীন বিলটা নিজের সারিতে, নাম "পথ বসানো হয়নি"
        $this->assertArrayHasKey('none', $rows, 'পথহীন বিলটা রিপোর্ট থেকে হারিয়ে গেছে।');
        $this->assertSame(__('sales::channel.none'), $rows['none']['channel_name']);
        $this->assertSame(0, bccomp((string) $rows['none']['net_sales'], '50', 2));
    }

    // ── ⓸ কোম্পানির দেয়াল ──────────────────────────────────────────────

    public function test_another_company_sees_none_of_these_sales(): void
    {
        $this->confirmedInvoice('2', '100');

        $this->assertNotEmpty($this->report()->rows, 'নিজের কোম্পানিতে সারি নেই — তাহলে নিচের খালি ফলটা কিছুই প্রমাণ করে না।');

        $mart = Company::query()->where('code', 'FMART')->firstOrFail();

        $theirs = CompanyContext::forCompany($mart->id, fn () => $this->report());

        $this->assertSame([], $theirs->rows, '⛔ অন্য কোম্পানির প্রসঙ্গে এই কোম্পানির বিক্রি দেখা গেছে।');
    }

    // ── ⓹ দরজা — একই মানুষ, চাবি ছাড়া ও চাবিসহ ─────────────────────────

    public function test_the_report_door_opens_for_the_same_user_only_once_the_key_is_given(): void
    {
        $this->confirmedInvoice('2', '100');

        $clerk = User::factory()->create(['current_company_id' => $this->company->id]);
        $clerk->companies()->attach($this->company->id, ['is_active' => true]);

        $this->assertFalse($clerk->can(SalesChannelReports::PERMISSION),
            'ভূমিকাহীন নতুন মানুষের হাতে আগেই চাবি — তাহলে নিচের ৪০৩ কিছুই প্রমাণ করত না।');

        $url = route('sales.report.show', ['slug' => 'by-channel']);

        $this->actingAs($clerk)->get($url)->assertForbidden();

        $clerk->givePermissionTo(SalesChannelReports::PERMISSION);

        $page = $this->actingAs($clerk->fresh())->get($url)->assertOk()->getContent();

        // পাতার ভাষা মানুষের নিজের — দুই নামের যেকোনোটা
        $this->assertTrue(
            str_contains($page, $this->retail->name_en) || str_contains($page, (string) $this->retail->name_bn),
            'চাবি পাওয়ার পরও পাতায় পথের সারিটা নেই।',
        );

        // ইঞ্জিনের নিজের পাহারাও (নির্ধারিত রিপোর্ট এটাই পড়ে) একই উত্তর দেয়
        $this->assertTrue(app(ReportEngine::class)->get(SalesChannelReports::KEY)->allows($clerk->fresh()));
    }

    // ── সহায়ক ───────────────────────────────────────────────────────────

    private function moveCustomerTo(SalesChannel $channel): void
    {
        $this->customer->forceFill(['channel_id' => $channel->id])->save();
        $this->customer->refresh();
    }

    private function confirmedInvoice(string $qty, string $rate): SalesInvoice
    {
        $service = app(SalesInvoiceService::class);

        return $service->confirm(
            $service->create(
                [
                    'customer_id' => $this->customer->id,
                    'warehouse_id' => $this->warehouse->id,
                    'trx_date' => now()->toDateString(),
                ],
                [['product_id' => $this->product->id, 'qty' => $qty, 'rate' => $rate]],
            )
        )->load('lines');
    }

    private function confirmedReturn(SalesInvoice $invoice, string $qty): SalesReturn
    {
        $returns = app(SalesReturnService::class);

        return $returns->confirm($returns->create(
            [
                'customer_id' => $invoice->customer_id,
                'warehouse_id' => $this->warehouse->id,
                'sales_invoice_id' => $invoice->id,
                'trx_date' => now()->toDateString(),
                // ফেরতের কারণ এখন বাধ্যতামূলক — ডেমোর নিজের "DAMAGE"
                'reason_code_id' => ReasonCode::query()->inContext(ReasonCode::SALES_RETURN)
                    ->where('code', 'DAMAGE')->value('id'),
            ],
            [[
                'product_id' => $this->product->id,
                'sales_invoice_line_id' => $invoice->lines->first()->id,
                'qty' => $qty,
            ]],
        ))->fresh();
    }

    private function report(): \App\Core\Engines\Report\ReportResult
    {
        return app(ReportEngine::class)->run(SalesChannelReports::KEY, [
            'from' => now()->subDay()->toDateString(),
            'to' => now()->addDay()->toDateString(),
        ]);
    }

    /** @return array<int|string, array<string, mixed>> পথের id ধরে; পথহীন সারি 'none' */
    private function reportRows(): array
    {
        $out = [];

        foreach ($this->report()->rows as $row) {
            $out[$row['channel_id'] === null ? 'none' : (int) $row['channel_id']] = $row;
        }

        return $out;
    }
}
