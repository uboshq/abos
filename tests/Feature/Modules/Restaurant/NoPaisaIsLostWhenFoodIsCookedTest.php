<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Restaurant;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\CostLayer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\Unit;
use App\Modules\Restaurant\Models\Production;
use App\Modules\Restaurant\Models\Recipe;
use App\Modules\Restaurant\Models\RecipeLine;
use App\Modules\Restaurant\Services\ProductionService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ রান্নার খরচ ভাগে ভাঙা পয়সা হারাত (পুরো-ERP অডিট, মজুদ ছ৮; মালিকের "সব খোলা ভুল", ১০ অক্টোবর ২০২৬)।
 *
 * ১০০ টাকার উপকরণে ১২ প্লেট: আগে একটাই স্তর ৮.৩৩৩৩ × ১২ = ৯৯.৯৯৯৬ — উপকরণ থেকে ১০০ বেরোল, খাবারে ঢুকল কম। এখন খাবারের
 * স্তরগুলোর মোট ঠিক ১০০, আর সব প্লেট বেচলে খরচও ঠিক ১০০।
 */
final class NoPaisaIsLostWhenFoodIsCookedTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_cooked_food_layers_hold_exactly_what_the_ingredients_cost(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $unit = Unit::query()->orderBy('id')->firstOrFail()->id;
        $dish = Product::query()->create(['code' => 'C8-DISH', 'name_en' => 'C8 dish', 'name_bn' => 'C8 dish', 'unit_id' => $unit, 'is_active' => true]);
        $rice = Product::query()->create(['code' => 'C8-RICE', 'name_en' => 'C8 rice', 'name_bn' => 'C8 rice', 'unit_id' => $unit, 'is_active' => true]);

        app(StockService::class)->move(product: $rice, warehouse: $warehouse, sourceType: 'test.opening', sourceId: 1, floor: '12');
        app(CostLayerService::class)->receive(product: $rice, qty: '12', unitCost: '16.6667', sourceType: 'test.opening', sourceId: 1);

        $recipe = Recipe::query()->create(['company_id' => CompanyContext::id(), 'product_id' => $dish->id, 'kind' => 'cooked', 'yield_qty' => '1', 'is_active' => true]);
        RecipeLine::query()->create(['company_id' => CompanyContext::id(), 'recipe_id' => $recipe->id, 'product_id' => $rice->id, 'qty' => '0.5', 'sort' => 0]);

        // ⓘ প্লেটে আধা কেজি চাল: ১২ প্লেটে ৬ কেজি × ১৬.৬৬৬৭ = ১০০.০০০২; ১২ দিয়ে ভাগে ৮.৩৩৩৩৫ — চার ঘরে মেলে না
        $service = app(ProductionService::class);
        $production = $service->confirm($service->create([
            'recipe_id' => $recipe->id, 'qty' => '12', 'trx_date' => now()->toDateString(), 'warehouse_id' => $warehouse->id,
        ]));
        $total = (string) $production->fresh()->cost_total;
        $this->assertSame(0, bccomp($total, '100.0002', 4), 'প্রস্তুতিটাই ভুল — উপকরণের খরচ অন্য।');

        $layers = CostLayer::query()->where('product_id', $dish->id)->where('source_type', Production::STOCK_SOURCE)->get();
        $held = $layers->reduce(fn (string $sum, CostLayer $l) => bcadd($sum, bcmul((string) $l->qty_in, (string) $l->unit_cost, 4), 4), '0');
        $qty = $layers->reduce(fn (string $sum, CostLayer $l) => bcadd($sum, (string) $l->qty_in, 4), '0');

        $this->assertSame(0, bccomp($held, $total, 4), "⛔ খাবারের স্তরে {$held}, অথচ উপকরণ থেকে বেরোল {$total} — ভাঙা পয়সা হারাল।");
        $this->assertSame(0, bccomp($qty, '12', 4), '⛔ প্লেটের সংখ্যা বদলাল।');

        // ⓘ বারোটা প্লেট বেচলে খরচ ঠিক উপকরণের খরচ
        $sold = app(CostLayerService::class)->issue(product: $dish, qty: '12', sourceType: 'test.sale', sourceId: 1);
        $this->assertSame(0, bccomp($sold['cost'], $total, 4), '⛔ সব প্লেট বেচার খরচ উপকরণের খরচের সাথে মেলে না।');
    }
}
