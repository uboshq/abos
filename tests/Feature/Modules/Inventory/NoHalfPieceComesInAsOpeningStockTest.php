<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\OpeningStockService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⛔ খোলা মজুদে "২.৫ পিস" ঢুকত (পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬, মজুদ ⚠️১২ — এই দরজা 69da3114-এ বাদ পড়েছিল)।
 *
 * ⓘ কাগজের লাইন, গণনা আর সমন্বয় পিস-বাক্সে আধা আটকায়, কেজি-লিটারে চলতে দেয় (মালিক, ৬ অক্টোবর ২০২৬); নতুন খোলা মজুদ —
 * এক সারি আর কার্ট — এখন একই নিয়মে। ⓘ পুরনো সারির সংশোধন নয়: নিয়মের আগে বসা ভগ্নাংশের সারি আটকে থাকলে কেউ তা ঠিক করতে পারত না।
 */
final class NoHalfPieceComesInAsOpeningStockTest extends TestCase
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
        app(StandardChart::class)->install();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
    }

    public function test_new_opening_stock_refuses_half_a_piece_and_takes_half_a_kilo(): void
    {
        $opening = app(OpeningStockService::class);
        $piece = $this->product('PCS');

        $this->assertRefused(fn () => $opening->bringIn($piece, $this->warehouse, '2.5', '100'), 'qty', 'এক সারির খোলা মজুদ');
        $this->assertRefused(fn () => $opening->bringInMany($this->warehouse, [['product_id' => $piece->id, 'qty' => '3', 'unit_cost' => '100', 'free_qty' => '0.5']]),
            'rows.0.qty', 'কার্টের আধা ফ্রি');
        $this->assertSame(0, bccomp(app(StockService::class)->floorQty($piece, $this->warehouse), '0', 4), '⛔ আটকানোর পরেও কিছু বসল।');

        $opening->bringIn($this->product('KG'), $this->warehouse, '2.5', '100');
    }

    public function test_an_old_fractional_row_can_still_be_corrected(): void
    {
        // ⓘ নিয়মের আগের সারি — একক তখন ভগ্নাংশ নিত, পরে বন্ধ হলো
        $unit = Unit::query()->create(['code' => 'W12OLD', 'name_en' => 'Old piece', 'name_bn' => 'পুরনো পিস', 'factor' => '1',
            'allows_fraction' => true, 'is_active' => true]);
        $product = Product::query()->create(['code' => 'W12-'.mb_substr(md5(microtime()), 0, 8), 'name_en' => 'Old row', 'name_bn' => 'পুরনো সারি',
            'unit_id' => $unit->id, 'is_active' => true]);
        $movement = app(OpeningStockService::class)->bringIn($product, $this->warehouse, '2.5', '100');
        $unit->forceFill(['allows_fraction' => false])->save();

        app(OpeningStockService::class)->correct($movement->fresh(), ['qty' => '2.5', 'unit_cost' => '120']);

        $this->assertSame(0, bccomp(app(StockService::class)->floorQty($product, $this->warehouse), '2.5', 4), '⛔ পুরনো ভগ্নাংশের সারি সংশোধনে আটকে গেল।');
    }

    private function assertRefused(callable $door, string $key, string $where): void
    {
        try {
            $door();
            $this->fail("⛔ {$where}-এ আধা পিস ঢুকল।");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($key, $e->errors(), "⛔ {$where} থামল, কিন্তু অন্য কারণে: ".implode(' ', $e->validator->errors()->all()));
        }
    }

    private function product(string $unit): Product
    {
        return Product::query()->create(['code' => 'W12-'.$unit.mb_substr(md5(microtime()), 0, 6), 'name_en' => 'Opening '.$unit,
            'name_bn' => 'খোলা '.$unit, 'unit_id' => Unit::query()->where('code', $unit)->firstOrFail()->id, 'is_active' => true]);
    }
}
