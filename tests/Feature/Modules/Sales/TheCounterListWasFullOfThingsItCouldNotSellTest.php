<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * কাউন্টারের তালিকা এমন মাল দিয়ে ভরা ছিল যা বেচাই যায় না।
 *
 * ── ⓘ মালিকের নির্দেশ, ২১ সেপ্টেম্বর ২০২৬ ────────────────────────────
 * *"0 stock products ekhane asbe na"* — খোঁজার ঘরটা খুললেই প্রথম
 * তিরিশটা সারির প্রায় সবগুলোয় "মজুদ ০ কার্টন"।
 *
 * ── ⛔ কেন এটা নিছক অগোছালো নয় ───────────────────────────────────────
 * কাউন্টারের তালিকাটা **বেচার জন্য**, দেখার জন্য নয়। যে মাল নেই তার
 * সারি বিক্রেতাকে কেবল পেরোতে হয় — আর ডিপোতে শূন্য মজুদের পণ্যই বেশি,
 * তাই যেটা সত্যিই আছে সেটা খুঁজে পেতে স্ক্রল করতে হত।
 */
final class TheCounterListWasFullOfThingsItCouldNotSellTest extends TestCase
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
     * ⭐ যে পণ্যের মজুদ নেই, সে তালিকায় নেই। যার আছে, সে আছে।
     *
     * ⚠️ দাবিটা দুইমুখী: শূন্যটা **বাদ যায়** আর মজুদওয়ালাটা **থাকে**।
     * ⓘ কেবল প্রথমটা দেখলে পরীক্ষাটা এমন কোডেও সবুজ থাকত যেখানে
     * তালিকাটা পুরো খালি।
     */
    public function test_a_product_with_no_stock_is_not_offered(): void
    {
        [$empty, $stocked] = $this->twoProducts();

        $names = $this->catalogueNames();

        $this->assertContains($stocked->name(), $names, 'মজুদওয়ালা পণ্যটাই তালিকা থেকে হারিয়ে গেছে।');
        $this->assertNotContains($empty->name(), $names, 'শূন্য মজুদের পণ্যটা এখনো তালিকায়।');
    }

    /**
     * ⭐ ফ্রি মাল থাকলে সে থাকে — মেঝেতে শূন্য হলেও।
     *
     * ⛔ ফ্রি পরিমাণ বিক্রির যোগ্য মাল, কেবল তার দাম নেই। ⓘ কেবল
     * `available` দেখে ছাঁকলে উপহারের মালটা কাউন্টার থেকে হারাত, আর
     * সেটা গুদামে পড়ে থেকে মেয়াদ পেরোত।
     */
    public function test_free_stock_alone_keeps_a_product_on_the_list(): void
    {
        [$empty] = $this->twoProducts();

        $this->move($empty, ['free_change' => '6']);

        $this->assertContains(
            $empty->name(),
            $this->catalogueNames(),
            'কেবল ফ্রি মাল থাকা পণ্যটা তালিকা থেকে বাদ পড়েছে।',
        );
    }

    /**
     * তালিকার নামগুলো — পর্দা যা পায়, ঠিক তাই।
     *
     * @return list<string>
     */
    private function catalogueNames(): array
    {
        $html = (string) $this->get(route('sales.direct.create'))->assertOk()->getContent();

        $found = preg_match('~catalogue:\s*(\[.*?\]),\s*\n~s', $html, $hit);

        $this->assertSame(1, $found, 'পর্দার পণ্যতালিকাটাই পাওয়া গেল না — পরীক্ষাটা কিছু দেখছে না।');

        $rows = json_decode(html_entity_decode($hit[1], ENT_QUOTES), true);

        $this->assertIsArray($rows);

        return array_column($rows, 'name');
    }

    /**
     * একটা শূন্য, একটা মজুদওয়ালা — বাকি সব পণ্যের মজুদ মুছে ফেলা হয়,
     * যাতে দাবিটা ডেমোর অবস্থার উপর নির্ভর না করে।
     *
     * @return array{Product, Product}
     */
    private function twoProducts(): array
    {
        StockMovement::query()->delete();

        $products = Product::query()->active()->orderBy('id')->take(2)->get();

        $this->assertCount(2, $products, 'ডেমোতে দুইটা পণ্যও নেই।');

        $this->move($products[1], ['floor_change' => '9']);

        return [$products[0], $products[1]];
    }

    /** @param  array<string, string>  $changes */
    private function move(Product $product, array $changes): void
    {
        StockMovement::query()->create([
            'company_id' => CompanyContext::id(),
            'branch_id' => CompanyContext::branchId(),
            'warehouse_id' => Warehouse::query()->value('id'),
            'product_id' => $product->id,
            'trx_date' => now()->toDateString(),
            'source_type' => 'test',
            'source_id' => 0,
            ...$changes,
        ]);
    }
}
