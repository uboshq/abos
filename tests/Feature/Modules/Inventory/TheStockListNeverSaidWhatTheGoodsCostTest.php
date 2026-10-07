<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\Inventory\Services\StockService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * মজুদের তালিকা কখনো বলত না মালটা কত দামে কেনা।
 *
 * ── ⭐ মালিকের নির্দেশ, ২৮ সেপ্টেম্বর ২০২৬ ────────────────────────────
 * *"মজুদ দেখার পাতায় দামসহ দেখা (ক্রয়মূল্য ও মজুদের মূল্য), আর দাম
 * লুকিয়ে দেখা। দাম দেখা যাবে কেবল cost.view চাবি থাকলে।"*
 *
 * ── ⓘ কেন দুইটা আলাদা প্রশ্ন ──────────────────────────────────────────
 * ⭐ **অনুমতি** (`inventory.cost.view`) — চাবিটা আছে কি না।
 * ⭐ **পছন্দ** (`?cost=hide`) — এই মুহূর্তে দেখতে চান কি না।
 *
 * ⛔ দুইটা গুলিয়ে ফেললে পাহারাটা ফাঁকা হয়ে যেত: একটা হাতে লেখা
 * `?cost=show` চাবিহীন লোককে দাম দেখিয়ে দিত। ⓘ নিচে দুইটার **মিশ্রণ**
 * ধরেই দাবি লেখা।
 *
 * ── ⓘ চাবিটা নতুন নয় ─────────────────────────────────────────────────
 * `inventory.cost.view` আগে থেকেই আছে, আর `Product::purchase_price` ও
 * `StockMovement::unit_cost` ওটাতেই বাঁধা ([[module.php]]-র
 * `sensitive_fields`)। ⭐ এই কাজে নতুন চাবি বানানো হয়নি — একই চাবির
 * পেছনে আরও দুইটা সংখ্যা রাখা হয়েছে।
 *
 * ── ⚠️ দাবিগুলো যেভাবে লেখা, আর কেন ──────────────────────────────────
 * ⛔ HTML-এ সংখ্যা খোঁজা হয় না। ⓘ একটা মজুদের পাতা সংখ্যায় ভরা, তাই
 * `assertSee('1,200.00')` এমনিতেই সবুজ হতে পারত। ⭐ তার বদলে পাতার
 * নিজের ছক-সংজ্ঞা পড়া হয়: কোন কলামগুলো সত্যিই তৈরি হলো, আর তাদের
 * `render` কী ফেরত দিল।
 */
final class TheStockListNeverSaidWhatTheGoodsCostTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

        $this->product = $this->aProductWorth('10', '120');
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    // ── ⭐ আসল দাবি ───────────────────────────────────────────────────

    public function test_the_list_shows_what_the_goods_cost_and_what_they_are_worth(): void
    {
        $page = $this->openList();

        $this->assertTrue($this->hasColumn($page, __('inventory::field.purchase_price')),
            'ক্রয়মূল্যের কলামটাই নেই।');
        $this->assertTrue($this->hasColumn($page, __('inventory::field.stock_value')),
            'মজুদের মূল্যের কলামটাই নেই।');

        /*
         * ⓘ ১০টা এসেছে ১২০ দরে, তাই গড় ক্রয়মূল্য ১২০ আর মূল্য ১,২০০।
         * ⭐ সংখ্যাটা ঐ পণ্যের **নিজের সারির** ঘর থেকে পড়া — পাতায়
         * লেখা খুঁজে নয়, কারণ মজুদের পাতা সংখ্যায় ভরা।
         */
        $cells = $this->cellsFor($this->product, $page);

        $this->assertSame($this->asShown('120'), $cells['unit_cost']);
        $this->assertSame($this->asShown('1200'), $cells['stock_value']);
    }

    public function test_the_value_follows_the_quantity_the_row_itself_shows(): void
    {
        /*
         * ⚠️ এই দাবিটা ছাড়া মূল্যটা স্তরের মোট মূল্য হয়েই থাকতে পারত,
         * আর তখন সারিটা **নিজের সাথেই** মিলত না: পরিমাণ কমে গেছে, অথচ
         * মূল্য আগের মতো।
         */
        app(StockService::class)->issue(
            product: $this->product,
            warehouse: $this->warehouse,
            qty: '4',
            sourceType: 'test:cost',
            sourceId: 1,
        );

        app(CostLayerService::class)->issue($this->product, '4', 'test:cost', 1);

        $cells = $this->cellsFor($this->product);

        $this->assertSame($this->asShown('120'), $cells['unit_cost'],
            'দর বদলানোর কথা নয় — একই চালানের মাল বেরিয়েছে।');

        $this->assertSame($this->asShown('720'), $cells['stock_value'],
            'ছয়টা পড়ে আছে ১২০ দরে, তাই ৭২০ হওয়ার কথা।');
    }

    public function test_a_product_whose_cost_is_unknown_shows_a_dash_not_a_zero(): void
    {
        /*
         * ⛔ শূন্য লিখলে পর্দা বলত *"এই মাল বিনামূল্যে এসেছে"*, অথচ
         * সত্যিটা হলো **দাম জানা নেই**। ⓘ ফ্রি মালের স্তর তৈরি হয় না,
         * তাই ঘরটা বাস্তবেই ঘটে।
         */
        $noCost = $this->aProductWithStockButNoLayers();

        $cells = $this->cellsFor($noCost);

        $this->assertSame('—', $cells['unit_cost']);
        $this->assertSame('—', $cells['stock_value']);
    }

    // ── ⭐ দরজাটা: একই ব্যবহারকারী, চাবি বন্ধ তারপর খোলা ──────────────

    public function test_without_the_key_the_columns_are_not_there_at_all(): void
    {
        /*
         * ⚠️ একই ব্যবহারকারী, একই পাতা — কেবল চাবিটা আলাদা। ⛔ দুইজন
         * আলাদা ব্যবহারকারী দিয়ে দেখালে ৪০৩-টা সদস্যপদ বা কোম্পানির
         * পার্থক্যেও আসতে পারত, আর দাবিটা কিছুই প্রমাণ করত না।
         */
        $this->assertTrue($this->hasColumn($this->openList(), __('inventory::field.purchase_price')),
            'চাবি থাকা অবস্থাতেই কলামটা নেই — তাহলে নিচের অনুপস্থিতি কিছুই বলে না।');

        $this->takeTheCostKeyAway();

        $page = $this->openList();

        $this->assertFalse($this->hasColumn($page, __('inventory::field.purchase_price')));
        $this->assertFalse($this->hasColumn($page, __('inventory::field.stock_value')));
    }

    public function test_without_the_key_the_number_never_reaches_the_page_either(): void
    {
        /*
         * ⛔ কলামটা না দেখানো যথেষ্ট নয়। ⓘ সংখ্যাটা ভিউ-তথ্যে থেকে
         * গেলে একটা রপ্তানি, একটা JSON, বা ভবিষ্যতের কোনো পাতা ওটা
         * নীরবে বের করে দিত। ⭐ তাই চাবি না থাকলে কোয়েরিতেই যোগ হয় না।
         */
        $this->takeTheCostKeyAway();

        $row = $this->openList()->viewData('products')->getCollection()->first();

        $this->assertNull($row->layer_value_total ?? null,
            'চাবি নেই, তবু মজুদের মূল্য পাতার তথ্যে পৌঁছেছে।');
        $this->assertNull($row->layer_qty_total ?? null);
    }

    public function test_a_hand_typed_show_cannot_beat_the_missing_key(): void
    {
        $this->takeTheCostKeyAway();

        $this->assertFalse(
            $this->hasColumn($this->openList(['cost' => 'show']), __('inventory::field.purchase_price')),
            'URL-এ cost=show লিখে চাবি ছাড়াই দাম দেখা গেছে।');
    }

    // ── ⓘ পছন্দটা, অনুমতির ভিতরে ─────────────────────────────────────

    public function test_the_owner_can_put_the_prices_away(): void
    {
        $this->assertFalse(
            $this->hasColumn($this->openList(['cost' => 'hide']), __('inventory::field.purchase_price')));

        // ⭐ আর ফিরিয়ে আনাও যায় — লুকানোটা একমুখী দরজা নয়
        $this->assertTrue(
            $this->hasColumn($this->openList(['cost' => 'show']), __('inventory::field.purchase_price')));
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /**
     * অ্যাপ যেভাবে একটা অঙ্ক ছাপে, ঠিক সেভাবে।
     *
     * ⓘ [[resources/views/components/ui/amount.blade.php]] ব্যবহার করে
     * `number_format($value, 2)` — তাই প্রত্যাশাটাও তাই, হাতে লেখা
     * `"1,200.00"` নয়। ⚠️ হাতে লিখলে দাবিটা **চেহারার** দাবি হয়ে যেত,
     * আর হাজার-চিহ্নের রীতি বদলানোর দিনে মিথ্যা লাল দিত।
     */
    private function asShown(string $amount): string
    {
        return number_format((float) $amount, 2);
    }

    /** @param  array<string, string>  $query */
    private function openList(array $query = []): TestResponse
    {
        $page = $this->actingAs($this->user->fresh())
            ->get(route('inventory.stock.index', $query));

        $page->assertOk();

        return $page;
    }

    /**
     * ছকের শিরোনামগুলো — রেন্ডার হওয়া পাতা থেকে।
     *
     * ⛔ `viewData('columns')` কাজ করে না: ছকের সংজ্ঞাটা ভিউয়ের নিজের
     * ভিতরে তৈরি হয়, কন্ট্রোলার থেকে আসে না। ⓘ প্রথম লেখায় ওটাই ধরে
     * নিয়েছিলাম, আর ধরে নেওয়াটা ভুল ছিল।
     *
     * ⚠️ শিরোনাম ধরে দেখা হয়, পাতায় লেখা খুঁজে নয় — *"ক্রয়মূল্য"*
     * শব্দটা পাতার অন্য কোথাও থাকলেও `<th>`-এ না থাকলে কলামটা নেই।
     *
     * @return list<string>
     */
    private function headers(TestResponse $page): array
    {
        preg_match_all('#<th\b[^>]*>(.*?)</th>#s', $page->getContent(), $m);

        return array_values(array_map(
            fn (string $cell) => trim(preg_replace('/\s+/u', ' ', strip_tags($cell))),
            $m[1],
        ));
    }

    /** কলামটা আছে কি না — শিরোনামের লেখা ধরে। */
    private function hasColumn(TestResponse $page, string $label): bool
    {
        return in_array($label, $this->headers($page), true);
    }

    /**
     * একটা পণ্যের সারির শেষ দুইটা ঘর — ক্রয়মূল্য আর মজুদের মূল্য।
     *
     * ⓘ দামের কলাম দুইটা সবার **শেষে** যোগ হয়, তাই সারির শেষ দুইটা ঘরই
     * ওরা। ⚠️ পরে কেউ আরও কলাম যোগ করলে এই ধরে-নেওয়াটা ভাঙবে — তাই
     * নিচে শিরোনামের অবস্থানও মিলিয়ে দেখা হয়, আর না মিললে পরীক্ষাটা
     * থেমে বলে দেয় কেন।
     *
     * @return array{unit_cost: string, stock_value: string}
     */
    private function cellsFor(Product $product, ?TestResponse $page = null): array
    {
        $page ??= $this->openList();
        $html = $page->getContent();
        $headers = $this->headers($page);

        $costAt = array_search(__('inventory::field.purchase_price'), $headers, true);
        $valueAt = array_search(__('inventory::field.stock_value'), $headers, true);

        $this->assertNotFalse($costAt, 'ক্রয়মূল্যের শিরোনামই নেই।');
        $this->assertSame($costAt + 1, $valueAt,
            'দুইটা দামের কলাম আর পাশাপাশি নেই — সারি পড়ার নিয়মটা বদলাতে হবে।');

        $found = preg_match(
            '#<tr\b[^>]*>(?:(?!</tr>).)*?'.preg_quote($product->code, '#').'(?:(?!</tr>).)*?</tr>#s',
            $html,
            $rowMatch,
        );

        $this->assertSame(1, $found, "পণ্য {$product->code}-এর সারিটা পাতায় নেই।");

        preg_match_all('#<td\b[^>]*>(.*?)</td>#s', $rowMatch[0], $cells);

        $text = array_map(
            fn (string $c) => trim(preg_replace('/\s+/u', ' ', strip_tags($c))),
            $cells[1],
        );

        $this->assertCount(count($headers), $text,
            'সারির ঘরের সংখ্যা শিরোনামের সংখ্যার সাথে মেলে না।');

        return ['unit_cost' => $text[$costAt], 'stock_value' => $text[$valueAt]];
    }

    /**
     * ⓘ চাবিটা **এই ব্যবহারকারীর কাছ থেকেই** নেওয়া হয়, আর দলের ভিতরে —
     * অনুমতিগুলো কোম্পানিতে বাঁধা (`team_foreign_key => company_id`),
     * তাই দল না বসিয়ে কেড়ে নিলে কিছুই ঘটত না আর দাবিটা নীরবে সবুজ হত।
     */
    private function takeTheCostKeyAway(): void
    {
        CompanyContext::forCompany(
            (int) CompanyContext::id(),
            fn () => $this->user->revokePermissionTo('inventory.cost.view'),
        );

        foreach ($this->user->roles as $role) {
            CompanyContext::forCompany(
                (int) CompanyContext::id(),
                fn () => $role->revokePermissionTo('inventory.cost.view'),
            );
        }

        $this->user = $this->user->fresh();
        $this->user->forgetCachedPermissions();
    }

    /** দাম জানা একটা পণ্য — স্তরে মাল, আর তাকেও মাল। */
    private function aProductWorth(string $qty, string $rate): Product
    {
        $product = $this->aFreshProduct();

        app(CostLayerService::class)->receive($product, $qty, $rate, 'test:cost', 1, 'IN-1', '2026-09-01');

        app(StockService::class)->move(
            product: $product,
            warehouse: $this->warehouse,
            sourceType: 'test:cost',
            sourceId: 1,
            floor: $qty,
        );

        return $product->refresh();
    }

    /** তাকে মাল, অথচ খরচের কোনো স্তর নেই — দাম জানা নেই এমন ঘর। */
    private function aProductWithStockButNoLayers(): Product
    {
        $product = $this->aFreshProduct();

        app(StockService::class)->move(
            product: $product,
            warehouse: $this->warehouse,
            sourceType: 'test:cost',
            sourceId: 2,
            floor: '5',
        );

        return $product->refresh();
    }

    private function aFreshProduct(): Product
    {
        $seed = Product::query()->orderBy('id')->firstOrFail();

        $product = $seed->replicate(['public_id', 'code', 'barcode']);
        $product->code = 'CV-'.str_pad((string) Product::query()->count(), 4, '0', STR_PAD_LEFT);
        $product->barcode = null;
        $product->is_active = true;
        $product->save();

        return $product->refresh();
    }
}
