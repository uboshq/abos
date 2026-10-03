<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\NumberSeries;
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
 * ফেরতের নিজের নম্বর (SR), বিক্রির নম্বর তাতে সূত্র হিসেবে। ⓘ উদ্ধৃতি আর আদেশের নিজের নম্বর থাকে (QT, SO)।
 *
 * ── ⭐ ২ অক্টোবর ২০২৬ থেকে — কাগজের নিজের উপসর্গ ──────────────────────
 * মালিক: *"INV-0154 ↔ CHA-0154"*। বিক্রির নম্বর S-0154-ই থাকে (`sale_no`), কাগজে তার লেজ নিজের উপসর্গে:
 * চালান CHA-0154, বিল INV-0154, গেট পাস GP-0154। একই ধরনের দ্বিতীয় কাগজ CHA-0154-2; প্রথমটা লেজ ছাড়া।
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

    /** ⭐ DO-তে জন্ম: বিক্রির নম্বর S সারি থেকে, চালান সেই নম্বরের লেজে CHA; দুই বিক্রি দুই নম্বর। */
    public function test_a_do_begins_a_sale_on_the_s_series(): void
    {
        $a = $this->confirmed();
        $b = $this->confirmed();

        $this->assertMatchesRegularExpression('/^S-\d+$/', (string) $a->sale_no, '⛔ বিক্রির নম্বর S সারিতে নয়।');
        $this->assertSame('CHA'.substr((string) $a->sale_no, 1), (string) $a->document_no,
            '⛔ চালান বিক্রির নম্বরের লেজে CHA পায়নি (INV-0154 ↔ CHA-0154)।');
        $this->assertNotSame($a->sale_no, $b->sale_no, '⛔ দুইটা আলাদা বিক্রি একই নম্বর পেয়েছে।');
    }

    /** ⭐ নম্বর সিরিজের পর্দায় চালানের উপসর্গ বদলালে পরের চালান নতুন উপসর্গে — পুরনোটা অক্ষত। */
    public function test_the_challan_prefix_comes_from_its_own_series(): void
    {
        $before = $this->confirmed();
        $this->confirmed();

        NumberSeries::query()->where('company_id', CompanyContext::id())->where('doc_type', 'DC')->update(['prefix' => 'DLV']);
        $after = $this->confirmed();

        $this->assertStringStartsWith('DLV-', (string) $after->document_no, '⛔ চালানের উপসর্গ সিরিজ থেকে আসেনি।');
        $this->assertStringStartsWith('CHA-', (string) $before->fresh()->document_no, '⛔ পুরনো চালানের নম্বর বদলে গেছে।');
    }

    /** ⭐ চালান → গেট পাস → বিল: একটাই নম্বর; ⓘ ফেরত নিজের নম্বরে, বিক্রির নম্বর সূত্র। */
    public function test_gate_pass_and_bill_share_the_number_and_a_return_refers_to_it(): void
    {
        $challan = $this->confirmed();
        $saleNo = (string) $challan->sale_no;

        app(DeliveryStageService::class)->move($challan, DeliveryStage::DISPATCHED);
        $pass = GatePass::query()->where('delivery_challan_id', $challan->id)->firstOrFail();
        $tail = substr($saleNo, 1);
        $this->assertSame('GP'.$tail, (string) $pass->document_no, '⛔ গেট পাস বিক্রির নম্বর পায়নি।');
        $this->assertSame($saleNo, (string) $pass->sale_no);

        // ⓘ রওনাতেই বিল ([[DispatchBill]]) — সেটাই এই বিক্রির বিল
        $invoice = SalesInvoice::query()->where('sale_no', $saleNo)->firstOrFail();
        $this->assertSame('INV'.$tail, (string) $invoice->document_no, '⛔ বিল চালানের নম্বর পায়নি (INV-0154 ↔ CHA-0154)।');
        $this->assertSame('CHA'.$tail, (string) $challan->document_no);

        $return = $this->returnOf($invoice->fresh('lines'));
        // ⓘ ফেরতের নিজের নম্বর, বিক্রির নম্বর সূত্র হিসেবে (মালিক, ২৯ সেপ্টেম্বর ২০২৬)
        $this->assertNotSame($saleNo, (string) $return->document_no, '⛔ ফেরত বিক্রির নম্বর নিয়েছে — ফেরতের নিজের নম্বর চাই।');
        $this->assertStringStartsNotWith($saleNo, (string) $return->document_no);
        $this->assertSame($saleNo, (string) $return->sale_no, '⛔ ফেরতে বিক্রির নম্বর সূত্র হিসেবে নেই।');
    }

    /** ⭐ একই বিক্রিতে দ্বিতীয় গেট পাস (পৌঁছায়নি, আবার রওনা) — GP-…-2, প্রথমটা অক্ষত। */
    public function test_a_second_paper_of_the_same_kind_gets_a_dash_two(): void
    {
        $challan = app(DeliveryChallanService::class)->confirm($this->challan());
        $stages = app(DeliveryStageService::class);

        $stages->move($challan, DeliveryStage::DISPATCHED);
        $stages->move($challan, DeliveryStage::FAILED, ['note' => 'দোকান বন্ধ']);
        $stages->move($challan, DeliveryStage::DISPATCHED);

        $numbers = GatePass::query()->where('delivery_challan_id', $challan->id)->orderBy('id')->pluck('document_no')->all();

        $gp = 'GP'.substr((string) $challan->sale_no, 1);
        $this->assertSame([$gp, $gp.'-2'], $numbers, '⛔ দ্বিতীয় গেট পাস -2 পায়নি।');
    }

    /** ⭐ এক আদেশের দুই DO — একই বিক্রি, দ্বিতীয় চালান -2; ⓘ আদেশ নিজের SO নম্বরেই থাকে। */
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
        $first = $this->confirmed(['sales_order_id' => $order->id], ['sales_order_line_id' => $line->id, 'delivered_qty' => '4']);
        $second = $this->confirmed(['sales_order_id' => $order->id], ['sales_order_line_id' => $line->id, 'delivered_qty' => '3']);

        $this->assertSame($first->sale_no, $second->sale_no, '⛔ একই আদেশের দ্বিতীয় DO নতুন বিক্রি হয়ে গেছে।');
        $cha = 'CHA'.substr((string) $first->sale_no, 1);
        $this->assertSame($cha, (string) $first->document_no);
        $this->assertSame($cha.'-2', (string) $second->document_no, '⛔ দ্বিতীয় চালান -2 পায়নি (CHA-0154-2)।');
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

    /*
     * ⭐ খসড়ার নিজের ক্রম — মালিক, ২ অক্টোবর ২০২৬: *"খসড়ার নম্বর আলাদা (DRF-0001)। আসল INV/CHA নম্বর বসে কেবল
     * নিশ্চিতের মুহূর্তে — সরকারি ক্রমে কোনো ফাঁক থাকে না"*।
     */
    public function test_a_draft_carries_a_draft_number_and_no_sale_number(): void
    {
        $draft = $this->challan();

        $this->assertMatchesRegularExpression('/^DRF-\d+$/', (string) $draft->document_no, '⛔ খসড়া চালান DRF নম্বর পায়নি।');
        $this->assertNull($draft->sale_no, '⛔ খসড়া বিক্রির আসল নম্বর খরচ করেছে।');

        $confirmed = app(DeliveryChallanService::class)->confirm($draft);

        $this->assertMatchesRegularExpression('/^S-\d+$/', (string) $confirmed->sale_no);
        $this->assertSame('CHA'.substr((string) $confirmed->sale_no, 1), (string) $confirmed->document_no,
            '⛔ নিশ্চিতের মুহূর্তে আসল CHA নম্বর বসেনি।');
    }

    /** ⛔ মোছা খসড়া S-ক্রমে ফাঁক রাখে না — পরের নিশ্চিত বিক্রি ঠিক পরের নম্বর পায়। */
    public function test_a_discarded_draft_leaves_no_gap_in_the_sale_series(): void
    {
        $first = $this->confirmed();

        $dropped = $this->challan();
        app(DeliveryChallanService::class)->cancel($dropped, 'ক্রেতা আসেননি');

        $next = $this->confirmed();

        $n = fn (DeliveryChallan $c) => (int) substr((string) $c->sale_no, 2);
        $this->assertSame($n($first) + 1, $n($next), '⛔ বাতিল খসড়া বিক্রির ক্রমে ফাঁক রেখে গেছে।');
    }

    /** ⭐ চালানের বিল চালানের লেজেই — INV-0154 ↔ CHA-0154। */
    public function test_a_bill_from_a_challan_shares_its_number(): void
    {
        $challan = $this->challan();
        $invoices = app(\App\Modules\Sales\Services\SalesInvoiceService::class);

        $challan = app(DeliveryChallanService::class)->confirm($challan);
        $invoice = $invoices->create([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
        ], [[
            'product_id' => $this->product->id,
            'delivery_challan_line_id' => $challan->lines->first()->id,
            'qty' => '5',
            'rate' => '10',
        ]]);

        $tail = substr((string) $challan->sale_no, 1);
        $this->assertSame('INV'.$tail, (string) $invoice->document_no, '⛔ চালানের বিলে INV-নম্বর চালানের লেজে নয়।');
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $extra
     * @param  array<string, mixed>  $line
     */
    private function confirmed(array $extra = [], array $line = []): DeliveryChallan
    {
        return app(DeliveryChallanService::class)->confirm($this->challan($extra, $line));
    }

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
