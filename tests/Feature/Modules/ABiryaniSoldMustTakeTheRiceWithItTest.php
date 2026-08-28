<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Recipe;
use App\Modules\Inventory\Models\RecipeLine;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\Inventory\Services\RecipeService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\Unit;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * একটা বিরিয়ানি বিক্রি হলে চালও কমে।
 *
 * ── কেন এটাই রেস্টুরেন্টের প্রথম পরীক্ষা ─────────────────────────────
 * "চিকেন বিরিয়ানি" নামে কিছু গুদামে ঢোকে না — ওটা তৈরি হয় চাল, মাংস আর
 * তেল থেকে। রেসিপি কাজ না করলে খাতা বলবে বিরিয়ানি বিক্রি হয়েছে আর
 * **চালের বস্তা অক্ষত থেকে যাবে**।
 *
 * ভুলটা নীরব: পর্দায় কিছু ভাঙে না, বিল ঠিকই ছাপে, আর ধরা পড়ে মাসের
 * শেষে যখন গুদাম গুনে দেখা যায় খাতার সাথে মিলছে না। তখন কোন দিনের কোন
 * বিক্রিতে গোলমাল হয়েছে সেটা আর বের করা যায় না।
 *
 * ── দুইটা ধরন, আর কেন একটাকে অন্যটা দিয়ে চালানো যায় না ───────────────
 * `to_order` — বিক্রির মুহূর্তে উপকরণ কমে (চা, চিকেন ফ্রাই)।
 * `batch` — রান্নার মুহূর্তে কমে, বিক্রিতে নয় (বিরিয়ানির হাঁড়ি)।
 *
 * হাঁড়িকে অর্ডারে-রান্না ধরলে সন্ধ্যায় ৩০ প্লেট নষ্ট হলে খাতা কিছুই
 * জানবে না — ওই ৩০ প্লেটের উপকরণ কোনোদিন কমেইনি।
 */
class ABiryaniSoldMustTakeTheRiceWithItTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private Product $rice;

    private Product $meat;

    private Product $biryani;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs($user);

        $this->warehouse = Warehouse::query()->orderBy('id')->firstOrFail();

        /*
         * উপকরণ আর খাবার — ডেমো থেকে নেওয়া হয় না।
         *
         * ডেমোতে রেস্টুরেন্টের পণ্য নেই, আর থাকলেও তার দর ও স্টক অন্য
         * পরীক্ষার সাথে জড়িয়ে যেত। নিজেরটা বানালে অঙ্কটা হুবহু জানা
         * থাকে, আর ব্যর্থ হলে কারণটাও স্পষ্ট।
         */
        $this->rice = $this->product('RICE', 'Rice');
        $this->meat = $this->product('MEAT', 'Chicken');
        $this->biryani = $this->product('BIRYANI', 'Chicken Biryani');

        $this->receive($this->rice, '100');
        $this->receive($this->meat, '50');
    }

    /**
     * অর্ডারে-রান্না: এক প্লেট বেচলে ঠিক এক প্লেটের উপকরণ কমে।
     */
    public function test_selling_one_plate_takes_one_plate_of_ingredients(): void
    {
        // ১০ প্লেটের রেসিপি: ৫ কেজি চাল, ২ কেজি মাংস
        $recipe = $this->recipe(Recipe::TO_ORDER, yield: '10', lines: [
            [$this->rice, '5', '0'],
            [$this->meat, '2', '0'],
        ]);

        app(RecipeService::class)->consume(
            $recipe,
            servings: '3',
            warehouse: $this->warehouse,
            sourceType: 'test.sale',
            sourceId: 1,
        );

        // ৩ প্লেট = ৩/১০ × ৫ = ১.৫ কেজি চাল, ৩/১০ × ২ = ০.৬ কেজি মাংস
        $this->assertOnHand($this->rice, '98.5000');
        $this->assertOnHand($this->meat, '49.4000');
    }

    /**
     * অপচয় ধরা হয় — গুদাম থেকে বেশি বেরোয়, রান্নায় কম যায়।
     *
     * ── কেন এটাই সবচেয়ে সহজে ভুল হয় ─────────────────────────────────
     * ১ কেজি আলুর খোসা ছাড়ালে ৮৫০ গ্রাম থাকে। রেসিপিতে লেখা থাকে
     * "৮৫০ গ্রাম", কারণ রান্নায় ওটাই যায়। কিন্তু গুদাম থেকে ১ কেজিই
     * বেরোয়।
     *
     * ৮৫০ গ্রাম কমালে প্রতি রান্নায় ১৫০ গ্রাম খাতায় থেকে যায় যা
     * বাস্তবে নেই — মাসে কয়েক বস্তা।
     *
     * অঙ্কটাও উল্টো করা সহজ: ৮৫০-এর সাথে ১৫% **যোগ** করলে পাওয়া যায়
     * ৯৭৭.৫, আর ওটা ভুল — ৯৭৭.৫ গ্রামের খোসা ছাড়ালে ৮৩১ থাকে। ঠিক
     * অঙ্কটা ভাগ: ৮৫০ ÷ ০.৮৫ = ১০০০।
     */
    public function test_waste_comes_out_of_the_store_too(): void
    {
        $recipe = $this->recipe(Recipe::TO_ORDER, yield: '1', lines: [
            [$this->rice, '0.85', '15'],
        ]);

        app(RecipeService::class)->consume(
            $recipe,
            servings: '1',
            warehouse: $this->warehouse,
            sourceType: 'test.sale',
            sourceId: 2,
        );

        // ০.৮৫ ÷ ০.৮৫ = ১.০০ — এক কেজিই বেরোয়
        $this->assertOnHand($this->rice, '99.0000');
    }

    /**
     * হাঁড়িতে-রান্না খাবারের **বিক্রিতে** উপকরণ কমে না।
     *
     * ── কেন এই দাবিটা উল্টো দিক থেকে লেখা ────────────────────────────
     * বেশিরভাগ পরীক্ষা দেখে "কাজটা হয়েছে কি না"। এটা দেখে **কাজটা
     * হয়নি তো?** — কারণ এখানে ভুলটা হয় দুইবার কমিয়ে ফেলার।
     *
     * চাল কমে হাঁড়ি চড়ানোর সময়। বিক্রিতেও কমালে এক সপ্তাহে স্টক শূন্যে
     * নামত যদিও বস্তা গুদামেই আছে।
     */
    public function test_a_batch_dish_does_not_consume_again_when_sold(): void
    {
        $recipe = $this->recipe(Recipe::BATCH, yield: '50', lines: [
            [$this->rice, '10', '0'],
        ]);

        $service = app(RecipeService::class);

        $this->assertFalse(
            $service->consumesOnSale($this->biryani),
            'হাঁড়িতে-রান্না খাবার বিক্রির সময় উপকরণ কমানোর কথা নয় — ওগুলো রান্নাতেই কমেছে।',
        );

        // আর অর্ডারে-রান্না হলে কমার কথা
        $recipe->forceFill(['kind' => Recipe::TO_ORDER])->save();

        $this->assertTrue(
            app(RecipeService::class)->consumesOnSale($this->biryani->fresh()),
            'অর্ডারে-রান্না খাবারের বিক্রিতে উপকরণ কমতেই হবে।',
        );
    }

    /**
     * ফলন শূন্য হলে কিছুই কমে না — গোটা বিক্রি ভাঙে না।
     *
     * শূন্য দিয়ে ভাগ করলে অঙ্কটা ছুড়ে ফেলত, আর একটা ভুল বসানো রেসিপি
     * কাউন্টারের প্রতিটা বিক্রি আটকে দিত। শূন্য ফলন মানে রেসিপিটা অচল,
     * তাই সে কিছুই দাবি করে না।
     */
    public function test_a_recipe_with_no_yield_consumes_nothing(): void
    {
        $recipe = $this->recipe(Recipe::TO_ORDER, yield: '0', lines: [
            [$this->rice, '5', '0'],
        ]);

        $taken = app(RecipeService::class)->consume(
            $recipe,
            servings: '3',
            warehouse: $this->warehouse,
            sourceType: 'test.sale',
            sourceId: 3,
        );

        $this->assertSame([], $taken);
        $this->assertOnHand($this->rice, '100.0000');
    }

    /**
     * উপকরণের নাম ও পরিমাণ কমানোর আগেই বলা যায়।
     *
     * খরচের রিপোর্টে "এই প্লেটে কত টাকার মাল গেল" দেখাতে একই হিসাব
     * লাগে। দুইবার লিখলে একদিন একটা বদলাত আর অন্যটা থেকে যেত।
     */
    public function test_what_will_be_taken_can_be_asked_before_taking(): void
    {
        $recipe = $this->recipe(Recipe::TO_ORDER, yield: '4', lines: [
            [$this->rice, '2', '0'],
            [$this->meat, '1', '0'],
        ]);

        $needs = app(RecipeService::class)->needsFor($recipe, '2');

        $this->assertCount(2, $needs);
        $this->assertSame($this->rice->id, $needs[0]['product']->id);
        $this->assertSame('1.000000', $needs[0]['qty']);   // ২/৪ × ২
        $this->assertSame('0.500000', $needs[1]['qty']);   // ২/৪ × ১

        // জিজ্ঞেস করায় স্টকের কিছু বদলায়নি
        $this->assertOnHand($this->rice, '100.0000');
    }

    /**
     * আসল বিক্রিতেই উপকরণ কমে — সেবা ধরে ডেকে নয়।
     *
     * ── কেন উপরের পরীক্ষাগুলো যথেষ্ট নয় ─────────────────────────────
     * ওগুলো `RecipeService`-কে সরাসরি ডাকে, অর্থাৎ প্রমাণ করে **সেবাটা
     * ঠিক**। কিন্তু বিক্রির পথে ওই সেবাটা কেউ না ডাকলে সবগুলোই সবুজ
     * থাকত আর গুদাম তবু ভুল হত।
     *
     * এই প্রকল্পের সবচেয়ে চেনা ফাঁদ: টুকরোটা কাজ করে, জোড়াটা লাগানো
     * হয়নি। তাই এখানে একটা সত্যিকারের বিল কাটা হয়।
     */
    public function test_a_real_invoice_takes_the_ingredients(): void
    {
        $this->recipe(Recipe::TO_ORDER, yield: '10', lines: [
            [$this->rice, '5', '0'],
            [$this->meat, '2', '0'],
        ]);

        $this->sell($this->biryani, '3');

        // ৩ প্লেট = ১.৫ কেজি চাল, ০.৬ কেজি মাংস
        $this->assertOnHand($this->rice, '98.5000');
        $this->assertOnHand($this->meat, '49.4000');

        /*
         * আর খাবারটার নিজের স্টক নড়ে না।
         *
         * "চিকেন বিরিয়ানি" কোনোদিন গুদামে ঢোকেনি; ওটাও কমাতে গেলে
         * ঋণাত্মক স্টক তৈরি হত এমন একটা জিনিসের যা কেনাই হয়নি।
         */
        $this->assertOnHand($this->biryani, '0.0000');
    }

    /**
     * উপকরণহীন রেসিপির খাবার বিক্রি হয় না — নীরবেও নয়।
     *
     * পরিকল্পনার ধাপ ১-এর প্রথম শর্ত। উপকরণ ছাড়া বিক্রি হলে বিল ছাপে,
     * টাকা আসে, কোথাও ভুল দেখায় না — আর স্টক নীরবে ভুল হতে থাকে।
     */
    public function test_a_dish_with_an_empty_recipe_refuses_to_sell(): void
    {
        Recipe::query()->create([
            'product_id' => $this->biryani->id,
            'kind' => Recipe::TO_ORDER,
            'yield_qty' => '1',
            'is_active' => true,
        ]);

        $this->expectException(ValidationException::class);

        $this->sell($this->biryani, '1');
    }

    /**
     * উপকরণ ফুরিয়ে গেলে বার্তাটা খাবারের ভাষায় আসে।
     *
     * কাউন্টারে বসা মানুষ চাল বেচছেন না, বিরিয়ানি বেচছেন। "চাল কম"
     * বললে তাঁকে নিজে হিসাব করে বুঝতে হত কোন খাবারটা আটকে গেল।
     */
    public function test_running_out_of_an_ingredient_is_said_in_the_dish_s_words(): void
    {
        $this->recipe(Recipe::TO_ORDER, yield: '1', lines: [
            [$this->rice, '500', '0'],   // গুদামে আছে ১০০
        ]);

        try {
            $this->sell($this->biryani, '1');
            $this->fail('উপকরণ না থাকলেও বিক্রি হয়ে গেল।');
        } catch (ValidationException $e) {
            $said = implode(' ', $e->validator->errors()->all());

            $this->assertStringContainsString('Chicken Biryani', $said,
                'বার্তায় খাবারের নাম থাকতে হবে — কাউন্টারের মানুষ ওটাই বেচছেন।');
            $this->assertStringContainsString('Rice', $said,
                'কোন উপকরণটা ফুরিয়েছে সেটাও বলতে হবে, নাহলে কী আনতে হবে জানা যায় না।');
        }
    }

    // ── সাজানোর সাহায্য ──────────────────────────────────────────────

    /** একটা সত্যিকারের বিল — তৈরি করে নিশ্চিত করা। */
    private function sell(Product $product, string $qty): void
    {
        $service = app(SalesInvoiceService::class);

        $service->confirm(
            $service->create(
                [
                    'customer_id' => Customer::query()->orderBy('id')->firstOrFail()->id,
                    'warehouse_id' => $this->warehouse->id,
                    'trx_date' => now()->toDateString(),
                ],
                [['product_id' => $product->id, 'qty' => $qty, 'rate' => '250.00']],
            )
        );
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

    /**
     * গুদামে মাল ঢোকানো — পরিমাণ **আর** দর, দুইটাই।
     *
     * ── কেন দুইটা ডাক লাগে ──────────────────────────────────────────
     * `StockService::move()` কেবল পরিমাণ নাড়ে; দরটা আলাদা একটা খাতায়
     * থাকে — FIFO স্তর। বিক্রির সময় খরচ ওই স্তর থেকেই টানা হয়।
     *
     * প্রথমবার কেবল পরিমাণ ঢুকিয়ে চালাতে গিয়ে বিলটা আটকে গিয়েছিল:
     * "ক্রয়মূল্যের হিসাব নেই"। পাহারাটা ঠিকই বলেছিল — মাল ছিল, দর
     * ছিল না, আর দর ছাড়া বিক্রীত পণ্যের ব্যয় লেখা যায় না।
     */
    private function receive(Product $product, string $qty): void
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
            unitCost: '60.00',
            sourceType: 'test.opening',
            sourceId: $product->id,
        );
    }

    /**
     * @param  list<array{0: Product, 1: string, 2: string}>  $lines
     */
    private function recipe(string $kind, string $yield, array $lines): Recipe
    {
        $recipe = Recipe::query()->create([
            'product_id' => $this->biryani->id,
            'kind' => $kind,
            'yield_qty' => $yield,
            'is_active' => true,
        ]);

        foreach ($lines as $i => [$product, $qty, $waste]) {
            RecipeLine::query()->create([
                'recipe_id' => $recipe->id,
                'product_id' => $product->id,
                'qty' => $qty,
                'waste_pct' => $waste,
                'sort' => $i,
            ]);
        }

        return $recipe->fresh(['lines.product']);
    }

    /**
     * গুদামে কত আছে।
     *
     * `floorQty`, `freeQty` নয় — দুইটা আলাদা জিনিস, আর প্রথমবার ভুলটা
     * এখানেই হয়েছিল। `free` মানে **ফ্রি/উপহারে দেওয়া** মাল; মূল স্টকটা
     * `floor`। ভুল ঘরটা পড়ায় প্রতিটা দাবি ০ দেখাচ্ছিল, যদিও মাল
     * ঠিকই ঢুকেছিল।
     */
    private function assertOnHand(Product $product, string $expected): void
    {
        $this->assertSame(
            $expected,
            app(StockService::class)->floorQty($product->fresh(), $this->warehouse),
            "{$product->code}-এর স্টক মিলছে না।",
        );
    }
}
