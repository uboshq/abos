<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Inventory\Services\StockTransferService;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * ব্যাচ ও মেয়াদ ছাপা কাগজে।
 *
 * ── কেন এটা সাজসজ্জা নয় ──────────────────────────────────────────────
 * রিকল হলে প্রশ্নটা হয় "আমার পাতাটা কি ওই লটের"। ক্রেতার হাতে যে কাগজ
 * আছে সেটাই একমাত্র উত্তর — দোকানের খাতা তার কাছে নেই। কাগজে লট না
 * থাকলে গোটা রিকল ব্যবস্থাটাই দোকানের ভেতরে আটকে থাকে।
 */
class BatchOnPaperTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private Warehouse $warehouse;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->customer = Customer::query()->firstOrFail();

        $this->product = Product::query()->firstOrFail();
        $this->product->forceFill(['track_batch' => true])->save();
    }

    private function lot(string $batchNo, string $expiry, string $qty): Batch
    {
        $batch = Batch::query()->create([
            'product_id' => $this->product->id,
            'batch_no' => $batchNo,
            'expiry_date' => $expiry,
        ]);

        app(StockService::class)->move(
            product: $this->product,
            warehouse: $this->warehouse,
            sourceType: 'test_opening',
            sourceId: $batch->id,
            floor: $qty,
            batch: $batch,
        );

        return $batch;
    }

    private function sell(string $qty): DeliveryChallan
    {
        $challan = app(DeliveryChallanService::class)->create(
            [
                'customer_id' => $this->customer->id,
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString(),
            ],
            [['product_id' => $this->product->id, 'delivered_qty' => $qty, 'rate' => '100']],
        );

        return app(DeliveryChallanService::class)->confirm($challan);
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

    // ── চালান ────────────────────────────────────────────────────

    /** চালানের প্রতিটা লাইনের নিচে লট ও মেয়াদ। */
    public function test_the_challan_carries_the_lot_and_its_expiry(): void
    {
        $this->lot('B1', now()->addMonths(8)->toDateString(), '50');

        $challan = $this->sell('5');
        $line = $this->printed('sales.print.challan', $challan)['doc']->lines[0];

        $this->assertStringContainsString('B1', $line['note']);
        $this->assertStringContainsString(now()->addMonths(8)->format('m/Y'), $line['note']);
    }

    /**
     * এক লাইন দুই লট থেকে পূরণ হলে দুইটাই লেখা থাকে।
     *
     * একটা লিখলে ক্রেতা ভাবতেন পুরোটা ওই লটের, আর রিকলে অর্ধেক মাল
     * খুঁজে পাওয়া যেত না।
     */
    public function test_two_lots_on_one_line_are_both_printed(): void
    {
        $this->lot('SOON', now()->addMonths(2)->toDateString(), '3');
        $this->lot('LATE', now()->addMonths(9)->toDateString(), '20');

        $challan = $this->sell('5');
        $note = $this->printed('sales.print.challan', $challan)['doc']->lines[0]['note'];

        $this->assertStringContainsString('SOON', $note);
        $this->assertStringContainsString('LATE', $note);
    }

    /** গেটপাসেও — দারোয়ান লট মিলিয়ে দেখেন, দাম দেখেন না। */
    public function test_the_gatepass_carries_the_lot_too(): void
    {
        $this->lot('B1', now()->addMonths(8)->toDateString(), '50');

        $challan = $this->sell('5');
        $line = $this->printed('sales.print.gatepass', $challan)['doc']->lines[0];

        $this->assertStringContainsString('B1', $line['note']);
    }

    // ── বিল ──────────────────────────────────────────────────────

    /**
     * বিলেও লট আসে — চালান থেকে টেনে।
     *
     * মাল বেরোয় চালানে, তাই লটের সিদ্ধান্ত ওখানে লেখা। বিলের লাইন তার
     * চালানের লাইনকে চেনে, আর ওই সুতো ধরেই লটে পৌঁছানো যায়।
     */
    public function test_the_invoice_pulls_the_lot_from_its_challan(): void
    {
        $this->lot('B1', now()->addMonths(8)->toDateString(), '50');

        $challan = $this->sell('5');

        $invoice = app(SalesInvoiceService::class)->create(
            [
                'customer_id' => $this->customer->id,
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString(),
            ],
            [[
                'product_id' => $this->product->id,
                'qty' => '5',
                'rate' => '100',
                'delivery_challan_line_id' => $challan->lines->first()->id,
            ]],
        );

        $line = $this->printed('sales.print.invoice', $invoice)['doc']->lines[0];

        $this->assertStringContainsString('B1', $line['note']);
    }

    // ── লট ছাড়া ব্যবসা ───────────────────────────────────────────

    /**
     * লট ধরা না থাকলে কাগজ অবিকল আগের মতো।
     *
     * ডিপোর চাল-ডাল-সাবানের চালানে একটা খালি লাইন যোগ হলে কাগজটা
     * অকারণে লম্বা হত, আর সরু রোলে প্রতিটা মিলিমিটার দামি।
     */
    public function test_a_business_without_lots_prints_exactly_as_before(): void
    {
        $plain = Product::query()->where('id', '!=', $this->product->id)->firstOrFail();
        $plain->forceFill(['track_batch' => false])->save();

        $challan = app(DeliveryChallanService::class)->create(
            [
                'customer_id' => $this->customer->id,
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString(),
            ],
            [['product_id' => $plain->id, 'delivered_qty' => '2', 'rate' => '100']],
        );

        app(DeliveryChallanService::class)->confirm($challan);

        $line = $this->printed('sales.print.challan', $challan)['doc']->lines[0];

        $this->assertSame('', $line['note']);
    }

    // ── গুদাম বদল ────────────────────────────────────────────────

    /**
     * গুদাম বদলেও লট ধরে ধরে যায়।
     *
     * আগে এখানে লট ছাড়া চলাচল লেখা হত, আর তার দুইটা ফল ছিল: উৎসে
     * লটের যোগফল মোট মজুদের সাথে মিলত না, আর গন্তব্যের মালটা "লট ধরা
     * শুরুর আগের" গণ্য হয়ে চিরকাল অবিক্রেয় থাকত।
     */
    public function test_a_transfer_carries_the_lots_with_it(): void
    {
        $to = Warehouse::query()->whereKeyNot($this->warehouse->id)->first();

        if ($to === null) {
            $this->markTestSkipped('ডেমোতে দ্বিতীয় গুদাম নেই।');
        }

        $batch = $this->lot('B1', now()->addMonths(8)->toDateString(), '50');

        $transfers = app(StockTransferService::class);

        $transfer = $transfers->create(
            [
                'from_warehouse_id' => $this->warehouse->id,
                'to_warehouse_id' => $to->id,
                'trx_date' => now()->toDateString(),
            ],
            [['product_id' => $this->product->id, 'qty' => '10']],
        );

        // dispatch = ট্রাকে উঠল (উৎসে আটকানো), receive = গন্তব্যে নামল
        $transfers->dispatch($transfer);
        $transfers->receive($transfer->fresh());

        $this->assertSame(0, bccomp('10', $batch->fresh()->balance($to), 4), 'গন্তব্যে লটের মাল নেই');

        /*
         * এই স্থানান্তরের কোনো চলাচল লট ছাড়া নয়।
         *
         * গুদামের *সব* চলাচল গোনা হয় না — ডেমো সিডারের পুরনো মালও ওই
         * গুদামে থাকতে পারে, আর সেগুলো লট ধরা শুরুর আগের। প্রথমবার সব
         * গুনে ফেল করেছিলাম, আর সেটা এই পরিবর্তনের দোষ ছিল না।
         */
        $this->assertSame(0, StockMovement::query()
            ->where('source_type', StockTransfer::STOCK_SOURCE)
            ->where('source_id', $transfer->id)
            ->whereNull('batch_id')
            ->where('warehouse_id', $to->id)
            ->count());
    }
}
