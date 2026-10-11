<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\Inventory\Services\StockFacts;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ মজুদের বয়সের পাতা দেখার শাখা বা গুদাম মানত না (পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬, মজুদ ⚠️৯)।
 *
 * ⓘ [[StockFacts::agingLayers()]] কোম্পানির সব স্তর পড়ত — এক গুদামে সীমিত মানুষ অন্য গুদামের পণ্য, কাগজ আর পরিমাণ
 * দেখতেন। এখন কেবল দেখার গুদামে মাল আছে এমন পণ্য; সব দেখেন এমন মানুষের পাতা আগের মতোই।
 */
final class TheAgePageStaysInsideTheWallTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_one_warehouse_clerk_sees_only_the_old_goods_of_their_warehouse(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);

        $mine = Warehouse::query()->create(['code' => 'AGE-MINE', 'name_en' => 'Clerk store', 'is_active' => true, 'branch_id' => $company->defaultBranch()?->id]);
        $other = Warehouse::query()->create(['code' => 'AGE-OTHER', 'name_en' => 'Other store', 'is_active' => true, 'branch_id' => $company->defaultBranch()?->id]);
        $here = $this->oldStock($mine, 'Age here');
        $there = $this->oldStock($other, 'Age there');

        $ids = fn () => app(StockFacts::class)->agingLayers(90)->pluck('product_id')->map(fn ($id) => (int) $id)->all();

        // ⓘ মালিক — সব গুদাম, আগের মতোই দুটোই
        $this->assertEqualsCanonicalizing([$here->id, $there->id], array_values(array_intersect($ids(), [$here->id, $there->id])),
            '⛔ সব দেখেন এমন মানুষের পাতা থেকে কিছু হারাল।');

        $clerk = User::factory()->create(['current_company_id' => $company->id]);
        $clerk->companies()->attach($company->id, ['is_active' => true]);
        UserDataScope::query()->create(['company_id' => $company->id, 'user_id' => $clerk->id, 'scope_type' => UserDataScope::WAREHOUSE, 'scope_id' => $mine->id]);
        app(DataScope::class)->forget();
        $this->actingAs($clerk);

        $seen = $ids();
        $this->assertContains($here->id, $seen, '⛔ নিজের গুদামের পুরনো মালই পাতায় নেই।');
        $this->assertNotContains($there->id, $seen, '⛔ এক গুদামের কেরানি অন্য গুদামের পুরনো মাল দেখলেন।');
    }

    private function oldStock(Warehouse $warehouse, string $name): Product
    {
        $product = Product::query()->create(['code' => 'AGE-'.mb_substr(md5($name.microtime()), 0, 8), 'name_en' => $name, 'name_bn' => $name,
            'unit_id' => Unit::query()->orderBy('id')->firstOrFail()->id, 'is_active' => true]);
        $day = now()->subDays(120)->toDateString();

        // ⓘ বয়স স্তরের তারিখ থেকে; তাকের সারি আজকের — বন্ধ মাসের পাহারা এখানে প্রশ্ন নয়
        app(StockService::class)->move(product: $product, warehouse: $warehouse, sourceType: 'test.opening', sourceId: $product->id, floor: '5');
        app(CostLayerService::class)->receive(product: $product, qty: '5', unitCost: '10', sourceType: 'test.opening', sourceId: $product->id, date: $day);

        return $product;
    }
}
