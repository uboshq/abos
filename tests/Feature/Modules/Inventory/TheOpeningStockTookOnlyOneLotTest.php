<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\BatchService;
use App\Modules\Inventory\Services\OpeningStockService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * খোলা মজুদ একই পণ্যের দ্বিতীয় লট নিত না — Inventory অডিট ম২৪, ৫ অক্টোবর ২০২৬।
 *
 * ⛔ চালুর দিনে তাকে একই ওষুধের দুই লট থাকলে প্রথমটা বসার পরে দ্বিতীয়টা "আগেই বসানো" বা "দেরি হয়ে গেছে" বলে থামত
 * ([[OpeningStockService::exists()]] আর [[OpeningStockService::stillOpen()]] পণ্য ধরে দেখত, লট নয়)।
 * ⭐ এখন প্রতিটা লট একবার করে বসে; একই লট দুইবার নয়, আর আসল লেনদেন হয়ে গেলে আর কোনো লট নয়।
 */
final class TheOpeningStockTookOnlyOneLotTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_lot_of_a_product_opens_once_until_the_first_real_movement(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $warehouse = Warehouse::query()->orderBy('id')->firstOrFail();
        $product = Product::query()->create(['code' => 'M24', 'name_en' => 'Two-lot syrup', 'name_bn' => 'দুই-লট সিরাপ',
            'unit_id' => Unit::query()->orderBy('id')->firstOrFail()->id, 'is_active' => true, 'track_batch' => true]);
        $lots = app(BatchService::class);
        $a = $lots->receive($product, 'M24-A', Carbon::today()->addMonths(3)->toDateString());
        $b = $lots->receive($product, 'M24-B', Carbon::today()->addMonths(8)->toDateString());
        $c = $lots->receive($product, 'M24-C', Carbon::today()->addMonths(9)->toDateString());
        $opening = app(OpeningStockService::class);

        $opening->bringIn($product, $warehouse, '10', '50', batch: $a);
        $opening->bringIn($product, $warehouse, '6', '55', batch: $b);

        $this->assertSame('6.0000', bcadd((string) StockMovement::query()->where('batch_id', $b->id)->sum('floor_change'), '0', 4),
            '⛔ দ্বিতীয় লট খোলা মজুদে বসল না।');

        $this->assertRefused(fn () => $opening->bringIn($product, $warehouse, '1', '50', batch: $a), 'একই লট দুইবার বসল।');

        // ⓘ আসল লেনদেনের পরে আর নয়
        app(StockService::class)->move(product: $product, warehouse: $warehouse, sourceType: 'test.sale', sourceId: 1, floor: '-1', batch: $a);
        $this->assertRefused(fn () => $opening->bringIn($product, $warehouse, '4', '50', batch: $c), 'লেনদেনের পরেও নতুন লট খোলা মজুদে বসল।');
    }

    private function assertRefused(callable $call, string $why): void
    {
        try {
            $call();
            $this->fail('⛔ '.$why);
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
    }
}
