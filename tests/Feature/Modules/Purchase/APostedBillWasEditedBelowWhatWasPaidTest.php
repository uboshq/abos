<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Engines\Approval\HeldForApproval;
use App\Core\Support\CompanyContext;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Services\DirectPurchaseService;
use App\Modules\Purchase\Services\PaymentService;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * নিশ্চিত বিল পরিশোধের নিচে নামানো যেত, আর অঙ্ক বাড়ালে সই লাগত না — পুনঃনিরীক্ষা, ৯ অক্টোবর ২০২৬।
 *
 * ── ⛔ কী ভুল ছিল ([[PurchaseBillService::updatePosted()]]) ─────────────
 * ① তালা ছিল না, আর "নতুন মোট ≥ শোধ + ফেরত" যাচাইও না: ১,০০০-এর বিলে ৮০০ শোধের পর বিলটা ৫০০
 *    করা যেত — ৩০০ তখন কোনো বিলের নামে নয়।
 * ② সইয়ের নিয়ম আবার চলত না: ছকের সীমার নিচের বিল বদলে সীমার উপরে নেওয়া যেত, কারও সই ছাড়াই।
 */
final class APostedBillWasEditedBelowWhatWasPaidTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private User $owner;

    private Supplier $supplier;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        app(StandardChart::class)->install();

        $this->supplier = Supplier::query()->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->firstOrFail();
    }

    public function test_a_paid_bill_cannot_be_edited_below_what_was_paid(): void
    {
        $bill = $this->postedBill('10'); // ১,০০০

        $payment = app(PaymentService::class)->create([
            'supplier_id' => $this->supplier->id,
            'trx_date' => now()->toDateString(),
            'amount' => '800',
        ], [['purchase_bill_id' => $bill->id, 'amount' => '800']]);
        $this->putMoneyIn($payment->account, '800');
        app(PaymentService::class)->confirm($payment->fresh());

        $this->assertSame(0, bccomp($bill->fresh()->paidAmount(), '800', 4), 'দাবির ভিত্তি: ৮০০ শোধ বসেনি।');

        $before = DB::table('ledger_entries')->count();

        try {
            app(PurchaseBillService::class)->update($bill->fresh(), $this->data(), [$this->line('5')], repost: true);
            $this->fail('⛔ ৮০০ শোধ করা বিল ৫০০-তে নামানো গেল।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('lines', $e->errors());
        }

        $this->assertSame(0, bccomp((string) $bill->fresh()->total, '1000', 4), '⛔ ফেরানো সম্পাদনাতেও বিলের মোট বদলে গেল।');
        $this->assertSame($before, DB::table('ledger_entries')->count(), '⛔ ফেরানো সম্পাদনা খাতায় দাগ রেখে গেল।');

        // ⓘ শোধের উপরে নামানো আগের মতোই চলে
        $edited = app(PurchaseBillService::class)->update($bill->fresh(), $this->data(), [$this->line('9')], repost: true);
        $this->assertSame(0, bccomp((string) $edited->total, '900', 4));
    }

    public function test_raising_a_posted_bill_over_the_flows_limit_asks_for_the_signature(): void
    {
        // ⓘ ছক আগে — ইঞ্জিন ছকগুলো একবার পড়ে মনে রাখে; ১,০০০-এর বিল সীমার নিচে, তাই সই ছাড়াই বসে
        $flow = ApprovalFlow::query()->create([
            'company_id' => CompanyContext::id(), 'module' => 'purchase', 'action' => 'bill',
            'document_type' => '', 'threshold_amount' => '3000', 'is_active' => true,
        ]);
        ApprovalFlowStep::query()->create([
            'approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => 'user', 'approver_id' => $this->owner->id,
        ]);

        $bill = $this->postedBill('10');

        try {
            app(PurchaseBillService::class)->update($bill->fresh(), $this->data(), [$this->line('50')], repost: true);
            $this->fail('⛔ ১,০০০-এর বিল সই ছাড়াই ৫,০০০ হয়ে গেল।');
        } catch (HeldForApproval) {
            // প্রত্যাশিত
        }

        $this->assertSame(0, bccomp((string) $bill->fresh()->total, '1000', 4), '⛔ সইয়ের অপেক্ষায় থাকা অবস্থায় বিলটা বদলে গেল।');
        $this->assertTrue(DB::table('approvals')->where('approvable_id', $bill->id)->where('status', 'pending')->exists(),
            '⛔ সইয়ের অনুরোধটা ইনবক্সে নেই — কেউ সই দিতে পারবে না।');

        // ⓘ নামানো বা সীমার নিচে থাকা বদল সই চায় না
        $edited = app(PurchaseBillService::class)->update($bill->fresh(), $this->data(), [$this->line('8')], repost: true);
        $this->assertSame(0, bccomp((string) $edited->total, '800', 4));
    }

    private function postedBill(string $qty): PurchaseBill
    {
        return app(DirectPurchaseService::class)->complete($this->data(), [$this->line($qty)])['bill'];
    }

    /** @return array<string, mixed> */
    private function data(): array
    {
        return [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
            'supplier_bill_no' => 'PAID-EDIT-1',
        ];
    }

    /** @return array<string, mixed> */
    private function line(string $qty): array
    {
        return ['product_id' => $this->product->id, 'qty' => $qty, 'rate' => '100', 'sales_price' => '100', 'tax' => '0'];
    }
}
