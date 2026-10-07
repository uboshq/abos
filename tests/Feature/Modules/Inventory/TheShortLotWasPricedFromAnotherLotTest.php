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
use App\Modules\Inventory\Services\StockAdjustmentService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ঘাটতির লটের দাম অন্য লট থেকে ধরা হত — Inventory অডিট ম৭ (গণনা), ৫ অক্টোবর ২০২৬।
 *
 * ⛔ গণনার ঘাটতিতে মাল বেরোত ঠিক লট থেকে, কিন্তু খরচ টানা হত লট না দেখে, পুরনো স্তর আগে: লট B (৩০ টাকা) কম পাওয়া গেল,
 * অথচ খরচ উঠল লট A-র ১০ টাকার স্তর থেকে — মোট মজুদের টাকা ঠিক থাকত, কিন্তু লট A-র স্তর খালি হত আর লট B-র রয়ে যেত,
 * আর পরে লট A বিক্রির লাভ ভুল দেখাত।
 * ⭐ এখন প্রতিটা বেরোনো চলাচলের খরচ তার নিজের লটের স্তর থেকে ([[CostLayerService::issue()]] `batch`)।
 */
final class TheShortLotWasPricedFromAnotherLotTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->create([
            'code' => 'M7-'.mb_substr(md5(microtime()), 0, 8), 'name_en' => 'Lot cost probe', 'name_bn' => 'লট-খরচের নমুনা',
            'unit_id' => Unit::query()->orderBy('id')->firstOrFail()->id, 'is_active' => true, 'track_batch' => true,
        ]);
    }

    /** ⭐ গোনা লট B-তে ২টা কম — খরচ লট B-র ৩০ টাকায়, লট A-র স্তর অক্ষত */
    public function test_a_counted_lots_shortage_is_priced_from_that_lot(): void
    {
        // ⓘ লট A-র স্তর আগে (পুরনো) — লট না দেখা FIFO হলে এটাই খেত
        $a = $this->lot('LOT-A', now()->addMonths(2)->toDateString(), '10', '10');
        $b = $this->lot('LOT-B', now()->addMonths(6)->toDateString(), '10', '30');

        app(StockAdjustmentService::class)->settle($this->product, $this->warehouse, '-2', $this->reason(), batch: $b);

        $this->assertSame('10', $this->left($a), '⛔ লট A-র স্তর থেকে ঘাটতির খরচ উঠেছে — লট B কম পাওয়া গেছে।');
        $this->assertSame('8', $this->left($b), '⛔ লট B-র স্তর থেকে খরচ ওঠেনি।');
    }

    /** ⭐ লট না বলা ঘাটতি আগে-মেয়াদ ক্রমে কয়েক লট জুড়ে — প্রতিটা অংশের খরচ তার লট থেকে */
    public function test_a_shortage_across_lots_prices_each_part_from_its_own_lot(): void
    {
        // ⓘ লট B-র স্তর পুরনো, কিন্তু লট A আগে মেয়াদের — মাল বেরোয় A আগে, খরচও A আগে হওয়া চাই
        $b = $this->lot('LOT-B', now()->addMonths(6)->toDateString(), '10', '30');
        $a = $this->lot('LOT-A', now()->addMonths(2)->toDateString(), '3', '10');

        app(StockAdjustmentService::class)->settle($this->product, $this->warehouse, '-5', $this->reason());

        $this->assertSame('0', $this->left($a), '⛔ লট A-র ৩টা বেরোল, অথচ তার স্তর অক্ষত।');
        $this->assertSame('8', $this->left($b), '⛔ লট B-র ২টার বদলে অন্য কিছু খরচে উঠেছে।');
    }

    private function lot(string $no, string $expiry, string $qty, string $cost): Batch
    {
        $batch = Batch::query()->create(['product_id' => $this->product->id, 'batch_no' => $no, 'expiry_date' => $expiry]);
        app(StockService::class)->move(product: $this->product, warehouse: $this->warehouse, sourceType: 'test.opening', sourceId: $batch->id, floor: $qty, batch: $batch);
        app(CostLayerService::class)->receive(product: $this->product, qty: $qty, unitCost: $cost, sourceType: 'test.opening', sourceId: $batch->id, batch: $batch);

        return $batch;
    }

    private function left(Batch $batch): string
    {
        $sum = (string) CostLayer::query()->where('batch_id', $batch->id)->sum('qty_remaining');

        return rtrim(rtrim(bcadd($sum, '0', 4), '0'), '.') ?: '0';
    }

    private function reason(): ReasonCode
    {
        return ReasonCode::query()->inContext(ReasonCode::STOCK_ADJUSTMENT)->active()->orderBy('id')->firstOrFail();
    }
}
