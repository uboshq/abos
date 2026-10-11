<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\Inventory\Services\StockAdjustmentService;
use App\Modules\Inventory\Services\StockCountService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⛔ গণনা, সমন্বয় আর দিয়ে-দেওয়ায় আস্ত-এককের নিয়ম ছিল না (পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬, মজুদ ⚠️১২)।
 *
 * ⓘ কাগজের লাইন [[PackConversion::toStockQty()]] দিয়ে যায় আর পিস-বাক্সে আধা থামে, কেজি-লিটারে চলে (মালিক, ৬ অক্টোবর
 * ২০২৬)। [[StockCountService]] আর [[StockAdjustmentService]] ওই দরজা দিয়ে যেত না — "২.৫ পিস" গোনা, মানা আর দিয়ে দেওয়া যেত,
 * আর আধা পিসের ঘাটতি খাতায় বসত। এখন তিন দরজাতেই একই নিয়ম; কেজির পণ্যে ভগ্নাংশ আগের মতোই চলে।
 */
final class AHalfPieceIsNeitherCountedNorAdjustedTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
    }

    public function test_half_a_piece_is_refused_at_the_count_the_adjustment_and_the_give_away(): void
    {
        $piece = $this->stocked('PCS', 'Half probe pcs');
        $reason = ReasonCode::query()->inContext(ReasonCode::STOCK_ADJUSTMENT)->active()->orderBy('id')->firstOrFail();

        $this->assertRefusedWhole(fn () => app(StockCountService::class)->record(['warehouse_id' => $this->warehouse->id],
            [['product_id' => $piece->id, 'counted_qty' => '7.5']]), 'গণনা');
        $this->assertRefusedWhole(fn () => app(StockAdjustmentService::class)->adjust($piece, $this->warehouse, '7.5', $reason), 'সমন্বয়');
        $this->assertRefusedWhole(fn () => app(StockAdjustmentService::class)->issue($piece, $this->warehouse, '0.5', $reason), 'দিয়ে দেওয়া');

        // ⓘ কিছুই বসেনি — তাক ১০-এই
        $this->assertSame(0, bccomp(app(StockService::class)->floorQty($piece, $this->warehouse), '10', 4));
    }

    public function test_a_kilogram_product_still_counts_and_adjusts_in_fractions(): void
    {
        $sugar = $this->stocked('KG', 'Half probe sugar');
        $reason = ReasonCode::query()->inContext(ReasonCode::STOCK_ADJUSTMENT)->active()->orderBy('id')->firstOrFail();

        $line = app(StockCountService::class)->record(['warehouse_id' => $this->warehouse->id],
            [['product_id' => $sugar->id, 'counted_qty' => '9.75']])->lines->first();
        $this->assertSame(0, bccomp((string) $line->difference, '-0.25', 4), '⛔ কেজির গণনায় ভগ্নাংশ আটকাল।');

        app(StockAdjustmentService::class)->issue($sugar, $this->warehouse, '0.5', $reason);
        $this->assertSame(0, bccomp(app(StockService::class)->floorQty($sugar, $this->warehouse), '9.5', 4), '⛔ আধা কেজি দেওয়া গেল না।');
    }

    private function assertRefusedWhole(callable $door, string $where): void
    {
        try {
            $door();
            $this->fail("⛔ {$where}-এ আধা পিস চলল।");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('qty', $e->errors(), "⛔ {$where} থামল, কিন্তু আস্ত-এককের কারণে নয়: ".implode(' ', $e->validator->errors()->all()));
        }
    }

    private function stocked(string $unit, string $name): Product
    {
        $product = Product::query()->create(['code' => 'HP-'.mb_substr(md5($name.microtime()), 0, 8), 'name_en' => $name, 'name_bn' => $name,
            'unit_id' => Unit::query()->where('code', $unit)->firstOrFail()->id, 'is_active' => true]);
        app(StockService::class)->move(product: $product, warehouse: $this->warehouse, sourceType: 'test.opening', sourceId: $product->id, floor: '10');
        app(CostLayerService::class)->receive(product: $product, qty: '10', unitCost: '10', sourceType: 'test.opening', sourceId: $product->id);

        return $product;
    }
}
