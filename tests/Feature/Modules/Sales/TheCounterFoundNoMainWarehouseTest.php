<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\BatchService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Inventory\Services\WarehouseService;
use App\Modules\MasterData\Models\Unit;
use App\Modules\Sales\Services\DirectSaleOptions;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * অন্য শাখার কাউন্টার প্রধান গুদাম না পেয়ে লট খালি দেখাত — লাইভের ত্রুটি (fe, ৬ অক্টোবর ২০২৬)।
 *
 * ⛔ ইউনিভারের সাত শাখার কেবল একটায় প্রধান গুদাম; বাকিদের কাউন্টার `null` গুদাম পেত, আর লট খালি — মালিক: *"লটে কোনো লট
 * দেখায় না, তাই বাছা যায় না, না বাছলে qty বসে না"*।
 * ⭐ এখন প্রধান না থাকলে শাখার একমাত্র চালু গুদাম ([[Warehouse::defaultInView()]]), একাধিক হলে স্পষ্ট বার্তা; শাখার প্রথম গুদাম
 * নিজেই প্রধান; আর পুরনো তথ্যে একমাত্র গুদামকে প্রধান করার মাইগ্রেশন।
 */
final class TheCounterFoundNoMainWarehouseTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Branch $home;

    private Branch $other;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->home = $this->company->defaultBranch();
        $this->other = Branch::query()->where('company_id', $this->company->id)->whereKeyNot($this->home->id)->first()
            ?? Branch::query()->create(['company_id' => $this->company->id, 'code' => 'CNB', 'name_en' => 'Other branch', 'is_active' => true]);
        CompanyContext::set($this->company->id, $this->home->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        // ⓘ অন্য শাখার সব গুদাম সরিয়ে রাখা — শাখাটা যেন কেবল এই পরীক্ষার গুদাম চেনে
        Warehouse::query()->withoutGlobalScopes()->where('branch_id', $this->other->id)->update(['is_active' => false, 'is_default' => false]);
    }

    public function test_the_other_branchs_counter_sees_its_own_lots_when_its_only_warehouse_is_not_main(): void
    {
        $store = $this->warehouse('CNW-1', $this->other);
        $this->assertFalse((bool) $store->fresh()->is_default, 'প্রস্তুতিটাই ভুল — গুদামটা প্রধান।');

        $product = Product::query()->create(['code' => 'CN-LOT', 'name_en' => 'Lot soap', 'name_bn' => 'লট সাবান',
            'unit_id' => Unit::query()->orderBy('id')->firstOrFail()->id, 'is_active' => true, 'track_batch' => true]);
        $lot = app(BatchService::class)->receive($product, 'CN-L1', Carbon::today()->addMonths(6)->toDateString());
        app(StockService::class)->move(product: $product, warehouse: $store, sourceType: 'test.in', sourceId: 1, floor: '8', batch: $lot);

        $this->viewBranch($this->other);

        $found = Warehouse::defaultInView();
        $this->assertNotNull($found, '⛔ প্রধান না থাকা শাখায় কাউন্টার কোনো গুদাম পেল না।');
        $this->assertSame((int) $store->id, (int) $found->id);
        $this->assertNotEmpty(app(DirectSaleOptions::class)->lots($found), '⛔ শাখার কাউন্টারে লট খালি।');
    }

    public function test_several_warehouses_and_no_main_one_says_so_instead_of_guessing(): void
    {
        $this->warehouse('CNW-A', $this->other);
        $this->warehouse('CNW-B', $this->other);
        Warehouse::query()->withoutGlobalScopes()->where('branch_id', $this->other->id)->update(['is_default' => false]);

        $this->viewBranch($this->other);

        $this->assertNull(Warehouse::defaultInView(), '⛔ দুইটা গুদামের মধ্যে একটা আন্দাজে নিল।');
        $this->assertSame(__('inventory::validation.choose_main_warehouse'), Warehouse::mainMissingMessage());
    }

    public function test_a_branchs_first_warehouse_becomes_its_main_one(): void
    {
        // ⓘ "সব শাখা" দেখা অবস্থায় — আগে এখানে গোনা হত দেখা সব গুদাম, তাই দ্বিতীয় শাখার প্রথমটা প্রধান হত না
        $store = app(WarehouseService::class)->create(['name_en' => 'First of its branch', 'branch_id' => $this->other->id]);

        $this->assertTrue((bool) $store->fresh()->is_default, '⛔ শাখার প্রথম গুদাম প্রধান হলো না।');
        $this->assertTrue((bool) Warehouse::query()->withoutGlobalScopes()->where('branch_id', $this->home->id)->where('is_default', true)->exists(),
            'অন্য শাখার প্রধান হারাল।');
    }

    public function test_the_migration_makes_a_branchs_only_warehouse_its_main_one(): void
    {
        $store = $this->warehouse('CNW-M', $this->other);
        DB::table('inv_warehouses')->where('id', $store->id)->update(['is_default' => false]);

        $migration = require base_path('app/Modules/Inventory/Database/Migrations/2027_02_16_110000_a_branch_with_one_warehouse_had_no_main_one.php');
        $migration->up();

        $this->assertTrue((bool) DB::table('inv_warehouses')->where('id', $store->id)->value('is_default'), '⛔ একমাত্র গুদাম প্রধান হলো না।');
    }

    private function warehouse(string $code, Branch $branch): Warehouse
    {
        return Warehouse::query()->create(['code' => $code, 'name_en' => $code, 'is_active' => true, 'branch_id' => $branch->id]);
    }

    private function viewBranch(Branch $branch): void
    {
        $this->owner->forceFill(['view_all_branches' => false, 'current_branch_id' => $branch->id])->save();
        CompanyContext::set($this->company->id, $branch->id);
        app(DataScope::class)->forget();
        $this->actingAs($this->owner->fresh());
    }
}
