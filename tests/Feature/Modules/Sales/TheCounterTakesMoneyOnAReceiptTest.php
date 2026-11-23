<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherApproval;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Customer\Models\Customer;
use App\Modules\Executive\Services\DayClosingService;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\PaymentMethod;
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\CollectionService;
use App\Modules\Sales\Services\PosService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * কাউন্টারের টাকাও রসিদ ভাউচার — মালিক, ২০ নভেম্বর ২০২৬ ("ok", টাকা ঢোকার কাগজ একটাই)।
 *
 * ── ⛔ আগে ─────────────────────────────────────────────────────────────
 * কাউন্টার (POS, [[PosService]]) প্রতিটা ভাগে একটা আদায় (COL-…) লিখত — অথচ আদায়ের দরজা ওয়েব, ফোন আর জমার খবর থেকে
 * বন্ধ হয়ে গেছে (dc0d02669)। কাউন্টারই শেষ জায়গা ছিল যেখানে নতুন COL বসত।
 *
 * ── ⭐ এখন ─────────────────────────────────────────────────────────────
 * প্রতিটা ভাগ একটা রসিদ (RCV-…), নিজে পাকা — সই নেই, দ্বিতীয় হাত নেই (কাউন্টারের বিক্রিটাই অনুমোদন); পক্ষ গ্রাহক, বিলের
 * বিপরীতে, উৎস "কাউন্টার", তারিখ বিক্রির। খাতার দাখিলা একই (Dr টাকার খাত / Cr পাওনা), তাই টিল, নগদ গণনা আর দিনের হিসাব
 * আগের অঙ্কই দেখায় — কেবল কাগজের নাম বদলায়। পুরনো দিনের আদায় যেমন ছিল তেমনই, একবারই গোনা।
 */
final class TheCounterTakesMoneyOnAReceiptTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\HasASecondSigner;

    private Company $company;

    private User $owner;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();
        app(SettingsService::class)->set('sales.screen_pos', true);

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->firstOrFail();
    }

    // ── ১ · একটা পাকা রসিদ, কোনো আদায় নয় ─────────────────────────────────

    public function test_a_counter_cash_sale_writes_one_posted_receipt_and_no_collection(): void
    {
        $collections = Collection::query()->count();
        $tillBefore = $this->till()->fresh()->balanceOn();

        $invoice = $this->sell('2', '100', ['paid' => '200'])['invoice'];

        $receipts = $this->counterReceipts();
        $this->assertCount(1, $receipts, '⛔ কাউন্টারের নগদ বিক্রিতে ঠিক একটা রসিদ বসেনি।');
        $receipt = $receipts->first();

        $this->assertSame(Voucher::RECEIPT, $receipt->type);
        $this->assertSame(DocumentStatus::CONFIRMED, $receipt->status, '⛔ কাউন্টারের রসিদ নিজে পাকা হয়নি।');
        $this->assertStringStartsWith('RCV', (string) $receipt->document_no);
        $this->assertSame('customer', $receipt->party_type);
        $this->assertSame((int) $invoice->customer_id, (int) $receipt->party_id);
        $this->assertSame(SalesInvoice::drillSourceType(), $receipt->against_type);
        $this->assertSame((int) $invoice->id, (int) $receipt->against_id);
        $this->assertSame($invoice->trx_date->toDateString(), $receipt->trx_date->toDateString());
        $this->assertSame((int) $invoice->branch_id, (int) $receipt->branch_id, '⛔ রসিদ বিলের শাখায় বসেনি।');
        $this->assertStringContainsString((string) $invoice->document_no, (string) $receipt->narration);
        $this->assertSame(0, bccomp((string) $receipt->amount, '200', 4));

        $this->assertSame($collections, Collection::query()->count(), '⛔ কাউন্টার এখনো আদায় (COL) লেখে।');
        $this->assertSame(0, bccomp($invoice->fresh()->dueAmount(), '0', 4), '⛔ রসিদের পরও বিলে বাকি দেখায়।');
        $this->assertSame(0, bccomp(bcsub($this->till()->fresh()->balanceOn(), $tillBefore, 4), '200', 4), '⛔ টাকাটা টিলে নামেনি।');

        // ⓘ খাতায় উৎস "রসিদ ভাউচার" — আদায়ের দাখিলা নয়
        $this->assertTrue(LedgerEntry::query()->where('source_id', $receipt->id)->where('source_type', 'receipt_voucher')->exists());
        $this->assertFalse(LedgerEntry::query()->where('source_type', 'collection')->where('trx_date', $invoice->trx_date->toDateString())->exists(),
            '⛔ আজকের খাতায় আদায়ের দাখিলা বসেছে।');
    }

    /** ⓘ বেশি দিলে ফেরতটা রসিদে বসে না — রসিদ বিলের সমান */
    public function test_the_change_never_reaches_the_receipt(): void
    {
        $result = $this->sell('2', '100', ['paid' => '500']);

        $this->assertSame(0, bccomp($result['change'], '300', 4));
        $this->assertSame(0, bccomp((string) $this->counterReceipts()->sole()->amount, '200', 4));
    }

    // ── ২ · ভাগ করে দেওয়া — প্রতিটা ভাগ নিজের খাতে ────────────────────────

    public function test_a_split_payment_writes_one_receipt_per_part_in_its_own_account(): void
    {
        $bkash = $this->bkashAccount();
        $method = $this->bkashMethod($bkash);

        $this->sell('1', '2300', ['payments' => [
            ['payment_method_id' => $method->id, 'amount' => '2000', 'reference' => 'TRX-CTR-1'],
            ['payment_method_id' => '', 'amount' => '300'],
        ]]);

        $receipts = $this->counterReceipts();
        $this->assertCount(2, $receipts);

        $byBkash = $receipts->firstWhere('instrument_no', 'TRX-CTR-1');
        $this->assertNotNull($byBkash, '⛔ বিকাশের ভাগের রসিদে TrxID নেই।');
        $this->assertSame(0, bccomp((string) $byBkash->amount, '2000', 4));
        $this->assertTrue($this->debits($byBkash, $bkash->id, '2000'), '⛔ বিকাশের রসিদ বিকাশের খাতে ডেবিট নয়।');

        $byCash = $receipts->first(fn (Voucher $v) => $v->id !== $byBkash->id);
        $this->assertSame(0, bccomp((string) $byCash->amount, '300', 4));
        $this->assertTrue($this->debits($byCash, (int) $this->till()->id, '300'), '⛔ নগদের রসিদ টিলে ডেবিট নয়।');
        $this->assertSame('cash', $byCash->instrument);

        $this->assertSame(0, bccomp($bkash->fresh()->balanceOn(), '2000', 4));
        $this->assertSame(0, Collection::query()->count());
    }

    // ── ৩ · সই নেই, দ্বিতীয় হাত নেই ──────────────────────────────────────

    /**
     * ⭐ রসিদ আর কাউন্টারের জমায় সইয়ের ছক চালু, লেখক ≠ পাকাকারী চালু — তবু কাউন্টারের রসিদ নিজে পাকা: কাউন্টারের বিক্রিটাই
     * অনুমোদন (মালিক, ২০ নভেম্বর ২০২৬)। ⓘ বিকাশে — নিজের বাক্সে নগদ এমনিতেই সই চায় না, তাই দাবিটা নগদ নয় এমন টাকায়।
     */
    public function test_the_counter_receipt_posts_without_a_signature_or_a_second_hand(): void
    {
        $this->flowFor(VoucherApproval::COUNTER_DEPOSIT);
        $this->flowFor(Voucher::RECEIPT);
        app(SettingsService::class)->set(VoucherService::MAKER_CHECKER, true);

        $method = $this->bkashMethod($this->bkashAccount());

        $this->sell('1', '500', ['payment_method_id' => $method->id, 'reference' => 'TRX-CTR-2', 'paid' => '500']);

        $receipt = $this->counterReceipts()->sole();
        $this->assertSame(DocumentStatus::CONFIRMED, $receipt->status, '⛔ কাউন্টারের রসিদ সইয়ের অপেক্ষায় আটকে গেছে।');
        $this->assertFalse(Approval::query()->where('approvable_id', $receipt->id)->where('status', Approval::PENDING)->exists(),
            '⛔ কাউন্টারের রসিদে সই চাওয়া হয়েছে।');
    }

    /** ⓘ মালিক নন এমন ক্যাশিয়ার — লেখক ≠ পাকাকারী চালু থাকলেও তাঁর কাউন্টারের রসিদ পাকা হয় (হাতের পোস্ট নয়) */
    public function test_a_cashier_who_is_not_the_owner_still_gets_a_posted_receipt(): void
    {
        app(SettingsService::class)->set(VoucherService::MAKER_CHECKER, true);

        $cashier = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id,
            'current_branch_id' => $this->company->defaultBranch()?->id]);
        $cashier->companies()->attach($this->company->id, ['is_active' => true]);
        $cashier->givePermissionTo(['sales.pos']);

        $this->actingAs($cashier->fresh());

        $this->sell('1', '150', ['paid' => '150']);

        $receipt = $this->counterReceipts()->sole();
        $this->assertSame(DocumentStatus::CONFIRMED, $receipt->status);
        $this->assertSame((int) $cashier->id, (int) $receipt->created_by);
    }

    // ── ৪ · টিল, নগদ গণনা আর দিনের হিসাব — একই অঙ্ক, একবারই ─────────────

    public function test_the_day_closing_counts_the_counter_money_once_as_before(): void
    {
        $tillBefore = $this->till()->fresh()->balanceOn();

        $invoice = $this->sell('3', '100', ['paid' => '300'])['invoice'];

        // ⓘ সব শাখা জুড়ে — দিনের হিসাব হেডারের শাখা মানে ([[ViewedBranch]])
        CompanyContext::set($this->company->id, null);
        $day = app(DayClosingService::class)->build(Carbon::parse($invoice->trx_date));

        $cash = collect($day['inflow']['groups'])->firstWhere('kind', Account::CASH);
        $this->assertNotNull($cash, '⛔ কাউন্টারের নগদ "আজ টাকা এল"-এ নেই।');
        $this->assertCount(1, $cash['rows'], '⛔ একই টাকা দুই সারিতে — রসিদ আর আদায় দুইটাই?');
        $this->assertSame(0, bccomp((string) $cash['total'], '300', 2));
        $this->assertSame((string) $this->counterReceipts()->sole()->document_no, $cash['rows'][0]['document_no']);

        // ⓘ টিলের খাতা — আদায়ের দিনে যা বাড়ত, রসিদেও ঠিক তাই (Dr টিল / Cr পাওনা)
        $this->assertSame(0, bccomp(bcsub($this->till()->fresh()->balanceOn(), $tillBefore, 4), '300', 4));
        $this->assertSame(0, bccomp($this->receivableOf((int) $invoice->customer_id, $invoice->trx_date->toDateString()), '0', 4),
            '⛔ গ্রাহকের পাওনা শূন্যে ফেরেনি — রসিদ পাওনার খাতে বসেনি।');
    }

    /**
     * ⭐ পুরনো দিন আদায়ে লেখা — যেমন ছিল তেমনই, একবারই গোনা; নতুন দিন রসিদে — দুইটা মিশে দ্বিগুণ হয় না।
     */
    public function test_an_old_day_recorded_as_a_collection_is_still_counted_once(): void
    {
        $yesterday = Carbon::today()->subDay()->toDateString();
        $dealer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $tillBefore = $this->till()->fresh()->balanceOn();

        // ⓘ পুরনো দিনের কাগজ — আদায়ের সেবা সরাসরি (দরজা বন্ধ, সেবা পুরনো কাগজের জন্য আছে)
        $collections = app(CollectionService::class);
        $old = $collections->confirm($collections->create([
            'customer_id' => $dealer->id,
            'account_id' => $this->till()->id,
            'trx_date' => $yesterday,
            'amount' => '700',
            'instrument' => 'cash',
        ], []));
        $this->assertSame(DocumentStatus::CONFIRMED, $old->status);

        $invoice = $this->sell('2', '100', ['paid' => '200'])['invoice'];

        CompanyContext::set($this->company->id, null);
        $closing = app(DayClosingService::class);

        $then = collect($closing->build(Carbon::parse($yesterday))['inflow']['groups'])->firstWhere('kind', Account::CASH);
        $this->assertNotNull($then);
        $this->assertCount(1, $then['rows'], '⛔ পুরনো দিনের আদায় হারিয়েছে বা দুইবার গোনা।');
        $this->assertSame(0, bccomp((string) $then['total'], '700', 2));
        $this->assertSame((string) $old->document_no, $then['rows'][0]['document_no']);

        $now = collect($closing->build(Carbon::parse($invoice->trx_date))['inflow']['groups'])->firstWhere('kind', Account::CASH);
        $this->assertCount(1, $now['rows']);
        $this->assertSame(0, bccomp((string) $now['total'], '200', 2));

        $this->assertSame(0, bccomp(bcsub($this->till()->fresh()->balanceOn(), $tillBefore, 4), '900', 4), '⛔ টিলের খাতা ৭০০ + ২০০ নয়।');
        $this->assertSame(1, Collection::query()->count(), '⛔ পুরনো আদায়টা ছাড়া আর কোনো আদায় থাকার কথা নয়।');
    }

    // ── ৫ · ফেরত — টাকা ফেরত পরিশোধে, রসিদ থাকে ─────────────────────────

    public function test_a_return_with_a_refund_pays_the_money_back_from_the_till_and_keeps_the_receipt(): void
    {
        $invoice = $this->sell('2', '500', ['paid' => '1000'])['invoice'];
        $afterSale = $this->till()->fresh()->balanceOn();

        $result = app(PosService::class)->takeBack([
            'document_no' => $invoice->document_no,
            'warehouse_id' => $this->warehouse->id,
            'reason_code_id' => (int) ReasonCode::query()->inContext(ReasonCode::SALES_RETURN)->where('code', 'DAMAGE')->value('id'),
            'refund' => true,
        ], [
            ['product_id' => $this->product->id, 'qty' => '1', 'sales_invoice_line_id' => $invoice->lines()->value('id')],
        ]);

        $refund = $result['refund'];
        $this->assertNotNull($refund, '⛔ শোধ হওয়া বিলের ফেরতে টাকা ফেরত যায়নি।');
        $this->assertSame(Voucher::PAYMENT, $refund->type);
        $this->assertSame(DocumentStatus::CONFIRMED, $refund->status);
        $this->assertSame(0, bccomp((string) $refund->amount, '500', 4), '⛔ ফেরত গেছে '.$refund->amount.', ৫০০ নয়।');
        $this->assertSame(0, bccomp(bcsub($afterSale, $this->till()->fresh()->balanceOn(), 4), '500', 4), '⛔ টিল থেকে ৫০০ বেরোয়নি।');

        // ⓘ রসিদ থাকে — টাকা নেওয়া আর ফেরত দুইটা আলাদা ঘটনা, দুইটা কাগজে (আগের আদায় + পরিশোধের হুবহু)
        $this->assertSame(DocumentStatus::CONFIRMED, $this->counterReceipts()->sole()->status);
        $this->assertSame(0, Collection::query()->count());
    }

    /** ⓘ বাকিতে বেচা বিলের ফেরতে নগদ বেরোয় না — রসিদ গোনা হয় বিলের শোধে ([[SalesInvoice::collectedAmount()]]) */
    public function test_a_part_paid_counter_bill_refunds_only_the_overpaid_part(): void
    {
        $dealer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $dealer->forceFill(['credit_limit' => '100000'])->save();

        // ১০ × ১০০ = ১,০০০, কাউন্টারে ৭০০ — বাকি ৩০০; ৪টা (৪০০) ফেরত → শোধের বাড়তি ১০০
        $invoice = $this->sell('10', '100', ['paid' => '700', 'customer_id' => $dealer->id])['invoice'];

        $result = app(PosService::class)->takeBack([
            'document_no' => $invoice->document_no,
            'warehouse_id' => $this->warehouse->id,
            'reason_code_id' => (int) ReasonCode::query()->inContext(ReasonCode::SALES_RETURN)->where('code', 'DAMAGE')->value('id'),
            'refund' => true,
        ], [
            ['product_id' => $this->product->id, 'qty' => '4', 'sales_invoice_line_id' => $invoice->lines()->value('id')],
        ]);

        $this->assertNotNull($result['refund']);
        $this->assertSame(0, bccomp((string) $result['refund']->amount, '100', 4), '⛔ ফেরত গেছে '.$result['refund']->amount.', ১০০ নয়।');
    }

    // ── ৬ · রসিদের তালিকা — "কাউন্টার" দাগ আর সব / কাউন্টার / অফিস ────────

    public function test_the_receipt_list_marks_counter_receipts_and_filters_counter_and_office(): void
    {
        $this->sell('1', '100', ['paid' => '100']);
        $counter = $this->counterReceipts()->sole();
        $office = $this->officeReceipt('55');
        $old = $this->oldCollection('77');

        $all = $this->get(route('accounts.voucher.index', [Voucher::RECEIPT]))->assertOk();
        $ids = $all->viewData('vouchers')->getCollection()->modelKeys();
        $this->assertContains($counter->id, $ids);
        $this->assertContains($office->id, $ids);
        $all->assertSee('data-counter-receipt', false)
            ->assertSee(__('accounts::voucher.counter_badge'))
            ->assertSee('data-old-papers', false)
            ->assertSee((string) $old->document_no);

        $onlyCounter = $this->get(route('accounts.voucher.index', [Voucher::RECEIPT, 'origin' => 'counter']))->assertOk();
        $ids = $onlyCounter->viewData('vouchers')->getCollection()->modelKeys();
        $this->assertContains($counter->id, $ids);
        $this->assertNotContains($office->id, $ids, '⛔ "কাউন্টার" ছাঁকনিতে অফিসের রসিদ এসেছে।');

        $onlyOffice = $this->get(route('accounts.voucher.index', [Voucher::RECEIPT, 'origin' => 'office']))->assertOk();
        $ids = $onlyOffice->viewData('vouchers')->getCollection()->modelKeys();
        $this->assertContains($office->id, $ids, '⛔ "অফিস" ছাঁকনিতে উৎসহীন (হাতে লেখা) রসিদ হারিয়েছে।');
        $this->assertNotContains($counter->id, $ids, '⛔ "অফিস" ছাঁকনিতে কাউন্টারের রসিদ এসেছে।');
        $onlyOffice->assertDontSee('data-counter-receipt', false);

        // ⓘ অচেনা মান নীরবে বাদ — "সব"
        $junk = $this->get(route('accounts.voucher.index', [Voucher::RECEIPT, 'origin' => 'nowhere']))->assertOk();
        $this->assertContains($counter->id, $junk->viewData('vouchers')->getCollection()->modelKeys());
        $this->assertContains($office->id, $junk->viewData('vouchers')->getCollection()->modelKeys());
    }

    /** ⓘ উৎসের ঘর কেবল রসিদের তালিকায় — পরিশোধের তালিকায় ঘরটা আঁকা হয় না */
    public function test_the_origin_filter_is_drawn_only_on_the_receipt_list(): void
    {
        $this->get(route('accounts.voucher.index', [Voucher::RECEIPT]))->assertOk()
            ->assertSee('data-filter-section="origin"', false)
            ->assertSee(__('accounts::voucher.filter.origin_counter'))
            ->assertSee(__('accounts::voucher.filter.origin_office'));

        $this->get(route('accounts.voucher.index', [Voucher::PAYMENT]))->assertOk()
            ->assertDontSee('data-filter-section="origin"', false);
    }

    // ── ৭ · ছাপা — বিলের কাগজে টাকার সারি রসিদ থেকে ──────────────────────

    public function test_the_counter_bill_print_lists_the_receipt(): void
    {
        $invoice = $this->sell('1', '250', ['paid' => '250'])['invoice'];
        $receipt = $this->counterReceipts()->sole();

        // ⓘ কাউন্টারের পরের পাতা — ৮০mm বিল খোলে; বিলের টাকার ছক রসিদ পড়ে এই সম্পর্ক থেকেই ([[SalesPrintController::paymentsAgainst()]])
        $this->get(route('sales.print.invoice', ['invoice' => $invoice->id, 'paper' => '80mm']))->assertOk();
        $this->assertSame([$receipt->id], $invoice->fresh()->receiptVouchers()->pluck('vouchers.id')->map(fn ($id) => (int) $id)->all(),
            '⛔ বিলের কাগজের টাকার ছকে কাউন্টারের রসিদ নেই।');

        // ⓘ রসিদের নিজের পাতাও খোলে — "বিক্রয়ে যোগ করা জমা"
        $this->get(route('accounts.voucher.show', $receipt))->assertOk()
            ->assertSee(__('accounts::voucher.origin_sales_deposit'));
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $extra
     * @return array{invoice: SalesInvoice, change: string}
     */
    private function sell(string $qty, string $rate, array $extra): array
    {
        return app(PosService::class)->checkout([
            'warehouse_id' => $this->warehouse->id,
            ...$extra,
        ], [
            ['product_id' => $this->product->id, 'qty' => $qty, 'rate' => $rate],
        ]);
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Voucher> */
    private function counterReceipts()
    {
        return Voucher::query()->withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('type', Voucher::RECEIPT)
            ->where('origin', Voucher::ORIGIN_COUNTER)
            ->with('lines')
            ->orderBy('id')
            ->get();
    }

    private function till(): Account
    {
        return app(CashTillService::class)->ensurePrimaryTill()->account;
    }

    private function debits(Voucher $voucher, int $accountId, string $amount): bool
    {
        return $voucher->lines->contains(fn ($l) => (int) $l->account_id === $accountId && bccomp((string) $l->debit, $amount, 4) === 0);
    }

    private function receivableOf(int $customerId, string $date): string
    {
        $row = LedgerEntry::query()
            ->where('account_id', StandardChart::find(StandardChart::RECEIVABLE)->id)
            ->where('party_type', 'customer')->where('party_id', $customerId)
            ->where('trx_date', $date)
            ->selectRaw('COALESCE(SUM(debit), 0) as d, COALESCE(SUM(credit), 0) as c')
            ->first();

        return bcsub((string) $row->d, (string) $row->c, 4);
    }

    private function bkashAccount(): Account
    {
        return Account::query()->create([
            'company_id' => $this->company->id,
            'code' => '1105-BKCTR',
            'name_en' => 'bKash Counter',
            'name_bn' => 'বিকাশ কাউন্টার',
            'parent_id' => StandardChart::find(StandardChart::MOBILE_MONEY)->id,
            'type' => Account::ASSET,
            'nature' => Account::DEBIT,
            'money_kind' => Account::MFS,
            'is_active' => true,
            'status' => DocumentStatus::CONFIRMED,
        ]);
    }

    private function bkashMethod(Account $account): PaymentMethod
    {
        return PaymentMethod::query()->create([
            'company_id' => $this->company->id,
            'code' => 'BKCTR',
            'name_en' => 'bKash',
            'name_bn' => 'বিকাশ',
            'kind' => 'mfs',
            'account_id' => $account->id,
            'needs_reference' => true,
            'is_active' => true,
        ]);
    }

    private function flowFor(string $action): void
    {
        $flow = ApprovalFlow::query()->create([
            'company_id' => $this->company->id,
            'module' => VoucherApproval::MODULE,
            'action' => $action,
            'document_type' => '',
            'threshold_amount' => null,
            'is_active' => true,
        ]);
        ApprovalFlowStep::query()->create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => 'user', 'approver_id' => $this->secondSigner()->id]);
        $this->app->forgetInstance(ApprovalEngine::class);
    }

    /** অফিসের রসিদ — হাতে লেখা, উৎস খালি */
    private function officeReceipt(string $amount): Voucher
    {
        $vouchers = app(VoucherService::class);
        $dealer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();

        $voucher = $vouchers->create([
            'type' => Voucher::RECEIPT,
            'trx_date' => Carbon::today()->toDateString(),
            'party_type' => 'customer',
            'party_id' => $dealer->id,
            'instrument' => 'cash',
            'narration' => 'office receipt',
        ], $vouchers->twoLineEntry(Voucher::RECEIPT, (int) StandardChart::find(StandardChart::RECEIVABLE)->id, (int) $this->till()->id, $amount));

        $voucher = $vouchers->post($voucher);
        $this->assertNull($voucher->origin);

        return $voucher;
    }

    /** পুরনো আদায় — আদায়ের সেবা সরাসরি */
    private function oldCollection(string $amount): Collection
    {
        $collections = app(CollectionService::class);

        return $collections->confirm($collections->create([
            'customer_id' => Customer::query()->where('name_en', 'Rahim Traders')->value('id'),
            'account_id' => $this->till()->id,
            'trx_date' => Carbon::today()->toDateString(),
            'amount' => $amount,
            'instrument' => 'cash',
        ], []));
    }
}
