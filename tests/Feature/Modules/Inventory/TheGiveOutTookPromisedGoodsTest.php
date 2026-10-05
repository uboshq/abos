<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use App\Modules\Approval\Services\ApprovalFlowService;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockCount;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\Inventory\Services\StockCountService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * বিনা বিক্রয়ে মাল বের করা অন্যের প্রতিশ্রুত মাল নিত — Inventory অডিট ম৬, ৫ অক্টোবর ২০২৬।
 *
 * ⛔ আপ্যায়ন, উপহার, মালিকের ব্যবহারে মাল বের করা মাপত কেবল তাক: তাকে ১০-এর ৬টা অন্যের আদেশে সংরক্ষিত, তবু ৫টা "উপহার"
 * বেরোত — আদেশের মাল খালি হাতে দাঁড়াত। আর সইয়ের অপেক্ষার মাঝে মাল সংরক্ষিত হলে শেষ সইয়ে আবার মাপাই হত না।
 * ⭐ এখন কাগজ খোলার সময় আর শেষ সইয়ে — দুই জায়গাতেই "পাওয়া যায়" (তাক − সংরক্ষিত − আটকানো), গুদাম তালাসহ।
 */
final class TheGiveOutTookPromisedGoodsTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private User $signer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->signer = User::query()->where('email', 'accounts@abos.test')->firstOrFail();
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
    }

    /** ⛔ তাকে ১০, ৬টা সংরক্ষিত — ৫টা বের করা থামে, ৪টা যায় */
    public function test_a_give_out_cannot_take_goods_promised_to_an_order(): void
    {
        $product = $this->stocked('10');
        $this->reserve($product, '6');

        try {
            app(StockCountService::class)->issue($product, $this->warehouse, '5', $this->issueReason());
            $this->fail('⛔ ৬টা অন্যের আদেশে সংরক্ষিত, তবু ৫টা "উপহার" বেরোল।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('qty', $e->errors());
        }

        app(StockCountService::class)->issue($product, $this->warehouse, '4', $this->issueReason());
        $this->assertSame(0, bccomp('6', app(StockService::class)->floorQty($product, $this->warehouse), 4));
    }

    /** ⛔ সইয়ের অপেক্ষার মাঝে মাল সংরক্ষিত হলে শেষ সইয়ে থামে — সংরক্ষিত মাল বেরোয় না */
    public function test_the_last_signature_measures_again(): void
    {
        app(ApprovalFlowService::class)->create(
            ['module' => 'inventory', 'action' => 'issue', 'is_active' => true],
            [['level' => 1, 'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $this->signer->id, 'requires_all' => false]],
        );

        $product = $this->stocked('10');
        [$paper, $held] = app(StockCountService::class)->issue($product, $this->warehouse, '5', $this->issueReason());
        $this->assertTrue($held, 'প্রস্তুতিটাই ভুল — কাগজ সইয়ে যায়নি।');

        $this->reserve($product, '8'); // ⓘ অপেক্ষার মাঝে — এখন পাওয়া যায় ২

        try {
            app(ApprovalEngine::class)->approve(
                Approval::query()->where('approvable_type', $paper->getMorphClass())->where('approvable_id', $paper->id)->latest('id')->firstOrFail(),
                $this->signer,
            );
        } catch (ValidationException) {
            // ⓘ সইয়ের ঘটনা থামলে সইও থামে — দুটোই ঠিক উত্তর; আসল দাবি নিচে
        }

        $this->assertNotSame(DocumentStatus::CONFIRMED, $paper->fresh()->status, '⛔ সংরক্ষিত মাল শেষ সইয়ে বেরিয়ে গেল।');
        $this->assertSame(0, bccomp('10', app(StockService::class)->floorQty($product, $this->warehouse), 4), '⛔ তাক থেকে মাল কমেছে।');
    }

    private function stocked(string $qty): Product
    {
        $product = Product::query()->create([
            'code' => 'M6-'.mb_substr(md5(microtime()), 0, 8), 'name_en' => 'Give-out probe', 'name_bn' => 'বের-করার নমুনা',
            'unit_id' => Unit::query()->orderBy('id')->firstOrFail()->id, 'is_active' => true,
        ]);
        app(StockService::class)->move(product: $product, warehouse: $this->warehouse, sourceType: 'test.opening', sourceId: $product->id, floor: $qty);
        app(CostLayerService::class)->receive(product: $product, qty: $qty, unitCost: '10.00', sourceType: 'test.opening', sourceId: $product->id);

        return $product;
    }

    private function reserve(Product $product, string $qty): void
    {
        app(StockService::class)->move(product: $product, warehouse: $this->warehouse, sourceType: 'test.order', sourceId: 1, reserved: $qty);
    }

    private function issueReason(): ReasonCode
    {
        return ReasonCode::query()->inContext(ReasonCode::STOCK_ISSUE)->active()->whereNotNull('account_id')->orderBy('id')->firstOrFail();
    }
}
