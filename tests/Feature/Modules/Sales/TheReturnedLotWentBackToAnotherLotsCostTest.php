<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\CostLayer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Sales\Services\SalesReturnService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ফেরত আসা লটের খরচ অন্য লটের স্তরে ফিরত — Inventory অডিট ম৭ (বিক্রয় ফেরত), ৫ অক্টোবর ২০২৬।
 *
 * ⛔ বিলের মাল দুই লট থেকে: লট A-র ৩টা (১০ টাকা), লট B-র ২টা (৩০ টাকা)। লট A-র ২টা ফেরত এল — মাল ঠিক লট A-তে ফিরত, কিন্তু
 * খরচ ফিরত টানের উল্টো ক্রমে, অর্থাৎ লট B-র ৩০ টাকার স্তরে। মোট টাকা ঠিক, কিন্তু লট A-র স্তর খালি আর লট B-র স্তরে মাল
 * ছাড়া টাকা — পরে লট A বিক্রিতে "স্তর নেই", লট B-র লাভ ভুল।
 * ⭐ এখন ফেরতের সারির লট বলা থাকলে সেই লটের স্তর আগে ([[CostLayerService::returnToLayers()]] `batch`)।
 */
final class TheReturnedLotWentBackToAnotherLotsCostTest extends TestCase
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

        app(StandardChart::class)->install();
        app(SettingsService::class)->set('customer.credit_limit_enabled', false);

        $this->customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->where('company_id', $company->id)->where('is_active', true)->orderBy('id')->firstOrFail()
            ->replicate(['public_id']);
        $this->product->forceFill(['code' => 'M7R-1', 'barcode' => null, 'name_en' => 'Return lot probe', 'name_bn' => 'ফেরত-লটের নমুনা', 'track_batch' => true])->save();
    }

    public function test_a_returned_lot_puts_its_cost_back_in_its_own_layer(): void
    {
        $a = $this->lot('LOT-A', now()->addMonth()->toDateString(), '3', '10');
        $b = $this->lot('LOT-B', now()->addYear()->toDateString(), '10', '30');

        $invoice = $this->sell('5'); // ⓘ আগে-মেয়াদ: লট A-র ৩টা, লট B-র ২টা
        $this->assertSame(['0', '8'], [$this->left($a), $this->left($b)], 'প্রস্তুতিটাই ভুল — বিক্রি লট ধরে খরচ টানেনি।');

        $returns = app(SalesReturnService::class);
        $returns->confirm($returns->create(
            ['customer_id' => $this->customer->id, 'warehouse_id' => $this->warehouse->id, 'sales_invoice_id' => $invoice->id,
                'trx_date' => now()->toDateString(), 'reason_code_id' => $this->reason()->id],
            [['product_id' => $this->product->id, 'sales_invoice_line_id' => $invoice->lines->first()->id, 'qty' => '2', 'rate' => '50', 'batch_id' => $a->id]],
        ));

        $this->assertSame('2', $this->left($a), '⛔ লট A-র ২টা ফিরল, অথচ তার খরচ লট A-র স্তরে ফেরেনি।');
        $this->assertSame('8', $this->left($b), '⛔ লট A-র ফেরতের খরচ লট B-র স্তরে বসেছে।');
    }

    private function sell(string $qty): SalesInvoice
    {
        $service = app(SalesInvoiceService::class);

        return $service->confirm($service->create(
            ['customer_id' => $this->customer->id, 'warehouse_id' => $this->warehouse->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $this->product->id, 'qty' => $qty, 'rate' => '50']],
        ))->load('lines');
    }

    private function lot(string $no, string $expiry, string $qty, string $cost): Batch
    {
        $batch = Batch::query()->create(['product_id' => $this->product->id, 'batch_no' => $no, 'expiry_date' => $expiry]);
        app(StockService::class)->move(product: $this->product, warehouse: $this->warehouse, sourceType: 'test.opening', sourceId: $batch->id, floor: $qty, batch: $batch);
        app(CostLayerService::class)->receive(product: $this->product, qty: $qty, unitCost: $cost, sourceType: 'test.opening', sourceId: $batch->id, batch: $batch);

        return $batch;
    }

    private function left(Batch $batch): string
    {
        $sum = (string) CostLayer::query()->where('batch_id', $batch->id)->sum('qty_remaining');

        return rtrim(rtrim(bcadd($sum, '0', 4), '0'), '.') ?: '0';
    }

    private function reason(): ReasonCode
    {
        return ReasonCode::query()->inContext(ReasonCode::SALES_RETURN)->where('code', 'DAMAGE')->firstOrFail();
    }
}
