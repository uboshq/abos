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
 * ⛔ মজুদের overview-তে গুদাম বাছলে কেবল চারটা অবস্থা বদলাত (পুরো-ERP অডিট, মজুদ M28c; মালিকের "সব খোলা ভুল", ১০ অক্টোবর ২০২৬)।
 *
 * ⓘ [[StockOverviewController]] গুদামটা কেবল [[StockFacts::states()]]-এ দিত — আজকের চলাচল, সাম্প্রতিক তালিকা, কম মজুদ আর বাকি
 * টালি গোটা শাখার। এক পর্দায় দুই প্রশ্নের উত্তর। এখন [[StockFacts::forWarehouse()]] — প্রতিটা টালি বাছা গুদামের।
 */
final class TheOverviewTilesFollowThePickedWarehouseTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_tile_reads_the_picked_warehouse(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $one = Warehouse::query()->create(['code' => 'OV-ONE', 'name_en' => 'Overview one', 'is_active' => true, 'branch_id' => $company->defaultBranch()?->id]);
        $two = Warehouse::query()->create(['code' => 'OV-TWO', 'name_en' => 'Overview two', 'is_active' => true, 'branch_id' => $company->defaultBranch()?->id]);
        $low = fn (string $name) => Product::query()->create(['code' => 'OV-'.mb_substr(md5($name.microtime()), 0, 8), 'name_en' => $name,
            'name_bn' => $name, 'unit_id' => Unit::query()->where('code', 'PCS')->firstOrFail()->id, 'is_active' => true, 'reorder_level' => 10]);
        $inOne = $low('Low in one');
        $inTwo = $low('Low in two');
        $stock = app(StockService::class);
        $stock->move(product: $inOne, warehouse: $one, sourceType: 'test.in', sourceId: 1, floor: '5');
        $stock->move(product: $inTwo, warehouse: $two, sourceType: 'test.in', sourceId: 2, floor: '5');
        $stock->move(product: $inTwo, warehouse: $two, sourceType: 'test.in', sourceId: 3, floor: '1');

        $page = $this->get(route('inventory.stock.overview', ['warehouse' => $one->id]))->assertOk();

        $this->assertSame(1, $page->viewData('movementsToday'), '⛔ আজকের চলাচল গোটা শাখার — বাছা গুদামের নয়।');
        $this->assertSame([$one->id], collect($page->viewData('recent'))->pluck('warehouse_id')->map(fn ($id) => (int) $id)->unique()->values()->all(),
            '⛔ সাম্প্রতিক তালিকায় অন্য গুদামের সারি।');
        // ⓘ কম মজুদের সংখ্যা বাছা গুদামের: পণ্য ১ এখানে ৫, পণ্য ২ এখানে ০ (গোটা শাখায় ৬ — সেটা অন্য গুদামের মাল)
        $available = collect($page->viewData('lowStock'))->mapWithKeys(fn ($p) => [(int) $p->id => (string) $p->available_qty]);
        $this->assertSame(0, bccomp($available[$inOne->id] ?? 'missing', '5', 4), '⛔ বাছা গুদামের কম মজুদের সংখ্যা ভুল।');
        $this->assertSame(0, bccomp($available[$inTwo->id] ?? 'missing', '0', 4), '⛔ কম মজুদে অন্য গুদামের মাল গোনা হল।');
        $this->assertSame(0, bccomp((string) $page->viewData('states')['floor'], '5', 4), 'প্রস্তুতিটাই ভুল — চারটা অবস্থা আগে থেকেই গুদাম মানত।');

        // ⓘ গুদাম না বাছলে আগের মতোই গোটা শাখা
        $all = $this->get(route('inventory.stock.overview'))->assertOk();
        $this->assertGreaterThanOrEqual(3, $all->viewData('movementsToday'), '⛔ গুদাম না বাছলেও সংখ্যা ছোট হয়ে গেল।');

        // ⛔ নাগালের বাইরের গুদাম বাছলে কিছুই নয় — কাঁচা SQL মডেলের দেয়াল মানে না, তাই সেবাই আটকায়
        $clerk = User::factory()->create(['current_company_id' => $company->id]);
        $clerk->companies()->attach($company->id, ['is_active' => true]);
        \App\Models\UserDataScope::query()->create(['company_id' => $company->id, 'user_id' => $clerk->id,
            'scope_type' => \App\Models\UserDataScope::WAREHOUSE, 'scope_id' => $one->id]);
        app(\App\Core\Services\DataScope::class)->forget();
        $this->actingAs($clerk);

        $theirs = collect(app(\App\Modules\Inventory\Services\StockFacts::class)->forWarehouse($two->id)->lowStock(1000))
            ->firstWhere('id', $inTwo->id);
        $this->assertSame(0, bccomp((string) ($theirs?->available_qty ?? '0'), '0', 4), '⛔ এক গুদামের কেরানি অন্য গুদাম বেছে তার মজুদ দেখলেন।');
    }
}
