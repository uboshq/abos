<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockTransferService;
use App\Modules\MasterData\Models\Unit;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Purchase\Services\PurchaseOrderService;
use App\Modules\Sales\Services\DirectSaleService;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * প্যাকে লেখা কাগজ — সংখ্যাটা সত্যিই নেমেছে কিনা।
 *
 * PackConversionTest ইঞ্জিনটা পরীক্ষা করে; এটা করে তার *সংযোগ*। ইঞ্জিন
 * ঠিক থাকা সত্ত্বেও কোনো সার্ভিসে ডাকটা বাদ পড়লে কিছুই ভাঙত না — কেবল
 * ওই কাগজে ২ বাক্স মানে ২ পিস হয়ে যেত, আর মজুদ নীরবে ভুল হত।
 */
class PackedDocumentTest extends TestCase
{
    use RefreshDatabase;

    private Unit $piece;

    private Unit $box;

    private Product $product;

    private Warehouse $warehouse;

    private Customer $customer;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->piece = Unit::query()->where('code', 'PCS')->firstOrFail();

        // ১ বাক্স = ১০ পাতা, ১ পাতা = ১০ পিস — অর্থাৎ ১০০ পিসে বাক্স
        $strip = Unit::query()->create([
            'code' => 'PATA', 'name_en' => 'Strip', 'name_bn' => 'পাতা',
            'base_unit_id' => $this->piece->id, 'factor' => '10', 'is_active' => true,
        ]);
        $this->box = Unit::query()->create([
            'code' => 'BOX', 'name_en' => 'Box', 'name_bn' => 'বাক্স',
            'base_unit_id' => $strip->id, 'factor' => '10', 'is_active' => true,
        ]);

        $this->product = Product::query()->firstOrFail();
        $this->product->forceFill(['unit_id' => $this->piece->id])->save();

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->customer = Customer::query()->firstOrFail();
        $this->supplier = Supplier::query()->firstOrFail();
    }

    private function assertQty(string $expected, mixed $actual): void
    {
        $this->assertSame(0, bccomp($expected, (string) $actual, 4), "{$actual} ≠ {$expected}");
    }

    /**
     * @return array<string, mixed>
     */
    private function printed(string $route, mixed $document): array
    {
        $seen = [];

        View::composer('print.document', function ($view) use (&$seen) {
            $seen = $view->getData();
        });

        $this->get(route($route, $document))->assertOk();

        return $seen;
    }

    // ── বিক্রয় ───────────────────────────────────────────────────

    /**
     * বিলে ২ বাক্স @ ৮০০ — মজুদে ২০০ পিস, দর ৮, মোট ১,৬০০।
     *
     * দর না নামালে মোট হত ১,৬০,০০০। ক্যাশিয়ার ধরে ফেলতেন, কিন্তু
     * ক্রয়ের কাগজে বা রিপোর্টে একই ভুল চুপচাপ বসে যেত।
     */
    public function test_a_sales_invoice_in_boxes_lands_in_pieces(): void
    {
        $invoice = $this->invoiceInBoxes('2', '800');
        $line = $invoice->lines->first();

        $this->assertQty('200', $line->qty);
        $this->assertQty('8', $line->rate);
        $this->assertQty('1600', $line->amount);
        $this->assertQty('1600', $invoice->fresh()->total);
    }

    /** লাইনটা মনে রাখে কীভাবে লেখা হয়েছিল — ছাপার জন্য। */
    public function test_the_invoice_line_remembers_the_pack(): void
    {
        $line = $this->invoiceInBoxes('2', '800')->lines->first();

        $this->assertQty('2', $line->entered_qty);
        $this->assertSame($this->box->id, (int) $line->entered_unit_id);
    }

    /** একক ছাড়া লাইন আগের মতোই — পুরনো পর্দা ও ইমপোর্ট কিছু টের পায় না। */
    public function test_a_line_without_a_unit_is_untouched(): void
    {
        $invoice = app(SalesInvoiceService::class)->create(
            $this->header(),
            [['product_id' => $this->product->id, 'qty' => '5', 'rate' => '100']],
        );

        $line = $invoice->lines->first();

        $this->assertQty('5', $line->qty);
        $this->assertQty('100', $line->rate);
        $this->assertNull($line->entered_qty);
        $this->assertNull($line->entered_unit_id);
    }

    // ── সরাসরি বিক্রি — চালান থেকে বিল ────────────────────────────

    /**
     * চালান থেকে বিলে গিয়ে সংখ্যাটা দ্বিতীয়বার ভাগ হয় না।
     *
     * হলে ১ বাক্স ১০০ পিস না হয়ে ১ পিস হত — মজুদ কমত ঠিকই, একশো ভাগের
     * এক ভাগ, আর কেউ বুঝত না টাকাটা কোথায় গেল।
     */
    public function test_a_direct_sale_converts_once_not_twice(): void
    {
        $sale = $this->directSaleOfOneBox();

        $this->assertQty('100', $sale['challan']->lines->first()->delivered_qty);
        $this->assertQty('100', $sale['invoice']->lines->first()->qty);
        $this->assertQty('8', $sale['invoice']->lines->first()->rate);
    }

    /** ক্রেতার হাতের বিলে "১ বাক্স" ছাপা থাকে, "১০০ পিস" নয়। */
    public function test_the_pack_survives_the_hop_to_the_invoice(): void
    {
        $line = $this->directSaleOfOneBox()['invoice']->lines->first();

        $this->assertQty('1', $line->entered_qty);
        $this->assertSame($this->box->id, (int) $line->entered_unit_id);
    }

    // ── ক্রয় ─────────────────────────────────────────────────────

    public function test_a_purchase_order_in_boxes_lands_in_pieces(): void
    {
        $order = app(PurchaseOrderService::class)->create(
            [
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString(),
            ],
            [[
                'product_id' => $this->product->id,
                'ordered_qty' => '3',
                'rate' => '500',
                'unit_id' => $this->box->id,
            ]],
        );

        $line = $order->lines->first();

        $this->assertQty('300', $line->ordered_qty);
        $this->assertQty('5', $line->rate);
        $this->assertQty('1500', $line->amount);
    }

    /**
     * বাক্সে বিল তুললে পণ্যের বিক্রয়মূল্যও পিসের দামেই বসে।
     *
     * না নামালে মাস্টারে দাম ১০০ গুণ বসত, আর পরদিন কাউন্টারে প্রতিটা
     * পিস বাক্সের দামে বিক্রি হত — ভুলটা ধরা পড়ত ক্রেতার চিৎকারে।
     */
    public function test_a_bill_in_boxes_sets_a_piece_sales_price(): void
    {
        $bill = app(PurchaseBillService::class)->create(
            ['supplier_id' => $this->supplier->id, 'trx_date' => now()->toDateString()],
            [[
                'product_id' => $this->product->id,
                'qty' => '2',
                'rate' => '500',
                'sales_price' => '900',
                'unit_id' => $this->box->id,
            ]],
        );

        $line = $bill->lines->first();

        $this->assertQty('200', $line->qty);
        $this->assertQty('5', $line->rate);
        $this->assertQty('9', $line->sales_price);
    }

    // ── কাগজ ─────────────────────────────────────────────────────

    /**
     * ছাপা বিলে "২ বাক্স @ ৮০০" — "২০০ পিস @ ৮" নয়।
     *
     * ক্রেতা যা চেয়েছিলেন কাগজে সেটাই থাকতে হবে, নাহলে তিনি মেলাতে
     * পারেন না আর দোকানে তর্ক হয়। টাকার অঙ্কটা দুইভাবেই এক — সেটাই
     * প্রমাণ যে ভেতরের হিসাব পিসেই চলেছে।
     */
    public function test_the_printed_invoice_speaks_in_boxes(): void
    {
        $seen = $this->printed('sales.print.invoice', $this->invoiceInBoxes('2', '800'));
        $line = $seen['doc']->lines[0];

        $this->assertSame('2', $line['qty']);
        $this->assertSame($this->box->name(), $line['unit']);
        $this->assertSame('800.00', $line['rate']);
        $this->assertSame('1,600.00', $line['amount']);
    }

    /** একক ছাড়া লাইনের কাগজ আগের মতোই — পণ্যের নিজের এককে। */
    public function test_an_unpacked_line_prints_as_it_always_did(): void
    {
        $invoice = app(SalesInvoiceService::class)->create(
            $this->header(),
            [['product_id' => $this->product->id, 'qty' => '4', 'rate' => '25']],
        );

        $seen = $this->printed('sales.print.invoice', $invoice);
        $line = $seen['doc']->lines[0];

        $this->assertSame('4', $line['qty']);
        $this->assertSame($this->piece->name(), $line['unit']);
        $this->assertSame('25.00', $line['rate']);
    }

    // ── গুদাম ────────────────────────────────────────────────────

    public function test_a_transfer_in_boxes_moves_pieces(): void
    {
        $to = Warehouse::query()->where('id', '!=', $this->warehouse->id)->first();

        if ($to === null) {
            $this->markTestSkipped('ডেমোতে দ্বিতীয় গুদাম নেই।');
        }

        $transfer = app(StockTransferService::class)->create(
            [
                'from_warehouse_id' => $this->warehouse->id,
                'to_warehouse_id' => $to->id,
                'trx_date' => now()->toDateString(),
            ],
            [[
                'product_id' => $this->product->id,
                'qty' => '1',
                'unit_id' => $this->box->id,
            ]],
        );

        $line = $transfer->lines->first();

        $this->assertQty('100', $line->qty);
        $this->assertQty('1', $line->entered_qty);
    }

    // ── সহায়ক ────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function header(): array
    {
        return [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
        ];
    }

    private function invoiceInBoxes(string $qty, string $rate)
    {
        return app(SalesInvoiceService::class)->create(
            $this->header(),
            [[
                'product_id' => $this->product->id,
                'qty' => $qty,
                'rate' => $rate,
                'unit_id' => $this->box->id,
            ]],
        );
    }

    /**
     * এক বাক্স — ডেমো গুদামে যতটা আছে তার ভেতরে।
     *
     * @return array<string, mixed>
     */
    private function directSaleOfOneBox(): array
    {
        return app(DirectSaleService::class)->complete(
            [...$this->header(), 'paid' => '0'],
            [[
                'product_id' => $this->product->id,
                'qty' => '1',
                'rate' => '800',
                'unit_id' => $this->box->id,
            ]],
        );
    }
}
