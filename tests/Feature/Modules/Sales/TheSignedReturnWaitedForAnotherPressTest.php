<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Services\DirectSaleService;
use App\Modules\Sales\Services\SalesReturnService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * সই-করা ফেরত আরেকটা চাপের অপেক্ষায় পড়ে থাকত — মালিকের বিক্রয় পরিকল্পনা §৬, ৬ অক্টোবর ২০২৬: "ফেরতের আদেশ → মাল গ্রহণ
 * (সেই লটে) → ক্রেডিট নোট"; fe: একই কাগজের তিন অবস্থা, খসড়া → অনুমোদন → গ্রহণ।
 *
 * ⛔ সইয়ের ছক থাকলে ফেরত খসড়ায় থামত, আর শেষ সইয়ের পরেও খসড়াই থাকত — কেউ আবার "নিশ্চিত" না চাপলে মাল খাতার বাইরে।
 * ⭐ এখন শেষ সইয়ে নিজে পাকা (মাল ফেরে, পাওনা কমে), প্রত্যাখ্যানে বাতিল ([[FinishTheReturnOnTheLastSignature]]), আর অপেক্ষার
 * সময় পাতা বলে "সইয়ের অপেক্ষায়"। ছক না থাকলে (UB) আগের মতো এক চাপে।
 */
final class TheSignedReturnWaitedForAnotherPressTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Product $biscuit;

    private Warehouse $warehouse;

    private int $customerId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        $this->biscuit = Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->customerId = (int) Customer::query()->where('name_en', 'Rahim Traders')->value('id');
    }

    public function test_the_last_signature_confirms_it_and_the_goods_come_back(): void
    {
        $this->signatureFlow();
        $return = $this->returnDraft();
        $before = $this->onHand();

        $this->assertHeld(fn () => app(SalesReturnService::class)->confirm($return));
        $this->assertSame(DocumentStatus::DRAFT, $return->fresh()->status);
        $this->assertSame(0, bccomp($before, $this->onHand(), 4), '⛔ সইয়ের আগেই মাল ফিরল।');

        $this->get(route('sales.return.show', $return))->assertOk()->assertSee('data-return-awaiting', false);

        app(ApprovalEngine::class)->approve($this->pending($return), $this->owner, 'ঠিক আছে');

        $this->assertSame(DocumentStatus::CONFIRMED, $return->fresh()->status, '⛔ শেষ সইয়ের পরেও ফেরত খসড়া।');
        $this->assertSame(0, bccomp(bcadd($before, '1', 4), $this->onHand(), 4), '⛔ শেষ সইয়ে মাল ফেরেনি।');
        $this->assertTrue(DB::table('ledger_entries')->where('source_type', 'sales_return')->where('source_id', $return->id)->exists(), '⛔ খাতায় ফেরত বসেনি।');
        $this->get(route('sales.return.show', $return))->assertOk()->assertDontSee('data-return-awaiting', false);
    }

    public function test_a_refused_signature_cancels_the_draft_and_nothing_moves(): void
    {
        $this->signatureFlow();
        $return = $this->returnDraft();
        $before = $this->onHand();

        $this->assertHeld(fn () => app(SalesReturnService::class)->confirm($return));
        app(ApprovalEngine::class)->reject($this->pending($return), $this->owner, 'ফেরত নয়');

        $this->assertSame(DocumentStatus::CANCELLED, $return->fresh()->status, '⛔ প্রত্যাখ্যানের পরেও ফেরত খোলা।');
        $this->assertSame(0, bccomp($before, $this->onHand(), 4), '⛔ প্রত্যাখ্যাত ফেরতে মাল ফিরল।');
        $this->assertFalse(DB::table('ledger_entries')->where('source_type', 'sales_return')->where('source_id', $return->id)->exists());
    }

    public function test_without_a_flow_one_press_still_does_it(): void
    {
        $return = $this->returnDraft();

        app(SalesReturnService::class)->confirm($return);

        $this->assertSame(DocumentStatus::CONFIRMED, $return->fresh()->status);
        $this->get(route('sales.return.show', $return))->assertOk()->assertDontSee('data-return-awaiting', false);
    }

    private function returnDraft(): SalesReturn
    {
        $sale = app(DirectSaleService::class)->complete(
            ['customer_id' => $this->customerId, 'warehouse_id' => $this->warehouse->id, 'own_transport' => '1'],
            [['product_id' => $this->biscuit->id, 'qty' => '3', 'rate' => '10', 'free_qty' => '0']],
        )['invoice']->fresh();

        return app(SalesReturnService::class)->create(
            ['customer_id' => $this->customerId, 'warehouse_id' => $this->warehouse->id, 'sales_invoice_id' => $sale->id,
                'trx_date' => now()->toDateString(),
                'reason_code_id' => ReasonCode::query()->inContext(ReasonCode::SALES_RETURN)->where('code', 'DAMAGE')->firstOrFail()->id],
            [['product_id' => $this->biscuit->id, 'sales_invoice_line_id' => $sale->lines()->firstOrFail()->id, 'qty' => '1', 'rate' => '10']],
        );
    }

    /** প্রতিষ্ঠানের বসানো সইয়ের ছক — এক ধাপ, সুপার অ্যাডমিন */
    private function signatureFlow(): void
    {
        $owner = \Spatie\Permission\Models\Role::query()->where('company_id', $this->company->id)
            ->where('name', \App\Core\Services\PermissionSyncer::SUPER_ADMIN_ROLE)->firstOrFail();
        $flow = ApprovalFlow::query()->create([
            'company_id' => $this->company->id, 'module' => 'sales', 'action' => 'return',
            'document_type' => '', 'threshold_amount' => null, 'is_active' => true,
        ]);
        \App\Models\ApprovalFlowStep::query()->create([
            'approval_flow_id' => $flow->id, 'level' => 1, 'step_name' => 'owner',
            'approver_type' => \App\Models\ApprovalFlowStep::BY_ROLE, 'approver_id' => (int) $owner->id,
        ]);
    }

    private function pending(SalesReturn $return): Approval
    {
        return Approval::query()->where('approvable_type', SalesReturn::class)->where('approvable_id', $return->id)
            ->where('status', Approval::PENDING)->firstOrFail();
    }

    private function assertHeld(\Closure $act): void
    {
        try {
            $act();
            $this->fail('⛔ ছক থাকা সত্ত্বেও ফেরত সই ছাড়াই পাকা হলো।');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
    }

    private function onHand(): string
    {
        return (string) DB::table('inv_stock_movements')
            ->where('product_id', $this->biscuit->id)->where('warehouse_id', $this->warehouse->id)->sum('floor_change');
    }
}
