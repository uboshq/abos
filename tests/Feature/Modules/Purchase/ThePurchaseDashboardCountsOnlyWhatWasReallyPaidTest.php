<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Engines\Dashboard\Stat;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Dashboard\PurchaseDashboard;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Services\PaymentService;
use App\Modules\Purchase\Services\PurchaseOrderService;
use App\Modules\Purchase\Services\PurchaseReceiptService;
use App\Modules\Purchase\Services\PurchaseReturnService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ক্রয়ের ড্যাশবোর্ড যা সত্যিই দেওয়া হলো তাই গোনে — অডিট ⚠️১৪ (৬ অক্টোবর ২০২৬)।
 *
 * ⛔ ধরা পড়েছিল: "এ মাসে পরিশোধ" অবস্থা না দেখে সব পরিশোধ যোগ করত (খসড়াও), আর কাউন্টারের পরিশোধ-ভাউচার বাদ দিত;
 * "সবচেয়ে বড় দেনা" বিলের মোটে সাজানো আর মোটটাই দেখানো — শোধ হয়ে যাওয়া বড় বিলও মাথায় বসত।
 *
 * হাতে গোনা: বিল ৩০ × ১০০ = ৩,০০০; পরিশোধ ১,০০০; ভাউচার ৩০০; ফেরত ২ × ১০০ = ২০০ → বাকি ১,৫০০।
 */
final class ThePurchaseDashboardCountsOnlyWhatWasReallyPaidTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    public function test_paid_counts_posted_payments_and_vouchers_and_the_payable_list_shows_what_is_still_owed(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        app(StandardChart::class)->install();
        app(\App\Core\Services\SettingsService::class)->set('purchase.payment_three_hands', false);

        $supplier = Supplier::query()->firstOrFail();
        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $product = Product::query()->whereNull('tax_id')->where('track_batch', false)->where('track_serial', false)
            ->where('qc_required', false)->orderBy('id')->firstOrFail();

        $clerk = User::factory()->create(['current_company_id' => $company->id]);
        $clerk->companies()->attach($company->id, ['is_active' => true]);
        $clerk->givePermissionTo(['purchase.receipt.create', 'purchase.payment.create', 'purchase.return.create']);
        $this->actingAs($clerk->fresh());

        $before = $this->paid();

        // ── কেনা ৩,০০০ ──
        $orders = app(PurchaseOrderService::class);
        $order = $orders->confirm($orders->create(
            ['supplier_id' => $supplier->id, 'warehouse_id' => $warehouse->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $product->id, 'ordered_qty' => '30', 'rate' => '100']],
        ))->load('lines');
        $receipt = app(PurchaseReceiptService::class)->create(
            ['purchase_order_id' => $order->id, 'warehouse_id' => $warehouse->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $product->id, 'purchase_order_line_id' => $order->lines->first()->id, 'received_qty' => '30', 'rate' => '100']],
        );
        $this->post(route('purchase.receipt.confirm', $receipt))->assertSessionHasNoErrors();
        $bill = PurchaseBill::query()->with('lines')->where('status', '<>', DocumentStatus::CANCELLED)
            ->whereHas('lines.receiptLine', fn ($q) => $q->where('purchase_receipt_id', $receipt->id))->sole();

        // ── খসড়া পরিশোধ ১,০০০ — গোনা নয় ──
        $payment = app(PaymentService::class)->create(
            ['supplier_id' => $supplier->id, 'trx_date' => now()->toDateString(), 'amount' => '1000'],
            [['purchase_bill_id' => $bill->id, 'amount' => '1000']],
        );
        $this->assertSame(0, bccomp($this->paid(), $before, 4), '⛔ খসড়া পরিশোধ "এ মাসে পরিশোধ"-এ গোনা হলো।');

        $this->putMoneyIn($payment->account, '1000');
        $this->post(route('purchase.payment.confirm', $payment))->assertSessionHasNoErrors();
        $this->assertSame(0, bccomp(bcsub($this->paid(), $before, 4), '1000', 4), 'নিশ্চিত পরিশোধ গোনা হলো না — দাবি অন্ধ।');

        // ── বিলের বিপরীতে পরিশোধ-ভাউচার: পাকা ৩০০ গোনা, খসড়া ৯৯৯ নয় ──
        $voucher = fn (string $no, string $amount, string $status) => DB::table('vouchers')->insert([
            'company_id' => $company->id, 'financial_year_id' => $bill->financial_year_id, 'branch_id' => $bill->branch_id,
            'type' => Voucher::PAYMENT, 'document_no' => $no, 'trx_date' => now()->toDateString(), 'amount' => $amount,
            'status' => $status, 'against_type' => PurchaseBill::drillSourceType(), 'against_id' => $bill->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $voucher('PV-DASH-1', '300', DocumentStatus::CONFIRMED);
        $voucher('PV-DASH-2', '999', DocumentStatus::DRAFT);
        $this->assertSame(0, bccomp(bcsub($this->paid(), $before, 4), '1300', 4), '⛔ বিলের বিপরীতে পাকা পরিশোধ-ভাউচার গোনা হলো না, বা খসড়াটা গোনা হলো।');

        // ── ফেরত ২০০ ──
        $return = app(PurchaseReturnService::class)->create(
            ['supplier_id' => $supplier->id, 'warehouse_id' => $warehouse->id, 'purchase_bill_id' => $bill->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $product->id, 'qty' => '2', 'purchase_bill_line_id' => $bill->lines->first()->id]],
        );
        $this->post(route('purchase.return.confirm', $return))->assertSessionHasNoErrors();

        // ── সবচেয়ে বড় দেনা: বাকি দেখায়, বাকি ধরে সাজায় ──
        $list = collect(PurchaseDashboard::dashboard()->listings)->first(fn ($l) => $l->href === route('purchase.bill.index'));
        $due = collect($list->columns)->firstWhere('key', 'amount')['render'];
        $mine = $list->rows->first(fn (PurchaseBill $b) => (int) $b->id === (int) $bill->id);
        $this->assertNotNull($mine, 'বিলটা বড় দেনার তালিকায় নেই — দাবি অন্ধ।');
        $this->assertSame(Money::format('1500'), $due($mine), '⛔ তালিকা বিলের মোট দেখায়, বাকি নয় (৩,০০০ − ১,০০০ − ৩০০ − ২০০)।');

        $amounts = $list->rows->map(fn ($b) => (float) str_replace(',', '', $due($b)))->all();
        $sorted = $amounts;
        rsort($sorted);
        $this->assertSame($sorted, $amounts, '⛔ তালিকা বাকি ধরে বড় থেকে ছোটতে সাজানো নয়।');
        $this->assertNotContains(0.0, $amounts, '⛔ পুরো শোধ হওয়া বিল দেনার তালিকায়।');
    }

    /**
     * ⭐ ফেরত সাজানোর ক্রম বদলায় — X: ৩০,০০,০০০ কেনা, ২০,০০,০০০ ফেরত → বাকি ১০,০০,০০০; Y: ১৫,০০,০০০, পুরোটা বাকি।
     * ⛔ বিলের মোটে সাজালে X আগে; বাকিতে সাজালে Y আগে। অঙ্ক বড়, যাতে ডেমোর বিলের নিচে না পড়ে (তালিকা আটটার)।
     */
    public function test_the_largest_payables_are_ranked_by_what_is_owed_after_returns(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        app(StandardChart::class)->install();

        $supplier = Supplier::query()->firstOrFail();
        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $product = Product::query()->whereNull('tax_id')->where('track_batch', false)->where('track_serial', false)
            ->where('qc_required', false)->orderBy('id')->firstOrFail();

        $clerk = User::factory()->create(['current_company_id' => $company->id]);
        $clerk->companies()->attach($company->id, ['is_active' => true]);
        $clerk->givePermissionTo(['purchase.receipt.create', 'purchase.payment.create', 'purchase.return.create']);
        $this->actingAs($clerk->fresh());

        $buy = function (string $qty) use ($supplier, $warehouse, $product): PurchaseBill {
            $orders = app(PurchaseOrderService::class);
            $order = $orders->confirm($orders->create(
                ['supplier_id' => $supplier->id, 'warehouse_id' => $warehouse->id, 'trx_date' => now()->toDateString()],
                [['product_id' => $product->id, 'ordered_qty' => $qty, 'rate' => '100000']],
            ))->load('lines');
            $receipt = app(PurchaseReceiptService::class)->create(
                ['purchase_order_id' => $order->id, 'warehouse_id' => $warehouse->id, 'trx_date' => now()->toDateString()],
                [['product_id' => $product->id, 'purchase_order_line_id' => $order->lines->first()->id, 'received_qty' => $qty, 'rate' => '100000']],
            );
            $this->post(route('purchase.receipt.confirm', $receipt))->assertSessionHasNoErrors();

            return PurchaseBill::query()->with('lines')->where('status', '<>', DocumentStatus::CANCELLED)
                ->whereHas('lines.receiptLine', fn ($q) => $q->where('purchase_receipt_id', $receipt->id))->sole();
        };

        $x = $buy('30');
        $y = $buy('15');

        $return = app(PurchaseReturnService::class)->create(
            ['supplier_id' => $supplier->id, 'warehouse_id' => $warehouse->id, 'purchase_bill_id' => $x->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $product->id, 'qty' => '20', 'purchase_bill_line_id' => $x->lines->first()->id]],
        );
        $this->post(route('purchase.return.confirm', $return))->assertSessionHasNoErrors();

        $list = collect(PurchaseDashboard::dashboard()->listings)->first(fn ($l) => $l->href === route('purchase.bill.index'));
        $ids = $list->rows->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        $this->assertContains((int) $x->id, $ids, 'X দেনার তালিকায় নেই — দাবি অন্ধ।');
        $this->assertContains((int) $y->id, $ids, 'Y দেনার তালিকায় নেই — দাবি অন্ধ।');
        $this->assertLessThan(array_search((int) $x->id, $ids, true), array_search((int) $y->id, $ids, true),
            '⛔ দেনার তালিকা ফেরত বাদ না দিয়ে সাজানো — ১০ লাখ বাকির X, ১৫ লাখ বাকির Y-র আগে।');
    }

    private function paid(): string
    {
        $value = (string) collect(PurchaseDashboard::dashboard()->stats)
            ->first(fn (Stat $s) => $s->label === __('purchase::dashboard.paid_this_month'))?->value;

        return str_replace(',', '', $value) ?: '0';
    }
}
