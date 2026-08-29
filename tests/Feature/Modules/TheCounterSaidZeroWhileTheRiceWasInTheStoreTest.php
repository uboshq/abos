<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Recipe;
use App\Modules\Inventory\Models\RecipeLine;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * কাউন্টারে বিরিয়ানি "০" দেখাত, অথচ চাল গুদামেই — রেস্টুরেন্টের ধাপ ৩।
 *
 * ── কেন এটা ধাপ ৩-এর আসল কাজ ─────────────────────────────────────────
 * অর্ডারে-রান্না খাবারের **নিজের কোনো মজুদ নেই** — ওটা রান্না হয় অর্ডার
 * পাওয়ার পর। POS প্রতিটা পণ্যের জন্য একই অঙ্ক কষে (মেঝে − ধরা − আটকানো),
 * আর সেটা এমন পণ্যের জন্য সবসময় শূন্য।
 *
 * ফলে কাউন্টারের পর্দায় বিরিয়ানির পাশে "০" বসে থাকে যদিও চাল, মাংস ও
 * তেল দিয়ে চল্লিশ প্লেট হয়। ক্যাশিয়ার হয় বেচেন না, নয় সন্দেহ নিয়ে
 * বেচেন — আর দুইটাই খারাপ।
 *
 * ধাপ ২-এ হিসাবটা লেখা হয়েছে ([[RecipeService::portionsPossible()]])।
 * ধাপ ৩ হলো সেটা কাউন্টারে পৌঁছে দেওয়া।
 */
class TheCounterSaidZeroWhileTheRiceWasInTheStoreTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

        /* কাউন্টারের পর্দা ডিফল্ট বন্ধ — পরিবেশকের জিনিস নয়। */
        app(SettingsService::class)->set('sales.screen_pos', true);
    }

    private function make(string $name): Product
    {
        return Product::query()->create([
            'code' => 'RP-'.mb_substr(md5($name.microtime()), 0, 8),
            'name_en' => $name,
            'name_bn' => $name,
            'unit_id' => Unit::query()->orderBy('id')->firstOrFail()->id,
            'sale_price' => '250',
            'is_active' => true,
        ]);
    }

    private function stocked(string $name, string $qty): Product
    {
        $product = $this->make($name);

        app(StockService::class)->move(
            product: $product,
            warehouse: $this->warehouse,
            sourceType: 'test.opening',
            sourceId: $product->id,
            floor: $qty,
        );

        app(CostLayerService::class)->receive(
            product: $product,
            qty: $qty,
            unitCost: '10.00',
            sourceType: 'test.opening',
            sourceId: $product->id,
        );

        return $product;
    }

    /**
     * @param  list<array{0: Product, 1: string}>  $lines
     */
    private function dish(string $name, array $lines): Product
    {
        $dish = $this->make($name);

        $recipe = Recipe::query()->create([
            'product_id' => $dish->id,
            'kind' => Recipe::TO_ORDER,
            'yield_qty' => '1',
            'is_active' => true,
        ]);

        foreach ($lines as $i => [$ingredient, $qty]) {
            RecipeLine::query()->create([
                'recipe_id' => $recipe->id,
                'product_id' => $ingredient->id,
                'qty' => $qty,
                'waste_pct' => '0',
                'sort' => $i,
            ]);
        }

        return $dish;
    }

    /** @return array<string, mixed>|null */
    private function onCounter(Product $product): ?array
    {
        /*
         * কাউন্টারের তালিকাটা পর্দা যা পায় সেখান থেকেই।
         *
         * ── কেন `viewData`, সার্ভিস ডেকে নয় ────────────────────────
         * দাবিটা হলো **ক্যাশিয়ার কী দেখেন**। সার্ভিস ডেকে মাপলে
         * হিসাবটা ঠিক আছে কি না জানা যেত, কিন্তু সংখ্যাটা পর্দা
         * পর্যন্ত পৌঁছায় কি না জানা যেত না — আর ভুলটা ঠিক ওই
         * দূরত্বটাতেই ছিল।
         */
        $products = $this->actingAs($this->user)
            ->get(route('sales.pos.index'))
            ->assertOk()
            ->viewData('products');

        foreach ($products as $row) {
            if ((int) $row->id === $product->id) {
                return (array) $row;
            }
        }

        return null;
    }

    /**
     * অর্ডারে-রান্না খাবারের পাশে উপকরণ যত প্লেট দেয়, ততই লেখা থাকে।
     */
    public function test_a_made_to_order_dish_shows_the_plates_its_ingredients_allow(): void
    {
        $rice = $this->stocked('Rice', '100');
        $oil = $this->stocked('Oil', '40');

        $biryani = $this->dish('Biryani', [[$rice, '1'], [$oil, '1']]);

        $row = $this->onCounter($biryani);

        $this->assertNotNull($row, 'খাবারটাই কাউন্টারের তালিকায় নেই।');

        $this->assertSame(0, bccomp((string) $row['available'], '40', 0), implode("\n", [
            'কাউন্টারে খাবারটার পাশে ভুল সংখ্যা: '.$row['available'],
            '',
            'অর্ডারে-রান্না খাবারের নিজের মজুদ থাকে না — উপকরণ যত প্লেট',
            'দেয়, কাউন্টারে ততই লেখা থাকার কথা। শূন্য দেখালে ক্যাশিয়ার',
            'বেচেন না, যদিও চাল গুদামেই।',
        ]));
    }

    /**
     * উপকরণ ফুরালে সংখ্যাটাও শূন্য — আর তখন শূন্যটাই সত্যি।
     */
    public function test_when_an_ingredient_runs_out_the_counter_says_zero(): void
    {
        $rice = $this->stocked('Rice', '100');
        $saffron = $this->make('Saffron');   // মজুদ নেই

        $kacchi = $this->dish('Kacchi', [[$rice, '1'], [$saffron, '1']]);

        $this->assertSame(0, bccomp((string) $this->onCounter($kacchi)['available'], '0', 0));
    }

    /**
     * সাধারণ পণ্যের হিসাব আগের মতোই।
     *
     * ── কেন এটা আলাদা করে পাহারা দেওয়া ───────────────────────────────
     * রেসিপির হিসাবটা যোগ করতে গিয়ে সবার জন্য বসিয়ে দেওয়া সহজ, আর
     * তাতে বিস্কুটের মজুদও রেসিপি খুঁজতে যেত — যার কোনোটাই নেই, তাই
     * উত্তর আসত শূন্য, আর গোটা দোকান বিক্রি বন্ধ করে দিত।
     */
    public function test_an_ordinary_product_still_counts_its_own_stock(): void
    {
        $biscuit = $this->stocked('Biscuit', '17');

        $this->assertSame(0, bccomp((string) $this->onCounter($biscuit)['available'], '17', 0));
    }

    /**
     * হাঁড়িতে-রান্না খাবার তার নিজের মজুদই দেখায়।
     *
     * সকালে হাঁড়ি চড়ানো হয়েছে, পঞ্চাশ প্লেট হয়েছে, আর সারাদিন ওই
     * পঞ্চাশটাই বিক্রি হয়। উপকরণ তখন আর প্রশ্ন নয় — ওগুলো সকালেই
     * খরচ হয়ে গেছে। উপকরণ ধরে গুনলে যা রান্নাই হয়নি তা বেচা যেত।
     */
    public function test_a_batch_cooked_dish_shows_what_was_actually_cooked(): void
    {
        $rice = $this->stocked('Rice', '500');

        $tehari = $this->make('Tehari');

        $recipe = Recipe::query()->create([
            'product_id' => $tehari->id,
            'kind' => Recipe::BATCH,
            'yield_qty' => '1',
            'is_active' => true,
        ]);

        RecipeLine::query()->create([
            'recipe_id' => $recipe->id,
            'product_id' => $rice->id,
            'qty' => '1',
            'waste_pct' => '0',
            'sort' => 0,
        ]);

        // হাঁড়িতে বারো প্লেট রান্না হয়েছে
        app(StockService::class)->move(
            product: $tehari,
            warehouse: $this->warehouse,
            sourceType: 'test.cooked',
            sourceId: $tehari->id,
            floor: '12',
        );

        $this->assertSame(0, bccomp((string) $this->onCounter($tehari)['available'], '12', 0),
            'হাঁড়ির খাবারে উপকরণ ধরে গোনা হয়েছে — যা রান্নাই হয়নি তা বেচা যেত।');
    }
}
