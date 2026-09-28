<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\DataScope;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Accounts\Models\Account;
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
use App\Modules\Sales\Services\DirectSaleService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * রাখা বিলটা একই কাউন্টারে অপেক্ষা করে — আর সেখান থেকেই পাকা হয়।
 *
 * ── ⭐ মালিকের নকশা, ২৬ সেপ্টেম্বর ২০২৬ (রাতে) ───────────────────────
 * *"etokkhon bill kore rakhlo ta save thakbe … sudu challan inv print hobe
 * na approval e zabe na. conf. korte hole abaer ei skinei aste hobe"* —
 * আর সীমা নিয়ে: *"bill atkanor kotha cilo conf/নিশ্চিত করুন e kintu
 * খসড়া hobe"*। একজন ক্রেতার একটাই খোলা খসড়া: *"ei khosora bill conf na
 * hole r ekta bill entry nibe na tar name age bill conf korbe noy batil
 * korbe noy edite korbe"*।
 *
 * ── ⓘ এই ফাইল কী মাপে ───────────────────────────────────────────────
 * ⓵ খসড়া রাখা: চালান ও বিল খসড়া, পর্দার ছবি বিলে, কোনো ভাউচার, সই,
 *   মজুদ বা খাতা নয় — অনুমোদনের ছক বসানো থাকলেও।
 * ⓶ ফেরানো: `?draft=ID`, আর একই কাগজ দুইটা পাকা বা আবার রাখা — নম্বর
 *   বদলায় না, উপহার দ্বিগুণ হয় না।
 * ⓷ একটাই খোলা খসড়া — শাখা পেরিয়েও।
 * ⓸ বাতিল — চাবি, কারণ, আর অন্য কোম্পানির খসড়া ৪০৪।
 * ⓹ হাতে লেখা চালান নম্বর।
 *
 * ⚠️ সবকিছু HTTP দিয়ে, কারণ নিয়মের অর্ধেক (যাচাই, রুট-বাঁধাই, চাবি)
 * কেবল দরজাতেই থাকে। ⛔ আর প্রতিটা দাবি ডাটাবেসের অবস্থা মাপে — কেবল
 * ৩০২ নয়, কারণ যাচাই-ব্যর্থতাও ৩০২।
 */
final class TheParkedBillWaitsAtTheSameCounterTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Customer $customer;

    private Customer $other;

    private Warehouse $warehouse;

    private Product $product;

    private Product $giftProduct;

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

        $this->customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $this->other = Customer::query()->whereKeyNot($this->customer->id)->orderBy('id')->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->orderBy('id')->firstOrFail();
        $this->giftProduct = Product::query()->whereKeyNot($this->product->id)->orderBy('id')->firstOrFail();

        /* ⓘ সীমা এখানে আলাদা দাবির বিষয় — বাকিগুলোতে সে যেন ভুল কারণে থামাতে না পারে */
        app(SettingsService::class)->set('customer.credit_limit_enabled', false);
    }

    // ── প্রস্তুতি ────────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $extra */
    private function sell(array $extra = [], ?Customer $for = null, int $qty = 10): TestResponse
    {
        return $this->post(route('sales.direct.store'), [
            'customer_id' => ($for ?? $this->customer)->id,
            'warehouse_id' => $this->warehouse->id,
            'lines' => [['product_id' => $this->product->id, 'qty' => (string) $qty, 'rate' => '100']],
            ...$extra,
        ]);
    }

    /** @param  array<string, mixed>  $extra */
    private function park(array $extra = [], ?Customer $for = null, int $qty = 10): SalesInvoice
    {
        $this->sell(['save_as_draft' => '1', ...$extra], $for, $qty)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('sales.direct.create'));

        return SalesInvoice::query()->latest('id')->firstOrFail();
    }

    private function challanOf(SalesInvoice $invoice): DeliveryChallan
    {
        $id = $invoice->fresh()->load('lines.challanLine')->lines->first()?->challanLine?->delivery_challan_id;

        $this->assertNotNull($id, 'দৃশ্যটাই বানানো যায়নি — বিলের সারি কোনো চালানে বাঁধা নয়।');

        return DeliveryChallan::query()->findOrFail($id);
    }

    private function floor(): string
    {
        return app(StockService::class)->floorQty($this->product, $this->warehouse);
    }

    /**
     * ব্যাংকে ১,০০০ টাকার জমা — যেটা সত্যিই সই চায়।
     *
     * ⚠️ নগদ নয়, ইচ্ছাকৃত: ২১ সেপ্টেম্বর থেকে নিজের বাক্সে নগদ রসিদ সই চায়
     * না ([[VoucherApproval::stopping()]] `landsInCash`)। ⛔ নগদ দিলে "খসড়া
     * সইয়ে যায়নি" দাবিটা কখনো লাল হতে পারত না — সইয়ের অনুরোধ এমনিতেই হত না।
     *
     * @return array<string, mixed>
     */
    private function bankDeposit(): array
    {
        /*
         * ⓘ ডেমোতে কোনো ব্যাংকের পাতা-খাত নেই (প্রথম চালানোয় ধরা পড়ল), তাই
         * [[CashOnlyLandsInYourOwnTillTest]]-এর মতো: একটা বৈধ নগদ খাতের
         * প্রতিটা ঘর নকল করে, কেবল পরিচয় আর ধরন বদলে।
         */
        $bank = Account::query()->ofMoneyKind(Account::BANK)->postable()->active()->orderBy('id')->first();

        if ($bank === null) {
            $sibling = Account::query()->ofMoneyKind(Account::CASH)->postable()->orderBy('id')->firstOrFail();

            $bank = $sibling->replicate(['public_id']);
            $bank->forceFill([
                'code' => 'BANK-PARK',
                'name_en' => 'BANK-PARK',
                'name_bn' => 'BANK-PARK',
                'money_kind' => Account::BANK,
            ])->save();
        }

        $this->assertTrue($bank->fresh()->isBank(), 'দৃশ্যটাই বানানো যায়নি — খাতটা ব্যাংক নয়।');

        return ['deposits' => [['amount' => '1000', 'account_id' => $bank->id, 'reference' => 'TRX-1']]];
    }

    private function counterVouchers(): int
    {
        return Voucher::query()->where('origin', Voucher::ORIGIN_COUNTER)->count();
    }

    private function counterDepositFlow(): void
    {
        $flow = ApprovalFlow::query()->create([
            'company_id' => CompanyContext::id(),
            'module' => VoucherApproval::MODULE,
            'action' => VoucherApproval::COUNTER_DEPOSIT,
            'document_type' => '',
            'threshold_amount' => null,
            'is_active' => true,
        ]);

        ApprovalFlowStep::query()->create([
            'approval_flow_id' => $flow->id,
            'level' => 1,
            'approver_type' => 'user',
            'approver_id' => $this->owner->id,
        ]);
    }

    // ── ⓵ খসড়া রাখা ─────────────────────────────────────────────────────

    /**
     * ⭐ "খসড়া রাখুন": দুইটা কাগজ খসড়া, পর্দার ছবি বিলে, আর কিছুই নড়ে না।
     *
     * ⚠️ বিপজ্জনক ইনপুট ইচ্ছাকৃত: কাউন্টার-জমার **ছক বসানো** আর ১,০০০
     * টাকা জমা। ⛔ আগে হাতের খসড়াও এই জমাকে সইয়ে পাঠাত — মালিক ঠিক
     * এটাই চাননি: *"approval e zabe na"*।
     */
    public function test_a_parked_bill_keeps_the_screen_and_moves_nothing(): void
    {
        $this->counterDepositFlow();

        $floor = $this->floor();
        $movements = DB::table('inv_stock_movements')->count();
        $ledger = DB::table('ledger_entries')->count();
        $approvals = DB::table('approvals')->count();
        $vouchers = $this->counterVouchers();

        $screen = ['cart' => [['product_id' => $this->product->id, 'qty' => 10]], 'note' => 'ফিরে এসে নেবেন'];

        $response = $this->sell([
            'save_as_draft' => '1',
            ...$this->bankDeposit(),
            'vehicle_no' => 'DHA-11',
            'screen_state' => json_encode($screen),
        ]);

        $invoice = SalesInvoice::query()->latest('id')->firstOrFail();
        $challan = $this->challanOf($invoice);

        $response->assertSessionHasNoErrors()
            ->assertRedirect(route('sales.direct.create'))
            ->assertSessionHas('saved', __('sales::message.draft_parked', [
                'invoice' => $invoice->document_no,
                'challan' => $challan->document_no,
            ]));

        $this->assertSame(DocumentStatus::DRAFT, $invoice->status, '⛔ খসড়া রাখতে চেয়ে বিলটা পাকা হয়ে গেছে।');
        $this->assertSame(DocumentStatus::DRAFT, $challan->status, '⛔ খসড়া রাখতে চেয়ে চালানটা পাকা হয়ে গেছে।');

        $this->assertIsArray($invoice->counter_draft, '⛔ পর্দার ছবিটা বিলে রাখা হয়নি — ফিরে এসে কিছুই পাওয়া যাবে না।');
        /* ⓘ `assertEquals`: MySQL-এর json চাবিগুলো নিজের ক্রমে সাজায় — ক্রম দাবি নয় */
        $this->assertEquals($screen, $invoice->counter_draft['screen'],
            '⛔ পর্দার ছবিটা যেমন পাঠানো হয়েছিল তেমন রাখা হয়নি।');
        $this->assertSame('DHA-11', $invoice->counter_draft['fields']['vehicle_no'] ?? null,
            '⛔ সাধারণ ঘরগুলো (গাড়ির নম্বর) ছবিতে নেই।');

        $this->assertSame($vouchers, $this->counterVouchers(),
            '⛔ খসড়ায় জমার ভাউচার তৈরি হয়েছে — মালিক: জমাগুলো পর্দার ছবিতেই থাকবে।');
        $this->assertSame($approvals, DB::table('approvals')->count(),
            '⛔ খসড়া সইয়ের অনুরোধ পাঠিয়েছে — মালিক: *"approval e zabe na"*।');
        $this->assertSame($movements, DB::table('inv_stock_movements')->count(), '⛔ খসড়ায় মজুদের সারি বসেছে।');
        $this->assertSame($floor, $this->floor(), '⛔ খসড়া অবস্থাতেই মাল গুদাম থেকে বেরিয়েছে।');
        $this->assertSame($ledger, DB::table('ledger_entries')->count(), '⛔ খসড়ায় খাতায় কিছু বসেছে।');
    }

    /**
     * ⛔ পাল্টা-দাবি: ছকটা সত্যিই কাজ করে — খসড়া না চাইলে একই জমা সইয়ে যায়।
     *
     * ⚠️ এটা ছাড়া উপরের "সইয়ে যায়নি" সবুজ হতে পারত কেবল এই কারণে যে ছকটাই
     * মরা। ⓘ আর এই পথটা (সই লাগবে, হাতের খসড়া নয়) মালিকের আগের নকশা —
     * অক্ষত থাকার কথা, আর সে কাউন্টারের "পেন্ডিং"-এ যায় না।
     */
    public function test_without_the_draft_button_the_same_deposit_still_goes_for_signature(): void
    {
        $this->counterDepositFlow();

        $approvals = DB::table('approvals')->count();

        $this->sell($this->bankDeposit())->assertSessionHasNoErrors();

        $invoice = SalesInvoice::query()->latest('id')->firstOrFail();

        $this->assertSame(DocumentStatus::DRAFT, $invoice->status);
        $this->assertSame(1, $this->counterVouchers(), '⛔ সই-লাগা জমার ভাউচার তৈরি হয়নি — ছকটা মরা।');
        $this->assertSame($approvals + 1, DB::table('approvals')->count(), '⛔ সইয়ের অনুরোধ যায়নি — ছকটা মরা।');
        $this->assertNull($invoice->counter_draft,
            '⛔ সইয়ের অপেক্ষার বিল কাউন্টারের "পেন্ডিং"-এ ঢুকেছে — ওটা বিলের পাতা থেকে শেষ হয়।');
    }

    // ── ⓶ ফেরানো ────────────────────────────────────────────────────────

    /** ⭐ `?draft=ID` পর্দাকে খসড়াটা ফিরিয়ে দেয় — আর "পেন্ডিং" তালিকায় সে ক্রেতার নামে। */
    public function test_the_counter_page_brings_the_draft_back(): void
    {
        $screen = ['cart' => [['product_id' => $this->product->id, 'qty' => 10]]];
        $invoice = $this->park(['screen_state' => json_encode($screen)]);
        $challan = $this->challanOf($invoice);

        $page = $this->get(route('sales.direct.create', ['draft' => $invoice->id]))->assertOk();

        $resume = $page->viewData('resume');

        $this->assertIsArray($resume, '⛔ `?draft=ID` দিয়েও পর্দা খালি খুলেছে।');
        $this->assertSame($invoice->id, $resume['invoiceId']);
        $this->assertSame($invoice->document_no, $resume['invoiceNo']);
        $this->assertSame($challan->document_no, $resume['challanNo']);
        $this->assertSame($this->customer->id, $resume['customerId']);
        $this->assertEquals($screen, $resume['screen'], '⛔ পর্দার ছবিটা ফেরেনি।');

        $pending = $page->viewData('pendingDrafts');

        $this->assertSame([$invoice->id], array_column($pending[$this->customer->id] ?? [], 'id'),
            '⛔ খসড়াটা ক্রেতার "পেন্ডিং" তালিকায় নেই।');
        $this->assertArrayNotHasKey($this->other->id, $pending,
            '⛔ অন্য ক্রেতার নামে একটা খসড়া দেখাচ্ছে।');
    }

    /**
     * ⭐ ফিরে এসে "নিশ্চিত করুন" — **সেই একই** চালান ও বিল পাকা, নম্বর অক্ষত।
     *
     * ⛔ নতুন কাগজ বানালে পুরনো খসড়াটা ঝুলে থাকত (সীমা আটকে), আর ক্রেতার
     * হাতে দুইটা নম্বর যেত।
     */
    public function test_confirming_a_resumed_draft_finishes_the_same_two_papers(): void
    {
        $invoice = $this->park();
        $challan = $this->challanOf($invoice);

        $invoiceNo = $invoice->document_no;
        $challanNo = $challan->document_no;
        $invoices = SalesInvoice::query()->count();
        $challans = DeliveryChallan::query()->count();
        $floor = $this->floor();

        $this->sell(['save_as_draft' => '0', 'resume_invoice_id' => $invoice->id, 'deposit' => '1000'])
            ->assertSessionHasNoErrors();

        $this->assertSame($invoices, SalesInvoice::query()->count(), '⛔ খসড়া পাকা করতে গিয়ে নতুন একটা বিল তৈরি হয়েছে।');
        $this->assertSame($challans, DeliveryChallan::query()->count(), '⛔ খসড়া পাকা করতে গিয়ে নতুন একটা চালান তৈরি হয়েছে।');

        $invoice = $invoice->fresh();
        $challan = $challan->fresh();

        $this->assertSame(DocumentStatus::CONFIRMED, $invoice->status, '⛔ ফেরানো খসড়াটা পাকা হয়নি।');
        $this->assertSame(DocumentStatus::CONFIRMED, $challan->status, '⛔ ফেরানো খসড়ার চালানটা পাকা হয়নি।');
        $this->assertSame($invoiceNo, $invoice->document_no, '⛔ পাকা করতে গিয়ে বিলের নম্বর বদলে গেছে।');
        $this->assertSame($challanNo, $challan->document_no, '⛔ পাকা করতে গিয়ে চালানের নম্বর বদলে গেছে।');
        $this->assertNull($invoice->counter_draft, '⛔ পাকা বিলটা এখনো "পেন্ডিং"-এ — আবার খোলা যাবে।');

        $this->assertSame(1, Voucher::query()->where('origin', Voucher::ORIGIN_COUNTER)
            ->where('against_id', $invoice->id)->count(), '⛔ পাকা করার সময় জমার ভাউচার বসেনি।');

        $this->assertSame(0, bccomp(bcsub($floor, $this->floor(), 4), '10', 4),
            '⛔ বিল পাকা হলো, অথচ মাল গুদাম থেকে বেরোয়নি।');
    }

    /**
     * ⭐ দুইবার রাখা — একই কাগজ, উপহার একবারই।
     *
     * ⛔ [[DirectSaleService::writeGifts()]] কেবল যোগ করে; পুরনো উপহার না মুছলে
     * প্রতিবার খুলে রাখলে উপহার দ্বিগুণ হত — আর পাকা হওয়ার দিন দ্বিগুণ মাল
     * ফ্রি ভাণ্ডার থেকে বেরোত।
     */
    public function test_parking_again_updates_the_same_draft_without_doubling_gifts(): void
    {
        $gifts = ['gifts' => [['product_id' => $this->giftProduct->id, 'qty' => '1']]];

        $invoice = $this->park($gifts);
        $challan = $this->challanOf($invoice);

        $this->assertSame(1, $challan->giftLines()->count(), 'দৃশ্যটাই বানানো যায়নি — উপহারের সারি বসেনি।');

        $invoiceNo = $invoice->document_no;
        $challanNo = $challan->document_no;
        $invoices = SalesInvoice::query()->count();

        foreach ([12, 14] as $qty) {
            $this->sell(['save_as_draft' => '1', 'resume_invoice_id' => $invoice->id, ...$gifts], null, $qty)
                ->assertSessionHasNoErrors()
                ->assertRedirect(route('sales.direct.create'));
        }

        $this->assertSame($invoices, SalesInvoice::query()->count(), '⛔ আবার রাখতে গিয়ে নতুন বিল তৈরি হয়েছে।');
        $this->assertSame(1, $challan->fresh()->giftLines()->count(), '⛔ দুইবার রাখায় উপহারের সারি জমে গেছে।');

        $invoice = $invoice->fresh(['lines']);

        $this->assertSame(DocumentStatus::DRAFT, $invoice->status);
        $this->assertNotNull($invoice->counter_draft);
        $this->assertSame($invoiceNo, $invoice->document_no, '⛔ আবার রাখায় বিলের নম্বর বদলেছে।');
        $this->assertSame($challanNo, $challan->fresh()->document_no, '⛔ আবার রাখায় চালানের নম্বর বদলেছে।');

        /* ⓘ শেষ পর্দাটাই সত্য — ১৪টা, আর সারি একটাই */
        $this->assertCount(1, $invoice->lines, '⛔ আবার রাখায় বিলের সারি জমে গেছে।');
        $this->assertSame(0, bccomp((string) $invoice->total, '1400', 4),
            '⛔ বিলটা শেষবার রাখা পর্দার অঙ্ক নেয়নি।');
    }

    /** ⛔ অন্য ক্রেতার নামে খসড়া ফেরানো যায় না — অন্যের কাগজে অন্যের মাল বসত। */
    public function test_a_draft_cannot_be_resumed_for_another_customer(): void
    {
        $invoice = $this->park();

        $this->sell(['save_as_draft' => '0', 'resume_invoice_id' => $invoice->id], $this->other)
            ->assertSessionHasErrors(['customer_id' => __('sales::validation.parked_draft_other_customer', [
                'no' => $invoice->document_no,
            ])]);

        $invoice = $invoice->fresh();

        $this->assertSame($this->customer->id, (int) $invoice->customer_id, '⛔ খসড়াটার ক্রেতা বদলে গেছে।');
        $this->assertSame(DocumentStatus::DRAFT, $invoice->status);
        $this->assertNotNull($invoice->counter_draft, '⛔ বাধা পেয়েও খসড়াটা "পেন্ডিং" থেকে সরে গেছে।');
    }

    /**
     * ⛔ যে খসড়া আর খোলা নেই তাকে ফেরানো যায় না — তিনটা আকারে।
     *
     * ⓘ পাকা হয়ে গেছে · বাতিল হয়ে গেছে · আর কাউন্টারের খসড়াই নয় (সইয়ের
     * অপেক্ষার বিল, `counter_draft` নেই)। ⚠️ শেষটাই বিপজ্জনক ইনপুট: সে
     * সত্যিই `draft`, তাই কেবল অবস্থা দেখলে দরজা খুলে যেত।
     */
    public function test_a_draft_that_is_no_longer_open_cannot_be_resumed(): void
    {
        $gone = __('sales::validation.parked_draft_gone');

        /*
         * ⚠️ ছকটা **প্রথম অনুরোধের আগেই** বসাতে হয়। ⓘ পরীক্ষায় একই অ্যাপ
         * সব অনুরোধে বেঁচে থাকে, আর অনুমোদনের ইঞ্জিন প্রথম প্রশ্নের উত্তর
         * মনে রাখে — পরে বসানো ছক সে দেখত না, আর ⓷-এর বিলটা সোজা পাকা হত।
         * ⓵ আর ⓶-এ কোনো জমা নেই, তাই ছকটা তাদের ছোঁয় না।
         */
        $this->counterDepositFlow();

        // ⓵ পাকা
        $confirmed = $this->park();
        $this->sell(['resume_invoice_id' => $confirmed->id])->assertSessionHasNoErrors();
        $this->assertSame(DocumentStatus::CONFIRMED, $confirmed->fresh()->status, 'দৃশ্যটাই বানানো যায়নি।');

        $this->sell(['resume_invoice_id' => $confirmed->id])
            ->assertSessionHasErrors(['resume_invoice_id' => $gone]);

        // ⓶ বাতিল
        $discarded = $this->park();
        $this->post(route('sales.direct.discard', $discarded), ['reason' => 'ক্রেতা আসেননি'])
            ->assertSessionHasNoErrors();

        $this->sell(['resume_invoice_id' => $discarded->id])
            ->assertSessionHasErrors(['resume_invoice_id' => $gone]);

        // ⓷ কাউন্টারের খসড়াই নয় — সইয়ের অপেক্ষার বিল
        $this->sell($this->bankDeposit(), $this->other)->assertSessionHasNoErrors();
        $held = SalesInvoice::query()->latest('id')->firstOrFail();

        $this->assertSame(DocumentStatus::DRAFT, $held->status, 'দৃশ্যটাই বানানো যায়নি — সইয়ের অপেক্ষার বিল খসড়া নয়।');
        $this->assertNull($held->counter_draft, 'দৃশ্যটাই বানানো যায়নি — এটা কাউন্টারের খসড়া হয়ে গেছে।');

        $invoices = SalesInvoice::query()->count();

        $this->sell(['save_as_draft' => '1', 'resume_invoice_id' => $held->id], $this->other)
            ->assertSessionHasErrors(['resume_invoice_id' => $gone]);

        $this->assertSame($invoices, SalesInvoice::query()->count());
        $this->assertNull($held->fresh()->counter_draft,
            '⛔ সইয়ের অপেক্ষার বিলটা কাউন্টারের খসড়ায় বদলে গেছে।');
    }

    // ── ⓷ একটাই খোলা খসড়া ────────────────────────────────────────────────

    /**
     * ⛔ খোলা খসড়া থাকলে সেই ক্রেতার নতুন বিল নেই — নিশ্চিত বা খসড়া, দুইটাই।
     */
    public function test_an_open_draft_blocks_a_new_bill_for_that_customer(): void
    {
        $invoice = $this->park();

        $invoices = SalesInvoice::query()->count();
        $blocked = __('sales::validation.open_draft_blocks_new_bill', ['no' => $invoice->document_no]);

        $this->sell(['save_as_draft' => '0'])->assertSessionHasErrors(['customer_id' => $blocked]);
        $this->sell(['save_as_draft' => '1'])->assertSessionHasErrors(['customer_id' => $blocked]);

        $this->assertSame($invoices, SalesInvoice::query()->count(),
            '⛔ খোলা খসড়া থাকা সত্ত্বেও একই ক্রেতার নতুন বিল বসেছে।');

        /* ⭐ পাল্টা-দাবি: দেয়ালটা ঐ ক্রেতার — অন্য ক্রেতা আটকায় না */
        $this->other->forceFill(['credit_limit' => '100000000'])->save();

        $this->sell(['save_as_draft' => '0'], $this->other)->assertSessionHasNoErrors();

        $this->assertSame($invoices + 1, SalesInvoice::query()->count(),
            '⛔ একজনের খসড়া আরেকজনের বিলও আটকে দিয়েছে।');
    }

    /**
     * ⛔ অন্য শাখার কাউন্টারে রাখা খসড়াও আটকায়।
     *
     * ⚠️ বিপজ্জনক ইনপুট: বিক্রেতা কেবল নিজের শাখা দেখেন, আর খসড়াটা অন্য
     * শাখায় — তাঁর চোখে সেটা **নেই**। ⓘ তাই প্রথমে মাপা হয় যে খসড়াটা
     * সত্যিই তাঁর কাছে অদৃশ্য; নাহলে দেয়ালটা শাখা পেরিয়ে দেখে কি না, সেটা
     * এই দাবি মাপতই না।
     */
    public function test_a_draft_parked_in_another_branch_still_blocks(): void
    {
        $invoice = $this->park();

        $home = (int) $this->company->defaultBranch()?->id;
        $elsewhere = Branch::query()->where('company_id', $this->company->id)
            ->whereKeyNot($home)->orderBy('id')->firstOrFail();

        DB::table('sal_invoices')->where('id', $invoice->id)->update(['branch_id' => $elsewhere->id]);

        UserDataScope::query()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->owner->id,
            'scope_type' => UserDataScope::BRANCH,
            'scope_id' => $home,
        ]);
        app()->forgetInstance(DataScope::class);

        $this->assertNull(SalesInvoice::query()->find($invoice->id),
            'দৃশ্যটাই বানানো যায়নি — অন্য শাখার খসড়াটা বিক্রেতার চোখে পড়ছে।');

        $invoices = SalesInvoice::acrossBranches()->count();

        $this->sell(['save_as_draft' => '0'])
            ->assertSessionHasErrors(['customer_id' => __('sales::validation.open_draft_blocks_new_bill', [
                'no' => $invoice->document_no,
            ])]);

        $this->assertSame($invoices, SalesInvoice::acrossBranches()->count(),
            '⛔ অন্য শাখায় খসড়া খোলা, অথচ এই শাখায় একই ক্রেতার নতুন বিল বসেছে।');
    }

    // ── ⓸ বাতিল ─────────────────────────────────────────────────────────

    /**
     * ⛔ চাবি ছাড়া বাতিল নেই — ⭐ আর চাবিটাই দরজা খোলে। একই মানুষ, দুইবার।
     *
     * ⓘ ভূমিকাহীন নতুন ব্যবহারকারী, কোম্পানির সদস্য; প্রথমে চাবি ছাড়া ৪০৩
     * আর খসড়া অক্ষত, তারপর কেবল `sales.challan.create` দিয়ে — খুলে যায়।
     */
    public function test_discarding_needs_the_counter_key_and_the_key_opens_it(): void
    {
        $invoice = $this->park();
        $challan = $this->challanOf($invoice);

        $clerk = User::factory()->create(['current_company_id' => $this->company->id]);
        $clerk->companies()->attach($this->company->id, ['is_active' => true]);

        $this->assertFalse($clerk->can('sales.challan.create'),
            'দৃশ্যটাই বানানো যায়নি — ভূমিকাহীন লোকটার কাউন্টারের চাবি আছে।');

        $this->actingAs($clerk)
            ->post(route('sales.direct.discard', $invoice), ['reason' => 'ভুল পার্টি'])
            ->assertForbidden();

        $this->assertSame(DocumentStatus::DRAFT, $invoice->fresh()->status, '⛔ ৪০৩ ফিরেছে, তবু খসড়াটা বাতিল হয়েছে।');
        $this->assertNotNull($invoice->fresh()->counter_draft);

        $clerk->givePermissionTo('sales.challan.create');
        $clerk = $clerk->fresh();

        /* ⛔ কারণ বাধ্যতামূলক — বাতিলের কাগজে সেটাই থাকে */
        $this->actingAs($clerk)
            ->post(route('sales.direct.discard', $invoice), ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->assertSame(DocumentStatus::DRAFT, $invoice->fresh()->status, '⛔ কারণ ছাড়াই খসড়াটা বাতিল হয়েছে।');

        $this->actingAs($clerk)
            ->post(route('sales.direct.discard', $invoice), ['reason' => 'ভুল পার্টি'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('sales.direct.create'))
            ->assertSessionHas('saved', __('sales::message.draft_discarded', ['no' => $invoice->document_no]));

        $invoice = $invoice->fresh();

        $this->assertSame(DocumentStatus::CANCELLED, $invoice->status, '⛔ চাবি দিয়েও বিলটা বাতিল হয়নি।');
        $this->assertSame(DocumentStatus::CANCELLED, $challan->fresh()->status,
            '⛔ বিল বাতিল, অথচ চালানটা খসড়া হয়ে ঝুলে আছে।');
        $this->assertNull($invoice->counter_draft, '⛔ বাতিল খসড়াটা এখনো "পেন্ডিং"-এ।');

        /* ⭐ বাতিলের পরে ক্রেতা আবার বিল নিতে পারেন — দেয়ালটা খোলা খসড়ার জন্যই ছিল */
        $this->actingAs($this->owner);
        $this->sell(['save_as_draft' => '0'])->assertSessionHasNoErrors();

        $this->assertSame(DocumentStatus::CONFIRMED, SalesInvoice::query()->latest('id')->firstOrFail()->status,
            '⛔ খসড়া বাতিলের পরেও ক্রেতার নতুন বিল আটকে আছে।');
    }

    /**
     * ⛔ অন্য কোম্পানির খসড়া "নেই" — ৪০৪, আর কিছুই বাতিল হয় না।
     *
     * ⓘ খসড়াটাকে ডাটাবেসে অন্য কোম্পানিতে সরানো হয় — অর্থাৎ ঠিক সেই সারি
     * যেটা অন্য কোম্পানির কাউন্টারে রাখা থাকত।
     */
    public function test_another_companys_draft_cannot_be_discarded(): void
    {
        $invoice = $this->park();

        $foreign = Company::query()->whereKeyNot($this->company->id)->orderBy('id')->firstOrFail();

        DB::table('sal_invoices')->where('id', $invoice->id)->update(['company_id' => $foreign->id]);

        $this->post(route('sales.direct.discard', $invoice->id), ['reason' => 'অন্যের কাগজ'])
            ->assertNotFound();

        $row = DB::table('sal_invoices')->where('id', $invoice->id)->first();

        $this->assertSame(DocumentStatus::DRAFT, $row->status, '⛔ অন্য কোম্পানির খসড়া বাতিল হয়ে গেছে।');
        $this->assertNotNull($row->counter_draft);
    }

    // ── ⭐ খসড়া তালিকা — মালিকের নির্দেশ, ২৮ সেপ্টেম্বর ২০২৬ ────────────────

    /**
     * তালিকায় এই কোম্পানির রাখা খসড়া আসে, খোলার লিংকসহ — অন্য কোম্পানিরটা নয়,
     * আর পাকা হয়ে যাওয়া বিলও নয়।
     */
    public function test_the_draft_list_shows_open_drafts_and_opens_them_at_the_counter(): void
    {
        $mine = $this->park();
        $foreign = $this->park([], $this->other);
        DB::table('sal_invoices')->where('id', $foreign->id)->update([
            'company_id' => Company::query()->whereKeyNot($this->company->id)->orderBy('id')->firstOrFail()->id,
        ]);

        $html = $this->get(route('sales.direct.drafts'))->assertOk()->getContent();

        $this->assertStringContainsString(e($mine->document_no), $html, '⛔ রাখা খসড়াটা তালিকায় নেই।');
        /* ⭐ তালিকা কেবল দেখায় — খোলা বা বাতিল নয় (মালিক, ২৮ সেপ্টেম্বর ২০২৬) */
        $this->assertStringNotContainsString(e(route('sales.direct.create', ['draft' => $mine->id])), $html,
            '⛔ তালিকা থেকে খসড়া খোলার লিংক আছে — খোলা কেবল কাউন্টারের Pending থেকে।');
        /* ⭐ মুছুন আর নিষ্ক্রিয় আছে — মালিকের দ্বিতীয় নির্দেশ, একই দিন */
        $this->assertStringContainsString(e(route('sales.direct.discard', $mine->id)), $html, '⛔ মুছুন বোতাম নেই।');
        $this->assertStringContainsString(e(route('sales.direct.draft_pause', $mine->id)), $html, '⛔ নিষ্ক্রিয় বোতাম নেই।');
        $this->assertStringContainsString(e(__('sales::message.stuck_parked')), $html, '⛔ কেন আটকে — লেখা নেই।');
        $this->assertStringContainsString(e(__('sales::action.view_draft')), $html, '⛔ দেখার বোতাম নেই।');
        // ⓘ সারির নিজের বোতাম ধরে — নম্বর দুই কোম্পানিতে একই হতে পারে, তাই নম্বর দিয়ে নয়
        $this->assertStringNotContainsString(e(route('sales.direct.draft_pause', $foreign->id)), $html,
            '⛔ অন্য কোম্পানির খসড়া তালিকায় এসেছে।');

        /* ⓘ পাকা বিল তালিকায় আসে না — কেবল এখনো খোলা খসড়া */
        $this->sell(['resume_invoice_id' => $mine->id, 'save_as_draft' => '0'])->assertSessionHasNoErrors();

        $this->assertStringNotContainsString(e(route('sales.direct.draft_pause', $mine->id)),
            $this->get(route('sales.direct.drafts'))->assertOk()->getContent(),
            '⛔ পাকা হয়ে যাওয়া বিলটা এখনো খসড়া তালিকায়।');
    }

    /**
     * ⭐ রাখা খসড়া চালান বা ইনভয়েসের তালিকায় যায় না — মালিকের নির্দেশ, ২৮
     * সেপ্টেম্বর ২০২৬। ⓘ পাকা হওয়া কাউন্টার-বিক্রি অবশ্যই যায়।
     */
    public function test_a_parked_draft_stays_out_of_the_challan_and_invoice_lists(): void
    {
        $parked = $this->park();
        $parkedChallan = $this->challanOf($parked);

        $this->sell(['save_as_draft' => '0'], $this->other)->assertSessionHasNoErrors();
        $sold = SalesInvoice::query()->latest('id')->firstOrFail();
        $soldChallan = $this->challanOf($sold);

        $invoices = $this->get(route('sales.invoice.index'))->assertOk()->getContent();
        $challans = $this->get(route('sales.challan.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString(e(route('sales.invoice.show', $parked)), $invoices,
            '⛔ রাখা খসড়া বিল ইনভয়েসের তালিকায় এসেছে।');
        $this->assertStringNotContainsString(e(route('sales.challan.show', $parkedChallan)), $challans,
            '⛔ রাখা খসড়ার চালান চালানের তালিকায় এসেছে।');

        $this->assertStringContainsString(e(route('sales.invoice.show', $sold)), $invoices,
            '⛔ পাকা কাউন্টার-বিক্রির বিল ইনভয়েসের তালিকা থেকে হারিয়েছে।');
        $this->assertStringContainsString(e(route('sales.challan.show', $soldChallan)), $challans,
            '⛔ পাকা কাউন্টার-বিক্রির চালান তালিকা থেকে হারিয়েছে।');
    }

    /**
     * ⭐ গ্রাহকের পরে পয়েন্ট — মালিকের নির্দেশ, ২৮ সেপ্টেম্বর ২০২৬: সব তালিকায় অবশ্যই।
     * ⓘ সারি সহ পাতা — খালি তালিকায় কলামের কোড কখনো চলে না।
     */
    public function test_every_list_shows_the_customers_point_after_the_customer(): void
    {
        $point = $this->customer->location?->name();
        $this->assertNotEmpty($point, 'প্রস্তুতিটাই ভুল — ক্রেতার পয়েন্ট নেই।');

        // ⓘ আগে পাকা বিক্রি, পরে খসড়া — খোলা খসড়া থাকলে একই ক্রেতার নতুন বিল আটকায়
        $this->sell(['save_as_draft' => '0'])->assertSessionHasNoErrors();
        $this->park();

        foreach (['sales.invoice.index', 'sales.challan.index', 'sales.direct.drafts'] as $list) {
            $html = $this->get(route($list))->assertOk()->getContent();

            $label = strpos($html, e(__('customer::field.point')));
            $this->assertNotFalse($label, "⛔ [{$list}] পয়েন্টের কলাম নেই।");
            $this->assertStringContainsString(e($point), $html, "⛔ [{$list}] ক্রেতার পয়েন্ট সারিতে নেই।");
        }
    }

    /**
     * ⛔ লাইভের অবস্থা — কাউন্টারের চিহ্ন (`counter_draft`) ছাড়া খসড়া।
     *
     * ⓘ মালিকের অভিযোগ, ২৮ সেপ্টেম্বর ২০২৬: লাইভের চারটা খসড়ার একটাতেও চিহ্ন
     * নেই (পুরনো, বা সইয়ের অপেক্ষায়), আর তাই ড্রপডাউন, তালিকা, দুই তালিকা থেকে
     * বাদ আর এক-ক্রেতা-এক-খসড়া — চারটাই চুপচাপ কাজ করত না। এই পরীক্ষা ঠিক ঐ
     * সারিটা বানায়।
     */
    public function test_a_draft_without_the_counter_mark_is_still_a_draft_everywhere(): void
    {
        $draft = $this->park();
        $challan = $this->challanOf($draft);
        DB::table('sal_invoices')->where('id', $draft->id)->update(['counter_draft' => null]);

        $list = $this->get(route('sales.direct.drafts'))->assertOk()->getContent();
        $this->assertStringContainsString(e($draft->document_no), $list, '⛔ চিহ্নহীন খসড়া খসড়া-তালিকায় নেই।');
        $this->assertStringContainsString(e(__('sales::message.stuck_unfinished')), $list,
            '⛔ চিহ্নহীন খসড়ার অবস্থা লেখা নেই।');

        $pending = collect($this->get(route('sales.direct.create'))->assertOk()->viewData('pendingDrafts'))->flatten(1);
        $this->assertTrue($pending->contains(fn ($d) => (int) $d['id'] === $draft->id),
            '⛔ চিহ্নহীন খসড়া Pending ড্রপডাউনে নেই।');

        $this->assertStringNotContainsString(e(route('sales.invoice.show', $draft)),
            $this->get(route('sales.invoice.index'))->assertOk()->getContent(),
            '⛔ চিহ্নহীন খসড়া ইনভয়েসের তালিকায় বসে আছে।');
        $this->assertStringNotContainsString(e(route('sales.challan.show', $challan)),
            $this->get(route('sales.challan.index'))->assertOk()->getContent(),
            '⛔ চিহ্নহীন খসড়ার চালান চালানের তালিকায় বসে আছে।');

        $this->sell(['save_as_draft' => '0'])->assertSessionHasErrors('customer_id');
    }

    /**
     * ⭐ নিষ্ক্রিয় খসড়া — মালিকের নির্দেশ, ২৮ সেপ্টেম্বর ২০২৬। তালিকায় থাকে, কিন্তু
     * ক্রেতার নতুন বিল আটকায় না আর Pending-এ আসে না; সক্রিয় করতে "একটাই খসড়া"
     * আবার যাচাই হয়।
     */
    public function test_a_paused_draft_frees_the_customer_and_resuming_checks_again(): void
    {
        $draft = $this->park();

        $this->post(route('sales.direct.draft_pause', $draft))->assertSessionHasNoErrors()
            ->assertRedirect(route('sales.direct.drafts'));
        $this->assertNotNull($draft->fresh()->draft_paused_at);

        $list = $this->get(route('sales.direct.drafts'))->assertOk()->getContent();
        $this->assertStringContainsString(e(__('sales::message.stuck_paused')), $list, '⛔ নিষ্ক্রিয় লেখা নেই।');

        $pending = collect($this->get(route('sales.direct.create'))->viewData('pendingDrafts'))->flatten(1);
        $this->assertFalse($pending->contains(fn ($d) => (int) $d['id'] === $draft->id),
            '⛔ নিষ্ক্রিয় খসড়া Pending-এ এসেছে।');

        /* ⓘ ক্রেতা এখন নতুন খসড়া নিতে পারেন */
        $second = $this->park();
        $this->assertNotSame($draft->id, $second->id);

        /* ⛔ আর তখন পুরনোটা সক্রিয় করা যায় না — একটাই খসড়া */
        $this->post(route('sales.direct.draft_resume', $draft))->assertSessionHasErrors('customer_id');
        $this->assertNotNull($draft->fresh()->draft_paused_at);

        /* ⓘ নতুনটা মুছলে পুরনোটা সক্রিয় হয় */
        $this->post(route('sales.direct.discard', $second), ['reason' => 'ভুল', 'back' => 'drafts'])
            ->assertSessionHasNoErrors();
        $this->post(route('sales.direct.draft_resume', $draft))->assertSessionHasNoErrors();
        $this->assertNull($draft->fresh()->draft_paused_at);
    }

    /** ⛔ চিহ্নহীন খসড়াও তালিকা থেকে মোছা যায় — বাতিল হয়, খাতায় কিছু ওঠে না। */
    public function test_an_unmarked_draft_can_be_deleted_from_the_list(): void
    {
        $draft = $this->park();
        DB::table('sal_invoices')->where('id', $draft->id)->update(['counter_draft' => null]);

        $this->post(route('sales.direct.discard', $draft), ['reason' => 'পুরনো', 'back' => 'drafts'])
            ->assertSessionHasNoErrors()->assertRedirect(route('sales.direct.drafts'));

        $this->assertSame(DocumentStatus::CANCELLED, $draft->fresh()->status);
    }

    /**
     * ⭐ নিশ্চিত করে সইয়ে পাঠানো বিক্রি খসড়া নয় — মালিকের নির্দেশ, ২৮ সেপ্টেম্বর ২০২৬
     * (*"etato maratok vul"*)। ⓘ খসড়া ট্যাবে নয়, "অনুমোদনের অপেক্ষায়" ট্যাবে; Pending-এ
     * আলাদা ভাগে, আর খোলে কাউন্টারেই — কেবল দেখার জন্য (নকশা বদল, একই দিন পরে:
     * Pending-এর সব ভাগ কাউন্টারে খোলে)।
     */
    public function test_a_sale_waiting_for_a_signature_is_not_a_draft(): void
    {
        $this->counterDepositFlow();
        $this->sell($this->bankDeposit())->assertSessionHasNoErrors();
        $held = SalesInvoice::query()->latest('id')->firstOrFail();

        $parked = $this->park([], $this->other);

        $drafts = $this->get(route('sales.direct.drafts'))->assertOk()->getContent();
        $waiting = $this->get(route('sales.direct.drafts', ['tab' => 'approval']))->assertOk()->getContent();

        $this->assertStringNotContainsString(e($held->document_no), $drafts, '⛔ সইয়ের অপেক্ষার বিক্রি খসড়া ট্যাবে।');
        $this->assertStringContainsString(e($parked->document_no), $drafts, 'প্রস্তুতিটাই ভুল — রাখা খসড়া নেই।');
        $this->assertStringContainsString(e($held->document_no), $waiting, '⛔ অনুমোদনের অপেক্ষার ট্যাবে বিক্রিটা নেই।');
        $this->assertStringNotContainsString(e($parked->document_no), $waiting, '⛔ রাখা খসড়া অনুমোদনের ট্যাবে।');

        $pending = collect($this->get(route('sales.direct.create'))->viewData('pendingDrafts'))->flatten(1)->keyBy('id');

        $this->assertSame('approval', $pending[$held->id]['group'] ?? null, '⛔ Pending-এ সইয়ের অপেক্ষার ভাগ নেই।');
        $this->assertSame(route('sales.direct.create', ['draft' => $held->id]), $pending[$held->id]['url'],
            '⛔ সইয়ের অপেক্ষার বিক্রি কাউন্টারে খোলে না।');
        $this->assertSame('draft', $pending[$parked->id]['group'] ?? null);
        $this->assertSame(route('sales.direct.create', ['draft' => $parked->id]), $pending[$parked->id]['url']);
    }

    /**
     * ⛔ চালানের সই লাগলে বিক্রিটা হারাত — abos-10-এর ধরা, ২৮ সেপ্টেম্বর ২০২৬।
     *
     * ⓘ লেনদেনের ভিতরে সই চাওয়া হত, আর ফেরত-গড়ানোয় অনুরোধ আর চালান দুইটাই মুছে
     * যেত — অথচ পর্দা বলত "অনুমোদনে পাঠানো হয়েছে"। এখন বিক্রিটা সইয়ের অপেক্ষায়
     * জমা থাকে, আর অনুরোধটা টিকে থাকে।
     */
    public function test_a_challan_that_needs_a_signature_keeps_the_sale_and_the_request(): void
    {
        $flow = ApprovalFlow::query()->create([
            'company_id' => CompanyContext::id(), 'module' => 'sales', 'action' => 'challan',
            'document_type' => '', 'threshold_amount' => null, 'is_active' => true,
        ]);
        ApprovalFlowStep::query()->create([
            'approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => 'user', 'approver_id' => $this->owner->id,
        ]);

        $challans = DeliveryChallan::query()->count();
        $requests = DB::table('approvals')->where('approvable_type', DeliveryChallan::class)->count();

        $this->sell(['save_as_draft' => '0'])->assertSessionHasNoErrors()->assertSessionHas('approval_notice');

        $this->assertSame($challans + 1, DeliveryChallan::query()->count(), '⛔ সইয়ের অপেক্ষার চালান মুছে গেছে।');
        $this->assertSame($requests + 1,
            DB::table('approvals')->where('approvable_type', DeliveryChallan::class)->where('status', 'pending')->count(),
            '⛔ চালানের সইয়ের অনুরোধ টেকেনি — সইকারীর ইনবক্স খালি।');

        $invoice = SalesInvoice::query()->latest('id')->firstOrFail();
        $this->assertSame(DocumentStatus::DRAFT, $invoice->status, 'সই না হওয়া পর্যন্ত বিল খসড়া।');
        $this->assertSame(DocumentStatus::DRAFT, $this->challanOf($invoice)->status, 'সই না হওয়া পর্যন্ত মাল বেরোয় না।');

        /* ⓘ আর তালিকায় এটা "অনুমোদনের অপেক্ষায়" — খসড়া নয় */
        $this->assertStringContainsString(e($invoice->document_no),
            $this->get(route('sales.direct.drafts', ['tab' => 'approval']))->assertOk()->getContent());
    }

    /**
     * ⭐ সইয়ের অপেক্ষার বিক্রি কাউন্টারে খোলে, কিন্তু কেবল দেখা — মালিকের অনুমোদিত নকশা,
     * ২৮ সেপ্টেম্বর ২০২৬।
     *
     * ⚠️ বিপজ্জনক ইনপুট ইচ্ছাকৃত: খোলা পর্দা থেকেই `resume_invoice_id` দিয়ে "নিশ্চিত"
     * পাঠানো। ⛔ পর্দার বোতাম বন্ধ থাকাটা পাহারা নয় — সার্ভার ফেরত না দিলে সই ছাড়াই
     * বিক্রিটা বদলে পাকা হয়ে যেত।
     */
    public function test_a_sale_waiting_for_a_signature_opens_at_the_counter_only_to_look(): void
    {
        $this->counterDepositFlow();
        $screen = ['cart' => [['product_id' => $this->product->id, 'qty' => 10]], 'note' => 'সইয়ে গেছে'];

        $this->sell(['screen_state' => json_encode($screen), ...$this->bankDeposit()])->assertSessionHasNoErrors();
        $held = SalesInvoice::query()->latest('id')->firstOrFail();

        $this->assertNull($held->counter_draft, '⛔ সইয়ের অপেক্ষার বিক্রি খসড়ার চিহ্ন পেয়েছে — সই ছাড়াই বদলানো যেত।');

        $page = $this->get(route('sales.direct.create', ['draft' => $held->id]))->assertOk();
        $resume = $page->viewData('resume');

        $this->assertIsArray($resume, '⛔ সইয়ের অপেক্ষার বিক্রি কাউন্টারে খোলে না।');
        $this->assertTrue($resume['viewOnly'], '⛔ সইয়ের অপেক্ষার বিক্রি বদলানোর জন্য খুলেছে।');
        $this->assertEquals($screen, $resume['screen'], '⛔ পর্দার ছবিটা ফেরেনি।');
        $this->assertStringContainsString('/approval', (string) $resume['approvalUrl']);
        $page->assertSee(route('sales.direct.draft_withdraw', $held->id), false);
        $page->assertDontSee(route('sales.direct.discard', $held->id), false);

        /* ⛔ খোলা পর্দা থেকেই নিশ্চিত — সার্ভার ফেরায় */
        $this->sell(['resume_invoice_id' => $held->id, 'save_as_draft' => '0'])->assertSessionHasErrors('resume_invoice_id');

        $this->assertSame(DocumentStatus::DRAFT, $held->fresh()->status, '⛔ সই ছাড়াই বিক্রিটা পাকা হয়েছে।');
        $this->assertTrue(DirectSaleService::isHeldForSignature($held->fresh()), '⛔ সইয়ের অনুরোধটা হারিয়েছে।');

        /* ⓘ সাধারণ খসড়া আগের মতোই বদলানোর জন্য খোলে */
        $parked = $this->park([], $this->other);
        $this->assertFalse($this->get(route('sales.direct.create', ['draft' => $parked->id]))->viewData('resume')['viewOnly']);
    }

    /**
     * ⭐ যিনি পাঠিয়েছেন তিনি খসড়ায় ফেরান — অনুরোধ প্রত্যাহার, জমা-ভাউচার বাতিল, ছবি খসড়ায়।
     *
     * ⚠️ বিপজ্জনক ইনপুট: ফিরিয়ে আনার পরে একই জমা দিয়ে আবার নিশ্চিত। ⛔ পুরনো ভাউচার
     * খসড়া থেকে গেলে সই শেষে দুই সেটই খাতায় উঠত — একই টাকা দুইবার।
     */
    public function test_the_sender_brings_a_held_sale_back_to_draft_and_the_money_is_not_doubled(): void
    {
        $this->counterDepositFlow();
        $screen = ['cart' => [['product_id' => $this->product->id, 'qty' => 10]]];

        $this->sell(['screen_state' => json_encode($screen), ...$this->bankDeposit()])->assertSessionHasNoErrors();
        $held = SalesInvoice::query()->latest('id')->firstOrFail();
        $first = $held->heldCounterDeposits()->get();

        $this->assertCount(1, $first, 'দৃশ্যটাই বানানো যায়নি — সইয়ের জমা নেই।');

        $this->post(route('sales.direct.draft_withdraw', $held))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('sales.direct.create', ['draft' => $held->id]));

        $held = $held->fresh();

        $this->assertFalse(DirectSaleService::isHeldForSignature($held), '⛔ ফিরিয়ে আনার পরেও সইয়ের অনুরোধ ঝুলে আছে।');
        $this->assertSame(DocumentStatus::CANCELLED, $first->first()->fresh()->status, '⛔ পুরনো জমা-ভাউচার বাতিল হয়নি।');
        $this->assertEquals($screen, $held->counter_draft['screen'] ?? null, '⛔ ছবিটা খসড়ায় ফেরেনি।');
        $this->assertNull($held->counter_screen);
        $this->assertFalse($this->get(route('sales.direct.create', ['draft' => $held->id]))->viewData('resume')['viewOnly'],
            '⛔ ফিরিয়ে আনার পরেও কেবল দেখা।');

        /* ⭐ আবার নিশ্চিত — একটাই জীবিত জমা, একটাই নতুন অনুরোধ */
        $this->sell(['resume_invoice_id' => $held->id, 'save_as_draft' => '0', ...$this->bankDeposit()])
            ->assertSessionHasNoErrors();

        $this->assertCount(1, $held->fresh()->heldCounterDeposits()->get(), '⛔ একই টাকার দুইটা জমা-ভাউচার — খাতায় দুইবার উঠত।');
        $this->assertTrue(DirectSaleService::isHeldForSignature($held->fresh()), '⛔ আবার নিশ্চিত করলেও সইয়ে যায়নি।');
    }

    /**
     * ⛔ কেবল যিনি পাঠিয়েছেন — একই লোক, একই বিক্রি; প্রথমে অন্য কেউ (চাবিসহ) ফেরত পান,
     * তারপর পাঠানেওয়ালা নিজে পারেন।
     */
    public function test_only_the_sender_can_bring_a_held_sale_back(): void
    {
        $this->counterDepositFlow();
        $this->sell($this->bankDeposit())->assertSessionHasNoErrors();
        $held = SalesInvoice::query()->latest('id')->firstOrFail();

        $clerk = User::factory()->create(['current_company_id' => $this->company->id]);
        $clerk->companies()->attach($this->company->id, ['is_active' => true]);
        $clerk->givePermissionTo('sales.challan.create');

        $this->actingAs($clerk->fresh())
            ->post(route('sales.direct.draft_withdraw', $held))
            ->assertSessionHasErrors('resume_invoice_id');

        $this->assertTrue(DirectSaleService::isHeldForSignature($held->fresh()), '⛔ অন্য কেউ সইয়ের অনুরোধ তুলে নিয়েছে।');
        $this->assertNull($held->fresh()->counter_draft);

        $this->actingAs($this->owner)
            ->post(route('sales.direct.draft_withdraw', $held))
            ->assertSessionHasNoErrors();

        $this->assertFalse(DirectSaleService::isHeldForSignature($held->fresh()));

        /* ⓘ আর খসড়াকে আবার "ফিরিয়ে আনা" যায় না */
        $this->post(route('sales.direct.draft_withdraw', $held))->assertSessionHasErrors('resume_invoice_id');
    }

    /**
     * ⛔ জমার সই শেষে বিক্রি শেষ করতে গেলে চালানের সই চাওয়াটা হারাত — লাইভে ধরা, ২৮
     * সেপ্টেম্বর ২০২৬ (INV-0003, INV-0005)।
     *
     * ⚠️ বিপজ্জনক ইনপুট: জমার সই হয়ে গেছে, আর চালানের নিজের সইয়ের ছক পরে বসানো। ⓘ আগে
     * অনুরোধটা লেনদেনের ভিতরে লেখা হত আর ফেরত-গড়ানোয় মুছত — বার্তা বলত "পাঠানো হয়েছে",
     * অথচ সইকারীর ইনবক্স খালি, আর বিক্রিটা চিরকাল আটকে।
     */
    public function test_finishing_a_signed_sale_keeps_the_challan_signature_request(): void
    {
        $this->counterDepositFlow();
        $this->sell(['own_transport' => '1', ...$this->bankDeposit()])->assertSessionHasNoErrors();
        $invoice = SalesInvoice::query()->latest('id')->firstOrFail();
        $challan = $this->challanOf($invoice);

        /* ⓘ জমার সই — হয়ে গেছে */
        DB::table('approvals')->where('approvable_type', Voucher::class)->where('status', 'pending')
            ->update(['status' => 'approved', 'decided_at' => now()]);

        $flow = ApprovalFlow::query()->create([
            'company_id' => CompanyContext::id(), 'module' => 'sales', 'action' => 'challan',
            'document_type' => '', 'threshold_amount' => null, 'is_active' => true,
        ]);
        ApprovalFlowStep::query()->create([
            'approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => 'user', 'approver_id' => $this->owner->id,
        ]);

        /* ⓘ ইঞ্জিন ছকগুলো মনে রাখে ($flowCache) — নতুন ছক দেখাতে নতুন ইঞ্জিন, লাইভে পরের অনুরোধের মতো */
        $this->app->forgetInstance(\App\Core\Engines\Approval\ApprovalEngine::class);
        $this->app->forgetInstance(\App\Core\Engines\Approval\DocumentApproval::class);
        $this->app->forgetInstance(DirectSaleService::class);

        try {
            app(DirectSaleService::class)->finishHeld($invoice->fresh());
            $this->fail('প্রস্তুতিটাই ভুল — চালানের সই না চেয়েই বিক্রি শেষ হয়েছে।');
        } catch (\App\Core\Engines\Approval\HeldForApproval) {
            // ⓘ প্রত্যাশিত — চালান সইয়ে গেছে
        }

        $this->assertSame(1,
            DB::table('approvals')->where('approvable_type', DeliveryChallan::class)
                ->where('approvable_id', $challan->id)->where('status', 'pending')->count(),
            '⛔ চালানের সইয়ের অনুরোধ টেকেনি — "পাঠানো হয়েছে" বলা হল, অথচ ইনবক্স খালি।');
        $this->assertSame(DocumentStatus::DRAFT, $invoice->fresh()->status, 'সই না হওয়া পর্যন্ত বিল খসড়া।');
        $this->assertSame(DocumentStatus::DRAFT, $challan->fresh()->status, 'সই না হওয়া পর্যন্ত মাল বেরোয় না।');
    }

    /** তালিকা থেকে বাতিল করলে তালিকাতেই ফেরা — আর চাবি ছাড়া পাতাটাই বন্ধ। */
    public function test_discarding_from_the_list_returns_to_the_list_and_the_list_needs_the_counter_key(): void
    {
        $invoice = $this->park();

        $this->post(route('sales.direct.discard', $invoice), ['reason' => 'ভুল পার্টি', 'back' => 'drafts'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('sales.direct.drafts'));

        $this->assertSame(DocumentStatus::CANCELLED, $invoice->fresh()->status);

        $clerk = User::factory()->create(['current_company_id' => $this->company->id]);
        $clerk->companies()->attach($this->company->id, ['is_active' => true]);

        $this->actingAs($clerk)->get(route('sales.direct.drafts'))->assertForbidden();

        $clerk->givePermissionTo('sales.challan.create');
        $this->actingAs($clerk->fresh())->get(route('sales.direct.drafts'))->assertOk();
    }

    // ── ⓹ হাতে লেখা চালান নম্বর ──────────────────────────────────────────

    /** ⭐ হাতে লেখা চালান নম্বর বসে — আর একই নম্বর দ্বিতীয়বার নেওয়া হয় না। */
    public function test_a_hand_written_challan_number_is_used_and_cannot_repeat(): void
    {
        $this->other->forceFill(['credit_limit' => '100000000'])->save();

        $this->sell(['save_as_draft' => '0', 'challan_no' => 'HAND-DC-77'])->assertSessionHasNoErrors();

        $this->assertSame('HAND-DC-77', DeliveryChallan::query()->latest('id')->value('document_no'),
            '⛔ হাতে লেখা চালান নম্বরটা বসেনি — সিরিজ নিজের নম্বর দিয়েছে।');

        $challans = DeliveryChallan::query()->count();

        $this->sell(['save_as_draft' => '0', 'challan_no' => 'HAND-DC-77'], $this->other)
            ->assertSessionHasErrors(['challan_no' => __('sales::validation.challan_no_taken', ['no' => 'HAND-DC-77'])]);

        $this->assertSame($challans, DeliveryChallan::query()->count(),
            '⛔ একই চালান নম্বরে দ্বিতীয় একটা চালান বসেছে।');
    }

    /**
     * ⭐ কাউন্টারের নম্বর নিজের সারিতে (DS) — খসড়া আর নিশ্চিত একই সারি; মালিকের নির্দেশ,
     * ২৮ সেপ্টেম্বর ২০২৬। ⓘ উপসর্গটা নম্বর-সারির, তাই কন্ট্রোল প্যানেল থেকে বদলায়।
     */
    public function test_counter_sales_number_from_their_own_ds_series(): void
    {
        $draft = $this->park();
        $this->sell(['save_as_draft' => '0'], $this->other)->assertSessionHasNoErrors();
        $sold = SalesInvoice::query()->latest('id')->firstOrFail();

        $first = (string) $this->challanOf($draft)->document_no;
        $second = (string) $this->challanOf($sold)->document_no;

        $this->assertStringStartsWith('DS', $first, '⛔ খসড়ার চালান DS সারিতে নয়।');
        $this->assertStringStartsWith('DS', $second, '⛔ নিশ্চিত বিক্রির চালান DS সারিতে নয়।');
        $this->assertNotSame($first, $second, '⛔ দুই বিক্রি একই নম্বর পেয়েছে।');
        $this->assertStringStartsWith('DS', (string) $this->get(route('sales.direct.create'))->viewData('challanPreview'));
    }

    /**
     * ⭐ পর্দায় দেখানো পরের নম্বরটাই লিখে দিলে সেটা **সিরিজের** নম্বর।
     *
     * ⛔ হাতের নম্বর ধরলে সিরিজ এগোত না, আর পরের চালান সিরিজ থেকে ঠিক ঐ
     * নম্বরটাই চাইত — যেটা ততক্ষণে নেওয়া।
     */
    public function test_writing_the_series_next_number_uses_the_series(): void
    {
        $preview = (string) $this->get(route('sales.direct.create'))->assertOk()->viewData('challanPreview');

        $this->assertNotSame('', $preview, 'দৃশ্যটাই বানানো যায়নি — চালানের সিরিজ নেই।');

        $this->sell(['save_as_draft' => '0', 'challan_no' => $preview])->assertSessionHasNoErrors();

        $this->assertSame($preview, DeliveryChallan::query()->latest('id')->value('document_no'));

        $after = (string) $this->get(route('sales.direct.create'))->assertOk()->viewData('challanPreview');

        $this->assertNotSame($preview, $after,
            '⛔ সিরিজের পরের নম্বরটা লেখা হলো, অথচ সিরিজ এগোয়নি — পরের চালান একই নম্বর চাইবে।');
    }

    // ── ⓺ সীমা: খসড়ায় নয়, পাকা করায় ──────────────────────────────────────

    /**
     * ⭐ সীমার বাইরের খসড়া রাখা যায়; ⛔ পাকা করা যায় না, আর খসড়াটা অক্ষত থাকে।
     *
     * ⓘ মালিক: *"bill atkanor kotha cilo conf/নিশ্চিত করুন e kintu খসড়া hobe"*।
     */
    public function test_an_over_limit_draft_is_kept_but_confirming_it_is_refused(): void
    {
        app(SettingsService::class)->set('customer.credit_limit_enabled', true);
        app(SettingsService::class)->set('customer.zero_limit_blocks', false);
        $this->customer->forceFill(['credit_limit' => '1000'])->save();

        $this->assertSame(0, bccomp((string) $this->customer->fresh()->outstanding(), '0', 4),
            'দৃশ্যটাই বানানো যায়নি — ক্রেতার আগের বকেয়া আছে।');

        $invoice = $this->park([], null, 15);

        $this->assertSame(DocumentStatus::DRAFT, $invoice->status);
        $this->assertNotNull($invoice->counter_draft, '⛔ সীমার বাইরের খসড়া রাখা যায়নি।');

        $this->sell(['save_as_draft' => '0', 'resume_invoice_id' => $invoice->id], null, 15)
            ->assertSessionHasErrors('customer_id');

        /* ⚠️ `customer_id`-এ আরও দুইটা বার্তা বসে — থামাটা যেন সীমার জন্যই হয় */
        $said = (string) (session('errors')?->get('customer_id')[0] ?? '');
        $this->assertNotSame(__('sales::validation.open_draft_blocks_new_bill', ['no' => $invoice->document_no]), $said,
            '⛔ খসড়াটা নিজেই নিজের পথ আটকেছে — সীমার দেয়াল মাপাই হয়নি।');
        $this->assertNotSame(__('sales::validation.parked_draft_other_customer', ['no' => $invoice->document_no]), $said);

        $invoice = $invoice->fresh();

        $this->assertSame(DocumentStatus::DRAFT, $invoice->status, '⛔ সীমার বাইরের খসড়া পাকা হয়ে গেছে।');
        $this->assertSame(DocumentStatus::DRAFT, $this->challanOf($invoice)->status,
            '⛔ দেয়ালে থেমেও চালানটা পাকা হয়ে গেছে — মাল বেরিয়েছে।');
        $this->assertNotNull($invoice->counter_draft, '⛔ দেয়ালে থামার পরে খসড়াটা "পেন্ডিং" থেকে হারিয়েছে।');
    }
}
