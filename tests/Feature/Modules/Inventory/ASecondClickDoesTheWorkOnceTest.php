<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\QualityInspection;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\Inventory\Services\QualityInspectionService;
use App\Modules\Inventory\Services\StockCountService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * দ্বিতীয় ক্লিক কাজটা আর করে না — চূড়ান্ত অডিট ⛔১৩, ৩০ সেপ্টেম্বর ২০২৬।
 *
 * ── ⛔ কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * গোনা-অনুমোদন ([[StockCountService::approve()]]) আর মান-পরীক্ষার রায় ([[QualityInspectionService::decide()]])
 * কাগজের অবস্থা দেখত **লেনদেনের বাইরে**, হাতে ধরা মডেল থেকে। দুইবার চাপ দিলে দুইটা অনুরোধই "খসড়া/অপেক্ষমাণ"
 * দেখত — গোনার প্রতিটা পার্থক্য দুইবার সমন্বয় হয়ে খতিয়ানে দুইবার উঠত, আর রায়ের মাল দুইবার আটকাত।
 * ⓘ [[StockTransferService]]-এ এই ফাঁক ২৯ সেপ্টেম্বরে বন্ধ হয়েছিল (সারি আটকে অবস্থা আবার পড়া); এখানে ছিল না।
 *
 * ── ⓘ দুইবার চাপ কীভাবে বানানো ─────────────────────────────────────────
 * একই কাগজের **দুইটা আলাদা মডেল**, দুটোই খসড়া অবস্থায় পড়া — ঠিক যেমন দুইটা অনুরোধ একই মুহূর্তে পড়ে। প্রথমটা
 * কাজ করে; দ্বিতীয়টা পুরনো মডেল হাতে আসে, আর তাকে থামতে হবে লেনদেনের ভেতরের তালা-পড়া দেখে।
 */
final class ASecondClickDoesTheWorkOnceTest extends TestCase
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

    public function test_approving_a_count_twice_settles_it_once(): void
    {
        $product = $this->product();
        $this->stockUp($product, '10');

        $count = app(StockCountService::class)->record(
            ['warehouse_id' => $this->warehouse->id],
            [['product_id' => $product->id, 'counted_qty' => '8']],
        );

        // ⓘ দুইটা অনুরোধ, একই কাগজ — দুটোই খসড়া দেখে
        $first = $count->fresh();
        $second = $count->fresh();

        app(StockCountService::class)->approve($first, $this->reason());

        try {
            app(StockCountService::class)->approve($second, $this->reason());
            $this->fail('দ্বিতীয় অনুমোদন থামেনি — গোনার পার্থক্য দুইবার সমন্বয় হয়েছে।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }

        $this->assertSame(0, bccomp(app(StockService::class)->floorQty($product, $this->warehouse), '8', 4),
            '⛔ তাকে ১০ − ২ = ৮ থাকার কথা, আছে '.app(StockService::class)->floorQty($product, $this->warehouse)
            .' — ঘাটতি দুইবার কাটা হয়েছে।');
    }

    public function test_deciding_an_inspection_twice_holds_the_goods_once(): void
    {
        $product = $this->product();
        $this->stockUp($product, '100');

        $service = app(QualityInspectionService::class);
        $paper = $service->open([
            'product_id' => $product->id,
            'warehouse_id' => $this->warehouse->id,
            'inspected_qty' => '10',
        ]);

        $first = $paper->fresh();
        $second = $paper->fresh();

        $service->decide(inspection: $first, result: QualityInspection::REJECTED, acceptedQty: '0', rejectedQty: '10');

        try {
            $service->decide(inspection: $second, result: QualityInspection::REJECTED, acceptedQty: '0', rejectedQty: '10');
            $this->fail('দ্বিতীয় রায় থামেনি — মাল দুইবার আটকেছে।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }

        $this->assertSame(0, bccomp(app(StockService::class)->holdQty($product, $this->warehouse), '10', 4),
            '⛔ আটকানো থাকার কথা ১০, আছে '.app(StockService::class)->holdQty($product, $this->warehouse).'।');
    }

    /**
     * ⓘ বিনাশ অংশে অংশে চলে ([[TheRejectedGoodsHadNowhereToGoTest]]), তাই দ্বিতীয় ডাক নিজে ভুল নয় — ভুল হলো দুইটা
     * একসাথে এসে দুজনেই "১০ আটকানো আছে" পড়া: সীমাটা আসে কাগজের নিজের `disposed_qty` থেকে, আর পুরনো মডেলে সেটা ০।
     * ⛔ তাই দুইবার পুরো ১০ বিনাশ হত — তাক থেকে ২০ কমত, অথচ কাগজ আটকেছিল ১০।
     */
    public function test_disposing_the_whole_hold_twice_disposes_it_once(): void
    {
        $product = $this->product();
        $this->stockUp($product, '100');

        $service = app(QualityInspectionService::class);
        $paper = $service->open([
            'product_id' => $product->id,
            'warehouse_id' => $this->warehouse->id,
            'inspected_qty' => '10',
        ]);
        $service->decide(inspection: $paper, result: QualityInspection::REJECTED, acceptedQty: '0', rejectedQty: '10');

        $first = $paper->fresh();
        $second = $paper->fresh();

        $service->dispose(inspection: $first, qty: '10', writeOff: $this->reason());

        try {
            $service->dispose(inspection: $second, qty: '10', writeOff: $this->reason());
            $this->fail('দ্বিতীয় বিনাশ থামেনি — কাগজ যা আটকায়নি তাও বিনাশ হয়েছে।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('qty', $e->errors());
        }

        $this->assertSame(0, bccomp(app(StockService::class)->floorQty($product, $this->warehouse), '90', 4),
            '⛔ তাকে ১০০ − ১০ = ৯০ থাকার কথা, আছে '.app(StockService::class)->floorQty($product, $this->warehouse).'।');
        $this->assertSame(0, bccomp((string) $paper->fresh()->disposed_qty, '10', 4), 'কাগজে বিনাশ ১০-এর বেশি লেখা হয়েছে।');
    }

    /**
     * ⚠️ ওপরের দাবিটা সারাই ছাড়াও সবুজ ছিল: পরপর চালালে দ্বিতীয় বিনাশ থামে [[StockService::release()]]-এর
     * পাহারায় — কিন্তু সে আটকানো মাল গোনে **তালা ছাড়া**, তাই দুইটা একসাথে এলে দুজনেই "১০ আটকানো" পড়ত।
     * ⓘ একটা প্রক্রিয়ায় সেই দৌড় বানানো যায় না; তাই এই দাবি সরাসরি মাপে যে বিনাশের সময় কাগজের সারিতে
     * `FOR UPDATE` তালা পড়ে — দ্বিতীয়টা তখন অপেক্ষা করে, তারপর নতুন `disposed_qty` দেখে ফিরে যায়।
     */
    public function test_disposal_locks_the_paper_before_it_counts_the_hold(): void
    {
        $product = $this->product();
        $this->stockUp($product, '100');

        $service = app(QualityInspectionService::class);
        $paper = $service->open([
            'product_id' => $product->id,
            'warehouse_id' => $this->warehouse->id,
            'inspected_qty' => '10',
        ]);
        $service->decide(inspection: $paper, result: QualityInspection::REJECTED, acceptedQty: '0', rejectedQty: '10');

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = strtolower($query->sql);
        });

        $service->dispose(inspection: $paper->fresh(), qty: '4', writeOff: $this->reason());

        $locked = array_filter($queries, fn (string $sql) => str_contains($sql, 'inv_quality_inspections') && str_contains($sql, 'for update'));

        $this->assertNotEmpty($locked, 'বিনাশের সময় কাগজের সারিতে তালা পড়েনি — দুইটা বিনাশ একসাথে পুরোটা নিতে পারে।');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function product(): Product
    {
        return Product::query()->create([
            'code' => 'TWICE-'.mb_substr(md5(microtime()), 0, 8),
            'name_en' => 'Twice probe',
            'name_bn' => 'দুইবারের নমুনা',
            'unit_id' => Unit::query()->orderBy('id')->firstOrFail()->id,
            'is_active' => true,
            'track_batch' => false,
        ]);
    }

    private function stockUp(Product $product, string $qty): void
    {
        app(StockService::class)->move(
            product: $product, warehouse: $this->warehouse,
            sourceType: 'test.opening', sourceId: $product->id, floor: $qty,
        );

        app(CostLayerService::class)->receive(
            product: $product, qty: $qty, unitCost: '10.00',
            sourceType: 'test.opening', sourceId: $product->id,
        );
    }

    private function reason(): ReasonCode
    {
        return ReasonCode::query()->inContext(ReasonCode::STOCK_ADJUSTMENT)->active()->orderBy('id')->firstOrFail();
    }
}
