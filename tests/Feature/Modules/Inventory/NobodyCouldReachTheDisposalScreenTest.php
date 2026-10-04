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
use Tests\TestCase;

/**
 * বাতিল মাল বিনাশের দরজা কেউ খুলতেই পারতেন না — অডিট গ৪, ৪ অক্টোবর ২০২৬।
 *
 * ── ⛔ যা ভাঙা ছিল ───────────────────────────────────────────────────
 * বিনাশের রুট পাহারা দিত `decide` নিয়ম, যা খোলে কেবল **অপেক্ষমাণ** কাগজে — অথচ বিনাশ চলে কেবল **বাতিল বা
 * কোয়ারেন্টাইন** কাগজে। ফলে মালিকসহ সবার জন্য ৪০৩, আর পাতায় ফর্মটাই দেখা যেত না। বাতিল মাল চিরকাল আটকে
 * থাকত, বা লোকে ঘুরপথ নিতেন (ছাড়ো, তারপর বের করো) — আর মাঝের মুহূর্তে বাতিল মাল বিক্রয়যোগ্য।
 *
 * ── ⭐ এই ফাইল যা পাহারা দেয় ─────────────────────────────────────────
 *   ১. বাতিল কাগজের পাতায় বিনাশের ফর্ম দেখা যায়
 *   ২. বিনাশের রুট রায়ের চাবিওয়ালাকে ঢুকতে দেয়, আর মাল খাতা থেকে যায়
 *   ৩. অপেক্ষমাণ কাগজে বিনাশ দরজাতেই থামে
 *   ৪. রায়ের চাবি ছাড়া কেউ বিনাশ করতে পারেন না
 */
final class NobodyCouldReachTheDisposalScreenTest extends TestCase
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

    public function test_the_rejected_paper_shows_the_disposal_form(): void
    {
        $paper = $this->rejected('10');

        $this->actingAs($this->owner)
            ->get(route('inventory.qc.show', $paper))
            ->assertOk()
            ->assertSee(route('inventory.qc.dispose', $paper), false);
    }

    public function test_the_disposal_door_lets_the_decider_in(): void
    {
        $paper = $this->rejected('10');
        $floor = app(StockService::class)->floorQty($this->product, $this->warehouse);

        $this->actingAs($this->owner)
            ->from(route('inventory.qc.show', $paper))
            ->post(route('inventory.qc.dispose', $paper), [
                'qty' => '10',
                'reason_code_id' => ReasonCode::query()->where('context', ReasonCode::STOCK_ADJUSTMENT)->firstOrFail()->id,
            ])
            ->assertRedirect(route('inventory.qc.show', $paper))
            ->assertSessionHasNoErrors();

        $this->assertSame(0, bccomp(
            bcsub($floor, app(StockService::class)->floorQty($this->product, $this->warehouse), 4), '10', 4),
            'বিনাশের পরও মাল তাকে — দরজাটা আসলে কিছুই করেনি।');
    }

    public function test_a_pending_paper_stops_at_the_door(): void
    {
        $paper = app(QualityInspectionService::class)->open([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'inspected_qty' => '10',
        ]);

        $this->actingAs($this->owner)
            ->post(route('inventory.qc.dispose', $paper), [
                'qty' => '1',
                'reason_code_id' => ReasonCode::query()->where('context', ReasonCode::STOCK_ADJUSTMENT)->firstOrFail()->id,
            ])
            ->assertForbidden();
    }

    public function test_without_the_verdict_key_nobody_disposes(): void
    {
        $paper = $this->rejected('10');

        $clerk = User::query()->where('email', 'sales@abos.test')->firstOrFail();
        CompanyContext::forCompany(
            (int) CompanyContext::id(),
            fn () => $clerk->givePermissionTo('inventory.qc.view', 'inventory.qc.create'),
        );

        $this->actingAs($clerk->fresh())
            ->post(route('inventory.qc.dispose', $paper), [
                'qty' => '1',
                'reason_code_id' => ReasonCode::query()->where('context', ReasonCode::STOCK_ADJUSTMENT)->firstOrFail()->id,
            ])
            ->assertForbidden();
    }

    private function rejected(string $qty): QualityInspection
    {
        $service = app(QualityInspectionService::class);

        $paper = $service->open([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'inspected_qty' => $qty,
        ]);

        $service->decide(inspection: $paper, result: QualityInspection::REJECTED, acceptedQty: '0', rejectedQty: $qty);

        return $paper->fresh();
    }
}
