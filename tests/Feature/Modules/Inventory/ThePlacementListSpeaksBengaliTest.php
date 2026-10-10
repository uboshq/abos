<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ মাল বসানোর তালিকা পণ্য আর গুদামের নাম সবসময় ইংরেজিতে দিত (পুরো-ERP অডিট, মজুদ ছ১৮; মালিকের "সব খোলা ভুল", ১০ অক্টোবর ২০২৬)।
 *
 * ⓘ মালিক কেবল বাংলা পড়েন, আর মজুদের রিপোর্টগুলো ব্যবহারকারীর ভাষায় নাম দেয় ([[StockReports]])। [[StockPlacementController]]
 * `p.name_en` আর `w.name_en` সোজা তুলত। এখন বাংলায় বাংলা নাম, না থাকলে ইংরেজি; ইংরেজিতে আগের মতোই।
 */
final class ThePlacementListSpeaksBengaliTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_bengali_reader_sees_bengali_names_on_the_placement_list(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $owner->forceFill(['locale' => 'bn'])->save();
        $this->actingAs($owner->fresh());

        $warehouse = Warehouse::query()->create(['code' => 'PL-BN', 'name_en' => 'Placement store', 'name_bn' => 'বসানোর গুদাম',
            'is_active' => true, 'branch_id' => $company->defaultBranch()?->id]);
        $product = Product::query()->create(['code' => 'PLBN-'.mb_substr(md5(microtime()), 0, 6), 'name_en' => 'Placement probe',
            'name_bn' => 'বসানোর নমুনা', 'unit_id' => Unit::query()->where('code', 'PCS')->firstOrFail()->id, 'is_active' => true]);
        app(StockService::class)->move(product: $product, warehouse: $warehouse, sourceType: 'purchase_bill', sourceId: 91801,
            unplaced: '3', documentNo: 'PL-BN-1');

        $lines = collect($this->get(route('inventory.stock.placement'))->assertOk()->viewData('papers'))
            ->flatMap(fn (array $paper) => $paper['lines'])->where('product_id', $product->id);

        $this->assertSame(['বসানোর নমুনা'], $lines->pluck('product_name')->unique()->values()->all(), '⛔ বাংলার পাঠক পণ্যের নাম ইংরেজিতে পেলেন।');
        $this->assertSame(['বসানোর গুদাম'], $lines->pluck('warehouse_name')->unique()->values()->all(), '⛔ বাংলার পাঠক গুদামের নাম ইংরেজিতে পেলেন।');
    }
}
