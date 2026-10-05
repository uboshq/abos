<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockCount;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * গ৬ — সমন্বয়ের পর্দা দিয়ে গণনার আলাদা চাবি এড়ানো যায় না (Inventory অডিট, ৪ অক্টোবর ২০২৬)।
 *
 * ⛔ গুদামের লোকের সমন্বয়ের চাবি আছে, মেনে নেওয়ার চাবি নেই ("গোনেন, মেনে নেন না")। অথচ সমন্বয়ের পর্দা গণনা লিখে
 * নিজেই মেনে নিত — গোনা ০ লিখলেই পুরো মজুদ ঘাটতি হয়ে খাতায় উঠত।
 */
final class TheAdjustmentScreenAcceptedItsOwnCountTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
    }

    public function test_a_storekeeper_writes_the_count_but_the_books_wait_for_an_approver(): void
    {
        $storekeeper = $this->storekeeper();
        $this->assertTrue($storekeeper->can('inventory.stock.adjust'), 'প্রস্তুতি: গুদামের লোক সমন্বয় লেখেন।');
        $this->assertFalse($storekeeper->can('inventory.count.approve'), 'প্রস্তুতি: গুদামের লোক মেনে নেন না।');

        $product = $this->stocked('40');
        $rows = $this->adjustmentRows();

        $response = $this->actingAs($storekeeper)
            ->from(route('inventory.stock.adjust'))
            ->post(route('inventory.stock.adjust.store'), $this->adjustment($product, '0'));

        $paper = StockCount::query()->latest('id')->firstOrFail();
        $response->assertRedirect(route('inventory.stock.adjust'))->assertSessionHasNoErrors();
        $this->assertStringContainsString($paper->document_no, (string) session('saved'));

        $this->assertSame(DocumentStatus::DRAFT, $paper->status, '⛔ গুদামের লোকের পর্দা নিজেই গণনা মেনে নিল।');
        $this->assertOnHand($product, '40', '⛔ মেনে নেওয়ার চাবি ছাড়া তাক খালি হলো।');
        $this->assertSame($rows, $this->adjustmentRows(), '⛔ মেনে নেওয়ার চাবি ছাড়া ঘাটতি খাতায় উঠল।');

        // ⓘ যিনি পারেন, তিনি গণনার পাতা থেকে মেনে নেন
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail())
            ->post(route('inventory.count.approve', $paper), ['reason_code_id' => $this->reason()->id])
            ->assertSessionHasNoErrors();

        $this->assertOnHand($product, '0', 'মেনে নেওয়ার পরে তাক গোনা সংখ্যায় আসেনি।');
        $this->assertSame($rows + 2, $this->adjustmentRows());
    }

    /** ⓘ একই মানুষ, চাবি পেলে — আজকের মতোই সাথে সাথে (ছক বন্ধে) */
    public function test_the_same_storekeeper_given_the_key_settles_at_once_as_today(): void
    {
        $storekeeper = $this->storekeeper();
        $first = $this->stocked('40');

        $this->actingAs($storekeeper)->post(route('inventory.stock.adjust.store'), $this->adjustment($first, '31'));
        $this->assertOnHand($first, '40', 'প্রস্তুতি: চাবি ছাড়া অপেক্ষা করে।');

        $storekeeper->givePermissionTo('inventory.count.approve');
        $second = $this->stocked('40');
        $rows = $this->adjustmentRows();

        $this->actingAs($storekeeper->fresh())
            ->from(route('inventory.stock.adjust'))
            ->post(route('inventory.stock.adjust.store'), $this->adjustment($second, '31'))
            ->assertSessionHasNoErrors();

        $this->assertOnHand($second, '31', '⛔ মেনে নেওয়ার চাবি থাকলেও সমন্বয় সাথে সাথে হলো না।');
        $this->assertSame($rows + 2, $this->adjustmentRows());
        $this->assertSame(DocumentStatus::CONFIRMED, StockCount::query()->latest('id')->firstOrFail()->status);
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function storekeeper(): User
    {
        $user = User::factory()->create(['current_company_id' => $this->company->id]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);
        $user->assignRole(Role::query()->where('name', 'Warehouse')->where('company_id', $this->company->id)->firstOrFail());

        return $user;
    }

    /** @return array<string, mixed> */
    private function adjustment(Product $product, string $counted): array
    {
        return [
            'product_id' => $product->id,
            'warehouse_id' => $this->warehouse->id,
            'reason_code_id' => $this->reason()->id,
            'counted' => $counted,
            'trx_date' => now()->toDateString(),
        ];
    }

    private function adjustmentRows(): int
    {
        return LedgerEntry::query()->where('source_type', StockService::ADJUSTMENT)->count();
    }

    private function assertOnHand(Product $product, string $expected, string $why): void
    {
        $this->assertSame(0, bccomp(app(StockService::class)->floorQty($product, $this->warehouse), $expected, 4), $why);
    }

    private function reason(): ReasonCode
    {
        return ReasonCode::query()->inContext(ReasonCode::STOCK_ADJUSTMENT)->active()->orderBy('id')->firstOrFail();
    }

    private function stocked(string $qty): Product
    {
        $product = Product::query()->create([
            'code' => 'G6-'.mb_substr(md5(microtime()), 0, 8),
            'name_en' => 'Adjust probe',
            'name_bn' => 'সমন্বয়ের নমুনা',
            'unit_id' => Unit::query()->orderBy('id')->firstOrFail()->id,
            'is_active' => true,
        ]);

        app(StockService::class)->move(product: $product, warehouse: $this->warehouse,
            sourceType: 'test.opening', sourceId: $product->id, floor: $qty);
        app(CostLayerService::class)->receive(product: $product, qty: $qty, unitCost: '10.00',
            sourceType: 'test.opening', sourceId: $product->id);

        return $product;
    }
}
