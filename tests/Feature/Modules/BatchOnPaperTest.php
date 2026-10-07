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
use Tests\Concerns\PrintsTheStandardPaper;
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
    use PrintsTheStandardPaper;
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

        /*
         * ⓘ এই পরীক্ষা চলতি নকশার কাগজ (`print.document`) মাপে। ⚠️ ২৯ সেপ্টেম্বর ২০২৬ থেকে
         * বিলের ডিফল্ট ক্লাসিক ([[AClassicTableInvoiceCanBeChosenTest]]), তাই নকশাটা এখানে
         * বেঁধে দেওয়া — নইলে পরীক্ষাটা মাপার কাগজই পেত না।
         */
        app(\App\Core\Services\SettingsService::class)->set('sales.print.design.invoice', 'standard');

        /*
         * ⓘ লট ছাপা এখন কোম্পানির পছন্দ — মালিক, ৪ অক্টোবর ২০২৬ (6d582592): বিল আর চালানে লট ডিফল্ট বন্ধ। এই দাবিগুলো
         * "লট চাইলে ঠিক লটটা আসে" মাপে, তাই সুইচ চালু করে নেওয়া; বন্ধ থাকলে কী হয়, সেটা
         * [[test_with_the_switch_off_the_lot_stays_off_the_paper()]]। ⚠️ আগে সুইচ ছিল না, আর তিনটা দাবি খালি নোট পেয়ে লাল ছিল।
         */
        app(\App\Core\Services\SettingsService::class)->set('sales.print.challan_show.lot', true);
        app(\App\Core\Services\SettingsService::class)->set('sales.print.show.lot', true);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->customer = Customer::query()->firstOrFail();

        $this->product = Product::query()->firstOrFail();
        $this->product->forceFill(['track_batch' => true])->save();

        /* ⓘ এই দাবিগুলো সাধারণ কাগজের তথ্য মাপে — নকশা নয় ([[PrintsTheStandardPaper]]) */
        $this->printTheStandardPaper();
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
                // ⓘ মাল কীভাবে যাবে — ছাপার আগে লাগে ([[RequireTransportBeforePrint]])
                'own_transport' => true,
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

    /**
     * ⭐ সুইচ বন্ধ (মালিকের ডিফল্ট) — চালানে লট নেই; গেটপাসে তবু থাকে, কারণ দারোয়ানের কাগজে সুইচই নেই।
     */
    public function test_with_the_switch_off_the_lot_stays_off_the_paper(): void
    {
        app(\App\Core\Services\SettingsService::class)->set('sales.print.challan_show.lot', false);
        $this->lot('B1', now()->addMonths(8)->toDateString(), '50');

        $challan = $this->sell('5');

        $this->assertStringNotContainsString('B1', (string) $this->printed('sales.print.challan', $challan)['doc']->lines[0]['note'],
            '⛔ লট বন্ধ, তবু চালানে লট ছাপা হলো।');
        $this->assertStringContainsString('B1', (string) $this->printed('sales.print.gatepass', $challan)['doc']->lines[0]['note']);
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

        /*
         * ⓘ নিশ্চিত বিল — খসড়ার ছাপা নকশা অনুযায়ীই বন্ধ ([[SalesPrintController::invoice()]]-এর
         * `isNotFinalYet`)। ⚠️ আগে এখানে খসড়াটাই ছাপা হত; পাহারাটা আসার পর পাতা ৩০২ দিত, আর
         * `assertOk()`-এর বার্তা বানাতে গিয়ে "all() on array" — আসল কারণটা ঢাকা পড়ে যেত।
         */
        $service = app(SalesInvoiceService::class);

        $invoice = $service->confirm($service->create(
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
        ));

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
                // ⓘ মাল কীভাবে যাবে — ছাপার আগে লাগে ([[RequireTransportBeforePrint]])
                'own_transport' => true,
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

    /**
     * ⭐ দুই লট মিলিয়ে বদলি পৌঁছায়, আর উৎসে আটকানো শূন্যে ফেরে — পুরো ERP অডিট ⛔১, ৬ অক্টোবর ২০২৬।
     *
     * ⛔ পাঠানোয় আটকানো বসে লট ছাড়া, আর গ্রহণে লট ধরে ছাড়ানো হত; ম১৭-এর লট-পাহারা ঐ লটে ০ পেত, তাই লটের মাল কখনো
     * গন্তব্যে নামত না ("আটকে আছে কেবল 0.0000")।
     */
    public function test_a_transfer_across_two_lots_arrives_and_frees_the_hold(): void
    {
        // ⓘ নিজের গন্তব্য গুদাম — ডেমোর উপর নির্ভর করে পাশ কাটানো নয় ([[ASkippedTestReadsAsAPassingOneTest]])
        $to = Warehouse::query()->create(['code' => 'LOT-TO', 'name_en' => 'Lot destination', 'is_active' => true,
            'branch_id' => $this->warehouse->branch_id]);

        $early = $this->lot('E1', now()->addMonths(3)->toDateString(), '6');
        $late = $this->lot('L1', now()->addMonths(9)->toDateString(), '20');
        $transfers = app(StockTransferService::class);
        $transfer = $transfers->create(
            ['from_warehouse_id' => $this->warehouse->id, 'to_warehouse_id' => $to->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $this->product->id, 'qty' => '10']],
        );

        $transfers->dispatch($transfer);
        $transfers->receive($transfer->fresh());

        $this->assertSame(0, bccomp('10', bcadd($early->fresh()->balance($to), $late->fresh()->balance($to), 4), 4), '⛔ দুই লট মিলিয়ে ১০ পৌঁছায়নি।');
        $this->assertSame(0, bccomp('16', bcadd($early->fresh()->balance($this->warehouse), $late->fresh()->balance($this->warehouse), 4), 4), '⛔ উৎসের লট থেকে ১০ কমেনি।');

        $held = (string) StockMovement::query()->where('product_id', $this->product->id)->where('warehouse_id', $this->warehouse->id)
            ->where('source_type', StockTransfer::STOCK_SOURCE)->where('source_id', $transfer->id)->sum('hold_change');
        $this->assertSame(0, bccomp($held, '0', 4), '⛔ উৎসে আটকানো রয়ে গেল।');
    }
}
