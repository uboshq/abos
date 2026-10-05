<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\QualityInspection;
use App\Modules\Inventory\Models\StockCount;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\Inventory\Services\QualityInspectionService;
use App\Modules\Inventory\Services\StockCountService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * গ৮ — গণনা আর বিনাশের ঘাটতি কেবল সমন্বয়ের কারণে বসে (Inventory অডিট, ৪ অক্টোবর ২০২৬)।
 *
 * ⛔ আগে মেনে নেওয়ার সময় যেকোনো কারণ বাছা যেত। গণনার ঘাটতিতে "মালিকের ব্যবহার" বাছলে টাকা মালিকের উত্তোলনে যেত,
 * ঘাটতির খাতে (৫১৬০) নয় — মুনাফা বেশি দেখাত, আর গুদামের ক্ষতি কারও চোখে পড়ত না।
 */
final class TheShortageWentToTheWrongAccountTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
    }

    public function test_a_count_is_not_settled_under_a_giveaway_reason(): void
    {
        [$product, $count] = $this->shortCount();

        $this->assertRefused(fn () => app(StockCountService::class)->approve($count, $this->issueReason()),
            '⛔ গণনার ঘাটতি "মাল বের করা"-র কারণে মেনে নেওয়া গেল — টাকা ঘাটতির খাতে না গিয়ে অন্য খাতে গেল।');

        $this->assertNothingMoved($product, $count);
    }

    public function test_a_count_is_not_settled_under_another_companys_or_a_retired_reason(): void
    {
        [$product, $count] = $this->shortCount();

        $theirs = ReasonCode::query()->withoutGlobalScopes()
            ->where('company_id', '!=', $this->company->id)
            ->where('context', ReasonCode::STOCK_ADJUSTMENT)
            ->firstOrFail();

        $this->assertRefused(fn () => app(StockCountService::class)->approve($count, $theirs),
            '⛔ অন্য কোম্পানির কারণে গণনা মেনে নেওয়া গেল।');

        $retired = $this->adjustmentReason();
        $retired->update(['is_active' => false]);

        $this->assertRefused(fn () => app(StockCountService::class)->approve($count, $retired->fresh()),
            '⛔ বন্ধ করা কারণে গণনা মেনে নেওয়া গেল।');

        $this->assertNothingMoved($product, $count);
    }

    /** ⓘ আজকের পথ অটুট — সমন্বয়ের কারণে ঘাটতি ৫১৬০-এ, ঠিক আগের মতো */
    public function test_an_adjustment_reason_still_settles_into_the_shortage_account(): void
    {
        [$product, $count] = $this->shortCount();
        $before = $this->debitsOn(StandardChart::INVENTORY_SHORTAGE_SURPLUS);

        // ⓘ যে কারণ নিজের খাত বলে না — ঘাটতি তখন ৫১৬০-এ
        $reason = ReasonCode::query()->inContext(ReasonCode::STOCK_ADJUSTMENT)->active()->whereNull('account_id')->orderBy('id')->firstOrFail();

        app(StockCountService::class)->approve($count, $reason);

        $this->assertSame(DocumentStatus::CONFIRMED, $count->fresh()->status);
        $this->assertSame(0, bccomp(app(StockService::class)->floorQty($product, $this->warehouse), '8', 4));
        $this->assertSame(0, bccomp(bcsub($this->debitsOn(StandardChart::INVENTORY_SHORTAGE_SURPLUS), $before, 4), '20', 4),
            '⛔ ২টা × ১০ টাকার ঘাটতি ৫১৬০-এ বসেনি।');
    }

    public function test_the_count_page_offers_only_adjustment_reasons_and_refuses_another(): void
    {
        [$product, $count] = $this->shortCount();

        $page = $this->get(route('inventory.count.show', $count))->assertOk();
        $page->assertSee($this->adjustmentReason()->label());
        $page->assertDontSee($this->issueReason()->label());

        $this->from(route('inventory.count.show', $count))
            ->post(route('inventory.count.approve', $count), ['reason_code_id' => $this->issueReason()->id])
            ->assertSessionHasErrors('reason_code_id');

        $this->assertNothingMoved($product, $count);
    }

    public function test_rejected_goods_are_not_written_off_under_a_giveaway_reason(): void
    {
        $product = $this->product();
        $this->stockUp($product, '20');

        $service = app(QualityInspectionService::class);
        $paper = $service->open(['product_id' => $product->id, 'warehouse_id' => $this->warehouse->id, 'inspected_qty' => '5']);
        $service->decide(inspection: $paper, result: QualityInspection::REJECTED, acceptedQty: '0', rejectedQty: '5');

        $this->assertRefused(fn () => $service->dispose(inspection: $paper->fresh(), qty: '5', writeOff: $this->issueReason()),
            '⛔ বাতিল মাল "মাল বের করা"-র কারণে বিনাশ হলো — ক্ষতি অন্য খাতে গেল।');

        $this->assertSame(0, bccomp(app(StockService::class)->floorQty($product, $this->warehouse), '20', 4));
        $this->assertSame(0, bccomp((string) $paper->fresh()->disposed_qty, '0', 4));

        // ⓘ ঠিক কারণে আগের মতোই চলে
        $service->dispose(inspection: $paper->fresh(), qty: '5', writeOff: $this->adjustmentReason());
        $this->assertSame(0, bccomp(app(StockService::class)->floorQty($product, $this->warehouse), '15', 4));
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /** @return array{0: Product, 1: StockCount} তাকে ১০, গোনা ৮ */
    private function shortCount(): array
    {
        $product = $this->product();
        $this->stockUp($product, '10');

        $count = app(StockCountService::class)->record(
            ['warehouse_id' => $this->warehouse->id],
            [['product_id' => $product->id, 'counted_qty' => '8']],
        );

        return [$product, $count->fresh()];
    }

    private function assertRefused(\Closure $act, string $why): void
    {
        try {
            $act();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reason_code_id', $e->errors(), 'আটকেছে, কিন্তু অন্য কারণে: '.json_encode($e->errors(), JSON_UNESCAPED_UNICODE));

            return;
        }

        $this->fail($why);
    }

    private function assertNothingMoved(Product $product, StockCount $count): void
    {
        $this->assertSame(DocumentStatus::DRAFT, $count->fresh()->status, '⛔ গণনাটা মেনে নেওয়া হয়ে গেছে।');
        $this->assertSame(0, bccomp(app(StockService::class)->floorQty($product, $this->warehouse), '10', 4), '⛔ তাক নড়েছে।');
    }

    private function product(): Product
    {
        return Product::query()->create([
            'code' => 'G8-'.mb_substr(md5(microtime()), 0, 8),
            'name_en' => 'Reason probe',
            'name_bn' => 'কারণের নমুনা',
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

    private function adjustmentReason(): ReasonCode
    {
        return ReasonCode::query()->inContext(ReasonCode::STOCK_ADJUSTMENT)->active()->orderBy('id')->firstOrFail();
    }

    private function issueReason(): ReasonCode
    {
        return ReasonCode::query()->inContext(ReasonCode::STOCK_ISSUE)->active()->whereNotNull('account_id')->orderBy('id')->firstOrFail();
    }

    private function debitsOn(string $code): string
    {
        $account = StandardChart::find($code);

        return (string) LedgerEntry::query()->where('account_id', $account?->id)
            ->selectRaw('COALESCE(SUM(debit), 0) as n')->value('n');
    }
}
