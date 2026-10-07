<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\QualityInspection;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\QualityInspectionService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * অনুমোদিত লটের বাতিল মাল বিক্রিতে থেকে যেত — Inventory অডিট ম৫, ৫ অক্টোবর ২০২৬।
 *
 * ⛔ (ক) রায় "অনুমোদিত" হলে কিছুই আটকাত না, সাথে বাতিল পরিমাণ লেখা থাকলেও — ৩০-এর ৫টা খারাপ, অথচ ৫টাই বিক্রয়যোগ্য;
 * (খ) গুদাম ছাড়া পরিদর্শনে কোনো রায়েই কিছু আটকাত না, আর কাগজ বলত "বাতিল"।
 * ⭐ এখন বাতিল অংশ সব রায়ে আটকায়, আর আটকানোর মতো কিছু থাকলে গুদাম ছাড়া রায় থামে।
 */
final class TheApprovedLotKeptItsRejectsOnSaleTest extends TestCase
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

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->create([
            'code' => 'M5-'.mb_substr(md5(microtime()), 0, 8), 'name_en' => 'Inspected probe', 'name_bn' => 'পরিদর্শনের নমুনা',
            'unit_id' => Unit::query()->orderBy('id')->firstOrFail()->id, 'qc_required' => true, 'is_active' => true,
        ]);
        app(StockService::class)->move(product: $this->product, warehouse: $this->warehouse, sourceType: 'test_opening', sourceId: $this->product->id, floor: '100');
    }

    /** ⛔ (ক) "অনুমোদিত", ২৫ ভালো ৫ খারাপ — খারাপ ৫টা আটকায়, বিক্রয়যোগ্য ৯৫ */
    public function test_an_approved_verdict_still_holds_its_rejected_part(): void
    {
        $paper = $this->openPaper('30', $this->warehouse->id);

        app(QualityInspectionService::class)->decide($paper, QualityInspection::APPROVED, '25', '5');

        $this->assertSame(0, bccomp('5', app(StockService::class)->holdQty($this->product, $this->warehouse), 4),
            '⛔ অনুমোদিত রায়ের বাতিল ৫টা আটকায়নি — খারাপ মাল বিক্রয়যোগ্য।');
        $this->assertSame(0, bccomp('95', app(StockService::class)->availableQty($this->product, $this->warehouse), 4));
    }

    /** ⛔ (খ) গুদাম ছাড়া পরিদর্শনে বাতিল রায় — থামে, কারণ কোথায় আটকাবে জানা নেই */
    public function test_a_verdict_that_must_hold_goods_needs_a_warehouse(): void
    {
        $paper = $this->openPaper('30', null);

        try {
            app(QualityInspectionService::class)->decide($paper, QualityInspection::REJECTED, '25', '5');
            $this->fail('⛔ গুদাম ছাড়া "বাতিল" রায় বসল, অথচ কিছুই আটকায়নি।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('warehouse_id', $e->errors());
        }

        $this->assertTrue($paper->fresh()->isPending(), '⛔ থামা রায়েও কাগজের অবস্থা বদলেছে।');
    }

    /** ⭐ পাহারা সব দরজা বন্ধ করে না: গুদাম ছাড়া পুরো পাশ — কিছু আটকানোর নেই, রায় বসে */
    public function test_a_clean_pass_without_a_warehouse_still_goes(): void
    {
        $paper = $this->openPaper('30', null);

        app(QualityInspectionService::class)->decide($paper, QualityInspection::APPROVED, '30', '0');

        $this->assertSame(QualityInspection::APPROVED, $paper->fresh()->status);
    }

    private function openPaper(string $qty, ?int $warehouseId): QualityInspection
    {
        return app(QualityInspectionService::class)->open(array_filter([
            'product_id' => $this->product->id,
            'warehouse_id' => $warehouseId,
            'inspected_qty' => $qty,
        ], fn ($v) => $v !== null));
    }
}
