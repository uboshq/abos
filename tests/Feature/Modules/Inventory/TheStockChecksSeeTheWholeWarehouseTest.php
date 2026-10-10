<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\BatchAllocator;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ মজুদের সেবা নিজের যাচাইয়ে মানুষের দেখার দেয়াল মানত (পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬, মজুদ ⚠️৩)।
 *
 * ⓘ [[StockService]] আর [[BatchAllocator]]-এর প্রতিটা যোগফল `StockMovement::query()` দিয়ে — তাতে ব্যবহারকারীর শাখা আর গুদামের
 * দেয়াল। এক গুদামে সীমিত মানুষ (গন্তব্যের গ্রহণকারী) উৎস গুদামের আটকানো "০" পেতেন আর গ্রহণ ফিরত; `reverse()` দেখার বাইরের
 * সারি চুপচাপ বাদ দিত — কাগজ বাতিল, মাল নড়েনি; লট বাছাই অন্য গুদামের লট দেখত না। ⭐ এখন যাচাই আর লেখার পথে কেবল কোম্পানির
 * দেয়াল; "সব গুদাম" দেখানোর প্রশ্নে (`statesFor(…, null)`) দেয়াল যেমন ছিল।
 */
final class TheStockChecksSeeTheWholeWarehouseTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $source;

    private Warehouse $mine;

    private User $clerk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->source = Warehouse::query()->create(['code' => 'W3SRC', 'name_en' => 'Source store', 'is_active' => true, 'branch_id' => $company->defaultBranch()?->id]);
        $this->mine = Warehouse::query()->create(['code' => 'W3MINE', 'name_en' => 'Clerk store', 'is_active' => true, 'branch_id' => $company->defaultBranch()?->id]);

        $this->clerk = User::factory()->create(['current_company_id' => $company->id]);
        $this->clerk->companies()->attach($company->id, ['is_active' => true]);
        UserDataScope::query()->create(['company_id' => $company->id, 'user_id' => $this->clerk->id, 'scope_type' => UserDataScope::WAREHOUSE, 'scope_id' => $this->mine->id]);
        app(DataScope::class)->forget();
    }

    public function test_a_clerk_of_one_warehouse_releases_and_reverses_what_the_other_warehouse_holds(): void
    {
        $product = $this->product(false);
        $stock = app(StockService::class);
        $stock->move(product: $product, warehouse: $this->source, sourceType: 'w3_opening', sourceId: 1, floor: '10');
        $stock->move(product: $product, warehouse: $this->source, sourceType: 'w3_transfer', sourceId: 2, hold: '4');
        $stock->move(product: $product, warehouse: $this->source, sourceType: 'w3_return', sourceId: 3, floor: '3');

        $this->actingAs($this->clerk);

        // ⛔ গ্রহণকারীর পথে উৎসের আটকানো ছাড়া — আগে "আটকে আছে কেবল ০"
        $stock->move(product: $product, warehouse: $this->source, sourceType: 'w3_transfer', sourceId: 2, hold: '-4', floor: '-4');

        // ⛔ দেখার বাইরের কাগজ ফেরানো — আগে চুপচাপ কিছুই না
        $back = $stock->reverse('w3_return', 3, 'w3_return:cancel');
        $this->assertCount(1, $back, '⛔ দেখার বাইরের গুদামের সারি ফেরানো হল না — কাগজ বাতিল, মাল নড়েনি');

        // ⓘ "সব গুদাম" দেখা — দেয়াল যেমন ছিল: কেরানি উৎসের মাল দেখেন না
        $this->assertSame(0, bccomp($stock->statesFor($product)['floor'], '0', 4), '⛔ দেখার দেয়ালও উঠে গেল — এক গুদামের কেরানি সব গুদামের মজুদ দেখেন');

        // ⓘ নাম ধরা গুদাম — ব্যবসার প্রশ্ন, পুরো সত্যি: ১০ − ৪ = ৬
        $this->assertSame(0, bccomp($stock->floorQty($product, $this->source), '6', 4));
    }

    public function test_a_clerk_of_one_warehouse_picks_a_lot_that_lives_in_another(): void
    {
        $product = $this->product(true);
        $lot = Batch::query()->create(['product_id' => $product->id, 'batch_no' => 'W3-LOT', 'expiry_date' => now()->addYear()->toDateString()]);
        app(StockService::class)->move(product: $product, warehouse: $this->source, sourceType: 'w3_opening', sourceId: 4, floor: '5', batch: $lot);

        $this->actingAs($this->clerk);

        $picked = app(BatchAllocator::class)->allocate($product, $this->source, '2');

        $this->assertSame([$lot->id], array_map(fn ($p) => $p['batch']->id, $picked), '⛔ অন্য গুদামের লট বাছাইয়ে এল না');
        $this->assertSame(0, bccomp($picked[0]['qty'], '2', 4));
    }

    private function product(bool $lots): Product
    {
        return Product::query()->create([
            'code' => 'W3-'.mb_substr(md5(microtime()), 0, 8), 'name_en' => 'Wall probe', 'name_bn' => 'দেয়ালের নমুনা',
            'unit_id' => Unit::query()->orderBy('id')->firstOrFail()->id, 'is_active' => true, 'track_batch' => $lots,
        ]);
    }
}
