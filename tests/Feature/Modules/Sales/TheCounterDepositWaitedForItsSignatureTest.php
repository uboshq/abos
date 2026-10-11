<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherApproval;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesInvoice;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
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

        $response = $this->sell($this->bankDeposit());

        $invoice = SalesInvoice::query()->latest('id')->firstOrFail();

        /*
         * ⓘ কাউন্টারেই ফেরে, বার্তাটা পপ-আপে — মালিকের ছবি (২৭ সেপ্টেম্বর ২০২৬,
         * সন্ধ্যা): অনুমোদনের বার্তা পপ-আপ, পাতার মাথায় বা বোতামের নিচে নয়।
         * ⚠️ আগে এই দাবি বিলের পাতায় যাওয়া চাইত — মালিকের সিদ্ধান্তের পরে সেটাই ভুল।
         */
        $response->assertRedirect(route('sales.direct.create'))
            ->assertSessionHas('approval_notice', __('sales::message.direct_sale_held', ['invoice' => $invoice->document_no]));

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
        $this->sell($this->bankDeposit());

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
        $this->sell($this->bankDeposit());

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

        /*
         * ⭐ শেষ সইয়েই সব একসাথে — বোতাম আর লাগে না (মালিকের সিদ্ধান্ত ১,
         * ২৭ সেপ্টেম্বর ২০২৬; [[HeldCounterSaleFinisher]])। ⚠️ আগে এখানে সইয়ের
         * পরে আবার "নিশ্চিত" চাপা হত — লাইভে INV-0005 ঠিক ঐ চাপের অপেক্ষায়
         * আটকে ছিল।
         */
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
     * ⛔ ছক বসানো থাকলেও নিজের বাক্সে নগদ বিক্রয় আটকায় না — সোজা রসিদে।
     *
     * ── ⓘ মালিকের নিয়ম, ২১ সেপ্টেম্বর ২০২৬ ─────────────────────────────
     * *"bank mfs e gele approval e asbe, cash e sudu tar nijer cash accounts e
     * taka nite parbe tai app er dorkar nai"* ([[VoucherApproval::stopping()]]
     * `landsInCash`)।
     *
     * ⚠️ কী ভাঙা ছিল: কাউন্টারের আগাম প্রশ্নটা ([[DirectSaleService::counterDepositNeedsApproval()]])
     * কেবল ছক দেখত — টাকা কোথায় নামছে দেখত না। ⛔ ফল একটা মরা খসড়া: চালান-বিল
     * আটকে থাকত, মাল বেরোত না, অথচ সইয়ের কোনো অনুরোধই যেত না, আর "শেষ
     * করুন" সই ছাড়াই পার হয়ে যেত। ⓘ এই ফাইলের প্রথম তিন দাবি ঠিক এই পথে
     * লাল হয়ে ধরিয়ে দিল — ওরা নগদে সই চাইছিল।
     */
    public function test_cash_into_the_own_till_is_not_held_even_with_a_counter_rule(): void
    {
        $this->flow(VoucherApproval::COUNTER_DEPOSIT);
        $floor = $this->floor();
        $approvals = Approval::query()->count();

        $this->sell()->assertSessionHasNoErrors()->assertRedirectContains('/print/invoice/');

        $invoice = SalesInvoice::query()->latest('id')->firstOrFail();

        $this->assertSame('confirmed', $invoice->status, '⛔ নিজের বাক্সে নগদ, তবু বিক্রয় খসড়ায় আটকে গেছে।');
        $this->assertFalse($invoice->isHeldAtCounter());
        $this->assertSame(bcsub($floor, '10', 4), $this->floor(), '⛔ নগদ বিক্রয়ে মাল বেরোয়নি।');
        $this->assertSame($approvals, Approval::query()->count(), '⛔ নিজের বাক্সের নগদে সই চাওয়া হয়েছে।');

        $voucher = Voucher::query()->where('origin', Voucher::ORIGIN_COUNTER)
            ->where('against_id', $invoice->id)->firstOrFail();

        $this->assertTrue($voucher->isPosted(), '⛔ নগদ জমার ভাউচার খাতায় বসেনি।');
    }

    /**
     * ⭐ সই না লাগলেও ডিপোজিটটা রসিদ ভাউচার — আর ভাউচার তালিকার নিজের ট্যাবে।
     *
     * ── মালিকের সিদ্ধান্ত, ১৯ সেপ্টেম্বর ২০২৬ ──────────────────────────
     * *"কাউন্টারের সব ডিপোজিট সবসময় রসিদ ভাউচার (RCV)। রেকর্ড থাকবে এক
     * রকমের, আর তালিকা সবসময় পুরো থাকবে।"* ⛔ আগে সই ছাড়া পথের টাকা
     * আদায়ের কাগজ হত, আর "Sales Added Deposit" ট্যাবে কখনো উঠত না।
     */
    public function test_without_a_rule_the_deposit_is_still_a_voucher_on_the_list(): void
    {
        $collectedBefore = $this->listedCollected();

        $this->sell();

        $invoice = SalesInvoice::query()->latest('id')->firstOrFail();

        $voucher = Voucher::query()
            ->where('origin', Voucher::ORIGIN_COUNTER)
            ->where('against_id', $invoice->id)
            ->firstOrFail();

        $this->assertTrue($voucher->isPosted(), 'সই না লাগা ডিপোজিট খাতায় বসেনি।');
        $this->assertSame('0.0000', $invoice->fresh()->dueAmount());
        $this->assertSame(0, Collection::query()
            ->where('customer_id', $this->customer->id)->where('trx_date', now()->toDateString())->count(),
            'কাউন্টার আবার আদায়ের কাগজ বানাচ্ছে।');

        $this->get(route('accounts.voucher.list', ['tab' => 'sales_deposit']))
            ->assertOk()
            ->assertSee($voucher->document_no);

        // ⓘ বিলের তালিকাও ভাউচারের টাকা গোনে — আগে কেবল আদায়ের কাগজ গুনত
        $this->assertSame(0, bccomp(bcsub($this->listedCollected(), $collectedBefore, 4), '1000', 4),
            'বিলের তালিকা কাউন্টারের টাকা দেখেনি।');
    }

    /**
     * ⭐ বিলের চেয়ে বেশি দিলে "ফেরত" নয় — গ্রাহকের খাতায় জমা।
     *
     * ⓘ মালিক: *"অগ্রিম আলাদাভাবে থাকবে না… ব্যাংক লেজারের মতো Dr Cr।"*
     */
    public function test_an_extra_deposit_stays_on_the_customer_account(): void
    {
        $before = (string) $this->customer->outstanding();
        $collectedBefore = $this->listedCollected();

        $this->post(route('sales.direct.store'), [
            'own_transport' => '1', // ⓘ ধাপ ৫ — নিশ্চিতে পরিবহন লাগে ([[TransportRule]]); এই দাবি অন্য কিছু মাপে
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'deposit' => '1500',
            'lines' => [['product_id' => $this->product->id, 'qty' => '10', 'rate' => '100']],
        ])->assertSessionHas('saved', fn (string $said) => str_contains(
            $said,
            __('sales::message.direct_extra_kept', ['amount' => Money::format('500')]),
        ));

        $this->assertSame(0, bccomp((string) $this->customer->fresh()->outstanding(), bcsub($before, '500', 4), 4),
            'বাড়তি ৫০০ গ্রাহকের খাতায় জমা হয়নি।');

        // ⓘ বিলের তালিকায় এক বিলের বাড়তি অন্য বিলে গড়ায় না — এই বিলের আদায় ১০০০-ই
        $this->assertSame(0, bccomp(bcsub($this->listedCollected(), $collectedBefore, 4), '1000', 4),
            'বিলের তালিকায় বাড়তি জমা অন্য বিলের বকেয়া কমিয়েছে।');
    }

    private function listedCollected(): string
    {
        return (string) $this->get(route('sales.invoice.index'))->viewData('totals')['collected'];
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

    /**
     * ১০ × ১০০ = ১,০০০ টাকার বিক্রয়, আর ১,০০০ টাকা জমা।
     *
     * ⓘ জমা না বললে পুরনো একক ঘরে নগদ — প্রধান টিলে, অর্থাৎ নিজের বাক্সে।
     *
     * @param  array<string, mixed>|null  $deposit
     */
    private function sell(?array $deposit = null): TestResponse
    {
        return $this->post(route('sales.direct.store'), [
            'own_transport' => '1', // ⓘ ধাপ ৫ — নিশ্চিতে পরিবহন লাগে ([[TransportRule]]); এই দাবি অন্য কিছু মাপে
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            ...($deposit ?? ['deposit' => '1000']),
            'lines' => [['product_id' => $this->product->id, 'qty' => '10', 'rate' => '100']],
        ]);
    }

    /**
     * ব্যাংকে ১,০০০ টাকার জমা — যেটা সত্যিই সই চায়।
     *
     * ⚠️ নগদ নয়, ইচ্ছাকৃত: ২১ সেপ্টেম্বর ২০২৬ থেকে নিজের বাক্সে নগদ রসিদ সই
     * চায় না ([[VoucherApproval::stopping()]] `landsInCash`)। ⛔ এই ফাইলটা ঐ
     * নিয়মের আগে লেখা, তাই নগদ দিয়েই সই চাইত — আর নিয়মের পর সেটা আর কখনো
     * আসত না। ⓘ ডেমোতে ব্যাংকের পাতা-খাত না থাকলে একটা বৈধ নগদ খাত নকল করে
     * কেবল ধরন বদলানো ([[TheParkedBillWaitsAtTheSameCounterTest]]-এর মতো)।
     *
     * @return array<string, mixed>
     */
    private function bankDeposit(): array
    {
        $bank = Account::query()->ofMoneyKind(Account::BANK)->postable()->active()->orderBy('id')->first();

        if ($bank === null) {
            $sibling = Account::query()->ofMoneyKind(Account::CASH)->postable()->orderBy('id')->firstOrFail();

            $bank = $sibling->replicate(['public_id']);
            $bank->forceFill([
                'code' => 'BANK-SIGN',
                'name_en' => 'BANK-SIGN',
                'name_bn' => 'BANK-SIGN',
                'money_kind' => Account::BANK,
            ])->save();
        }

        $this->assertTrue($bank->fresh()->isBank(), 'দৃশ্যটাই বানানো যায়নি — খাতটা ব্যাংক নয়।');

        return ['deposits' => [['amount' => '1000', 'account_id' => $bank->id, 'reference' => 'TRX-SIGN']]];
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
