<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\DeliveryStage;
use App\Modules\Sales\Services\DeliveryStageService;
use App\Modules\Sales\Services\DirectSaleService;
use App\Modules\Sales\Services\SaleEditor;
use App\Modules\Sales\Services\SalesReturnService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * নিশ্চিত বিক্রি শোধরানোর পথ ছিল না — মালিক, ২ অক্টোবর ২০২৬ ([[docs/বিক্রয়ের কাজের ধারা — ২ অক্টোবর.md]] §৪)।
 *
 * ── ⛔ কী ছিল ─────────────────────────────────────────────────────────
 * নিশ্চিতের পরে একমাত্র পথ ছিল বাতিল — নতুন নিয়মে সেটাও বন্ধ। ক্রেতা "দুইটা কম দিন" বললে কিছুই করার থাকত না।
 *
 * ── ⭐ এখন ─────────────────────────────────────────────────────────────
 * *"ইনভয়েসের পরে, গেট পাসের আগে: কেবল সম্পাদনা — খাতার এন্ট্রি উল্টে নতুন বসে, নম্বর একই, অডিটে আগে-পরে দুইটাই"*।
 * ([[SaleEditor]]) — গেট পাস, ফেরত বা ক্রেডিট নোট থাকলে নয়।
 */
final class AConfirmedSaleCouldNotBeCorrectedTest extends TestCase
{
    use RefreshDatabase;

    private Product $biscuit;

    private Warehouse $warehouse;

    /** @var array<string, mixed> */
    private array $data;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->biscuit = Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->data = [
            'customer_id' => Customer::query()->where('name_en', 'Rahim Traders')->value('id'),
            'warehouse_id' => $this->warehouse->id,
            'own_transport' => '1',
        ];
    }

    /** ⭐ পরিমাণ বদলাল — একই নম্বর, নতুন মোট, মাল আর খাতা নতুন পরিমাণে, কোনো দ্বিতীয় কাগজ নয় */
    public function test_an_edit_keeps_the_numbers_and_rewrites_goods_and_books(): void
    {
        $before = $this->onHand();
        $sale = $this->sell('2');
        $challan = $this->challanOf($sale);

        $edited = app(SaleEditor::class)->edit($sale, $this->data, $this->lines('5'));

        $this->assertSame(DocumentStatus::CONFIRMED, $edited->status);
        $this->assertSame($sale->document_no, $edited->document_no, '⛔ সম্পাদনায় বিলের নম্বর বদলেছে।');
        $this->assertSame($challan->document_no, $this->challanOf($edited)->document_no, '⛔ চালানের নম্বর বদলেছে।');
        $this->assertSame(0, bccomp('50', (string) $edited->total, 4), '⛔ নতুন মোট বসেনি।');
        $this->assertSame(1, SalesInvoice::query()->where('sale_no', $sale->sale_no)->count(), '⛔ দ্বিতীয় বিল জন্মেছে।');
        $this->assertSame(1, DeliveryChallan::query()->where('sale_no', $sale->sale_no)->count(), '⛔ দ্বিতীয় চালান জন্মেছে।');
        $this->assertSame(0, bccomp(bcsub($before, '5', 4), $this->onHand(), 4), '⛔ মাল নতুন পরিমাণে বেরোয়নি (দুইবার বা পুরনোটা)।');
        $this->assertSame(0, bccomp('50', $this->receivableOf($edited), 4), '⛔ খাতায় ক্রেতার দেনা নতুন মোট নয়।');
        $this->assertTrue(
            DB::table('audit_trails')->where('auditable_type', SalesInvoice::class)->where('auditable_id', $sale->id)->where('action', 'edited')->exists(),
            '⛔ অডিটে "সম্পাদিত" লেখা নেই।',
        );
    }

    /** ⛔ গেট পাসের পরে নয় — তখন কেবল ফেরত বা ক্রেডিট নোট */
    public function test_no_edit_after_the_gate_pass(): void
    {
        $sale = $this->sell('2');
        app(DeliveryStageService::class)->move($this->challanOf($sale), DeliveryStage::DISPATCHED);

        $this->assertRefused(fn () => app(SaleEditor::class)->edit($sale, $this->data, $this->lines('1')));
        $this->assertSame(0, bccomp('20', (string) $sale->fresh()->total, 4));
    }

    /** ⛔ ফেরত থাকলে নয় */
    public function test_no_edit_once_something_came_back(): void
    {
        $sale = $this->sell('3');

        app(SalesReturnService::class)->create([
            'customer_id' => $sale->customer_id,
            'warehouse_id' => $this->warehouse->id,
            'sales_invoice_id' => $sale->id,
            'reason_code_id' => ReasonCode::query()->inContext(ReasonCode::SALES_RETURN)->value('id'),
            'trx_date' => now()->toDateString(),
        ], [['product_id' => $this->biscuit->id, 'sales_invoice_line_id' => $sale->lines()->value('id'), 'qty' => '1']]);

        $this->assertRefused(fn () => app(SaleEditor::class)->edit($sale, $this->data, $this->lines('1')));
    }

    /** ⛔ মাঝপথে আটকালে কিছুই বদলায় না — অর্ধেক-উল্টানো বিক্রি নয় */
    public function test_a_failed_edit_changes_nothing(): void
    {
        $sale = $this->sell('2');
        $stock = $this->onHand();
        $books = $this->receivableOf($sale);

        $this->assertRefused(fn () => app(SaleEditor::class)->edit($sale, $this->data, $this->lines('999999')), null);

        $this->assertSame(DocumentStatus::CONFIRMED, $sale->fresh()->status, '⛔ আটকানো সম্পাদনায় বিল খসড়ায় পড়ে আছে।');
        $this->assertSame(0, bccomp($stock, $this->onHand(), 4), '⛔ আটকানো সম্পাদনায় মাল ফিরে গেছে।');
        $this->assertSame(0, bccomp($books, $this->receivableOf($sale), 4), '⛔ আটকানো সম্পাদনায় খাতা উল্টে আছে।');
    }

    /** ⓘ বিলের পাতায় বোতাম কেবল সম্পাদনা চললে; কাউন্টার ?edit= পর্দা খোলে সম্পাদনার ব্যানারসহ */
    public function test_the_pages_offer_the_edit_only_while_it_is_allowed(): void
    {
        $sale = $this->sell('2');

        $this->get(route('sales.invoice.show', $sale))->assertOk()->assertSee('data-edit-sale', false);
        $this->get(route('sales.direct.create', ['edit' => $sale->id]))->assertOk()
            ->assertSee('data-editing-sale', false)
            ->assertSee('name="edit_invoice_id" value="'.$sale->id.'"', false);

        app(DeliveryStageService::class)->move($this->challanOf($sale), DeliveryStage::DISPATCHED);

        $this->get(route('sales.invoice.show', $sale))->assertOk()->assertDontSee('data-edit-sale', false);
        $this->get(route('sales.direct.create', ['edit' => $sale->id]))->assertOk()->assertDontSee('data-editing-sale', false);
    }

    /**
     * ⭐ পেন্ডিং থেকে খোলা "ডেলিভারির অপেক্ষায়" ব্যানারেও ✎ — গেট পাসের আগে আছে, পরে নেই; একই ব্যবহারকারী, একই বিল
     * (মালিক, ৪ অক্টোবর ২০২৬, 63-এর মাধ্যমে: তিনি "হালনাগাদ করুন" চাপছিলেন আর কিছুই হচ্ছিল না)।
     */
    public function test_the_delivery_banner_offers_the_edit_until_the_gate_pass(): void
    {
        $sale = $this->sell('2');
        $banner = fn () => (string) $this->get(route('sales.direct.create', ['draft' => $sale->id]))->assertOk()->getContent();

        $before = $banner();
        $this->assertStringContainsString('data-delivery-banner', $before, 'প্রস্তুতিটাই ভুল — বিলটা ডেলিভারির অপেক্ষার ব্যানারে খোলেনি।');
        $this->assertStringContainsString('data-edit-sale', $before, '⛔ গেট পাসের আগে ব্যানারে সম্পাদনার বোতাম নেই।');
        $this->assertStringContainsString(e(route('sales.direct.create', ['edit' => $sale->id])), $before, '⛔ বোতামটা ?edit= পথে যায় না।');

        app(DeliveryStageService::class)->move($this->challanOf($sale), DeliveryStage::DISPATCHED);

        $this->assertStringNotContainsString('data-edit-sale', $banner(), '⛔ গেট পাসের পরেও ব্যানারে সম্পাদনার বোতাম।');
    }

    /**
     * ⭐ সম্পাদনা ফিরে এলে কারণটা পাতার মাথায়, লাল — 63, ৪ অক্টোবর ২০২৬ (মালিক "edit hoy na" বলছিলেন, কারণ চাপা ছিল)।
     */
    public function test_a_refused_edit_says_why_at_the_top(): void
    {
        $sale = $this->sell('2');
        $edit = route('sales.direct.create', ['edit' => $sale->id]);

        $this->get($edit)->assertOk()->assertDontSee('data-edit-refused', false);

        $this->from($edit)->post(route('sales.direct.store'), ['edit_invoice_id' => $sale->id, 'lines' => []])
            ->assertRedirect($edit);

        $this->get($edit)->assertOk()->assertSee('data-edit-refused', false);
    }

    /**
     * ⭐ দলের খাতা তাজা — সম্পাদিত বিলের কেবল শেষ রূপ; আগের সারি আর উল্টো সারি খাতায় থাকে কিন্তু দেখায় না,
     * বকেয়া একই (মালিক, ৪ অক্টোবর ২০২৬, INV-0002)। ⛔ বাতিল বিলের উল্টো সারি এতে বাদ যায় না।
     */
    public function test_the_party_ledger_shows_only_the_last_form_of_an_edited_bill(): void
    {
        $sale = $this->sell('2');
        $edited = app(SaleEditor::class)->edit($sale, $this->data, $this->lines('5'));
        $customer = (int) $this->data['customer_id'];

        $all = \App\Models\LedgerEntry::query()->forParty('customer', $customer)->where('source_id', $sale->id)
            ->where('source_type', 'like', \App\Modules\Sales\Models\SalesInvoice::drillSourceType().'%')->count();
        $shown = \App\Core\Support\PartyLedger::withoutUndoneEdits(\App\Models\LedgerEntry::query()->forParty('customer', $customer))
            ->where('source_id', $sale->id)->where('source_type', 'like', \App\Modules\Sales\Models\SalesInvoice::drillSourceType().'%')->get();

        $this->assertSame(3, $all, 'প্রস্তুতিটাই ভুল — খাতায় আগের, উল্টো আর নতুন, তিন সারি থাকার কথা।');
        $this->assertCount(1, $shown, '⛔ দলের খাতায় সম্পাদিত বিলের আগের বা উল্টো সারি দেখাচ্ছে।');
        $this->assertSame(0, bccomp('50', (string) $shown->first()->debit, 4), '⛔ দেখানো সারিটা শেষ রূপ (৫০) নয়।');

        $everything = \App\Models\LedgerEntry::query()->forParty('customer', $customer)->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as n')->value('n');
        $fresh = \App\Core\Support\PartyLedger::withoutUndoneEdits(\App\Models\LedgerEntry::query()->forParty('customer', $customer))->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as n')->value('n');
        $this->assertSame(0, bccomp((string) $everything, (string) $fresh, 4), '⛔ লুকানোয় বকেয়া বদলে গেছে।');

        // ⓘ পাশে একটা কখনো-না-বদলানো বিল — ছকের এক সারিতে নম্বর যতবার আসে (কাগজের ঘর আর বিবরণ), সম্পাদিতটাও ঠিক ততবার
        $plain = $this->sell('1');
        $page = (string) $this->get(route('customer.show', $customer))->assertOk()->getContent();
        $table = substr($page, (int) strpos($page, 'id="transactions"'));
        $times = fn (string $no) => preg_match_all('/'.preg_quote($no, '/').'(?!\d)/u', $table);

        $this->assertGreaterThan(0, $times($plain->document_no), 'প্রস্তুতিটাই ভুল — না-বদলানো বিলটা ছকে নেই।');
        $this->assertSame($times($plain->document_no), $times($edited->document_no), '⛔ সম্পাদিত বিল ছকে একাধিক সারিতে দেখাচ্ছে।');
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /**
     * ⭐ সম্পাদনায় খুললে বিক্রির নিজের মাল লটে ফেরত গোনা — মালিক, ৭ অক্টোবর ২০২৬ (CHA-0004: "লট Opening-এ 0, সারিতে 1")।
     * ⛔ লটে একটাই ছিল আর এই বিক্রিই নিয়েছে; পর্দা ০ দেখাত। ⓘ নতুন বিক্রির কাউন্টারে আগের মতোই ০ (তালিকায় নেই)।
     */
    public function test_editing_a_sale_counts_its_own_goods_back_into_the_lot(): void
    {
        $unit = \App\Modules\MasterData\Models\Unit::query()->orderBy('id')->firstOrFail();
        $lotted = Product::query()->create(['code' => 'ONE-LOT', 'name_en' => 'One Lot', 'name_bn' => 'One Lot', 'is_active' => true,
            'track_batch' => true, 'unit_id' => $unit->id, 'sale_price' => '10']);
        $batch = app(\App\Modules\Inventory\Services\BatchService::class)->receive(product: $lotted, batchNo: 'Opening');
        app(\App\Modules\Inventory\Services\OpeningStockService::class)->bringIn($lotted, $this->warehouse, '1', '8', now()->subDay(), null, $batch);

        $sale = app(DirectSaleService::class)->complete($this->data,
            [['product_id' => $lotted->id, 'qty' => '1', 'rate' => '10', 'free_qty' => '0', 'batch_id' => $batch->id]])['invoice']->fresh();

        $fresh = $this->get(route('sales.direct.create', ['warehouse_id' => $this->warehouse->id]))->assertOk()->viewData('lots');
        $this->assertArrayNotHasKey((string) $lotted->id, $fresh, 'প্রস্তুতিটাই ভুল — নতুন বিক্রিতে খালি লটটা তালিকায়।');

        $lots = $this->get(route('sales.direct.create', ['warehouse_id' => $this->warehouse->id, 'edit' => $sale->id]))->assertOk()->viewData('lots');
        $mine = collect($lots[(string) $lotted->id] ?? $lots[$lotted->id] ?? [])->firstWhere('id', (string) $batch->id);
        $this->assertNotNull($mine, '⛔ সম্পাদনায় বিক্রির নিজের লটটাই তালিকায় নেই।');
        $this->assertSame(0, bccomp((string) $mine['qty'], '1', 4), '⛔ সম্পাদনায় নিজের নেওয়া ১টা লটে ফেরত গোনা হয়নি।');
    }

    private function sell(string $qty): SalesInvoice
    {
        return app(DirectSaleService::class)->complete($this->data, $this->lines($qty))['invoice']->fresh();
    }

    /** @return list<array<string, mixed>> */
    private function lines(string $qty): array
    {
        return [['product_id' => $this->biscuit->id, 'qty' => $qty, 'rate' => '10', 'free_qty' => '0']];
    }

    private function challanOf(SalesInvoice $invoice): DeliveryChallan
    {
        return $invoice->lines()->firstOrFail()->challanLine->challan;
    }

    private function onHand(): string
    {
        return (string) DB::table('inv_stock_movements')
            ->where('product_id', $this->biscuit->id)->where('warehouse_id', $this->warehouse->id)->sum('floor_change');
    }

    /** বিলের নিজের দাখিলা আর তার উল্টো দাখিলা মিলে ক্রেতার দেনা */
    private function receivableOf(SalesInvoice $invoice): string
    {
        $type = SalesInvoice::drillSourceType();

        return (string) DB::table('ledger_entries')
            ->whereIn('source_type', [$type, $type.':reversal'])
            ->where('source_id', $invoice->id)
            ->where('party_type', 'customer')
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as n')
            ->value('n');
    }

    private function assertRefused(callable $edit, ?string $key = 'edit'): void
    {
        try {
            $edit();
            $this->fail('⛔ সম্পাদনা হয়ে গেল, অথচ হওয়ার কথা নয়।');
        } catch (ValidationException $e) {
            if ($key !== null) {
                $this->assertArrayHasKey($key, $e->errors());
            }
        }
    }
}
