<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Approval\Services\ApprovalFlowService;
use App\Modules\Inventory\Models\Batch;
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
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * গ৭ — একই ঘাটতি একবারই খাতায়, যতবারই আবার চাপা হোক (Inventory অডিট, ৪ অক্টোবর ২০২৬)।
 *
 * ⛔ আগে সমন্বয় সইয়ে আটকালে খসড়া গণনাটা পড়ে থাকত, আর আবার চাপলে আরেকটা খসড়া হত। দুটোই একই খাতার সংখ্যা দেখে
 * লেখা, তাই অনুমোদনকারী পরে দুটো মানলে একই ঘাটতি দুইবার বসত — তাকে ৪০, গোনা ৩১, খাতায় ২২।
 */
final class ARetriedCountBookedTheShortageTwiceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Warehouse $warehouse;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->actingAs($this->owner);
    }

    public function test_a_held_adjustment_pressed_again_makes_no_second_paper_and_books_once(): void
    {
        $product = $this->stocked('40');
        $signer = User::query()->where('email', 'accounts@abos.test')->firstOrFail();
        app(ApprovalFlowService::class)->create(
            ['module' => 'inventory', 'action' => 'count', 'is_active' => true],
            [['level' => 1, 'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $signer->id, 'requires_all' => false]],
        );
        $ledgerBefore = $this->adjustmentRows();

        $this->from(route('inventory.stock.adjust'))
            ->post(route('inventory.stock.adjust.store'), $this->adjustment($product, '31'))
            ->assertSessionHasErrors('status');

        $paper = StockCount::query()->latest('id')->firstOrFail();

        // ⛔ আবার চাপা — দ্বিতীয় কাগজ নয়, আগেরটার নাম বলে থামে
        $again = $this->from(route('inventory.stock.adjust'))
            ->post(route('inventory.stock.adjust.store'), $this->adjustment($product, '31'));
        $again->assertSessionHasErrors('lines');
        $this->assertStringContainsString($paper->document_no, (string) session('errors')->first('lines'));

        $this->assertSame(1, $this->papersFor($product), '⛔ আবার চাপায় দ্বিতীয় খসড়া গণনা তৈরি হলো।');

        app(ApprovalEngine::class)->approve(
            Approval::query()->where('approvable_type', $paper->getMorphClass())->where('approvable_id', $paper->id)->sole(), $signer);

        $this->post(route('inventory.count.approve', $paper), ['reason_code_id' => $this->reason()->id])->assertSessionHasNoErrors();

        $this->assertOnHand($product, '31', '⛔ ঘাটতি একবারের বেশি বসেছে।');
        $this->assertSame($ledgerBefore + 2, $this->adjustmentRows(), '⛔ খতিয়ানে ঘাটতি একবারের বেশি।');
    }

    public function test_two_counts_of_the_same_goods_cannot_wait_together(): void
    {
        $product = $this->stocked('10');
        $first = $this->writeCount($product, '8');

        $this->assertRefused(fn () => $this->writeCount($product, '8'), 'lines', $first->document_no,
            '⛔ একই পণ্যের দ্বিতীয় খসড়া গণনা লেখা গেল।');

        // ⓘ অন্য পণ্য, বা অন্য গুদাম — বাধা নেই
        $this->writeCount($this->stocked('5'), '5');
        $this->assertSame(1, $this->papersFor($product));
    }

    public function test_lots_wait_apart_but_a_count_without_a_lot_waits_for_all_of_them(): void
    {
        $product = $this->stocked('0', tracksLots: true);
        $this->stockLot($product, 'LOT-A', '5');
        $this->stockLot($product, 'LOT-B', '5');

        $lotA = $this->writeCount($product, '3', 'LOT-A');

        // ⓘ লট B আলাদা মাল — চলে
        $this->writeCount($product, '4', 'LOT-B');

        // ⛔ লট ছাড়া ঘাটতি যেকোনো লট থেকে কাটে — দুই খসড়ার সাথেই মেলে
        $this->assertRefused(fn () => $this->writeCount($product, '2'), 'lines', null, '⛔ লট ছাড়া গণনা লটের খসড়ার পাশে লেখা গেল।');

        // ⛔ লট A আবার — আগেরটার নাম বলে থামে
        $this->assertRefused(fn () => $this->writeCount($product, '1', 'LOT-A'), 'lines', $lotA->document_no, '⛔ লট A-র দ্বিতীয় খসড়া লেখা গেল।');

        // ⛔ উল্টো ক্রমেও: লট ছাড়া খসড়া অপেক্ষায় থাকলে কোনো লটের গণনা নয়
        $other = $this->stocked('0', tracksLots: true);
        $this->stockLot($other, 'LOT-C', '5');
        $loose = $this->writeCount($other, '4');
        $this->assertRefused(fn () => $this->writeCount($other, '3', 'LOT-C'), 'lines', $loose->document_no, '⛔ লট ছাড়া খসড়ার পাশে লটের গণনা লেখা গেল।');
    }

    /** ⓘ আগের দিনের পড়ে থাকা জোড়া — একটা মেনে নিলে অন্যটা বাসি */
    public function test_a_left_over_pair_from_before_books_the_shortage_once(): void
    {
        $product = $this->stocked('10');
        $first = $this->writeCount($product, '8');
        $second = $this->legacyTwin($first);

        $this->travel(5)->seconds();
        app(StockCountService::class)->approve($first->fresh(), $this->reason());

        $this->assertRefused(fn () => app(StockCountService::class)->approve($second->fresh(), $this->reason()), 'status', $first->document_no,
            '⛔ পড়ে থাকা জোড়ার দ্বিতীয়টাও মানা গেল — একই ঘাটতি দুইবার।');

        $this->assertOnHand($product, '8', '⛔ ঘাটতি দুইবার কাটা হয়েছে।');
        $this->assertSame(DocumentStatus::DRAFT, $second->fresh()->status);
    }

    public function test_a_draft_is_cancelled_with_a_reason_and_then_a_new_count_can_be_written(): void
    {
        $product = $this->stocked('10');
        $paper = $this->writeCount($product, '8');

        $this->assertRefused(fn () => app(StockCountService::class)->cancel($paper, '  '), 'cancel_reason', null, '⛔ কারণ ছাড়া বাতিল হলো।');

        app(StockCountService::class)->cancel($paper, 'ভুল গুদামে গোনা');

        $paper->refresh();
        $this->assertSame(DocumentStatus::CANCELLED, $paper->status);
        $this->assertSame('ভুল গুদামে গোনা', $paper->cancel_reason);
        $this->assertSame((int) $this->owner->id, (int) $paper->cancelled_by);
        $this->assertNotNull($paper->cancelled_at);
        $this->assertOnHand($product, '10', '⛔ বাতিলে খাতা নড়েছে।');

        $this->assertRefused(fn () => app(StockCountService::class)->approve($paper, $this->reason()), 'status', null, '⛔ বাতিল গণনা মেনে নেওয়া গেল।');
        $this->assertRefused(fn () => app(StockCountService::class)->cancel($paper, 'আবার'), 'status', null, '⛔ বাতিল গণনা আবার বাতিল হলো।');

        // ⓘ এখন নতুন গণনা চলে
        $this->writeCount($product, '9');
        $this->assertSame(2, $this->papersFor($product));
    }

    public function test_the_page_cancels_and_only_the_writer_or_an_approver_may(): void
    {
        $storekeeper = $this->userInRole('Warehouse');
        $another = $this->userInRole('Warehouse');
        $this->assertFalse($storekeeper->can('inventory.count.approve'), 'প্রস্তুতি: গুদামের লোক মেনে নিতে পারেন না।');

        $product = $this->stocked('10');
        $this->actingAs($storekeeper);
        $paper = $this->writeCount($product, '8');

        $this->actingAs($another)
            ->post(route('inventory.count.cancel', $paper), ['cancel_reason' => 'অন্যের খসড়া'])
            ->assertForbidden();

        // ⓘ মেনে নেওয়ার চাবিওয়ালা অন্যের খসড়াও তুলতে পারেন
        $approver = $this->userInRole('Warehouse');
        $approver->givePermissionTo('inventory.count.approve');
        $this->assertTrue($approver->fresh()->can('cancel', $paper), '⛔ মেনে নেওয়ার চাবিওয়ালা অন্যের খসড়া বাতিল করতে পারেন না।');

        $this->actingAs($storekeeper)->get(route('inventory.count.show', $paper))
            ->assertOk()->assertSee(__('inventory::action.cancel_count'));

        $this->actingAs($storekeeper)
            ->from(route('inventory.count.show', $paper))
            ->post(route('inventory.count.cancel', $paper), ['cancel_reason' => 'নিজের ভুল গোনা'])
            ->assertRedirect(route('inventory.count.show', $paper))
            ->assertSessionHasNoErrors();

        $this->assertSame(DocumentStatus::CANCELLED, $paper->fresh()->status);
        $this->get(route('inventory.count.show', $paper))->assertOk()
            ->assertSee('নিজের ভুল গোনা')
            ->assertDontSee(__('inventory::action.cancel_count'));
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function writeCount(Product $product, string $counted, ?string $lot = null): StockCount
    {
        return app(StockCountService::class)->record(
            ['warehouse_id' => $this->warehouse->id],
            [['product_id' => $product->id, 'counted_qty' => $counted, 'batch_no' => $lot]],
        );
    }

    /** ⓘ পাহারার আগের দিনের মতো — একই খাতার সংখ্যায় দ্বিতীয় খসড়া, সরাসরি টেবিলে */
    private function legacyTwin(StockCount $first): StockCount
    {
        $line = $first->lines()->firstOrFail();
        $twin = StockCount::query()->create([
            'company_id' => $first->company_id,
            'branch_id' => $first->branch_id,
            'document_no' => $first->document_no.'-B',
            'count_date' => $first->count_date,
            'warehouse_id' => $first->warehouse_id,
            'status' => DocumentStatus::DRAFT,
            'created_by' => $this->owner->id,
        ]);
        $twin->lines()->create($line->only(['company_id', 'product_id', 'batch_id', 'book_qty', 'counted_qty', 'difference', 'unit_cost']));

        return $twin;
    }

    private function assertRefused(\Closure $act, string $field, ?string $mentions, string $why): void
    {
        try {
            $act();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors(), 'আটকেছে, কিন্তু অন্য কারণে: '.json_encode($e->errors(), JSON_UNESCAPED_UNICODE));

            if ($mentions !== null) {
                $this->assertStringContainsString($mentions, implode(' ', $e->errors()[$field]));
            }

            return;
        }

        $this->fail($why);
    }

    /** @return array<string, mixed> */
    private function adjustment(Product $product, string $counted): array
    {
        return [
            'product_id' => $product->id,
            'warehouse_id' => $this->warehouse->id,
            'reason_code_id' => $this->reason()->id,
            'counted' => $counted,
            'trx_date' => now()->toDateString(),
        ];
    }

    private function papersFor(Product $product): int
    {
        return StockCount::query()->whereHas('lines', fn ($q) => $q->where('product_id', $product->id))->count();
    }

    private function adjustmentRows(): int
    {
        return LedgerEntry::query()->where('source_type', StockService::ADJUSTMENT)->count();
    }

    private function assertOnHand(Product $product, string $expected, string $why): void
    {
        $this->assertSame(0, bccomp(app(StockService::class)->floorQty($product, $this->warehouse), $expected, 4), $why);
    }

    private function reason(): ReasonCode
    {
        return ReasonCode::query()->inContext(ReasonCode::STOCK_ADJUSTMENT)->active()->orderBy('id')->firstOrFail();
    }

    private function stocked(string $qty, bool $tracksLots = false): Product
    {
        $product = Product::query()->create([
            'code' => 'G7-'.mb_substr(md5(microtime()), 0, 8),
            'name_en' => 'Retry probe',
            'name_bn' => 'আবার-চাপার নমুনা',
            'unit_id' => Unit::query()->orderBy('id')->firstOrFail()->id,
            'is_active' => true,
            'track_batch' => $tracksLots,
        ]);

        if (bccomp($qty, '0', 4) > 0) {
            app(StockService::class)->move(product: $product, warehouse: $this->warehouse,
                sourceType: 'test.opening', sourceId: $product->id, floor: $qty);
            app(CostLayerService::class)->receive(product: $product, qty: $qty, unitCost: '10.00',
                sourceType: 'test.opening', sourceId: $product->id);
        }

        return $product;
    }

    private function stockLot(Product $product, string $no, string $qty): void
    {
        $lot = Batch::query()->create(['product_id' => $product->id, 'batch_no' => $no, 'expiry_date' => now()->addMonths(6)->toDateString()]);

        app(StockService::class)->move(product: $product, warehouse: $this->warehouse,
            sourceType: 'test.opening', sourceId: $product->id, floor: $qty, batch: $lot);
        app(CostLayerService::class)->receive(product: $product, qty: $qty, unitCost: '10.00',
            sourceType: 'test.opening', sourceId: $product->id, batch: $lot);
    }

    private function userInRole(string $name): User
    {
        $user = User::factory()->create(['current_company_id' => $this->company->id]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);
        $user->assignRole(Role::query()->where('name', $name)->where('company_id', $this->company->id)->firstOrFail());

        return $user;
    }
}
