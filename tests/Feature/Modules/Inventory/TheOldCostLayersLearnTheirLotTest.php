<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\CostLayer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\Inventory\Services\ProductService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * পুরনো খরচের স্তর তার লট শেখে — কেবল যেখানে নিশ্চিত (`abos:cost-layer-lots`, চূড়ান্ত অডিট ৩০ সেপ্টেম্বর ২০২৬)।
 *
 *   এক কাগজে এক লট                      → বসে
 *   খোলা মজুদের মতো উৎস (স্তরের উৎস = চলাচলের আইডি) → বসে
 *   এক কাগজে কয়েক লট, পরিমাণ হুবহু মেলে    → প্রতিটা নিজের লটে
 *   এক কাগজে কয়েক লট, মেলে না              → ⛔ ছোঁয়া হয় না (দ্ব্যর্থ)
 *   `--force` ছাড়া                          → কিছুই লেখে না, কিন্তু একই সংখ্যা বলে
 *   শেষে লট ধরে স্তর বনাম তাক — ফারাক ছাপা হয় (সমন্বয়কের শর্ত)
 */
final class TheOldCostLayersLearnTheirLotTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->product = app(ProductService::class)->create([
            'name_en' => 'Old Layer Proof Oil',
            'name_bn' => 'পুরনো স্তর প্রমাণ তেল',
            'unit_id' => Unit::query()->where('code', 'PCS')->value('id'),
            'purchase_price' => '100',
            'sale_price' => '150',
        ]);
        $this->product->forceFill(['track_batch' => true])->save();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
    }

    public function test_only_the_certain_layers_learn_their_lot_and_a_dry_run_writes_nothing(): void
    {
        $single = $this->lot('L-SINGLE');
        $this->arrive('test_in', 1, '10', $single);
        $one = $this->layer('test_in', 1, '10');

        $opening = $this->lot('L-OPEN');
        $movement = $this->arrive('opening', (int) $this->product->id, '7', $opening);
        $open = $this->layer('opening', (int) $movement->id, '7');

        [$four, $six] = [$this->lot('L-FOUR'), $this->lot('L-SIX')];
        $this->arrive('test_in', 3, '4', $four);
        $this->arrive('test_in', 3, '6', $six);
        $exact4 = $this->layer('test_in', 3, '4');
        $exact6 = $this->layer('test_in', 3, '6');

        $this->arrive('test_in', 4, '5', $this->lot('L-A'));
        $this->arrive('test_in', 4, '5', $this->lot('L-B'));
        $muddled = $this->layer('test_in', 4, '10');

        $this->artisan('abos:cost-layer-lots', ['--company' => CompanyContext::id()])
            ->expectsOutputToContain('বসত 4 · দ্ব্যর্থ (ছোঁয়া হয়নি) 1')
            ->assertSuccessful();

        $this->assertNull($one->fresh()->batch_id, '⛔ --force ছাড়াই লিখেছে।');

        $this->artisan('abos:cost-layer-lots', ['--company' => CompanyContext::id(), '--force' => true])
            ->expectsOutputToContain('বসল 4 · দ্ব্যর্থ (ছোঁয়া হয়নি) 1')
            ->assertSuccessful();

        $this->assertSame($single->id, $one->fresh()->batch_id);
        $this->assertSame($opening->id, $open->fresh()->batch_id, 'খোলা মজুদের স্তর (উৎস = চলাচলের আইডি) লট পায়নি।');
        $this->assertSame($four->id, $exact4->fresh()->batch_id);
        $this->assertSame($six->id, $exact6->fresh()->batch_id);
        $this->assertNull($muddled->fresh()->batch_id, '⛔ দ্ব্যর্থ স্তরে আন্দাজে লট বসেছে।');
    }

    public function test_the_report_shows_where_a_lots_layers_and_its_shelf_differ(): void
    {
        $lot = $this->lot('L-GAP');
        $this->arrive('test_in', 1, '10', $lot);
        $this->layer('test_in', 1, '10');

        // ⓘ অতীতের বিক্রি: তাক থেকে এই লটের ৩ গেল, কিন্তু খরচ অন্য স্তর থেকে টানা — স্তরে ১০-ই বাকি
        app(StockService::class)->move(
            product: $this->product, warehouse: $this->warehouse, sourceType: 'test_out', sourceId: 1,
            floor: '-3', date: now()->toDateString(), documentNo: 'OUT-1', batch: $lot,
        );

        // ⓘ উল্টো ফারাক: তাকে ৫, স্তরে ২ — এই লটের ৩টা বেচলে FIFO-তে পড়বে
        $short = $this->lot('L-SHORT');
        $this->arrive('test_in', 2, '5', $short);
        $this->layer('test_in', 2, '2');

        $this->artisan('abos:cost-layer-lots', ['--company' => CompanyContext::id()])
            ->expectsOutputToContain('তাকের মাল: 2 টা লট')
            ->expectsOutputToContain('L-GAP  Old Layer Proof Oil  স্তর 10.0000  তাক 7.0000')
            ->expectsOutputToContain('বেচলে FIFO-তে পড়বে): 1 টা লট, মোট 3.0000 একক')
            ->assertSuccessful();
    }

    private function lot(string $no): Batch
    {
        return Batch::query()->create(['company_id' => CompanyContext::id(), 'product_id' => $this->product->id, 'batch_no' => $no]);
    }

    private function arrive(string $sourceType, int $sourceId, string $qty, Batch $lot)
    {
        return app(StockService::class)->move(
            product: $this->product, warehouse: $this->warehouse, sourceType: $sourceType, sourceId: $sourceId,
            floor: $qty, date: now()->toDateString(), documentNo: 'IN-'.$sourceId, batch: $lot,
        );
    }

    /** ⓘ পুরনো ধাঁচের স্তর — লট ছাড়া, যেমন আজকের আগে জন্মাত */
    private function layer(string $sourceType, int $sourceId, string $qty): CostLayer
    {
        return app(CostLayerService::class)->receive($this->product, $qty, '100', $sourceType, $sourceId, 'IN-'.$sourceId);
    }
}
