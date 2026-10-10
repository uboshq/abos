<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\ApprovalFlowStep;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Approval\Services\ApprovalFlowService;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockCount;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockAdjustmentService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⛔ ফ্রি মাল গোনা বা সারানো যেত না (পুরো-ERP অডিট, মজুদ ⚠️৬ক; fe-র তিন শর্তসহ, ১০ অক্টোবর ২০২৬)।
 *
 * ⓘ সমন্বয় কেবল দামি মাল নাড়ত — হারানো ফ্রি কার্টন বা গুনে পাওয়া বাড়তি ফ্রি খাতায় তোলার পথ ছিল না। এখন সমন্বয়ের
 * পর্দায় "ফ্রি মাল" বাছলে একই কাগজ (গণনা, `kind = free`), একই সই আর একই চাবি, আর মেনে নিলে ফ্রি ভাণ্ডার নড়ে।
 * fe-র শর্ত: (১) সই আর maker-checker দামি মালের মতো; (২) খাতায় কিছু যায় না — `ledger_entries`-এর সংখ্যা স্থির;
 * (৩) কারণ বাধ্যতামূলক, অডিটে "ফ্রি" লেখা।
 *
 * মজুদ: লট A (মেয়াদ আগে) ফ্রি ১০, লট B ফ্রি ৫, অর্ডারে ধরা ফ্রি ৪, দামি তাকে ৩।
 */
final class TheFreeGoodsCanBeSetRightTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Warehouse $warehouse;

    private User $owner;

    private Product $product;

    private Batch $early;

    private Batch $late;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->create(['code' => 'FR-'.mb_substr(md5(microtime()), 0, 6), 'name_en' => 'Free probe',
            'name_bn' => 'ফ্রির নমুনা', 'unit_id' => Unit::query()->where('code', 'PCS')->firstOrFail()->id,
            'is_active' => true, 'track_batch' => true]);
        $lot = fn (string $no, int $days) => Batch::query()->create(['company_id' => $this->company->id, 'product_id' => $this->product->id,
            'batch_no' => $no, 'expiry_date' => now()->addDays($days)->toDateString()]);
        $this->early = $lot('FR-A', 60);
        $this->late = $lot('FR-B', 90);

        $stock = app(StockService::class);
        $stock->move(product: $this->product, warehouse: $this->warehouse, sourceType: 'test.free', sourceId: 1, free: '10', batch: $this->early);
        $stock->move(product: $this->product, warehouse: $this->warehouse, sourceType: 'test.free', sourceId: 2, free: '5', batch: $this->late);
        $stock->move(product: $this->product, warehouse: $this->warehouse, sourceType: 'test.free', sourceId: 3, freeReserved: '4');
        $stock->move(product: $this->product, warehouse: $this->warehouse, sourceType: 'test.paid', sourceId: 4, floor: '3', batch: $this->early);
        // ⓘ দামি মালের দাম আছে — তাই গড় দরও আছে; ফ্রি কাগজে সেটা বসলে সইয়ের টাকার অঙ্ক মিথ্যা হত
        app(\App\Modules\Inventory\Services\CostLayerService::class)->receive(product: $this->product, qty: '3', unitCost: '50',
            sourceType: 'test.paid', sourceId: 4, batch: $this->early);
    }

    public function test_a_lost_free_carton_comes_off_the_free_pool_only_and_nothing_reaches_the_books(): void
    {
        $ledger = LedgerEntry::query()->count();

        $this->from(route('inventory.stock.adjust'))
            ->post(route('inventory.stock.adjust.store'), $this->form('12'))
            ->assertSessionHasNoErrors();

        $paper = StockCount::query()->latest('id')->firstOrFail();
        $this->assertSame(StockCount::KIND_FREE, $paper->kind, '⛔ ফ্রি সমন্বয় দামি গণনার কাগজ হয়ে গেল।');
        $this->assertSame(DocumentStatus::CONFIRMED, $paper->status);
        $this->assertSame(0, bccomp((string) $paper->lines->first()->book_qty, '15', 4), '⛔ খাতার সংখ্যা ফ্রি ভাণ্ডারের নয়।');
        $this->assertNull($paper->lines->first()->unit_cost, '⛔ ফ্রি মালে দর বসল।');

        $this->assertFree('12', '⛔ ফ্রি ভাণ্ডার থেকে ৩ কমল না।');
        $this->assertSame(0, bccomp($this->early->freeBalance($this->warehouse), '7', 4), '⛔ ঘাটতি মেয়াদের ক্রমে আগের লট থেকে নয়।');
        $this->assertSame(0, bccomp($this->late->freeBalance($this->warehouse), '5', 4));
        $this->assertSame(0, bccomp(app(StockService::class)->floorQty($this->product, $this->warehouse), '3', 4), '⛔ দামি তাক নড়ল।');

        // (২) খাতায় কিছু যায় না
        $this->assertSame($ledger, LedgerEntry::query()->count(), '⛔ ফ্রি মালের ঘাটতি খাতায় টাকা তুলল।');

        // (৩) কারণ বসে, অডিটে "ফ্রি"
        $this->assertNotNull($paper->lines->first()->reason_code_id, '⛔ কারণ ছাড়া বসল।');
        $this->assertSame(1, AuditTrail::query()->where('action', 'free_stock_adjusted')->where('auditable_id', $paper->id)->count(),
            '⛔ অডিটে লেখা নেই যে এটা ফ্রি মালের সমন্বয়।');
        $this->assertSame(1, StockMovement::query()->where('source_type', StockService::ADJUSTMENT)
            ->where('product_id', $this->product->id)->where('narration', 'like', '%'.$paper->document_no.'%')->count());
    }

    public function test_free_goods_that_orders_hold_cannot_be_written_off(): void
    {
        // ⓘ ফ্রি ১৫, অর্ডারে ধরা ৪ → সর্বোচ্চ ১১ কমানো যায়; ১২ নয়
        try {
            app(StockAdjustmentService::class)->settleFree($this->product, $this->warehouse, '-12', $this->reason());
            $this->fail('⛔ অর্ডারে ধরা ফ্রি মালও ঘাটতিতে গেল।');
        } catch (ValidationException $e) {
            $this->assertSame(__('inventory::validation.free_short_beyond_spare', ['product' => $this->product->name(), 'spare' => '11']),
                $e->errors()['counted'][0] ?? null);
        }

        $this->assertFree('15', '⛔ ফিরিয়েও কিছু কমল।');

        // ⓘ লট দেওয়া থাকলে সেই লটের বেশি নয়
        try {
            app(StockAdjustmentService::class)->settleFree($this->product, $this->warehouse, '-6', $this->reason(), batch: $this->late);
            $this->fail('⛔ লট B-র ৫-এর বেশি কমল।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('counted', $e->errors());
        }

        app(StockAdjustmentService::class)->settleFree($this->product, $this->warehouse, '-11', $this->reason());
        $this->assertFree('4');
    }

    public function test_found_free_goods_go_into_the_named_lot_and_a_lot_kept_product_needs_one(): void
    {
        $ledger = LedgerEntry::query()->count();

        try {
            app(StockAdjustmentService::class)->settleFree($this->product, $this->warehouse, '2', $this->reason());
            $this->fail('⛔ লট-ধরা পণ্যের বাড়তি ফ্রি লট ছাড়া উঠল।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('batch_no', $e->errors());
        }

        $this->from(route('inventory.stock.adjust'))
            ->post(route('inventory.stock.adjust.store'), $this->form('7', ['batch_no' => 'FR-B']))
            ->assertSessionHasNoErrors();

        $this->assertSame(0, bccomp($this->late->freeBalance($this->warehouse), '7', 4), '⛔ বাড়তি ফ্রি বলা লটে উঠল না।');
        $this->assertFree('17');
        $this->assertSame($ledger, LedgerEntry::query()->count(), '⛔ বাড়তি ফ্রি খাতায় টাকা তুলল।');
    }

    public function test_the_same_signature_and_the_same_approve_key_as_paid_goods(): void
    {
        // (১) maker-checker — সমন্বয়ের চাবি আছে, মেনে নেওয়ার নেই: কাগজ খসড়া, মাল নড়ে না
        $clerk = User::factory()->create(['current_company_id' => $this->company->id]);
        $clerk->companies()->attach($this->company->id, ['is_active' => true]);
        CompanyContext::forCompany($this->company->id, fn () => $clerk->givePermissionTo(
            Permission::findOrCreate('inventory.stock.adjust', 'web'), Permission::findOrCreate('inventory.stock.view', 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($clerk->fresh());

        $this->from(route('inventory.stock.adjust'))
            ->post(route('inventory.stock.adjust.store'), $this->form('14'))
            ->assertSessionHasNoErrors();

        $paper = StockCount::query()->latest('id')->firstOrFail();
        $this->assertSame([StockCount::KIND_FREE, DocumentStatus::DRAFT], [$paper->kind, $paper->status], '⛔ চাবি ছাড়াই ফ্রি সমন্বয় মেনে নেওয়া হলো।');
        $this->assertFree('15');

        // ⓘ একই মালের দ্বিতীয় ফ্রি খসড়া নয়, কিন্তু দামি মালের গণনা আলাদা খাতা — চলে
        $this->from(route('inventory.stock.adjust'))
            ->post(route('inventory.stock.adjust.store'), $this->form('13'))
            ->assertSessionHasErrors('lines');
        $this->from(route('inventory.stock.adjust'))
            ->post(route('inventory.stock.adjust.store'), ['pool' => 'paid'] + $this->form('3'))
            ->assertSessionHasNoErrors();

        // (১) সইয়ের ছক — দামি মালের সেই একই ছক ফ্রি কাগজেও
        $this->actingAs($this->owner);
        $signer = User::query()->where('email', 'accounts@abos.test')->firstOrFail();
        app(ApprovalFlowService::class)->create(
            ['module' => 'inventory', 'action' => 'count', 'is_active' => true],
            [['level' => 1, 'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $signer->id, 'requires_all' => false]],
        );

        $this->post(route('inventory.count.approve', $paper), ['reason_code_id' => $this->reason()->id])->assertSessionHasErrors('status');
        $this->assertFree('15', '⛔ সই ছাড়াই ফ্রি ঘাটতি বসল।');

        app(ApprovalEngine::class)->approve(
            Approval::query()->where('approvable_type', $paper->getMorphClass())->where('approvable_id', $paper->id)->sole(), $signer);
        $this->post(route('inventory.count.approve', $paper), ['reason_code_id' => $this->reason()->id])->assertSessionHasNoErrors();

        $this->assertFree('14');
    }

    private function form(string $counted, array $extra = []): array
    {
        return $extra + [
            'pool' => 'free',
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'reason_code_id' => $this->reason()->id,
            'counted' => $counted,
            'trx_date' => now()->toDateString(),
        ];
    }

    private function reason(): ReasonCode
    {
        return ReasonCode::query()->inContext(ReasonCode::STOCK_ADJUSTMENT)->active()->orderBy('id')->firstOrFail();
    }

    private function assertFree(string $expected, string $why = ''): void
    {
        $this->assertSame(0, bccomp(app(StockService::class)->freeQty($this->product, $this->warehouse), $expected, 4),
            $why ?: "⛔ ফ্রি মজুদ {$expected} নয়।");
    }
}
