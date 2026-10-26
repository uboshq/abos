<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Http\Controllers\Api\AuthController;
use App\Models\Branch;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\FinancialYear;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Models\VoucherAllocation;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ReadsTheRefusal;
use Tests\TestCase;

/**
 * ⭐ পরিশোধ ভাউচারে সরবরাহকারীর খোলা বিল — প্রতিটা বিলে নিজের অঙ্ক (fe-র সিদ্ধান্ত ক, নকশা পাতা ৪ "১০০%"; cb-ma-5, ২৫ অক্টোবর ২০২৬)।
 *
 * ⓘ রসিদের হুবহু নিয়ম ([[AReceiptSettlesManyBillsTest]]): `bills[i][id]` = বিলের public_id, `bills[i][amount]`; প্রতিটা ≤ বিলের বাকি,
 * যোগফল ≤ মোট। বিল আসে ক্রয়ের চুক্তি থেকে ([[\App\Core\Contracts\PayableOpenBills]]), বাকি ক্রয়ের নিজের নিয়মে।
 *
 * দাবি:
 *   ১. ওয়েবের কার্ড আর ফোনের দরজা সরবরাহকারীর খোলা বিল দেয় — একই public_id, একই বাকি
 *   ২. দুই বিলে দুই অঙ্ক — প্রতিটা বিলের বাকি ঠিক নিজের অঙ্কে কমে, তালিকার বাকিও (`voucher_paid_total`), সারি লেখা, একক "বিপরীতে" খালি
 *   ৩. যোগফলের বাইরের টাকা বিলে বসে না, সরবরাহকারীর নিজের খাতায় (Dr) — দেনা পুরো টাকায় কমে
 *   ৪. নিয়ম নিজের ঘরে নিজের কথায়: বিলের বাকির বেশি, যোগফল মোটের বেশি, অন্য সরবরাহকারীর বিল
 *   ৫. পোস্টের মুহূর্তে পাহারা: খসড়ার পরে বিল মিটে গেলে খসড়া আর পোস্ট হয় না
 *   ৬. সারাংশের কার্ডে নকশার "বিলে প্রয়োগ · অপ্রয়োগকৃত" — দুই ভাউচারে, বিলের বাক্সের ঘটনায় বাঁধা, পাশ নিজের (Cr/Dr)
 */
final class APaymentSettlesTheSuppliersBillsTest extends TestCase
{
    use ReadsTheRefusal;
    use RefreshDatabase;

    private User $owner;

    private Supplier $supplier;

    private Account $till;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();

        $this->supplier = Supplier::query()->orderBy('id')->firstOrFail();
        $this->till = Account::query()->where('money_kind', Account::CASH)->postable()->active()->orderBy('code')->firstOrFail();
        // ⓘ নগদে দেওয়া (ব্যাংকে নয়) — প্রাপকের ব্যাংকের সই এই দাবির বিষয় নয়; কাউন্টারে টাকা আগে থেকে
        $fund = app(VoucherService::class)->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => now()->subDays(3)->toDateString(), 'narration' => 'কাউন্টারে মূলধন'],
            [['account_id' => $this->till->id, 'debit' => '100000'], ['account_id' => StandardChart::find(StandardChart::OWNER_CAPITAL)->id, 'credit' => '100000']],
        );
        app(VoucherService::class)->post($fund);
    }

    /** ১ */
    public function test_the_web_card_and_the_phone_door_give_the_suppliers_open_bills(): void
    {
        $bill = $this->confirmedBill($this->supplier, '300', 'SUP-77');

        $web = collect($this->getJson(route('accounts.voucher.due', ['party_type' => 'supplier', 'party_id' => $this->supplier->id]))->assertOk()->json('bills'));
        $card = $web->firstWhere('public_id', $bill->public_id);
        $this->assertNotNull($card, '⛔ পরিশোধ ভাউচারের কার্ডে সরবরাহকারীর খোলা বিল নেই');
        $this->assertSame(0, bccomp((string) $card['outstanding'], $bill->fresh()->dueAmount(), 4), '⛔ কার্ডের বাকি বিলের নিজের বাকি নয়');
        $this->assertStringContainsString('SUP-77', (string) $card['no'], '⛔ নকশার "সরবরাহকারীর নং" বিলের নামে নেই');

        Sanctum::actingAs($this->owner->fresh(), [AuthController::APP]);
        $phone = collect($this->getJson('/api/v1/accounts/parties/supplier/'.$this->supplier->id.'/open-bills')->assertOk()->json('bills'));
        $this->assertSame(0, bccomp((string) ($phone->firstWhere('id', $bill->public_id)['outstanding'] ?? '-1'), $bill->fresh()->dueAmount(), 4),
            '⛔ ফোনের খোলা বিলে সরবরাহকারীর বিলটা নেই, বা বাকি ওয়েবের নয়');
    }

    /** ২ */
    public function test_one_payment_settles_two_bills_each_by_its_own_amount(): void
    {
        $first = $this->confirmedBill($this->supplier, '300', 'SUP-1');
        $second = $this->confirmedBill($this->supplier, '200', 'SUP-2');
        [$dueFirst, $dueSecond] = [$first->fresh()->dueAmount(), $second->fresh()->dueAmount()];

        $this->accepted($this->pay('450', [[$first, '300'], [$second, '150']]), 'দুই বিলের পরিশোধ');
        $voucher = Voucher::query()->where('type', Voucher::PAYMENT)->latest('id')->firstOrFail();

        $this->assertSame(DocumentStatus::CONFIRMED, $voucher->status, 'দৃশ্যটাই ঠিক নয় — পরিশোধ খাতায় বসেনি');
        $this->assertNull($voucher->against_type, '⛔ বহু বিলের পরিশোধে একক "বিপরীতে"-ও ভরা — একই বিল দুই পথে দুবার বসত');
        $this->assertSame(2, VoucherAllocation::query()->where('voucher_id', $voucher->id)->count(), '⛔ বিলের সারি লেখা হয়নি');
        $this->assertSame(0, bccomp(bcsub($dueFirst, $first->fresh()->dueAmount(), 4), '300', 4), '⛔ প্রথম সরবরাহকারী-বিলের বাকি ঠিক ৩০০ কমেনি');
        $this->assertSame(0, bccomp(bcsub($dueSecond, $second->fresh()->dueAmount(), 4), '150', 4), '⛔ দ্বিতীয় সরবরাহকারী-বিলের বাকি ঠিক ১৫০ কমেনি');

        $listed = PurchaseBill::acrossBranches()->withPaid()->find($second->id);
        $this->assertSame(0, bccomp($listed->dueAmount(), $second->fresh()->dueAmount(), 4),
            '⛔ তালিকার বাকি (voucher_paid_total) আর বিলের নিজের বাকি আলাদা — বকেয়ার রিপোর্ট আর সরবরাহকারী-পরিশোধ পুরনো বাকি দেখাবে');
    }

    /** ৩ */
    public function test_money_beyond_the_rows_stays_on_the_suppliers_own_account(): void
    {
        $bill = $this->confirmedBill($this->supplier, '100', 'SUP-3');
        $due = $bill->fresh()->dueAmount();

        $this->accepted($this->pay('250', [[$bill, '100']]), 'বিলের বাইরেসহ পরিশোধ');
        $voucher = Voucher::query()->where('type', Voucher::PAYMENT)->latest('id')->firstOrFail();

        $this->assertSame(0, bccomp(bcsub($due, $bill->fresh()->dueAmount(), 4), '100', 4), '⛔ বিলে সারির অঙ্কের বেশি বা কম বসল');
        $debited = (string) LedgerEntry::query()->where('source_type', Voucher::SOURCE_TYPES[Voucher::PAYMENT])->where('source_id', $voucher->id)
            ->where('party_type', 'supplier')->where('party_id', $this->supplier->id)->sum('debit');
        $this->assertSame(0, bccomp($debited, '250', 4), '⛔ সরবরাহকারীর দেনা পুরো ২৫০-এ কমেনি — বিলের বাইরের ১৫০ তাঁর খাতায় বসেনি: '.$debited);
    }

    /** ৪ */
    public function test_the_bill_rows_refuse_in_their_own_fields(): void
    {
        $bill = $this->confirmedBill($this->supplier, '100', 'SUP-4');
        $due = $bill->fresh()->dueAmount();
        $other = Supplier::query()->whereKeyNot($this->supplier->id)->orderBy('id')->firstOrFail();
        $stranger = $this->confirmedBill($other, '100', 'SUP-X');

        foreach ([
            'বিলের বাকির বেশি' => [bcadd($due, '500', 4), [[$bill, bcadd($due, '1', 4)]], 'bills.0.amount', 'accounts::validation.bill_over_due',
                ['bill' => $bill->fresh()->document_no.' (SUP-4)', 'amount' => Money::format(bcadd($due, '1', 4)), 'due' => Money::format($due)]],
            'যোগফল মোটের বেশি' => ['50', [[$bill, '80']], 'bills', 'accounts::validation.bills_over_gross',
                ['sum' => Money::format('80'), 'gross' => Money::format('50')]],
            'অন্য সরবরাহকারীর বিল' => ['50', [[$stranger, '10']], 'bills.0.id', 'accounts::validation.against_unknown', []],
        ] as $case => [$amount, $rows, $field, $key, $with]) {
            $before = Voucher::query()->count();
            $this->pay($amount, $rows);
            $said = (string) ($this->refusalOn($field) ?? '');

            $this->assertContains($said, [trans($key, $with, 'bn'), trans($key, $with, 'en')], "⛔ {$case}: থামেনি, বা {$field}-এর ঘরে নিজের কথায় নয়: ".$said);
            $this->assertSame($before, Voucher::query()->count(), "⛔ {$case}: থামা পরিশোধ লেখা হলো");
        }
    }

    /** ৫ */
    public function test_a_draft_cannot_post_once_another_payment_has_settled_its_bill(): void
    {
        $bill = $this->confirmedBill($this->supplier, '100', 'SUP-5');
        $due = $bill->fresh()->dueAmount();
        $this->accepted($this->pay($due, [[$bill, $due]], draft: true), 'খসড়া');
        $draft = Voucher::query()->where('type', Voucher::PAYMENT)->latest('id')->firstOrFail();
        $this->assertTrue($draft->isDraft(), 'দৃশ্যটাই ঠিক নয় — খসড়া নয়');

        $this->accepted($this->pay($due, [[$bill, $due]]), 'অন্য পরিশোধ পুরো বিল মেটাল');
        $this->assertSame(0, bccomp($bill->fresh()->dueAmount(), '0', 4), 'দৃশ্যটাই ঠিক নয় — বিল মেটেনি');

        try {
            app(VoucherService::class)->post($draft->fresh());
            $this->fail('⛔ মিটে যাওয়া সরবরাহকারী-বিলের খসড়া পরিশোধ পোস্ট হলো — বিলে একই টাকা দুবার বসল');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('bills', $e->errors(), '⛔ খসড়া থামল, কিন্তু বিলের সারির কারণে নয়: '.json_encode($e->errors(), JSON_UNESCAPED_UNICODE));
        }
    }

    /** ৬ */
    public function test_the_summary_card_shows_applied_and_unapplied_on_both_vouchers(): void
    {
        foreach ([Voucher::RECEIPT => 'unapplied_on_in', Voucher::PAYMENT => 'unapplied_on_out'] as $type => $key) {
            $html = (string) $this->get(route('accounts.voucher.create', ['type' => $type]))->assertOk()->getContent();
            $summary = (string) strstr($html, 'data-voucher-summary');

            $this->assertStringContainsString('data-sum-applied x-text="sumApplied"', $summary, "⛔ {$type}: সারাংশে \"বিলে প্রয়োগ\" নেই");
            $this->assertStringContainsString('data-sum-unapplied x-text="sumUnapplied"', $summary, "⛔ {$type}: সারাংশে \"বিলে প্রয়োগ হয়নি\" নেই");
            $this->assertNotEmpty(array_filter(['bn', 'en'], fn (string $l) => str_contains($summary, e(trans('money.screen.sum.'.$key, [], $l)))),
                "⛔ {$type}: অপ্রয়োগকৃতের নাম নিজের পাশের (Cr/Dr) নয়");
            $this->assertStringContainsString('@bills-applied="heardApplied($event.detail)"', $html, "⛔ {$type}: সারাংশ বিলের বাক্সের যোগফল শোনে না");
        }
    }

    // ── প্রস্তুতি ────────────────────────────────────────────────────────

    /** @param  list<array{0: PurchaseBill, 1: string}>  $rows */
    private function pay(string $gross, array $rows, bool $draft = false): \Illuminate\Testing\TestResponse
    {
        return $this->from(route('accounts.voucher.create', ['type' => Voucher::PAYMENT]))
            ->post(route('accounts.voucher.store', ['type' => Voucher::PAYMENT]), array_filter([
                'type' => Voucher::PAYMENT,
                'trx_date' => now()->toDateString(),
                'narration' => 'বহু বিলের পরিশোধ',
                'gross_amount' => $gross,
                'party_type' => 'supplier',
                'party_id' => $this->supplier->id,
                'from_account_id' => $this->till->id,
                'to_account_id' => StandardChart::find(StandardChart::PAYABLE)->id,
                'instrument' => 'cash',
                'bills' => array_map(fn (array $r) => ['id' => (string) $r[0]->public_id, 'amount' => $r[1]], $rows),
                'save_as_draft' => $draft ? '1' : null,
            ], fn ($v) => $v !== null));
    }

    private function accepted(\Illuminate\Testing\TestResponse $response, string $case): void
    {
        $said = $this->refusalMessages();
        $this->assertSame([], $said, "⛔ {$case}: পরিশোধ ফিরে এল: ".json_encode($said, JSON_UNESCAPED_UNICODE));
    }

    private function confirmedBill(Supplier $supplier, string $total, string $theirNo): PurchaseBill
    {
        return PurchaseBill::query()->create([
            'branch_id' => Branch::query()->firstOrFail()->id,
            'financial_year_id' => FinancialYear::query()->firstOrFail()->id,
            'supplier_id' => $supplier->id,
            'warehouse_id' => Warehouse::query()->firstOrFail()->id,
            'document_no' => 'PBL-T'.random_int(10000, 99999),
            'supplier_bill_no' => $theirNo,
            'trx_date' => now()->subDays(2)->toDateString(),
            'subtotal' => $total,
            'discount' => '0',
            'tax' => '0',
            'total' => $total,
            'ait_amount' => '0',
            'vds_amount' => '0',
            'status' => DocumentStatus::CONFIRMED,
        ]);
    }
}
