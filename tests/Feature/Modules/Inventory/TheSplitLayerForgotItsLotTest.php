<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\CostLayer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\PackRebase;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * প্যাক-রূপান্তরে ভাগ হওয়া স্তর তার লট ভুলে যেত — Inventory অডিট ম৭ (প্যাক-রূপান্তর), ৫ অক্টোবর ২০২৬।
 *
 * ⛔ কার্টন থেকে পিসে নামালে টাকা হুবহু রাখতে স্তরটা দুই সারিতে ভাগ হয় ([[PackRebase]]); নতুন সারিতে `batch_id` বসত না —
 * ২৩ কার্টনের লটের কিছু পিসের দাম তখন লটহীন, আর লট ধরে বিক্রিতে সেগুলো "লটের স্তর নেই" হয়ে অন্য স্তর থেকে টানত।
 * ⭐ এখন ভাগের সারিও মূল স্তরের লট নেয়।
 */
final class TheSplitLayerForgotItsLotTest extends TestCase
{
    use RefreshDatabase;

    public function test_both_halves_of_a_split_layer_keep_the_lot(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $product = Product::query()->create([
            'company_id' => CompanyContext::id(), 'code' => 'TSPLIT', 'name_en' => 'Split 40gm',
            'unit_id' => Unit::query()->where('code', 'CTN')->firstOrFail()->id, 'is_active' => true, 'track_batch' => true,
        ]);
        $lot = Batch::query()->create(['product_id' => $product->id, 'batch_no' => 'SPL-1', 'expiry_date' => now()->addYear()->toDateString()]);

        // ⓘ লাইভের মাপ: ২৩ কার্টন × ১৭২.৫৪ — ৫৫২ পিসে এক দরে বসে না, তাই স্তর ভাগ হয়
        StockMovement::query()->create([
            'company_id' => CompanyContext::id(), 'product_id' => $product->id, 'warehouse_id' => Warehouse::query()->orderBy('id')->value('id'),
            'batch_id' => $lot->id, 'trx_date' => now()->toDateString(), 'floor_change' => '23', 'source_type' => 'opening', 'source_id' => $product->id,
        ]);
        CostLayer::query()->create([
            'company_id' => CompanyContext::id(), 'product_id' => $product->id, 'batch_id' => $lot->id,
            'source_type' => 'opening', 'source_id' => $product->id, 'trx_date' => now()->toDateString(),
            'qty_in' => '23', 'qty_remaining' => '23', 'unit_cost' => '172.54',
        ]);

        $report = app(PackRebase::class)->run($product, Unit::query()->where('code', 'PCS')->firstOrFail(), '24', apply: true);
        $this->assertGreaterThan(0, $report['layers_split'], 'প্রস্তুতিটাই ভুল — স্তর ভাগ হয়নি।');

        $layers = CostLayer::query()->where('product_id', $product->id)->get();
        $this->assertCount(2, $layers);
        $this->assertSame([$lot->id, $lot->id], $layers->pluck('batch_id')->map(fn ($id) => (int) $id)->all(),
            '⛔ ভাগের সারি লট হারিয়েছে — তার পিসগুলোর দাম এখন লটহীন।');
    }
}
