<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * মাল বসানো অন্য কাগজের বা অন্য লটের অপেক্ষার মাল নিজের নামে তুলে নিত — অডিট গ১৪, ৪ অক্টোবর ২০২৬।
 *
 * ── ⛔ যা ভাঙা ছিল ───────────────────────────────────────────────────
 * [[StockService::place()]]-এর সীমা ছিল গুদামে পণ্যের **মোট** অপেক্ষা, তালা ছাড়া। দুইটা বিলের মাল অপেক্ষায়
 * থাকলে একটা বিলেই দুইটার যোগফল বসানো যেত — এক লটে বেশি উঠত, অন্য বিল চিরকাল অপেক্ষায়; আর দুজন একসাথে
 * বসালে দুজনেই পুরোটা দেখতেন, শূন্য থেকে মাল জন্মাত। ফর্মের লুকানো `source_type` বদলে যেকোনো কাগজের নামেও
 * বসানো যেত।
 *
 * ── ⭐ এই ফাইল যা পাহারা দেয় ─────────────────────────────────────────
 *   ১. এক বিলে কেবল সেই বিলের অপেক্ষা
 *   ২. এক লটে কেবল সেই লটের অপেক্ষা; লট-ধরা পণ্যে লট বাধ্যতামূলক; অন্য পণ্যের লট নয়
 *   ৩. পর্দা কেবল অপেক্ষার ঘরে মাল নামানো কাগজের নাম নেয়
 *   ৪. গোনাটা তালাসহ
 */
final class PlacementTookAnotherPapersGoodsTest extends TestCase
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

    // ── ১ · কাগজ ধরে ─────────────────────────────────────────────────

    public function test_one_bill_cannot_place_another_bills_goods(): void
    {
        $product = $this->product();
        $this->arrive($product, 9101, '10');
        $this->arrive($product, 9102, '20');

        $this->assertPlacingRefused($product, 9101, '25');

        $this->assertSame(0, bccomp(app(StockService::class)->floorQty($product, $this->warehouse), '0', 4),
            'বিল ৯১০১-এর নামে ২৫ বসে গেছে, অথচ সে এনেছিল ১০ — বিল ৯১০২ চিরকাল অপেক্ষায় থাকত।');
    }

    // ── ২ · লট ধরে ───────────────────────────────────────────────────

    public function test_one_lot_cannot_place_another_lots_goods(): void
    {
        $product = $this->product(lots: true);
        $a = $this->lot($product, 'A');
        $b = $this->lot($product, 'B');

        $this->arrive($product, 9201, '10', $a);
        $this->arrive($product, 9201, '10', $b);

        $this->assertPlacingRefused($product, 9201, '15', $a);
    }

    public function test_a_lot_kept_product_needs_its_lot_to_be_placed(): void
    {
        $product = $this->product(lots: true);
        $a = $this->lot($product, 'A');
        $this->arrive($product, 9202, '10', $a);

        $this->assertPlacingRefused($product, 9202, '5', null, 'batch_id', 'place_needs_lot', ['product' => $product->name()]);
    }

    public function test_another_products_lot_cannot_be_placed(): void
    {
        $product = $this->product(lots: true);
        $other = $this->product(lots: true);
        $theirs = $this->lot($other, 'X');

        $this->arrive($product, 9203, '10', $this->lot($product, 'A'));
        $this->arrive($other, 9203, '10', $theirs);

        $this->assertPlacingRefused($product, 9203, '5', $theirs, 'batch_id', 'lot_of_another_product',
            ['lot' => 'X', 'product' => $product->name()]);
    }

    // ── ৩ · পর্দার কাগজের নাম ────────────────────────────────────────

    public function test_the_screen_refuses_a_paper_that_never_waits_to_be_placed(): void
    {
        $product = $this->product();
        $this->arrive($product, 9301, '10');

        $this->actingAs($this->owner)
            ->from(route('inventory.stock.placement'))
            ->post(route('inventory.stock.placement.store'), [
                'lines' => [[
                    'product_id' => $product->id,
                    'warehouse_id' => $this->warehouse->id,
                    'source_type' => 'stock_adjustment',
                    'source_id' => 1,
                    'qty' => '5',
                ]],
            ])
            ->assertSessionHasErrors();

        $this->assertSame(0, bccomp(app(StockService::class)->floorQty($product, $this->warehouse), '0', 4),
            'অপেক্ষার ঘরে কখনো মাল না নামানো কাগজের নামে মাল তাকে উঠে গেছে।');
    }

    // ── ৪ · তালা ─────────────────────────────────────────────────────

    public function test_the_waiting_goods_are_counted_under_a_lock(): void
    {
        $product = $this->product();
        $this->arrive($product, 9401, '10');

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = strtolower($query->sql);
        });

        app(StockService::class)->place(
            product: $product, warehouse: $this->warehouse, qty: '4',
            sourceType: 'purchase_bill', sourceId: 9401,
        );

        $sums = array_filter($queries, fn (string $sql) => str_contains($sql, 'sum(') && str_contains($sql, 'unplaced_change'));

        $this->assertNotEmpty($sums, 'অপেক্ষার মাল গোনার কোয়েরিই পাওয়া গেল না।');

        foreach ($sums as $sql) {
            $this->assertStringContainsString('for update', $sql,
                'অপেক্ষার মাল গোনা হয়েছে তালা ছাড়া — দুজন একসাথে বসালে শূন্য থেকে মাল জন্মাত।');
        }
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function product(bool $lots = false): Product
    {
        return Product::query()->create([
            'code' => 'G14-'.mb_substr(md5(microtime().random_int(0, 9999)), 0, 8),
            'name_en' => 'Waiting to be placed',
            'name_bn' => 'বসানোর অপেক্ষায়',
            'unit_id' => Unit::query()->orderBy('id')->firstOrFail()->id,
            'track_batch' => $lots,
            'is_active' => true,
        ]);
    }

    private function lot(Product $product, string $no): Batch
    {
        return Batch::query()->create([
            'product_id' => $product->id,
            'batch_no' => $no,
            'expiry_date' => now()->addYear()->toDateString(),
        ]);
    }

    private function arrive(Product $product, int $bill, string $qty, ?Batch $batch = null): void
    {
        app(StockService::class)->move(
            product: $product,
            warehouse: $this->warehouse,
            sourceType: 'purchase_bill',
            sourceId: $bill,
            unplaced: $qty,
            batch: $batch,
        );
    }

    /**
     * ⓘ কেবল "ফিরিয়েছে" নয় — **কোন কারণে** ফিরিয়েছে, আর তাকে কিছু ওঠেনি (fe, ১০ অক্টোবর ২০২৬)।
     *
     * ⛔ আগে যেকোনো ValidationException-এই চুপচাপ পাস, কোনো যাচাই ছাড়া — তিনটা লটের পরীক্ষা "কোনো যাচাই নেই" বলে লাল
     * (ঝুঁকিপূর্ণ) থাকত, আর অন্য কারণে ফেরালেও (ধরা যাক লট তৈরিতেই গলদ) সবুজ দেখাত। এখন ঘর আর বার্তা দুটোই মেলে; বার্তার যে
     * অংশ গোনা থেকে আসে (`:waiting`), সেটা যেকোনো সংখ্যা।
     *
     * @param  array<string, string>  $params  জানা প্যারামিটার; বাকিগুলো যেকোনো লেখা
     */
    private function assertPlacingRefused(Product $product, int $bill, string $qty, ?Batch $batch = null,
        string $field = 'qty', string $because = 'more_than_unplaced', array $params = []): void
    {
        $before = app(StockService::class)->floorQty($product, $this->warehouse);

        try {
            app(StockService::class)->place(
                product: $product,
                warehouse: $this->warehouse,
                qty: $qty,
                sourceType: 'purchase_bill',
                sourceId: $bill,
                batch: $batch,
            );

            $this->fail("বিল {$bill}-এর নামে {$qty} বসে গেছে — অন্য কাগজ বা অন্য লটের অপেক্ষা থেকে।");
        } catch (ValidationException $e) {
            $said = $e->errors()[$field][0] ?? null;
            $this->assertNotNull($said, "⛔ বিল {$bill} ফিরল, কিন্তু অন্য ঘরে: ".implode(' ', $e->validator->errors()->all()));

            $mark = '@@ANY@@';
            $shape = __('inventory::validation.'.$because, $params + ['waiting' => $mark, 'lot' => $mark, 'product' => $mark]);
            $pattern = '/^'.str_replace(preg_quote($mark, '/'), '.+', preg_quote($shape, '/')).'$/u';
            $this->assertMatchesRegularExpression($pattern, $said, "⛔ বিল {$bill} ফিরল, কিন্তু অন্য কারণে।");
        }

        $this->assertSame(0, bccomp(app(StockService::class)->floorQty($product, $this->warehouse), $before, 4),
            "⛔ ফিরিয়েও বিল {$bill}-এর কিছু তাকে উঠল।");
    }
}
