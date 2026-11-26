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
 *   ৩. কেবল দেখায়: সারিতে টাকা বসানো যায় না, কিছুই লেখা হয় না
 *   ৪. টাকা এলে (বিল না বেছে) আগের জের আগে কমে — নমুনার "গ"
 *   ৫. বাকি নিয়ন্ত্রণের প্রোফাইল আর দুই ফোনের দরজা একই অঙ্ক দেখায়; পর্দায় ধরনের নাম আর লিংক
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
                'bills' => [['id' => '', 'amount' => '100']],
            ]);

        $this->assertNotNull($this->refusalOn('bills.0.id'), '⛔ আগের জেরের সারিতে টাকা বসানো গেল');
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

    // ── প্রস্তুতি ────────────────────────────────────────────────────────

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
