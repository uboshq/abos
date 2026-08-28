<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Production;
use App\Modules\Inventory\Models\Recipe;
use App\Modules\Inventory\Models\RecipeLine;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\Inventory\Services\ProductionService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\Unit;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * হাঁড়ি একবার চড়ে, প্লেট পঞ্চাশবার যায়।
 *
 * ── কেন এটাই এই ফিচারের সবচেয়ে বড় ঝুঁকি ──────────────────────────────
 * ভুলটা হয় **দুইবার কমিয়ে ফেলার**: একবার হাঁড়ি চড়ানোর সময়, আবার প্রতি
 * প্লেট বিক্রির সময়। তখন এক সপ্তাহে চালের স্টক শূন্যে নামে যদিও বস্তা
 * গুদামেই আছে।
 *
 * উল্টো ভুলটাও সমান খারাপ: বিক্রিতে কিছুই না কমা। তখন খাতা বলে ৫০ প্লেট
 * বিক্রি হয়েছে আর তৈরি খাবারের স্টক ৫০-ই থেকে যায়।
 *
 * এই ফাইলটা দুইটাই মাপে, একই হাঁড়ির উপরে।
 */
class ThePotIsCookedOnceAndSoldFiftyTimesTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private Product $rice;

    private Product $meat;

    private Product $biryani;

    private Recipe $recipe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs($user);

        $this->warehouse = Warehouse::query()->orderBy('id')->firstOrFail();

        $this->rice = $this->product('RICE', 'Rice');
        $this->meat = $this->product('MEAT', 'Chicken');
        $this->biryani = $this->product('BIRYANI', 'Chicken Biryani');

        // ১০০ কেজি চাল @ ৬০, ৫০ কেজি মাংস @ ৩০০
        $this->receive($this->rice, '100', '60.00');
        $this->receive($this->meat, '50', '300.00');

        // ৫০ প্লেটের হাঁড়ি: ১০ কেজি চাল, ৪ কেজি মাংস
        $this->recipe = $this->batchRecipe(yield: '50', lines: [
            [$this->rice, '10'],
            [$this->meat, '4'],
        ]);
    }

    /**
     * রান্নার পর উপকরণ কমে আর তৈরি খাবার গুদামে ঢোকে।
     */
    public function test_cooking_takes_the_ingredients_and_puts_the_dish_on_the_shelf(): void
    {
        $this->cook('50');

        $this->assertOnHand($this->rice, '90.0000');
        $this->assertOnHand($this->meat, '46.0000');
        $this->assertOnHand($this->biryani, '50.0000');
    }

    /**
     * তৈরি খাবারের দর উপকরণেরই — নতুন করে কিছু হিসাব করা হয় না।
     *
     * ১০ কেজি চাল × ৬০ = ৬০০, ৪ কেজি মাংস × ৩০০ = ১২০০, মোট ১৮০০।
     * ৫০ প্লেটে ভাগ করলে প্রতি প্লেট ৩৬ টাকা।
     */
    public function test_the_dish_carries_the_cost_of_what_went_into_it(): void
    {
        $production = $this->cook('50');

        $this->assertSame('1800.0000', (string) $production->cost_total);
        $this->assertSame('36.0000', $production->unitCost());

        // আর ওই দরেই স্তরটা বসেছে
        $this->assertSame(
            '1800.0000',
            app(CostLayerService::class)->valueOnHand($this->biryani->fresh()),
        );
    }

    /**
     * অর্ধেক হাঁড়ি রান্না হলে উপকরণও অর্ধেক।
     *
     * রেসিপির ফলন ৫০, আজ হয়েছে ২৫ — তখন ৫ কেজি চাল, ২ কেজি মাংস।
     * বাস্তবের সংখ্যাটা না ধরলে খাতা রোজ ৫০ ধরত।
     */
    public function test_half_a_pot_takes_half_the_ingredients(): void
    {
        $this->cook('25');

        $this->assertOnHand($this->rice, '95.0000');
        $this->assertOnHand($this->meat, '48.0000');
        $this->assertOnHand($this->biryani, '25.0000');
    }

    /**
     * **এটাই সবচেয়ে জরুরি দাবি** — বিক্রিতে চাল আর কমে না।
     *
     * হাঁড়ির খাবার বিক্রি হলে কমে তৈরি খাবারটা নিজে। উপকরণ আগেই
     * কমে গেছে; আবার কমালে চাল দুইবার খরচ হত।
     */
    public function test_selling_a_plate_takes_the_dish_not_the_rice_again(): void
    {
        $this->cook('50');

        $this->sell($this->biryani, '3');

        // তৈরি খাবার কমল
        $this->assertOnHand($this->biryani, '47.0000');

        // আর চাল-মাংস যেখানে ছিল সেখানেই
        $this->assertOnHand($this->rice, '90.0000');
        $this->assertOnHand($this->meat, '46.0000');
    }

    /**
     * উপকরণ না থাকলে রান্না হয় না, আর বার্তাটা খাবারের ভাষায়।
     */
    public function test_cooking_without_enough_ingredients_is_refused(): void
    {
        try {
            $this->cook('600');   // ১২০ কেজি চাল লাগত, আছে ১০০
            $this->fail('উপকরণ না থাকলেও রান্না হয়ে গেল।');
        } catch (ValidationException $e) {
            $said = implode(' ', $e->validator->errors()->all());

            $this->assertStringContainsString('Chicken Biryani', $said);
            $this->assertStringContainsString('Rice', $said);
        }

        // আর কিছুই নড়েনি
        $this->assertOnHand($this->rice, '100.0000');
        $this->assertOnHand($this->biryani, '0.0000');
    }

    /**
     * খসড়া অবস্থায় কিছুই নড়ে না।
     *
     * রাঁধুনি হাঁড়ি চড়িয়ে দিয়ে পরে গুনে বলেন কয় প্লেট হলো — ততক্ষণে
     * সংখ্যাটা বদলাতে পারে। খসড়াতেই স্টক নড়লে ওই বদলটা আর করা যেত না।
     */
    public function test_a_draft_moves_nothing(): void
    {
        app(ProductionService::class)->create([
            'recipe_id' => $this->recipe->id,
            'warehouse_id' => $this->warehouse->id,
            'qty' => '50',
        ]);

        $this->assertOnHand($this->rice, '100.0000');
        $this->assertOnHand($this->biryani, '0.0000');
    }

    /** একই কাগজ দুইবার নিশ্চিত করা যায় না — চাল দুইবার কমত। */
    public function test_a_paper_cannot_be_confirmed_twice(): void
    {
        $production = $this->cook('50');

        $this->expectException(ValidationException::class);

        app(ProductionService::class)->confirm($production);
    }

    /**
     * পর্দাগুলো খোলে, আর নিশ্চিত করার বোতামটা সত্যিই কাজ করে।
     *
     * ── কেন সেবার পরীক্ষা যথেষ্ট নয় ─────────────────────────────────
     * উপরের সব দাবি `ProductionService`-কে সরাসরি ডাকে, অর্থাৎ প্রমাণ
     * করে **সেবাটা ঠিক**। কিন্তু ওই সেবায় পৌঁছানোর একমাত্র পথ এই
     * পর্দা — আর জোড়াটা লাগানো না থাকলে সবগুলোই সবুজ থাকত আর
     * রাঁধুনি কিছুই করতে পারতেন না।
     */
    public function test_the_screens_open_and_the_confirm_button_works(): void
    {
        $this->get(route('inventory.production.index'))->assertOk();
        $this->get(route('inventory.production.create'))->assertOk();

        // খসড়া বানানো — পর্দার পথেই
        $this->post(route('inventory.production.store'), [
            'recipe_id' => $this->recipe->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
            'qty' => '50',
        ])->assertRedirect();

        $production = Production::query()->latest('id')->firstOrFail();

        $this->get(route('inventory.production.show', $production))->assertOk();

        // এখনো কিছুই নড়েনি
        $this->assertOnHand($this->rice, '100.0000');

        $this->post(route('inventory.production.confirm', $production))->assertRedirect();

        $this->assertOnHand($this->rice, '90.0000');
        $this->assertOnHand($this->biryani, '50.0000');

        // নিশ্চিত হওয়ার পর পাতাটা উপকরণের সারিও দেখায়
        $this->get(route('inventory.production.show', $production))
            ->assertOk()
            ->assertSee('Rice');
    }

    /**
     * অর্ডারে-রান্না রেসিপি এই পর্দায় বাছাই করা যায় না।
     *
     * বাছা গেলে উপকরণ দুইবার কমত — একবার এখানে, আরেকবার বিক্রির সময়।
     * তালিকায় না থাকাই প্রথম পাহারা, আর যাচাইটা দ্বিতীয়: হাতে বানানো
     * POST তালিকা মানে না।
     */
    public function test_a_made_to_order_recipe_is_not_offered_here(): void
    {
        $this->recipe->forceFill(['kind' => Recipe::TO_ORDER])->save();

        $offered = $this->get(route('inventory.production.create'))
            ->assertOk()
            ->viewData('recipes');

        $this->assertSame([], $offered, 'অর্ডারে-রান্না রেসিপি রান্নার পর্দায় আসার কথা নয়।');
    }

    /**
     * খাদ্য-খরচের রিপোর্ট সত্যি বলে।
     *
     * ── কেন এই সংখ্যাটার ভুল হওয়া সবচেয়ে বিপজ্জনক ───────────────────
     * রেস্টুরেন্টে সিদ্ধান্ত এই একটা শতাংশের উপরেই হয়: দাম বাড়াব কি না,
     * রেসিপি হালকা করব কি না, ওই পদটা মেনু থেকে তুলে দেব কি না।
     *
     * ভুল সংখ্যা কোনো ত্রুটি দেখায় না — কেবল ভুল সিদ্ধান্ত আনে, আর
     * সেটা ধরা পড়ে অনেক পরে।
     *
     * ── অঙ্কটা হাতে মিলিয়ে দেখা ─────────────────────────────────────
     * ৫০ প্লেটের হাঁড়িতে ১৮০০ টাকার মাল → প্রতি প্লেট ৩৬।
     * ৩ প্লেট বিক্রি @ ২৫০ → বিক্রয় ৭৫০, উপকরণ ১০৮।
     * ১০৮ ÷ ৭৫০ = **১৪.৪%**।
     */
    public function test_the_food_cost_report_tells_the_truth(): void
    {
        $this->cook('50');
        $this->sell($this->biryani, '3');

        $rows = app(ReportEngine::class)
            ->run('inventory.food_cost', [
                'from' => now()->subDay()->toDateString(),
                'to' => now()->addDay()->toDateString(),
            ]);

        /* সারিগুলো অ্যারে, বস্তু নয় — `ReportResult::$rows` তাই ঘোষণা করে। */
        $row = $rows->rows[0] ?? null;

        $this->assertNotNull($row, 'রিপোর্টে একটাও সারি নেই — অথচ রান্না করা খাবার বিক্রি হয়েছে।');

        /*
         * `bccomp`, `assertSame` নয় — সংখ্যাটা মেলানো হয়, লেখাটা নয়।
         *
         * SQL-এর `SUM()` দশমিকের ঘর বাড়িয়ে ফেরত দেয়: `108.00000000`,
         * `108.0000` নয়। প্রথম লেখায় লেখা ধরে মেলানো হয়েছিল আর টেস্ট
         * লাল হয়েছিল — অথচ **অঙ্কটা ঠিকই ছিল**।
         *
         * টাকার তুলনা লেখা ধরে করলে একদিন ঠিক উল্টোটাও ঘটে: দুইটা
         * আলাদা সংখ্যা একই লেখায় মিলে যায়।
         */
        $this->assertSame(0, bccomp('750', (string) $row['revenue'], 4));
        $this->assertSame(0, bccomp('108', (string) $row['food_cost'], 4));
        $this->assertSame(0, bccomp('14.40', (string) $row['food_cost_pct'], 2));
    }

    /**
     * রেসিপি নেই এমন পণ্য এই রিপোর্টে আসে না।
     *
     * চালের "খাদ্য-খরচ" মানে কেবল ক্রয়মূল্য, আর ওটা মুনাফার রিপোর্টেই
     * আছে। এখানে এলে গড়টা অর্থহীন হত — প্রায় ১০০% খরচের সারিগুলো
     * রান্না করা খাবারের সংখ্যাকে চাপা দিত।
     */
    public function test_a_product_without_a_recipe_stays_out_of_it(): void
    {
        $this->cook('50');
        $this->sell($this->biryani, '3');
        $this->sell($this->rice, '2');

        $rows = app(ReportEngine::class)
            ->run('inventory.food_cost', [
                'from' => now()->subDay()->toDateString(),
                'to' => now()->addDay()->toDateString(),
            ]);

        /*
         * নামের ঘরটা `CODE - Name` আকারে আসে (`productName()`), কেবল
         * নাম নয় — তাই মেলানোটা "ভেতরে আছে কি না" ধরে।
         */
        $names = implode(' | ', array_column($rows->rows, 'product_name'));

        $this->assertStringContainsString('Chicken Biryani', $names);
        $this->assertStringNotContainsString(
            'Rice',
            $names,
            'রেসিপিহীন পণ্য খাদ্য-খরচের রিপোর্টে আসার কথা নয়।',
        );
    }

    // ── সাজানোর সাহায্য ──────────────────────────────────────────────

    private function cook(string $qty): Production
    {
        $service = app(ProductionService::class);

        return $service->confirm($service->create([
            'recipe_id' => $this->recipe->id,
            'warehouse_id' => $this->warehouse->id,
            'qty' => $qty,
        ]));
    }

    private function sell(Product $product, string $qty): void
    {
        $service = app(SalesInvoiceService::class);

        $service->confirm($service->create(
            [
                'customer_id' => Customer::query()->orderBy('id')->firstOrFail()->id,
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString(),
            ],
            [['product_id' => $product->id, 'qty' => $qty, 'rate' => '250.00']],
        ));
    }

    private function product(string $code, string $name): Product
    {
        return Product::query()->create([
            'code' => $code,
            'name_en' => $name,
            'name_bn' => $name,
            'unit_id' => Unit::query()->orderBy('id')->firstOrFail()->id,
            'is_active' => true,
        ]);
    }

    private function receive(Product $product, string $qty, string $unitCost): void
    {
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
            unitCost: $unitCost,
            sourceType: 'test.opening',
            sourceId: $product->id,
        );
    }

    /**
     * @param  list<array{0: Product, 1: string}>  $lines
     */
    private function batchRecipe(string $yield, array $lines): Recipe
    {
        $recipe = Recipe::query()->create([
            'product_id' => $this->biryani->id,
            'kind' => Recipe::BATCH,
            'yield_qty' => $yield,
            'is_active' => true,
        ]);

        foreach ($lines as $i => [$product, $qty]) {
            RecipeLine::query()->create([
                'recipe_id' => $recipe->id,
                'product_id' => $product->id,
                'qty' => $qty,
                'waste_pct' => '0',
                'sort' => $i,
            ]);
        }

        return $recipe->fresh(['lines.product', 'product']);
    }

    private function assertOnHand(Product $product, string $expected): void
    {
        $this->assertSame(
            $expected,
            app(StockService::class)->floorQty($product->fresh(), $this->warehouse),
            "{$product->code}-এর স্টক মিলছে না।",
        );
    }
}
