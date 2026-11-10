<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Dashboard\InventoryBoard;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ মেয়াদ নিয়ন্ত্রণ — মজুদের ড্যাশবোর্ডের "মেয়াদ ঘনিয়ে আসা" ঘর (মালিকের চূড়ান্ত নমুনা HmzeE5CN2wvtyLPE5exjvV; আগে v2-এর মেয়াদের চার্ট)।
 *
 * দাবি: মাল থাকা লটই আসে, সামনের দিনের মধ্যে যার মেয়াদ, দিনের ক্রমে; শেষ হয়ে যাওয়া লট (বিক্রি হয়ে শূন্য) আসে না; মেয়াদ পেরোনো লট আর
 * দিনের সীমার বাইরের লট এই তালিকায় নেই ([[InventoryBoard::expiringLots()]])।
 */
final class TheDashboardSortsLotsByExpiryTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_lots_with_goods_inside_the_window_come_and_in_day_order(): void
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

        $lot('GONE', -3, ['10']);
        $lot('MONTH', 20, ['10']);
        $lot('WEEK', 5, ['10']);
        $lot('LATER', 200, ['10']);
        $lot('SOLDOUT', 5, ['10', '-10']);

        $mine = array_values(array_filter(app(InventoryBoard::class)->expiringLots(60), fn (array $l) => in_array($l['batch'], ['GONE', 'WEEK', 'MONTH', 'LATER', 'SOLDOUT'], true)));

        $this->assertSame(['WEEK', 'MONTH'], array_column($mine, 'batch'), '⛔ ভুল লট এল — শেষ হওয়া, মেয়াদ পেরোনো বা সীমার বাইরের লটও, বা দিনের ক্রমে নয়।');
        $this->assertSame([5, 20], array_column($mine, 'days'), '⛔ মেয়াদের দিন ভুল।');
        $this->assertSame(0, bccomp($mine[0]['qty'], '10', 4), '⛔ লটের পরিমাণ চলাচলের যোগ নয়।');
    }
}
