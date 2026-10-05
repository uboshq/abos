<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Restaurant;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\CostLayer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\Unit;
use App\Modules\Restaurant\Models\Recipe;
use App\Modules\Restaurant\Models\RecipeLine;
use App\Modules\Restaurant\Services\ProductionService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * রান্নার খরচ লট দেখত না — Inventory অডিট ম৭ (উৎপাদন), ৫ অক্টোবর ২০২৬।
 *
 * ⛔ (ক) উপকরণ বেরোত আগে-মেয়াদের লট থেকে, কিন্তু খরচ টানা হত লট না দেখে, পুরনো স্তর আগে — লট A-র চাল রান্নায় গেল, অথচ
 * লট B-র ৩০ টাকার স্তর খালি হল; (খ) তৈরি খাবার লটে উঠত, কিন্তু তার খরচের স্তর লটহীন — লট ধরে বিক্রিতে "লটের স্তর নেই"।
 * ⭐ এখন উপকরণের প্রতিটা লট-অংশের খরচ সেই লটের স্তর থেকে, আর তৈরি খাবারের স্তর তার লটে।
 */
final class TheCookingCostIgnoredItsLotsTest extends TestCase
{
    use RefreshDatabase;

    public function test_ingredients_cost_by_their_lots_and_the_dish_layer_carries_its_lot(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $unit = Unit::query()->orderBy('id')->firstOrFail()->id;
        $make = fn (string $code, bool $lots) => Product::query()->create([
            'code' => $code, 'name_en' => $code, 'name_bn' => $code, 'unit_id' => $unit, 'is_active' => true, 'track_batch' => $lots,
        ]);
        $dish = $make('M7P-DISH', true);
        $rice = $make('M7P-RICE', true);

        // ⓘ লট B-র স্তর আগে (পুরনো), কিন্তু লট A আগে মেয়াদের — চাল বেরোয় A থেকে, খরচও A থেকে হওয়া চাই
        $b = $this->lot($rice, $warehouse, 'RICE-B', now()->addYear()->toDateString(), '10', '30');
        $a = $this->lot($rice, $warehouse, 'RICE-A', now()->addMonth()->toDateString(), '10', '10');

        $recipe = Recipe::query()->create(['company_id' => CompanyContext::id(), 'product_id' => $dish->id, 'kind' => 'cooked', 'yield_qty' => '1', 'is_active' => true]);
        RecipeLine::query()->create(['company_id' => CompanyContext::id(), 'recipe_id' => $recipe->id, 'product_id' => $rice->id, 'qty' => '1', 'sort' => 0]);

        $service = app(ProductionService::class);
        $production = $service->confirm($service->create([
            'recipe_id' => $recipe->id, 'qty' => '3', 'trx_date' => now()->toDateString(), 'warehouse_id' => $warehouse->id,
        ]));

        $this->assertSame(['7', '10'], [$this->left($a), $this->left($b)], '⛔ রান্নায় লট A-র চাল গেল, অথচ খরচ উঠল অন্য লটের স্তর থেকে।');
        $this->assertSame(0, bccomp('30', (string) $production->fresh()->cost_total, 4), '⛔ রান্নার খরচ লট A-র ১০ টাকায় নয়।');

        $dishLayer = CostLayer::query()->where('product_id', $dish->id)->first();
        $dishLot = Batch::query()->where('product_id', $dish->id)->where('batch_no', $production->document_no)->first();
        $this->assertNotNull($dishLot, 'প্রস্তুতিটাই ভুল — তৈরি খাবার লটে ওঠেনি।');
        $this->assertSame((int) $dishLot->id, (int) $dishLayer?->batch_id, '⛔ তৈরি খাবারের খরচের স্তর লট চেনে না।');
    }

    private function lot(Product $product, Warehouse $warehouse, string $no, string $expiry, string $qty, string $cost): Batch
    {
        $batch = Batch::query()->create(['product_id' => $product->id, 'batch_no' => $no, 'expiry_date' => $expiry]);
        app(StockService::class)->move(product: $product, warehouse: $warehouse, sourceType: 'test.opening', sourceId: $batch->id, floor: $qty, batch: $batch);
        app(CostLayerService::class)->receive(product: $product, qty: $qty, unitCost: $cost, sourceType: 'test.opening', sourceId: $batch->id, batch: $batch);

        return $batch;
    }

    private function left(Batch $batch): string
    {
        $sum = (string) CostLayer::query()->where('batch_id', $batch->id)->sum('qty_remaining');

        return rtrim(rtrim(bcadd($sum, '0', 4), '0'), '.') ?: '0';
    }
}
