<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\QualityInspection;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\QualityInspectionService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "অনুমোদিত" রায়ের বাতিল অংশ ফেরার কারণে আটকাত — মজুদ লাইন-ধরে নিরীক্ষা, ১৩ অক্টোবর ২০২৬।
 *
 * ⛔ ৩০টার ৫টা খারাপ, রায় "অনুমোদিত": খারাপ ৫টা আটকাত `HOLD-RET`-এ — ফেরার কারণ। ফলে সাধারণ "ছাড়ো" পর্দা থেকে
 * ছেড়ে কাউন্টারে বেচা যেত, আর বিনাশের দরজা "অনুমোদিত" কাগজ ফিরিয়ে দিত — খারাপ মাল নষ্ট হিসেবে খাতা থেকে যাওয়ার
 * কোনো পথ ছিল না। সেবার নিজের কথাই ("বাতিলের মাল ফেরে না") তখন মিথ্যা।
 * ⭐ এখন বাতিল অংশ `HOLD-REJ`-এ, আর অনুমোদিত কাগজেও তার বিনাশ হয়।
 */
final class TheApprovedLotsRejectsCouldBeReleasedForSaleTest extends TestCase
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
            'code' => 'QCR-'.mb_substr(md5(microtime()), 0, 8), 'name_en' => 'Inspected probe', 'name_bn' => 'পরিদর্শনের নমুনা',
            'unit_id' => Unit::query()->orderBy('id')->firstOrFail()->id, 'qc_required' => true, 'is_active' => true,
        ]);
        app(StockService::class)->move(product: $this->product, warehouse: $this->warehouse, sourceType: 'test_opening', sourceId: $this->product->id, floor: '100');
    }

    private function approvedWithFiveBad(): QualityInspection
    {
        $paper = app(QualityInspectionService::class)->open([
            'product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id, 'inspected_qty' => '30',
        ]);

        return app(QualityInspectionService::class)->decide($paper, QualityInspection::APPROVED, '25', '5');
    }

    public function test_the_rejected_part_is_held_as_rejected_goods_not_as_goods_that_return(): void
    {
        $this->approvedWithFiveBad();

        $code = StockMovement::query()
            ->where('product_id', $this->product->id)
            ->where('hold_change', '>', 0)
            ->latest('id')
            ->firstOrFail()
            ->reasonCode?->code;

        $this->assertSame('HOLD-REJ', $code, 'অনুমোদিত রায়ের খারাপ অংশ ফেরার কারণে আটকেছে — ছেড়ে বেচা যেত।');
    }

    public function test_the_rejected_part_of_an_approved_paper_can_be_written_off(): void
    {
        $paper = $this->approvedWithFiveBad();
        $before = app(StockService::class)->statesFor($this->product, $this->warehouse);

        app(QualityInspectionService::class)->dispose(
            inspection: $paper->fresh(),
            qty: '5',
            writeOff: ReasonCode::query()->where('context', ReasonCode::STOCK_ADJUSTMENT)->firstOrFail(),
        );

        $after = app(StockService::class)->statesFor($this->product, $this->warehouse);

        $this->assertSame(0, bccomp(bcsub($before['floor'], $after['floor'], 4), '5', 4), 'খারাপ ৫টা খাতা থেকে যায়নি।');
        $this->assertSame(0, bccomp(app(StockService::class)->holdQty($this->product, $this->warehouse), '0', 4), 'আটকানো রয়ে গেছে।');
        $this->assertTrue($this->owner()->can('dispose', $paper->fresh()), 'বিনাশের অনুমতি অনুমোদিত কাগজে খোলে না — পর্দায় ফর্মই আসত না।');
    }

    private function owner(): User
    {
        return User::query()->where('email', 'owner@abos.test')->firstOrFail();
    }
}
