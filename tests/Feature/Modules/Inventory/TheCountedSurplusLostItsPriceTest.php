<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\CostLayer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockCount;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * গণনায় পাওয়া বাড়তি মাল তার দর হারাত — Inventory অডিট ম১, ৫ অক্টোবর ২০২৬।
 *
 * ⛔ বাড়তির দর লিখলেও ফেলে দেওয়া হত: গণনার কাগজ সবসময় গড় দর বসাত, আর হাতের সমন্বয়-পর্দা লেখা দরটা কাগজে পাঠাতই না।
 * ফল: স্তর না থাকলে (নতুন পণ্য, পুরনো মাল) বাড়তি খাতায় তোলাই যেত না ("দর লাগবে"), আর থাকলে মানুষের দরের বদলে গড় বসত।
 * গণনার পাতায় লট আর দরের ঘরই ছিল না — লট-ধরা পণ্যে বাড়তি পেলে পুরো গণনা আটকে যেত।
 * ⭐ এখন লেখা দরই বসে (খালি হলে আগের মতো গড়), আর পাতায় লট-ধরা পণ্যের সারিতে লট আর মেয়াদ, প্রতিটা সারিতে দর।
 */
final class TheCountedSurplusLostItsPriceTest extends TestCase
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

    /** ⭐ স্তর নেই এমন পণ্যে বাড়তি — লেখা দরে খাতায় ওঠে */
    public function test_a_surplus_with_no_cost_layer_goes_in_at_the_rate_typed(): void
    {
        $product = $this->product(tracksLots: false);

        $this->countAndApprove($product, ['counted_qty' => '5', 'unit_cost' => '40']);

        $this->assertSame('5', $this->floor($product), '⛔ বাড়তি ৫টা খাতায় ওঠেনি।');
        $this->assertSame(['40.0000'], $this->layerRates($product), '⛔ বাড়তির স্তর লেখা দর ৪০-এ বসেনি।');
    }

    /** ⭐ লেখা দর গড়ের উপরে — গড় ১০, লেখা ২৫: বাড়তি ২টা ২৫-এ */
    public function test_the_rate_typed_beats_the_average(): void
    {
        $product = $this->product(tracksLots: false);
        app(StockService::class)->move(product: $product, warehouse: $this->warehouse, sourceType: 'test.opening', sourceId: $product->id, floor: '10');
        app(CostLayerService::class)->receive(product: $product, qty: '10', unitCost: '10', sourceType: 'test.opening', sourceId: $product->id);

        $this->countAndApprove($product, ['counted_qty' => '12', 'unit_cost' => '25']);

        $this->assertSame(['10.0000', '25.0000'], $this->layerRates($product), '⛔ লেখা দর ফেলে গড় বসেছে।');
    }

    /** ⭐ হাতের সমন্বয়-পর্দাও লেখা দর কাগজে পাঠায় */
    public function test_the_adjustment_screen_sends_its_rate(): void
    {
        $product = $this->product(tracksLots: false);

        $this->post(route('inventory.stock.adjust.store'), [
            'product_id' => $product->id, 'warehouse_id' => $this->warehouse->id, 'reason_code_id' => $this->reason()->id,
            'counted' => '5', 'unit_cost' => '40', 'trx_date' => now()->toDateString(),
        ])->assertSessionHasNoErrors();

        $this->assertSame('5', $this->floor($product));
        $this->assertSame(['40.0000'], $this->layerRates($product));
    }

    /** ⭐ গণনার পাতায় লট-ধরা পণ্যের সারিতে লট আর মেয়াদ, প্রতিটা সারিতে দর; লট-ধরা বাড়তি পাতা থেকেই খাতায় ওঠে */
    public function test_the_count_sheet_takes_a_lot_and_a_rate(): void
    {
        $product = $this->product(tracksLots: true);
        app(StockService::class)->move(product: $product, warehouse: $this->warehouse, sourceType: 'test.opening', sourceId: $product->id, floor: '1');

        $page = $this->get(route('inventory.count.create', ['warehouse' => $this->warehouse->id]))->assertOk();
        $html = $page->getContent();
        $i = array_search($product->id, collect($page->viewData('products'))->pluck('id')->all(), true);
        $this->assertNotFalse($i, 'প্রস্তুতিটাই ভুল — পণ্যটা শিটে নেই।');

        foreach (['batch_no', 'expiry_date', 'unit_cost'] as $field) {
            $this->assertStringContainsString("lines[{$i}][{$field}]", $html, "⛔ গণনার পাতায় {$field}-এর ঘর নেই।");
        }

        // ⓘ গোনা হয় লট ধরে: নতুন লট CNT-LOT-1-এর খাতা ০, গোনা ৪ — সেই লটে ৪টা বাড়তি; আগের লটহীন ১টা অক্ষত
        $this->countAndApprove($product, ['counted_qty' => '4', 'unit_cost' => '30', 'batch_no' => 'CNT-LOT-1', 'expiry_date' => now()->addYear()->toDateString()]);
        $lot = \App\Modules\Inventory\Models\Batch::query()->where('product_id', $product->id)->where('batch_no', 'CNT-LOT-1')->first();
        $this->assertNotNull($lot, '⛔ গণনার পাতার লট নম্বরে লট জন্মায়নি।');
        $this->assertSame(0, bccomp('4', $lot->floorBalance($this->warehouse), 4), '⛔ লট-ধরা বাড়তি লটে ওঠেনি।');
        $this->assertSame('5', $this->floor($product));
    }

    /** ⛔ ঋণাত্মক দর কাগজে বসে না — পর্দার যাচাই ছাড়া সরাসরি ডাকলেও (ফোন, আমদানি) */
    public function test_a_negative_rate_is_refused_even_without_the_screen(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(\App\Modules\Inventory\Services\StockCountService::class)->record(
            ['warehouse_id' => $this->warehouse->id],
            [['product_id' => $this->product(tracksLots: false)->id, 'counted_qty' => '5', 'unit_cost' => '-5']],
        );
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /** @param  array<string, string>  $line */
    private function countAndApprove(Product $product, array $line): void
    {
        $this->post(route('inventory.count.store'), [
            'warehouse_id' => $this->warehouse->id, 'count_date' => now()->toDateString(),
            'lines' => [['product_id' => $product->id, ...$line]],
        ])->assertSessionHasNoErrors();

        $count = StockCount::query()->latest('id')->firstOrFail();
        $this->post(route('inventory.count.approve', $count), ['reason_code_id' => $this->reason()->id])->assertSessionHasNoErrors();
    }

    private function product(bool $tracksLots): Product
    {
        return Product::query()->create([
            'code' => 'CNT-'.mb_substr(md5(microtime()), 0, 8), 'name_en' => 'Count probe', 'name_bn' => 'গণনার নমুনা',
            'unit_id' => Unit::query()->orderBy('id')->firstOrFail()->id, 'is_active' => true, 'track_batch' => $tracksLots,
        ]);
    }

    private function floor(Product $product): string
    {
        $sum = (string) StockMovement::query()->where('product_id', $product->id)->where('warehouse_id', $this->warehouse->id)->sum('floor_change');

        return rtrim(rtrim(bcadd($sum, '0', 4), '0'), '.') ?: '0';
    }

    /** @return list<string> */
    private function layerRates(Product $product): array
    {
        return CostLayer::query()->where('product_id', $product->id)->orderBy('id')->pluck('unit_cost')
            ->map(fn ($c) => bcadd((string) $c, '0', 4))->all();
    }

    private function reason(): ReasonCode
    {
        return ReasonCode::query()->inContext(ReasonCode::STOCK_ADJUSTMENT)->active()->orderBy('id')->firstOrFail();
    }
}
