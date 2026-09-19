<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherApproval;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesInvoice;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * কাউন্টারের ডিপোজিট সইয়ের অপেক্ষায় থাকল — আর বিক্রয়টাও, পুরোটা।
 *
 * ── ⭐ মালিকের নকশা, ১৯ সেপ্টেম্বর ২০২৬ ──────────────────────────────
 * *"Add Deposit → রসিদ ভাউচার। Invoice confirm করলে approval-এ যাবে,
 * invoice খসড়া থাকবে, কোনো print option আসবে না যতক্ষণ approve হচ্ছে।
 * Deposit approve হলে bill print হবে।"*
 *
 * প্রশ্নের উত্তরে মালিক আরও বললেন:
 *   · সই না হওয়া পর্যন্ত **সবকিছু** অপেক্ষা করবে — মালও বের হবে না।
 *   · নিয়ম বসানো না থাকলে আজকের মতো — সাথে সাথে নিশ্চিত আর ছাপা।
 *   · ডিপোজিটটা হিসাবের **আসল রসিদ ভাউচার**, আর কাউন্টারের **নিজের নিয়ম**
 *     (`counter_deposit`) — হাতে লেখা রসিদ থেকে আলাদা।
 */
final class TheCounterDepositWaitedForItsSignatureTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Customer $customer;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs($this->user);

        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();

        $this->customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->orderBy('id')->firstOrFail();
    }

    /**
     * ⭐ সই লাগলে — সব খসড়া, মাল নড়ে না, আর পর্দা বিলের পাতায়।
     */
    public function test_a_signed_counter_deposit_holds_the_whole_sale(): void
    {
        $this->flow(VoucherApproval::COUNTER_DEPOSIT);
        $floor = $this->floor();

        $response = $this->sell();

        $invoice = SalesInvoice::query()->latest('id')->firstOrFail();

        $response->assertRedirect(route('sales.invoice.show', $invoice->id));

        $this->assertSame('draft', $invoice->status, 'বিলটা সই ছাড়াই নিশ্চিত হয়ে গেছে।');
        $this->assertTrue($invoice->isHeldAtCounter());

        $this->assertSame('draft', DeliveryChallan::query()->latest('id')->value('status'),
            'চালান নিশ্চিত হয়ে গেছে — মাল সইয়ের আগেই বের হয়েছে।');

        $this->assertSame($floor, $this->floor(), 'সইয়ের আগেই গুদাম থেকে মাল কমেছে।');

        $voucher = $invoice->heldCounterDeposits()->firstOrFail();

        $this->assertSame(Voucher::RECEIPT, $voucher->type);
        $this->assertSame(Voucher::ORIGIN_COUNTER, $voucher->origin);
        $this->assertSame('1000.0000', (string) $voucher->amount);

        $this->assertDatabaseHas('approvals', [
            'approvable_id' => $voucher->id,
            'module' => VoucherApproval::MODULE,
            'action' => VoucherApproval::COUNTER_DEPOSIT,
            'status' => 'pending',
        ]);
    }

    /**
     * ⭐ সইয়ের আগে ছাপা নেই, বদলানো নেই — বোতাম লুকানো, আর দরজাতেও তালা।
     */
    public function test_a_held_sale_can_neither_be_printed_nor_edited(): void
    {
        $this->flow(VoucherApproval::COUNTER_DEPOSIT);
        $this->sell();

        $invoice = SalesInvoice::query()->latest('id')->firstOrFail();
        $show = route('sales.invoice.show', $invoice->id);

        $page = $this->get($show);
        $page->assertOk();
        $page->assertDontSee(route('sales.print.invoice', $invoice), escape: false);
        $page->assertDontSee(route('sales.invoice.edit', $invoice), escape: false);
        $page->assertSee(__('sales::action.finish_held'));

        // ⛔ ঠিকানা টাইপ করে ছাপা — বিলের পাতায় ফেরে, বার্তাসহ
        $this->from($show)->get(route('sales.print.invoice', $invoice))
            ->assertRedirect($show)
            ->assertSessionHasErrors('status');

        $this->from($show)->get(route('sales.print.draft', $invoice))
            ->assertRedirect($show)
            ->assertSessionHasErrors('status');
    }

    /**
     * ⭐ সইয়ের আগে "নিশ্চিত" আটকায়; সইয়ের পরে সব একসাথে খাতায়।
     */
    public function test_confirming_waits_for_the_signature_and_then_finishes_everything(): void
    {
        $this->flow(VoucherApproval::COUNTER_DEPOSIT);
        $floor = $this->floor();
        $this->sell();

        $invoice = SalesInvoice::query()->latest('id')->firstOrFail();
        $show = route('sales.invoice.show', $invoice->id);

        // ⛔ সই হয়নি — কিছুই নড়ে না
        $this->from($show)->post(route('sales.invoice.confirm', $invoice))
            ->assertSessionHasErrors('status');

        $this->assertSame('draft', $invoice->fresh()->status);
        $this->assertSame($floor, $this->floor());

        // ⓘ অনুমোদনকারী সই দিলেন
        $voucher = $invoice->heldCounterDeposits()->firstOrFail();
        $approval = Approval::query()->where('approvable_id', $voucher->id)
            ->where('action', VoucherApproval::COUNTER_DEPOSIT)->firstOrFail();

        app(ApprovalEngine::class)->approve($approval, $this->user);

        // ⭐ একই বোতাম — এবার সব একসাথে
        $this->from($show)->post(route('sales.invoice.confirm', $invoice))
            ->assertSessionHasNoErrors()
            ->assertRedirect($show);

        $invoice = $invoice->fresh();

        $this->assertSame('confirmed', $invoice->status, 'সইয়ের পরেও বিল নিশ্চিত হয়নি।');
        $this->assertFalse($invoice->isHeldAtCounter());

        $this->assertSame('confirmed', $voucher->fresh()->status, 'ডিপোজিটের ভাউচার খাতায় ওঠেনি।');

        $this->assertSame(
            bcsub($floor, '10', 4),
            $this->floor(),
            'বিক্রয় নিশ্চিত হলো, অথচ গুদাম থেকে মাল কমেনি।',
        );

        // ⓘ ১০ × ১০০ = ১০০০, আর ডিপোজিটও ১০০০ — বকেয়া শূন্য (a5d654c7-এর গোনা)
        $this->assertSame('0.0000', $invoice->dueAmount());
    }

    /**
     * ⭐ নিয়ম না থাকলে আজকের মতো — সোজা রসিদে।
     */
    public function test_without_a_counter_rule_the_counter_prints_straight_away(): void
    {
        $this->sell()->assertRedirectContains('/print/invoice/');

        $invoice = SalesInvoice::query()->latest('id')->firstOrFail();

        $this->assertSame('confirmed', $invoice->status);
        $this->assertFalse($invoice->isHeldAtCounter());
    }

    /**
     * ⭐ দুই নিয়ম আলাদা — হাতে লেখা রসিদের ছক কাউন্টার আটকায় না।
     *
     * ⓘ মালিকের কথা: *"কাউন্টারের জন্য আলাদা নিয়ম, বাকিগুলো আলাদা।"*
     */
    public function test_the_hand_receipt_rule_does_not_hold_the_counter(): void
    {
        $this->flow(Voucher::RECEIPT);

        $this->sell()->assertRedirectContains('/print/invoice/');

        $this->assertFalse(SalesInvoice::query()->latest('id')->firstOrFail()->isHeldAtCounter(),
            'হাতে লেখা রসিদের নিয়মে কাউন্টারের বিক্রয় আটকে গেছে।');
    }

    private function sell(): \Illuminate\Testing\TestResponse
    {
        return $this->post(route('sales.direct.store'), [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'deposit' => '1000',
            'lines' => [['product_id' => $this->product->id, 'qty' => '10', 'rate' => '100']],
        ]);
    }

    private function flow(string $action): void
    {
        $flow = ApprovalFlow::query()->create([
            'company_id' => CompanyContext::id(),
            'module' => VoucherApproval::MODULE,
            'action' => $action,
            'document_type' => '',
            'threshold_amount' => null,
            'is_active' => true,
        ]);

        ApprovalFlowStep::query()->create([
            'approval_flow_id' => $flow->id,
            'level' => 1,
            'approver_type' => 'user',
            'approver_id' => $this->user->id,
        ]);
    }

    private function floor(): string
    {
        return app(StockService::class)->floorQty($this->product, $this->warehouse);
    }
}
