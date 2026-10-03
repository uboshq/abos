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

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

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
