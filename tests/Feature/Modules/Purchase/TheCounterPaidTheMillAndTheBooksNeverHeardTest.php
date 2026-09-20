<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Cheque;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherApproval;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\PaymentMethod;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Services\DirectPurchaseService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * কাউন্টারে মিলকে টাকা দেওয়া হলো, আর ভাউচারের খাতা তা কোনোদিন শুনল না।
 *
 * ── ⭐ মালিকের সিদ্ধান্ত, ২০ সেপ্টেম্বর ২০২৬ ───────────────────────────
 * *"বিক্রয় counter-এর নিয়মেই করো।"* ⓘ বিক্রয়ের কাউন্টারে প্রতিটা জমা
 * এখন রসিদ ভাউচার; ক্রয়ের কাউন্টারে প্রতিটা পরিশোধ তেমনি একটা **পরিশোধ
 * ভাউচার**, সরবরাহকারীর নামে আর বিলের সাথে বাঁধা।
 *
 * ── ⚠️ একটা জায়গায় আলাদা, আর কারণটা ঘটনার ────────────────────────────
 * বিক্রয়ে সই না হলে মালই বেরোয় না। ⛔ ক্রয়ে মাল ইতিমধ্যে গুদামে; তাই বিল
 * ও মাল এগোয়, কেবল টাকাটা খসড়া ভাউচার হয়ে সইয়ের অপেক্ষায় থাকে।
 */
final class TheCounterPaidTheMillAndTheBooksNeverHeardTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Supplier $supplier;

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

        $this->supplier = Supplier::query()->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->orderBy('id')->firstOrFail();
    }

    /**
     * ⭐ নগদে দেওয়া টাকা — পরিশোধ ভাউচার, খাতায় বসা, বিলের সাথে বাঁধা।
     */
    public function test_cash_at_the_counter_becomes_a_posted_payment_voucher(): void
    {
        $result = $this->buy(['paid_now' => '600']);

        $bill = $result['bill'];

        $voucher = Voucher::query()
            ->where('origin', Voucher::ORIGIN_COUNTER)
            ->where('type', Voucher::PAYMENT)
            ->latest('id')
            ->firstOrFail();

        $this->assertTrue($voucher->isPosted(), 'কাউন্টারের পরিশোধ খাতায় বসেনি।');
        $this->assertSame('supplier', $voucher->party_type);
        $this->assertSame((int) $this->supplier->id, (int) $voucher->party_id);
        $this->assertSame((int) $bill->id, (int) $voucher->against_id);
        $this->assertSame(0, bccomp((string) $voucher->amount, '600', 4));

        // ⓘ বিলের বাকিও কমেছে — ভাউচারের টাকা গোনা হয়েছে
        $this->assertSame(0, bccomp($bill->fresh()->paidAmount(), '600', 4),
            'ভাউচারে দেওয়া টাকাটা বিলের শোধ হিসেবে গোনা হয়নি।');
    }

    /**
     * ⭐ ছক বসানো থাকলে টাকাটা সইয়ের অপেক্ষায় — কিন্তু মাল আর বিল এগোয়।
     */
    public function test_a_rule_holds_the_money_but_not_the_goods(): void
    {
        $this->flow(VoucherApproval::COUNTER_PAYMENT);

        $result = $this->buy(['paid_now' => '600']);

        $bill = $result['bill']->fresh();

        $this->assertSame('confirmed', $bill->status, 'সইয়ের অপেক্ষায় বিলটাও আটকে গেছে — মাল তো এসেই গেছে।');

        $voucher = Voucher::query()->where('origin', Voucher::ORIGIN_COUNTER)->latest('id')->firstOrFail();

        $this->assertFalse($voucher->isPosted(), 'সই ছাড়াই টাকা খাতায় বসে গেছে।');
        $this->assertSame(0, bccomp($bill->paidAmount(), '0', 4), 'খসড়া ভাউচারের টাকা শোধ হিসেবে গোনা হয়েছে।');

        $this->assertDatabaseHas('approvals', [
            'approvable_id' => $voucher->id,
            'action' => VoucherApproval::COUNTER_PAYMENT,
            'status' => 'pending',
        ]);

        // ⓘ সই হলে ভাউচারটা খাতায় বসানো যায়, আর তখন বিল শোধ দেখায়
        $approval = Approval::query()->where('approvable_id', $voucher->id)->firstOrFail();
        app(ApprovalEngine::class)->approve($approval, $this->user);

        app(\App\Modules\Accounts\Services\VoucherService::class)->post($voucher->fresh());

        $this->assertSame(0, bccomp($bill->fresh()->paidAmount(), '600', 4));
    }

    /**
     * ⛔ বিক্রয়ের ডিপোজিটের ছক ক্রয়ের কাউন্টার আটকায় না — দুইটা আলাদা নিয়ম।
     */
    public function test_the_sales_counter_rule_does_not_hold_a_purchase(): void
    {
        $this->flow(VoucherApproval::COUNTER_DEPOSIT);

        $this->buy(['paid_now' => '600']);

        $voucher = Voucher::query()->where('origin', Voucher::ORIGIN_COUNTER)->latest('id')->firstOrFail();

        $this->assertTrue($voucher->isPosted(),
            'বিক্রয়ের ডিপোজিটের ছক ক্রয়ের পরিশোধ আটকে দিয়েছে।');
    }

    /**
     * ⭐ চেকে দিলে টাকা ২১১৫-এ বসে, আর চেকটা রেজিস্টারে ওঠে।
     */
    public function test_a_cheque_lands_in_cheques_issued_and_in_the_register(): void
    {
        $method = PaymentMethod::query()->where('code', 'CHQ')->firstOrFail();

        $this->buy(['deposits' => [[
            'payment_method_id' => $method->id,
            'amount' => '600',
            'reference' => 'PAY-CHQ-1',
            'ref_date' => now()->toDateString(),
            'bank_name' => 'City Bank',
        ]]]);

        $voucher = Voucher::query()->where('origin', Voucher::ORIGIN_COUNTER)->latest('id')->firstOrFail();

        $issued = (int) \App\Modules\Accounts\Models\Account::query()
            ->where('code', StandardChart::CHEQUES_ISSUED)->value('id');

        $this->assertTrue($voucher->fresh(['lines'])->lines
            ->contains(fn ($line) => (int) $line->account_id === $issued && bccomp((string) $line->credit, '0', 4) > 0),
            'চেকের টাকা ২১১৫ ইস্যু করা চেকে বসেনি।');

        $cheque = Cheque::query()->where('cheque_no', 'PAY-CHQ-1')->firstOrFail();

        $this->assertSame(Cheque::ISSUED, $cheque->direction);
        $this->assertSame((int) $voucher->id, (int) $cheque->voucher_id);
        $this->assertSame('supplier', $cheque->party_type);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array{bill: PurchaseBill, payments: list<Voucher>}
     */
    private function buy(array $extra): array
    {
        return app(DirectPurchaseService::class)->complete(
            [
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString(),
                'supplier_bill_no' => 'MILL-'.fake()->unique()->numberBetween(1000, 9999),
                ...$extra,
            ],
            [['product_id' => $this->product->id, 'qty' => '10', 'rate' => '60']],
        );
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
}
