<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherApproval;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\SalesInvoice;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * কাউন্টারের পর্দা মালিকের আঁকা ছকে — **আঁকা পাতায়** মাপা।
 *
 * ── ⭐ মালিকের ছবি, ২৭ সেপ্টেম্বর ২০২৬ (সন্ধ্যা) — আগের জায়গার নিয়ম বাতিল ──
 * ⓵ অনুমোদনের বার্তা পপ-আপে — পাতার মাথার ব্যানারে নয়, বোতামের নিচেও নয়।
 * ⓶ বাকির সীমার সতর্কতা পপ-আপে (সারিটা কার্টে যায় — সেটা [[direct-sale.test.js]])।
 * ⓷ "খসড়া রাখুন" ধূসর।
 * ⓸ মাথা দুই সারিতে: তারিখ · শর্ত · পেন্ডিং / বিল নম্বর · চালান নম্বর · DO।
 * ⓹ লটের ঘর পণ্যের নামের সারির একেবারে ডানে।
 * ⓺ একই পণ্য একবারই — পর্দার JS-এর কাজ, তাই [[direct-sale.test.js]]-এ।
 * ⓻ অবশিষ্ট সীমা আর সীমা অতিক্রম চলতি মোটের নিচেও (ডানেরটাও থাকে)।
 *
 * ── ⚠️ কেন আঁকা পাতা, সেবা নয় ────────────────────────────────────────
 * মালিক পর্দা দেখে বিচার করেন। ⛔ সেবার প্রতিটা দাবি সবুজ রেখেও জিনিসটা
 * ভুল জায়গায় বসতে পারে — ঠিক তাই হয়েছিল, আর তিনি বলেছিলেন *"sob ager
 * motoi ki kaj korla"*।
 */
final class TheCounterPutsEachNoticeWhereTheOwnerDrewItTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Customer $customer;

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
        app(CashTillService::class)->ensurePrimaryTill();

        $this->customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->orderBy('id')->firstOrFail();
    }

    // ── ⓵ অনুমোদনের বার্তা — পপ-আপ ─────────────────────────────────────────

    /**
     * ⓵ চালানের অনুমোদনে আটকালে (সেবার `HeldForApproval`) — বার্তাটা পপ-আপে।
     */
    public function test_a_challan_held_for_approval_opens_the_popup_not_a_banner_or_a_line(): void
    {
        $this->flow('sales', 'challan');

        $this->from(route('sales.direct.create'))->post(route('sales.direct.store'), [
            'own_transport' => '1', // ⓘ ধাপ ৫ — নিশ্চিতে পরিবহন লাগে ([[TransportRule]]); এই দাবি অন্য কিছু মাপে
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'deposit' => '1000',
            'lines' => [['product_id' => $this->product->id, 'qty' => '10', 'rate' => '100']],
        ])->assertRedirect(route('sales.direct.create'));

        $html = $this->counterPage();
        $notice = $this->approvalPopupText($html);

        // ⓘ সেবার বাক্য ([[DocumentApproval::awaitingWord()]]) — কারণসহ বা কারণ ছাড়া
        $prefix = trim(explode(':reason', (string) __('core.approval.awaiting_because'))[0]);

        $this->assertTrue(
            $notice !== '' && (str_starts_with($notice, $prefix) || $notice === __('core.approval.awaiting')),
            "⛔ অনুমোদনের পপ-আপে সেবার বার্তাটা নেই — পাওয়া গেল: '{$notice}'।",
        );

        $this->assertSame(1, substr_count($html, e($notice)),
            '⛔ অনুমোদনের বার্তা পপ-আপের বাইরেও বসেছে — মাথায় বা বোতামের নিচে।');
        /*
         * ⓘ ত্রুটির তালিকাটাই মাপা — `role="alert"`-এর ভিতরের তালিকা। ⚠️ কেবল `list-disc` খুঁজলে মার্জিনের
         * সতর্কতাও (NEXUS §৩২, `role="status"`, একই তালিকার চেহারা) ধরা পড়ত, আর দাবিটা অন্য জিনিসে লাল হত।
         */
        $this->assertDoesNotMatchRegularExpression('/role="alert"[^>]*>\s*<ul class="list-inside list-disc"/', $html,
            '⛔ অনুমোদনের বার্তা ত্রুটির তালিকায় (বোতামের নিচে) গেছে।');
    }

    /**
     * ⓵ ডিপোজিটের সইয়ের অপেক্ষা — একই পপ-আপ, কাউন্টারেই।
     */
    public function test_a_deposit_waiting_for_its_signature_opens_the_same_popup(): void
    {
        $this->flow(VoucherApproval::MODULE, VoucherApproval::COUNTER_DEPOSIT);

        $this->from(route('sales.direct.create'))->post(route('sales.direct.store'), [
            'own_transport' => '1', // ⓘ ধাপ ৫ — নিশ্চিতে পরিবহন লাগে ([[TransportRule]]); এই দাবি অন্য কিছু মাপে
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            ...$this->bankDeposit(),
            'lines' => [['product_id' => $this->product->id, 'qty' => '10', 'rate' => '100']],
        ])->assertRedirect(route('sales.direct.create'));

        $invoice = SalesInvoice::query()->latest('id')->firstOrFail();
        $this->assertSame('draft', $invoice->status, 'প্রস্তুতিটাই ভুল — বিক্রয়টা সইয়ে আটকায়নি।');

        $html = $this->counterPage();
        $expected = __('sales::message.direct_sale_held', ['invoice' => $invoice->document_no]);

        $this->assertSame($expected, $this->approvalPopupText($html),
            '⛔ সইয়ের অপেক্ষার বার্তা পপ-আপে নেই।');
        $this->assertSame(1, substr_count($html, e($expected)),
            '⛔ সইয়ের অপেক্ষার বার্তা পপ-আপের বাইরেও বসেছে।');
    }

    /**
     * ⓵ পাল্টা-দাবি: সাধারণ ত্রুটি (অনুমোদন নয়) আগের মতোই বোতামের নিচে — পপ-আপ খালি।
     *
     * ⚠️ `assertSessionHasErrors()` এখানে ইচ্ছাকৃতভাবে নেই — মেপে শেখা: ওটা
     * ডাকলে পরের GET-এ ত্রুটিগুলো আর পাতায় আসে না (বুট-প্রোব: ডাক ছাড়া
     * তালিকা আছে, ডাকসহ নেই)।
     */
    public function test_an_ordinary_error_stays_under_the_buttons_and_the_popup_stays_empty(): void
    {
        $this->from(route('sales.direct.create'))->post(route('sales.direct.store'), [
            'own_transport' => '1', // ⓘ ধাপ ৫ — নিশ্চিতে পরিবহন লাগে ([[TransportRule]]); এই দাবি অন্য কিছু মাপে
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'lines' => [],
        ])->assertRedirect(route('sales.direct.create'));

        $html = $this->counterPage();

        $this->assertSame(1, substr_count($html, 'list-inside list-disc'),
            '⛔ ত্রুটির তালিকা পাতায় একবার থাকার কথা — বোতামের নিচে।');
        $this->assertGreaterThan(strrpos($html, 'name="save_as_draft"'), strpos($html, 'list-inside list-disc'),
            '⛔ ত্রুটির তালিকা বোতামের আগে — পাতার মাথার দিকে।');
        $this->assertSame('', $this->approvalPopupText($html),
            '⛔ সাধারণ ত্রুটিতে অনুমোদনের পপ-আপ খুলছে।');
    }

    // ── ⓶ সীমার সতর্কতা — পপ-আপ ────────────────────────────────────────────

    public function test_the_credit_warning_lives_only_in_its_popup(): void
    {
        $html = $this->counterPage();

        $popup = $this->popupBlock($html, 'credit-warning');

        $this->assertStringContainsString('x-show="creditWarningOpen"', $popup,
            '⛔ সীমার পপ-আপ খোলার বাঁধাই নেই।');
        $this->assertStringContainsString('x-text="creditWarning"', $popup,
            '⛔ সীমার বার্তা পপ-আপের ভিতরে নেই।');
        $this->assertSame(1, substr_count($html, 'x-text="creditWarning"'),
            '⛔ সীমার বার্তা পপ-আপের বাইরেও বাঁধা — পুরনো লেখাটা রয়ে গেছে।');

        $this->assertStringNotContainsString('creditWarning',
            (string) file_get_contents(base_path('app/Modules/Sales/Resources/views/direct/partials/entry.blade.php')),
            '⛔ পণ্যের সারির ঘরে সীমার সতর্কতা বাঁধা।');
    }

    // ── ⓷ "খসড়া রাখুন" ধূসর ────────────────────────────────────────────────

    public function test_the_draft_button_is_grey_in_the_rendered_page(): void
    {
        $html = $this->counterPage();

        $this->assertSame(1, preg_match_all('/<button\b[^>]*\bvalue="1"[^>]*>/u', $html, $all),
            'প্রস্তুতিটাই ভুল — value="1" বোতাম পাতায় একটাই থাকার কথা (খসড়া)।');

        $tag = $all[0][0];

        $this->assertStringContainsString('name="save_as_draft"', $tag, 'প্রস্তুতিটাই ভুল — এটা খসড়ার বোতাম নয়।');
        $this->assertStringContainsString('bg-(--color-border-strong)', $tag, '⛔ খসড়ার বোতাম ধূসর নয়।');
        $this->assertStringNotContainsString('bg-(--color-brand-500)', $tag, '⛔ খসড়ার বোতাম মূল রঙে।');
    }

    // ── ⓸ মাথা দুই সারিতে ──────────────────────────────────────────────────

    public function test_the_header_reads_date_terms_pending_then_sale_number_and_do(): void
    {
        $html = $this->counterPage();

        $order = [
            'তারিখ' => 'name="trx_date"',
            'শর্ত' => 'x-model="creditTerm"',
            'পেন্ডিং' => 'openPending($event)',
            // ⭐ এক বিক্রি এক নম্বর (মালিক, ২৯ সেপ্টেম্বর ২০২৬) — বিল আর চালানের দুই ঘর একটায় ([[SaleNumber]])
            'বিক্রি নম্বর' => 'name="challan_no"',
            'DO নম্বর' => 'name="do_no"',
        ];

        $last = -1;

        foreach ($order as $what => $needle) {
            $this->assertSame(1, substr_count($html, $needle), "প্রস্তুতিটাই ভুল — '{$what}' ঘরটা পাতায় একবার নেই।");

            $at = (int) strpos($html, $needle);
            $this->assertGreaterThan($last, $at, "⛔ মাথার ক্রম ভুল — '{$what}' আগের ঘরের আগে বসেছে।");
            $last = $at;
        }

        $this->assertStringContainsString('sm:grid-cols-[minmax(9.5rem,1.2fr)_1fr_1fr]', $html,
            '⛔ মাথাটা তিন কলামের নয় — তাহলে ছয়টা ঘর দুই সারিতে ভাগ হয় না।');

        // ⚠️ পেন্ডিং ঘর লুকালে দ্বিতীয় সারি উঠে আসত — ঘরটা সবসময় থাকে
        $before = substr($html, 0, (int) strpos($html, 'openPending($event)'));
        $label = substr($before, (int) strrpos($before, '<label'));
        $this->assertStringNotContainsString('x-show', substr($label, 0, (int) strpos($label, '>')),
            '⛔ পেন্ডিং ঘরটা লুকানো যায় — সারি ভেঙে যাবে।');
    }

    // ── ⓹ লটের ঘর নামের সারির ডানে ─────────────────────────────────────────

    public function test_the_lot_box_sits_at_the_right_end_of_the_product_name_row(): void
    {
        $html = $this->counterPage();

        // ⓘ নামের সারিটাই — উপরের খোঁজার চিহ্নেও একই `x-show` আছে, তাই শ্রেণিসহ
        $row = strpos($html, 'x-show="! pickerOpen" x-cloak class="flex items-baseline gap-2"');
        $this->assertNotFalse($row, 'প্রস্তুতিটাই ভুল — পণ্যের নামের সারি পাতায় নেই।');

        $code = strpos($html, 'x-text="(picked && picked.code)"', $row);
        $lot = strpos($html, 'x-model="entry.batchId"', $row);
        $search = strpos($html, 'x-show="pickerOpen"', $row);

        $this->assertNotFalse($lot, 'প্রস্তুতিটাই ভুল — লটের ঘর পাতায় নেই।');
        $this->assertTrue($code !== false && $search !== false && $code < $lot && $lot < $search,
            '⛔ লটের ঘর পণ্যের নামের সারির শেষে নেই (নাম · কোড · লট ক্রমে থাকার কথা)।');
    }

    // ── ⓻ সীমা অতিক্রম চলতি মোটের নিচেও ────────────────────────────────────

    public function test_over_the_limit_shows_under_the_running_total_and_in_the_right_panel(): void
    {
        $html = $this->counterPage();

        $running = strpos($html, e(__('sales::field.running_total')));
        $green = strpos($html, 'data-row="credit-over"');

        $this->assertNotFalse($running, 'প্রস্তুতিটাই ভুল — চলতি মোট পাতায় নেই।');
        $this->assertNotFalse($green, '⛔ চলতি মোটের নিচে সীমা অতিক্রমের সারি নেই।');
        $this->assertGreaterThan($running, $green, '⛔ সীমা অতিক্রম চলতি মোটের আগে বসেছে।');

        // ⓘ ডানের প্যানেলেরটাও থাকে — মালিক: দুই জায়গাতেই
        $this->assertGreaterThanOrEqual(2, substr_count($html, e(__('sales::message.credit_over'))),
            '⛔ সীমা অতিক্রম দুই জায়গায় নেই।');
        $this->assertGreaterThanOrEqual(2, substr_count($html, e(__('sales::field.credit_left'))),
            '⛔ অবশিষ্ট সীমা দুই জায়গায় নেই।');
    }

    // ── সহায়ক ──────────────────────────────────────────────────────────────

    private function counterPage(): string
    {
        return (string) $this->get(route('sales.direct.create'))->assertOk()->getContent();
    }

    /** পপ-আপের ব্লক — `data-popup` থেকে ১৫০০ অক্ষর। */
    private function popupBlock(string $html, string $name): string
    {
        $at = strpos($html, 'data-popup="'.$name.'"');
        $this->assertNotFalse($at, "⛔ '{$name}' পপ-আপটাই পাতায় নেই।");

        return substr($html, (int) $at, 1500);
    }

    /** অনুমোদনের পপ-আপে সার্ভারের বসানো লেখা — খালি মানে পপ-আপ বন্ধ। */
    private function approvalPopupText(string $html): string
    {
        $this->assertSame(1, preg_match('/x-text="approvalNotice">([^<]*)<\/p>/u',
            $this->popupBlock($html, 'approval-notice'), $m),
            'প্রস্তুতিটাই ভুল — অনুমোদনের পপ-আপে লেখার ঘর নেই।');

        return html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5);
    }

    private function flow(string $module, string $action): void
    {
        $flow = ApprovalFlow::query()->create([
            'company_id' => CompanyContext::id(),
            'module' => $module,
            'action' => $action,
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

    /**
     * ব্যাংকে জমা — নিজের বাক্সে নগদ সই চায় না (২১ সেপ্টেম্বর ২০২৬), তাই ব্যাংক।
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
                'code' => 'BANK-DREW',
                'name_en' => 'BANK-DREW',
                'name_bn' => 'BANK-DREW',
                'money_kind' => Account::BANK,
            ])->save();
        }

        return ['deposits' => [['amount' => '1000', 'account_id' => $bank->id, 'reference' => 'TRX-DREW']]];
    }
}
