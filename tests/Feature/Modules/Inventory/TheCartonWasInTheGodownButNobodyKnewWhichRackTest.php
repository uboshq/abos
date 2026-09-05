<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StorageLocation;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * কার্টনটা গুদামে ছিল, কেবল কোন তাকে তা কেউ জানত না।
 *
 * ── কী মাপা হচ্ছে ───────────────────────────────────────────────────
 * তাকের ঘরটা **ঐচ্ছিক**, আর ঐচ্ছিক জিনিস সবচেয়ে সহজে নীরবে ভাঙে:
 * কেউ পাঠাল না — সবুজ; কেউ ভুলটা পাঠাল — তাও সবুজ। তাই দুইটা দিকই
 * আলাদা করে মাপা হয়।
 */
class TheCartonWasInTheGodownButNobodyKnewWhichRackTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Warehouse $warehouse;

    private Product $product;

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

    /** মাল বসানোর সারিতে তাকটা লেখা থাকে। */
    public function test_the_shelf_it_was_put_on_is_written_down(): void
    {
        $stock = app(StockService::class);
        $shelf = $this->aShelf();

        $stock->move(
            product: $this->product,
            warehouse: $this->warehouse,
            sourceType: 'test',
            sourceId: 1,
            unplaced: '10',
        );

        $movement = $stock->place(
            product: $this->product,
            warehouse: $this->warehouse,
            qty: '10',
            sourceType: 'test',
            sourceId: 1,
            location: $shelf,
        );

        $this->assertSame($shelf->id, $movement->storage_location_id);
        $this->assertSame('ব্লক ১ ▸ র‍্যাক ১ ▸ শেলফ ১', $shelf->path());
    }

    /**
     * ⛔ অন্য গুদামের তাক বসানো যায় না।
     *
     * ⚠️ পর্দা এটা বাছতেই দেয় না, কিন্তু API বা খুলে রাখা পুরনো ট্যাব
     * থেকে আসতে পারে। না আটকালে খাতায় এমন সারি বসত যা বলত মালটা এমন
     * জায়গায় আছে যেখানে ঐ গুদামের কেউ কোনোদিন যান না।
     */
    public function test_a_shelf_from_another_warehouse_is_refused(): void
    {
        $stock = app(StockService::class);

        $other = Warehouse::query()->where('id', '!=', $this->warehouse->id)->first()
            ?? Warehouse::create([
                'company_id' => CompanyContext::id(),
                'code' => 'W2',
                'name_en' => 'Second',
                'is_active' => true,
            ]);

        $strangersShelf = StorageLocation::create([
            'company_id' => CompanyContext::id(),
            'warehouse_id' => $other->id,
            'code' => 'X1',
            'name_en' => 'Stranger',
            'depth' => StorageLocation::BLOCK,
        ]);

        $stock->move(
            product: $this->product,
            warehouse: $this->warehouse,
            sourceType: 'test',
            sourceId: 2,
            unplaced: '5',
        );

        $this->expectException(ValidationException::class);

        $stock->place(
            product: $this->product,
            warehouse: $this->warehouse,
            qty: '5',
            sourceType: 'test',
            sourceId: 2,
            location: $strangersShelf,
        );
    }

    /**
     * তাক ছাড়াও বসানো যায় — ছোট দোকানের পথ।
     *
     * ⓘ এটা না মাপলে কেউ একদিন ঘরটা `required` করে দিতেন, আর তখন
     * এগারোটা শিল্পের অন্তত ছয়টায় প্রতিটা বসানো আটকে যেত।
     */
    public function test_a_small_shop_places_without_any_shelf(): void
    {
        $stock = app(StockService::class);

        $stock->move(
            product: $this->product,
            warehouse: $this->warehouse,
            sourceType: 'test',
            sourceId: 3,
            unplaced: '4',
        );

        $movement = $stock->place(
            product: $this->product,
            warehouse: $this->warehouse,
            qty: '4',
            sourceType: 'test',
            sourceId: 3,
        );

        $this->assertNull($movement->storage_location_id);
        $this->assertSame('4.0000', $movement->floor_change);
    }

    /** পর্দাটা খোলে, আর তাকের ঘরগুলো দেখায়। */
    public function test_the_placement_screen_offers_the_shelves(): void
    {
        $shelf = $this->aShelf();

        app(StockService::class)->move(
            product: $this->product,
            warehouse: $this->warehouse,
            sourceType: 'test',
            sourceId: 4,
            unplaced: '7',
            documentNo: 'GRN-TEST-1',
        );

        $this->actingAs($this->owner)
            ->get(route('inventory.stock.placement'))
            ->assertOk()
            ->assertSee('stockPlacement(')
            ->assertSee((string) $shelf->id, false)
            ->assertSee(__('inventory::field.depth_3'));
    }

    /** সেটিংস পর্দা থেকে তাক বানানো যায়, আর গভীরতা নিজে থেকে বসে। */
    public function test_a_storekeeper_can_build_the_tree_from_settings(): void
    {
        $this->actingAs($this->owner)
            ->post(route('inventory.warehouse.place.store', $this->warehouse), [
                'code' => 'B9',
                'name_en' => 'Block Nine',
            ])->assertRedirect();

        $block = StorageLocation::query()->where('code', 'B9')->firstOrFail();
        $this->assertSame(StorageLocation::BLOCK, $block->depth);

        $this->actingAs($this->owner)
            ->post(route('inventory.warehouse.place.store', $this->warehouse), [
                'code' => 'R9',
                'name_en' => 'Rack Nine',
                'parent_id' => $block->id,
            ])->assertRedirect();

        $this->assertSame(
            StorageLocation::RACK,
            StorageLocation::query()->where('code', 'R9')->value('depth'),
            'গভীরতাটা বাবার থেকে গোনা হয়নি — তাহলে র‍্যাকের নিচে ব্লক বসানো যেত।',
        );
    }

    /** ⛔ শেলফের নিচে আর কিছু বসে না — আজকের পর্দা তিনটাই আঁকে। */
    public function test_the_tree_stops_at_three_steps(): void
    {
        $shelf = $this->aShelf();

        $this->actingAs($this->owner)
            ->post(route('inventory.warehouse.place.store', $this->warehouse), [
                'code' => 'T4',
                'name_en' => 'Fourth',
                'parent_id' => $shelf->id,
            ])->assertStatus(422);
    }

    private function aShelf(): StorageLocation
    {
        $block = StorageLocation::create([
            'company_id' => CompanyContext::id(),
            'warehouse_id' => $this->warehouse->id,
            'code' => 'B1',
            'name_en' => 'Block 1',
            'name_bn' => 'ব্লক ১',
            'depth' => StorageLocation::BLOCK,
        ]);

        $rack = StorageLocation::create([
            'company_id' => CompanyContext::id(),
            'warehouse_id' => $this->warehouse->id,
            'parent_id' => $block->id,
            'code' => 'R1',
            'name_en' => 'Rack 1',
            'name_bn' => 'র‍্যাক ১',
            'depth' => StorageLocation::RACK,
        ]);

        return StorageLocation::create([
            'company_id' => CompanyContext::id(),
            'warehouse_id' => $this->warehouse->id,
            'parent_id' => $rack->id,
            'code' => 'S1',
            'name_en' => 'Shelf 1',
            'name_bn' => 'শেলফ ১',
            'depth' => StorageLocation::SHELF,
        ]);
    }
}
