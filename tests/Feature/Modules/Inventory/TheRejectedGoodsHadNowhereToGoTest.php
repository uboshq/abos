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
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * বাতিল মালের যাওয়ার জায়গা ছিল না।
 *
 * ── ⛔ যে অবস্থাটা ছিল ───────────────────────────────────────────────
 * পরিদর্শনে বাতিল হলে মালটা আটকে যেত, আর **চিরকাল আটকেই থাকত** —
 * গুদামে জায়গা নিত, মজুদের মূল্যে গোনা হত, অথচ বিক্রি করা যেত না।
 *
 * ── ⚠️ আর দুই ধাপে করতে গেলে একটা জানালা খুলত ────────────────────────
 * আগে উপায় ছিল: আটকানো ছেড়ে দাও, তারপর স্টক সমন্বয়ে বাদ দাও।
 * ⛔ কিন্তু `available = floor − reserved − hold` — অর্থাৎ আটকানো
 * ছাড়ার **সাথে সাথেই** মালটা বিক্রয়যোগ্য, আর দ্বিতীয় ধাপের আগে
 * কাউন্টার থেকে বেরিয়ে যেতে পারত।
 *
 * ⓘ পরিদর্শনে বাতিল হওয়া ওষুধের বেলায় ঐ কয়েক সেকেন্ড যথেষ্ট, আর
 * কোথাও কোনো ভুল দেখাত না।
 *
 * ── ⭐ এই ফাইল যা পাহারা দেয় ─────────────────────────────────────────
 *   ১. বিনাশের পর মাল তাক থেকেও যায়, আটকানো থেকেও
 *   ২. **কোনো মুহূর্তেই** বিক্রয়যোগ্য সংখ্যাটা বাড়ে না
 *   ৩. এই কাগজ যতটা আটকে রেখেছে তার বেশি বিনাশ করা যায় না
 *   ৪. রায় না হওয়া কাগজে বিনাশ চলে না
 *   ৫. দুইবারে ভাগ করে বিনাশ করা যায়, আর হিসাব মনে থাকে
 */
final class TheRejectedGoodsHadNowhereToGoTest extends TestCase
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

        $this->product = Product::query()->where('track_batch', false)->orderBy('id')->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

        app(StockService::class)->move(
            product: $this->product,
            warehouse: $this->warehouse,
            sourceType: 'test_seed',
            sourceId: $this->product->id,
            floor: '100',
        );
    }

    // ── ১ ও ২ · দুই দিক থেকেই যায়, আর মাঝখানে কিছু খোলে না ───────────

    public function test_disposal_takes_the_goods_off_the_shelf_and_out_of_the_hold(): void
    {
        $paper = $this->rejected('10');

        $before = app(StockService::class)->statesFor($this->product, $this->warehouse);

        app(QualityInspectionService::class)->dispose(
            inspection: $paper,
            qty: '10',
            writeOff: $this->writeOffReason(),
        );

        $after = app(StockService::class)->statesFor($this->product, $this->warehouse);

        $this->assertSame(0, bccomp(bcsub($before['floor'], $after['floor'], 4), '10', 4),
            'মাল তাক থেকে বাদ যায়নি — বিনাশ মানে খাতা থেকেও যাওয়া।');

        $this->assertSame(0, bccomp(bcsub($before['hold'], $after['hold'], 4), '10', 4),
            'আটকানো ছাড়া হয়নি — তাহলে hold তাকের চেয়ে বড় থেকে যেত, আর '
            .'বিক্রয়যোগ্য সংখ্যাটা ঋণাত্মক দেখাত।');
    }

    public function test_the_sellable_figure_never_rises_along_the_way(): void
    {
        /*
         * ⭐ এটাই গোটা কাজটার কারণ।
         *
         * ⛔ দুই ধাপে করলে মাঝখানে `available` ঠিক ১০ বেড়ে যেত, আর ঐ
         * মুহূর্তে বাতিল মালটা বেচা যেত। ⓘ এক লেনদেনে করায় কোনো
         * মাঝখানই নেই — আর দাবিটা সেটাই মাপে: আগে যা ছিল, পরেও তাই।
         */
        $paper = $this->rejected('10');

        $before = app(StockService::class)->availableQty($this->product, $this->warehouse);

        app(QualityInspectionService::class)->dispose(
            inspection: $paper,
            qty: '10',
            writeOff: $this->writeOffReason(),
        );

        $after = app(StockService::class)->availableQty($this->product, $this->warehouse);

        $this->assertSame(0, bccomp($before, $after, 4),
            'বিনাশের পর বিক্রয়যোগ্য সংখ্যাটা বদলে গেছে — অথচ বাতিল মাল '
            .'কোনোদিনই বিক্রয়যোগ্য ছিল না, আর এখন সেটা খাতা থেকেও গেছে।');
    }

    // ── ৩ · এই কাগজের সীমা ───────────────────────────────────────────

    public function test_a_paper_cannot_dispose_of_more_than_it_holds(): void
    {
        /*
         * ⛔ গুদামের মোট আটকানো সংখ্যা দিয়ে সীমা বসালে একটা কাগজ দিয়ে
         * **অন্য কাগজের** মাল বিনাশ করা যেত — ⚠️ আর দুইটা পরিদর্শনের
         * বাতিল মাল একই তাকে পাশাপাশি থাকাটাই স্বাভাবিক।
         */
        $paper = $this->rejected('10');

        $this->expectException(ValidationException::class);

        app(QualityInspectionService::class)->dispose(
            inspection: $paper,
            qty: '11',
            writeOff: $this->writeOffReason(),
        );
    }

    // ── ৪ · রায় ছাড়া নয় ──────────────────────────────────────────────

    public function test_a_paper_without_a_verdict_disposes_of_nothing(): void
    {
        /*
         * ⚠️ অপেক্ষমাণ কাগজে বিনাশ করতে দিলে পরিদর্শক দেখার **আগেই**
         * মাল চলে যেত, আর পরিদর্শনটার কোনো মানেই থাকত না।
         */
        $paper = app(QualityInspectionService::class)->open([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'inspected_qty' => '10',
        ]);

        $this->expectException(ValidationException::class);

        app(QualityInspectionService::class)->dispose(
            inspection: $paper,
            qty: '1',
            writeOff: $this->writeOffReason(),
        );
    }

    // ── ৫ · ভাগে ভাগে, আর হিসাব মনে থাকে ─────────────────────────────

    public function test_disposal_can_happen_in_parts(): void
    {
        /*
         * ⓘ বাস্তবে পুরোটা একবারে যায় না: বিশটা আজ, বাকিটা সরবরাহকারী
         * দেখতে আসার পরে। ⚠️ কতটা ইতিমধ্যে গেছে সেটা মনে না রাখলে
         * দ্বিতীয়বারে সীমাটা ভুল হত, আর একই মাল দুইবার বিনাশ করা যেত।
         */
        $paper = $this->rejected('10');
        $service = app(QualityInspectionService::class);

        $service->dispose(inspection: $paper, qty: '4', writeOff: $this->writeOffReason());
        $service->dispose(inspection: $paper->fresh(), qty: '6', writeOff: $this->writeOffReason());

        $this->assertSame(0, bccomp((string) $paper->fresh()->disposed_qty, '10', 4));

        $this->expectException(ValidationException::class);

        $service->dispose(inspection: $paper->fresh(), qty: '1', writeOff: $this->writeOffReason());
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function rejected(string $qty): QualityInspection
    {
        $service = app(QualityInspectionService::class);

        $paper = $service->open([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'inspected_qty' => $qty,
        ]);

        $service->decide(
            inspection: $paper,
            result: QualityInspection::REJECTED,
            acceptedQty: '0',
            rejectedQty: $qty,
        );

        return $paper->fresh();
    }

    private function writeOffReason(): ReasonCode
    {
        return ReasonCode::query()
            ->where('context', ReasonCode::STOCK_ADJUSTMENT)
            ->firstOrFail();
    }
}
