<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Customer\Models\Customer;
use App\Modules\Finance\Models\CapitalEntry;
use App\Modules\Finance\Models\Withdrawal;
use App\Modules\MasterData\Models\Person;
use App\Modules\Sales\Models\SalesInvoice;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * গ২ — একটা ভাউচার কেবল সেই কাগজই মেটায় যার সাথে সে মেলে (Accounts-Finance অডিট, ৪ অক্টোবর ২০২৬)।
 *
 * ⛔ আগে ভাউচারের লুকানো "কোন কাগজের বিপরীতে" ঘর কিছুই যাচাই করত না:
 *  · ১ টাকার রসিদে ৫ লাখের মূলধন "এসেছে" হত;
 *  · সইয়ের অপেক্ষায় থাকা উত্তোলন "পরিশোধিত" হয়ে যেত;
 *  · এক গ্রাহকের টাকায় আরেক গ্রাহকের বিল শোধ দেখাত।
 *
 * ⓘ শর্ত বলে কাগজ নিজেই ([[SettlementTerms]]); মাপে [[VoucherService::assertAgainstFits()]]।
 */
final class AVoucherSettlesOnlyThePaperItFitsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Account $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();

        $this->bank = Account::query()->create([
            'company_id' => $this->company->id,
            'code' => '1102-G2',
            'name_en' => 'G2 Bank',
            'name_bn' => 'গ২ ব্যাংক',
            'parent_id' => StandardChart::find(StandardChart::BANK)->id,
            'type' => Account::ASSET,
            'nature' => Account::DEBIT,
            'money_kind' => Account::BANK,
            'is_active' => true,
            'status' => DocumentStatus::CONFIRMED,
        ]);
    }

    public function test_one_taka_cannot_bring_in_half_a_million_of_capital(): void
    {
        $entry = $this->capital('500000');

        $this->assertRefused(fn () => $this->receipt('1', 'capital_entry', $entry->id, $entry->person_id), 'against_wrong_amount');
        $this->assertSame(CapitalEntry::DRAFT, $entry->fresh()->status, '⛔ ১ টাকায় ৫ লাখের মূলধন "এসেছে" হলো।');
    }

    public function test_the_exact_capital_arrives_and_settles_it(): void
    {
        $entry = $this->capital('50000');

        $this->receipt('50000', 'capital_entry', $entry->id, $entry->person_id);

        $this->assertSame(CapitalEntry::POSTED, $entry->fresh()->status, 'ঠিক অঙ্কের রসিদেও মূলধন নিষ্পন্ন হলো না — যাচাইটা সব আটকাচ্ছে।');
    }

    public function test_a_payment_cannot_settle_capital_that_comes_in(): void
    {
        $entry = $this->capital('50000');

        $this->assertRefused(fn () => $this->settleWith(Voucher::PAYMENT, '50000', 'capital_entry', $entry->id, [
            ['account_id' => $this->other()->id, 'debit' => '50000', 'credit' => '0'],
            ['account_id' => $this->bank->id, 'debit' => '0', 'credit' => '50000'],
        ]), 'against_wrong_type');
    }

    public function test_a_withdrawal_awaiting_its_signature_cannot_be_paid_by_a_voucher(): void
    {
        $withdrawal = Withdrawal::query()->create([
            'branch_id' => $this->company->defaultBranch()?->id,
            'document_no' => 'WD-G2',
            'person_id' => $this->person()->id,
            'amount' => '10000',
            'kind' => Withdrawal::DRAWING,
            'trx_date' => now()->toDateString(),
            'status' => DocumentStatus::DRAFT,
        ]);

        Approval::query()->create([
            'company_id' => $this->company->id,
            'approvable_type' => Withdrawal::class,
            'approvable_id' => $withdrawal->id,
            'module' => 'finance',
            'action' => 'withdrawal',
            'amount' => '10000',
            'status' => Approval::PENDING,
            'current_level' => 1,
            'requested_by' => auth()->id(),
            'requested_at' => now(),
        ]);

        $this->assertRefused(fn () => $this->settleWith(Voucher::PAYMENT, '10000', 'withdrawal', $withdrawal->id, [
            ['account_id' => $this->other()->id, 'debit' => '10000', 'credit' => '0'],
            ['account_id' => $this->bank->id, 'debit' => '0', 'credit' => '10000'],
        ]), 'against_closed');

        $this->assertSame(DocumentStatus::DRAFT, $withdrawal->fresh()->status, '⛔ সইয়ের অপেক্ষায় থাকা উত্তোলন "পরিশোধিত" হলো।');
    }

    public function test_one_customers_money_cannot_clear_another_customers_bill(): void
    {
        [$a, $b] = Customer::query()->take(2)->get()->all();
        $invoice = $this->invoiceFor($b, '1000');

        $this->assertRefused(fn () => $this->settleWith(Voucher::RECEIPT, '1000', 'sales_invoice', $invoice->id, [
            ['account_id' => $this->bank->id, 'debit' => '1000', 'credit' => '0'],
            ['account_id' => $this->code(StandardChart::RECEIVABLE)->id, 'debit' => '0', 'credit' => '1000'],
        ], ['party_type' => 'customer', 'party_id' => $a->id]), 'against_wrong_party');
    }

    public function test_a_bill_takes_part_of_its_due_but_not_more(): void
    {
        $customer = Customer::query()->firstOrFail();
        $invoice = $this->invoiceFor($customer, '1000');
        $lines = fn (string $amount) => [
            ['account_id' => $this->bank->id, 'debit' => $amount, 'credit' => '0'],
            ['account_id' => $this->code(StandardChart::RECEIVABLE)->id, 'debit' => '0', 'credit' => $amount],
        ];
        $party = ['party_type' => 'customer', 'party_id' => $customer->id];

        $this->assertRefused(fn () => $this->settleWith(Voucher::RECEIPT, '1500', 'sales_invoice', $invoice->id, $lines('1500'), $party), 'against_wrong_amount');

        $this->settleWith(Voucher::RECEIPT, '400', 'sales_invoice', $invoice->id, $lines('400'), $party);
        $this->assertSame(0, bccomp($invoice->fresh()->dueAmount(), '600', 4), 'আংশিক আদায় বিলের বাকি কমাল না।');
    }

    public function test_a_paper_that_does_not_exist_cannot_be_settled(): void
    {
        $this->assertRefused(fn () => $this->receipt('100', 'capital_entry', 999999, null), 'against_unknown');
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    private function assertRefused(\Closure $act, string $key): void
    {
        try {
            $act();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('against_id', $e->errors(), 'আটকেছে, কিন্তু অন্য কারণে: '.json_encode($e->errors(), JSON_UNESCAPED_UNICODE));
            $this->assertStringStartsWith(mb_substr(__('accounts::validation.'.$key), 0, 12), $e->errors()['against_id'][0]);

            return;
        }

        $this->fail('⛔ দরজা খোলা — ভাউচারটা কাগজটা মেটিয়ে দিল।');
    }

    private function receipt(string $amount, string $type, int $id, ?int $personId): Voucher
    {
        return $this->settleWith(Voucher::RECEIPT, $amount, $type, $id, [
            ['account_id' => $this->bank->id, 'debit' => $amount, 'credit' => '0'],
            ['account_id' => $this->code(StandardChart::OWNER_CAPITAL)->id, 'debit' => '0', 'credit' => $amount],
        ], $personId === null ? [] : ['party_type' => 'person', 'party_id' => $personId]);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $extra
     */
    private function settleWith(string $type, string $amount, string $againstType, int $againstId, array $lines, array $extra = []): Voucher
    {
        $voucher = app(VoucherService::class)->create([
            'type' => $type,
            'trx_date' => now()->toDateString(),
            'narration' => 'গ২',
            'instrument_no' => 'G2-'.uniqid(),
            'against_type' => $againstType,
            'against_id' => $againstId,
            ...$extra,
        ], $lines);

        return app(VoucherService::class)->post($voucher);
    }

    private function capital(string $amount): CapitalEntry
    {
        return CapitalEntry::query()->create([
            'branch_id' => $this->company->defaultBranch()?->id,
            'document_no' => 'CAP-G2-'.$amount,
            'person_id' => $this->person()->id,
            'contributor_type' => CapitalEntry::PARTNER,
            'entry_type' => CapitalEntry::CONTRIBUTION,
            'trx_date' => now()->toDateString(),
            'amount' => $amount,
            'status' => CapitalEntry::DRAFT,
        ]);
    }

    private function invoiceFor(Customer $customer, string $total): SalesInvoice
    {
        return SalesInvoice::query()->create([
            'branch_id' => $this->company->defaultBranch()?->id,
            'document_no' => 'INV-G2-'.$customer->id,
            'customer_id' => $customer->id,
            'trx_date' => now()->toDateString(),
            'due_on' => now()->addDays(30)->toDateString(),
            'subtotal' => $total,
            'discount' => '0',
            'tax' => '0',
            'total' => $total,
            'status' => DocumentStatus::CONFIRMED,
        ]);
    }

    private function person(): Person
    {
        return Person::query()->firstOrCreate(
            ['company_id' => $this->company->id, 'name_en' => 'G2 Partner'],
            ['code' => 'P-G2', 'name_bn' => 'গ২ অংশীদার', 'is_active' => true],
        );
    }

    private function code(string $code): Account
    {
        return Account::query()->where('code', $code)->firstOrFail();
    }

    private function other(): Account
    {
        return Account::query()->postable()->active()->whereNull('money_kind')->where('type', Account::EQUITY)->firstOrFail();
    }
}
