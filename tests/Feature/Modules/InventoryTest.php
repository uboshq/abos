<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\ProductService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Inventory\Services\WarehouseService;
use App\Modules\MasterData\Models\ReasonCode;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Inventory — Phase 6।
 *
 * এই মডিউলের একটাই কেন্দ্রীয় দাবি, আর এখানকার বেশিরভাগ পরীক্ষা সেটাই
 * যাচাই করে: **স্টক একটা খতিয়ান, একটা সংখ্যা নয়।** "আছে কত" প্রশ্নের
 * উত্তর সবসময় চলাচলের যোগফল, আর সেই কারণেই খাতার সংখ্যা আর সারির
 * যোগফল কখনো আলাদা হতে পারে না।
 *
 * দ্বিতীয় দাবি চারটা অবস্থার অঙ্ক: Floor − Reserved − Hold = Available।
 */
class InventoryTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->user);

        // ডেমো ডাটাতেই গুদাম আছে, তাই এখানে নতুন একটা বানানো হয় না —
        // বানালে সেটা "প্রথম গুদাম" হত না, আর প্রধান গুদামের নিয়মগুলো
        // ভুল গুদামের উপর পরীক্ষা করা হত
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
    }

    private function stock(): StockService
    {
        return app(StockService::class);
    }

    private function product(string $name = 'Test Product', array $overrides = []): Product
    {
        return app(ProductService::class)->create([
            'name_en' => $name,
            'purchase_price' => 100,
            'sale_price' => 120,
            'reorder_level' => 0,
            ...$overrides,
        ]);
    }

    private function reason(string $context): ReasonCode
    {
        return ReasonCode::query()->inContext($context)->active()->firstOrFail();
    }

    private function holdReason(): ReasonCode
    {
        // HOLD প্রসঙ্গে প্রমিত তালিকায় কিছু নেই, তাই টেস্টেই বানানো —
        // আসল প্রতিষ্ঠান নিজের কারণগুলো Settings থেকে বসাবে
        return ReasonCode::query()->create([
            'code' => 'PRICE-HOLD',
            'name_en' => 'Held for a better price',
            'name_bn' => 'দাম বাড়ার অপেক্ষায়',
            'context' => ReasonCode::HOLD,
            'returns_to_stock' => true,
            'is_active' => true,
        ]);
    }

    private function receive(Product $product, string $qty): StockMovement
    {
        return $this->stock()->move(
            product: $product,
            warehouse: $this->warehouse,
            sourceType: 'test_receipt',
            sourceId: 1,
            floor: $qty,
        );
    }

    // ── স্টক একটা খতিয়ান ────────────────────────────────────────────────

    /**
     * পণ্যের টেবিলে কোনো পরিমাণের কলাম নেই।
     *
     * থাকলে সেটা একদিন সারির যোগফলের সাথে মিলত না — একটা ব্যর্থ
     * ট্রানজেকশন, সমান্তরাল দুইটা বিল, বা নতুন কোনো পথ যেটা কলামটার
     * কথা জানে না। তখন "খাতায় ৫০, তাকে ৪৭" প্রশ্নের কোনো উত্তর থাকত না।
     */
    public function test_a_product_carries_no_quantity_column(): void
    {
        $columns = Schema::getColumnListing('inv_products');

        foreach (['qty', 'quantity', 'stock', 'on_hand', 'floor_qty'] as $forbidden) {
            $this->assertNotContains($forbidden, $columns, "পরিমাণ কলাম রাখা যাবে না: {$forbidden}");
        }
    }

    public function test_stock_is_the_sum_of_its_movements(): void
    {
        $product = $this->product();

        $this->receive($product, '100');
        $this->receive($product, '50');
        $this->stock()->move(
            product: $product, warehouse: $this->warehouse,
            sourceType: 'test_issue', sourceId: 1, floor: '-30',
        );

        $this->assertSame(0, bccomp($this->stock()->floorQty($product), '120', 4));
    }

    // ── চারটা অবস্থার অঙ্ক ──────────────────────────────────────────────

    /**
     * Floor − Reserved − Hold = Available।
     *
     * এই একটা লাইনই পুরো মডিউলের ভিত্তি। Reserved ও Hold-এর মাল তাকেই
     * আছে, তাই Floor থেকে বাদ যায় না — শুধু বেচা যায় না। আলাদা করে
     * সরিয়ে রাখলে গণনার সময় তাকের সংখ্যা আর খাতার সংখ্যা মিলত না,
     * অথচ কোনো ভুল হয়নি।
     */
    public function test_available_is_floor_less_reserved_and_held(): void
    {
        $product = $this->product();

        $this->receive($product, '100');

        // ২০ অর্ডারে ধরা
        $this->stock()->move(
            product: $product, warehouse: $this->warehouse,
            sourceType: 'test_order', sourceId: 1, reserved: '20',
        );

        // ১৫ আটকানো
        $this->stock()->hold($product, $this->warehouse, '15', $this->holdReason());

        $states = $this->stock()->statesFor($product);

        $this->assertSame(0, bccomp($states['floor'], '100', 4));
        $this->assertSame(0, bccomp($states['reserved'], '20', 4));
        $this->assertSame(0, bccomp($states['hold'], '15', 4));
        $this->assertSame(0, bccomp($states['available'], '65', 4), '100 − 20 − 15 = 65');
    }

    /**
     * আটকানো মাল তাকেই থাকে।
     *
     * Floor থেকে বাদ দিলে গুদামে দাঁড়িয়ে গোনা মানুষ ১০০ পেতেন আর খাতা
     * বলত ৮৫ — অথচ কেউ কিছু সরায়নি।
     */
    public function test_holding_does_not_take_stock_off_the_floor(): void
    {
        $product = $this->product();
        $this->receive($product, '100');

        $this->stock()->hold($product, $this->warehouse, '15', $this->holdReason());

        $this->assertSame(0, bccomp($this->stock()->floorQty($product), '100', 4));
    }

    public function test_releasing_gives_the_stock_back(): void
    {
        $product = $this->product();
        $this->receive($product, '100');

        $reason = $this->holdReason();

        $this->stock()->hold($product, $this->warehouse, '40', $reason);
        $this->stock()->release($product, $this->warehouse, '15', $reason);

        $this->assertSame(0, bccomp($this->stock()->holdQty($product), '25', 4));
        $this->assertSame(0, bccomp($this->stock()->availableQty($product), '75', 4));
    }

    // ── নিয়ম ────────────────────────────────────────────────────────────

    /**
     * তাকে যা নেই তা বের করা যায় না।
     */
    public function test_stock_that_is_not_there_cannot_leave(): void
    {
        $product = $this->product();
        $this->receive($product, '10');

        $this->expectException(ValidationException::class);

        $this->stock()->move(
            product: $product, warehouse: $this->warehouse,
            sourceType: 'test_issue', sourceId: 1, floor: '-11',
        );
    }

    /**
     * যা বেচা যায় তার বেশি আটকানো যায় না।
     *
     * নাহলে Available ঋণাত্মক হয়ে যেত, আর ঋণাত্মক "বিক্রয়যোগ্য" বলে
     * কিছু নেই — ওটা দেখে কেউ বুঝত না কী করতে হবে।
     */
    public function test_more_than_available_cannot_be_held(): void
    {
        $product = $this->product();
        $this->receive($product, '10');

        $this->expectException(ValidationException::class);

        $this->stock()->hold($product, $this->warehouse, '11', $this->holdReason());
    }

    /**
     * কারণ ছাড়া মাল আটকানো যায় না, আর কারণটা ঠিক প্রসঙ্গের হতে হবে।
     *
     * বিক্রয়-ফেরতের কারণ দিয়ে মাল আটকানো গেলে "কত মাল ক্ষতিগ্রস্ত"
     * প্রশ্নের উত্তরে ফেরত আসা মালও গোনা হত।
     */
    public function test_a_hold_needs_a_hold_reason(): void
    {
        $product = $this->product();
        $this->receive($product, '100');

        $this->expectException(ValidationException::class);

        $this->stock()->hold($product, $this->warehouse, '10', $this->reason(ReasonCode::SALES_RETURN));
    }

    public function test_a_movement_that_moves_nothing_is_refused(): void
    {
        $product = $this->product();

        $this->expectException(ValidationException::class);

        $this->stock()->move(
            product: $product, warehouse: $this->warehouse,
            sourceType: 'test', sourceId: 1,
        );
    }

    // ── গণনা ও সমন্বয় ───────────────────────────────────────────────────

    /**
     * সমন্বয়ে পার্থক্যটা লেখা হয়, নতুন সংখ্যাটা নয়।
     *
     * "৫০ ছিল, ৪৭ পাওয়া গেল, তাই −৩" — এভাবে লিখলে পরে জিজ্ঞেস করা
     * যায় "ওই তিনটা কোথায় গেল"। শুধু ৪৭ বসিয়ে দিলে প্রশ্নটাই আর করা
     * যেত না, আর খতিয়ানে একটা ব্যাখ্যাহীন লাফ থাকত।
     */
    public function test_a_count_records_the_difference_not_the_new_figure(): void
    {
        $product = $this->product();
        $this->receive($product, '50');

        $movement = $this->stock()->adjust(
            product: $product,
            warehouse: $this->warehouse,
            countedQty: '47',
            reason: $this->reason(ReasonCode::STOCK_ADJUSTMENT),
        );

        $this->assertNotNull($movement);
        $this->assertSame(0, bccomp((string) $movement->floor_change, '-3', 4));
        $this->assertSame(0, bccomp($this->stock()->floorQty($product), '47', 4));
    }

    public function test_a_count_that_matches_writes_nothing(): void
    {
        $product = $this->product();
        $this->receive($product, '50');

        $before = StockMovement::query()->count();

        $movement = $this->stock()->adjust(
            product: $product,
            warehouse: $this->warehouse,
            countedQty: '50',
            reason: $this->reason(ReasonCode::STOCK_ADJUSTMENT),
        );

        // শূন্য সারি খতিয়ানে শুধু ভিড় বাড়ায়
        $this->assertNull($movement);
        $this->assertSame($before, StockMovement::query()->count());
    }

    // ── গুদাম ───────────────────────────────────────────────────────────

    /**
     * প্রতিটা গুদামের হিসাব আলাদা।
     *
     * না হলে নেত্রকোনার মাল ময়মনসিংহের তালিকায় দেখাত, আর সেলসম্যান
     * এমন জিনিস বেচতেন যা তার শাখায় নেই — ধরা পড়ত মাল দিতে গিয়ে,
     * ক্রেতার সামনে।
     */
    public function test_each_warehouse_counts_on_its_own(): void
    {
        $product = $this->product();

        $second = app(WarehouseService::class)->create([
            'code' => 'NTK',
            'name_en' => 'Netrakona Store',
        ]);

        $this->receive($product, '60');

        $this->stock()->move(
            product: $product, warehouse: $second,
            sourceType: 'test_receipt', sourceId: 2, floor: '40',
        );

        $this->assertSame(0, bccomp($this->stock()->floorQty($product, $this->warehouse), '60', 4));
        $this->assertSame(0, bccomp($this->stock()->floorQty($product, $second), '40', 4));

        // গুদাম না বললে সবগুলোর যোগফল
        $this->assertSame(0, bccomp($this->stock()->floorQty($product), '100', 4));
    }

    /**
     * নতুন কোম্পানির প্রথম গুদামটাই প্রধান হয়ে যায়।
     *
     * প্রধান গুদাম ছাড়া মাল কোথায় ঢুকবে তা বলার কেউ থাকত না — প্রথম
     * ক্রয়ের বিলটাই লেখা যেত না।
     */
    public function test_the_first_warehouse_becomes_the_default(): void
    {
        $other = Company::query()->where('code', 'FMART')->firstOrFail();

        CompanyContext::forCompany($other->id, function () {
            $this->assertSame(0, Warehouse::query()->count(), 'শুরুতে কোনো গুদাম নেই');

            $first = app(WarehouseService::class)->create([
                'code' => 'FIRST',
                'name_en' => 'First Store',
            ]);

            $this->assertTrue($first->fresh()->is_default);
        });
    }

    public function test_the_default_warehouse_cannot_be_deactivated(): void
    {
        $this->expectException(ValidationException::class);

        app(WarehouseService::class)->deactivate($this->warehouse);
    }

    // ── পণ্য ────────────────────────────────────────────────────────────

    public function test_a_blank_code_comes_from_the_number_series(): void
    {
        $this->assertStringStartsWith('PRD-', $this->product()->code);
    }

    /**
     * দুইটা পণ্যে একই বারকোড থাকতে পারে না।
     *
     * থাকলে স্ক্যানার কোনটা বেছে নেবে তা বলা যায় না, আর ভুলটা ধরা পড়ে
     * ভুল জিনিস বিক্রি হওয়ার পরে — কাউন্টারে, ক্রেতার সামনে।
     */
    public function test_two_products_cannot_share_a_barcode(): void
    {
        $this->product('First', ['barcode' => '8901234567890']);

        $this->expectException(ValidationException::class);

        $this->product('Second', ['barcode' => '8901234567890']);
    }

    public function test_a_product_with_stock_can_still_be_deactivated(): void
    {
        $product = $this->product();
        $this->receive($product, '25');

        app(ProductService::class)->deactivate($product);

        $this->assertFalse($product->fresh()->is_active);

        // গুদামে যা আছে তা তো আছেই — বেচে শেষ করতে হবে
        $this->assertSame(0, bccomp($this->stock()->floorQty($product), '25', 4));
    }

    // ── টেন্যান্ট ও অনুমতি ──────────────────────────────────────────────

    public function test_one_company_never_sees_another_companys_stock(): void
    {
        $product = $this->product();
        $this->receive($product, '100');

        $other = Company::query()->where('code', 'FMART')->firstOrFail();
        CompanyContext::set($other->id, $other->defaultBranch()?->id);

        $this->assertNull(Product::query()->find($product->id));
        $this->assertSame(0, StockMovement::query()->count());
    }

    public function test_a_user_without_the_permission_cannot_reach_any_screen(): void
    {
        $stranger = User::factory()->create();
        $stranger->companies()->attach($this->company, ['is_active' => true]);
        $stranger->forceFill(['current_company_id' => $this->company->id])->save();

        $product = $this->product();

        foreach ([
            route('inventory.product.index'),
            route('inventory.product.show', $product),
            route('inventory.warehouse.index'),
            route('inventory.stock.index'),
            route('inventory.stock.adjust'),
            route('inventory.report.show', 'stock-summary'),
        ] as $url) {
            $this->actingAs($stranger)->get($url)->assertForbidden();
        }
    }

    // ── পর্দা ───────────────────────────────────────────────────────────

    public function test_the_stock_screen_shows_all_four_states(): void
    {
        $product = $this->product('Visible Product');
        $this->receive($product, '100');
        $this->stock()->hold($product, $this->warehouse, '15', $this->holdReason());

        $this->actingAs($this->user)
            ->get(route('inventory.stock.index'))
            ->assertOk()
            ->assertSee(__('inventory::field.floor'), false)
            ->assertSee(__('inventory::field.reserved'), false)
            ->assertSee(__('inventory::field.hold'), false)
            ->assertSee(__('inventory::field.available'), false)
            // ১০০ − ০ − ১৫ = ৮৫
            ->assertSee('85.00');
    }

    public function test_the_hold_report_separates_the_reasons(): void
    {
        $product = $this->product();
        $this->receive($product, '100');

        $priceHold = $this->holdReason();

        $damaged = ReasonCode::query()->create([
            'code' => 'DAMAGED-HOLD',
            'name_en' => 'Damaged',
            'name_bn' => 'ক্ষতিগ্রস্ত',
            'context' => ReasonCode::HOLD,
            'returns_to_stock' => false,
            'is_active' => true,
        ]);

        $this->stock()->hold($product, $this->warehouse, '35', $priceHold);
        $this->stock()->hold($product, $this->warehouse, '5', $damaged);

        $result = app(ReportEngine::class)->run('inventory.hold', [
            'from' => now()->subYear()->toDateString(),
            'to' => now()->addDay()->toDateString(),
        ]);

        /*
         * এক পণ্যের বিপরীতে দুইটা আলাদা সারি — এটাই এই রিপোর্টের একমাত্র কারণ।
         *
         * "৪০ আটকানো" বললে মালিক ভাবতেন তার মালে সমস্যা; "৫ ক্ষতিগ্রস্ত,
         * ৩৫ দাম বাড়ার অপেক্ষায়" বললে তিনি জানেন ৩৫টা তার নিজের সিদ্ধান্ত।
         *
         * শুধু এই পণ্যের সারি গোনা হয়, মোট সারি নয় — ডেমো ডাটাতেও আটকানো
         * মাল আছে, আর সেগুলো গুনলে টেস্টটা ডেমোর পরিমাণ বদলালেই ভাঙত।
         */
        $rows = array_values(array_filter(
            $result->rows,
            fn ($row) => (int) ((array) $row)['product_id'] === $product->id,
        ));

        $this->assertCount(2, $rows);

        $held = array_map(fn ($row) => (string) ((array) $row)['held'], $rows);
        sort($held);

        $this->assertSame(0, bccomp($held[0], '5', 4));
        $this->assertSame(0, bccomp($held[1], '35', 4));
    }

    public function test_creating_a_product_through_the_screen_works_end_to_end(): void
    {
        $this->actingAs($this->user)
            ->post(route('inventory.product.store'), [
                'name_en' => 'Screen Product',
                'name_bn' => 'স্ক্রিন পণ্য',
                'barcode' => '1234567890123',
                'purchase_price' => '90.50',
                'sale_price' => '110.00',
                'reorder_level' => '10',
            ])
            ->assertRedirect();

        $product = Product::query()->where('name_en', 'Screen Product')->firstOrFail();

        $this->assertSame('স্ক্রিন পণ্য', $product->name('bn'));
        $this->assertSame($this->user->id, $product->created_by);
    }
}
