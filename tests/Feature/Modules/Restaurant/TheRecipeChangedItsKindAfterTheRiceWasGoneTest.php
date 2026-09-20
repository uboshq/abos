<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Restaurant;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Restaurant\Models\Production;
use App\Modules\Restaurant\Models\Recipe;
use App\Modules\Restaurant\Models\RecipeLine;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * চাল চলে যাওয়ার পর রেসিপি তার ধরন বদলে ফেলত।
 *
 * ── ⓘ অডিট, ২০ সেপ্টেম্বর ২০২৬ ───────────────────────────────────────
 * হাঁড়ির রেসিপি (`batch`) রান্নার মুহূর্তেই উপকরণ কাটে। ⛔ রান্নার পর
 * সেটা `to_order` করে দিলে বিক্রয় চালান **প্রতিটা বিক্রিতে আবার** কাটত —
 * চাল সকালে একবার গেছে, বিক্রিতে আবার যাবে।
 *
 * ── ⚠️ পুরনো পরীক্ষা কেন ধরেনি ───────────────────────────────────────
 * সে ঠিক এই বদলটাই করত, আর কেবল দেখত সারিগুলো বদলেছে কি না — ফলটা
 * দেখত না। ⓘ তাই এই পরীক্ষা ফল ধরে: রান্নার পর বদলটা **প্রত্যাখ্যান**
 * হয়, আর উপকরণের সারি বদলানো আগের মতোই চলে।
 */
final class TheRecipeChangedItsKindAfterTheRiceWasGoneTest extends TestCase
{
    use RefreshDatabase;

    private Product $dish;

    private Product $rice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->dish = Product::query()->firstOrFail();
        $this->rice = Product::query()->where('id', '!=', $this->dish->id)->firstOrFail();
    }

    /**
     * ⛔ রান্না হয়ে যাওয়া রেসিপির ধরন বদলানো যায় না।
     */
    public function test_a_cooked_recipe_cannot_change_its_kind(): void
    {
        $recipe = $this->cookedRecipe();

        $this->put(route('restaurant.recipe.update', $recipe), [
            'product_id' => $this->dish->id,
            'kind' => Recipe::TO_ORDER,
            'yield_qty' => '10',
            'lines' => [['product_id' => $this->rice->id, 'qty' => '2', 'waste_pct' => '0']],
        ])->assertSessionHasErrors('kind');

        $this->assertSame(Recipe::BATCH, $recipe->fresh()->kind,
            'ধরনটা তবু বদলে গেছে — বিক্রিতে উপকরণ দ্বিতীয়বার কাটত।');
    }

    /**
     * ⛔ পদও বদলানো যায় না — ঐ রান্নাগুলো তাহলে অন্য পদের হয়ে যেত।
     */
    public function test_a_cooked_recipe_cannot_change_its_dish(): void
    {
        $recipe = $this->cookedRecipe();
        $other = Product::query()->where('id', '!=', $this->dish->id)
            ->where('id', '!=', $this->rice->id)->firstOrFail();

        $this->put(route('restaurant.recipe.update', $recipe), [
            'product_id' => $other->id,
            'kind' => Recipe::BATCH,
            'yield_qty' => '10',
            'lines' => [['product_id' => $this->rice->id, 'qty' => '2', 'waste_pct' => '0']],
        ])->assertSessionHasErrors('product_id');

        $this->assertSame($this->dish->id, (int) $recipe->fresh()->product_id);
    }

    /**
     * ⭐ উপকরণ বদলানো তবু চলে — রাঁধুনি পরিমাণ শোধরান, আর সেটা পুরনো
     * কাগজ বদলায় না।
     */
    public function test_the_ingredients_can_still_be_corrected(): void
    {
        $recipe = $this->cookedRecipe();

        $this->put(route('restaurant.recipe.update', $recipe), [
            'product_id' => $this->dish->id,
            'kind' => Recipe::BATCH,
            'yield_qty' => '10',
            'lines' => [['product_id' => $this->rice->id, 'qty' => '5', 'waste_pct' => '0']],
        ])->assertSessionHasNoErrors();

        $this->assertSame('5.0000', (string) $recipe->fresh()->lines->first()->qty);
    }

    /**
     * ⓘ যে রেসিপিতে এখনো রান্না হয়নি, সেটা আগের মতোই বদলানো যায়।
     */
    public function test_an_unused_recipe_is_still_free_to_change(): void
    {
        $recipe = $this->recipe();

        $this->put(route('restaurant.recipe.update', $recipe), [
            'product_id' => $this->dish->id,
            'kind' => Recipe::TO_ORDER,
            'yield_qty' => '1',
            'lines' => [['product_id' => $this->rice->id, 'qty' => '2', 'waste_pct' => '0']],
        ])->assertSessionHasNoErrors();

        $this->assertSame(Recipe::TO_ORDER, $recipe->fresh()->kind);
    }

    /** ⓘ বাতিল রান্না গোনা হয় না — উপকরণ ফেরত গেছে। */
    public function test_a_cancelled_run_does_not_lock_the_recipe(): void
    {
        $recipe = $this->recipe();
        $this->cook($recipe, DocumentStatus::CANCELLED);

        $this->put(route('restaurant.recipe.update', $recipe), [
            'product_id' => $this->dish->id,
            'kind' => Recipe::BATCH,
            'yield_qty' => '10',
            'lines' => [['product_id' => $this->rice->id, 'qty' => '2', 'waste_pct' => '0']],
        ])->assertSessionHasNoErrors();

        $this->assertSame(Recipe::BATCH, $recipe->fresh()->kind);
    }

    private function cookedRecipe(): Recipe
    {
        $recipe = $this->recipe(Recipe::BATCH);
        $this->cook($recipe, DocumentStatus::CONFIRMED);

        return $recipe;
    }

    private function cook(Recipe $recipe, string $status): void
    {
        Production::query()->create([
            'company_id' => CompanyContext::id(),
            'branch_id' => CompanyContext::branchId(),
            'document_no' => 'PRD-'.$recipe->id.'-'.$status,
            'recipe_id' => $recipe->id,
            'product_id' => $recipe->product_id,
            'warehouse_id' => Warehouse::query()->firstOrFail()->id,
            'trx_date' => now()->toDateString(),
            'qty' => '10',
            'cost_total' => '0',
            'status' => $status,
        ]);
    }

    private function recipe(string $kind = Recipe::TO_ORDER): Recipe
    {
        $recipe = Recipe::query()->create([
            'product_id' => $this->dish->id,
            'kind' => $kind,
            'yield_qty' => $kind === Recipe::BATCH ? '10' : '1',
            'is_active' => true,
        ]);

        RecipeLine::query()->create([
            'recipe_id' => $recipe->id,
            'product_id' => $this->rice->id,
            'qty' => '2',
            'waste_pct' => '0',
            'sort' => 0,
        ]);

        return $recipe->fresh(['lines.product', 'product']);
    }
}
