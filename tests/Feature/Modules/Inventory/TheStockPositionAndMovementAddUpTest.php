<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
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
 * মজুদের অবস্থা ও মালের চলাচল — রিপোর্ট সেন্টার ধাপ ৩ ([[InventoryAnalysisReports]])।
 *
 * এক পণ্য: আগের মাসে ২০ কেনা; এই সময়ে ৩০ কেনা (১০ এখনো বসানো বাকি), ১২ বেচা, ৫ আটকে, ২ ধরা (বিক্রির জন্য),
 * ৪ স্থানান্তরে গেল, ১ সমন্বয়ে কম, ৩ বিক্রয়-ফেরত।
 *   অবস্থা → হাতে ৩৬, তাকে ২১, আটকে ৫, বসানো বাকি ১০, ধরা ২, বেচা যাবে ১৪, মূল্য ৩৬ × ৫০ = ১,৮০০
 *   চলাচল → শুরু ২০, কেনা ৩০, বেচা ১২, ফেরত এল ৩, স্থানান্তরে গেল ৪, সমন্বয় −১, শেষ ৩৬ (শুরু + সব = শেষ)
 * ⓘ গুদামটা অন্য শাখার হলে এই শাখায় কিছুই নেই।
 */
final class TheStockPositionAndMovementAddUpTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private Warehouse $warehouse;

    public function test_position_and_movement_add_up_and_stay_inside_the_branch_wall(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

        $this->product = Product::query()->create([
            'code' => 'ZQ-POS', 'name_en' => 'Position probe', 'name_bn' => 'অবস্থার নমুনা',
            'unit_id' => Unit::query()->orderBy('id')->firstOrFail()->id, 'is_active' => true,
        ]);
        DB::table('inv_cost_layers')->insert([
            'company_id' => CompanyContext::id(), 'product_id' => $this->product->id, 'source_type' => 'test', 'source_id' => 1,
            'trx_date' => now()->subMonth()->toDateString(), 'qty_in' => '50', 'qty_remaining' => '36', 'unit_cost' => '50',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $before = now()->subDays(40)->toDateString();
        $this->move('purchase_receipt', $before, floor: '20');
        $this->move('purchase_receipt', null, floor: '20');
        $this->move('purchase_bill', null, unplaced: '10');
        $this->move('delivery_challan', null, floor: '-12');
        // ⓘ অ্যাপের আটকানো তাক কমায় না, কেবল আটকায় ([[StockService::hold()]]) — আটকানো মাল তাকেরই অংশ (অডিট ম১০, ৫ অক্টোবর ২০২৬;
        // ⛔ আগে এখানে তাক −৫ লেখা ছিল, আর রিপোর্টের "হাতে"-তে আটকানো আলাদা যোগ — দুই ভুল কাটাকাটি হয়ে সবুজ দেখাত)
        $this->move('quality_hold', null, hold: '5');
        $this->move('sales_order', null, reserved: '2');
        $this->move('stock_transfer', null, floor: '-4');
        $this->move('stock_adjustment', null, floor: '-1');
        $this->move('sales_return', null, floor: '3');

        $position = collect($this->report('inventory.stock_position', []))->firstWhere('group_key', $this->product->id);
        $this->assertSame(['36', '26', '5', '10', '2', '19', '1800'], $this->nums($position,
            ['on_hand', 'floor', 'held', 'unplaced', 'reserved', 'sellable', 'value']), 'মজুদের অবস্থা ভুল।');

        $movement = collect($this->report('inventory.movement_summary', [
            'from' => now()->subDays(29)->toDateString(), 'to' => now()->toDateString(),
        ]))->firstWhere('product_id', $this->product->id);
        $this->assertSame(['20', '30', '12', '3', '0', '0', '4', '-1', '0', '36'], $this->nums($movement,
            ['opening', 'bought', 'sold', 'returned_in', 'returned_out', 'transfer_in', 'transfer_out', 'adjusted', 'other', 'closing']),
            'চলাচল ভুল — বা শুরু + সব ≠ শেষ।');

        $other = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)
            ->where('id', '<>', $company->defaultBranch()->id)->firstOrFail();
        $this->warehouse->forceFill(['branch_id' => $other->id])->saveQuietly();

        $this->assertNull(collect($this->report('inventory.stock_position', ['branch_id' => $company->defaultBranch()->id]))
            ->firstWhere('group_key', $this->product->id), '⛔ অন্য শাখার গুদামের মাল এই শাখার অবস্থায়।');
    }

    private function move(string $source, ?string $date, string $floor = '0', string $unplaced = '0', string $hold = '0', string $reserved = '0'): void
    {
        DB::table('inv_stock_movements')->insert([
            'company_id' => CompanyContext::id(), 'branch_id' => $this->warehouse->branch_id,
            'product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id,
            'trx_date' => $date ?? now()->toDateString(),
            'floor_change' => $floor, 'unplaced_change' => $unplaced, 'hold_change' => $hold, 'reserved_change' => $reserved,
            'source_type' => $source, 'source_id' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function report(string $key, array $filters): array
    {
        return app(ReportEngine::class)->run($key, $filters, perPage: 2000)->rows;
    }

    /** @return list<string> */
    private function nums(?array $row, array $keys): array
    {
        $this->assertNotNull($row, 'পণ্যের সারি নেই।');

        return array_map(fn ($k) => (string) round((float) $row[$k]), $keys);
    }
}
