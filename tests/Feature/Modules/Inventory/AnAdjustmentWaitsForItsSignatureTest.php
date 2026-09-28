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
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ মজুদ সমন্বয় সইয়ের অপেক্ষা করে — অডিট §১১, ২৭ সেপ্টেম্বর ২০২৬।
 *
 * ── কী বলেছিল অডিট ───────────────────────────────────────────────────
 * *"মজুদ সমন্বয় খাতায় পোস্ট হয় বিনা অনুমোদনে"* আর *"মডিউলের অনুমোদন
 * তালিকায় যোগ করা"*। ⓘ মেপে দেখা গেল পর্দার পথটা ১৮ সেপ্টেম্বর থেকেই
 * `inventory.count` চাবিতে সই চায় — কিন্তু ⚠️ module.php-র মন্তব্য তখনও
 * বলত *"সমন্বয় ইচ্ছাকৃতভাবে বাইরে"*, তালিকায় নামটা বলত কেবল *"গণনার
 * পার্থক্য"*, আর `moves_money` বলত এই মডিউলে টাকা নড়ে না — ⛔ অথচ মেনে
 * নেওয়ার মুহূর্তেই খতিয়ানে Dr ঘাটতি / Cr মজুদ বসে।
 *
 * ── ⭐ এই ফাইলের তিন দাবি ─────────────────────────────────────────────
 *   ১. তালিকায় নামটা সমন্বয়কেও বলে, আর কাজটা টাকার কাজ হিসেবে ঘোষিত
 *   ২. ছক থাকলে সমন্বয়ের পর্দা থেমে যায় — মাল নড়ে না, খাতা নড়ে না;
 *      সইয়ের পর মেনে নিলে তবেই দুইটা নড়ে
 *   ৩. ছক না থাকলে **আজকের** ইঞ্জিনে সাথে সাথে হয়
 *
 * ⚠️ তিন নম্বরটা একটা **মেয়াদি দাবি** — নিচে কারণসহ।
 */
final class AnAdjustmentWaitsForItsSignatureTest extends TestCase
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

    /**
     * ⭐ ছক সাজানোর তালিকায় সমন্বয়ের নাম আছে, আর সেটা টাকার কাজ।
     *
     * ⓘ টাকার কাজ মানে দুইটা জিনিস: একসাথে সই দেওয়া যায় না
     * ([[BulkApproval]]), আর [[MoneyFlowDefaults]] প্রতিটা কোম্পানিতে
     * এর জন্য হিসাবরক্ষকের ছক বসায়।
     */
    public function test_the_adjustment_is_on_the_list_and_counts_as_money(): void
    {
        $inventory = app(ModuleRegistry::class)->get('inventory');

        $this->assertNotNull($inventory);
        $this->assertArrayHasKey('count', $inventory->approvals,
            'মজুদের গণনা/সমন্বয় অনুমোদনের তালিকা থেকে উধাও।');
        $this->assertContains('count', $inventory->movesMoney,
            'সমন্বয় খতিয়ানে বসে, অথচ টাকার কাজ হিসেবে ঘোষিত নয় — একসাথে সইয়ে ঢুকে যাবে।');

        $this->assertStringContainsStringIgnoringCase('adjustment',
            (string) __('inventory::approval.count', [], 'en'),
            'তালিকার নাম কেবল গণনা বলে — ছক সাজানোর মানুষ বুঝবেন না এটাই সমন্বয় আটকায়।');
    }

    /**
     * ⛔ ছক থাকলে সমন্বয় থামে — মালও নড়ে না, খাতাও না।
     *
     * ⓘ তারপর সইকারী সই দেন, আর মেনে নেওয়ার পর্দা থেকে কাজটা শেষ হয় —
     * ⭐ দুই দিকই মাপা, নাহলে "থামে" দাবিটা এমন পর্দাতেও সবুজ থাকত যেটা
     * কোনোদিন কিছুই করে না।
     */
    public function test_with_a_flow_the_adjustment_waits_for_a_signature(): void
    {
        $product = $this->stocked('সইয়ের অপেক্ষার পণ্য', '40');
        $this->aCountFlowSignedBy($this->signer);
        $ledgerBefore = $this->adjustmentLedgerRows();

        $this->actingAs($this->owner)
            ->from(route('inventory.stock.adjust'))
            ->post(route('inventory.stock.adjust.store'), $this->adjustment($product, '31'))
            ->assertSessionHasErrors('status');

        $this->assertOnHand($product, '40', 'ছক বসানো, তবু সমন্বয় সই ছাড়াই তাক বদলে দিয়েছে।');
        $this->assertSame($ledgerBefore, $this->adjustmentLedgerRows(),
            'ছক বসানো, তবু সমন্বয় সই ছাড়াই খতিয়ানে বসেছে।');

        $count = StockCount::query()->latest('id')->firstOrFail();
        $this->assertSame(DocumentStatus::DRAFT, $count->status, 'সইয়ের আগেই কাগজটা নিশ্চিত হয়ে গেছে।');

        $approval = Approval::query()
            ->where('approvable_type', $count->getMorphClass())
            ->where('approvable_id', $count->id)
            ->sole();

        $this->assertSame(Approval::PENDING, $approval->status);
        $this->assertSame('inventory', $approval->module);
        $this->assertSame('count', $approval->action);

        // ── সই, তারপর মেনে নেওয়া ─────────────────────────────────────
        app(ApprovalEngine::class)->approve($approval->fresh(), $this->signer);

        $this->actingAs($this->owner)
            ->from(route('inventory.count.show', $count))
            ->post(route('inventory.count.approve', $count), ['reason_code_id' => $this->aReason()->id])
            ->assertSessionHasNoErrors();

        $this->assertSame(DocumentStatus::CONFIRMED, $count->fresh()->status);
        $this->assertOnHand($product, '31', 'সই পাওয়ার পরেও তাক আগের সংখ্যাই বলছে।');
        $this->assertSame($ledgerBefore + 2, $this->adjustmentLedgerRows(),
            'সই পাওয়ার পরে খতিয়ানে ঘাটতির দুই সারি বসেনি।');
    }

    /**
     * ⓘ ছক না থাকলে আজকের ইঞ্জিনে সমন্বয় সাথে সাথে হয়।
     *
     * ── ⚠️ মেয়াদি দাবি — অডিট §১.৩ ধাপ ২-এর দিনে উল্টাবে ──────────────
     * আজ [[ApprovalEngine::request()]] ছক না পেলে `null` ফেরায় — "এগোও"।
     * ⭐ ধাপ ২-এর পর `moves_money`-র কাজে ছক না থাকলে ইঞ্জিন
     * [[\App\Core\Engines\Approval\NoApprovalFlow]] ছুঁড়বে, আর `count` এখন
     * ঐ তালিকায়। ⛔ তখন এই দাবি লাল হবে, আর সেটাই ঠিক — বদলাতে হবে এভাবে:
     *
     *   ক. নাম: `…_is_refused_without_a_flow`; দাবি — `assertSessionHasErrors`
     *      (NoApprovalFlow::FIELD), তাক ৪০-ই, খতিয়ানে নতুন সারি নেই
     *   খ. আর "সই হলে হয়" দিকটা [[SignsMoneyOff]] দিয়ে: `moneyFlowsFor()`
     *      ছক বসায়, `secondSignerIn()` আলাদা একজন হিসাবরক্ষক দেন
     *
     * ⚠️ দাবিটা তখন **মুছে ফেলা নয়, উল্টানো** — নাহলে "ছক নেই তো চলে যায়"
     * পথটা আবার খুললে কোথাও কিছু লাল হত না।
     */
    public function test_without_a_flow_the_adjustment_posts_at_once_today(): void
    {
        $product = $this->stocked('ছকহীন পণ্য', '40');
        $ledgerBefore = $this->adjustmentLedgerRows();

        $this->actingAs($this->owner)
            ->from(route('inventory.stock.adjust'))
            ->post(route('inventory.stock.adjust.store'), $this->adjustment($product, '31'))
            ->assertSessionHasNoErrors();

        $this->assertOnHand($product, '31', 'ছক নেই, তবু সমন্বয় তাক বদলায়নি।');
        $this->assertSame($ledgerBefore + 2, $this->adjustmentLedgerRows(),
            'ছক নেই, তবু সমন্বয় খতিয়ানে বসেনি।');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function adjustment(Product $product, string $counted): array
    {
        return [
            'product_id' => $product->id,
            'warehouse_id' => $this->warehouse->id,
            'reason_code_id' => $this->aReason()->id,
            'counted' => $counted,
            'trx_date' => now()->toDateString(),
        ];
    }

    /** ⓘ `inventory.count` — সমন্বয়ের পর্দা ঠিক এই চাবিতেই সই চায়। */
    private function aCountFlowSignedBy(User $signer): void
    {
        app(ApprovalFlowService::class)->create(
            ['module' => 'inventory', 'action' => 'count', 'is_active' => true],
            [[
                'level' => 1,
                'approver_type' => ApprovalFlowStep::BY_USER,
                'approver_id' => $signer->id,
                'requires_all' => false,
            ]],
        );
    }

    private function adjustmentLedgerRows(): int
    {
        return LedgerEntry::query()->where('source_type', StockService::ADJUSTMENT)->count();
    }

    private function assertOnHand(Product $product, string $expected, string $why): void
    {
        $this->assertSame(0, bccomp(
            app(StockService::class)->floorQty($product, $this->warehouse), $expected, 4,
        ), $why);
    }

    /**
     * ⓘ **সমন্বয়ের** কারণ — ভুল প্রসঙ্গেরটা নিলে পর্দা ৪২২ দিত, আর দাবিটা
     * এমন পথ মাপত যা কেউ কোনোদিন নেয় না।
     */
    private function aReason(): ReasonCode
    {
        return ReasonCode::query()
            ->inContext(ReasonCode::STOCK_ADJUSTMENT)
            ->active()->orderBy('id')->firstOrFail();
    }

    private function stocked(string $label, string $onHand, string $unitCost = '10.00'): Product
    {
        $product = Product::query()->create([
            'code' => 'ADJ-'.mb_substr(md5($label.microtime()), 0, 8),
            'name_en' => $label,
            'name_bn' => $label,
            'unit_id' => Unit::query()->orderBy('id')->firstOrFail()->id,
            'is_active' => true,
        ]);

        app(StockService::class)->move(
            product: $product,
            warehouse: $this->warehouse,
            sourceType: 'test.opening',
            sourceId: $product->id,
            floor: $onHand,
        );

        app(CostLayerService::class)->receive(
            product: $product,
            qty: $onHand,
            unitCost: $unitCost,
            sourceType: 'test.opening',
            sourceId: $product->id,
        );

        return $product;
    }
}
