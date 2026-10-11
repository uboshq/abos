<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ সমন্বয়ের সফল-বার্তা পরিমাণকে টাকার ধাঁচে লিখত (পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬, মজুদ ⓘ১৪)।
 *
 * ⓘ [[StockController]] পার্থক্যটা `Money::format()`-এ দিত — দুই দশমিকে গোল: ১.২৫৫ কেজির ঘাটতি বার্তায় "১.২৬"। এখন
 * `Money::quantity()` — খাতার চার ঘর, শেষের শূন্য ছাঁটা; খোলা মজুদের বার্তাতেও একই (মূল্যটা টাকা, তাই সেখানে format-ই)।
 */
final class TheAdjustedMessageSaysTheQuantityTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_message_shows_the_weighed_difference_not_a_rounded_amount(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

        $sugar = Product::query()->create(['code' => 'Q14-'.mb_substr(md5(microtime()), 0, 8), 'name_en' => 'Loose sugar', 'name_bn' => 'খোলা চিনি',
            'unit_id' => Unit::query()->where('code', 'KG')->firstOrFail()->id, 'is_active' => true]);
        app(StockService::class)->move(product: $sugar, warehouse: $warehouse, sourceType: 'test.opening', sourceId: $sugar->id, floor: '10');
        app(CostLayerService::class)->receive(product: $sugar, qty: '10', unitCost: '100', sourceType: 'test.opening', sourceId: $sugar->id);

        $this->post(route('inventory.stock.adjust.store'), [
            'product_id' => $sugar->id, 'warehouse_id' => $warehouse->id,
            'reason_code_id' => ReasonCode::query()->inContext(ReasonCode::STOCK_ADJUSTMENT)->active()->orderBy('id')->firstOrFail()->id,
            'counted' => '8.745', 'trx_date' => now()->toDateString(),
        ])->assertSessionHasNoErrors()
            ->assertSessionHas('saved', __('inventory::message.adjusted', ['difference' => '-1.255']));

        // ⓘ খোলা মজুদের বার্তাও — পরিমাণ পরিমাণের মতো, মূল্য টাকার মতো
        $oil = Product::query()->create(['code' => 'Q14O-'.mb_substr(md5(microtime()), 0, 8), 'name_en' => 'Loose oil', 'name_bn' => 'খোলা তেল',
            'unit_id' => Unit::query()->where('code', 'LTR')->firstOrFail()->id, 'is_active' => true]);
        $this->post(route('inventory.stock.opening.store'), [
            'product_id' => $oil->id, 'warehouse_id' => $warehouse->id, 'qty' => '2.755', 'unit_cost' => '100', 'trx_date' => now()->toDateString(),
        ])->assertSessionHasNoErrors()
            ->assertSessionHas('saved', __('inventory::message.opening_saved', ['product' => $oil->name(), 'qty' => '2.755', 'value' => '275.50']));
    }
}
