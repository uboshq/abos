<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\User;
use App\Models\Company;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Sales\Services\SalesOrderService;
use App\Modules\Sales\Support\SalesOrderStatus as S;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * প্রতিটা চালান আর বিলের পরে আদেশের ঘরে অগ্রগতি লেখা থাকে — SO+DO মেশানোর নকশা, ধাপ ৫ (৪ অক্টোবর ২০২৬)।
 *
 * ⛔ আগে `delivery_status`/`billing_status` কেউ লিখত না — তালিকা, ফিল্টার আর রিপোর্ট পুরনো অঙ্ক পড়ত।
 * ⓘ উল্টো দিকও: বাতিলে অগ্রগতি আবার নামে।
 */
final class AnOrderKnowsHowFarItGotAfterEveryPaperTest extends TestCase
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

        app(SettingsService::class)->set('sales.screen_orders', true);

        $this->customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        Customer::query()->whereKey($this->customer->id)->update(['credit_limit' => '100000000']);
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->where('track_batch', false)->where('is_active', true)
            ->where('sale_price', '>', 0)->orderBy('id')->firstOrFail();

        app(StockService::class)->move(
            product: $this->product, warehouse: $this->warehouse,
            sourceType: StockService::ADJUSTMENT, sourceId: $this->product->id, floor: '50',
        );
    }

    public function test_a_challan_moves_the_delivery_and_its_cancel_moves_it_back(): void
    {
        $order = $this->order('10');
        $this->assertSame(S::NONE, $this->stored($order)['delivery'], 'প্রস্তুতিটাই ভুল।');

        $challan = $this->deliver($order, '4');
        $this->assertSame(S::PARTIAL, $this->stored($order)['delivery'], '⛔ চালান নিশ্চিত হলো, অথচ আদেশের ঘরে অগ্রগতি লেখা হয়নি।');
        $this->assertSame(S::PARTIAL, $this->stored($order)['line_delivery'], '⛔ লাইনের ঘরে লেখা হয়নি।');

        app(DeliveryChallanService::class)->cancel($challan->fresh(['lines']), 'ভুল চালান');
        $this->assertSame(S::NONE, $this->stored($order)['delivery'], '⛔ চালান বাতিল হলো, অথচ আদেশ এখনো "আংশিক" বলছে।');
    }

    public function test_a_bill_moves_the_billing_and_its_cancel_moves_it_back(): void
    {
        $order = $this->order('10');
        $challan = $this->deliver($order, '10');
        $this->assertSame(S::FULL, $this->stored($order)['delivery']);

        $invoice = $this->bill($challan, '10');
        $this->assertSame(S::FULL, $this->stored($order)['billing'], '⛔ বিল পোস্ট হলো, অথচ আদেশের বিলের ঘর বদলায়নি।');

        // ⓘ পাকা বিল সরাসরি বাতিল হয় না — বাতিল-ইনভয়েস (CXL) দিয়ে উল্টায়; মালিক নিজে চাইলে সাথে সাথে পাকা
        app(\App\Modules\Sales\Services\SalesInvoiceCancellationService::class)
            ->request($invoice->fresh(['lines']), User::query()->where('email', 'owner@abos.test')->firstOrFail(), 'ভুল বিল');
        $this->assertSame(S::NONE, $this->stored($order)['billing'], '⛔ বিল বাতিল হলো, অথচ আদেশ এখনো "পুরো বিল" বলছে।');
        $this->assertSame(S::NONE, $this->stored($order)['delivery'], '⛔ বাতিল-ইনভয়েস চালানও উল্টাল, অথচ আদেশ এখনো "পুরো চালান" বলছে।');
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /** @return array{delivery: string|null, billing: string|null, line_delivery: string|null} */
    private function stored(SalesOrder $order): array
    {
        $fresh = SalesOrder::query()->with('lines')->findOrFail($order->id);

        return [
            'delivery' => $fresh->delivery_status,
            'billing' => $fresh->billing_status,
            'line_delivery' => $fresh->lines->first()?->delivery_status,
        ];
    }

    private function order(string $qty): SalesOrder
    {
        $orders = app(SalesOrderService::class);
        $draft = $orders->create([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
        ], [[
            'product_id' => $this->product->id,
            'ordered_qty' => $qty,
            'rate' => (string) $this->product->sale_price,
        ]]);

        return $orders->confirm($draft->fresh(['lines']))->fresh(['lines']);
    }

    private function deliver(SalesOrder $order, string $qty): DeliveryChallan
    {
        $line = $order->fresh(['lines'])->lines->first();
        $challans = app(DeliveryChallanService::class);

        $paper = $challans->create([
            'customer_id' => $order->customer_id,
            'warehouse_id' => $this->warehouse->id,
            'sales_order_id' => $order->id,
            'trx_date' => now()->toDateString(),
        ], [[
            'product_id' => $line->product_id,
            'sales_order_line_id' => $line->id,
            'delivered_qty' => $qty,
            'rate' => (string) $line->rate,
        ]]);

        return $challans->confirm($paper->fresh(['lines']))->fresh(['lines']);
    }

    private function bill(DeliveryChallan $challan, string $qty): SalesInvoice
    {
        $line = $challan->lines->first();
        $invoices = app(SalesInvoiceService::class);

        $invoice = $invoices->create([
            'customer_id' => $challan->customer_id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
        ], [[
            'product_id' => $line->product_id,
            'delivery_challan_line_id' => $line->id,
            'qty' => $qty,
            'rate' => (string) $line->rate,
        ]]);

        return $invoices->confirm($invoice->fresh(['lines']));
    }
}
