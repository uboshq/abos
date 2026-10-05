<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Core\Support\ViewedBranch;
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
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * এক গুদামের কেরানি "সব শাখা" বাছলে সব গুদাম দেখতেন — Inventory অডিট ম১৩ ও ম৩০, ৫ অক্টোবর ২০২৬।
 *
 * ⛔ কাঁচা মজুদ-কোয়েরি গুদাম জানত কেবল হেডারের শাখা থেকে: "সব শাখা" মানে সব গুদাম, মানুষটা এক গুদামে সীমিত হলেও — তাই
 * তিনি অন্য গুদামের পরিমাণ আর মূল্য দেখতেন; আর মাল বসানোর অপেক্ষার বোর্ডে কোনো ছাঁকনিই ছিল না, অন্য শাখার বিলও ভাসত।
 * ⭐ এখন গুদাম-সীমিত মানুষ "সব শাখা"-তেও কেবল নিজের গুদাম পান ([[Warehouse::idsInViewedBranch()]]), আর বোর্ডটা সেই তালিকা মানে।
 */
final class TheOneWarehouseClerkSawEveryWarehouseTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_warehouse_bound_clerk_sees_only_their_warehouse_with_every_branch_picked(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);

        $mine = Warehouse::query()->create(['code' => 'ZQ13A', 'name_en' => 'Clerk store', 'is_active' => true, 'branch_id' => $company->defaultBranch()?->id]);
        $other = Warehouse::query()->create(['code' => 'ZQ13B', 'name_en' => 'Other store', 'is_active' => true, 'branch_id' => $company->defaultBranch()?->id]);
        $product = Product::query()->create([
            'code' => 'M13-'.mb_substr(md5(microtime()), 0, 8), 'name_en' => 'Waiting probe', 'name_bn' => 'অপেক্ষার নমুনা',
            'unit_id' => Unit::query()->orderBy('id')->firstOrFail()->id, 'is_active' => true, 'track_batch' => false,
        ]);
        app(StockService::class)->move(product: $product, warehouse: $mine, sourceType: 'purchase_bill', sourceId: 91301, unplaced: '5', documentNo: 'ZQ13-MINE');
        app(StockService::class)->move(product: $product, warehouse: $other, sourceType: 'purchase_bill', sourceId: 91302, unplaced: '7', documentNo: 'ZQ13-OTHER');

        // ⓘ মালিক — সীমাহীন: দুটোই
        $this->get(route('inventory.stock.placement'))->assertOk()->assertSee('ZQ13-MINE')->assertSee('ZQ13-OTHER');

        $role = Role::query()->create(['name' => 'one-store-clerk', 'guard_name' => 'web', 'company_id' => $company->id]);
        $role->syncPermissions(Permission::query()->whereIn('name', ['inventory.stock.view', 'inventory.stock.place'])->get());
        $clerk = User::factory()->create(['current_company_id' => $company->id]);
        $clerk->companies()->attach($company->id, ['is_active' => true]);
        $clerk->assignRole($role);
        UserDataScope::query()->create(['company_id' => $company->id, 'user_id' => $clerk->id, 'scope_type' => UserDataScope::WAREHOUSE, 'scope_id' => $mine->id]);
        app(DataScope::class)->forget();

        $this->actingAs($clerk);
        $this->assertNull(ViewedBranch::one(), 'প্রস্তুতিটাই ভুল — কেরানির হেডারে এক শাখা বাছা, "সব শাখা" নয়।');
        $this->assertSame([$mine->id], Warehouse::idsInViewedBranch(), '⛔ "সব শাখা"-তে এক গুদামের কেরানি সব গুদাম পেলেন।');

        $this->get(route('inventory.stock.placement'))->assertOk()
            ->assertSee('ZQ13-MINE')
            ->assertDontSee('ZQ13-OTHER');
    }
}
