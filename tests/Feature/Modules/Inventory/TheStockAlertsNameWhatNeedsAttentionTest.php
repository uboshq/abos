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
 * মজুদের সতর্কতা — রিপোর্ট সেন্টার ধাপ ৪ ([[InventoryControlReports::stockAlerts()]])।
 *
 *   স্তর ৫, হাতে ৩       → স্তরের নিচে
 *   এল ৪, গেল ৪          → শূন্য
 *   গেল ২ (আসেনি)        → শূন্যের নিচে
 *   সর্বোচ্চ ১০, হাতে ১৫ → বেশি জমা
 *   স্তর ৫, হাতে ৮       → কিছুই নয়
 * ⓘ ধরন বেছে কেবল সেই সতর্কতা; আর গুদামটা অন্য শাখার হলে এই শাখায় কোনো সতর্কতাই নেই।
 */
final class TheStockAlertsNameWhatNeedsAttentionTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    public function test_each_product_gets_its_one_alert_and_the_branch_wall_holds(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

        $below = $this->product('ZQ-BELOW', reorder: '5');
        $this->move($below, '3');
        $zero = $this->product('ZQ-ZERO');
        $this->move($zero, '4');
        $this->move($zero, '-4');
        $negative = $this->product('ZQ-NEG');
        $this->move($negative, '-2');
        $over = $this->product('ZQ-OVER', max: '10');
        $this->move($over, '15');
        $fine = $this->product('ZQ-FINE', reorder: '5');
        $this->move($fine, '8');

        $alerts = $this->alerts([]);
        $this->assertSame([
            'ZQ-BELOW' => __('inventory::control.alert_below'),
            'ZQ-NEG' => __('inventory::control.alert_negative'),
            'ZQ-OVER' => __('inventory::control.alert_over'),
            'ZQ-ZERO' => __('inventory::control.alert_zero'),
        ], $alerts, 'সতর্কতাগুলো ভুল — বা ঠিক পণ্যটা তালিকার বাইরে রইল।');

        $this->assertSame(['ZQ-OVER' => __('inventory::control.alert_over')], $this->alerts(['status' => 'over']));

        $other = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)
            ->where('id', '<>', $company->defaultBranch()->id)->firstOrFail();
        $this->warehouse->forceFill(['branch_id' => $other->id])->saveQuietly();

        $this->assertSame([], $this->alerts(['branch_id' => $company->defaultBranch()->id]),
            '⛔ অন্য শাখার গুদামের মাল এই শাখার সতর্কতায়।');
    }

    /** @return array<string, string> কোড → সতর্কতা, কেবল এই পরীক্ষার পণ্য */
    private function alerts(array $extra): array
    {
        $rows = app(ReportEngine::class)->run('inventory.stock_alerts', $extra, perPage: 1000)->rows;

        return collect($rows)
            ->filter(fn ($r) => str_starts_with((string) $r['product_name'], 'ZQ-'))
            ->mapWithKeys(fn ($r) => [explode(' - ', (string) $r['product_name'])[0] => (string) $r['alert']])
            ->sortKeys()
            ->all();
    }

    private function product(string $code, string $reorder = '0', ?string $max = null): Product
    {
        return Product::query()->create([
            'code' => $code,
            'name_en' => $code.' probe',
            'name_bn' => $code.' নমুনা',
            'unit_id' => Unit::query()->orderBy('id')->firstOrFail()->id,
            'is_active' => true,
            'reorder_level' => $reorder,
            'max_level' => $max,
        ]);
    }

    /** ⓘ সরাসরি সারি — শূন্যের নিচের মজুদ সেবা দিয়ে বানানো যায় না (পাহারা থামায়), অথচ পুরনো আমদানিতে থাকে */
    private function move(Product $product, string $qty): void
    {
        DB::table('inv_stock_movements')->insert([
            'company_id' => CompanyContext::id(),
            'branch_id' => $this->warehouse->branch_id,
            'product_id' => $product->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
            'floor_change' => $qty,
            'source_type' => 'test.alert',
            'source_id' => $product->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
