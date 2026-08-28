<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Recipe;
use App\Modules\Inventory\Models\RecipeLine;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * রেসিপির পর্দাগুলো খোলে, আর ফর্ম যা ঠেকানোর কথা তাই ঠেকায়।
 *
 * ── কেন এই পরীক্ষাটা আলাদা করে দরকার ─────────────────────────────────
 * ইঞ্জিনের পরীক্ষা ([[ABiryaniSoldMustTakeTheRiceWithItTest]]) প্রমাণ করে
 * উপকরণ ঠিকভাবে কমে। কিন্তু ওই ইঞ্জিনে পৌঁছানোর একমাত্র পথ এই পর্দা —
 * আর পর্দা না খুললে ইঞ্জিনটা কেউ ব্যবহারই করতে পারবেন না।
 *
 * এই প্রকল্পের চেনা ফাঁদ: টুকরোটা কাজ করে, জোড়াটা লাগানো হয়নি।
 */
class ARecipeScreenOpensTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Product $dish;

    private Product $rice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs($this->user);

        $products = Product::query()->orderBy('id')->take(2)->get();
        $this->dish = $products->first();
        $this->rice = $products->last();
    }

    public function test_the_list_opens_when_there_are_no_recipes_yet(): void
    {
        $this->get(route('inventory.recipe.index'))
            ->assertOk()
            ->assertSee(__('inventory::message.no_recipes'));
    }

    public function test_the_list_opens_with_a_recipe_on_it(): void
    {
        $this->recipe();

        $this->get(route('inventory.recipe.index'))
            ->assertOk()
            ->assertSee($this->dish->name());
    }

    public function test_the_form_opens_empty_and_for_editing(): void
    {
        $this->get(route('inventory.recipe.create'))->assertOk();

        $this->get(route('inventory.recipe.edit', $this->recipe()))->assertOk();
    }

    /**
     * উপকরণহীন রেসিপি সংরক্ষণ করা যায় না।
     *
     * বিক্রির পথেও পাহারা আছে, কিন্তু সেখানে বাধা পাওয়ার চেয়ে এখানে
     * বানাতেই না পারা ভালো — কাউন্টারে একজন দাঁড়িয়ে থাকা অবস্থায়
     * "রেসিপি অসম্পূর্ণ" পড়ার চেয়ে।
     */
    public function test_a_recipe_cannot_be_saved_without_ingredients(): void
    {
        $this->post(route('inventory.recipe.store'), [
            'product_id' => $this->dish->id,
            'kind' => Recipe::TO_ORDER,
            'yield_qty' => '1',
        ])->assertSessionHasErrors('lines');

        $this->assertDatabaseCount('inv_recipes', 0);
    }

    /** খাবার নিজেই নিজের উপকরণ হতে পারে না — হলে অসীম চক্র। */
    public function test_a_dish_cannot_be_its_own_ingredient(): void
    {
        $this->post(route('inventory.recipe.store'), [
            'product_id' => $this->dish->id,
            'kind' => Recipe::TO_ORDER,
            'yield_qty' => '1',
            'lines' => [['product_id' => $this->dish->id, 'qty' => '1']],
        ])->assertSessionHasErrors('lines.0.product_id');

        $this->assertDatabaseCount('inv_recipes', 0);
    }

    /** এক পণ্যের দুইটা রেসিপি নয় — কোনটা দিয়ে কমবে তা আর বলা যেত না। */
    public function test_one_product_cannot_have_two_recipes(): void
    {
        $this->recipe();

        $this->post(route('inventory.recipe.store'), [
            'product_id' => $this->dish->id,
            'kind' => Recipe::TO_ORDER,
            'yield_qty' => '1',
            'lines' => [['product_id' => $this->rice->id, 'qty' => '1']],
        ])->assertSessionHasErrors('product_id');

        $this->assertDatabaseCount('inv_recipes', 1);
    }

    /**
     * সংরক্ষণে লাইনগুলো বদলে যায়, জমে না।
     *
     * লাইনগুলো মুছে আবার লেখা হয়। ভুল করে কেবল যোগ করলে সম্পাদনার পর
     * পুরনো উপকরণগুলোও থেকে যেত — আর বিক্রিতে দ্বিগুণ মাল কমত।
     */
    public function test_saving_replaces_the_lines_rather_than_adding_to_them(): void
    {
        $recipe = $this->recipe();

        $this->put(route('inventory.recipe.update', $recipe), [
            'product_id' => $this->dish->id,
            'kind' => Recipe::BATCH,
            'yield_qty' => '10',
            'lines' => [['product_id' => $this->rice->id, 'qty' => '4', 'waste_pct' => '10']],
        ])->assertRedirect();

        $recipe->refresh()->load('lines');

        $this->assertCount(1, $recipe->lines);
        $this->assertSame(Recipe::BATCH, $recipe->kind);
        $this->assertSame('4.0000', $recipe->lines->first()->qty);
    }

    /** "মোছা" মানে নিষ্ক্রিয় করা — ইতিহাস থেকে যায় (নিয়ম ৫)। */
    public function test_deleting_only_switches_it_off(): void
    {
        $recipe = $this->recipe();

        $this->delete(route('inventory.recipe.destroy', $recipe))->assertRedirect();

        $this->assertFalse($recipe->fresh()->is_active);
        $this->assertDatabaseCount('inv_recipes', 1);

        $this->post(route('inventory.recipe.activate', $recipe))->assertRedirect();

        $this->assertTrue($recipe->fresh()->is_active);
    }

    private function recipe(): Recipe
    {
        $recipe = Recipe::query()->create([
            'product_id' => $this->dish->id,
            'kind' => Recipe::TO_ORDER,
            'yield_qty' => '1',
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
