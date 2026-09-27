<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Engines\Dashboard\Stat;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
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
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ক্রয়ের ড্যাশবোর্ডের "মোট দেনা" পরিশোধ আর ফেরত ভুলে যেত।
 *
 * ── ⛔ কী দেখা গিয়েছিল, লাইভ QA, ২৭ সেপ্টেম্বর ২০২৬ ─────────────────
 * TCL কোম্পানিতে ৳৩,০০০-এর ক্রয়, ৳১,০০০ পরিশোধ, ৳২০০ ফেরত। আসল দেনা
 * ৳১,৮০০ — অথচ ক্রয়ের ড্যাশবোর্ড বলছিল **৳৩,০০০**।
 *
 * ⓘ কারণ: সংখ্যাটা খাতা থেকে নয়, নিশ্চিত বিলগুলোর `total` যোগ করে বের
 * হত। ⚠️ বিলের মোট কখনো কমে না — টাকা গেলেও না, মাল ফেরত গেলেও না —
 * তাই সংখ্যাটা কেবল বাড়ত। মালিক নগদের পরিকল্পনা করতেন এমন দেনা ধরে যা
 * আসলে শোধ হয়ে গেছে।
 *
 * ── ⭐ এক সংখ্যা, এক সংজ্ঞা ──────────────────────────────────────────
 * হিসাবের ড্যাশবোর্ড, অর্থের ড্যাশবোর্ড আর CFO পাতা আগে থেকেই
 * [[AccountsFacts::payable]] পড়ে — খাত ২১১১-এর জের (ক্রেডিট − ডেবিট)।
 * ⛔ ক্রয়ের পর্দাটাই একমাত্র নিজের `SUM` লিখত, আর তাই একই প্রশ্নের দুই
 * উত্তর দিত।
 *
 * ── ⓘ অঙ্কগুলো হাতে গোনা ────────────────────────────────────────────
 * ```
 * গ্রহণ + বিল  ৩০ × ৳১০০   = ৳৩,০০০.০০০০   Cr ২১১১
 * পরিশোধ                  = ৳১,০০০.০০০০   Dr ২১১১
 * ফেরত       ২ × ৳১০০    = ৳২০০.০০০০     Dr ২১১১
 * দেনা       ৩,০০০ − ১,০০০ − ২০০ = ৳১,৮০০.০০০০
 * ```
 * ⚠️ ডেমো ডেটায় আগে থেকেই কিছু দেনা থাকতে পারে, তাই দাবিগুলো **বৃদ্ধি**
 * মাপে (পরে − আগে), আর পাশাপাশি পুরো সংখ্যাটা খাতার সাথে মেলায়।
 */
final class ThePayableOnTheDashboardForgotThePaymentsTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private const RATE = '100';

    private const QTY_BOUGHT = '30';

    private const QTY_RETURNED = '2';

    private const BOUGHT = '3000.0000';

    private const PAID = '1000.0000';

    private const RETURNED = '200.0000';

    /** ৩,০০০ − ১,০০০ − ২০০ — হাতে গোনা */
    private const STILL_OWED = '1800.0000';

    private Company $company;

    private User $clerk;

    private Supplier $supplier;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        app(StandardChart::class)->install();

        $this->supplier = Supplier::query()->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

        /* ⓘ ভ্যাট, লট, সিরিয়াল, পরিদর্শন বাদ — নাহলে অঙ্কে ভ্যাট ঢুকত */
        $this->product = Product::query()
            ->whereNull('tax_id')
            ->where('track_batch', false)
            ->where('track_serial', false)
            ->where('qc_required', false)
            ->orderBy('id')
            ->firstOrFail();

        $this->clerk = User::factory()->create(['current_company_id' => $this->company->id]);
        $this->clerk->companies()->attach($this->company->id, ['is_active' => true]);
        $this->clerk->givePermissionTo(['purchase.receipt.create', 'purchase.payment.create', 'purchase.return.create']);
        $this->actingAs($this->clerk->fresh());
    }

    /**
     * ⭐ ৩,০০০ কেনা, ১,০০০ দেওয়া, ২০০ ফেরত → ড্যাশবোর্ডে ১,৮০০ বাড়ে,
     * আর পুরো সংখ্যাটা খাত ২১১১-এর জেরের সমান।
     */
    public function test_the_dashboard_payable_is_what_is_still_owed_not_what_was_billed(): void
    {
        $dashboardBefore = $this->dashboardPayable();
        $ledgerBefore = $this->ledgerPayable();
        $suppliersBefore = $this->allSuppliersOwed();

        // ── ১. কেনা — আদেশ, গ্রহণ, গ্রহণেই বিল ────────────────────────
        $orders = app(PurchaseOrderService::class);
        $order = $orders->create([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
        ], [['product_id' => $this->product->id, 'ordered_qty' => self::QTY_BOUGHT, 'rate' => self::RATE]]);
        $order = $orders->confirm($order)->load('lines');

        $receipt = app(PurchaseReceiptService::class)->create([
            'purchase_order_id' => $order->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
        ], [[
            'product_id' => $this->product->id,
            'purchase_order_line_id' => $order->lines->first()->id,
            'received_qty' => self::QTY_BOUGHT,
            'rate' => self::RATE,
        ]]);

        $this->post(route('purchase.receipt.confirm', $receipt))->assertSessionHasNoErrors();

        $bill = PurchaseBill::query()
            ->with('lines')
            ->where('status', '<>', DocumentStatus::CANCELLED)
            ->whereHas('lines.receiptLine', fn ($q) => $q->where('purchase_receipt_id', $receipt->id))
            ->sole();

        $this->assertSame(self::BOUGHT, bcadd((string) $bill->total, '0', 4),
            '⛔ ভিত্তিটাই নড়বড়ে: বিলের মোট ৩০ × ১০০ = ৩,০০০ নয়।');

        // ── ২. পরিশোধ — ১,০০০ ─────────────────────────────────────────
        $payment = app(PaymentService::class)->create([
            'supplier_id' => $this->supplier->id,
            'trx_date' => now()->toDateString(),
            'amount' => self::PAID,
        ], [['purchase_bill_id' => $bill->id, 'amount' => self::PAID]]);

        /*
         * ⓘ ডেমোর টিল খালি, আর নগদ শূন্যের নিচে নামে না ([[CashOnHand]])।
         * টাকা আসে মালিকের পুঁজি থেকে — খাত ২১১১ ছোঁয় না, তাই দেনার
         * অঙ্কে কোনো প্রভাব নেই।
         */
        $this->putMoneyIn($payment->account, self::PAID);

        $this->post(route('purchase.payment.confirm', $payment))->assertSessionHasNoErrors();

        $this->assertSame(DocumentStatus::CONFIRMED, $payment->fresh()->status,
            '⛔ ভিত্তিটাই নড়বড়ে: পরিশোধটা নিশ্চিত হয়নি, খাতায় কিছু বসেনি।');

        // ── ৩. ফেরত — ২ × ১০০ ─────────────────────────────────────────
        $return = app(PurchaseReturnService::class)->create([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_bill_id' => $bill->id,
            'trx_date' => now()->toDateString(),
        ], [[
            'product_id' => $this->product->id,
            'qty' => self::QTY_RETURNED,
            'purchase_bill_line_id' => $bill->lines->first()->id,
        ]]);

        $this->post(route('purchase.return.confirm', $return))->assertSessionHasNoErrors();

        $this->assertSame(self::RETURNED, bcadd((string) $return->fresh()->total, '0', 4),
            '⛔ ভিত্তিটাই নড়বড়ে: ফেরতের মোট ২ × ১০০ = ২০০ নয়।');

        // ── ৪. খাতা আর সরবরাহকারীরা — হাতে গোনা ১,৮০০ ─────────────────
        $this->assertSame(self::STILL_OWED, bcsub($this->ledgerPayable(), $ledgerBefore, 4),
            '⛔ খাত ২১১১-এর জের ঠিক ১,৮০০ বাড়েনি — তাহলে সমস্যা ড্যাশবোর্ডে নয়, পোস্টিংয়ে।');

        $this->assertSame(self::STILL_OWED, bcsub($this->allSuppliersOwed(), $suppliersBefore, 4),
            '⛔ সব সরবরাহকারীর দেনার যোগফল ঠিক ১,৮০০ বাড়েনি।');

        // ── ৫. ড্যাশবোর্ড — আসল প্রশ্ন ──────────────────────────────────
        $grew = bcsub($this->dashboardPayable(), $dashboardBefore, 4);

        $this->assertSame(self::STILL_OWED, $grew, implode("\n", [
            "ক্রয়ের ড্যাশবোর্ডের \"মোট দেনা\" বেড়েছে ৳{$grew}, অথচ আসল দেনা বেড়েছে ৳১,৮০০।",
            '',
            'ⓘ ৩,০০০ কেনা − ১,০০০ দেওয়া − ২০০ ফেরত = ১,৮০০।',
            '⛔ ৩,০০০ দেখালে সংখ্যাটা বিলের মোট যোগ করছে — পরিশোধ আর ফেরত বাদ পড়েছে।',
            '⭐ সংজ্ঞা একটাই: খাত ২১১১-এর জের (AccountsFacts::payable)।',
        ]));

        $this->assertSame($this->ledgerPayable(), $this->dashboardPayable(), implode("\n", [
            'ক্রয়ের ড্যাশবোর্ডের "মোট দেনা" খাত ২১১১-এর জেরের সমান নয়।',
            '',
            '⛔ হিসাবের ড্যাশবোর্ড খাতা পড়ে — দুই পর্দায় একই প্রশ্নের দুই উত্তর আসবে।',
        ]));

        // ── ৬. পর্দায় সত্যিই ঐ সংখ্যাটাই আঁকা হয় ──────────────────────
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $owner->switchCompany($this->company->id);

        $this->actingAs($owner->fresh())
            ->get(route('module.dashboard', ['module' => 'purchase']))
            ->assertOk()
            ->assertSee(Money::format($this->ledgerPayable()), false);
    }

    /**
     * ড্যাশবোর্ডের সংজ্ঞা থেকে "মোট দেনা"-র ঘরটা — লেবেল ধরে খোঁজা,
     * ক্রম ধরে নয়, যাতে ঘর সাজানো বদলালে দাবি ভুল ঘর না মাপে।
     */
    private function dashboardPayable(): string
    {
        $label = __('purchase::dashboard.payable');

        $stats = array_values(array_filter(
            PurchaseDashboard::dashboard()->stats,
            fn (Stat $s) => $s->label === $label,
        ));

        $this->assertCount(1, $stats, '⛔ ড্যাশবোর্ডে "মোট দেনা" ঘরটা ঠিক একবার নেই — দাবিটা কিছুই মাপত না।');

        $shown = (string) $stats[0]->value;

        /* ⓘ পর্দার রূপ "১,৮০০.০০"-এর মতো — কমা সরিয়ে bcmath-এর সংখ্যা */
        return bcadd(str_replace(',', '', $shown), '0', 4);
    }

    /** খাত ২১১১-এর জের, ক্রেডিট − ডেবিট — সরাসরি খতিয়ান থেকে, কোডের সাহায্য ছাড়া। */
    private function ledgerPayable(): string
    {
        $accountId = (int) Account::query()->where('code', StandardChart::PAYABLE)->valueOrFail('id');

        return bcadd((string) (LedgerEntry::query()
            ->where('account_id', $accountId)
            ->selectRaw('COALESCE(SUM(credit) - SUM(debit), 0) as net')
            ->value('net') ?? '0'), '0', 4);
    }

    /** সব সরবরাহকারীর দেনার যোগফল — প্রত্যেকের নিজের খাতা থেকে। */
    private function allSuppliersOwed(): string
    {
        return Supplier::query()->get()->reduce(
            fn (string $sum, Supplier $s) => bcadd($sum, $s->payable(), 4),
            '0.0000',
        );
    }
}
