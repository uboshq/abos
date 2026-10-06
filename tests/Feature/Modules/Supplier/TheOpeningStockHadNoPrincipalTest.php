<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Supplier;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\AuditTrail;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\CostLayer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\OpeningStockService;
use App\Modules\MasterData\Models\Unit;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Reports\PrincipalCommissionReport;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * খোলা মজুদ কোন প্রিন্সিপালের, তা কোথাও লেখা থাকত না — মালিক, ৬ অক্টোবর ২০২৬।
 *
 * *"কোন পণ্য কোন প্রিন্সিপালের, খোলা মজুদে সেটা উল্লেখ করে দেওয়ার ব্যবস্থা করো"*। ⛔ "আসল" কমিশনের অংশ গোনা হত কেবল
 * ক্রয়ের স্তর থেকে, তাই চালুর দিনের মাল কারও অংশে আসত না।
 * ⭐ এখন খরচের স্তর জানে মালটা কার (`supplier_id`): ক্রয়ে উৎস থেকে, খোলা মজুদে বাছা, আর আগে বসানো খোলা মজুদে পরেও বসানো যায়
 * ([[OpeningPrincipalController]])।
 */
final class TheOpeningStockHadNoPrincipalTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Branch $a;

    private Supplier $principal;

    private Supplier $other;

    private Warehouse $store;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        Carbon::setTestNow('2026-10-05 10:00:00');

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->a = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'MMS')->firstOrFail();
        CompanyContext::set($this->company->id, $this->a->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();

        $suppliers = Supplier::query()->onlySuppliers()->orderBy('id')->take(2)->get();
        [$this->principal, $this->other] = [$suppliers->first(), $suppliers->last()];
        $this->principal->forceFill(['principal_branch_id' => $this->a->id, 'commission_basis' => 'actual', 'commission_rate' => null,
            'cycle_start_day' => 2, 'cycle_close_day' => 1])->save();
        $this->store = Warehouse::query()->create(['code' => 'OPN-A', 'name_en' => 'Opening store', 'is_active' => true, 'branch_id' => $this->a->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_opening_stock_marked_with_the_principal_counts_in_its_share_and_unmarked_or_others_do_not(): void
    {
        $mine = $this->opened('OPN-MINE', '30', $this->principal->id);
        $nobody = $this->opened('OPN-NONE', '50', null);
        $theirs = $this->opened('OPN-THEIRS', '70', $this->other->id);

        $this->sell($mine, '2');
        $this->sell($nobody, '1');
        $this->sell($theirs, '1');

        $this->assertSame('60.00', $this->share(), '⛔ প্রিন্সিপালের খোলা মজুদের কেনা দাম (২ × ৩০) অংশে নেই, বা অন্যেরটা/বসানো-নেই ঢুকল।');
    }

    public function test_a_principal_set_later_on_old_opening_stock_counts_in_the_next_report(): void
    {
        $soap = $this->opened('OPN-LATE', '40', null);
        $this->sell($soap, '3');
        $this->assertSame('0.00', $this->share(), 'প্রস্তুতিটাই ভুল — বসানোর আগেই অংশে এল।');

        $layer = CostLayer::query()->where('product_id', $soap->id)->where('source_type', 'opening')->firstOrFail();

        $this->get(route('inventory.stock.opening.principal'))->assertOk()->assertSee('OPN-LATE');
        $this->post(route('inventory.stock.opening.principal.update'), ['layer_ids' => [$layer->id], 'supplier_id' => $this->principal->id])
            ->assertSessionHasNoErrors();

        $this->assertSame('120.00', $this->share(), '⛔ পরে বসানো প্রিন্সিপাল পরের রিপোর্টে এল না।');
        $this->assertTrue(AuditTrail::query()->where('auditable_type', CostLayer::class)->where('auditable_id', $layer->id)
            ->where('action', 'opening_principal_set')->exists(), '⛔ প্রিন্সিপাল বসানো অডিটে নেই।');
    }

    public function test_the_bulk_page_touches_only_opening_layers(): void
    {
        $bought = Product::query()->create(['code' => 'OPN-BUY', 'name_en' => 'Bought', 'name_bn' => 'কেনা',
            'unit_id' => Unit::query()->orderBy('id')->firstOrFail()->id, 'is_active' => true]);
        $bill = PurchaseBill::query()->create(['company_id' => $this->company->id, 'branch_id' => $this->a->id,
            'document_no' => 'OPN-PB', 'supplier_id' => $this->other->id, 'trx_date' => '2026-09-20']);
        // ⚠️ ক্রয়ের স্তরের source_id ইচ্ছা করে একটা খোলা মজুদের চলাচলের id-র সমান — উৎসের ধরন না দেখলে পাতা এটাকেও তুলত
        $opened = $this->opened('OPN-TWIN', '20', null);
        $twinId = (int) \App\Modules\Inventory\Models\StockMovement::query()->where('product_id', $opened->id)->where('source_type', 'opening')->value('id');
        $layer = app(\App\Modules\Inventory\Services\CostLayerService::class)->receive(product: $bought, qty: '5', unitCost: '10',
            sourceType: 'purchase_bill', sourceId: $twinId, supplierId: $this->other->id);

        $this->post(route('inventory.stock.opening.principal.update'), ['layer_ids' => [$layer->id], 'supplier_id' => $this->principal->id]);

        $this->assertSame((int) $this->other->id, (int) $layer->fresh()->supplier_id, '⛔ খোলা মজুদের পাতা ক্রয়ের স্তরের প্রিন্সিপাল বদলে দিল।');
    }

    public function test_the_migration_fills_old_purchase_layers_from_their_bill(): void
    {
        $product = Product::query()->create(['code' => 'OPN-OLD', 'name_en' => 'Old buy', 'name_bn' => 'পুরনো কেনা',
            'unit_id' => Unit::query()->orderBy('id')->firstOrFail()->id, 'is_active' => true]);
        $bill = PurchaseBill::query()->create(['company_id' => $this->company->id, 'branch_id' => $this->a->id,
            'document_no' => 'OPN-OLD-PB', 'supplier_id' => $this->principal->id, 'trx_date' => '2026-09-20']);
        // ⓘ মাইগ্রেশনের আগের স্তর — ঘরটা খালি
        $layer = app(\App\Modules\Inventory\Services\CostLayerService::class)->receive(product: $product, qty: '5', unitCost: '10',
            sourceType: 'purchase_bill', sourceId: $bill->id);
        $this->assertNull($layer->fresh()->supplier_id);

        $migration = require base_path('app/Modules/Inventory/Database/Migrations/2027_02_16_100000_a_cost_layer_knows_whose_goods_it_holds.php');
        $migration->up();

        $this->assertSame((int) $this->principal->id, (int) $layer->fresh()->supplier_id, '⛔ পুরনো ক্রয়ের স্তর তার বিলের সরবরাহকারী পেল না।');
    }

    private function opened(string $code, string $cost, ?int $supplier): Product
    {
        $product = Product::query()->create(['code' => $code, 'name_en' => $code, 'name_bn' => $code, 'sale_price' => '100',
            'unit_id' => Unit::query()->orderBy('id')->firstOrFail()->id, 'is_active' => true]);
        app(OpeningStockService::class)->bringIn($product, $this->store, '10', $cost, '2026-09-25', supplierId: $supplier);

        return $product;
    }

    private function sell(Product $product, string $qty): void
    {
        $service = app(SalesInvoiceService::class);
        $service->confirm($service->create(
            ['customer_id' => Customer::query()->orderBy('id')->firstOrFail()->id, 'warehouse_id' => $this->store->id, 'trx_date' => '2026-10-03'],
            [['product_id' => $product->id, 'qty' => $qty, 'rate' => '100']],
        ));
    }

    private function share(): string
    {
        $row = collect(app(ReportEngine::class)->run(PrincipalCommissionReport::KEY, [])->rows)->firstWhere('supplier_id', $this->principal->id);
        $this->assertNotNull($row);

        return (string) $row['share'];
    }
}
