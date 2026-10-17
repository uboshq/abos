<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Http\Controllers\Api\AuthController;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Approval\Services\ApprovalFlowService;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockCount;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ মজুদের পাঁচ লেখা ফোনের নিজের ফর্মে — মালিক, ১৭ অক্টোবর ২০২৬ (fe; [[StockPhoneController]])।
 *
 * দাবি, আসল HTTP দিয়ে:
 *  - চাবি ছাড়া প্রতিটা দরজা ৪০৩।
 *  - অন্য শাখার গুদাম "পাওয়া গেল না" (৪২২) — কিছু নড়ে না।
 *  - স্টকের বেশি বের করা আটকায় (ঋণাত্মক মজুদ নয়)।
 *  - একই `Idempotency-Key` দুবার পাঠালে একটাই কাগজ, একবারই মাল নড়ে।
 *  - স্টক-খাতা আর হিসাবের খাতা দুটোই মেলে — ইস্যু, খোলা মজুদ, গণনা।
 *  - সই ওয়েবের একই: মেনে নেওয়ার চাবি ছাড়া গণনা খসড়া; ছক থাকলে ইস্যু সইয়ের অপেক্ষায়, মাল নড়ে না।
 *  - মাল বসানো আর লট ওয়েবের একই পথে।
 */
final class ThePhoneWritesTheStockTheWebWayTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->warehouse = Warehouse::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'WH-MMS')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        // ⓘ মালিক গুদামের শাখায় বসা — অন্য শাখার গুদাম তখন দেয়ালের বাইরে
        $this->owner->forceFill(['current_company_id' => $this->company->id, 'current_branch_id' => $this->warehouse->branch_id])->save();
        CompanyContext::set((int) $this->company->id, (int) $this->warehouse->branch_id);
        $this->actingAs($this->owner);
    }

    public function test_an_issue_goes_out_once_for_one_key_and_both_books_agree(): void
    {
        $product = $this->stocked('40');
        $before = $this->debitsOn($this->issueReason()->account_id);
        $body = $this->issueBody($product, '5');

        $first = $this->phonePost($this->owner, '/api/v1/inventory/issue', $body, 'issue-key-0001')->assertCreated();
        $this->assertSame('done', $first->json('status'));
        $this->phonePost($this->owner, '/api/v1/inventory/issue', $body, 'issue-key-0001')->assertCreated()->assertHeader('Idempotent-Replay', 'true');

        $this->assertOnHand($product, '35', '⛔ একই চাবিতে দুবার পাঠালে মাল দুবার বেরোল, বা একবারও না।');
        $this->assertSame(1, StockCount::query()->where('kind', StockCount::KIND_ISSUE)->whereHas('lines', fn ($q) => $q->where('product_id', $product->id))->count(),
            '⛔ একই চাবিতে দুটো ইস্যুর কাগজ।');
        $this->assertSame(0, bccomp(bcsub($this->debitsOn($this->issueReason()->account_id), $before, 4), '50', 4),
            '⛔ ৫টা × ১০ টাকা কারণের খাতে বসেনি — হিসাবের খাতা স্টক-খাতার সাথে মেলে না।');
    }

    public function test_more_than_the_stock_is_refused_and_nothing_moves(): void
    {
        $product = $this->stocked('3');

        $this->phonePost($this->owner, '/api/v1/inventory/issue', $this->issueBody($product, '5'), 'issue-key-0002')
            ->assertStatus(422)->assertJsonValidationErrors('qty');

        $this->assertOnHand($product, '3', '⛔ স্টকের বেশি বের করা ফোন থেকে আটকায়নি।');
    }

    public function test_every_door_asks_the_webs_key(): void
    {
        $nobody = $this->staff([]);
        $product = $this->stocked('10');

        $this->phoneGet($nobody, '/api/v1/inventory/setup')->assertForbidden();
        $this->phoneGet($nobody, '/api/v1/inventory/placement')->assertForbidden();
        $this->phoneGet($nobody, '/api/v1/inventory/counts')->assertForbidden();
        $this->phonePost($nobody, '/api/v1/inventory/placement', ['lines' => [['ref' => 'x']]], 'nokey-0001')->assertForbidden();
        $this->phonePost($nobody, '/api/v1/inventory/adjust', $this->adjustBody($product, '9'), 'nokey-0002')->assertForbidden();
        $this->phonePost($nobody, '/api/v1/inventory/issue', $this->issueBody($product, '1'), 'nokey-0003')->assertForbidden();
        $this->phonePost($nobody, '/api/v1/inventory/opening', ['product' => $product->public_id], 'nokey-0004')->assertForbidden();
        $this->phonePost($nobody, '/api/v1/inventory/lot', ['product' => $product->public_id], 'nokey-0005')->assertForbidden();

        $this->assertOnHand($product, '10', '⛔ চাবি ছাড়া মাল নড়ল।');
    }

    public function test_a_warehouse_in_another_branch_is_not_found_and_nothing_moves(): void
    {
        $product = $this->stocked('10');
        $there = Warehouse::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'WH-NTK')->firstOrFail();
        $this->assertNotSame((int) $this->warehouse->branch_id, (int) $there->branch_id, 'প্রস্তুতিটাই ভুল — দুই গুদাম এক শাখার।');

        $this->phonePost($this->owner, '/api/v1/inventory/issue', ['warehouse' => $there->public_id] + $this->issueBody($product, '1'), 'branch-key-0001')
            ->assertStatus(422)->assertJsonValidationErrors('warehouse');
        $this->phonePost($this->owner, '/api/v1/inventory/opening', [
            'product' => $product->public_id, 'warehouse' => $there->public_id, 'qty' => '5', 'unit_cost' => '10',
        ], 'branch-key-0002')->assertStatus(422)->assertJsonValidationErrors('warehouse');

        $this->assertSame(0, StockMovement::query()->withoutGlobalScopes()->where('warehouse_id', $there->id)->where('product_id', $product->id)->count(),
            '⛔ অন্য শাখার গুদামে মাল নড়ল।');
    }

    public function test_a_count_without_the_approve_key_waits_and_the_approver_settles_it_into_both_books(): void
    {
        $product = $this->stocked('20');
        $counter = $this->staff(['inventory.stock.adjust']);

        $waiting = $this->phonePost($counter, '/api/v1/inventory/adjust', $this->adjustBody($product, '17'), 'count-key-0001')->assertStatus(202);
        $this->assertSame('waiting', $waiting->json('status'));
        $count = StockCount::query()->where('public_id', $waiting->json('id'))->firstOrFail();
        $this->assertSame(DocumentStatus::DRAFT, $count->status, '⛔ মেনে নেওয়ার চাবি ছাড়া গণনা নিজেই মেনে নেওয়া হলো।');
        $this->assertOnHand($product, '20', '⛔ মেনে নেওয়ার আগেই মাল কমল।');

        $this->assertContains($count->public_id, array_column($this->phoneGet($this->owner, '/api/v1/inventory/counts')->assertOk()->json('rows'), 'id'));
        $this->phonePost($counter, '/api/v1/inventory/counts/'.$count->public_id.'/approve', ['reason' => $this->adjustmentReason()->public_id], 'count-key-0002')
            ->assertForbidden();

        $inventoryBefore = $this->netOn(StandardChart::INVENTORY);
        $this->phonePost($this->owner, '/api/v1/inventory/counts/'.$count->public_id.'/approve', ['reason' => $this->adjustmentReason()->public_id], 'count-key-0003')
            ->assertCreated()->assertJsonPath('status', 'done');

        $this->assertOnHand($product, '17', '⛔ মেনে নেওয়ার পরে মাল গোনার সমান হয়নি।');
        $this->assertSame(0, bccomp(bcsub($inventoryBefore, $this->netOn(StandardChart::INVENTORY), 4), '30', 4),
            '⛔ ৩টা × ১০ টাকা ঘাটতি মজুদের খাতা থেকে কমেনি — দুই খাতা মেলে না।');
    }

    public function test_with_a_flow_the_issue_waits_for_its_signature_and_moves_nothing(): void
    {
        app(ApprovalFlowService::class)->create(
            ['module' => 'inventory', 'action' => 'issue', 'is_active' => true],
            [['level' => 1, 'approver_type' => ApprovalFlowStep::BY_USER,
                'approver_id' => User::query()->where('email', 'accounts@abos.test')->value('id'), 'requires_all' => false]],
        );
        $product = $this->stocked('40');

        $this->phonePost($this->owner, '/api/v1/inventory/issue', $this->issueBody($product, '5'), 'held-key-0001')
            ->assertStatus(202)->assertJsonPath('status', 'held');

        $this->assertOnHand($product, '40', '⛔ সইয়ের আগেই ফোন থেকে মাল বেরোল।');
    }

    public function test_opening_stock_lands_once_in_the_stock_book_and_the_accounts(): void
    {
        $product = $this->product('OP');
        $body = ['product' => $product->public_id, 'warehouse' => $this->warehouse->public_id, 'qty' => '12', 'unit_cost' => '10'];

        $inventoryBefore = $this->netOn(StandardChart::INVENTORY);
        $this->phonePost($this->owner, '/api/v1/inventory/opening', $body, 'open-key-0001')->assertCreated();
        $this->phonePost($this->owner, '/api/v1/inventory/opening', $body, 'open-key-0001')->assertCreated();

        $this->assertOnHand($product, '12', '⛔ খোলা মজুদ একবার বসেনি।');
        $this->assertSame(1, StockMovement::query()->where('product_id', $product->id)->where('source_type', 'opening')->count(),
            '⛔ একই চাবিতে খোলা মজুদ দুবার।');
        $this->assertSame(0, bccomp(bcsub($this->netOn(StandardChart::INVENTORY), $inventoryBefore, 4), '120', 4),
            '⛔ ১২ × ১০ টাকা মজুদের খাতায় একবার বসেনি — দুই খাতা মেলে না।');
    }

    public function test_goods_waiting_are_put_away_through_the_webs_own_path(): void
    {
        $product = $this->product('PW');
        app(StockService::class)->move(product: $product, warehouse: $this->warehouse, sourceType: 'purchase_bill', sourceId: 4343,
            documentNo: 'PBL-4343', unplaced: '10');

        $papers = $this->phoneGet($this->owner, '/api/v1/inventory/placement')->assertOk()->json('papers');
        $line = collect($papers)->flatMap(fn (array $p) => $p['lines'])->first(fn (array $l) => str_contains((string) $l['product'], $product->code));
        $this->assertNotNull($line, '⛔ বসানোর অপেক্ষার মাল ফোনের তালিকায় নেই।');
        $this->assertArrayNotHasKey('source_id', $line, '⛔ ক্রমিক id ফোনে গেল।');

        $this->phonePost($this->owner, '/api/v1/inventory/placement', ['lines' => [['ref' => $line['ref'], 'qty' => '6']]], 'place-key-0001')
            ->assertCreated()->assertJsonPath('placed', 1);
        $this->assertOnHand($product, '6', '⛔ বসানো মাল তাকে ওঠেনি।');

        $this->phonePost($this->owner, '/api/v1/inventory/placement', ['lines' => [['ref' => $line['ref'], 'qty' => '9']]], 'place-key-0002')
            ->assertStatus(422);
        $this->assertOnHand($product, '6', '⛔ অপেক্ষার চেয়ে বেশি বসল।');

        $this->phonePost($this->owner, '/api/v1/inventory/placement', ['lines' => [['ref' => 'not-a-real-ref', 'qty' => '1']]], 'place-key-0003')
            ->assertStatus(422)->assertJsonValidationErrors('lines.0.ref');
    }

    public function test_untracked_goods_get_a_lot_through_the_webs_own_path(): void
    {
        $product = $this->product('LT', ['track_batch' => true]);
        app(StockService::class)->move(product: $product, warehouse: $this->warehouse, sourceType: 'test.opening', sourceId: $product->id, floor: '8');

        $this->phonePost($this->owner, '/api/v1/inventory/lot', [
            'product' => $product->public_id, 'warehouse' => $this->warehouse->public_id, 'batch_no' => 'L-PH-1', 'qty' => '5',
        ], 'lot-key-0001')->assertCreated();

        $batch = Batch::query()->withoutGlobalScopes()->where('product_id', $product->id)->where('batch_no', 'L-PH-1')->firstOrFail();
        $this->assertSame(0, bccomp((string) StockMovement::query()->where('product_id', $product->id)->where('batch_id', $batch->id)->sum('floor_change'), '5', 4),
            '⛔ লটে ৫টা ওঠেনি।');
        $this->assertOnHand($product, '8', '⛔ লট দিতে গিয়ে মোট মাল বদলাল।');
    }

    /** ⛔ গণনাকারী মেনে নেওয়ার চাবি পেলেও নিজের গণনা নিজে মানেন না — ফোনেও অন্য হাত (maker-checker; a5-এর পর্যালোচনা, fe, ১৭ অক্টোবর ২০২৬) */
    public function test_the_counter_with_the_approve_key_still_waits_for_another_hand(): void
    {
        $product = $this->stocked('20');
        $counter = $this->staff(['inventory.stock.adjust', 'inventory.count.approve']);
        $approver = $this->staff(['inventory.count.approve']);

        $waiting = $this->phonePost($counter, '/api/v1/inventory/adjust', $this->adjustBody($product, '18'), 'self-key-0001')->assertStatus(202);
        $this->assertSame('waiting', $waiting->json('status'), '⛔ চাবি থাকায় নিজের গণনা নিজেই মেনে নেওয়া হলো।');
        $this->phonePost($counter, '/api/v1/inventory/counts/'.$waiting->json('id').'/approve', ['reason' => $this->adjustmentReason()->public_id], 'self-key-0002')
            ->assertForbidden();
        $this->assertOnHand($product, '20', '⛔ যিনি গুনলেন তাঁর মেনে নেওয়ায় মাল কমল।');

        $this->phonePost($approver, '/api/v1/inventory/counts/'.$waiting->json('id').'/approve', ['reason' => $this->adjustmentReason()->public_id], 'self-key-0003')
            ->assertCreated();
        $this->assertOnHand($product, '18', '⛔ অন্যজন মানার পরেও মাল গোনার সমান হয়নি।');
    }

    /** ⛔ "1e3" পরিমাণে ৪২২, ৫০০ নয়; চাবি ছাড়া নতুন লেখা ৪২৮ — মাল নড়ে না */
    public function test_a_scientific_quantity_and_a_keyless_write_are_refused_and_nothing_moves(): void
    {
        $product = $this->stocked('10');

        $this->phonePost($this->owner, '/api/v1/inventory/issue', $this->issueBody($product, '1e0'), 'sci-key-0101')
            ->assertStatus(422)->assertJsonValidationErrors('qty');
        $this->phonePost($this->owner, '/api/v1/inventory/adjust', $this->adjustBody($product, '9e0'), 'sci-key-0102')
            ->assertStatus(422)->assertJsonValidationErrors('counted');

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->owner->fresh(), [AuthController::APP]);
        $this->flushHeaders();
        $this->postJson('/api/v1/inventory/issue', $this->issueBody($product, '1'))->assertStatus(428);

        $this->assertOnHand($product, '10', '⛔ "1e0" বা চাবি ছাড়া মাল নড়ল।');
    }

    // ── যন্ত্রপাতি ──

    private function issueBody(Product $product, string $qty): array
    {
        return ['product' => $product->public_id, 'warehouse' => $this->warehouse->public_id,
            'reason' => $this->issueReason()->public_id, 'qty' => $qty, 'trx_date' => now()->toDateString()];
    }

    private function adjustBody(Product $product, string $counted): array
    {
        return ['product' => $product->public_id, 'warehouse' => $this->warehouse->public_id,
            'reason' => $this->adjustmentReason()->public_id, 'counted' => $counted, 'trx_date' => now()->toDateString()];
    }

    private function issueReason(): ReasonCode
    {
        return ReasonCode::query()->inContext(ReasonCode::STOCK_ISSUE)->active()->whereNotNull('account_id')->orderBy('id')->firstOrFail();
    }

    private function adjustmentReason(): ReasonCode
    {
        return ReasonCode::query()->inContext(ReasonCode::STOCK_ADJUSTMENT)->active()->orderBy('id')->firstOrFail();
    }

    private function product(string $tag, array $extra = []): Product
    {
        return Product::query()->create($extra + [
            'code' => $tag.'-'.mb_substr(md5(microtime()), 0, 8),
            'name_en' => 'Phone stock probe '.$tag,
            'name_bn' => 'ফোনের মজুদ-নমুনা '.$tag,
            'unit_id' => Unit::query()->orderBy('id')->firstOrFail()->id,
            'is_active' => true,
        ]);
    }

    private function stocked(string $qty): Product
    {
        $product = $this->product('ST');
        app(StockService::class)->move(product: $product, warehouse: $this->warehouse, sourceType: 'test.opening', sourceId: $product->id, floor: $qty);
        app(CostLayerService::class)->receive(product: $product, qty: $qty, unitCost: '10.00', sourceType: 'test.opening', sourceId: $product->id);

        return $product;
    }

    private function assertOnHand(Product $product, string $expected, string $why): void
    {
        $this->assertSame(0, bccomp(app(StockService::class)->floorQty($product, $this->warehouse), $expected, 4), $why);
    }

    private function debitsOn(?int $accountId): string
    {
        return (string) LedgerEntry::query()->where('account_id', $accountId)->selectRaw('COALESCE(SUM(debit), 0) as n')->value('n');
    }

    /** খাতের নিট (ডেবিট − ক্রেডিট), নিচের সব খাতসহ */
    private function netOn(string $code): string
    {
        $ids = StandardChart::find($code)?->selfAndDescendants()->pluck('id')->all() ?? [0];

        return (string) LedgerEntry::query()->whereIn('account_id', $ids)->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as n')->value('n');
    }

    /** @param  list<string>  $keys */
    private function staff(array $keys): User
    {
        $user = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id, 'current_branch_id' => $this->warehouse->branch_id]);
        $user->companies()->attach($this->company, ['is_active' => true]);

        if ($keys !== []) {
            CompanyContext::forCompany((int) $this->company->id, fn () => $user->givePermissionTo(
                ...array_map(fn (string $k) => Permission::findOrCreate($k, 'web'), $keys)));
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        return $user->fresh();
    }

    private function phoneGet(User $user, string $url): TestResponse
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh(), [AuthController::APP]);

        return $this->getJson($url);
    }

    private function phonePost(User $user, string $url, array $body, string $key): TestResponse
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh(), [AuthController::APP]);

        return $this->withHeader('Idempotency-Key', $key)->postJson($url, $body);
    }
}
