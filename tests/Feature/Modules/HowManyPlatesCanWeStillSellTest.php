<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Recipe;
use App\Modules\Inventory\Models\RecipeLine;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\Inventory\Services\RecipeService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * আর কয় প্লেট বেচা যাবে — রেস্টুরেন্টের ধাপ ২।
 *
 * ── কেন এই সংখ্যাটা ──────────────────────────────────────────────────
 * গুদামে কত কেজি চাল আছে সেটা কাউন্টারের প্রশ্ন নয়। কাউন্টারের একটাই
 * প্রশ্ন: আর কয় প্লেট বেচা যাবে। ওটা না জানলে অর্ডার নেওয়া হয়, টাকা
 * নেওয়া হয়, তারপর রান্নাঘর বলে "শেষ"।
 *
 * এই ফাইলটা সেই সংখ্যাটার অঙ্ক পাহারা দেয় — কারণ একটা ভুল সংখ্যা
 * এখানে সবচেয়ে খারাপ: পর্দায় বড় করে লেখা থাকে, কেউ মিলিয়ে দেখে না,
 * আর ভুলটা ধরা পড়ে ঠিক তখন যখন খদ্দের সামনে দাঁড়িয়ে।
 */
class HowManyPlatesCanWeStillSellTest extends TestCase
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
        $this->actingAs($this->user);

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
    }

    private function make(string $name): Product
    {
        return Product::query()->create([
            'code' => 'KB-'.mb_substr(md5($name.microtime()), 0, 8),
            'name_en' => $name,
            'name_bn' => $name,
            'unit_id' => Unit::query()->orderBy('id')->firstOrFail()->id,
            'is_active' => true,
        ]);
    }

    /**
     * উপকরণ, আর গুদামে তার পরিমাণ।
     *
     * পরিমাণ **আর** দর — দুইটাই। `move()` কেবল পরিমাণ নাড়ে; দরটা
     * FIFO স্তরে বসে, আর দর ছাড়া বিক্রীত পণ্যের ব্যয় লেখা যায় না।
     */
    private function ingredient(string $name, string $onHand): Product
    {
        $product = $this->make($name);

        if (bccomp($onHand, '0', 4) > 0) {
            app(StockService::class)->move(
                product: $product,
                warehouse: $this->warehouse,
                sourceType: 'test.opening',
                sourceId: $product->id,
                floor: $onHand,
            );

            app(CostLayerService::class)->receive(
                product: $product,
                qty: $onHand,
                unitCost: '10.00',
                sourceType: 'test.opening',
                sourceId: $product->id,
            );
        }

        return $product;
    }

    /**
     * @param  list<array{product: Product, qty: string}>  $lines
     */
    private function dish(string $name, array $lines, string $yield = '1'): Recipe
    {
        $recipe = Recipe::query()->create([
            'product_id' => $this->make($name)->id,
            'kind' => Recipe::TO_ORDER,
            'yield_qty' => $yield,
            'is_active' => true,
        ]);

        foreach ($lines as $i => $line) {
            RecipeLine::query()->create([
                'recipe_id' => $recipe->id,
                'product_id' => $line['product']->id,
                'qty' => $line['qty'],
                'waste_pct' => '0',
                'sort' => $i,
            ]);
        }

        return $recipe->fresh(['lines.product']);
    }

    /**
     * সবচেয়ে ছোটটাই উত্তর — গড় নয়, যোগফল নয়।
     *
     * চাল দিয়ে একশো প্লেট হয়, তেল দিয়ে চারটা। উত্তর চার, কারণ একটা
     * উপকরণ ফুরালে গোটা পদটাই বন্ধ।
     */
    public function test_the_scarcest_ingredient_decides_the_number(): void
    {
        $rice = $this->ingredient('Rice', '100');
        $oil = $this->ingredient('Oil', '4');

        $biryani = $this->dish('Biryani', [
            ['product' => $rice, 'qty' => '1'],
            ['product' => $oil, 'qty' => '1'],
        ]);

        $answer = app(RecipeService::class)->portionsPossible($biryani, $this->warehouse);

        $this->assertSame(0, bccomp($answer['portions'], '4', 0),
            'সবচেয়ে ছোট উপকরণটা উত্তর ঠিক করেনি।');

        $this->assertSame($oil->id, $answer['limiting']?->id,
            'কোনটা আটকাচ্ছে সেটা ভুল বলা হয়েছে।');
    }

    /**
     * ভগ্নাংশ নিচের দিকে গোনা হয়।
     *
     * সাড়ে তিন প্লেট বলে কিছু নেই। উপরে গুনলে শেষ প্লেটটার উপকরণ
     * থাকত না — অর্থাৎ ঠিক সেই দৃশ্য যেটা এড়ানোর জন্য এই সংখ্যাটা।
     */
    public function test_half_a_plate_is_not_a_plate(): void
    {
        $rice = $this->ingredient('Rice', '7');

        $dish = $this->dish('Plain rice', [['product' => $rice, 'qty' => '2']]);

        $this->assertSame(0, bccomp(
            app(RecipeService::class)->portionsPossible($dish, $this->warehouse)['portions'],
            '3', 0,
        ));
    }

    /**
     * একটা উপকরণ শূন্য হলে পদটা শূন্য — বাকি সব ভরা থাকলেও।
     */
    public function test_one_empty_ingredient_closes_the_dish(): void
    {
        $rice = $this->ingredient('Rice', '500');
        $saffron = $this->ingredient('Saffron', '0');

        $dish = $this->dish('Kacchi', [
            ['product' => $rice, 'qty' => '1'],
            ['product' => $saffron, 'qty' => '1'],
        ]);

        $answer = app(RecipeService::class)->portionsPossible($dish, $this->warehouse);

        $this->assertSame(0, bccomp($answer['portions'], '0', 0));
        $this->assertSame($saffron->id, $answer['limiting']?->id);
    }

    /**
     * যে লাইনে কিছুই লাগে না সে কিছুই আটকায় না।
     *
     * কেউ একটা লাইনে ০ লিখে রাখেন ("পরে ঠিক করব")। ওটা দিয়ে ভাগ করলে
     * পাতাটাই ভেঙে যেত — আর একটা রান্নাঘরের বোর্ড ভাঙা মানে কাউন্টার
     * অন্ধ।
     */
    public function test_a_zero_line_neither_blocks_nor_breaks(): void
    {
        $rice = $this->ingredient('Rice', '10');
        $garnish = $this->ingredient('Garnish', '0');

        $dish = $this->dish('Polao', [
            ['product' => $rice, 'qty' => '1'],
            ['product' => $garnish, 'qty' => '0'],
        ]);

        $answer = app(RecipeService::class)->portionsPossible($dish, $this->warehouse);

        $this->assertSame(0, bccomp($answer['portions'], '10', 0));
        $this->assertSame($rice->id, $answer['limiting']?->id);
    }

    /**
     * বিক্রির পর সংখ্যাটা নিজেই কমে — ওটাই "রিয়েল-টাইম" কথাটার মানে।
     */
    public function test_the_number_falls_as_the_ingredients_leave(): void
    {
        $rice = $this->ingredient('Rice', '10');
        $dish = $this->dish('Khichuri', [['product' => $rice, 'qty' => '1']]);

        $recipes = app(RecipeService::class);

        $this->assertSame(0, bccomp($recipes->portionsPossible($dish, $this->warehouse)['portions'], '10', 0));

        $recipes->consume($dish, '4', $this->warehouse, 'test', 1);

        $this->assertSame(0, bccomp($recipes->portionsPossible($dish, $this->warehouse)['portions'], '6', 0),
            'উপকরণ বেরিয়ে গেছে, অথচ বোর্ডের সংখ্যা বদলায়নি।');
    }

    /**
     * পর্দাটা খোলে, আর JSON-টাও একই সংখ্যাই বলে।
     *
     * ── কেন দুইটা একসাথে মাপা ────────────────────────────────────────
     * পাতাটা প্রতি বিশ সেকেন্ডে JSON-টা ডাকে। দুইটা আলাদা হয়ে গেলে
     * পর্দায় একটা সংখ্যা বসত, বিশ সেকেন্ড পর আরেকটা — আর কেউ বলতে
     * পারত না কোনটা সত্যি।
     */
    public function test_the_board_and_its_refresh_say_the_same_thing(): void
    {
        $rice = $this->ingredient('Rice', '9');
        $dish = $this->dish('Tehari', [['product' => $rice, 'qty' => '3']]);

        $this->get(route('inventory.kitchen.index'))
            ->assertOk()
            ->assertSee($dish->product->name());

        $json = $this->getJson(route('inventory.kitchen.refresh'))->assertOk()->json();

        $mine = collect($json['dishes'])->firstWhere('id', $dish->id);

        $this->assertSame(3, $mine['portions']);
        $this->assertSame($rice->name(), $mine['limiting']);
    }

    /**
     * নিষ্ক্রিয় রেসিপি বোর্ডে ওঠে না।
     *
     * ওটা ইতিহাসের জন্য রাখা। বোর্ডে থাকলে কাউন্টার এমন একটা পদ
     * বেচতে যেত যেটা আর বানানোই হয় না।
     */
    public function test_a_retired_dish_is_not_on_the_board(): void
    {
        $rice = $this->ingredient('Rice', '50');
        $dish = $this->dish('Old special', [['product' => $rice, 'qty' => '1']]);

        $dish->forceFill(['is_active' => false])->save();

        $json = $this->getJson(route('inventory.kitchen.refresh'))->assertOk()->json();

        $this->assertNull(collect($json['dishes'])->firstWhere('id', $dish->id));
    }
}
