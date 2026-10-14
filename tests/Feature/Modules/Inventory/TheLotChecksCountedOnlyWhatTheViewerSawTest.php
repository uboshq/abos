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
use App\Modules\Inventory\Services\BatchService;
use App\Modules\Inventory\Services\StockAdjustmentService;
use App\Modules\Inventory\Services\StockService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * লটের যাচাই কেবল দেখার দেয়ালের ভেতরটা গুনত — মজুদ লাইন-ধরে নিরীক্ষা, ১৩ অক্টোবর ২০২৬।
 *
 * ⛔ [[StockService::VIEW_WALLS]]-এর নিয়ম ("দেয়াল মানুষের দেখার জন্য, তাকের সত্যির জন্য নয়") চারটা গোনায় খাটেনি:
 * [[StockAdjustmentService::lotFloor()]] (গণনার লট-যাচাই), [[Batch::floorBalance()]] আর `freeBalance()` গুদাম ধরে
 * (লট-বণ্টনের যাচাই), [[StrandedStock]]-এর লটহীন সীমা আর মান-পরীক্ষার অপেক্ষার আটকানো। ⓘ অন্য গুদামে সীমিত অনুমোদক বা
 * পরিদর্শক তখন "লট কম" বা "০ আটকানো" পেতেন — অনুমোদন ফিরত, বা একই মাল দুইবার আটকাত।
 * ⓘ গুদাম না বলা যোগফল (দেখার সংখ্যা) আগের মতো দেয়ালসহ।
 */
final class TheLotChecksCountedOnlyWhatTheViewerSawTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_warehouse_limited_person_gets_the_whole_warehouse_in_a_lot_check(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $branch = $company->defaultBranch();
        CompanyContext::set($company->id, $branch->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $mine = Warehouse::acrossWarehouses()->create(['code' => 'LC-MINE', 'name_en' => 'Mine', 'is_active' => true, 'branch_id' => $branch->id]);
        $theirs = Warehouse::acrossWarehouses()->create(['code' => 'LC-THEIRS', 'name_en' => 'Theirs', 'is_active' => true, 'branch_id' => $branch->id]);
        $product = Product::query()->orderBy('id')->firstOrFail();
        $lot = app(BatchService::class)->receive($product, 'LC-LOT');
        app(StockService::class)->move(product: $product, warehouse: $theirs, sourceType: 'test.in', sourceId: 1, floor: '7', batch: $lot);

        $clerk = User::query()->where('email', 'accounts@abos.test')->firstOrFail();
        UserDataScope::query()->create(['company_id' => $company->id, 'user_id' => $clerk->id, 'scope_type' => UserDataScope::WAREHOUSE, 'scope_id' => $mine->id]);
        app(DataScope::class)->forget();
        $this->actingAs($clerk);

        $this->assertSame(0, bccomp(app(StockAdjustmentService::class)->lotFloor($lot, $theirs), '7', 4),
            'গণনার লট-যাচাই দেয়ালের ভেতরটাই গুনল — অন্য গুদামের অনুমোদক "লট কম" পেতেন।');
        $this->assertSame(0, bccomp($lot->floorBalance($theirs), '7', 4),
            'লট-বণ্টনের তাক-গোনা দেয়ালের ভেতরটাই গুনল।');
        $this->assertSame(0, bccomp($lot->floorBalance(), '0', 4),
            'গুদাম না বলা দেখার যোগফলে দেয়াল নেই — সীমিত মানুষ অন্য গুদামের মাল দেখছেন।');
    }
}
