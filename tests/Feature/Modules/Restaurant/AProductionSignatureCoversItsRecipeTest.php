<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Restaurant;

use App\Core\Engines\Approval\DocumentFingerprint;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Restaurant\Models\Recipe;
use App\Modules\Restaurant\Models\RecipeLine;
use App\Modules\Restaurant\Services\ProductionService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ রান্নার সই প্রণালীরও — হিসাবের নিরীক্ষা, ১৩ অক্টোবর ২০২৬ ([[Production::fingerprintRelations()]])।
 *
 * ⛔ নিশ্চিতের সময় উপকরণ আসে প্রণালী থেকে, আর প্রণালী যেকোনো সময় বদলায়। আগে রান্নার ছাপে প্রণালী ছিল না: ১০০ প্লেটের সইয়ের
 * পরে উপকরণ দশগুণ করলে ছাপ একই — পুরনো সইয়েই দশগুণ মজুদ আর খরচ।
 */
final class AProductionSignatureCoversItsRecipeTest extends TestCase
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

    public function test_a_changed_recipe_changes_the_productions_fingerprint(): void
    {
        [$dish, $ingredient] = Product::query()->orderBy('id')->take(2)->get()->all();

        $recipe = Recipe::query()->create(['company_id' => CompanyContext::id(), 'product_id' => $dish->id, 'kind' => 'cooked',
            'yield_qty' => '1', 'is_active' => true]);
        $line = RecipeLine::query()->create(['company_id' => CompanyContext::id(), 'recipe_id' => $recipe->id,
            'product_id' => $ingredient->id, 'qty' => '1', 'sort' => 0]);

        $production = app(ProductionService::class)->create(['recipe_id' => $recipe->id, 'qty' => '100', 'trx_date' => now()->toDateString()]);

        $print = app(DocumentFingerprint::class);
        $signed = $print->of($production->fresh());
        $this->assertSame($signed, $print->of($production->fresh()), '⛔ বদলহীন রান্নার ছাপ দুইবারে দুই রকম — সই কখনো খাটত না।');

        $line->update(['qty' => '10']);
        $this->assertNotSame($signed, $print->of($production->fresh()), '⛔ প্রণালীতে উপকরণ দশগুণ, অথচ রান্নার ছাপ একই — পুরনো সইয়েই খাতায় উঠত।');

        $line->update(['qty' => '1']);
        $recipe->update(['yield_qty' => '10']);
        $this->assertNotSame($signed, $print->of($production->fresh()), '⛔ প্রণালীর ফলন বদলেছে, অথচ রান্নার ছাপ একই।');
    }
}
