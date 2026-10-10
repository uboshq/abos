<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⛔ মজুদের রিপোর্টে গুদামের দেয়াল ছিল না (পুরো-ERP অডিট, মজুদ ⛔; মালিকের "সব খোলা ভুল", ১০ অক্টোবর ২০২৬)।
 *
 * ⓘ রিপোর্টগুলো কাঁচা কোয়েরি — কোম্পানি আর শাখার দেয়াল বসত, গুদামের নয়। এক গুদামে সীমিত মানুষ গুদাম-ভিত্তিক মজুদ,
 * খতিয়ান, লট আর আটকানো মালের রিপোর্টে সব গুদামের সারি পেতেন। এখন [[ReportEngine::warehouseWall()]] — সীমা না থাকলে আগের মতোই।
 */
final class TheStockReportsStayInsideTheWarehouseWallTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_one_warehouse_clerk_sees_only_their_warehouse_in_the_stock_reports(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $mine = Warehouse::query()->create(['code' => 'RW-MINE', 'name_en' => 'Clerk store', 'is_active' => true, 'branch_id' => $company->defaultBranch()?->id]);
        $other = Warehouse::query()->create(['code' => 'RW-OTHER', 'name_en' => 'Other store', 'is_active' => true, 'branch_id' => $company->defaultBranch()?->id]);
        $product = Product::query()->create(['code' => 'RW-'.mb_substr(md5(microtime()), 0, 8), 'name_en' => 'Wall report probe',
            'name_bn' => 'দেয়ালের রিপোর্টের নমুনা', 'unit_id' => Unit::query()->where('code', 'PCS')->firstOrFail()->id, 'is_active' => true]);
        app(StockService::class)->move(product: $product, warehouse: $mine, sourceType: 'test.in', sourceId: 1, floor: '5');
        app(StockService::class)->move(product: $product, warehouse: $other, sourceType: 'test.in', sourceId: 2, floor: '7');

        $pairs = fn () => collect(app(ReportEngine::class)->run('inventory.stock_by_warehouse', ['to' => now()->toDateString()], perPage: 1000)->rows)
            ->map(fn ($r) => (string) $r['pair_key'])->filter(fn ($k) => str_starts_with($k, $product->id.'-'))->sort()->values()->all();
        $ledger = fn () => collect(app(ReportEngine::class)->run('inventory.stock_ledger', ['from' => now()->subDay()->toDateString(), 'to' => now()->toDateString()],
            perPage: 1000)->rows)->filter(fn ($r) => $r['source_type'] === 'test.in' && in_array((int) $r['source_id'], [1, 2], true))->count();

        // ⓘ মালিক — দুই গুদামই
        $this->assertSame([$product->id.'-'.$mine->id, $product->id.'-'.$other->id], $pairs(), 'প্রস্তুতিটাই ভুল — মালিক দুই গুদাম দেখেন না।');
        $this->assertSame(2, $ledger(), 'প্রস্তুতিটাই ভুল — খতিয়ানে দুই সারি নেই।');

        $clerk = User::factory()->create(['current_company_id' => $company->id]);
        $clerk->companies()->attach($company->id, ['is_active' => true]);
        CompanyContext::forCompany($company->id, fn () => $clerk->givePermissionTo(Permission::findOrCreate('inventory.report', 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        UserDataScope::query()->create(['company_id' => $company->id, 'user_id' => $clerk->id, 'scope_type' => UserDataScope::WAREHOUSE, 'scope_id' => $mine->id]);
        app(DataScope::class)->forget();
        $this->actingAs($clerk->fresh());

        $this->assertSame([$product->id.'-'.$mine->id], $pairs(), '⛔ এক গুদামের কেরানি গুদাম-ভিত্তিক মজুদে অন্য গুদামও দেখলেন।');
        $this->assertSame(1, $ledger(), '⛔ এক গুদামের কেরানি খতিয়ানে অন্য গুদামের সারি দেখলেন।');
    }
}
