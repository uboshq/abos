<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\Inventory\Services\PackRebase;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⛔ একক বদলে মজুদের মূল্য নড়লে কমান্ড বলত "লেখা হয়নি", অথচ লেখা হয়ে যেত (পুরো-ERP অডিট, মজুদ ছ১৪; মালিকের "সব খোলা ভুল",
 * ১০ অক্টোবর ২০২৬)।
 *
 * ⓘ [[PackRebase::run()]] `--apply`-তে সবসময় commit করত; মূল্যের যাচাই ছিল কেবল কমান্ডের ছাপা বার্তায়। এখন মূল্য নড়লে কিছুই
 * পাকা নয় — একক, মজুদ আর স্তর আগের মতো, আর রিপোর্ট বলে `written = false`। ⓘ এখানে মূল্যটা ইচ্ছে করে নড়ানো হয়
 * (পণ্য সংরক্ষণের মুহূর্তে একটা স্তরের দাম বদলে), কারণ স্বাভাবিক পথে রূপান্তর মূল্য হুবহু রাখে।
 */
final class ARebaseThatMovesTheValueWritesNothingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_rebase_whose_value_moves_is_rolled_back_even_with_apply(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $carton = Unit::query()->where('code', 'CTN')->firstOrFail();
        $product = Product::query()->create(['company_id' => CompanyContext::id(), 'code' => 'TDRIFT', 'name_en' => 'Drift 40gm',
            'unit_id' => $carton->id, 'is_active' => true]);
        StockMovement::query()->create(['company_id' => CompanyContext::id(), 'product_id' => $product->id,
            'warehouse_id' => Warehouse::query()->orderBy('id')->value('id'), 'trx_date' => now()->toDateString(),
            'floor_change' => '20', 'source_type' => 'opening', 'source_id' => $product->id]);
        app(CostLayerService::class)->receive($product, '20', '240', 'opening', $product->id, 'OPEN');

        // ⓘ মূল্য নড়ানো — পণ্যের একক বদলের মুহূর্তে একটা স্তরের দাম বদলায়
        Product::saving(fn (Product $p) => $p->code === 'TDRIFT'
            ? DB::table('inv_cost_layers')->where('product_id', $p->id)->update(['unit_cost' => DB::raw('unit_cost + 1')]) : null);

        $report = app(PackRebase::class)->run($product, Unit::query()->where('code', 'PCS')->firstOrFail(), '24', apply: true);

        $this->assertNotSame(0, bccomp($report['value_before'], $report['value_after'], 4), 'প্রস্তুতিটাই ভুল — মূল্য নড়েনি।');
        $this->assertFalse($report['written'], '⛔ মূল্য নড়ল, তবু রিপোর্ট বলল লেখা হয়েছে।');
        $this->assertSame((int) $carton->id, (int) $product->fresh()->unit_id, '⛔ মূল্য নড়ল, তবু পণ্যের একক বদলে পাকা হল।');
        $this->assertSame(0, bccomp('20', (string) StockMovement::query()->where('product_id', $product->id)->sum('floor_change'), 4),
            '⛔ মূল্য নড়ল, তবু মজুদ ২৪ গুণ হয়ে রইল।');
    }
}
