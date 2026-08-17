<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * চার্ট / বাল্ক DO — গোটা ক্যাটালগ এক শীটে।
 *
 * ── কেন এই পর্দাটা লাগে ─────────────────────────────────────────────
 * লাইন এডিটর একবারে একটা পণ্য নেয়, আর কাউন্টারে দাঁড়ানো ক্রেতার চারটা
 * জিনিসের জন্য ওটাই ঠিক। ডিলারের মাসিক চার্টের জন্য নয়: ওখানে ছাপা
 * তালিকা ধরে একশো সারি নামতে হয়, আর প্রতিটার জন্য আগে পণ্য খোঁজা মানে
 * একশোটা বাধা।
 *
 * ── এখানে কী পরখ করা হয়, আর কী নয় ──────────────────────────────────
 * শীটটা Alpine-এ চলে, তাই টাইপ করা ও Apply করাটা ব্রাউজারের কাজ।
 * সার্ভার দিকের যেটুকু ভুল হলে পর্দাটা মিথ্যা বলবে, সেটাই এখানে ধরা:
 * **শীটে যে মজুদের সংখ্যাগুলো ছাপা হয়, সেগুলো সত্যি কি না** — আর ওটাই
 * একমাত্র জিনিস যা এখানে ভুল হলে কেউ টের পেত না, কারণ সংখ্যাটা দেখতে
 * ঠিকই লাগে।
 */
class TheWholeCatalogueAsOneSheetTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
    }

    /** শীটটা চালানের ফর্মে সত্যিই আছে। */
    public function test_the_sheet_is_offered_on_the_challan_form(): void
    {
        $this->get(route('sales.challan.create'))
            ->assertOk()
            ->assertSee(__('sales::bulk.open'))
            ->assertSee(__('sales::bulk.nothing_until_apply'));
    }

    /**
     * শীটে প্রতিটা সচল পণ্য আছে — "গোটা ক্যাটালগ" কথাটার মানে ওটাই।
     *
     * একটাও বাদ পড়লে যিনি ছাপা তালিকা ধরে নামছেন তিনি একটা সারিতে এসে
     * থেমে যেতেন, আর তখন শীটটার পুরো উদ্দেশ্যই নষ্ট।
     */
    public function test_every_active_product_is_on_the_sheet(): void
    {
        $html = $this->get(route('sales.challan.create'))->assertOk()->getContent();

        $products = Product::query()->active()->get();

        $this->assertGreaterThan(0, $products->count());

        foreach ($products as $product) {
            $this->assertStringContainsString($product->code, $html,
                "{$product->code} শীটে নেই — ছাপা তালিকা ধরে নামলে ওই সারিতে এসে থামতে হত।");
        }
    }

    /**
     * শীটে ছাপা মজুদের সংখ্যাগুলো সত্যি।
     *
     * ── কেন এটাই এখানকার আসল পরীক্ষা ────────────────────────────────
     * ভুল সংখ্যা দেখতে ঠিক সংখ্যার মতোই। কেউ "পাওয়া যাবে ১২০" দেখে
     * ১২০ লিখে দিতেন, আর মাল বেরোনোর সময় জানা যেত ৪০-ও নেই।
     */
    public function test_the_stock_figures_on_the_sheet_are_the_real_ones(): void
    {
        $product = Product::query()->active()->firstOrFail();

        app(StockService::class)->move(
            product: $product,
            warehouse: $this->warehouse,
            sourceType: 'opening',
            sourceId: $product->id,
            floor: '25',
            date: now(),
        );

        $states = app(StockService::class)->statesForAll($this->warehouse);

        $this->assertSame(0, bccomp($states[$product->id]['available'],
            app(StockService::class)->availableQty($product, $this->warehouse), 4),
            'গোটা তালিকার হিসাব আর একক পণ্যের হিসাব দুই কথা বলছে।');

        $this->get(route('sales.challan.create'))
            ->assertOk()
            ->assertSee(rtrim(rtrim($states[$product->id]['available'], '0'), '.'), false);
    }

    /**
     * চারশো পণ্যেও একটা কোয়েরি।
     *
     * ── কেন এটা একটা টেস্টের যোগ্য ──────────────────────────────────
     * পণ্য ধরে ধরে গোনা কোডটা দেখতে নিরীহ, আর ডেমো তথ্যে দ্রুতই চলে।
     * আসল ডিপোর চারশো পণ্যে সেটাই চারশো কোয়েরি — পর্দা খুলতে কয়েক
     * সেকেন্ড, আর কেউ বলতে পারত না কেন।
     */
    public function test_the_whole_catalogue_costs_one_query(): void
    {
        DB::enableQueryLog();
        DB::flushQueryLog();

        app(StockService::class)->statesForAll($this->warehouse);

        $this->assertCount(1, DB::getQueryLog(),
            'গোটা ক্যাটালগের মজুদ একটার বেশি কোয়েরিতে গোনা হচ্ছে।');

        DB::disableQueryLog();
    }

    /**
     * যে পণ্যের কোনো চলাচল নেই তার ঘরও শূন্য দেখায়, ফাঁকা নয়।
     *
     * ── কেন এটা আলাদা করে পরখ করা হয় ────────────────────────────────
     * চলাচল না থাকলে পণ্যটা হিসাবের তালিকাতেই থাকে না — সেখানে সে
     * অনুপস্থিত, শূন্য নয়। শীট যদি অনুপস্থিতিকে ফাঁকা ঘর হিসেবে
     * দেখাত, পাঠক ভাবতেন সংখ্যাটা জানা যায়নি; শূন্য পড়ে তিনি জানেন
     * মাল নেই। দুইটা আলাদা কথা, আর দ্বিতীয়টাই সত্যি।
     */
    public function test_a_product_that_never_moved_reads_zero(): void
    {
        $fresh = Product::query()->create([
            'company_id' => CompanyContext::id(),
            'code' => 'NEVER-MOVED',
            'name_en' => 'Never Moved',
            'name_bn' => 'কখনো নড়েনি',
            'unit_id' => Product::query()->value('unit_id'),
            'sale_price' => '10',
            'purchase_price' => '8',
            'is_active' => true,
        ]);

        $states = app(StockService::class)->statesForAll($this->warehouse);

        $this->assertArrayNotHasKey($fresh->id, $states,
            'চলাচল ছাড়া পণ্যও হিসাবের তালিকায় ঢুকে পড়েছে।');

        /*
         * পর্দায় ঘরটা শূন্য — ফাঁকা নয়।
         *
         * শীটের তথ্যটা Alpine-এর অ্যাট্রিবিউটে JSON হয়ে বসে, তাই
         * উদ্ধৃতিগুলো `"` হয়ে যায়। কাঁচা লেখা খুঁজলে টেস্টটা
         * এসকেপের নিয়ম বদলালেই ভাঙত — তাই খোঁজা হয় ওই রূপেই।
         */
        $this->get(route('sales.challan.create'))
            ->assertOk()
            ->assertSee('NEVER-MOVED', false)
            ->assertSee('available\u0022:\u00220\u0022', false);
    }

    /**
     * ফ্রি-র ঘরটা চালানের শীটে নেই।
     *
     * ── কেন নেই, আর কেন সেটা ইচ্ছাকৃত ───────────────────────────────
     * `sal_challan_lines`-এ `free_qty` কলামটা আছে, কিন্তু চালানের সেবা
     * ওটা লেখে না আর নিশ্চিত করার সময় ফ্রি মাল নড়েও না। ঘরটা দেখালে
     * মানুষ সংখ্যা লিখতেন আর সেটা নীরবে হারিয়ে যেত — ঠিক যে ফাঁদে
     * ক্রয়ের free_qty পড়েছিল (তালিকার ৩৯ক)।
     *
     * এই টেস্টটা তাই একটা নিষেধ: ঘরটা ফেরাতে হলে আগে সেবার পথটা
     * বানাতে হবে, আর তখন এই টেস্টটাও বদলাতে হবে — নীরবে নয়।
     */
    public function test_the_challan_sheet_shows_no_free_column(): void
    {
        $this->get(route('sales.challan.create'))
            ->assertOk()
            ->assertDontSee(__('sales::bulk.total_free'));
    }
}
