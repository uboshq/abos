<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Http\Controllers\StockController;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * মজুদের তালিকা ভরা ছিল এমন পণ্যে, যাদের গায়ে কোনো মাল নেই।
 *
 * ── ⭐ মালিকের নির্দেশ, ২৮ সেপ্টেম্বর ২০২৬ ────────────────────────────
 * *"যে পণ্যের মজুদ শূন্য, সেটা তালিকায় আসবে না। শূন্যগুলো দেখার জন্য
 * একটা ছাঁকনি থাকবে।"*
 *
 * ── ⛔ আগের নিয়মটা কেন ভুল উত্তর দিত ─────────────────────────────────
 * [[StockController::index()]]-এর শর্ত ছিল *"সক্রিয়, **অথবা** এখনো কিছু
 * ধরে আছে"*। ⓘ দ্বিতীয় অংশটা ইচ্ছাকৃত আর দরকারি — নিষ্ক্রিয় পণ্যের গায়ে
 * মাল থাকলে সেটা লুকালে *"গুদামে মাল, পর্দায় কিছু নেই"* হত, আর সেটা
 * একবার ঘটেছিলও।
 *
 * ⚠️ কিন্তু প্রথম অংশটা **সক্রিয়-অথচ-শূন্য** পণ্যও টেনে আনত, আর একটা
 * ডিপোর তালিকায় ওগুলোই সংখ্যায় বেশি। ⛔ ফল: যে পণ্যটা আজ ফুরিয়ে আসছে
 * সেটা তিন নম্বর পাতায় চলে যেত, কারণ প্রথম দুই পাতা ভরা থাকত এমন সারিতে
 * যাদের নিয়ে আজ কারও কিছু করার নেই।
 *
 * ── ⛔ আর একটা দ্বিতীয় ভুল, যা এই কাজেই ধরা পড়ল ──────────────────────
 * "কিছু ধরে আছে" শর্তটা **চারটা** ঘর গুনত (মেঝে, আটকানো, বসার অপেক্ষায়,
 * ফ্রি), অথচ তালিকা **সাতটা** ঘরের সংখ্যা দেখাত। ⓘ আগের নিয়মে সেটা
 * নিরীহ ছিল: সক্রিয় পণ্য `active()` দিয়েই চলে আসত।
 *
 * ⛔ কিন্তু শূন্য সারি লুকানো শুরু হলে ওটা মারাত্মক হয়ে যেত — `reserved`,
 * `free_reserved` বা `unplaced_free`-তে মাল থাকা পণ্য শর্তের চোখে শূন্য,
 * তাই **তালিকা থেকেই উধাও**। ⚠️ অর্থাৎ একটা সারাই ঠিক সেই পুরনো
 * অভিযোগটাকেই ফিরিয়ে আনত: *"স্টক দেখাচ্ছে না"*।
 *
 * ⭐ তাই ঘরগুলোর নাম এখন এক জায়গায় ([[StockController::BOXES]]), আর
 * নিচের দাবিটা ঐ সাতটার প্রত্যেকটাকে আলাদা করে ধরে।
 *
 * ── ⓘ এই ফাইলটা কী মাপে না ───────────────────────────────────────────
 * ⛔ সংখ্যাগুলো ঠিক কি না — সেটা [[TheFreeCartonWasInvisibleEverywhereTest]]
 * আর [[StockMovementReportTest]]-এর কাজ। ⓘ এখানকার প্রশ্ন একটাই: **কোন
 * সারিগুলো পর্দায় আসে**।
 */
final class TheStockListWasFullOfProductsWithNoStockTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs($user);

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    // ── ⭐ আসল দাবি ───────────────────────────────────────────────────

    public function test_a_product_holding_nothing_stays_out_of_the_list(): void
    {
        $empty = $this->productWithNoStock();

        $this->assertNotContains($empty->id, $this->listed(),
            'গায়ে কোনো মাল নেই, তবু পণ্যটা তালিকায় আছে।');
    }

    public function test_the_zero_filter_is_where_those_products_are_found(): void
    {
        /*
         * ⚠️ এই দাবিটা ছাড়া সারাইটা "লুকিয়ে ফেলা" হয়ে যেত, আর মালিক
         * শূন্য মজুদের পণ্য কোথাও দেখতে পেতেন না — অর্থাৎ তথ্যটা হারিয়ে
         * যেত, কেবল সরানো হত না।
         */
        $empty = $this->productWithNoStock();

        $this->assertContains($empty->id, $this->listed('zero'),
            'শূন্য মজুদের ছাঁকনিতেও পণ্যটা নেই — সারিটা তাহলে হারিয়ে গেছে।');

        $this->assertNotContains($empty->id, $this->listed(),
            'ডিফল্ট ছাঁকনিতেও চলে এসেছে, তাই ছাঁকনি দুইটা আসলে এক।');
    }

    /**
     * ⭐ সাতটা ঘরের **প্রত্যেকটা** আলাদা করে ধরা।
     *
     * ── ⛔ কেন প্রত্যেকটা আলাদা, একটা নমুনা নয় ─────────────────────────
     * তিনটা ঘর (`reserved`, `free_reserved`, `unplaced_free`) পুরনো শর্তে
     * ছিলই না। ⓘ কেবল `floor` দিয়ে একটা দাবি লিখলে সেটা সবুজ হত আর
     * ঐ তিনটা ঘরের ভুলটা ধরাই পড়ত না।
     *
     * ⚠️ আর গোনাটা [[StockController::BOXES]] থেকেই আসে, হাতে লেখা তালিকা
     * থেকে নয় — তাই কেউ একটা নতুন ঘর যোগ করলে এই দাবিটা **নিজে থেকেই**
     * তার জন্যও চলবে। ⓘ হাতে লেখা হলে নতুন ঘরটা অপরীক্ষিত থাকত, আর
     * সেটাই আজকের ভুলটার জন্ম-পদ্ধতি।
     */
    public function test_stock_in_any_one_of_the_seven_boxes_keeps_a_product_listed(): void
    {
        $this->assertCount(7, StockController::BOXES,
            'ঘরের সংখ্যা বদলেছে — দাবিটা আর সবগুলো ধরছে কি না দেখে নিন।');

        foreach (StockController::BOXES as $i => $column) {
            $product = $this->productWithNoStock();

            // `floor_change` → `floor`, `unplaced_free_change` → `unplacedFree`
            $box = lcfirst(str_replace('_', '', ucwords(substr($column, 0, -7), '_')));

            /*
             * ⓘ পুরো তালিকাটা একবারে খোলা হয়, কারণ PHP নামযুক্ত
             * আর্গুমেন্টের **পরে** unpack নেয় না — তাই ঘরটার নামও
             * এই অ্যারেরই একটা চাবি।
             */
            app(StockService::class)->move(...[
                'product' => $product,
                'warehouse' => $this->warehouse,
                'sourceType' => 'test:boxes',
                'sourceId' => 100 + $i,
                $box => '5',
            ]);

            $this->assertContains($product->id, $this->listed(),
                "`{$column}`-এ মাল আছে, তবু পণ্যটা তালিকা থেকে উধাও — "
                .'শর্তটা এই ঘরটাকে গোনে না।');

            $this->assertNotContains($product->id, $this->listed('zero'),
                "`{$column}`-এ মাল আছে, অথচ পণ্যটা শূন্য মজুদের তালিকায়।");
        }
    }

    // ── ⓘ যা বদলায়নি, আর বদলানো চলবে না ─────────────────────────────

    public function test_all_shows_what_the_screen_showed_before(): void
    {
        $empty = $this->productWithNoStock();
        $held = $this->productWithNoStock();

        app(StockService::class)->move(
            product: $held,
            warehouse: $this->warehouse,
            sourceType: 'test:boxes',
            sourceId: 1,
            floor: '9',
        );

        $all = $this->listed('all');

        $this->assertContains($empty->id, $all);
        $this->assertContains($held->id, $all);
    }

    public function test_an_unknown_filter_falls_back_to_hiding_the_zeros(): void
    {
        /*
         * ⛔ অজানা মান চুপচাপ "সব" হয়ে গেলে পুরনো আচরণটা একটা হাতে লেখা
         * URL দিয়েই ফিরে আসত, আর কেউ টেরও পেত না।
         */
        $empty = $this->productWithNoStock();

        $this->assertNotContains($empty->id, $this->listed('abcd'));
    }

    public function test_an_inactive_product_still_holding_stock_is_listed(): void
    {
        /*
         * ⚠️ এটাই সেই পুরনো বাগের পাহারা যেটার জন্য "অথবা কিছু ধরে আছে"
         * অংশটা লেখা হয়েছিল: নিষ্ক্রিয় করা মানে *"আর কিনব না"*, কখনোই
         * *"যা আছে তা ভুলে যাও"*। ⛔ টাকা ইতিমধ্যে খরচ হয়ে গেছে।
         */
        $product = $this->productWithNoStock();

        app(StockService::class)->move(
            product: $product,
            warehouse: $this->warehouse,
            sourceType: 'test:boxes',
            sourceId: 2,
            floor: '7',
        );

        $product->forceFill(['is_active' => false])->save();

        $this->assertContains($product->id, $this->listed(),
            'নিষ্ক্রিয় পণ্যের গায়ে মাল আছে, তবু তালিকায় নেই — '
            .'গুদামে মাল, পর্দায় কিছু নেই।');
    }

    public function test_an_inactive_product_at_zero_stays_out_even_of_the_zero_filter(): void
    {
        /*
         * ⓘ এই সিদ্ধান্তটা আগেই নেওয়া ছিল আর এই কাজে বদলানো হয়নি:
         * শূন্য হয়ে যাওয়া নিষ্ক্রিয় পণ্য নিয়ে কারও কিছু করার নেই।
         * ⭐ দাবিটা আছে যাতে ওটা কেউ অজান্তে বদলে না ফেলে।
         */
        $product = $this->productWithNoStock();
        $product->forceFill(['is_active' => false])->save();

        $this->assertNotContains($product->id, $this->listed('zero'));
        $this->assertNotContains($product->id, $this->listed());
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /**
     * তালিকায় যে পণ্যগুলো সত্যিই এল — রেন্ডার হওয়া পাতার নিজের তথ্য থেকে।
     *
     * ⛔ HTML-এ নাম খোঁজা হয় না: ডেমোর পণ্যের নাম পাতার অন্য জায়গাতেও
     * থাকে (মেনু, খোঁজার বাক্সের পুরনো মান), আর তখন দাবিটা অন্ধ হত।
     *
     * @return list<int>
     */
    private function listed(?string $stock = null): array
    {
        $page = $this->get(route('inventory.stock.index', $stock === null ? [] : ['stock' => $stock]));

        $page->assertOk();

        return $this->idsOn($page);
    }

    /** @return list<int> */
    private function idsOn(TestResponse $page): array
    {
        return $page->viewData('products')
            ->getCollection()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * একটা নতুন সক্রিয় পণ্য, যার গায়ে একটাও চলাচল নেই।
     *
     * ⛔ ডেমোর পণ্য ব্যবহার করা যায় না: সিডার ওদের গায়ে মাল বসায়, তাই
     * "শূন্য" দাবিটা প্রথম দিন থেকেই মিথ্যা হত। ⓘ আর এটাই এই ফাইলের
     * সবচেয়ে সহজ ফাঁদ — সবুজ দেখাত, কিছুই মাপত না।
     */
    private function productWithNoStock(): Product
    {
        $seed = Product::query()->orderBy('id')->firstOrFail();

        $product = $seed->replicate(['public_id', 'code', 'barcode']);
        $product->code = 'ZS-'.str_pad((string) Product::query()->count(), 4, '0', STR_PAD_LEFT);
        $product->barcode = null;
        $product->is_active = true;
        $product->save();

        return $product->refresh();
    }
}
