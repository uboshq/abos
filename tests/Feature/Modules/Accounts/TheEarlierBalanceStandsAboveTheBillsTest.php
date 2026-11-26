<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Contracts\PartyOpenBills;
use App\Core\Support\CompanyContext;
use App\Core\Support\OpeningRow;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\CustomerService;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ReadsTheRefusal;
use Tests\TestCase;

/**
 * ⭐ "আগের জের (ওপেনিং)" — খাতার জেরের যে অংশ কোনো খোলা বিলে নেই, বিলের তালিকার সবার উপরে (মালিকের নমুনা, ২৬ নভেম্বর ২০২৬:
 * আগের-জের-ওপেনিং-নমুনা-২৬-নভেম্বর.html; ADI-র ৮৫ গ্রাহকের ৳13,30,223.08)।
 *
 * দাবি:
 *   ১. রসিদের খোলা বিল: প্রথম সারি আগের জের, অঙ্ক = খাতার জের − বিলের বাকি; সারিগুলোর যোগ = খাতার জের
 *   ২. জের বিলের যোগের বেশি না হলে সারিই নেই
 *   ৩. আগের জের বিল হিসেবে পাঠানো যায় না (ফাঁকা বা "opening" চাবি), কিছুই লেখা হয় না
 *   ৪. টাকা এলে (বিল না বেছে) আগের জের আগে কমে — নমুনার "গ"
 *   ৫. বাকি নিয়ন্ত্রণের প্রোফাইল আর দুই ফোনের দরজা একই অঙ্ক দেখায়; পর্দায় ধরনের নাম আর লিংক
 *   ⭐ নমুনা v2 (আন্তর্জাতিক মান, ২৬ নভেম্বর ২০২৬):
 *   ৬. আগের জেরে টিক মানা হয় — বিলে লেখা টাকা বিলে, আগের জেরের টাকা পক্ষের খাতায়, জের থেকে কমে
 *   ৭. খাতার জের বিলের চেয়ে কম → সবুজ "অগ্রিম" সারি, বিয়োগে; মোট = খাতার জের — রসিদে আর প্রোফাইলে দুই দিকেই
 *   ৮. খাতার লিংক কেবল যার জন্য বিবরণী খোলে; ⓘ-র লেখায় উৎস আর তারিখ
 *   ৯. প্রোফাইলে বয়সের ঘর জেরের প্রথম দিন থেকে, বয়সের ছকের সাত ধাপে
 *  ১০. বিল ছাড়া বাড়তি টাকা এলে প্রোফাইলের মোট তবু খাতার জের (অটো-বসানো বাকির পাশে)
 */
final class TheEarlierBalanceStandsAboveTheBillsTest extends TestCase
{
    use ReadsTheRefusal;
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
    }

    /** ১ */
    public function test_the_card_starts_with_the_earlier_balance_and_adds_up_to_the_ledger(): void
    {
        $this->customerWithOpening('5000');
        $invoice = $this->confirmedInvoice();

        $answer = $this->dueAnswer();
        $first = $answer['opening'] ?? [];

        $this->assertSame(OpeningRow::KIND, $first['kind'] ?? null, '⛔ কার্ডের উত্তরে আগের জের নেই: '.json_encode($answer, JSON_UNESCAPED_UNICODE));
        $this->assertNotContains(OpeningRow::KIND, collect($answer['bills'])->pluck('kind')->all(), '⛔ আগের জের বিলের তালিকায় ঢুকল — ফোন আর ওয়েবের বিল আলাদা হত');
        $this->assertSame(0, bccomp((string) $first['outstanding'], '5000', 4), '⛔ আগের জেরের অঙ্ক খাতার জের − বিলের বাকি নয়');
        $this->assertContains($first['no'], [trans('core.opening_row.label', [], 'bn'), trans('core.opening_row.label', [], 'en')], '⛔ সারির নাম "আগের জের (ওপেনিং)" নয়');
        $this->assertSame(route('accounts.statement', ['type' => 'customer', 'id' => $this->customer->id]), $first['url'] ?? null, '⛔ নামটা হিসাব বিবরণীতে যায় না');

        $sum = collect($answer['bills'])->reduce(fn (string $s, array $b) => bcadd($s, (string) $b['outstanding'], 4), (string) $first['outstanding']);
        $this->assertSame(0, bccomp($sum, (string) $answer['amount'], 4), "⛔ সারিগুলোর যোগ {$sum}, খাতার জের {$answer['amount']}");
        $this->assertSame(0, bccomp((string) $answer['amount'], bcadd('5000', (string) $invoice->total, 4), 4), '⛔ প্রস্তুতিটাই ভুল');
    }

    /** ২ */
    public function test_no_row_when_the_bills_hold_the_whole_balance(): void
    {
        $this->customer = app(CustomerService::class)->create(['name_en' => 'No Opening Customer', 'credit_limit' => 0, 'credit_days' => 0]);
        $this->customer->forceFill(['credit_limit' => '1000000000'])->save();
        $this->confirmedInvoice();

        $this->assertNull($this->dueAnswer()['opening'] ?? null, '⛔ বিলেই পুরো জের, তবু আগের জেরের সারি');
        $this->assertNull(app(PartyOpenBills::class)->openingRow('customer', (int) $this->customer->id));
    }

    /** ৩ */
    public function test_the_row_takes_no_money_and_writes_nothing(): void
    {
        $this->customerWithOpening('5000');
        $before = [Voucher::query()->count(), \App\Models\LedgerEntry::query()->count()];

        $this->from(route('accounts.voucher.create', ['type' => Voucher::RECEIPT]))
            ->post(route('accounts.voucher.store', ['type' => Voucher::RECEIPT]), [
                'type' => Voucher::RECEIPT, 'trx_date' => now()->toDateString(), 'narration' => 'আগের জেরে বসানোর চেষ্টা', 'amount' => '100',
                'party_type' => 'customer', 'party_id' => $this->customer->id, 'instrument' => 'cash',
                'to_account_id' => Account::query()->where('money_kind', Account::CASH)->where('is_group', false)->orderBy('code')->firstOrFail()->id,
                'bills' => [['id' => '', 'amount' => '100'], ['id' => OpeningRow::TICK_ID, 'amount' => '100']],
            ]);

        $this->assertNotNull($this->refusalOn('bills.0.id'), '⛔ ফাঁকা চাবিতে টাকা বসানো গেল');
        $this->assertNotNull($this->refusalOn('bills.1.id'), '⛔ আগের জের বিল হিসেবে টাকা নিল — ওটা বিল নয়');
        $this->assertSame($before, [Voucher::query()->count(), \App\Models\LedgerEntry::query()->count()], '⛔ দেখানোর সারি কিছু লিখল');
    }

    /** ৪ */
    public function test_money_without_a_bill_lowers_the_earlier_balance_first(): void
    {
        $this->customerWithOpening('5000');
        $invoice = $this->confirmedInvoice();

        $this->from(route('accounts.voucher.create', ['type' => Voucher::RECEIPT]))
            ->post(route('accounts.voucher.store', ['type' => Voucher::RECEIPT]), [
                'type' => Voucher::RECEIPT, 'trx_date' => now()->toDateString(), 'narration' => 'বিল না বেছে', 'amount' => '3000',
                'party_type' => 'customer', 'party_id' => $this->customer->id, 'instrument' => 'cash',
                'to_account_id' => Account::query()->where('money_kind', Account::CASH)->where('is_group', false)->orderBy('code')->firstOrFail()->id,
            ])->assertSessionHasNoErrors();

        $voucher = Voucher::query()->latest('id')->firstOrFail();
        if ($voucher->isDraft()) {
            app(\App\Modules\Accounts\Services\VoucherService::class)->post($voucher);
        }

        $row = app(PartyOpenBills::class)->openingRow('customer', (int) $this->customer->id);
        $this->assertNotNull($row, '⛔ ২,০০০ বাকি থাকতেও আগের জের নেই');
        $this->assertSame(0, bccomp((string) $row['outstanding'], '2000', 4), '⛔ টাকা আগে আগের জের থেকে কমেনি');
        $this->assertSame(0, bccomp($invoice->fresh()->ownDue(), (string) $invoice->total, 4), '⛔ বিল না বেছেও বিলের নিজের বাকি কমল');
    }

    /** ৫ */
    public function test_the_profile_and_the_phone_say_the_same(): void
    {
        $this->customerWithOpening('5000');
        $invoice = $this->confirmedInvoice();

        $page = (string) $this->get(route('credit_control.customer.show', $this->customer))->assertOk()->getContent();
        $this->assertStringContainsString('data-opening-row', $page, '⛔ প্রোফাইলের বিলের তালিকায় আগের জের নেই');
        $this->assertStringContainsString(e(__('core.opening_row.tag')), $page, '⛔ "কোনো বিলে নেই" লেখা নেই');

        \Laravel\Sanctum\Sanctum::actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail(), [\App\Http\Controllers\Api\AuthController::APP]);
        $phone = $this->getJson('/api/v1/accounts/parties/customer/'.$this->customer->id.'/open-bills')->assertOk()->json();
        $this->assertSame(0, bccomp((string) ($phone['opening']['outstanding'] ?? '0'), '5000', 4), '⛔ ফোনের খোলা বিলে আগের জের নেই');
        $this->assertCount(1, $phone['bills'] ?? [], '⛔ ফোনের `bills`-এ আগের জের ঢুকল — ফোন ওটাকে বিল ধরত');

        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail(), 'web');
        $card = (string) $this->get(route('accounts.voucher.create', ['type' => Voucher::RECEIPT]))->assertOk()->getContent();
        $this->assertStringContainsString('x-if="openingLink(b)"', $card, '⛔ কার্ডে আগের জেরের লিংক নেই');
        $this->assertStringContainsString('data-bills-due-sum x-text="dueSumText"', $card, '⛔ কার্ডের নিচে মোট বাকি নেই');
        $this->assertSame(0, bccomp((string) $phone['opening']['outstanding'], bcsub((string) $this->dueAnswer()['amount'], (string) $invoice->total, 4), 4),
            '⛔ ফোনের আগের জের ওয়েবের খাতার জের − বিল নয়');
    }

    /** ৬ */
    public function test_ticking_the_earlier_balance_is_honoured(): void
    {
        $this->customerWithOpening('5000');
        $invoice = $this->confirmedInvoice();

        // ⓘ পর্দায়: বিলে ৳300, আগের জেরে ৳2,700 — পাঠানো হয় কেবল বিলের সারি, বাকি টাকা পক্ষের খাতায়
        $this->receipt('3000', [['id' => (string) $invoice->public_id, 'amount' => '300']]);

        $this->assertSame(0, bccomp($invoice->fresh()->ownDue(), '0', 4), '⛔ বিলে টিক দেওয়া ৳300 বিলে বসেনি');
        $row = app(PartyOpenBills::class)->openingRow('customer', (int) $this->customer->id);
        $this->assertSame(OpeningRow::KIND, $row['kind'] ?? null, '⛔ আগের জের হারাল');
        $this->assertSame(0, bccomp((string) $row['outstanding'], '2300', 4), '⛔ আগের জেরে লেখা ৳2,700 জের থেকে কমেনি');
    }

    /** ৭ */
    public function test_less_on_the_ledger_than_on_the_bills_shows_a_green_advance_row(): void
    {
        $this->customer = app(CustomerService::class)->create(['name_en' => 'Advance Customer', 'credit_limit' => 0, 'credit_days' => 0]);
        $this->customer->forceFill(['credit_limit' => '1000000000'])->save();
        $invoice = $this->confirmedInvoice();
        $this->receipt('1000');

        $ledger = bcsub((string) $invoice->total, '1000', 4);
        $this->assertSame(-1, bccomp($ledger, '0', 4), '⛔ প্রস্তুতিটাই ভুল — জের ঋণাত্মক নয়');

        $answer = $this->dueAnswer();
        $row = $answer['opening'] ?? [];
        $this->assertSame(OpeningRow::ADVANCE, $row['kind'] ?? null, '⛔ বিলের চেয়ে কম জের, তবু অগ্রিমের সারি নেই: '.json_encode($answer, JSON_UNESCAPED_UNICODE));
        $this->assertSame(-1, bccomp((string) $row['outstanding'], '0', 4), '⛔ অগ্রিম বিয়োগে নয়');
        $this->assertSame('', (string) $row['public_id'], '⛔ অগ্রিমে টিকের চাবি — ওটা পাওনা নয়');
        $sum = collect($answer['bills'])->reduce(fn (string $s, array $b) => bcadd($s, (string) $b['outstanding'], 4), (string) $row['outstanding']);
        $this->assertSame(0, bccomp($sum, $ledger, 4), "⛔ রসিদে সারির যোগ {$sum}, খাতার জের {$ledger}");

        $page = (string) $this->get(route('credit_control.customer.show', $this->customer))->assertOk()->getContent();
        $this->assertStringContainsString('data-opening-kind="'.OpeningRow::ADVANCE.'"', $page, '⛔ প্রোফাইলে সবুজ অগ্রিম সারি নেই');
        $this->assertSame(0, bccomp($this->profileTotal(), $ledger, 4), '⛔ প্রোফাইলের মোট খাতার জের নয়');
    }

    /** ৮ */
    public function test_the_ledger_link_comes_only_to_who_can_open_it(): void
    {
        $this->customerWithOpening('5000');
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $clerk = User::factory()->create();
        $clerk->companies()->attach($company, ['is_active' => true]);
        $clerk->forceFill(['current_company_id' => $company->id])->save();
        $this->actingAs($clerk->fresh());

        $row = app(PartyOpenBills::class)->openingRow('customer', (int) $this->customer->id);
        $this->assertNotNull($row, '⛔ প্রস্তুতিটাই ভুল — সারি নেই');
        $this->assertNull($row['url'], '⛔ খাতা দেখার চাবি ছাড়াই খাতার লিংক এল');
        $this->assertStringContainsString(\App\Core\Support\DateFormat::format((string) $row['date']), (string) $row['info'], '⛔ ⓘ-তে বসানোর তারিখ নেই');

        \Spatie\Permission\Models\Permission::findOrCreate('accounts.report', 'web');
        $clerk->givePermissionTo('accounts.report');
        $this->actingAs($clerk->fresh());
        $this->assertNotNull(app(PartyOpenBills::class)->openingRow('customer', (int) $this->customer->id)['url'] ?? null, '⛔ চাবি থাকা সত্ত্বেও লিংক নেই');
    }

    /** ৯ */
    public function test_the_profile_puts_the_earlier_balance_in_its_age_band(): void
    {
        $this->customerWithOpening('5000');
        $row = app(PartyOpenBills::class)->openingRow('customer', (int) $this->customer->id, false, true);
        $band = __('credit_control::report.bucket_'.\App\Modules\CreditControl\Reports\CreditPositionReports::bucketOf((int) $row['age']));
        $this->assertGreaterThan(30, (int) $row['age'], '⛔ বয়স জেরের প্রথম দিন থেকে নয়');

        $page = (string) $this->get(route('credit_control.customer.show', $this->customer))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-opening-bucket>\s*'.preg_quote(e($band), '/').'\s*</u', $page, "⛔ প্রোফাইলে আগের জের \"{$band}\" ঘরে নেই");
    }

    /** ১০ */
    public function test_extra_money_without_a_bill_keeps_the_profile_equal_to_the_ledger(): void
    {
        $this->customerWithOpening('5000');
        $invoice = $this->confirmedInvoice();
        $this->receipt('5200');

        $ledger = bcsub(bcadd('5000', (string) $invoice->total, 4), '5200', 4);
        $this->assertSame(0, bccomp($this->profileTotal(), $ledger, 4), '⛔ বিল ছাড়া বাড়তি টাকার পরে প্রোফাইলের মোট খাতার জের নয়');
    }

    // ── প্রস্তুতি ────────────────────────────────────────────────────────

    /** @param list<array{id: string, amount: string}> $bills */
    private function receipt(string $amount, array $bills = []): void
    {
        $this->from(route('accounts.voucher.create', ['type' => Voucher::RECEIPT]))
            ->post(route('accounts.voucher.store', ['type' => Voucher::RECEIPT]), array_filter([
                'type' => Voucher::RECEIPT, 'trx_date' => now()->toDateString(), 'narration' => 'আগের জের নমুনা v2', 'amount' => $amount,
                'party_type' => 'customer', 'party_id' => $this->customer->id, 'instrument' => 'cash',
                'to_account_id' => Account::query()->where('money_kind', Account::CASH)->where('is_group', false)->orderBy('code')->firstOrFail()->id,
                'bills' => $bills,
            ], fn ($v) => $v !== []))->assertSessionHasNoErrors();

        $voucher = Voucher::query()->latest('id')->firstOrFail();
        if ($voucher->isDraft()) {
            app(\App\Modules\Accounts\Services\VoucherService::class)->post($voucher);
        }
    }

    /** প্রোফাইলের নিচের মোট — পাতা যা দেখায়, সেটাই */
    private function profileTotal(): string
    {
        $view = $this->get(route('credit_control.customer.show', $this->customer))->assertOk();

        return bcadd((string) $view->viewData('billsTotal'), '0', 4);
    }

    private function customerWithOpening(string $amount): void
    {
        $this->customer = app(CustomerService::class)->create([
            'name_en' => 'Opening Balance Customer', 'credit_limit' => 0, 'credit_days' => 0,
            'opening_balance' => $amount, 'opening_date' => now()->subMonths(2)->toDateString(),
        ]);
        $this->customer->forceFill(['credit_limit' => '1000000000'])->save();
    }

    /** @return array<string, mixed> */
    private function dueAnswer(): array
    {
        return $this->getJson(route('accounts.voucher.due', ['party_type' => 'customer', 'party_id' => $this->customer->id]))->assertOk()->json();
    }

    private function confirmedInvoice(): SalesInvoice
    {
        $service = app(SalesInvoiceService::class);

        return $service->confirm($service->create(
            ['customer_id' => $this->customer->id, 'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id, 'trx_date' => now()->toDateString()],
            [['product_id' => Product::query()->firstOrFail()->id, 'qty' => '3', 'rate' => '100']],
        ))->fresh();
    }
}
