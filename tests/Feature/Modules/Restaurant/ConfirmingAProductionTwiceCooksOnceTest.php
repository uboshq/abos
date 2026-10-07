<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Restaurant;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Restaurant\Models\Production;
use App\Modules\Restaurant\Models\Recipe;
use App\Modules\Restaurant\Models\RecipeLine;
use App\Modules\Restaurant\Services\ProductionService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * একটা রান্না দুইবার নিশ্চিত করলে রান্না একবারই — চূড়ান্ত অডিট ⛔১৩, ৩০ সেপ্টেম্বর ২০২৬।
 *
 * ── ⛔ কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * [[ProductionService::confirm()]] খসড়া কি না দেখত **লেনদেনের বাইরে**, হাতে ধরা মডেল থেকে। দুইবার চাপ দিলে দুইটা
 * অনুরোধই "খসড়া" দেখত — উপকরণ দুইবার কাটা যেত, আর রান্না করা খাবার দুইবার গুদামে ঢুকত; খাতায় খরচ দ্বিগুণ,
 * তাকে এমন খাবার যা কোনোদিন রাঁধা হয়নি। ⓘ [[StockTransferService]]-এর সেই একই সারাই: লেনদেনের ভেতরে সারি আটকে
 * অবস্থা আবার পড়া।
 *
 * ⓘ দুইবার চাপ = একই কাগজের দুইটা আলাদা মডেল, দুটোই খসড়া অবস্থায় পড়া।
 */
final class ConfirmingAProductionTwiceCooksOnceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    public function test_a_second_confirm_cooks_nothing(): void
    {
        $recipe = $this->aRecipe();
        $dish = Product::query()->findOrFail($recipe->product_id);
        $dish->track_batch = false;
        $dish->save();

        $service = app(ProductionService::class);
        $production = $service->create([
            'recipe_id' => $recipe->id,
            'qty' => '5',
            'trx_date' => now()->toDateString(),
        ]);

        // ⓘ উপকরণ ডেমোতেও কিছু থাকে — তাই হাতে লেখা সংখ্যা নয়, আগের পরিমাণ মেপে নেওয়া
        $ingredient = Product::query()->findOrFail($recipe->lines->first()->product_id);
        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $before = app(StockService::class)->floorQty($ingredient, $warehouse);

        $first = $production->fresh();
        $second = $production->fresh();

        $service->confirm($first);

        try {
            $service->confirm($second);
            $this->fail('দ্বিতীয় নিশ্চিত থামেনি — রান্না দুইবার হয়েছে।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }

        $arrivals = StockMovement::query()
            ->where('source_type', Production::STOCK_SOURCE)
            ->where('source_id', $production->id)
            ->where('product_id', $production->product_id)
            ->where('floor_change', '>', 0)
            ->count();

        $this->assertSame(1, $arrivals, '⛔ রান্না করা খাবার গুদামে '.$arrivals.' বার ঢুকেছে।');

        $after = app(StockService::class)->floorQty($ingredient, $warehouse);

        $this->assertSame(0, bccomp(bcsub($before, $after, 4), '5', 4),
            '⛔ উপকরণ ৫ কমার কথা, কমেছে '.bcsub($before, $after, 4).' — উপকরণ দুইবার কাটা হয়েছে।');
    }

    /**
     * একটা রেসিপি — ডেমোতে একটাও নেই ([[TheCookedFoodWentInWithoutADateOnItTest]]-এর সেই একই কারণ), তাই এই ফাইলের নিজের।
     */
    private function aRecipe(): Recipe
    {
        $products = Product::query()->orderBy('id')->take(2)->get();

        $this->assertCount(2, $products, 'ডেমোতে দুইটা পণ্যও নেই — পরীক্ষাটা কিছুই দেখছে না।');

        $recipe = Recipe::query()->create([
            'company_id' => CompanyContext::id(),
            'product_id' => $products[0]->id,
            'kind' => 'cooked',
            'yield_qty' => '1',
            'is_active' => true,
        ]);

        RecipeLine::query()->create([
            'company_id' => CompanyContext::id(),
            'recipe_id' => $recipe->id,
            'product_id' => $products[1]->id,
            'qty' => '1',
            'sort' => 0,
        ]);

        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $stock = app(StockService::class);

        $stock->move(
            product: $products[1], warehouse: $warehouse,
            sourceType: 'purchase_bill', sourceId: 9001, unplaced: '500',
        );

        $stock->place(
            product: $products[1], warehouse: $warehouse,
            qty: '500', sourceType: 'purchase_bill', sourceId: 9001,
        );

        return $recipe->fresh(['lines.product']);
    }
}
