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
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * পরিদর্শনের অপেক্ষার মাল বিক্রি হয়ে যেত — অডিট গ৩, ৪ অক্টোবর ২০২৬।
 *
 * ── ⛔ যা ভাঙা ছিল ───────────────────────────────────────────────────
 * মাল গ্রহণে পরিদর্শন খুললেও মাল আটকাত না। তাকে উঠেই বিক্রি হত — পরিদর্শকের দেখার আগেই — আর পরে "বাতিল"
 * রায় দিতে গেলে ত্রুটি, কারণ বাতিল করার মতো মাল আর নেই।
 *
 * ── ⭐ এই ফাইল যা পাহারা দেয় ─────────────────────────────────────────
 *   ১. তাকের মালে কাগজ খুললেই সেটা আর বিক্রয়যোগ্য নয়
 *   ২. গ্রহণের মাল (অপেক্ষার ঘরে) তাকে ওঠার মুহূর্তেই আটকায় — এক মুহূর্তও বিক্রয়যোগ্য নয়
 *   ৩. রায়ে অপেক্ষার আটকানো পুরোটা ফেরে — পাশ করা মাল আবার বিক্রয়যোগ্য, বাতিল অংশটুকুই আটকে থাকে
 *   ৪. অপেক্ষার আটকানো মজুদের "ছাড়ো" পর্দা থেকে ছাড়া যায় না
 */
final class GoodsWaitingForInspectionWereSoldTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Product $product;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

        $this->product = Product::query()->create([
            'code' => 'G3-'.mb_substr(md5(microtime()), 0, 8),
            'name_en' => 'Waiting for the inspector',
            'name_bn' => 'পরিদর্শকের অপেক্ষায়',
            'unit_id' => Unit::query()->orderBy('id')->firstOrFail()->id,
            'qc_required' => true,
            'is_active' => true,
        ]);
    }

    // ── ১ · তাকের মাল ────────────────────────────────────────────────

    public function test_opening_a_paper_takes_the_goods_out_of_sale(): void
    {
        $this->shelve('100');

        $this->openPaper('30');

        $this->assertSame(0, bccomp($this->available(), '70', 4),
            'পরিদর্শনের অপেক্ষার ৩০ এখনো বিক্রয়যোগ্য — পরিদর্শক দেখার আগেই কাউন্টার থেকে বেরিয়ে যেত।');
    }

    // ── ২ · গ্রহণের মাল ──────────────────────────────────────────────

    public function test_received_goods_are_held_the_moment_they_reach_the_shelf(): void
    {
        // ⓘ গ্রহণে মাল অপেক্ষার ঘরে নামে, আর কাগজ খোলে — তখন আটকানোর মতো কিছু তাকে নেই
        app(StockService::class)->move(
            product: $this->product,
            warehouse: $this->warehouse,
            sourceType: 'purchase_receipt',
            sourceId: 8801,
            unplaced: '40',
        );

        $this->openPaper('40', ['source_type' => 'purchase_receipt', 'source_id' => 8801]);

        // ⓘ গুদামের লোক ৩০ তাকে তুললেন
        app(StockService::class)->place(
            product: $this->product,
            warehouse: $this->warehouse,
            qty: '30',
            sourceType: 'purchase_receipt',
            sourceId: 8801,
        );

        $this->assertSame(0, bccomp($this->available(), '0', 4),
            'তাকে ওঠা পরিদর্শনের অপেক্ষার মাল বিক্রয়যোগ্য হয়ে গেছে।');
        $this->assertSame(0, bccomp(app(StockService::class)->holdQty($this->product, $this->warehouse), '30', 4));
    }

    // ── ৩ · রায়ে মীমাংসা ─────────────────────────────────────────────

    public function test_the_verdict_settles_the_waiting_hold(): void
    {
        $this->shelve('100');

        $passed = $this->openPaper('30');
        app(QualityInspectionService::class)->decide($passed, QualityInspection::APPROVED, '30', '0');

        $this->assertSame(0, bccomp($this->available(), '100', 4),
            'পাশ করা মাল তবু আটকে আছে — অপেক্ষার আটকানো রায়ে ফেরেনি।');

        $rejected = $this->openPaper('20');
        app(QualityInspectionService::class)->decide($rejected, QualityInspection::REJECTED, '15', '5');

        $this->assertSame(0, bccomp(app(StockService::class)->holdQty($this->product, $this->warehouse), '5', 4),
            'রায়ের পরে আটকানো কেবল বাতিল ৫ হওয়ার কথা — অপেক্ষার আটকানো রয়ে গেছে, বা দুইবার গোনা হয়েছে।');
    }

    // ── ৪ · "ছাড়ো" পর্দা ─────────────────────────────────────────────

    public function test_the_release_screen_cannot_free_goods_waiting_for_inspection(): void
    {
        $this->shelve('100');
        $this->openPaper('30');

        $this->actingAs($this->owner)
            ->from(route('inventory.stock.adjust'))
            ->post(route('inventory.stock.release'), [
                'product_id' => $this->product->id,
                'warehouse_id' => $this->warehouse->id,
                'reason_code_id' => ReasonCode::query()->where('code', 'HOLD-RET')->firstOrFail()->id,
                'qty' => '30',
            ]);

        $this->assertSame(0, bccomp($this->available(), '70', 4),
            'পরিদর্শনের অপেক্ষার মাল "ছাড়ো" পর্দা দিয়ে ছাড়া হয়ে গেছে — রায় ছাড়াই বিক্রয়যোগ্য।');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function shelve(string $qty): void
    {
        app(StockService::class)->move(
            product: $this->product,
            warehouse: $this->warehouse,
            sourceType: 'test_opening',
            sourceId: $this->product->id,
            floor: $qty,
        );
    }

    /** @param  array<string, mixed>  $extra */
    private function openPaper(string $qty, array $extra = []): QualityInspection
    {
        return app(QualityInspectionService::class)->open([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'inspected_qty' => $qty,
            ...$extra,
        ]);
    }

    private function available(): string
    {
        return app(StockService::class)->availableQty($this->product, $this->warehouse);
    }
}
