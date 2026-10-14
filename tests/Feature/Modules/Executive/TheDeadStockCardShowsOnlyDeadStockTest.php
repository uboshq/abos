<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Executive;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⛔ বিশ্লেষণের "অচল মাল" কার্ডে ধীর মালও আসত — ec, Executive লাইন-নিরীক্ষা, ১৪ অক্টোবর ২০২৬।
 *
 * ⓘ কার্ডে চাপলে খোলে `status=dead`-এর তালিকা, অথচ কার্ড নিজে অবস্থা না বেছে ধীর-অচল দুটোই টানত: ১০০ দিন পড়ে থাকা
 * দামি মাল (ধীর) কার্ডের মাথায়, চাপলে তালিকায় নেই। এখন কার্ড আর তালিকা একই কথা বলে।
 */
final class TheDeadStockCardShowsOnlyDeadStockTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    public function test_the_dead_stock_card_lists_only_what_the_dead_list_shows(): void
    {
        $this->seed(DemoSeeder::class);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $company = Company::query()->findOrFail($owner->current_company_id);
        CompanyContext::set($company->id, $owner->current_branch_id === null ? null : (int) $owner->current_branch_id);
        $this->actingAs($owner);

        // ⓘ হেডারের শাখার গুদাম — কার্ড চলতি কোম্পানিকে হেডারের শাখায় দেখে
        $this->warehouse = Warehouse::query()->withoutGlobalScopes()->where('company_id', $company->id)
            ->when($owner->current_branch_id !== null, fn ($q) => $q->where('branch_id', $owner->current_branch_id))
            ->orderBy('id')->firstOrFail();

        // ⓘ দুটোই বিশাল দামের, যেন ডেমোর যেকোনো মালের উপরে সেরা দশে বসে
        $slow = $this->product('ZQ-SLOW');
        $this->move($slow, '10', 200);
        $this->move($slow, '-2', 100);
        $this->layer($slow, '8', '9000000');

        $dead = $this->product('ZQ-DEAD');
        $this->move($dead, '10', 200);
        $this->layer($dead, '10', '8000000');

        $rows = $this->get(route('executive.analysis'))->assertOk()->viewData('lists')['dead']['rows'];
        $names = array_map(fn ($r) => explode(' - ', (string) $r['product_name'])[0], $rows);

        $this->assertContains('ZQ-DEAD', $names, 'প্রস্তুতিটাই ভুল — অচল মাল কার্ডে নেই।');
        $this->assertNotContains('ZQ-SLOW', $names, '⛔ ধীর মাল "অচল মাল" কার্ডে — চাপলে খোলা তালিকায় সে নেই।');
    }

    private function product(string $code): Product
    {
        return Product::query()->create([
            'code' => $code, 'name_en' => $code.' probe', 'name_bn' => $code.' নমুনা',
            'unit_id' => Unit::query()->orderBy('id')->firstOrFail()->id, 'is_active' => true,
        ]);
    }

    private function move(Product $product, string $qty, int $daysAgo): void
    {
        DB::table('inv_stock_movements')->insert([
            'company_id' => CompanyContext::id(), 'branch_id' => $this->warehouse->branch_id,
            'product_id' => $product->id, 'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->subDays($daysAgo)->toDateString(), 'floor_change' => $qty,
            'source_type' => 'test.idle', 'source_id' => $product->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function layer(Product $product, string $qty, string $cost): void
    {
        DB::table('inv_cost_layers')->insert([
            'company_id' => CompanyContext::id(), 'product_id' => $product->id, 'source_type' => 'test', 'source_id' => $product->id,
            'trx_date' => now()->subDays(200)->toDateString(), 'qty_in' => $qty, 'qty_remaining' => $qty, 'unit_cost' => $cost,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
