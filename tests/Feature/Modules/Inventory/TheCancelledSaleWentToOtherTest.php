<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Dashboard\InventoryWidgets;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockFacts;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * বাতিল বিক্রি "অন্য"-তে পড়ত, আর হোম আর ড্যাশবোর্ডের "পুনঃক্রয়ের নিচে" দুই সংখ্যা বলত — Inventory অডিট ম২৮, ৫ অক্টোবর ২০২৬।
 *
 * ⛔ চলাচলের সারাংশ উৎসের পুরো নাম মেলাত, তাই `delivery_challan:cancel` কোনো ঘরে না মিলে "অন্য"-তে যেত: বিক্রি ১০,
 * অন্য +৪, অথচ আসলে বিক্রি ৬। আর হোমের উইজেট নিজের হাতে লেখা কোয়েরিতে সব শাখার গুদাম গুনত, ড্যাশবোর্ড শাখার।
 * ⭐ এখন উল্টো সারি নিজের ঘরেই কাটে, আর দুই পর্দা একই প্রশ্ন একই জায়গা থেকে করে।
 */
final class TheCancelledSaleWentToOtherTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    public function test_a_cancelled_challan_cuts_the_sale_not_lands_in_other(): void
    {
        $product = Product::query()->create(['code' => 'M28', 'name_en' => 'Cancel probe', 'name_bn' => 'বাতিল-নমুনা',
            'unit_id' => Unit::query()->orderBy('id')->firstOrFail()->id, 'is_active' => true]);
        $warehouse = Warehouse::query()->orderBy('id')->firstOrFail();

        foreach ([[\App\Modules\Inventory\Services\OpeningStockService::SOURCE_TYPE, '20'], ['delivery_challan', '-10'], ['delivery_challan:cancel', '4']] as $i => [$source, $qty]) {
            StockMovement::query()->create(['company_id' => $this->company->id, 'branch_id' => $warehouse->branch_id,
                'product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'trx_date' => '2026-09-10',
                'floor_change' => $qty, 'source_type' => $source, 'source_id' => $i + 1]);
        }

        $row = app(ReportEngine::class)->run('inventory.movement_summary', [
            'from' => '2026-09-01', 'to' => '2026-09-30', 'product_id' => $product->id,
        ])->rows[0];

        $this->assertSame(0, bccomp((string) $row['sold'], '6', 4), '⛔ বাতিল চালান বিক্রি কমাল না।');
        $this->assertSame(0, bccomp((string) $row['other'], '0', 4), '⛔ বাতিলের উল্টো সারি "অন্য"-তে পড়ল।');
        $this->assertSame(0, bccomp((string) $row['closing'], '14', 4));
    }

    public function test_the_home_widget_and_the_dashboard_count_the_same_products_below_reorder(): void
    {
        $home = $this->company->defaultBranch();
        $other = \App\Models\Branch::query()->where('company_id', $this->company->id)->whereKeyNot($home->id)->first()
            ?? \App\Models\Branch::query()->create(['company_id' => $this->company->id, 'code' => 'M28B', 'name_en' => 'Other branch', 'is_active' => true]);
        $away = Warehouse::query()->create(['code' => 'M28W', 'name_en' => 'Away store', 'is_active' => true, 'branch_id' => $other->id]);

        // ⓘ মাল কেবল অন্য শাখার গুদামে — এই শাখায় শূন্য, তাই এই শাখার চোখে পুনঃক্রয়ের নিচে
        $product = Product::query()->create(['code' => 'M28R', 'name_en' => 'Reorder probe', 'name_bn' => 'পুনঃক্রয়-নমুনা',
            'unit_id' => Unit::query()->orderBy('id')->firstOrFail()->id, 'is_active' => true, 'reorder_level' => 5]);
        StockMovement::query()->create(['company_id' => $this->company->id, 'branch_id' => $other->id, 'product_id' => $product->id,
            'warehouse_id' => $away->id, 'trx_date' => now()->toDateString(), 'floor_change' => '10', 'source_type' => 'test.m28', 'source_id' => 1]);

        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $owner->forceFill(['view_all_branches' => false, 'current_branch_id' => $home->id])->save();
        app(\App\Core\Services\DataScope::class)->forget();
        $this->actingAs($owner->fresh());
        $this->assertSame((int) $home->id, \App\Core\Support\ViewedBranch::one(), 'প্রস্তুতিটাই ভুল — এক শাখা দেখা হচ্ছে না।');

        $widget = collect(InventoryWidgets::widgets())->first(fn ($w) => $w->label === __('inventory::dashboard.below_reorder'));

        $this->assertNotNull($widget, 'প্রস্তুতিটাই ভুল — উইজেট পাওয়া গেল না।');
        $this->assertSame((string) app(StockFacts::class)->belowReorder(), (string) $widget->value,
            '⛔ হোম আর ড্যাশবোর্ড "পুনঃক্রয়ের নিচে" আলাদা সংখ্যা বলল।');
    }
}
