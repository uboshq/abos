<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\BatchService;
use App\Modules\Inventory\Services\StockService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * বিক্রির পাহারা মেয়াদ পেরোনো লটকে বিক্রয়যোগ্য গুনত — fe-র সিদ্ধান্ত (ক), ১৩ অক্টোবর ২০২৬ (মজুদ লাইন-ধরে নিরীক্ষা)।
 *
 * ⛔ [[StockService::assertEnoughAvailable()]] গুনত `তাক − ধরা − আটকানো`, মেয়াদ না দেখে। মেয়াদি ১০ + ভালো ১০, আর অন্য
 * ডিওর ১০ ধরা: ভালো লট থেকে ১০ বেচা পার হত (২০ − ১০ = ১০), ডিওর মাল শেষ, পেছনে কেবল মেয়াদি মাল।
 * ⭐ এখন একমাত্র সূত্রে ([[StockService::availableSql()]]) — আন্তর্জাতিক নিয়ম: মেয়াদি মাল বিক্রয়যোগ্য নয়।
 */
final class TheSaleCountedTheExpiredLotAsSellableTest extends TestCase
{
    use RefreshDatabase;

    public function test_another_orders_goods_are_not_sold_by_counting_an_expired_lot(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $warehouse = Warehouse::acrossWarehouses()->create(['code' => 'EXP-WH', 'name_en' => 'Expiry probe', 'is_active' => true, 'branch_id' => $company->defaultBranch()?->id]);
        $product = Product::query()->orderBy('id')->firstOrFail();
        $stock = app(StockService::class);

        $old = app(BatchService::class)->receive($product, 'EXP-OLD', expiry: now()->subDays(3)->toDateString());
        $good = app(BatchService::class)->receive($product, 'EXP-GOOD', expiry: now()->addYear()->toDateString());
        $stock->move(product: $product, warehouse: $warehouse, sourceType: 'test.in', sourceId: 1, floor: '10', batch: $old, date: now()->subDays(10));
        $stock->move(product: $product, warehouse: $warehouse, sourceType: 'test.in', sourceId: 2, floor: '10', batch: $good);

        // ⓘ অন্য ডিও ১০ ধরে রেখেছে
        $stock->move(product: $product, warehouse: $warehouse, sourceType: 'test.do', sourceId: 3, reserved: '10');

        $this->expectException(ValidationException::class);

        $stock->issue(product: $product, warehouse: $warehouse, sourceType: 'test.sale', sourceId: 4, qty: '10', batch: $good, fromAvailable: true);
    }
}
