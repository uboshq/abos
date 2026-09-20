<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * বোর্ডটা বলত না কার কার্টন, কোন চালানের।
 *
 * ── ⓘ মালিকের ছবি, ২০ সেপ্টেম্বর ২০২৬ ────────────────────────────────
 * তিনি পর্দার ছবি পাঠিয়ে বললেন *"porda emon hobe"*। ⓘ ছবিতে প্রতিটা
 * কাগজের মাথায় চারটা কথা — কার, কোন চালান, কবে, আর কে প্রক্রিয়া
 * করলেন; আর উপরে দুইটা ভাগ: ক্রয়ের মাল আর ফেরত আসা মাল।
 *
 * ── ⚠️ কেন এটা কেবল সাজসজ্জা নয় ──────────────────────────────────────
 * গুদামের লোক হাতে একটা কাগজ নিয়ে দাঁড়ান। ⛔ পর্দায় কেবল একটা নম্বর
 * থাকলে তিনি মেলাতে পারতেন না — নম্বরটা তাঁর কাগজেরই কি না, সেটা
 * সরবরাহকারীর নাম ছাড়া বলা যায় না।
 */
final class TheBoardDidNotSayWhoseCartonsTheseWereTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private Product $product;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->warehouse = Warehouse::query()->firstOrFail();
        $this->product = Product::query()->firstOrFail();
    }

    /**
     * ⭐ কাগজের মাথায় কে করলেন সেটা লেখা থাকে।
     */
    public function test_the_paper_says_who_processed_it(): void
    {
        $this->waiting('GRN-1', 'test_purchase', 11);

        $this->actingAs($this->owner)
            ->get(route('inventory.stock.placement'))
            ->assertOk()
            ->assertSee(__('inventory::field.processed_by'))
            ->assertSee($this->owner->name)
            ->assertSee('GRN-1');
    }

    /**
     * ⭐ দুইটা ভাগ — ক্রয়ের কাগজ আর ফেরতের কাগজ।
     *
     * ⚠️ ভাগ দুইটা খালি হলেও দেখা যায়: "ফেরতের কিছু নেই" একটা উত্তর,
     * আর ভাগটা না থাকলে মানুষ ফেরতের মাল অন্য কোথাও খুঁজতে বেরোতেন।
     */
    public function test_purchases_and_returns_stand_in_their_own_sections(): void
    {
        $this->waiting('GRN-2', 'test_purchase', 12);

        $page = $this->actingAs($this->owner)
            ->get(route('inventory.stock.placement'))
            ->assertOk()
            ->assertSee(__('inventory::label.purchase_placement'))
            ->assertSee(__('inventory::label.return_placement'));

        // ⓘ আজ ফেরতের মাল সোজা তাকে ওঠে, তাই ঐ ভাগটা খালি — আর সেটা লেখা থাকে
        $page->assertSee(__('inventory::message.no_returns_to_place'));

        $this->assertCount(1, $page->viewData('groups')['purchase']);
        $this->assertSame([], $page->viewData('groups')['return']);
    }

    /**
     * ⭐ ফেরতের উৎস এলে সে নিজের ভাগেই বসে।
     */
    public function test_a_return_goes_to_the_return_section(): void
    {
        $this->waiting('SR-1', 'sales_return', 21);

        $page = $this->actingAs($this->owner)
            ->get(route('inventory.stock.placement'))
            ->assertOk();

        $this->assertCount(1, $page->viewData('groups')['return']);
        $this->assertSame([], $page->viewData('groups')['purchase']);
    }

    /**
     * ⭐ সারির নিজের বোতাম — কেবল ঐ সারিটাই বসে, বাকিটা অপেক্ষায় থাকে।
     *
     * ⚠️ এটাই মালিকের ছবির তিরচিহ্ন, আর এটাই দশ কার্টনের আটটা বসানোর পথ।
     */
    public function test_one_line_can_be_placed_on_its_own(): void
    {
        $other = Product::query()->where('id', '!=', $this->product->id)->firstOrFail();

        $this->waiting('GRN-3', 'test_purchase', 31);
        $this->waiting('GRN-3', 'test_purchase', 31, $other);

        $this->actingAs($this->owner)
            ->post(route('inventory.stock.placement.store'), [
                'only' => '0',
                'lines' => [
                    [
                        'product_id' => $this->product->id,
                        'warehouse_id' => $this->warehouse->id,
                        'source_type' => 'test_purchase',
                        'source_id' => 31,
                        'qty' => '7',
                    ],
                    [
                        'product_id' => $other->id,
                        'warehouse_id' => $this->warehouse->id,
                        'source_type' => 'test_purchase',
                        'source_id' => 31,
                        'qty' => '7',
                    ],
                ],
            ])->assertRedirect();

        $this->assertSame(
            '7.0000',
            (string) StockMovement::query()
                ->where('product_id', $this->product->id)
                ->where('source_type', 'test_purchase')
                ->sum('floor_change'),
            'প্রথম সারিটা বসেনি।',
        );

        $this->assertSame(
            '0',
            (string) StockMovement::query()
                ->where('product_id', $other->id)
                ->where('source_type', 'test_purchase')
                ->where('floor_change', '>', 0)
                ->count(),
            'দ্বিতীয় সারিটাও বসে গেছে — তিরচিহ্নটা তাহলে গোটা কাগজ বসায়।',
        );
    }

    private function waiting(string $documentNo, string $sourceType, int $sourceId, ?Product $product = null): void
    {
        $this->actingAs($this->owner);

        app(StockService::class)->move(
            product: $product ?? $this->product,
            warehouse: $this->warehouse,
            sourceType: $sourceType,
            sourceId: $sourceId,
            unplaced: '7',
            documentNo: $documentNo,
        );
    }
}
