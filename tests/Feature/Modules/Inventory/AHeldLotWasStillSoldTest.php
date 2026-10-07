<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\QualityInspection;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\Inventory\Services\QualityInspectionService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * এক লটে আটকানো মাল তবু বিক্রি হত — অডিট গ২, ৪ অক্টোবর ২০২৬।
 *
 * ── ⛔ যা ভাঙা ছিল ───────────────────────────────────────────────────
 * লট বাছার সময় ([[BatchAllocator]], [[StockService::issue()]]-এর বাছা লট) লটের আটকানো অংশ বাদ যেত না।
 * লট A-র ১০টা পরিদর্শনে বাতিল হয়ে আটকানো, লট B-তে ৫০ — আগে-মেয়াদ নিয়মে লট A বাছা হত, বাতিল মাল চলে যেত।
 * ⚠️ আর বাতিল মাল বিনাশের সময় ছাড়া আর বাদ দুইটাই লট ছাড়া চলত — তাক থেকে যেত **অন্য** লট।
 *
 * ── ⭐ এই ফাইল যা পাহারা দেয় ─────────────────────────────────────────
 *   ১. আগে-মেয়াদ নিয়ম আটকানো লট এড়িয়ে পরেরটা নেয়
 *   ২. আটকানো লট হাতে বাছলেও বিক্রি থামে
 *   ৩. বাতিল মাল বিনাশে ঠিক সেই লটটাই যায়
 */
final class AHeldLotWasStillSoldTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private Warehouse $warehouse;

    private Batch $soon;

    private Batch $late;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

        $this->product = Product::query()->create([
            'code' => 'G2-'.mb_substr(md5(microtime()), 0, 8),
            'name_en' => 'Lot with a hold',
            'name_bn' => 'আটকানো লট',
            'unit_id' => Unit::query()->orderBy('id')->firstOrFail()->id,
            'track_batch' => true,
            'is_active' => true,
        ]);

        $this->soon = $this->lot('SOON', now()->addMonths(2)->toDateString(), '10');
        $this->late = $this->lot('LATE', now()->addMonths(12)->toDateString(), '50');
    }

    // ── ১ · আগে-মেয়াদ নিয়ম ──────────────────────────────────────────

    public function test_fefo_skips_what_is_held_in_a_lot(): void
    {
        // ⓘ আগে-মেয়াদের লটের পুরো ১০টা পরিদর্শনে বাতিল, আটকানো — লটের নামে
        $this->holdInLot($this->soon, '10');

        $out = app(StockService::class)->issue(
            product: $this->product,
            warehouse: $this->warehouse,
            sourceType: 'test_sale',
            sourceId: 1,
            qty: '10',
        );

        $lots = collect($out)->map(fn (StockMovement $m) => $m->batch?->batch_no)->unique()->values()->all();

        $this->assertSame(['LATE'], $lots,
            'আটকানো লট থেকেই মাল বেরিয়েছে — পরিদর্শনে বাতিল মাল ডিলারের কাছে গেল।');
    }

    // ── ২ · হাতে বাছা লট ─────────────────────────────────────────────

    public function test_a_chosen_lot_will_not_sell_what_it_holds(): void
    {
        $this->holdInLot($this->soon, '8');

        $this->expectException(ValidationException::class);

        app(StockService::class)->issue(
            product: $this->product,
            warehouse: $this->warehouse,
            sourceType: 'test_sale',
            sourceId: 2,
            qty: '5',
            batch: $this->soon,
        );
    }

    // ── ৩ · বিনাশ ঠিক লট থেকে ────────────────────────────────────────

    public function test_disposal_takes_the_rejected_lot_not_another(): void
    {
        // ⓘ পরের-মেয়াদের লটটাই বাতিল — লট না বললে আগে-মেয়াদ নিয়ম অন্য লট (SOON) কাটত
        $service = app(QualityInspectionService::class);

        $paper = $service->open([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'batch_id' => $this->late->id,
            'inspected_qty' => '10',
        ]);

        $service->decide(inspection: $paper, result: QualityInspection::REJECTED, acceptedQty: '0', rejectedQty: '10');

        $service->dispose(
            inspection: $paper->fresh(),
            qty: '10',
            writeOff: ReasonCode::query()->where('context', ReasonCode::STOCK_ADJUSTMENT)->firstOrFail(),
        );

        $this->assertSame(0, bccomp($this->late->floorBalance($this->warehouse), '40', 4),
            'বাতিল লট (LATE) তাকেই রয়ে গেছে।');
        $this->assertSame(0, bccomp($this->soon->floorBalance($this->warehouse), '10', 4),
            'বাতিল মাল বিনাশে ভালো লট (SOON) কাটা গেছে — রিকলের খাতা আর তাকের মাল দুইটাই ভুল।');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function lot(string $no, string $expiry, string $qty): Batch
    {
        $batch = Batch::query()->create([
            'product_id' => $this->product->id,
            'batch_no' => $no,
            'expiry_date' => $expiry,
        ]);

        app(StockService::class)->move(
            product: $this->product,
            warehouse: $this->warehouse,
            sourceType: 'test_opening',
            sourceId: $batch->id,
            floor: $qty,
            batch: $batch,
        );

        app(CostLayerService::class)->receive(
            product: $this->product,
            qty: $qty,
            unitCost: '10',
            sourceType: 'test_opening',
            sourceId: $batch->id,
            batch: $batch,
        );

        return $batch;
    }

    private function holdInLot(Batch $batch, string $qty): void
    {
        app(StockService::class)->hold(
            product: $this->product,
            warehouse: $this->warehouse,
            qty: $qty,
            reason: ReasonCode::query()->where('code', 'HOLD-REJ')->firstOrFail(),
            batch: $batch,
        );
    }
}
