<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\GatePass;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\DeliveryStage;
use App\Modules\Sales\Services\DeliveryStageService;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Sales\Services\SalesOrderService;
use App\Modules\Sales\Services\SalesReturnService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * একটা বিক্রির একটাই নম্বর — মালিকের সিদ্ধান্ত, ২৯ সেপ্টেম্বর ২০২৬ ([[SaleNumber]])।
 *
 * ── ⛔ কী ছিল ─────────────────────────────────────────────────────────
 * একটা বিক্রিতে চার সারির চার নম্বর: চালান DC-0012, বিল INV-0031, গেট পাস GP-0007, ফেরত
 * SRT-0002। দোকানদার ফোনে একটা নম্বর বললে কেউ জানত না কোন কাগজের কথা, আর চারটা
 * মেলাতে হত হাতে।
 *
 * ── ⭐ এখন ─────────────────────────────────────────────────────────────
 * নম্বর জন্মায় DO বা সরাসরি বিক্রিতে (S-0001); চালান, গেট পাস, বিল — সবাই সেই নম্বর।
 * ফেরতের নিজের নম্বর (SR), বিক্রির নম্বর তাতে সূত্র হিসেবে। একই বিক্রিতে একই ধরনের দ্বিতীয় কাগজ S-0012/2; প্রথমটা লেজ ছাড়া। ⓘ উদ্ধৃতি আর
 * আদেশের নিজের নম্বর থাকে (QT, SO)।
 */
final class OneSaleCarriesOneNumberTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private Product $product;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail();
        $this->customer = Customer::query()->firstOrFail();
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    /** ⭐ DO-তে জন্ম: চালানের নম্বরই বিক্রির নম্বর, S সারি থেকে; দুই বিক্রি দুই নম্বর। */
    public function test_a_do_begins_a_sale_on_the_s_series(): void
    {
        $a = $this->challan();
        $b = $this->challan();

        $this->assertMatchesRegularExpression('/^S-\d+$/', (string) $a->document_no, '⛔ চালান S সারিতে নয়।');
        $this->assertSame($a->document_no, $a->sale_no, '⛔ প্রথম চালানে লেজ বসেছে, বা বিক্রির নম্বর লেখা হয়নি।');
        $this->assertNotSame($a->sale_no, $b->sale_no, '⛔ দুইটা আলাদা বিক্রি একই নম্বর পেয়েছে।');
    }

    /** ⭐ চালান → গেট পাস → বিল: একটাই নম্বর; ⓘ ফেরত নিজের নম্বরে, বিক্রির নম্বর সূত্র। */
    public function test_gate_pass_and_bill_share_the_number_and_a_return_refers_to_it(): void
    {
        $challan = app(DeliveryChallanService::class)->confirm($this->challan());
        $saleNo = (string) $challan->sale_no;

        app(DeliveryStageService::class)->move($challan, DeliveryStage::DISPATCHED);
        $pass = GatePass::query()->where('delivery_challan_id', $challan->id)->firstOrFail();
        $this->assertSame($saleNo, (string) $pass->document_no, '⛔ গেট পাস নিজের সারির নম্বর পেয়েছে, বিক্রির নয়।');
        $this->assertSame($saleNo, (string) $pass->sale_no);

        $invoice = $this->billFor($challan);
        $this->assertSame($saleNo, (string) $invoice->document_no, '⛔ বিল চালানের নম্বর পায়নি।');
        $this->assertSame($saleNo, (string) $invoice->sale_no);

        app(SalesInvoiceService::class)->confirm($invoice->fresh());
        $return = $this->returnOf($invoice->fresh('lines'));
        // ⓘ ফেরতের নিজের নম্বর, বিক্রির নম্বর সূত্র হিসেবে (মালিক, ২৯ সেপ্টেম্বর ২০২৬)
        $this->assertNotSame($saleNo, (string) $return->document_no, '⛔ ফেরত বিক্রির নম্বর নিয়েছে — ফেরতের নিজের নম্বর চাই।');
        $this->assertStringStartsNotWith($saleNo, (string) $return->document_no);
        $this->assertSame($saleNo, (string) $return->sale_no, '⛔ ফেরতে বিক্রির নম্বর সূত্র হিসেবে নেই।');
    }

    /** ⭐ একই বিক্রিতে দ্বিতীয় গেট পাস (পৌঁছায়নি, আবার রওনা) — S-…/2, প্রথমটা অক্ষত। */
    public function test_a_second_paper_of_the_same_kind_gets_a_slash_two(): void
    {
        $challan = app(DeliveryChallanService::class)->confirm($this->challan());
        $stages = app(DeliveryStageService::class);

        $stages->move($challan, DeliveryStage::DISPATCHED);
        $stages->move($challan, DeliveryStage::FAILED, ['note' => 'দোকান বন্ধ']);
        $stages->move($challan, DeliveryStage::DISPATCHED);

        $numbers = GatePass::query()->where('delivery_challan_id', $challan->id)->orderBy('id')->pluck('document_no')->all();

        $this->assertSame([$challan->sale_no, $challan->sale_no.'/2'], $numbers,
            '⛔ দ্বিতীয় গেট পাস বিক্রির নম্বর থেকে /2 পায়নি।');
    }

    /** ⭐ এক আদেশের দুই DO — একই বিক্রি, দ্বিতীয় চালান /2; ⓘ আদেশ নিজের SO নম্বরেই থাকে। */
    public function test_two_dos_of_one_order_share_the_sale_and_the_order_keeps_its_own_number(): void
    {
        $orders = app(SalesOrderService::class);
        $order = $orders->confirm($orders->create([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
        ], [['product_id' => $this->product->id, 'ordered_qty' => '10', 'rate' => '10']]));

        $this->assertStringStartsNotWith('S-', (string) $order->document_no, '⛔ আদেশ বিক্রির নম্বর নিয়েছে।');

        $line = $order->lines()->firstOrFail();
        $first = $this->challan(['sales_order_id' => $order->id], ['sales_order_line_id' => $line->id, 'delivered_qty' => '4']);
        $second = $this->challan(['sales_order_id' => $order->id], ['sales_order_line_id' => $line->id, 'delivered_qty' => '3']);

        $this->assertSame($first->sale_no, $second->sale_no, '⛔ একই আদেশের দ্বিতীয় DO নতুন বিক্রি হয়ে গেছে।');
        $this->assertSame($first->sale_no, $first->document_no);
        $this->assertSame($first->sale_no.'/2', $second->document_no, '⛔ দ্বিতীয় চালান /2 পায়নি।');
    }

    /** ⭐ হাতে লেখা বিক্রি নম্বর বসে; ⛔ অন্য বিক্রির নম্বর আবার নেওয়া যায় না। */
    public function test_a_hand_written_sale_number_is_kept_and_cannot_repeat(): void
    {
        $this->assertSame('HAND-77', (string) $this->challan(['document_no' => 'HAND-77'])->sale_no);

        $before = DeliveryChallan::query()->count();

        try {
            $this->challan(['document_no' => 'HAND-77']);
            $this->fail('⛔ একই হাতে লেখা নম্বরে দ্বিতীয় বিক্রি বসেছে।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('challan_no', $e->errors());
        }

        $this->assertSame($before, DeliveryChallan::query()->count());
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $extra
     * @param  array<string, mixed>  $line
     */
    private function challan(array $extra = [], array $line = []): DeliveryChallan
    {
        return app(DeliveryChallanService::class)->create([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
            'own_transport' => true,
            ...$extra,
        ], [['product_id' => $this->product->id, 'delivered_qty' => '5', 'rate' => '10', ...$line]]);
    }

    private function billFor(DeliveryChallan $challan): SalesInvoice
    {
        $line = $challan->fresh('lines')->lines->firstOrFail();

        return app(SalesInvoiceService::class)->create([
            'customer_id' => $challan->customer_id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
        ], [[
            'product_id' => $this->product->id,
            'delivery_challan_line_id' => $line->id,
            'qty' => '5',
            'rate' => '10',
        ]]);
    }

    private function returnOf(SalesInvoice $invoice): SalesReturn
    {
        return app(SalesReturnService::class)->create([
            'customer_id' => $invoice->customer_id,
            'warehouse_id' => $this->warehouse->id,
            'sales_invoice_id' => $invoice->id,
            'reason_code_id' => ReasonCode::query()->inContext(ReasonCode::SALES_RETURN)->value('id'),
            'trx_date' => now()->toDateString(),
        ], [[
            'product_id' => $this->product->id,
            'sales_invoice_line_id' => $invoice->lines->first()->id,
            'qty' => '1',
        ]]);
    }
}
