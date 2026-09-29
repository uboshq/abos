<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\Inventory\Services\StockCountService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * গণনার পার্থক্য বসে গণনার মুহূর্ত ধরে, অনুমোদনের মুহূর্ত ধরে নয় — ২৯ সেপ্টেম্বর ২০২৬ (অডিট)।
 *
 * ── ⛔ সন্দেহ ১ (ক) ───────────────────────────────────────────────────────
 * গণনার সারি খাতার সংখ্যা রাখে গণনার মুহূর্তে (`book_qty`), আর পার্থক্যও
 * (`difference`)। ⚠️ কিন্তু অনুমোদন `adjust(counted_qty)` ডাকে, আর সেটা পার্থক্য
 * আবার মাপে **অনুমোদনের মুহূর্তের** তাক ধরে। মাঝে যা বিক্রি হলো, তা তখন
 * "উদ্বৃত্ত" হয়ে ফেরে — বিক্রি হয়ে যাওয়া মাল খাতায় আবার ঢোকে।
 *
 * ── ⛔ সন্দেহ ২ (খ) ───────────────────────────────────────────────────────
 * লটওয়ালা পণ্যে গণনার সারি লট ধরে গোনা হয়, অথচ `book_qty` পুরো পণ্যের; আর
 * ঘাটতি বেরোয় লট না বলে (পুরনোটা আগে)। ⚠️ তাহলে লট A-র ঘাটতি লট B থেকে কাটত।
 */
class TheCountWasSettledAtTheWrongMomentTest extends TestCase
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

    /**
     * (ক) তাকে ১০, গোনা হলো ৮ (−২), মাঝে ৩ বিক্রি → অনুমোদনের পরে তাকে ৫, আর সমন্বয় −২।
     */
    public function test_a_sale_between_counting_and_approval_is_not_counted_back_as_surplus(): void
    {
        $product = $this->product(tracksLots: false);
        $this->stockUp($product, '10');

        $count = app(StockCountService::class)->record(
            ['warehouse_id' => $this->warehouse->id],
            [['product_id' => $product->id, 'counted_qty' => '8']],
        );

        $this->assertSame(0, bccomp((string) $count->lines->first()->difference, '-2', 4),
            'প্রস্তুতিটাই ভুল — গণনার পার্থক্য −২ নয়।');

        // ⓘ গণনার পরে, অনুমোদনের আগে — কাউন্টারে ৩টা বিক্রি
        app(StockService::class)->issue(
            product: $product, warehouse: $this->warehouse,
            sourceType: 'test.sale', sourceId: 1, qty: '3',
        );

        app(StockCountService::class)->approve($count->fresh(), $this->reason());

        $this->assertSame(0, bccomp(app(StockService::class)->floorQty($product, $this->warehouse), '5', 4),
            '⛔ অনুমোদনের পরে তাকে ১০ − ৩ − ২ = ৫ থাকার কথা, আছে '
            .app(StockService::class)->floorQty($product, $this->warehouse).' — বিক্রি হওয়া মাল আবার খাতায় ঢুকেছে।');
    }

    /**
     * (খ) লট A-তে ৫, লট B-তে ৫; কেবল লট A গোনা হলো ৩ → A = ৩, B = ৫, মোট ৮।
     */
    public function test_a_shortage_in_one_lot_is_taken_from_that_lot(): void
    {
        $product = $this->product(tracksLots: true);
        $lotA = $this->lot($product, 'LOT-A', now()->addMonths(6)->toDateString());
        $lotB = $this->lot($product, 'LOT-B', now()->addMonths(3)->toDateString());
        $this->stockUp($product, '5', $lotA);
        $this->stockUp($product, '5', $lotB);

        $count = app(StockCountService::class)->record(
            ['warehouse_id' => $this->warehouse->id],
            [['product_id' => $product->id, 'batch_no' => 'LOT-A', 'counted_qty' => '3']],
        );

        app(StockCountService::class)->approve($count->fresh(), $this->reason());

        $this->assertSame(0, bccomp($this->lotFloor($lotA), '3', 4),
            '⛔ লট A-তে ৩ থাকার কথা (গোনা হয়েছে ৩), আছে '.$this->lotFloor($lotA).'।');
        $this->assertSame(0, bccomp($this->lotFloor($lotB), '5', 4),
            '⛔ লট B কেউ গোনেনি, তবু বদলেছে — আছে '.$this->lotFloor($lotB).', থাকার কথা ৫।');
        $this->assertSame(0, bccomp(app(StockService::class)->floorQty($product, $this->warehouse), '8', 4),
            '⛔ পণ্যের মোট ৮ থাকার কথা।');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function product(bool $tracksLots): Product
    {
        return Product::query()->create([
            'code' => 'CNT-'.mb_substr(md5(microtime()), 0, 8),
            'name_en' => 'Count probe',
            'name_bn' => 'গণনার নমুনা',
            'unit_id' => Unit::query()->orderBy('id')->firstOrFail()->id,
            'is_active' => true,
            'track_batch' => $tracksLots,
        ]);
    }

    private function lot(Product $product, string $no, string $expiry): Batch
    {
        return Batch::query()->create(['product_id' => $product->id, 'batch_no' => $no, 'expiry_date' => $expiry]);
    }

    private function stockUp(Product $product, string $qty, ?Batch $batch = null): void
    {
        app(StockService::class)->move(
            product: $product, warehouse: $this->warehouse,
            sourceType: 'test.opening', sourceId: $product->id, floor: $qty, batch: $batch,
        );

        app(CostLayerService::class)->receive(
            product: $product, qty: $qty, unitCost: '10.00',
            sourceType: 'test.opening', sourceId: $product->id,
        );
    }

    private function lotFloor(Batch $batch): string
    {
        return (string) StockMovement::query()
            ->where('batch_id', $batch->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->sum('floor_change');
    }

    private function reason(): ReasonCode
    {
        return ReasonCode::query()->inContext(ReasonCode::STOCK_ADJUSTMENT)->active()->orderBy('id')->firstOrFail();
    }
}
