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
use Tests\TestCase;

/**
 * রান্না করা খাবার গুদামে ঢুকত গায়ে কোনো তারিখ ছাড়াই।
 *
 * ── ⭐ মালিকের নির্দেশ, ২৩ সেপ্টেম্বর ২০২৬ ───────────────────────────
 * *"etaw koro"* — লট ছাড়া মাল ঢোকার শেষ দরজাটাও বন্ধ করার কথা।
 *
 * ── ⚠️ কেন খাবারে লটের প্রশ্নটা সবচেয়ে জরুরি ───────────────────────
 * ⓘ কার্টনে ছাপা মালের মেয়াদ মাসে গোনা হয়, রান্না করা খাবারের ঘণ্টায়।
 * ⛔ আর খাবারে বিষক্রিয়া হলে প্রশ্নটা কখনোই *"কোন পণ্য"* নয় — প্রশ্নটা
 * *"কোন দিনের রান্না, আর ওটা কার কাছে গেছে"*।
 *
 * ── ⓘ লট নম্বরটা মানুষ লেখেন না ─────────────────────────────────────
 * ⚠️ রান্নার লট **কাগজটাই** — উৎপাদনের ডকুমেন্ট নম্বর। ⛔ হাতে লিখতে
 * দিলে রাঁধুনি প্রতিদিন কিছু একটা বানাতেন, আর দুইদিনের রান্না একই
 * নম্বরে পড়ে যেত — ঠিক সেই দুইদিন আলাদা করাই বিষক্রিয়ার দিনে প্রথম কাজ।
 */
final class TheCookedFoodWentInWithoutADateOnItTest extends TestCase
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

    /**
     * ⭐ রান্নার লট নম্বরটা কাগজেরই নম্বর।
     */
    public function test_the_lot_number_is_the_document_number(): void
    {
        $production = $this->cooked(lots: true);

        $movement = $this->arrivalOf($production);

        $this->assertNotNull($movement?->batch_id, 'রান্না করা খাবারটা লট ছাড়াই গুদামে ঢুকেছে।');

        $this->assertSame(
            (string) $production->document_no,
            (string) $movement->batch?->batch_no,
            'লট নম্বরটা কাগজের নম্বর নয় — কেউ হাতে কিছু বসিয়েছে।',
        );
    }

    /**
     * ⓘ আর যে খাবারে লট ধরা হয় না, তার কিছুই বদলায়নি।
     *
     * ── ⚠️ কেন এই দাবিটা ছাড়া উপরেরটা বিপজ্জনক ──────────────────────
     * ⛔ উপরেরটা একা থাকলে **সব পণ্যে লট বসানো** একটা যন্ত্রও সবুজ পেত।
     * ⓘ আর তখন চা-চিনির মতো মালেও একটা বানানো লট বসত, আর বানানো লট
     * রিকলের খাতায় একটা মিথ্যা সারি।
     */
    public function test_a_dish_that_keeps_no_lots_still_goes_in_plain(): void
    {
        $production = $this->cooked(lots: false);

        $movement = $this->arrivalOf($production);

        $this->assertNotNull($movement, 'লট ধরা হয় না এমন খাবারটা গুদামে ঢোকেইনি।');

        $this->assertNull($movement->batch_id, 'লট ধরা হয় না এমন খাবারেও একটা লট বসে গেছে।');
    }

    /**
     * ⭐ আর মেয়াদটা লটের গায়ে বসে।
     *
     * ⓘ খাবারে মেয়াদই লটের থাকার মূল কারণ — ⚠️ লট আছে অথচ মেয়াদ নেই,
     * এমন সারি থেকে *"এটা কি এখনো খাওয়ার মতো"* প্রশ্নের উত্তর পাওয়া
     * যায় না।
     */
    public function test_the_expiry_lands_on_the_lot(): void
    {
        $when = now()->addDays(2)->toDateString();

        $production = $this->cooked(lots: true, expiry: $when);

        $this->assertSame(
            $when,
            $this->arrivalOf($production)?->batch?->expiry_date?->toDateString(),
            'মেয়াদটা লটের গায়ে বসেনি।',
        );
    }

    /** একটা রান্না — নিশ্চিত করা পর্যন্ত। */
    private function cooked(bool $lots, ?string $expiry = null): Production
    {
        $recipe = $this->aRecipe();

        $dish = Product::query()->findOrFail($recipe->product_id);
        $dish->track_batch = $lots;
        $dish->save();

        $service = app(ProductionService::class);

        $production = $service->create([
            'recipe_id' => $recipe->id,
            'qty' => '5',
            'trx_date' => now()->toDateString(),
            'expiry_date' => $expiry,
        ]);

        return $service->confirm($production);
    }

    /**
     * একটা রেসিপি — ডেমোতে একটাও নেই, আর সেটাই স্বাভাবিক।
     *
     * ── ⚠️ এটা মেপে শেখা ─────────────────────────────────
     * ⓘ প্রথমে `Recipe::firstOrFail()` লেখা ছিল, আর তিনটা দাবিই
     * লাল হয়েছিল: ডেমোতে কোনো রেসিপি নেই, কারণ রেস্টুরেন্ট
     * মডিউলটা মালিকের সিদ্ধান্তে বন্ধ।
     *
     * ⛔ ডেমোর সারির ওপর দাঁড়ানো দাবি পরের বছর ডেমো বদলালেই
     * ভাঙে — ⓘ তাই রেসিপিটা এই ফাইলের নিজের।
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

        /* ⓘ একটা উপকরণ — রেসিপি খালি হলে রান্নাই হয় না */
        RecipeLine::query()->create([
            'company_id' => CompanyContext::id(),
            'recipe_id' => $recipe->id,
            'product_id' => $products[1]->id,
            'qty' => '1',
            'sort' => 0,
        ]);

        /* ⚠️ উপকরণটা তাকে থাকতে হয়, নাহলে রান্না আটকে যায় */
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

    /** ঐ রান্নার ফলে গুদামে ঢোকা সারিটা। */
    private function arrivalOf(Production $production): ?StockMovement
    {
        return StockMovement::query()
            ->where('source_type', Production::STOCK_SOURCE)
            ->where('source_id', $production->id)
            ->where('product_id', $production->product_id)
            ->where('floor_change', '>', 0)
            ->with('batch')
            ->first();
    }
}
