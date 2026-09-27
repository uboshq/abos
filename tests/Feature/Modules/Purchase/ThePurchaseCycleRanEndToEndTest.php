<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\CostLayer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Purchase\Models\Payment;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Models\PurchaseOrder;
use App\Modules\Purchase\Models\PurchaseReceipt;
use App\Modules\Purchase\Models\PurchaseRequisition;
use App\Modules\Purchase\Models\PurchaseReturn;
use App\Modules\Purchase\Services\PaymentService;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Purchase\Services\PurchaseOrderService;
use App\Modules\Purchase\Services\PurchaseReceiptService;
use App\Modules\Purchase\Services\PurchaseReturnService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ক্রয়ের পুরো চক্র — চাওয়া থেকে ফেরত পর্যন্ত, এক টানে।
 *
 * ── ⛔ কেন এই ফাইলটা, ২৭ সেপ্টেম্বর ২০২৬ ─────────────────────────────
 * অডিট ২৭.৯.২৬ §৬: *"ক্রয় একটা বড় মডিউল, পরীক্ষা পাতলা — পুরো চক্রের
 * শুরু-থেকে-শেষ একটা পরীক্ষা লেখো।"* ⓘ এই ফোল্ডারের উনিশটা পরীক্ষার
 * প্রতিটা **একটা** ধাপ মাপে। ⚠️ কিন্তু একটা ধাপের সংখ্যা পরের ধাপে
 * ঠিকঠাক পৌঁছায় কি না — চাহিদার পরিমাণ আদেশে, আদেশের দর গ্রহণে, গ্রহণের
 * দর বিলে, বিলের মোট পরিশোধে — সেটা কেউ মাপত না।
 *
 * ── ⭐ টাকার অঙ্কগুলো হাতে গোনা, কোড থেকে ধার করা নয় ──────────────
 * ```
 * চাহিদা   ৫০ × ৳১২০ (আন্দাজ)
 * আদেশ    ৫০ × ৳১২৫.৫০           = ৳৬,২৭৫.০০০০
 * গ্রহণ    ৪০ × ৳১২৫.৫০           = ৳৫,০২০.০০০০   Dr ১১২০ / Cr ২১৬০
 * বিল      ৪০ × ৳১২৫.৫০           = ৳৫,০২০.০০০০   Dr ২১৬০ / Cr ২১১১
 * পরিশোধ                         = ৳৫,০২০.০০০০   Dr ২১১১ / Cr নগদ
 * ফেরত     ৬ × ৳১২৫.৫০            = ৳৭৫৩.০০০০     Dr ২১১১ / Cr ১১২০
 * ```
 * ⛔ প্রত্যাশিত অঙ্ক কোডের নিজের হিসাব থেকে নিলে দাবিটা কখনো লাল হত না
 * ([[never-supply-the-name-yourself]])।
 *
 * ── ⓘ ভূমিকাহীন লোক, ঠিক যতটা চাবি লাগে ──────────────────────────────
 * ডেমো-ভূমিকা ধার করা হয় না — ভূমিকার ছাঁচ বদলালে দাবিটা **ভুল কারণে**
 * লাল হত ([[TheShipmentDoorsWereNeverKnockedOnTest]]-এর নকশা)।
 */
final class ThePurchaseCycleRanEndToEndTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private const QTY_ASKED = '50';

    private const RATE = '125.50';

    private const QTY_RECEIVED = '40';

    private const QTY_RETURNED = '6';

    /** ৪০ × ১২৫.৫০ — গ্রহণ, বিল আর পরিশোধের অঙ্ক */
    private const RECEIVED_VALUE = '5020.0000';

    /** ৬ × ১২৫.৫০ — ফেরতের অঙ্ক */
    private const RETURNED_VALUE = '753.0000';

    /** ৫০ × ১২৫.৫০ — আদেশের মোট */
    private const ORDER_TOTAL = '6275.0000';

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

        /*
         * ⓘ বাড়তি নেওয়ার সুযোগ শূন্য — স্পষ্ট করে বসানো। ⚠️ ডেমোর
         * মান বদলালে বাড়তি-গ্রহণের দাবিটা ভুল কারণে সবুজ হয়ে যেত।
         */
        app(SettingsService::class)->set('purchase.over_receipt_percent', 0);

        $this->supplier = Supplier::query()->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

        /*
         * ⚠️ ভ্যাট, লট, সিরিয়াল আর পরিদর্শন — চারটাই বাদ। ⓘ চারটারই নিজের
         * পরীক্ষা আছে; এখানে থাকলে টাকার অঙ্কে ভ্যাট ঢুকত আর গ্রহণে লট
         * নম্বর চাইত, আর চক্রের প্রশ্নটা ওগুলোর নিচে চাপা পড়ত।
         */
        $this->product = Product::query()
            ->whereNull('tax_id')
            ->where('track_batch', false)
            ->where('track_serial', false)
            ->where('qc_required', false)
            ->orderBy('id')
            ->firstOrFail();

        $this->clerk = User::factory()->create(['current_company_id' => $this->company->id]);
        $this->clerk->companies()->attach($this->company->id, ['is_active' => true]);
        $this->actingAs($this->clerk);
    }

    /**
     * ⭐ পুরো চক্র: চাহিদা → অনুমোদন → আদেশ → নিশ্চিত → গ্রহণ (বিলসহ) →
     * পরিশোধ → আংশিক ফেরত। প্রতিটা ধাপে মাল, স্তর আর খাতা মাপা।
     */
    public function test_the_whole_purchase_cycle_runs_from_request_to_return(): void
    {
        $stockBefore = $this->onHand();
        $payableBefore = $this->payable();
        $inventoryBefore = $this->accountNet(StandardChart::INVENTORY);

        // ── ১. চাহিদা — লেখা আর অনুমোদন, দুইটাই দরজা দিয়ে ──────────────
        $this->grant('purchase.requisition.create', 'purchase.requisition.approve');

        $this->post(route('purchase.requisition.store'), [
            'trx_date' => now()->toDateString(),
            'purpose' => 'মাসের মজুদ',
            'lines' => [['product_id' => $this->product->id, 'qty' => self::QTY_ASKED, 'estimated_rate' => '120']],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $requisition = PurchaseRequisition::query()->latest('id')->firstOrFail();

        $this->assertSame(DocumentStatus::DRAFT, $requisition->status,
            'ⓘ লেখা চাহিদা খসড়া থাকার কথা — ⛔ লিখলেই অনুমোদিত হলে সই-এর ধাপটার কোনো মানে থাকত না।');

        $this->post(route('purchase.requisition.approve', $requisition))
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(DocumentStatus::CONFIRMED, $requisition->fresh()->status,
            '⛔ অনুমোদনের দরজা ৩০২ দিল, অথচ চাহিদাটা অনুমোদিত হয়নি।');

        // ── ২. চাহিদা → আদেশ → দর বসানো → নিশ্চিত ────────────────────────
        $this->grant('purchase.order.create', 'purchase.order.update');

        $this->post(route('purchase.requisition.order', $requisition), [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
        ])->assertSessionHasNoErrors()->assertRedirect();

        $requisition = $requisition->fresh(['order.lines']);
        $order = $requisition->order;

        $this->assertNotNull($order, '⛔ চাহিদা থেকে আদেশ বানানোর দরজা খুলেছে, অথচ আদেশ জন্মায়নি।');
        $this->assertSame(DocumentStatus::CLOSED, $requisition->status,
            '⚠️ আদেশ হয়ে যাওয়া চাহিদা বন্ধ না হলে একই জিনিস দুইবার কেনা যেত।');
        $this->assertSame(0, bccomp((string) $order->lines->first()->ordered_qty, self::QTY_ASKED, 4),
            '⛔ চাহিদার ৫০ আদেশে পৌঁছায়নি — ক্রয় বিভাগ ভুল পরিমাণ কিনত।');
        $this->assertSame(0, bccomp((string) $order->lines->first()->rate, '0', 4),
            'ⓘ চাহিদার আন্দাজি দর আদেশে যাওয়ার কথা নয় (PurchaseRequisitionService::toOrder)।');

        /* ⓘ দর বসায় ক্রয় বিভাগ — খসড়া আদেশ সম্পাদনা, সরবরাহকারীর কথা শুনে */
        $order = app(PurchaseOrderService::class)->update($order, [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
        ], [['product_id' => $this->product->id, 'ordered_qty' => self::QTY_ASKED, 'rate' => self::RATE]]);

        $this->assertSame(self::ORDER_TOTAL, $this->money($order->total),
            '⛔ আদেশের মোট ৫০ × ১২৫.৫০ = ৬,২৭৫ নয়।');

        $this->post(route('purchase.order.confirm', $order))
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(DocumentStatus::CONFIRMED, $order->fresh()->status,
            '⛔ আদেশ নিশ্চিতের দরজা ৩০২ দিল, অথচ আদেশটা খসড়াই।');
        $this->assertSame(0, LedgerEntry::query()->where('source_type', PurchaseOrder::drillSourceType())
            ->where('source_id', $order->id)->count(),
            'ⓘ আদেশ একটা অভিপ্রায় — খাতায় কিছু বসলে মাল না এলেও দায় থেকে যেত।');

        // ── ৩. মাল গ্রহণ — আদেশের ৫০-এর ৪০ এল ─────────────────────────────
        $receipt = app(PurchaseReceiptService::class)->create([
            'purchase_order_id' => $order->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
            'supplier_challan_no' => 'CH-771',
        ], [[
            'product_id' => $this->product->id,
            'purchase_order_line_id' => $order->lines->first()->id,
            'received_qty' => self::QTY_RECEIVED,
            'rate' => self::RATE,
        ]]);

        $this->assertSame(0, bccomp($this->onHand(), $stockBefore, 4),
            'ⓘ খসড়া গ্রহণে মাল নড়ার কথা নয় — ⛔ নড়লে বাতিল না করা প্রতিটা খসড়া গুদামে ভূতের মাল রেখে যেত।');

        /*
         * ⭐ এক ব্যবহারকারী, চাবি ছাড়া তারপর চাবিসহ ([[same-user-key-off-then-on]])।
         * ⚠️ এই দরজাটাই মাল আর টাকা দুইটাই নাড়ায় — তাই এখানেই মাপা।
         */
        $this->actingAs($this->clerk->fresh())
            ->post(route('purchase.receipt.confirm', $receipt))
            ->assertForbidden();

        $this->assertSame(DocumentStatus::DRAFT, $receipt->fresh()->status,
            '⛔ চাবি ছাড়া ৪০৩ ফিরেছে, তবু মাল গ্রহণ নিশ্চিত হয়ে গেছে।');

        $this->grant('purchase.receipt.create');

        $this->post(route('purchase.receipt.confirm', $receipt))
            ->assertSessionHasNoErrors()->assertRedirect(route('purchase.receipt.show', $receipt));

        $receipt = $receipt->fresh(['lines']);

        $this->assertSame(DocumentStatus::CONFIRMED, $receipt->status,
            '⛔ মাল গ্রহণের দরজা খুলেছে, অথচ গ্রহণটা খসড়াই।');
        $this->assertSame(self::RECEIVED_VALUE, $this->money($receipt->total),
            '⛔ গ্রহণের মোট ৪০ × ১২৫.৫০ = ৫,০২০ নয়।');

        $this->assertSame(
            bcadd($stockBefore, self::QTY_RECEIVED, 4),
            $this->onHand(),
            '⛔ গুদামে ঠিক ৪০ বাড়ার কথা — কম-বেশি মানে গুদাম আর কাগজ আলাদা।',
        );

        $movedHere = StockMovement::query()
            ->where('source_type', PurchaseReceipt::STOCK_SOURCE)
            ->where('source_id', $receipt->id)
            ->get();

        $this->assertSame([(int) $this->warehouse->id], $movedHere->pluck('warehouse_id')->map(fn ($id) => (int) $id)->unique()->values()->all(),
            '⛔ মাল অন্য গুদামে নেমেছে — আদেশে যে গুদাম বলা, সেখানে নয়।');

        $layer = CostLayer::query()
            ->where('source_type', PurchaseReceipt::STOCK_SOURCE)
            ->where('source_id', $receipt->id)
            ->sole();

        $this->assertSame(self::RECEIVED_VALUE, bcmul((string) $layer->qty_in, (string) $layer->unit_cost, 4),
            '⛔ মালের দামের স্তর ৪০ × ১২৫.৫০ নয় — বিক্রির খরচ ভুল দরে বসবে।');
        $this->assertSame(0, bccomp((string) $layer->qty_remaining, self::QTY_RECEIVED, 4),
            'ⓘ কিছু বিক্রি হয়নি, তাই স্তরের পুরো ৪০ বাকি থাকার কথা।');

        $this->assertDocumentBalanced(PurchaseReceipt::drillSourceType(), $receipt->id, 'মাল গ্রহণ');
        $this->assertSame(self::RECEIVED_VALUE, $this->sourceNet(StandardChart::INVENTORY, PurchaseReceipt::drillSourceType(), $receipt->id),
            '⛔ মজুদ খাতে (১১২০) গ্রহণের ৫,০২০ ডেবিট বসেনি।');
        $this->assertSame('-'.self::RECEIVED_VALUE, $this->sourceNet(StandardChart::GOODS_RECEIVED_NOT_INVOICED, PurchaseReceipt::drillSourceType(), $receipt->id),
            '⛔ বিল-না-আসা মালের দায় (২১৬০) ৫,০২০ ক্রেডিট হয়নি।');

        // ── ৪. বিল — গ্রহণেই আপনা থেকে (মালিকের নির্দেশ, ১৯ সেপ্টেম্বর) ─────
        $bill = $this->billsOf($receipt)->sole();

        $this->assertSame(DocumentStatus::CONFIRMED, $bill->status,
            '⛔ গ্রহণের সাথে বিলটা নিশ্চিত হয়নি — দায় সরবরাহকারীর নামে বসেনি।');
        $this->assertSame(self::RECEIVED_VALUE, $this->money($bill->total),
            '⛔ বিলের মোট গ্রহণের ৫,০২০ নয়।');

        $this->assertDocumentBalanced(PurchaseBill::drillSourceType(), $bill->id, 'ক্রয় বিল');
        $this->assertSame(self::RECEIVED_VALUE, $this->sourceNet(StandardChart::GOODS_RECEIVED_NOT_INVOICED, PurchaseBill::drillSourceType(), $bill->id),
            '⛔ বিল ২১৬০-এর অপেক্ষমাণ দায় ঠিক ৫,০২০ সরায়নি।');
        $this->assertSame('-'.self::RECEIVED_VALUE, $this->sourceNet(StandardChart::PAYABLE, PurchaseBill::drillSourceType(), $bill->id),
            '⛔ সরবরাহকারীর প্রদেয়তে (২১১১) ৫,০২০ ক্রেডিট হয়নি।');
        $this->assertSame('0.0000', $this->sourceNet(StandardChart::PURCHASE_PRICE_VARIANCE, PurchaseBill::drillSourceType(), $bill->id),
            'ⓘ গ্রহণ আর বিলের দর একই — মূল্য-পার্থক্য খাতে এক পয়সাও যাওয়ার কথা নয়।');

        $this->assertSame(self::RECEIVED_VALUE, bcsub($this->payable(), $payableBefore, 4),
            '⛔ সরবরাহকারীর কাছে দেনা ঠিক বিলের ৫,০২০ বাড়েনি।');
        $this->assertSame(self::RECEIVED_VALUE, $bill->fresh()->dueAmount(),
            'ⓘ কিছুই দেওয়া হয়নি, তাই বিলের পুরোটাই বাকি।');

        // ── ৫. পরিশোধ — পুরো বিল ─────────────────────────────────────────
        // ⓘ নগদ শূন্যের নিচে নামে না (CashOnHand, ২৭ সেপ্টেম্বর ২০২৬) — আগে টাকাটা টিলে,
        // মালিকের পুঁজি থেকে। কেবল এই পরিশোধের সারি মাপা হয়, তাই কোনো দাবির অঙ্ক বদলায় না।
        $this->putMoneyIn(app(CashTillService::class)->ensurePrimaryTill()->account, self::RECEIVED_VALUE);

        $payment = app(PaymentService::class)->create([
            'supplier_id' => $this->supplier->id,
            'trx_date' => now()->toDateString(),
            'amount' => self::RECEIVED_VALUE,
        ], [['purchase_bill_id' => $bill->id, 'amount' => self::RECEIVED_VALUE]]);

        $this->grant('purchase.payment.create');

        $this->post(route('purchase.payment.confirm', $payment))
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(DocumentStatus::CONFIRMED, $payment->fresh()->status,
            '⛔ পরিশোধের দরজা ৩০২ দিল, অথচ পরিশোধটা খসড়াই।');
        $this->assertDocumentBalanced(Payment::drillSourceType(), $payment->id, 'পরিশোধ');
        $this->assertSame(self::RECEIVED_VALUE, $this->sourceNet(StandardChart::PAYABLE, Payment::drillSourceType(), $payment->id),
            '⛔ পরিশোধে প্রদেয় (২১১১) ৫,০২০ ডেবিট হয়নি।');
        $this->assertSame($payableBefore, $this->payable(),
            '⛔ পুরো বিল শোধের পরও সরবরাহকারীর খাতায় দেনা রয়ে গেছে (বা উল্টো দিকে গেছে)।');
        $this->assertSame('0.0000', $bill->fresh()->dueAmount(),
            '⛔ বিলটা পুরো শোধ দেখাচ্ছে না — বকেয়ার তালিকায় থেকে যাবে, আর আবার টাকা যাবে।');
        $this->assertSame(self::RECEIVED_VALUE, $bill->fresh()->paidAmount(),
            '⛔ বিলের বিপরীতে দেওয়া টাকা ৫,০২০ নয়।');

        // ── ৬. ফেরত — ৪০-এর ৬টা ভাঙা ────────────────────────────────────
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

        $this->grant('purchase.return.create');

        $this->post(route('purchase.return.confirm', $return))
            ->assertSessionHasNoErrors()->assertRedirect();

        $return = $return->fresh();

        $this->assertSame(DocumentStatus::CONFIRMED, $return->status,
            '⛔ ফেরতের দরজা ৩০২ দিল, অথচ ফেরতটা খসড়াই।');
        $this->assertSame(self::RETURNED_VALUE, $this->money($return->total),
            '⛔ ফেরতের মোট ৬ × ১২৫.৫০ = ৭৫৩ নয় — দর বিল থেকে আসার কথা।');
        $this->assertSame(self::RETURNED_VALUE, $this->money($return->cost_of_goods),
            '⛔ ফেরত যাওয়া মালের দাম ঐ গ্রহণের স্তর থেকে আসেনি।');

        $this->assertSame(
            bcadd($stockBefore, bcsub(self::QTY_RECEIVED, self::QTY_RETURNED, 4), 4),
            $this->onHand(),
            '⛔ গুদামে ৪০ − ৬ = ৩৪ থাকার কথা।',
        );
        $this->assertSame(0, bccomp((string) $layer->fresh()->qty_remaining, '34', 4),
            '⛔ দামের স্তর থেকে ঠিক ৬টা বেরোয়নি — মজুদের মূল্য আর তাক আলাদা হবে।');

        $this->assertDocumentBalanced(PurchaseReturn::drillSourceType(), $return->id, 'ক্রয় ফেরত');
        $this->assertSame(self::RETURNED_VALUE, $this->sourceNet(StandardChart::PAYABLE, PurchaseReturn::drillSourceType(), $return->id),
            '⛔ ফেরতে প্রদেয় (২১১১) ৭৫৩ ডেবিট হয়নি।');
        $this->assertSame('-'.self::RETURNED_VALUE, $this->sourceNet(StandardChart::INVENTORY, PurchaseReturn::drillSourceType(), $return->id),
            '⛔ ফেরতে মজুদ (১১২০) ৭৫৩ ক্রেডিট হয়নি।');

        /* ⓘ পুরো শোধের পর ফেরত — সরবরাহকারী এখন আমাদের কাছে ৭৫৩ দেনা */
        $this->assertSame('-'.self::RETURNED_VALUE, bcsub($this->payable(), $payableBefore, 4),
            '⛔ ফেরতের পর সরবরাহকারীর খাতা ঠিক ৭৫৩ কমেনি।');

        // ── ৭. শেষ হিসাব — গোটা চক্রের পর ─────────────────────────────────
        $this->assertSame(
            bcsub(self::RECEIVED_VALUE, self::RETURNED_VALUE, 4),
            bcsub($this->accountNet(StandardChart::INVENTORY), $inventoryBefore, 4),
            '⛔ মজুদ খাত গোটা চক্রে ঠিক ৫,০২০ − ৭৫৩ = ৪,২৬৭ বাড়েনি।',
        );
        $this->assertSame('0.0000', $this->sourcesNet(StandardChart::GOODS_RECEIVED_NOT_INVOICED, [
            [PurchaseReceipt::drillSourceType(), $receipt->id],
            [PurchaseBill::drillSourceType(), $bill->id],
        ]), '⛔ বিল আসার পরও ২১৬০-এ এই চালানের দায় ঝুলে আছে।');
        $this->assertCompanyBooksBalance();
    }

    /**
     * ⛔ আদেশের চেয়ে বেশি মাল নেওয়া যায় না — আর আটকালে কিছুই বসে না।
     *
     * ⚠️ দ্বিতীয় অর্ধেকটাই আসল: বার্তা দেখিয়েও যদি গ্রহণের সারি বা স্টক
     * চলাচল থেকে যেত, তবে গুদামে এমন মাল দেখাত যা কেউ আনেনি।
     */
    public function test_receiving_more_than_was_ordered_is_refused_and_moves_nothing(): void
    {
        $order = $this->confirmedOrder();
        $orderLine = $order->lines->first();

        $this->receiveAndConfirm($order, self::QTY_RECEIVED);   // ৫০-এর ৪০ এল

        $receiptsBefore = PurchaseReceipt::query()->count();
        $movementsBefore = StockMovement::query()->count();
        $ledgerBefore = LedgerEntry::query()->count();
        $stockBefore = $this->onHand();

        try {
            app(PurchaseReceiptService::class)->create([
                'purchase_order_id' => $order->id,
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString(),
            ], [[
                'product_id' => $this->product->id,
                'purchase_order_line_id' => $orderLine->id,
                'received_qty' => '11',     // বাকি ১০, চাওয়া ১১
                'rate' => self::RATE,
            ]]);

            $this->fail('⛔ ৫০-এর আদেশে ৪০ + ১১ = ৫১ নেওয়া গেল — আদেশের সীমা কাগজেই রইল।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('lines', $e->errors(), 'ⓘ বার্তাটা সারির ঘরে আসার কথা, পর্দা ওখানেই দেখায়।');
        }

        $this->assertSame($receiptsBefore, PurchaseReceipt::query()->count(),
            '⛔ আটকানো গ্রহণের কাগজটা তবু লেখা হয়ে গেছে।');
        $this->assertSame($movementsBefore, StockMovement::query()->count(),
            '⛔ আটকানো গ্রহণে স্টক চলাচল বসেছে।');
        $this->assertSame($ledgerBefore, LedgerEntry::query()->count(),
            '⛔ আটকানো গ্রহণে খাতায় সারি বসেছে।');
        $this->assertSame($stockBefore, $this->onHand(),
            '⛔ আটকানো গ্রহণে গুদামের মাল বদলেছে।');

        /* ⭐ সীমানা: ঠিক বাকি ১০ নেওয়া যায় — নাহলে উপরের আটকানোটা ভুল কারণে */
        $this->receiveAndConfirm($order, '10');

        $this->assertSame(bcadd($stockBefore, '10', 4), $this->onHand(),
            '⛔ আদেশের ঠিক বাকি ১০ নেওয়া গেল না — সীমাটা এক ধাপ আগেই কাটছে।');
    }

    /**
     * ⛔ যা এসেছে তার বেশি বিল হয় না — আর আটকালে খাতায় কিছু বসে না।
     *
     * ⓘ গ্রহণেই পুরো ৪০-এর বিল হয়ে গেছে। ⚠️ তার উপর আরেকটা বিল গেলে
     * ২১৬০ খাত ঋণাত্মক হত আর সরবরাহকারী একই মালের দাম দুইবার পেতেন।
     */
    public function test_billing_more_than_was_received_is_refused_and_posts_nothing(): void
    {
        $receipt = $this->receiveAndConfirm($this->confirmedOrder(), self::QTY_RECEIVED);
        $bill = $this->billsOf($receipt)->sole();

        $billsBefore = PurchaseBill::query()->count();
        $ledgerBefore = LedgerEntry::query()->count();
        $payableBefore = $this->payable();

        try {
            app(PurchaseBillService::class)->create([
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString(),
            ], [[
                'product_id' => $this->product->id,
                'qty' => '1',
                'rate' => self::RATE,
                'purchase_receipt_line_id' => $receipt->lines->first()->id,
            ]]);

            $this->fail('⛔ ৪০ আসা মালে ৪০ + ১ = ৪১-এর বিল হয়ে গেল।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('lines', $e->errors());
        }

        $this->assertSame($billsBefore, PurchaseBill::query()->count(),
            '⛔ আটকানো বিলের খসড়াটা তবু থেকে গেছে।');
        $this->assertSame($ledgerBefore, LedgerEntry::query()->count(),
            '⛔ আটকানো বিলে খাতায় সারি বসেছে।');
        $this->assertSame($payableBefore, $this->payable(),
            '⛔ আটকানো বিলে সরবরাহকারীর দেনা বদলেছে।');
        $this->assertSame(self::RECEIVED_VALUE, $this->money($bill->fresh()->total),
            '⛔ আগের বিলটার অঙ্ক বদলে গেছে।');

        /* ⓘ "বাকি অংশের বিল" দরজাটাও নতুন কিছু বানায় না */
        $this->grant('purchase.bill.create');

        $this->post(route('purchase.receipt.bill', $receipt))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('saved', __('purchase::message.auto_bill_nothing_left'));

        $this->assertSame($billsBefore, PurchaseBill::query()->count(),
            '⛔ পুরো বিল হয়ে যাওয়া গ্রহণে "বাকি অংশের বিল" নতুন বিল বানিয়েছে।');
    }

    /**
     * ⭐ উল্টো পথ: বিল বাতিল → গ্রহণ বাতিল — খাতা মেলে, গুদাম আগের জায়গায়।
     *
     * ⚠️ বাতিল মুছে ফেলা নয়, উল্টো সারি। ⓘ তাই প্রশ্নটা "সারি নেই" নয় —
     * "প্রতিটা খাতের নিট শূন্য, আর গুদামের নিট শূন্য"। ⛔ কোনো একটা উল্টো
     * সারি বাদ পড়লে ২১৬০ বা ২১১১-এ একটা অঙ্ক ঝুলে থাকত যা কেউ বসায়নি।
     */
    public function test_cancelling_the_bill_then_the_receipt_leaves_books_and_shelves_as_they_were(): void
    {
        $stockBefore = $this->onHand();
        $payableBefore = $this->payable();
        $inventoryBefore = $this->accountNet(StandardChart::INVENTORY);

        $receipt = $this->receiveAndConfirm($this->confirmedOrder(), self::QTY_RECEIVED);
        $bill = $this->billsOf($receipt)->sole();

        $this->assertSame(self::RECEIVED_VALUE, bcsub($this->payable(), $payableBefore, 4),
            'ⓘ এই দাবির ভিত্তি: বাতিলের আগে দেনা সত্যিই ৫,০২০ বেড়েছিল।');

        /* ⓘ ক্রম উল্টো দিকে — বিল থাকা অবস্থায় গ্রহণ বাতিল আটকায় */
        $this->grant('purchase.receipt.cancel');

        $this->post(route('purchase.receipt.cancel', $receipt), ['reason' => 'ভুল মাল'])
            ->assertSessionHasErrors('status');

        $this->assertSame(DocumentStatus::CONFIRMED, $receipt->fresh()->status,
            '⛔ বিল থাকা অবস্থায় গ্রহণ বাতিল হয়ে গেছে — ২১৬০ ঋণাত্মক পড়ে থাকত।');

        $this->grant('purchase.bill.cancel');

        $this->post(route('purchase.bill.cancel', $bill), ['reason' => 'ভুল মাল'])
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->post(route('purchase.receipt.cancel', $receipt), ['reason' => 'ভুল মাল'])
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(DocumentStatus::CANCELLED, $bill->fresh()->status, '⛔ বিলটা বাতিল হয়নি।');
        $this->assertSame(DocumentStatus::CANCELLED, $receipt->fresh()->status, '⛔ গ্রহণটা বাতিল হয়নি।');

        $this->assertSame($stockBefore, $this->onHand(),
            '⛔ বাতিলের পর গুদামের মাল আগের জায়গায় ফেরেনি।');
        $this->assertSame(0, CostLayer::query()
            ->where('source_type', PurchaseReceipt::STOCK_SOURCE)
            ->where('source_id', $receipt->id)->count(),
            '⛔ বাতিল গ্রহণের দামের স্তর রয়ে গেছে — না-আসা মালের দামে বিক্রির খরচ বসবে।');

        $this->assertDocumentBalanced(PurchaseReceipt::drillSourceType(), $receipt->id, 'বাতিল মাল গ্রহণ', withReversal: true);
        $this->assertDocumentBalanced(PurchaseBill::drillSourceType(), $bill->id, 'বাতিল বিল', withReversal: true);

        $both = [
            [PurchaseReceipt::drillSourceType(), $receipt->id],
            [PurchaseReceipt::drillSourceType().':reversal', $receipt->id],
            [PurchaseBill::drillSourceType(), $bill->id],
            [PurchaseBill::drillSourceType().':reversal', $bill->id],
        ];

        foreach ([StandardChart::INVENTORY, StandardChart::GOODS_RECEIVED_NOT_INVOICED, StandardChart::PAYABLE] as $code) {
            $this->assertSame('0.0000', $this->sourcesNet($code, $both),
                "⛔ বাতিলের পর {$code} খাতে এই দুই কাগজের নিট শূন্য নয় — একটা উল্টো সারি বাদ পড়েছে।");
        }

        $this->assertSame($payableBefore, $this->payable(),
            '⛔ বাতিলের পর সরবরাহকারীর দেনা আগের জায়গায় ফেরেনি।');
        $this->assertSame($inventoryBefore, $this->accountNet(StandardChart::INVENTORY),
            '⛔ বাতিলের পর মজুদ খাত আগের জায়গায় ফেরেনি।');
        $this->assertCompanyBooksBalance();
    }

    // ── সহায়ক ────────────────────────────────────────────────────────────

    /** একটা নিশ্চিত আদেশ — ৫০ × ১২৫.৫০, সেবা দিয়ে (দরজাগুলো প্রথম পরীক্ষায় মাপা)। */
    private function confirmedOrder(): PurchaseOrder
    {
        $service = app(PurchaseOrderService::class);

        $order = $service->create([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
        ], [['product_id' => $this->product->id, 'ordered_qty' => self::QTY_ASKED, 'rate' => self::RATE]]);

        return $service->confirm($order)->load('lines');
    }

    /** আদেশ ধরে মাল গ্রহণ, আর দরজা দিয়ে নিশ্চিত — বিলসহ। */
    private function receiveAndConfirm(PurchaseOrder $order, string $qty): PurchaseReceipt
    {
        $receipt = app(PurchaseReceiptService::class)->create([
            'purchase_order_id' => $order->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
        ], [[
            'product_id' => $this->product->id,
            'purchase_order_line_id' => $order->lines->first()->id,
            'received_qty' => $qty,
            'rate' => self::RATE,
        ]]);

        $this->grant('purchase.receipt.create');

        $this->post(route('purchase.receipt.confirm', $receipt))->assertSessionHasNoErrors();

        return $receipt->fresh(['lines']);
    }

    private function grant(string ...$keys): void
    {
        $this->clerk->givePermissionTo($keys);
        $this->clerk = $this->clerk->fresh();
        $this->actingAs($this->clerk);
    }

    /** গুদামে মোট — তাকে ও অপেক্ষার ঘরে মিলিয়ে (`on_hand`)। */
    private function onHand(): string
    {
        return app(StockService::class)->statesFor($this->product, $this->warehouse)['on_hand'];
    }

    private function payable(): string
    {
        return $this->supplier->fresh()->payable();
    }

    private function money(mixed $value): string
    {
        return bcadd((string) $value, '0', 4);
    }

    private function accountId(string $code): int
    {
        return (int) Account::query()->where('code', $code)->valueOrFail('id');
    }

    /** একটা খাতের নিট (ডেবিট − ক্রেডিট), গোটা কোম্পানিতে। */
    private function accountNet(string $code): string
    {
        return $this->money(LedgerEntry::query()
            ->where('account_id', $this->accountId($code))
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as net')
            ->value('net'));
    }

    /** একটা কাগজের একটা খাতে নিট (ডেবিট − ক্রেডিট)। */
    private function sourceNet(string $code, string $sourceType, int $sourceId): string
    {
        return $this->sourcesNet($code, [[$sourceType, $sourceId]]);
    }

    /** @param list<array{0: string, 1: int}> $sources */
    private function sourcesNet(string $code, array $sources): string
    {
        return $this->money(LedgerEntry::query()
            ->where('account_id', $this->accountId($code))
            ->where(function ($q) use ($sources) {
                foreach ($sources as [$type, $id]) {
                    $q->orWhere(fn ($w) => $w->where('source_type', $type)->where('source_id', $id));
                }
            })
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as net')
            ->value('net'));
    }

    /**
     * একটা কাগজের দাখিলা নিজে মেলে — ডেবিটের যোগ = ক্রেডিটের যোগ, আর
     * সারি অন্তত একটা আছে।
     *
     * ⚠️ দ্বিতীয় শর্তটা না থাকলে "কিছুই বসেনি" আর "মিলে গেছে" একই রকম
     * সবুজ দেখাত ([[a-green-guard-may-never-have-looked]])।
     */
    private function assertDocumentBalanced(string $sourceType, int $sourceId, string $label, bool $withReversal = false): void
    {
        $types = $withReversal ? [$sourceType, $sourceType.':reversal'] : [$sourceType];

        $rows = LedgerEntry::query()->whereIn('source_type', $types)->where('source_id', $sourceId)->get();

        $this->assertNotEmpty($rows, "⛔ {$label}: খাতায় একটা সারিও বসেনি — মেলানোর কিছুই নেই।");

        $debit = $rows->reduce(fn (string $s, $r) => bcadd($s, (string) $r->debit, 4), '0');
        $credit = $rows->reduce(fn (string $s, $r) => bcadd($s, (string) $r->credit, 4), '0');

        $this->assertSame($debit, $credit, "⛔ {$label}: ডেবিট {$debit} আর ক্রেডিট {$credit} মেলে না।");
    }

    /** গোটা কোম্পানির খাতা — রেওয়ামিল মেলে। */
    private function assertCompanyBooksBalance(): void
    {
        $row = LedgerEntry::query()
            ->selectRaw('COALESCE(SUM(debit), 0) as d, COALESCE(SUM(credit), 0) as c')
            ->first();

        $this->assertSame($this->money($row->d), $this->money($row->c),
            '⛔ গোটা কোম্পানির খাতায় ডেবিট আর ক্রেডিট মেলে না — রেওয়ামিল ভাঙা।');
    }

    /** @return Collection<int, PurchaseBill> */
    private function billsOf(PurchaseReceipt $receipt): Collection
    {
        return PurchaseBill::query()
            ->with('lines')
            ->where('status', '<>', DocumentStatus::CANCELLED)
            ->whereHas('lines.receiptLine', fn ($q) => $q->where('purchase_receipt_id', $receipt->id))
            ->get();
    }
}
