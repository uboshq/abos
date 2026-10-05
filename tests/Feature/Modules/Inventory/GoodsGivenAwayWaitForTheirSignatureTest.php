<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Module\ModuleRegistry;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\LedgerEntry;
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
 * গ৫ — "মাল বের করা" সইয়ের ধারায় (Inventory অডিট, ৪ অক্টোবর ২০২৬)।
 *
 * ⛔ আগে আপ্যায়ন, উপহার বা মালিকের ব্যবহারে মাল বের করলে সাথে সাথে খরচের খাতে টাকা উঠত, কোনো সই ছাড়া — একজন
 * গুদামের লোক তাকের সব মাল "উপহার" দেখিয়ে খাতা থেকে বের করে দিতে পারতেন। মালিকের নিয়ম: যেকোনো টাকা, যেকোনো অঙ্কে সই।
 */
final class GoodsGivenAwayWaitForTheirSignatureTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private User $owner;

    private User $signer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->signer = User::query()->where('email', 'accounts@abos.test')->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->actingAs($this->owner);
    }

    public function test_the_give_out_is_on_the_signature_list_and_counts_as_money(): void
    {
        $inventory = app(ModuleRegistry::class)->get('inventory');

        $this->assertArrayHasKey('issue', $inventory->approvals, '⛔ মাল বের করা অনুমোদনের তালিকায় নেই — ছক বসানোই যায় না।');
        $this->assertContains('issue', $inventory->movesMoney, '⛔ মাল বের করা টাকার কাজ হিসেবে ঘোষিত নয় — একসাথে সইয়ে ঢুকে যাবে।');
        $this->assertNotSame('inventory::approval.issue', __('inventory::approval.issue'));
    }

    /** ⓘ ছক বন্ধে আজকের মতোই — সাথে সাথে তাক থেকে, কারণের খাতে */
    public function test_without_a_flow_the_goods_go_out_at_once_as_today(): void
    {
        $product = $this->stocked('40');
        $before = $this->debitsOn($this->issueReason()->account_id);

        $this->from(route('inventory.stock.issue'))
            ->post(route('inventory.stock.issue.store'), $this->giveOut($product, '5'))
            ->assertSessionHasNoErrors();

        $this->assertOnHand($product, '35', '⛔ ছক বন্ধে মাল তাক থেকে বেরোয়নি।');
        $this->assertSame(0, bccomp(bcsub($this->debitsOn($this->issueReason()->account_id), $before, 4), '50', 4),
            '⛔ ৫টা × ১০ টাকা কারণের খাতে বসেনি।');

        $paper = StockCount::query()->latest('id')->firstOrFail();
        $this->assertTrue($paper->isIssue());
        $this->assertSame(DocumentStatus::CONFIRMED, $paper->status);
        $this->assertSame((int) $this->issueReason()->id, (int) $paper->reason_code_id);
    }

    public function test_with_a_flow_the_goods_wait_and_go_out_by_themselves_on_the_last_signature(): void
    {
        $this->issueFlow();
        $product = $this->stocked('40');
        $before = $this->debitsOn($this->issueReason()->account_id);

        $this->from(route('inventory.stock.issue'))
            ->post(route('inventory.stock.issue.store'), $this->giveOut($product, '5'))
            ->assertSessionHasNoErrors();

        $paper = StockCount::query()->latest('id')->firstOrFail();
        $this->assertStringContainsString($paper->document_no, (string) session('saved'));
        $this->assertSame(DocumentStatus::DRAFT, $paper->status);
        $this->assertOnHand($product, '40', '⛔ সইয়ের আগেই মাল তাক থেকে বেরোল।');
        $this->assertSame(0, bccomp($this->debitsOn($this->issueReason()->account_id), $before, 4), '⛔ সইয়ের আগেই খাতায় টাকা উঠল।');

        $approval = $this->approvalOf($paper);
        $this->assertSame(Approval::PENDING, $approval->status);
        $this->assertSame(0, bccomp((string) $approval->amount, '50', 4), 'সইকারী যে অঙ্ক দেখেন তা মালের দাম নয়।');

        app(ApprovalEngine::class)->approve($approval, $this->signer);

        $this->assertSame(DocumentStatus::CONFIRMED, $paper->fresh()->status, '⛔ শেষ সইয়ের পরে কাগজটা নিজে শেষ হয়নি।');
        $this->assertOnHand($product, '35', '⛔ শেষ সইয়ের পরে মাল বেরোয়নি।');
        $this->assertSame(0, bccomp(bcsub($this->debitsOn($this->issueReason()->account_id), $before, 4), '50', 4),
            '⛔ শেষ সইয়ের পরে টাকা কাগজের কারণের খাতে বসেনি।');
    }

    public function test_pressing_again_while_waiting_makes_no_second_paper(): void
    {
        $this->issueFlow();
        $product = $this->stocked('40');

        [$first] = app(StockCountService::class)->issue($product, $this->warehouse, '5', $this->issueReason());

        $this->assertRefused(fn () => app(StockCountService::class)->issue($product, $this->warehouse, '5', $this->issueReason()),
            'qty', $first->document_no, '⛔ সইয়ের অপেক্ষায় থাকতেই একই মালের দ্বিতীয় বের-করা কাগজ হলো।');

        // ⓘ ফেরত দিলে খসড়া থাকে; লেখক বাতিল করেন — কিছুই নড়েনি, আর নতুন কাগজ লেখা যায়
        app(ApprovalEngine::class)->reject($this->approvalOf($first), $this->signer, 'না');
        $this->assertSame(DocumentStatus::DRAFT, $first->fresh()->status);

        app(StockCountService::class)->cancel($first->fresh(), 'সই পাইনি');
        $this->assertOnHand($product, '40', '⛔ ফেরত আর বাতিলের পরেও মাল নড়েছে।');

        [$second, $held] = app(StockCountService::class)->issue($product, $this->warehouse, '5', $this->issueReason());
        $this->assertTrue($held);
        $this->assertNotSame($first->id, $second->id);
    }

    public function test_a_give_out_paper_cannot_be_accepted_through_the_count_path(): void
    {
        $this->issueFlow();
        $product = $this->stocked('40');
        [$paper] = app(StockCountService::class)->issue($product, $this->warehouse, '5', $this->issueReason());

        $this->assertFalse($this->owner->can('approve', $paper), '⛔ গণনার চাবিতে বের-করার কাগজ মানা যায়।');

        $this->assertRefused(fn () => app(StockCountService::class)->approve($paper, $this->adjustmentReason()),
            'status', $paper->document_no, '⛔ গণনার পথে বের-করার কাগজ সই ছাড়াই, অন্য কারণে মানা গেল।');

        $this->get(route('inventory.count.show', $paper))->assertOk()
            ->assertSee($this->issueReason()->label())
            ->assertDontSee(__('inventory::action.settle_count'));

        $this->assertOnHand($product, '40', '⛔ গণনার পথে মাল বেরিয়ে গেল।');
    }

    /** ⓘ গণনার ছক বের করাকে আটকায় না, বের করার ছক গণনাকে নয় — দুই আলাদা চাবি */
    public function test_a_count_flow_does_not_hold_a_give_out(): void
    {
        app(ApprovalFlowService::class)->create(
            ['module' => 'inventory', 'action' => 'count', 'is_active' => true],
            [['level' => 1, 'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $this->signer->id, 'requires_all' => false]],
        );
        $product = $this->stocked('40');

        [$paper, $held] = app(StockCountService::class)->issue($product, $this->warehouse, '5', $this->issueReason());

        $this->assertFalse($held, '⛔ বের করা গণনার চাবিতে সই চাইল।');
        $this->assertSame(DocumentStatus::CONFIRMED, $paper->status);
        $this->assertOnHand($product, '35', 'বের করা হয়নি।');
    }

    /**
     * ⓘ বের করা সত্যিকারের চলাচল, খাতার সংখ্যার ছবি নয় — তাই গণনার মাঝে বের করলে গণনা বাসি হয় না।
     * তাকে ৪০, গোনা ৩৮ (২ হারানো), তারপর ৫ উপহার → মেনে নিলে ৩৩।
     */
    public function test_a_give_out_between_counting_and_accepting_leaves_the_count_valid(): void
    {
        $product = $this->stocked('40');
        $count = app(StockCountService::class)->record(['warehouse_id' => $this->warehouse->id],
            [['product_id' => $product->id, 'counted_qty' => '38']]);

        $this->travel(5)->seconds();
        app(StockCountService::class)->issue($product, $this->warehouse, '5', $this->issueReason());
        $this->travel(5)->seconds();

        app(StockCountService::class)->approve($count->fresh(), $this->adjustmentReason());

        $this->assertOnHand($product, '33', '⛔ বের করার পরে গণনাটা বাসি ধরা হলো, বা ঘাটতি ভুল বসল।');
    }

    public function test_only_a_give_out_reason_and_never_more_than_the_shelf(): void
    {
        $product = $this->stocked('40');

        $this->assertRefused(fn () => app(StockCountService::class)->issue($product, $this->warehouse, '5', $this->adjustmentReason()),
            'reason_code_id', null, '⛔ গণনার কারণে মাল বের করা গেল।');

        $this->assertRefused(fn () => app(StockCountService::class)->issue($product, $this->warehouse, '41', $this->issueReason()),
            'qty', null, '⛔ তাকের চেয়ে বেশি মাল বের করা গেল।');

        $this->assertRefused(fn () => app(StockCountService::class)->issue($product, $this->warehouse, '0', $this->issueReason()),
            'qty', null, '⛔ শূন্য মাল বের করার কাগজ হলো।');

        $this->assertOnHand($product, '40', 'কিছু বেরিয়ে গেছে।');
        $this->assertSame(0, StockCount::query()->where('kind', StockCount::KIND_ISSUE)->count(), 'বাতিল চেষ্টায় কাগজ রয়ে গেছে।');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function issueFlow(): void
    {
        app(ApprovalFlowService::class)->create(
            ['module' => 'inventory', 'action' => 'issue', 'is_active' => true],
            [['level' => 1, 'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $this->signer->id, 'requires_all' => false]],
        );
    }

    private function approvalOf(StockCount $paper): Approval
    {
        return Approval::query()->where('approvable_type', $paper->getMorphClass())->where('approvable_id', $paper->id)
            ->latest('id')->firstOrFail();
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
    private function giveOut(Product $product, string $qty): array
    {
        return [
            'product_id' => $product->id,
            'warehouse_id' => $this->warehouse->id,
            'reason_code_id' => $this->issueReason()->id,
            'qty' => $qty,
            'trx_date' => now()->toDateString(),
        ];
    }

    private function debitsOn(?int $accountId): string
    {
        return (string) LedgerEntry::query()->where('account_id', $accountId)
            ->selectRaw('COALESCE(SUM(debit), 0) as n')->value('n');
    }

    private function assertOnHand(Product $product, string $expected, string $why): void
    {
        $this->assertSame(0, bccomp(app(StockService::class)->floorQty($product, $this->warehouse), $expected, 4), $why);
    }

    private function issueReason(): ReasonCode
    {
        return ReasonCode::query()->inContext(ReasonCode::STOCK_ISSUE)->active()->whereNotNull('account_id')->orderBy('id')->firstOrFail();
    }

    private function adjustmentReason(): ReasonCode
    {
        return ReasonCode::query()->inContext(ReasonCode::STOCK_ADJUSTMENT)->active()->orderBy('id')->firstOrFail();
    }

    private function stocked(string $qty): Product
    {
        $product = Product::query()->create([
            'code' => 'G5-'.mb_substr(md5(microtime()), 0, 8),
            'name_en' => 'Give-out probe',
            'name_bn' => 'বের-করার নমুনা',
            'unit_id' => Unit::query()->orderBy('id')->firstOrFail()->id,
            'is_active' => true,
        ]);

        app(StockService::class)->move(product: $product, warehouse: $this->warehouse,
            sourceType: 'test.opening', sourceId: $product->id, floor: $qty);
        app(CostLayerService::class)->receive(product: $product, qty: $qty, unitCost: '10.00',
            sourceType: 'test.opening', sourceId: $product->id);

        return $product;
    }
}
