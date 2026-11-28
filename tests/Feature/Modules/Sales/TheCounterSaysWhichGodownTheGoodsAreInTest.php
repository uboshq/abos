<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StorageLocation;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\Services\DirectSaleOptions;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ কাউন্টারের "অবস্থান" বাক্স — মাল কোন গুদামে, কোন জায়গায় (মালিক, ২৮ নভেম্বর ২০২৬: "কোন গোডাউনে আছে এটা যাতে দেখায়")।
 *
 * ⓘ পণ্যের তালিকায় `where` = সবচেয়ে বেশি মাল যেখানে: গুদামের নাম, জায়গার পুরো পথ (Block ▸ Rack ▸ Shelf), আরও কয় জায়গায়।
 * ⛔ খালি হয়ে যাওয়া জায়গা (যোগ ০) কখনো দেখায় না — নইলে লোক খালি তাকে খুঁজতে যেত।
 */
final class TheCounterSaysWhichGodownTheGoodsAreInTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->warehouse = Warehouse::query()->firstOrFail();
        $this->product = Product::query()->firstOrFail();
    }

    public function test_the_counter_names_the_godown_and_the_shelf_with_the_most_goods(): void
    {
        $stock = app(StockService::class);
        $shelf = $this->aShelf();

        $stock->move(product: $this->product, warehouse: $this->warehouse, sourceType: 'test', sourceId: 1, unplaced: '100000');
        $stock->place(product: $this->product, warehouse: $this->warehouse, qty: '100000', sourceType: 'test', sourceId: 1, location: $shelf);

        $where = $this->whereOf($this->product);

        $this->assertNotNull($where, '⛔ মাল থাকা পণ্যের অবস্থান আসেনি।');
        $this->assertSame($this->warehouse->name(), $where['godown']);
        $this->assertSame($shelf->path(), $where['place'], '⛔ সবচেয়ে বেশি মাল যে তাকে, সেটা দেখায়নি।');
        $this->assertSame(100000.0, (float) $where['qty']);
    }

    /** @return array{godown: string, place: string, qty: string, more: int}|null */
    private function whereOf(Product $product): ?array
    {
        $row = app(DirectSaleOptions::class)->catalogue($this->warehouse, 50, (int) $product->id)->first();

        return $row === null ? null : $row->where;
    }

    private function aShelf(): StorageLocation
    {
        $make = fn (?int $parent, string $code, string $en, string $bn, int $depth) => StorageLocation::create([
            'company_id' => CompanyContext::id(), 'warehouse_id' => $this->warehouse->id, 'parent_id' => $parent,
            'code' => $code, 'name_en' => $en, 'name_bn' => $bn, 'depth' => $depth,
        ]);

        $block = $make(null, 'WB1', 'Block 1', 'ব্লক ১', StorageLocation::BLOCK);
        $rack = $make($block->id, 'WR1', 'Rack 1', 'র‍্যাক ১', StorageLocation::RACK);

        return $make($rack->id, 'WS1', 'Shelf 1', 'শেলফ ১', StorageLocation::SHELF);
    }
}
