<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Engines\Dashboard\Breakdown;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Dashboard\InventoryDashboard;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * মেয়াদ নিয়ন্ত্রণ — মজুদ ড্যাশবোর্ডে মাল আছে এমন লট, মেয়াদের দিন ধরে ভাগে (নতুন ড্যাশবোর্ড, ২ অক্টোবর ২০২৬)।
 *
 * ⭐ প্রতিটা লট ঠিক একটা ভাগে; পেরিয়ে যাওয়াটা আলাদা ভাগ।
 * ⛔ শেষ হয়ে যাওয়া লট (তাকে ০) গোনায় নেই — "মেয়াদ পেরোচ্ছে" রিপোর্টের একই নিয়ম।
 * ⛔ সুইচ বন্ধে চার্টটাই নেই — চালুর আগে পুরনো ড্যাশবোর্ড যেমন ছিল।
 */
final class TheDashboardSortsLotsByExpiryTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_lot_with_goods_lands_in_one_window_and_an_empty_lot_in_none(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $product = Product::query()->firstOrFail();
        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

        $lot = function (string $no, int $days, array $moves) use ($company, $product, $warehouse): void {
            $batch = Batch::query()->create([
                'company_id' => $company->id, 'product_id' => $product->id, 'batch_no' => $no,
                'expiry_date' => now()->addDays($days)->toDateString(),
            ]);

            foreach ($moves as $i => $qty) {
                StockMovement::query()->create([
                    'company_id' => $company->id, 'product_id' => $product->id, 'warehouse_id' => $warehouse->id,
                    'batch_id' => $batch->id, 'trx_date' => now()->toDateString(), 'floor_change' => $qty,
                    'source_type' => 'test', 'source_id' => 1, 'document_no' => 'EXP-'.$no.'-'.$i,
                ]);
            }
        };

        $before = $this->windows();

        $lot('GONE', -3, ['10']);
        $lot('WEEK', 5, ['10']);
        $lot('MONTH', 20, ['10']);
        $lot('QTR', 60, ['10']);
        $lot('LATER', 200, ['10']);
        $lot('SOLDOUT', 5, ['10', '-10']);

        $after = $this->windows();

        $this->assertSame(
            ['expired' => 1, 'within_7' => 1, 'within_30' => 1, 'within_90' => 1, 'later' => 1],
            array_map(fn ($key) => $after[$key] - $before[$key], array_combine(array_keys($after), array_keys($after))),
            '⛔ লটগুলো ভুল ভাগে — বা শেষ হয়ে যাওয়া লটও গোনা হয়েছে।',
        );

        config(['abos.dashboards_v2' => false]);
        $labels = array_map(fn ($p) => $p->label, InventoryDashboard::dashboard()->panels);
        $this->assertNotContains(__('inventory::dashboard.expiry_title'), $labels, '⛔ সুইচ বন্ধ, তবু মেয়াদের চার্ট — পুরনো ড্যাশবোর্ড বদলে গেছে।');
    }

    /** @return array<string, int> */
    private function windows(): array
    {
        config(['abos.dashboards_v2' => true]);

        $panel = collect(InventoryDashboard::dashboard()->panels)->firstWhere('label', __('inventory::dashboard.expiry_title'));
        $this->assertInstanceOf(Breakdown::class, $panel, 'মেয়াদ নিয়ন্ত্রণের চার্ট নেই।');

        $out = [];
        foreach (['expired', 'within_7', 'within_30', 'within_90', 'later'] as $i => $key) {
            $this->assertSame(__('inventory::dashboard.expiry_'.$key), $panel->parts[$i]['label']);
            $out[$key] = (int) $panel->parts[$i]['value'];
        }

        return $out;
    }
}
