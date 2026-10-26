<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Core\Support\Settlement;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Approval\Services\OwnerSignsDiscounts;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\CustomerService;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\ReadsTheRefusal;
use Tests\TestCase;

/**
 * ⭐ রসিদ আর পরিশোধ ভাউচারে কম-বেশি — ছাড় বা রাউন্ডিং, আদায়ের হুবহু নিয়মে (মালিকের নকশা ১০০, দফা ৪; Core
 * [[\App\Core\Support\Settlement]]; cb-ma-5, ২৫ অক্টোবর ২০২৬)।
 *
 * দাবি:
 *   ১. রসিদে ৳০.৫০ রাউন্ডিং সই ছাড়াই পোস্ট — Dr ৫৩০৫ ০.৫০, গ্রাহকের পাওনা পুরো ১,০০০.৫০-এ কমে
 *   ২. পরিশোধে ৳০.৫০ রাউন্ডিং সই ছাড়াই — Cr ৫৩০৫ ০.৫০, সরবরাহকারীর দেনা ১,০০০.৫০-এ কমে
 *   ৩. রসিদে ছাড় মালিকের সইয়ে: খসড়া থাকে, ঠিক সেই অঙ্কের অনুরোধ যায়, খাতায় কিছু ওঠে না, আর সেবার সরাসরি পোস্টও থামে
 *   ৪. পরিশোধে সরবরাহকারীর ছাড় — লেখক লেখেন, মালিক নন এমন কেউ সই দিতে পারেন না, মালিক সই দিলে নিজে পোস্ট; Cr ৫১৫০ (কেনার খরচ
 *      কমে, IAS 2), দেনা ১,০২০-এ কমে
 *   ৫. নিয়ম নিজের ঘরে নিজের কথায়: সীমার বেশি রাউন্ডিং, ধরন ছাড়া অঙ্ক, সইদাতা নেই — কিছু লেখা হয় না
 *   ৬. প্রিভিউ: ছাড়ে `needs_signature` হ্যাঁ, আর খাতার সারিতে ৪১২০
 *   ৭. পর্দা: ছাড়/রাউন্ডিং চিপ আর অঙ্কের ঘর ফর্মে যায়, সারাংশে মালিকের সইয়ের চিপ, বোতাম "সইয়ে পাঠান"-এ বদলায়
 *   ৯. হিসাবের সীমা, বিল বাছা: পাওয়া + কম-বেশি ঠিক বিলের বাকিতে পার, এক পয়সা বেশিতে `difference.amount`-এ ফেরত (fe, ২৫ অক্টোবর ২০২৬)
 *  ১০. বিল না বাছলে একই সীমা পক্ষের মোট বকেয়ায় — ছাড় দিয়ে স্থিতি উল্টো দিকে যায় না
 *   ৮. ছকের ৫৩০৫ কোডে অন্য অর্থের খাত (ব্যাংক) বসে থাকলে রাউন্ডিং থামে, নিজের ঘরে নিজের কথায় — রাউন্ডিং "ব্যাংকে টাকা" হয়ে দেখা দিত না
 */
final class AVoucherSettlesShortByADiscountOrRoundingTest extends TestCase
{
    use ReadsTheRefusal;
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Account $till;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();
        app(OwnerSignsDiscounts::class)->ensure($this->company);

        $this->till = Account::query()->where('money_kind', Account::CASH)->postable()->active()->orderBy('code')->firstOrFail();
        $fund = app(VoucherService::class)->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => now()->subDays(3)->toDateString(), 'narration' => 'কাউন্টারে মূলধন'],
            [['account_id' => $this->till->id, 'debit' => '100000'], ['account_id' => StandardChart::find(StandardChart::OWNER_CAPITAL)->id, 'credit' => '100000']],
        );
        app(VoucherService::class)->post($fund);

        // ⓘ কম-বেশির হিসাবের সীমা (দাবি ৯–১০) — দুই পক্ষেরই ৳৫,০০০ বকেয়া, নইলে ছাড় মেটানোর কিছুই থাকত না
        $this->owes('customer', (int) Customer::acrossDealers()->orderBy('id')->firstOrFail()->id, '5000');
        $this->owes('supplier', (int) Supplier::query()->orderBy('id')->firstOrFail()->id, '5000');
    }

    /** ১ */
    public function test_a_receipt_rounds_fifty_paisa_without_a_signature(): void
    {
        $this->accepted($this->receive('1000', 'rounding', '0.50'), 'রাউন্ডিংসহ রসিদ');
        $voucher = $this->latest(Voucher::RECEIPT);

        $this->assertSame(DocumentStatus::CONFIRMED, $voucher->status, '⛔ ৳০.৫০ রাউন্ডিংয়ের রসিদ সই ছাড়া পোস্ট হয়নি');
        $this->assertSame(0, Approval::query()->where('action', 'discount')->count(), '⛔ ৳০.৫০ রাউন্ডিংয়ে সই চাওয়া হলো — ৳০.৫০ পর্যন্ত সই-মুক্ত');
        $this->assertSame('0.5000', $this->side($voucher, StandardChart::ROUNDING_DIFFERENCE, 'debit'), '⛔ রসিদের রাউন্ডিং ৫৩০৫-এ Dr হয়নি');
        $this->assertSame('1000.5000', $this->partySide($voucher, 'customer', 'credit'), '⛔ রাউন্ডিংসহ গ্রাহকের পাওনা পুরোটায় কমেনি');
    }

    /** ২ */
    public function test_a_payment_rounds_fifty_paisa_without_a_signature(): void
    {
        $this->accepted($this->pay('1000', 'rounding', '0.50'), 'রাউন্ডিংসহ পরিশোধ');
        $voucher = $this->latest(Voucher::PAYMENT);

        $this->assertSame(DocumentStatus::CONFIRMED, $voucher->status, '⛔ ৳০.৫০ রাউন্ডিংয়ের পরিশোধ সই ছাড়া পোস্ট হয়নি');
        $this->assertSame('0.5000', $this->side($voucher, StandardChart::ROUNDING_DIFFERENCE, 'credit'), '⛔ পরিশোধের রাউন্ডিং ৫৩০৫-এ Cr হয়নি');
        $this->assertSame('1000.5000', $this->partySide($voucher, 'supplier', 'debit'), '⛔ রাউন্ডিংসহ সরবরাহকারীর দেনা পুরোটায় কমেনি');
    }

    /** ৩ */
    public function test_a_receipt_discount_waits_for_the_owner(): void
    {
        $this->accepted($this->receive('950', 'discount', '50'), 'ছাড়সহ রসিদ');
        $voucher = $this->latest(Voucher::RECEIPT);

        $this->assertSame(DocumentStatus::DRAFT, $voucher->status, '⛔ মালিকের সই ছাড়াই ছাড়সহ রসিদ পোস্ট হলো');
        $approval = Approval::query()->where('approvable_type', Voucher::class)->where('approvable_id', $voucher->id)->where('action', 'discount')->first();
        $this->assertNotNull($approval, '⛔ রসিদের ছাড়ের সইয়ের অনুরোধ যায়নি');
        $this->assertSame(0, bccomp((string) $approval->amount, '50', 4), '⛔ সইয়ের অনুরোধ ছাড়ের অঙ্কের নয়');
        $this->assertSame(0, LedgerEntry::query()->where('source_type', Voucher::SOURCE_TYPES[Voucher::RECEIPT])->where('source_id', $voucher->id)->count(),
            '⛔ সইয়ের আগেই ছাড় খাতায় উঠল');

        try {
            app(VoucherService::class)->post($voucher->fresh());
            $this->fail('⛔ সই ছাড়াই সেবার সরাসরি পোস্ট ছাড় পার করল');
        } catch (ValidationException $e) {
            $this->assertContains($e->errors()['difference.amount'][0] ?? null, $this->both('accounts::validation.difference_discount_awaiting'),
                '⛔ সরাসরি পোস্ট থামল, কিন্তু ছাড়ের সইয়ের কারণে নয়: '.json_encode($e->errors(), JSON_UNESCAPED_UNICODE));
        }
    }

    /** ৪ */
    public function test_a_supplier_discount_is_signed_by_the_owner_and_lowers_the_cost_of_goods(): void
    {
        // ⓘ তিন হাতের নিয়ম বন্ধ — শেষ সইয়ে নিজে পোস্ট দেখতে (চালু থাকলে অন্য কেউ চাপেন; ভাউচারের নিজের সইয়ের একই পথ)
        app(SettingsService::class)->set(VoucherService::MAKER_CHECKER, false);
        $maker = $this->person(['accounts.voucher.create', 'accounts.voucher.update', 'accounts.voucher.view', 'accounts.report']);

        $this->actingAs($maker);
        $this->accepted($this->pay('1000', 'discount', '20'), 'ছাড়সহ পরিশোধ');
        $voucher = $this->latest(Voucher::PAYMENT);
        $this->assertSame(DocumentStatus::DRAFT, $voucher->status, '⛔ সরবরাহকারীর ছাড় মালিকের সই ছাড়াই পোস্ট হলো');
        $approval = Approval::query()->where('approvable_type', Voucher::class)->where('approvable_id', $voucher->id)->where('action', 'discount')->firstOrFail();

        $manager = $this->person(['approval.decide', 'approval.view', 'accounts.report']);
        $refused = false;
        try {
            app(ApprovalEngine::class)->approve($approval->fresh(), $manager);
        } catch (\RuntimeException) {
            $refused = true;
        }
        $this->assertTrue($refused, '⛔ মালিক নন এমন একজনের সইয়ে ভাউচারের ছাড় পার হলো');

        app(ApprovalEngine::class)->approve($approval->fresh(), $this->owner->fresh());
        $voucher = $voucher->fresh();

        $this->assertSame(DocumentStatus::CONFIRMED, $voucher->status, '⛔ মালিকের সইয়ের পরেও পরিশোধ নিজে পোস্ট হলো না');
        $this->assertSame('20.0000', $this->side($voucher, StandardChart::PURCHASE_PRICE_VARIANCE, 'credit'),
            '⛔ সরবরাহকারীর ছাড় ৫১৫০-এ Cr হয়নি — কেনার খরচ কমে (IAS 2), বিক্রির ছাড়ের খাতে নয়');
        $this->assertSame('0.0000', $this->side($voucher, StandardChart::COLLECTION_DISCOUNT, 'credit'), '⛔ সরবরাহকারীর ছাড় বিক্রয়ের ছাড়ের খাতে গেল');
        $this->assertSame('1020.0000', $this->partySide($voucher, 'supplier', 'debit'), '⛔ ছাড়সহ সরবরাহকারীর দেনা পুরো বিলে কমেনি');
    }

    /** ৫ */
    public function test_the_difference_refuses_in_its_own_fields(): void
    {
        $limit = Settlement::limit(app(SettingsService::class));

        foreach ([
            'সীমার বেশি রাউন্ডিং' => [['difference' => ['kind' => 'rounding', 'amount' => '11']], 'difference.amount', 'accounts::validation.difference_over_limit',
                ['amount' => Money::format('11'), 'limit' => Money::format($limit)]],
            'ধরন ছাড়া অঙ্ক' => [['difference' => ['kind' => '', 'amount' => '5']], 'difference.kind', 'accounts::validation.difference_needs_kind', []],
        ] as $case => [$extra, $field, $key, $with]) {
            $before = Voucher::query()->count();
            $this->post(route('accounts.voucher.store', ['type' => Voucher::RECEIPT]), [...$this->receipt('1000'), ...$extra]);
            $said = (string) ($this->refusalOn($field) ?? '');

            $this->assertContains($said, $this->both($key, $with), "⛔ {$case}: থামেনি, বা {$field}-এর ঘরে নিজের কথায় নয়: ".$said);
            $this->assertSame($before, Voucher::query()->count(), "⛔ {$case}: থামা রসিদ লেখা হলো");
        }

        ApprovalFlow::query()->where('module', 'sales')->where('action', 'discount')->update(['is_active' => false]);
        $before = Voucher::query()->count();
        $this->receive('950', 'discount', '50');
        $said = (string) ($this->refusalOn('difference.amount') ?? '');

        $this->assertContains($said, $this->both('accounts::validation.difference_discount_no_signer'), '⛔ সইদাতা নেই, তবু ছাড় থামল না বা অন্য কারণে থামল: '.$said);
        $this->assertSame($before, Voucher::query()->count(), '⛔ সইদাতা ছাড়া ছাড়ের রসিদ খসড়া হয়ে পড়ে রইল');
    }

    /** ৬ */
    public function test_the_preview_says_a_signature_and_shows_the_discount_line(): void
    {
        $answer = $this->postJson(route('accounts.voucher.simulate'), [...$this->receipt('950'), 'difference' => ['kind' => 'discount', 'amount' => '50']])
            ->assertOk()->json();

        $this->assertTrue($answer['needs_signature'] ?? false, '⛔ ছাড়ের প্রিভিউ "সই লাগবে" বলে না');
        $discount = StandardChart::find(StandardChart::COLLECTION_DISCOUNT);
        $row = collect($answer['rows'] ?? [])->first(fn (array $r) => str_starts_with((string) $r['account'], $discount->code.' '));
        $this->assertSame('50.00', $row['debit'] ?? null, '⛔ প্রিভিউর খাতায় ছাড় ৪১২০-এ Dr নেই: '.json_encode($answer['rows'] ?? [], JSON_UNESCAPED_UNICODE));
    }

    /** ৭ */
    public function test_the_screen_sends_the_difference_and_switches_the_button(): void
    {
        foreach ([Voucher::RECEIPT => 'data-sum-discount', Voucher::PAYMENT => 'data-sum-discount'] as $type => $mark) {
            $html = (string) $this->get(route('accounts.voucher.create', ['type' => $type]))->assertOk()->getContent();

            foreach (['discount', 'rounding', 'none'] as $chip) {
                $this->assertStringContainsString('name="difference[kind]" value="'.($chip === 'none' ? '' : $chip).'"', $html, "⛔ {$type}: \"{$chip}\" চিপ ফর্মে যায় না");
            }
            $this->assertStringContainsString('data-money-shown="difference[amount]"', $html, "⛔ {$type}: কম-বেশির অঙ্কের ঘর নেই");
            $this->assertStringContainsString($mark.' x-text="sumDifference"', $html, "⛔ {$type}: সারাংশে ছাড়ের সারি নেই");
            $this->assertStringContainsString('x-show="roundingOwner" data-sum-rounding-owner', $html, "⛔ {$type}: রাউন্ডিংয়ে মালিকের সইয়ের চিপ নেই");
            $this->assertStringContainsString('x-text="buttonLabel" data-owner-signs-label', $html, "⛔ {$type}: সই লাগলে বোতাম \"সইয়ে পাঠান\" হয় না");
        }
    }

    /** ৮ */
    public function test_a_rounding_head_that_lost_its_meaning_refuses(): void
    {
        StandardChart::find(StandardChart::ROUNDING_DIFFERENCE)->forceFill(['money_kind' => Account::BANK])->save();
        $before = Voucher::query()->count();

        $this->receive('1000', 'rounding', '0.50');
        $said = (string) ($this->refusalOn('difference.amount') ?? '');

        $this->assertContains($said, $this->both('accounts::validation.difference_head_missing', ['code' => StandardChart::ROUNDING_DIFFERENCE]),
            '⛔ অর্থ হারানো ৫৩০৫-এ রাউন্ডিং থামেনি, বা অন্য কারণে থামল: '.$said);
        $this->assertSame($before, Voucher::query()->count(), '⛔ থামা রসিদ লেখা হলো');
    }

    /** ৯ */
    public function test_with_a_bill_the_difference_stops_at_the_bills_due(): void
    {
        $customer = app(CustomerService::class)->create(['name_en' => 'Cap Bill Customer', 'credit_limit' => 0, 'credit_days' => 0]);
        $customer->forceFill(['credit_limit' => '1000000000'])->save();
        $invoice = app(SalesInvoiceService::class)->confirm(app(SalesInvoiceService::class)->create(
            ['customer_id' => $customer->id, 'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id, 'trx_date' => now()->toDateString()],
            [['product_id' => Product::query()->firstOrFail()->id, 'qty' => '1', 'rate' => '100']],
        ))->fresh();
        $due = $invoice->dueAmount();
        $bills = [['id' => (string) $invoice->public_id, 'amount' => $due]];

        // ⓘ ছাড়, রাউন্ডিং নয়: ৳10.01-এর রাউন্ডিং আগেই ৳10-এর সীমায় থামে, তাই বাকির নিয়মটা পর্যন্ত পৌঁছায় না (fe, ২৬ অক্টোবর ২০২৬:
        //   cb-ma-5v-এ এই দাবিটা সীমার কথা পেয়ে লাল হয়েছিল)। টাকা বাকির ৳10.00 কম, কম-বেশি ৳10.01 — এক পয়সা বেশি।
        $this->post(route('accounts.voucher.store', ['type' => Voucher::RECEIPT]), [...$this->receipt(bcsub($due, '10.00', 2)), 'party_id' => $customer->id,
            'difference' => ['kind' => 'discount', 'amount' => '10.01'], 'bills' => $bills]);
        $this->assertContains($this->refusalOn('difference.amount'), $this->both('accounts::validation.difference_over_due', ['room' => Money::format('10.00'), 'due' => Money::format($due)]),
            '⛔ বিলের বাকির এক পয়সা বেশি কম-বেশি থামল না, বা difference.amount-এ নিজের কথায় নয়: '.json_encode($this->refusalMessages(), JSON_UNESCAPED_UNICODE));

        $this->post(route('accounts.voucher.store', ['type' => Voucher::RECEIPT]), [...$this->receipt(bcsub($due, '0.50', 2)), 'party_id' => $customer->id,
            'difference' => ['kind' => 'rounding', 'amount' => '0.50'], 'bills' => $bills]);
        $this->assertNull($this->refusalOn('difference.amount'), '⛔ ঠিক বিলের বাকি পর্যন্ত কম-বেশিও থামল');
        $this->assertSame(0, bccomp($invoice->fresh()->dueAmount(), '0', 4), '⛔ সীমার ভিতরের কম-বেশিতে বিল পুরো মেটেনি');
    }

    /** ১০ */
    public function test_without_a_bill_the_difference_stops_at_the_partys_due(): void
    {
        $customer = app(CustomerService::class)->create(['name_en' => 'Cap Due Customer', 'credit_limit' => 0, 'credit_days' => 0]);
        $this->owes('customer', (int) $customer->id, '500');

        $this->post(route('accounts.voucher.store', ['type' => Voucher::RECEIPT]), [...$this->receipt('499.49'), 'party_id' => $customer->id,
            'difference' => ['kind' => 'rounding', 'amount' => '0.52']]);
        $this->assertContains($this->refusalOn('difference.amount'), $this->both('accounts::validation.difference_over_due', ['room' => Money::format('0.51'), 'due' => Money::format('500')]),
            '⛔ পক্ষের বকেয়ার এক পয়সা বেশি কম-বেশি থামল না — স্থিতি উল্টো দিকে যেত: '.json_encode($this->refusalMessages(), JSON_UNESCAPED_UNICODE));

        $this->post(route('accounts.voucher.store', ['type' => Voucher::RECEIPT]), [...$this->receipt('499.50'), 'party_id' => $customer->id,
            'difference' => ['kind' => 'rounding', 'amount' => '0.50']]);
        $this->assertNull($this->refusalOn('difference.amount'), '⛔ ঠিক পক্ষের বকেয়া পর্যন্ত কম-বেশিও থামল');
    }

    /** ⓘ পক্ষের বকেয়া — জাবেদায়, নির্দিষ্ট আগের দিনে (গ্রাহক তাঁর পাওনা খাতে Dr, সরবরাহকারী দেনায় Cr) */
    private function owes(string $type, int $id, string $amount): void
    {
        $lines = $type === 'customer'
            ? [['account_id' => StandardChart::find(StandardChart::RECEIVABLE)->id, 'debit' => $amount, 'party_type' => $type, 'party_id' => $id],
                ['account_id' => StandardChart::find(StandardChart::OWNER_CAPITAL)->id, 'credit' => $amount]]
            : [['account_id' => StandardChart::find(StandardChart::OWNER_CAPITAL)->id, 'debit' => $amount],
                ['account_id' => StandardChart::find(StandardChart::PAYABLE)->id, 'credit' => $amount, 'party_type' => $type, 'party_id' => $id]];
        $journal = app(VoucherService::class)->create(['type' => Voucher::JOURNAL, 'trx_date' => now()->subDays(3)->toDateString(), 'narration' => 'আগের বকেয়া'], $lines);
        app(VoucherService::class)->post($journal);
    }

    // ── প্রস্তুতি ────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function receipt(string $amount): array
    {
        return [
            'type' => Voucher::RECEIPT, 'trx_date' => now()->toDateString(), 'narration' => 'কম-বেশির রসিদ', 'amount' => $amount,
            'party_type' => 'customer', 'party_id' => Customer::acrossDealers()->orderBy('id')->firstOrFail()->id,
            'to_account_id' => $this->till->id, 'from_account_id' => StandardChart::find(StandardChart::RECEIVABLE)->id, 'instrument' => 'cash',
        ];
    }

    private function receive(string $amount, string $kind, string $difference): \Illuminate\Testing\TestResponse
    {
        return $this->from(route('accounts.voucher.create', ['type' => Voucher::RECEIPT]))
            ->post(route('accounts.voucher.store', ['type' => Voucher::RECEIPT]), [...$this->receipt($amount), 'difference' => ['kind' => $kind, 'amount' => $difference]]);
    }

    private function pay(string $gross, string $kind, string $difference): \Illuminate\Testing\TestResponse
    {
        return $this->from(route('accounts.voucher.create', ['type' => Voucher::PAYMENT]))
            ->post(route('accounts.voucher.store', ['type' => Voucher::PAYMENT]), [
                'type' => Voucher::PAYMENT, 'trx_date' => now()->toDateString(), 'narration' => 'কম-বেশির পরিশোধ', 'gross_amount' => $gross,
                'party_type' => 'supplier', 'party_id' => Supplier::query()->orderBy('id')->firstOrFail()->id,
                'from_account_id' => $this->till->id, 'to_account_id' => StandardChart::find(StandardChart::PAYABLE)->id, 'instrument' => 'cash',
                'difference' => ['kind' => $kind, 'amount' => $difference],
            ]);
    }

    private function accepted(\Illuminate\Testing\TestResponse $response, string $case): void
    {
        $said = $this->refusalMessages();
        $this->assertSame([], $said, "⛔ {$case}: ফিরে এল: ".json_encode($said, JSON_UNESCAPED_UNICODE));
    }

    private function latest(string $type): Voucher
    {
        return Voucher::query()->withoutGlobalScope('user-branch')->where('type', $type)->latest('id')->firstOrFail();
    }

    private function side(Voucher $voucher, string $code, string $side): string
    {
        return bcadd((string) LedgerEntry::query()->where('source_type', Voucher::SOURCE_TYPES[$voucher->type])->where('source_id', $voucher->id)
            ->where('account_id', StandardChart::find($code)->id)->sum($side), '0', 4);
    }

    private function partySide(Voucher $voucher, string $partyType, string $side): string
    {
        return bcadd((string) LedgerEntry::query()->where('source_type', Voucher::SOURCE_TYPES[$voucher->type])->where('source_id', $voucher->id)
            ->where('party_type', $partyType)->sum($side), '0', 4);
    }

    /**
     * @param  array<string, string>  $with
     * @return list<string>
     */
    private function both(string $key, array $with = []): array
    {
        return [trans($key, $with, 'bn'), trans($key, $with, 'en')];
    }

    /** @param  list<string>  $keys */
    private function person(array $keys): User
    {
        $user = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);
        CompanyContext::forCompany((int) $this->company->id, fn () => $user->givePermissionTo(
            array_map(fn (string $k) => \Spatie\Permission\Models\Permission::findOrCreate($k, 'web'), $keys)));
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }
}
