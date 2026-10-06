<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\GoodsWaitingToBePlaced;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * মাল বসানোর ব্যাজ সব গুদামের বিল গুনত — Inventory অডিট ম৩০, ৫ অক্টোবর ২০২৬।
 *
 * ⛔ বোর্ড নিজের গুদামে সীমিত হলো (ম১৩), কিন্তু মেনুর ব্যাজ ([[GoodsWaitingToBePlaced::pendingCount()]]) কোম্পানির সব
 * বিল গুনত — এক গুদামের কেরানি ব্যাজে "২" দেখে খুলতেন, বোর্ডে একটা।
 * ⭐ এখন ব্যাজ আর বোর্ড একই দেয়ালে ([[Warehouse::idsInViewedBranch()]])।
 */
final class TheBadgeCountedEveryWarehousesBillsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_one_store_clerks_badge_counts_only_their_stores_bills(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $mine = Warehouse::query()->create(['code' => 'ZQ30A', 'name_en' => 'Clerk store', 'is_active' => true, 'branch_id' => $company->defaultBranch()?->id]);
        $other = Warehouse::query()->create(['code' => 'ZQ30B', 'name_en' => 'Other store', 'is_active' => true, 'branch_id' => $company->defaultBranch()?->id]);
        $product = Product::query()->create(['code' => 'M30', 'name_en' => 'Badge probe', 'name_bn' => 'ব্যাজ-নমুনা',
            'unit_id' => Unit::query()->orderBy('id')->firstOrFail()->id, 'is_active' => true, 'track_batch' => false]);
        app(StockService::class)->move(product: $product, warehouse: $mine, sourceType: 'purchase_bill', sourceId: 93001, unplaced: '5', documentNo: 'ZQ30-MINE');
        app(StockService::class)->move(product: $product, warehouse: $other, sourceType: 'purchase_bill', sourceId: 93002, unplaced: '7', documentNo: 'ZQ30-OTHER');

        $clerk = User::factory()->create(['current_company_id' => $company->id]);
        $clerk->companies()->attach($company->id, ['is_active' => true]);
        UserDataScope::query()->create(['company_id' => $company->id, 'user_id' => $clerk->id,
            'scope_type' => UserDataScope::WAREHOUSE, 'scope_id' => $mine->id]);
        app(DataScope::class)->forget();
        $this->actingAs($clerk);

        $this->assertSame(1, app(GoodsWaitingToBePlaced::class)->pendingCount(), '⛔ এক গুদামের কেরানির ব্যাজ অন্য গুদামের বিলও গুনল।');
    }
}
