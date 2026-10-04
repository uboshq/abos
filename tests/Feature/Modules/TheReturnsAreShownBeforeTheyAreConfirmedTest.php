<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\Purchase\Models\PurchaseReturn;
use App\Modules\Purchase\Services\DirectPurchaseService;
use App\Modules\Purchase\Services\PurchaseReturnService;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Sales\Services\SalesReturnService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ বিক্রি ফেরত আর ক্রয় ফেরতের "নিশ্চিত করুন"-এর আগে সারাংশ — মালিক, ৪ অক্টোবর ২০২৬: *"sob kichutei"*
 * ([[SalesPaperOverview::salesReturn()]], [[PurchaseReturnOverview]])।
 *
 * দাবি:
 *   সারাংশ ফেরতের নিজের সারি আর মোট দেখায়, আর কিছুই লেখে না (অবস্থা খসড়াই, মজুদ নড়ে না);
 *   ⛔ বিল বা বিক্রির চেয়ে বেশি ফেরত — দরজার নিজের কথায় "নিশ্চিত হবে না" (`whatWouldStopTheConfirm()`);
 *   দুই পাতা সারাংশের ঠিকানা, পপ-আপের খোলস আর "নিশ্চিত" বোতামের চিহ্ন বহন করে।
 */
final class TheReturnsAreShownBeforeTheyAreConfirmedTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->orderBy('id')->firstOrFail();
        $this->product->update(['track_batch' => false]);
    }

    public function test_the_purchase_return_overview_reads_the_paper_and_stops_more_than_was_billed(): void
    {
        $bill = app(DirectPurchaseService::class)->complete(
            ['supplier_id' => Supplier::query()->value('id'), 'warehouse_id' => $this->warehouse->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $this->product->id, 'qty' => '10', 'rate' => '100', 'sales_price' => '150']],
        )['bill'];

        $return = app(PurchaseReturnService::class)->create([
            'supplier_id' => $bill->supplier_id, 'warehouse_id' => $this->warehouse->id,
            'purchase_bill_id' => $bill->id, 'trx_date' => now()->toDateString(),
        ], [['product_id' => $this->product->id, 'qty' => '2', 'purchase_bill_line_id' => $bill->lines->first()->id]]);
        $moves = StockMovement::query()->count();

        $this->post(route('purchase.return.overview', $return))->assertOk()
            ->assertSee('data-overview-blocks="0"', false)
            ->assertSee($bill->document_no)
            ->assertSee('200.00');
        $this->assertSame(DocumentStatus::DRAFT, $return->fresh()->status, '⛔ সারাংশ দেখতে গিয়েই ফেরত নিশ্চিত হয়ে গেল।');
        $this->assertSame($moves, StockMovement::query()->count(), '⛔ সারাংশ দেখতে গিয়েই মজুদ নড়ল।');

        // ⛔ বিলে ১০, ফেরত ১২ — দরজা থামাবে, সারাংশ আগেই বলে
        $return->lines()->update(['qty' => '12']);

        $this->post(route('purchase.return.overview', PurchaseReturn::query()->findOrFail($return->id)))->assertOk()
            ->assertSee('data-overview-blocks="1"', false);

        $this->get(route('purchase.return.show', $return))->assertOk()
            ->assertSee('data-confirm-overview="'.route('purchase.return.overview', $return).'"', false)
            ->assertSee('data-confirm-overview-dialog', false)
            ->assertSee('data-overview-trigger', false);
    }

    public function test_the_sales_return_overview_reads_the_paper_and_stops_more_than_was_sold(): void
    {
        app(StockService::class)->move(
            product: $this->product, warehouse: $this->warehouse, sourceType: 'opening', sourceId: 1,
            floor: '50', date: now()->toDateString(), documentNo: 'TEST-RET-OV',
        );
        $customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $customer->forceFill(['credit_limit' => '1000000'])->save();

        $invoices = app(SalesInvoiceService::class);
        $sale = $invoices->confirm($invoices->create(
            ['customer_id' => $customer->id, 'warehouse_id' => $this->warehouse->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $this->product->id, 'qty' => '5', 'rate' => '100']],
        ));

        $return = app(SalesReturnService::class)->create([
            'customer_id' => $customer->id, 'warehouse_id' => $this->warehouse->id, 'sales_invoice_id' => $sale->id,
            'reason_code_id' => ReasonCode::query()->inContext(ReasonCode::SALES_RETURN)->value('id'),
            'trx_date' => now()->toDateString(),
        ], [['product_id' => $this->product->id, 'sales_invoice_line_id' => $sale->lines()->value('id'), 'qty' => '2']]);
        $moves = StockMovement::query()->count();

        $this->post(route('sales.return.overview', $return))->assertOk()
            ->assertSee('data-overview-blocks="0"', false)
            ->assertSee($sale->document_no)
            ->assertSee(__('sales::overview_confirm.return_total'));
        $this->assertSame(DocumentStatus::DRAFT, $return->fresh()->status, '⛔ সারাংশ দেখতে গিয়েই ফেরত নিশ্চিত হয়ে গেল।');
        $this->assertSame($moves, StockMovement::query()->count(), '⛔ সারাংশ দেখতে গিয়েই মজুদ নড়ল।');

        // ⛔ বেচা ৫, ফেরত ৭ — দরজা থামাবে, সারাংশ আগেই বলে
        $return->lines()->update(['qty' => '7']);

        $this->post(route('sales.return.overview', SalesReturn::query()->findOrFail($return->id)))->assertOk()
            ->assertSee('data-overview-blocks="1"', false);

        $this->get(route('sales.return.show', $return))->assertOk()
            ->assertSee('data-confirm-overview="'.route('sales.return.overview', $return).'"', false)
            ->assertSee('data-confirm-overview-dialog', false)
            ->assertSee('data-overview-trigger', false);
    }
}
