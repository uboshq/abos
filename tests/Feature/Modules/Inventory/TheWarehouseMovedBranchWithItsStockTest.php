<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Inventory\Services\WarehouseService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * মজুদসহ গুদাম শাখা বদলাত, আর মাল রেখেই নিষ্ক্রিয় হত — Inventory অডিট ম২১, ৫ অক্টোবর ২০২৬।
 *
 * ⛔ [[WarehouseService]] কিছুই দেখত না: শাখা বদলালে আগের চলাচল আর খাতা পুরনো শাখায়, তাক নতুনটায়; নিষ্ক্রিয়
 * করলে তাকের মাল আর ধরা মাল এমন গুদামে আটকে থাকত যাকে আর কোনো তালিকা দেখায় না।
 * ⭐ এখন চলাচল হয়ে যাওয়া গুদামের শাখা বদলায় না, আর কিছু বাকি থাকলে নিষ্ক্রিয় হয় না; খালি গুদাম আগের মতোই।
 */
final class TheWarehouseMovedBranchWithItsStockTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Branch $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        $this->other = Branch::query()->where('company_id', $this->company->id)->whereKeyNot($this->company->defaultBranch()?->id)->first()
            ?? Branch::query()->create(['company_id' => $this->company->id, 'code' => 'M21B', 'name_en' => 'Other branch', 'is_active' => true]);
    }

    public function test_a_warehouse_with_movements_keeps_its_branch_and_an_empty_one_can_move(): void
    {
        $used = $this->warehouse('M21U');
        $this->stockIn($used, '5');

        try {
            app(WarehouseService::class)->update($used, ['code' => 'M21U', 'name_en' => 'Used', 'branch_id' => $this->other->id]);
            $this->fail('⛔ মজুদের লেনদেন হয়ে যাওয়া গুদাম অন্য শাখায় গেল।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('branch_id', $e->errors());
        }

        $this->assertSame((int) $this->company->defaultBranch()?->id, (int) $used->fresh()->branch_id);

        $empty = $this->warehouse('M21E');
        app(WarehouseService::class)->update($empty, ['code' => 'M21E', 'name_en' => 'Empty', 'branch_id' => $this->other->id]);
        $this->assertSame((int) $this->other->id, (int) $empty->fresh()->branch_id, 'খালি গুদামও শাখা বদলাতে পারল না।');
    }

    public function test_a_warehouse_with_goods_cannot_be_deactivated_until_it_is_empty(): void
    {
        $store = $this->warehouse('M21D');
        $product = $this->stockIn($store, '3');

        try {
            app(WarehouseService::class)->deactivate($store);
            $this->fail('⛔ তিনটা মাল রেখেই গুদাম নিষ্ক্রিয় হলো।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('is_active', $e->errors());
        }

        $this->assertTrue((bool) $store->fresh()->is_active);

        app(StockService::class)->move(product: $product, warehouse: $store, sourceType: 'test.out', sourceId: 2, floor: '-3');
        app(WarehouseService::class)->deactivate($store);
        $this->assertFalse((bool) $store->fresh()->is_active, 'খালি গুদামও নিষ্ক্রিয় হলো না।');
    }

    private function warehouse(string $code): Warehouse
    {
        return Warehouse::query()->create(['code' => $code, 'name_en' => $code, 'is_active' => true, 'branch_id' => $this->company->defaultBranch()?->id]);
    }

    private function stockIn(Warehouse $warehouse, string $qty): Product
    {
        $product = Product::query()->orderBy('id')->firstOrFail();
        app(StockService::class)->move(product: $product, warehouse: $warehouse, sourceType: 'test.in', sourceId: 1, floor: $qty);

        return $product;
    }
}
