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
 * ধীর ও অচল মাল — রিপোর্ট সেন্টার ধাপ ৪ ([[InventoryControlReports::slowAndDead()]])।
 *
 *   ২০০ দিন আগে এল, একটাও যায়নি              → অচল
 *   ২০০ দিন আগে ১০ এল, ১০০ দিন আগে ২ গেল     → ধীর; বছরে ঘোরে ২/৮ = ০.২৫; মূল্য ৮ × ৫০ = ৪০০
 *   ১০ দিন আগে গেল                            → তালিকায় নেই
 *   হাতে কিছু নেই                             → তালিকায় নেই
 * ⓘ অন্য শাখার গুদাম হলে এই শাখায় কিছুই নেই।
 */
final class TheIdleStockIsNamedSlowOrDeadTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    public function test_idle_goods_are_named_with_their_turns_and_value_inside_the_branch_wall(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

        $dead = $this->product('ZQ-DEAD');
        $this->move($dead, '10', 200);

        $slow = $this->product('ZQ-SLOW');
        $this->move($slow, '10', 200);
        $this->move($slow, '-2', 100);
        DB::table('inv_cost_layers')->insert([
            'company_id' => CompanyContext::id(), 'product_id' => $slow->id, 'source_type' => 'test', 'source_id' => 1,
            'trx_date' => now()->subDays(200)->toDateString(), 'qty_in' => '10', 'qty_remaining' => '8', 'unit_cost' => '50',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $busy = $this->product('ZQ-BUSY');
        $this->move($busy, '10', 200);
        $this->move($busy, '-3', 10);

        $gone = $this->product('ZQ-GONE');
        $this->move($gone, '5', 300);
        $this->move($gone, '-5', 250);

        $rows = $this->rows([]);
        $this->assertSame(['ZQ-DEAD', 'ZQ-SLOW'], array_keys($rows), 'ধীর-অচলের তালিকা ভুল।');
        $this->assertSame(__('inventory::control.state_dead'), $rows['ZQ-DEAD']['state']);
        $this->assertSame(__('inventory::control.state_slow'), $rows['ZQ-SLOW']['state']);
        $this->assertSame(0, bccomp((string) $rows['ZQ-SLOW']['turns'], '0.25', 2), 'বছরে ঘোরা ভুল।');
        $this->assertSame(0, bccomp((string) $rows['ZQ-SLOW']['value'], '400', 2), 'মূল্য ভুল।');

        $this->assertSame(['ZQ-DEAD'], array_keys($this->rows(['status' => 'dead'])));

        $other = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)
            ->where('id', '<>', $company->defaultBranch()->id)->firstOrFail();
        $this->warehouse->forceFill(['branch_id' => $other->id])->saveQuietly();
        $this->assertSame([], $this->rows(['branch_id' => $company->defaultBranch()->id]), '⛔ অন্য শাখার মাল এই শাখায়।');
    }

    /** @return array<string, array<string, mixed>> কোড → সারি, কেবল এই পরীক্ষার পণ্য */
    private function rows(array $extra): array
    {
        return collect(app(ReportEngine::class)->run('inventory.slow_dead', $extra, perPage: 1000)->rows)
            ->filter(fn ($r) => str_starts_with((string) $r['product_name'], 'ZQ-'))
            ->mapWithKeys(fn ($r) => [explode(' - ', (string) $r['product_name'])[0] => $r])
            ->sortKeys()
            ->all();
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
}
