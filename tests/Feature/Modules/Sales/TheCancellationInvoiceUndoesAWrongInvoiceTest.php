<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\Company;
use App\Models\PeriodLock;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceCancellation;
use App\Modules\Sales\Services\DeliveryStage;
use App\Modules\Sales\Services\DeliveryStageService;
use App\Modules\Sales\Services\DirectSaleService;
use App\Modules\Sales\Services\SalesInvoiceCancellationService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * বাতিল-ইনভয়েস (Cancellation Invoice) — মালিক, ৪ অক্টোবর ২০২৬: আন্তর্জাতিক মান ([[SalesInvoiceCancellationService]])।
 *
 * কাউন্টারে বিস্কুট ২ × ১০ — বিল আর চালান পাকা, গেট পাস হয়নি। ভুল বিল:
 *   · মালিক নিজে দেন → CXL-… সাথে সাথে পাকা; বিল আর চালান বাতিল, মাল তাকে ফেরে, গ্রাহকের দেনা শূন্য, উল্টো সারি CXL নম্বরে,
 *     বিলের আগে-পরে সংশোধনের খাতায়; বিলের পাতায় সূত্র, কাগজ ছাপা যায়।
 *   · কাউন্টারের কর্মী (চাবিসহ) দেন → প্রতিষ্ঠানের সইয়ের ছক থাকলে সইয়ের অপেক্ষা, সই হলে নিজে পাকা; ছক না থাকলে সাথে সাথে।
 *   · গেট পাস হয়ে গেলে, মাস বন্ধ থাকলে, কারণ না দিলে, আগেই একটা থাকলে — ফেরত, কারণসহ।
 */
final class TheCancellationInvoiceUndoesAWrongInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Product $biscuit;

    private Warehouse $warehouse;

    private array $data;

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
        $this->data = [
            'customer_id' => Customer::query()->where('name_en', 'Rahim Traders')->value('id'),
            'warehouse_id' => $this->warehouse->id,
            'own_transport' => '1',
        ];
    }

    public function test_the_owner_issues_it_and_goods_books_and_papers_all_follow(): void
    {
        $before = $this->onHand();
        $sale = $this->sell('2');
        $challan = $this->challanOf($sale);
        $this->assertSame(0, bccomp(bcsub($before, '2', 4), $this->onHand(), 4), 'প্রস্তুতি: মাল বেরিয়েছে।');

        $this->post(route('sales.invoice.cancellation', $sale), ['reason' => 'ভুল গ্রাহকের নামে কাটা'])
            ->assertRedirect()->assertSessionHas('saved');

        $paper = SalesInvoiceCancellation::query()->where('sales_invoice_id', $sale->id)->firstOrFail();
        $this->assertStringStartsWith('CXL-', $paper->document_no, '⛔ নিজের ক্রমে নম্বর নয়।');
        $this->assertSame(DocumentStatus::CONFIRMED, $paper->status);

        $this->assertSame(DocumentStatus::CANCELLED, $sale->fresh()->status, '⛔ আসল ইনভয়েস বাতিল হয়নি।');
        $this->assertSame($sale->document_no, $sale->fresh()->document_no, '⛔ আসল ইনভয়েসের নম্বর বদলেছে।');
        $this->assertSame(DocumentStatus::CANCELLED, $challan->fresh()->status, '⛔ চালান বাতিল হয়নি।');
        $this->assertSame(0, bccomp($before, $this->onHand(), 4), '⛔ মাল তাকে ফেরেনি।');
        $this->assertSame(0, bccomp('0', $this->receivableOf($sale), 4), '⛔ গ্রাহকের দেনা শূন্যে ফেরেনি।');

        $this->assertTrue(DB::table('ledger_entries')->where('source_type', SalesInvoice::drillSourceType().':reversal')
            ->where('source_id', $sale->id)->where('document_no', $paper->document_no)->exists(), '⛔ উল্টো সারি বাতিল-ইনভয়েসের নম্বরে নয়।');
        $this->assertTrue($sale->fresh()->auditTrail()->where('action', 'cancelled_by_cxl')->exists(), '⛔ বিলের অডিটে বাতিল-ইনভয়েস নেই।');

        $this->get(route('sales.invoice.show', $sale))->assertOk()->assertSee($paper->document_no);
        $this->get(route('sales.cancellation.show', $paper))->assertOk()->assertSee($sale->document_no);
        $print = $this->get(route('sales.cancellation.print', $paper))->assertOk();
        $this->assertStringContainsString('pdf', strtolower((string) $print->headers->get('content-type')));
    }

    public function test_after_the_gate_pass_it_is_a_return_not_a_cancellation(): void
    {
        $sale = $this->sell('2');
        app(DeliveryStageService::class)->move($this->challanOf($sale), DeliveryStage::DISPATCHED);

        $this->assertRefusedWith(fn () => $this->request($sale, $this->owner), 'after_gate_pass', $sale);
        $this->assertSame(DocumentStatus::CONFIRMED, $sale->fresh()->status);
    }

    /** ⓘ আগের মাসের বিল, সেই মাস বন্ধ — এ মাস খোলা থাকলেও ফেরত (খাতার ইঞ্জিন কেবল আজকের মাস দেখত) */
    public function test_a_closed_month_refuses_and_nothing_is_left_behind(): void
    {
        $this->travel(-1)->months();
        $sale = $this->sell('2');
        $this->travelBack();
        PeriodLock::query()->create([
            'company_id' => $sale->company_id, 'year' => (int) $sale->trx_date->year, 'month' => (int) $sale->trx_date->month,
            'reason' => 'মাস বন্ধ', 'locked_by' => $this->owner->id, 'locked_at' => now(),
        ]);

        try {
            $this->request($sale, $this->owner);
            $this->fail('⛔ বন্ধ মাসে বাতিল-ইনভয়েস হয়ে গেল।');
        } catch (ValidationException) {
        }

        $this->assertSame(DocumentStatus::CONFIRMED, $sale->fresh()->status);
        $this->assertSame(0, SalesInvoiceCancellation::query()->count(), '⛔ থামার পরেও কাগজ রয়ে গেছে।');
    }

    public function test_staff_with_the_key_waits_for_the_owner_and_the_signature_finishes_it(): void
    {
        $this->signatureFlow();
        $sale = $this->sell('2');
        $clerk = $this->clerk(['sales.invoice.view', 'sales.invoice.cancellation']);

        $this->actingAs($clerk)->post(route('sales.invoice.cancellation', $sale), ['reason' => 'দর ভুল'])->assertRedirect();

        $paper = SalesInvoiceCancellation::query()->where('sales_invoice_id', $sale->id)->firstOrFail();
        $this->assertSame(SalesInvoiceCancellation::AWAITING, $paper->status);
        $this->assertSame(DocumentStatus::CONFIRMED, $sale->fresh()->status, '⛔ সইয়ের আগেই বিল উল্টে গেছে।');

        $approval = app(ApprovalEngine::class)->latestFor($paper, SalesInvoiceCancellationService::APPROVAL_ACTION);
        $this->assertNotNull($approval, '⛔ মালিকের সই চাওয়া হয়নি।');

        // ⛔ সইয়ের আগে কর্মী নিজে পাকা করতে পারেন না
        try {
            app(SalesInvoiceCancellationService::class)->confirm($paper, $clerk);
            $this->fail('⛔ সই ছাড়াই কর্মীর হাতে পাকা হয়ে গেল।');
        } catch (ValidationException) {
        }
        $this->assertSame(DocumentStatus::CONFIRMED, $sale->fresh()->status);

        $this->actingAs($this->owner);
        app(ApprovalEngine::class)->approve($approval, $this->owner, 'ঠিক আছে');

        $this->assertSame(DocumentStatus::CONFIRMED, $paper->fresh()->status, '⛔ শেষ সইয়ে পাকা হয়নি।');
        $this->assertSame(DocumentStatus::CANCELLED, $sale->fresh()->status);
    }

    /** ⓘ সইয়ের ছক প্রতিষ্ঠানের, বাধ্যতামূলক নয় (মালিকের সংস্করণ ২) — ছক না থাকলে চাবিওয়ালার হাতেই পাকা */
    public function test_without_a_signature_flow_the_key_holder_issues_it_at_once(): void
    {
        ApprovalFlow::query()->where('company_id', $this->company->id)->where('action', SalesInvoiceCancellationService::APPROVAL_ACTION)->delete();
        $sale = $this->sell('2');
        $clerk = $this->clerk(['sales.invoice.view', 'sales.invoice.cancellation']);

        $paper = $this->request($sale, $clerk);

        $this->assertSame(DocumentStatus::CONFIRMED, $paper->status);
        $this->assertSame(DocumentStatus::CANCELLED, $sale->fresh()->status);
    }

    public function test_the_key_reason_and_once_only(): void
    {
        $sale = $this->sell('2');

        $this->actingAs($this->clerk(['sales.invoice.view']))->post(route('sales.invoice.cancellation', $sale), ['reason' => 'x'])->assertForbidden();

        $this->actingAs($this->owner)->post(route('sales.invoice.cancellation', $sale), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->assertSame(DocumentStatus::CONFIRMED, $sale->fresh()->status);

        $this->request($sale, $this->owner);
        $this->assertRefusedWith(fn () => $this->request($sale->fresh(), $this->owner), 'not_confirmed', $sale);
    }

    // ── সহায়ক ───────────────────────────────────────────────────────────

    /** প্রতিষ্ঠানের বসানো সইয়ের ছক — এক ধাপ, সুপার অ্যাডমিন */
    private function signatureFlow(): void
    {
        $owner = \Spatie\Permission\Models\Role::query()->where('company_id', $this->company->id)
            ->where('name', \App\Core\Services\PermissionSyncer::SUPER_ADMIN_ROLE)->firstOrFail();
        $flow = ApprovalFlow::query()->create([
            'company_id' => $this->company->id, 'module' => 'sales', 'action' => SalesInvoiceCancellationService::APPROVAL_ACTION,
            'document_type' => '', 'threshold_amount' => null, 'is_active' => true,
        ]);
        \App\Models\ApprovalFlowStep::query()->create([
            'approval_flow_id' => $flow->id, 'level' => 1, 'step_name' => 'owner',
            'approver_type' => \App\Models\ApprovalFlowStep::BY_ROLE, 'approver_id' => (int) $owner->id,
        ]);
    }

    private function request(SalesInvoice $invoice, User $by): SalesInvoiceCancellation
    {
        $this->actingAs($by);

        return app(SalesInvoiceCancellationService::class)->request($invoice, $by, 'ভুল বিল');
    }

    private function assertRefusedWith(callable $attempt, string $key, SalesInvoice $sale): void
    {
        try {
            $attempt();
            $this->fail("⛔ {$key}: থামার কথা ছিল।");
        } catch (ValidationException $e) {
            $this->assertSame(__('sales::cancellation.'.$key, ['no' => $sale->document_no, 'cxl' => '', 'challan' => '']),
                $e->errors()['reason'][0] ?? null, json_encode($e->errors(), JSON_UNESCAPED_UNICODE));
        }
    }

    /** @param  list<string>  $keys */
    private function clerk(array $keys): User
    {
        $user = User::query()->where('email', 'accounts@abos.test')->firstOrFail();

        CompanyContext::forCompany((int) $this->company->id, function () use ($user, $keys): void {
            foreach ($keys as $key) {
                $user->givePermissionTo(Permission::findOrCreate($key, 'web'));
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    private function sell(string $qty): SalesInvoice
    {
        $this->actingAs($this->owner);

        return app(DirectSaleService::class)->complete($this->data, [['product_id' => $this->biscuit->id, 'qty' => $qty, 'rate' => '10', 'free_qty' => '0']])['invoice']->fresh();
    }

    private function challanOf(SalesInvoice $invoice): DeliveryChallan
    {
        return $invoice->lines()->firstOrFail()->challanLine->challan;
    }

    private function onHand(): string
    {
        return (string) DB::table('inv_stock_movements')
            ->where('product_id', $this->biscuit->id)->where('warehouse_id', $this->warehouse->id)->sum('floor_change');
    }

    private function receivableOf(SalesInvoice $invoice): string
    {
        $type = SalesInvoice::drillSourceType();

        return (string) DB::table('ledger_entries')->whereIn('source_type', [$type, $type.':reversal'])
            ->where('source_id', $invoice->id)->where('party_type', 'customer')
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as n')->value('n');
    }
}
