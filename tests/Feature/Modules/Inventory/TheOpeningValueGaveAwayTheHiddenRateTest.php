<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\OpeningStockService;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * খোলা মজুদের মূল্য লুকানো দরটা বলে দিত — Inventory অডিট ম১২ (খ), ৫ অক্টোবর ২০২৬।
 *
 * ⛔ খরচ দেখার চাবি নেই এমন মানুষের কাছে খোলা মজুদের তালিকায় দর ঢাকা, অথচ প্রতিটা সারির মূল্য আর উপরের মোট খোলা —
 * মূল্য ÷ পরিমাণ = দর, অর্থাৎ পাহারাটা অলংকার।
 * ⭐ এখন দর যার কাছে লুকানো, মূল্য আর মোটও তার কাছে লুকানো; চাবি যাঁর আছে তিনি তিনটাই দেখেন।
 */
final class TheOpeningValueGaveAwayTheHiddenRateTest extends TestCase
{
    use RefreshDatabase;

    public function test_without_the_cost_key_the_value_hides_with_the_rate(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);
        app(StandardChart::class)->install();

        $product = Product::query()->create([
            'code' => 'M12-'.mb_substr(md5(microtime()), 0, 8), 'name_en' => 'Opening probe', 'name_bn' => 'খোলার নমুনা',
            'unit_id' => Unit::query()->orderBy('id')->firstOrFail()->id, 'is_active' => true, 'track_batch' => false,
        ]);
        // ⓘ চেনা যায় এমন সংখ্যা: ১০ × ৩৭.১৩ = ৩৭১.৩০
        app(OpeningStockService::class)->bringIn($product, Warehouse::query()->orderBy('id')->firstOrFail(), '10', '37.13');

        $this->get(route('inventory.stock.opening'))->assertOk()->assertSee('371.30');

        // ⓘ ভূমিকা কোম্পানির, আর মানুষটা কোম্পানির সদস্য — খোলা মজুদের চাবি আছে, খরচ দেখার চাবি নেই
        $role = Role::query()->create(['name' => 'opening-clerk', 'guard_name' => 'web', 'company_id' => $company->id]);
        $role->syncPermissions(Permission::query()->whereIn('name', ['inventory.stock.view', 'inventory.stock.opening'])->get());
        $clerk = User::factory()->create(['current_company_id' => $company->id]);
        $clerk->companies()->attach($company->id, ['is_active' => true]);
        $clerk->assignRole($role);
        $this->assertFalse($clerk->fresh()->can('inventory.cost.view'), 'প্রস্তুতিটাই ভুল — কেরানির খরচ দেখার চাবি আছে।');

        $page = $this->actingAs($clerk)->get(route('inventory.stock.opening'))->assertOk();
        $page->assertDontSee('37.13');
        $page->assertDontSee('371.30');
    }
}
