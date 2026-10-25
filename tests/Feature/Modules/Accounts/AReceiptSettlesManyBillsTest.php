<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Models\LedgerEntry;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Models\VoucherAllocation;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\CustomerService;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⭐ এক টাকা, বহু বিল — রসিদে প্রতিটা বিলে নিজের অঙ্ক (মালিকের নকশা: "খোলা বিল", "এই রসিদে কত"; cb-ma-4, ২৫ অক্টোবর ২০২৬;
 * পরিশোধে-মোট-টাকা-চুক্তি §৩–৪)।
 *
 * দাবি:
 *   ১. দুই বিলে দুই অঙ্ক — প্রতিটা বিলের বাকি ঠিক নিজের অঙ্কে কমে, তালিকার বাকিও (`voucher_total`), সারি লেখা, একক "বিপরীতে" খালি,
 *      আর রসিদটা দুই বিলের পাতাতেই
 *   ২. যোগফলের বাইরের টাকা বিলে প্রয়োগ হয় না, গ্রাহকের নিজের খাতায় (Cr) — পক্ষের খাতা পুরো টাকায় কমে, বিলে কেবল সারির অঙ্ক
 *   ৩. নিয়ম নিজের ঘরে নিজের কথায়: বিলের বাকির বেশি (`bills.0.amount`), যোগফল টাকার বেশি (`bills`), অন্য গ্রাহকের বিল (`bills.0.id`)
 *   ৪. অডিট ম১ যা পাহারা দিত তা প্রতিটা সারিতে, পোস্টের মুহূর্তে: খসড়ার পরে অন্য রসিদ বিলটা মিটিয়ে দিলে খসড়া আর পোস্ট হয় না
 *   ৫. পর্দা: নকশার কলাম (কাগজ · তারিখ · শেষ দিন · দেরি · মূল টাকা · বাকি · এই রসিদে কত), সারি চুক্তির ঘর থেকে ভরে, টিকের সারি `bills[i]`-এ যায়
 */
final class AReceiptSettlesManyBillsTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $this->customer = $this->newCustomer('Many Bills Customer');
    }

    /** ১ — দুই বিলে দুই অঙ্ক */
    public function test_one_receipt_settles_two_bills_each_by_its_own_amount(): void
    {
        $first = $this->confirmedInvoice($this->customer, '3', '100');   // ৩০০
        $second = $this->confirmedInvoice($this->customer, '2', '100');  // ২০০
        [$dueFirst, $dueSecond] = [$first->fresh()->dueAmount(), $second->fresh()->dueAmount()];

        $this->accepted($this->receive('450', [[$first, '300'], [$second, '150']]), 'দুই বিলের রসিদ');
        $voucher = Voucher::query()->where('type', Voucher::RECEIPT)->latest('id')->firstOrFail();

        $this->assertSame(DocumentStatus::CONFIRMED, $voucher->status, 'দৃশ্যটাই ঠিক নয় — রসিদ খাতায় বসেনি');
        $this->assertNull($voucher->against_type, '⛔ বহু বিলের রসিদে একক "বিপরীতে"-ও ভরা — একই বিল দুই পথে দুবার বসত');
        $this->assertSame(2, VoucherAllocation::query()->where('voucher_id', $voucher->id)->count(), '⛔ বিলের সারি লেখা হয়নি');

        $this->assertSame(0, bccomp(bcsub($dueFirst, $first->fresh()->dueAmount(), 4), '300', 4), '⛔ প্রথম বিলের বাকি ঠিক ৩০০ কমেনি');
        $this->assertSame(0, bccomp(bcsub($dueSecond, $second->fresh()->dueAmount(), 4), '150', 4), '⛔ দ্বিতীয় বিলের বাকি ঠিক ১৫০ কমেনি');

        $listed = SalesInvoice::query()->withCollected()->find($second->id);
        $this->assertSame(0, bccomp($listed->dueAmount(), $second->fresh()->dueAmount(), 4),
            '⛔ তালিকার বাকি (voucher_total) আর বিলের নিজের বাকি আলাদা — আদায়ের পর্দা বা রিপোর্ট পুরনো বাকি দেখাবে');
        foreach ([$first, $second] as $invoice) {
            $this->assertTrue($invoice->fresh()->receiptVouchers()->whereKey($voucher->id)->exists(),
                '⛔ রসিদটা '.$invoice->document_no.'-এর পাতায় নেই — টাকাটা কোন কাগজে গেল বলা যায় না');
        }
    }

    /** ২ — যোগফলের বাইরের টাকা বিলে প্রয়োগ হয় না, গ্রাহকের নিজের খাতায় Cr থাকে (চুক্তি §৭.৬; "অগ্রিম" খাত নেই): পক্ষ পুরো টাকায়, বিল কেবল সারির অঙ্কে */
    public function test_money_beyond_the_rows_stays_on_the_customers_own_account(): void
    {
        $invoice = $this->confirmedInvoice($this->customer, '1', '100');
        $due = $invoice->fresh()->dueAmount();

        $this->accepted($this->receive('250', [[$invoice, '100']]), 'অগ্রিমসহ রসিদ');
        $voucher = Voucher::query()->where('type', Voucher::RECEIPT)->latest('id')->firstOrFail();

        $this->assertSame(0, bccomp(bcsub($due, $invoice->fresh()->dueAmount(), 4), '100', 4), '⛔ বিলে সারির অঙ্কের বেশি বা কম বসল');
        $credited = (string) LedgerEntry::query()->where('source_type', Voucher::SOURCE_TYPES[Voucher::RECEIPT])->where('source_id', $voucher->id)
            ->where('party_type', 'customer')->where('party_id', $this->customer->id)->sum('credit');
        $this->assertSame(0, bccomp($credited, '250', 4), '⛔ গ্রাহকের খাতা পুরো ২৫০-এ কমেনি — বিলের বাইরের ১৫০ গ্রাহকের খাতায় বসেনি: '.$credited);
    }

    /** ৩ — নিয়ম নিজের ঘরে নিজের কথায় */
    public function test_the_bill_rows_refuse_in_their_own_fields(): void
    {
        $invoice = $this->confirmedInvoice($this->customer, '1', '100');
        $due = $invoice->fresh()->dueAmount();
        $stranger = $this->confirmedInvoice($this->newCustomer('Somebody Else'), '1', '100');

        foreach ([
            'বিলের বাকির বেশি' => [bcadd($due, '500', 4), [[$invoice, bcadd($due, '1', 4)]], 'bills.0.amount', 'accounts::validation.bill_over_due',
                ['bill' => $invoice->document_no, 'amount' => Money::format(bcadd($due, '1', 4)), 'due' => Money::format($due)]],
            'যোগফল টাকার বেশি' => ['50', [[$invoice, '80']], 'bills', 'accounts::validation.bills_over_gross',
                ['sum' => Money::format('80'), 'gross' => Money::format('50')]],
            'অন্য গ্রাহকের বিল' => ['50', [[$stranger, '10']], 'bills.0.id', 'accounts::validation.against_unknown', []],
        ] as $case => [$amount, $rows, $field, $key, $with]) {
            $before = Voucher::query()->count();
            $this->receive($amount, $rows);
            $said = (string) (session('errors')?->getBag('default')->first($field) ?? '');

            $this->assertContains($said, [trans($key, $with, 'bn'), trans($key, $with, 'en')], "⛔ {$case}: থামেনি, বা {$field}-এর ঘরে নিজের কথায় নয়: ".$said);
            $this->assertSame($before, Voucher::query()->count(), "⛔ {$case}: থামা রসিদ লেখা হলো");
        }
    }

    /** ৪ — অডিট ম১-এর পাহারা প্রতিটা সারিতে, পোস্টের মুহূর্তে */
    public function test_a_draft_cannot_post_once_another_receipt_has_settled_its_bill(): void
    {
        $invoice = $this->confirmedInvoice($this->customer, '1', '100');
        $due = $invoice->fresh()->dueAmount();
        $this->accepted($this->receive($due, [[$invoice, $due]], draft: true), 'খসড়া');
        $draft = Voucher::query()->where('type', Voucher::RECEIPT)->latest('id')->firstOrFail();
        $this->assertTrue($draft->isDraft(), 'দৃশ্যটাই ঠিক নয় — খসড়া নয়');

        $this->accepted($this->receive($due, [[$invoice, $due]]), 'অন্য রসিদ পুরো বিল মেটাল');
        $this->assertSame(0, bccomp($invoice->fresh()->dueAmount(), '0', 4), 'দৃশ্যটাই ঠিক নয় — বিল মেটেনি');

        try {
            app(VoucherService::class)->post($draft->fresh());
            $this->fail('⛔ মিটে যাওয়া বিলের খসড়া রসিদ পোস্ট হলো — বিলে একই টাকা দুবার বসল');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('bills', $e->errors(), '⛔ খসড়া থামল, কিন্তু বিলের সারির কারণে নয়: '.json_encode($e->errors(), JSON_UNESCAPED_UNICODE));
        }
        $this->assertTrue($draft->fresh()->isDraft(), '⛔ থামা খসড়া খাতায় বসল');
    }

    /** ৫ — পর্দার নকশার কলাম আর পাঠানো ঘর */
    public function test_the_screen_draws_the_designs_bill_columns_and_sends_the_ticked_rows(): void
    {
        $html = (string) $this->get(route('accounts.voucher.create', ['type' => Voucher::RECEIPT]))->assertOk()->getContent();

        foreach (['paper', 'date', 'due', 'late', 'total', 'remaining', 'this_in'] as $col) {
            $said = array_filter(['bn', 'en'], fn (string $l) => str_contains($html, e(trans('money.screen.col.'.$col, [], $l))));
            $this->assertNotEmpty($said, "⛔ খোলা বিলে \"{$col}\" কলাম নেই");
        }
        foreach ([
            'data-bill-due-date x-text="dayMonth(b.due_date)"' => 'শেষ দিন due_date থেকে নয়',
            'data-bill-late x-bind:class="lateClass(b)" x-text="lateText(b)"' => 'দেরি days_late থেকে নয়, বা রং নিজের নিয়মে নয়',
            'data-bill-total x-text="lakh(b.total)"' => 'মূল টাকা total থেকে নয়',
            'data-bill-outstanding x-text="lakh(b.outstanding)"' => 'বাকি outstanding থেকে নয়',
            "x-bind:name=\"billField(i, 'id')\"" => 'টিকের সারির বিল ফর্মের সাথে যায় না',
            "x-bind:name=\"billField(i, 'amount')\"" => 'টিকের সারির অঙ্ক ফর্মের সাথে যায় না',
        ] as $needle => $why) {
            $this->assertStringContainsString($needle, $html, '⛔ '.$why);
        }
    }

    // ── প্রস্তুতি ────────────────────────────────────────────────────────

    /** @param  list<array{0: SalesInvoice, 1: string}>  $rows */
    private function receive(string $amount, array $rows, bool $draft = false): \Illuminate\Testing\TestResponse
    {
        return $this->from(route('accounts.voucher.create', ['type' => Voucher::RECEIPT]))
            ->post(route('accounts.voucher.store', ['type' => Voucher::RECEIPT]), array_filter([
                'type' => Voucher::RECEIPT,
                'trx_date' => now()->toDateString(),
                'narration' => 'বহু বিলের রসিদ',
                'amount' => $amount,
                'party_type' => 'customer',
                'party_id' => $this->customer->id,
                'to_account_id' => Account::query()->where('money_kind', Account::CASH)->where('is_group', false)->orderBy('code')->firstOrFail()->id,
                'instrument' => 'cash',
                'bills' => array_map(fn (array $r) => ['id' => (string) $r[0]->public_id, 'amount' => $r[1]], $rows),
                'save_as_draft' => $draft ? '1' : null,
            ], fn ($v) => $v !== null));
    }

    private function accepted(\Illuminate\Testing\TestResponse $response, string $case): void
    {
        $said = session('errors')?->getBag('default')->toArray() ?? [];
        $this->assertSame([], $said, "⛔ {$case}: রসিদ ফিরে এল: ".json_encode($said, JSON_UNESCAPED_UNICODE));
    }

    private function newCustomer(string $name): Customer
    {
        $customer = app(CustomerService::class)->create(['name_en' => $name, 'credit_limit' => 0, 'credit_days' => 0]);
        $customer->forceFill(['credit_limit' => '1000000000'])->save();

        return $customer;
    }

    private function confirmedInvoice(Customer $customer, string $qty, string $rate): SalesInvoice
    {
        return app(SalesInvoiceService::class)->confirm(app(SalesInvoiceService::class)->create(
            ['customer_id' => $customer->id, 'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id, 'trx_date' => now()->toDateString()],
            [['product_id' => Product::query()->firstOrFail()->id, 'qty' => $qty, 'rate' => $rate]],
        ));
    }
}
