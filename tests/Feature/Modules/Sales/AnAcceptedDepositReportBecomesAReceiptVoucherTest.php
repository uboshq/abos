<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\ApprovalFlow;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Models\VoucherAllocation;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherApproval;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Models\DepositClaim;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\DepositClaimService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ টাকা ঢোকার কাগজ একটাই — গৃহীত "টাকা জমার খবর" রসিদ ভাউচার (RCV-…) হয়, আদায় (COL-…) নয় (মালিক, ২০ নভেম্বর ২০২৬, চূড়ান্ত)।
 *
 * দাবি: গ্রহণে একটা রসিদ — গ্রাহক পক্ষ, যা পাঠানো হলো (এসেছে + চার্জ), চার্জ নিজের ঘরে, খবরের তারিখ, স্লিপের নম্বর লেনদেন নম্বরে আর
 * জমার স্লিপের ঘরে, বাছা বিল রসিদের বিলের ভাগে; খবর রসিদে বাঁধা; কোনো আদায় নয়। ⓘ রসিদের নিজের ছক চাইলে খবর অপেক্ষায় থাকে,
 * ফেরত দিলে অপেক্ষার রসিদ বাতিল হয়; হিসাব থেকে অপেক্ষার রসিদ বাতিল হলে খবর আবার রসিদহীন।
 */
final class AnAcceptedDepositReportBecomesAReceiptVoucherTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Customer $dealer;

    private Account $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        app(StandardChart::class)->install();

        // ⓘ এই ফাইল মাপে রসিদের ঘর আর বাঁধন — ব্যাংক স্টেটমেন্টে মেলানোর নিয়ম [[TheBankStatementHasTheFinalWordTest]]-এ
        app(\App\Core\Services\SettingsService::class)->set(DepositClaim::BANK_LINE_FIRST, false);
        ApprovalFlow::query()->where('module', VoucherApproval::MODULE)->where('action', Voucher::RECEIPT)->update(['is_active' => false]);
        ApprovalEngine::flowsChanged();

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        $this->dealer = Customer::query()->orderBy('id')->firstOrFail();
        $this->dealer->forceFill(['branch_id' => $this->company->defaultBranch()?->id])->save();

        $this->bank = Account::query()->create([
            'company_id' => $this->company->id, 'code' => '1102-RCVDEP', 'name_en' => 'Receipt deposit bank', 'name_bn' => 'রসিদের ব্যাংক',
            'parent_id' => StandardChart::find(StandardChart::BANK)->id,
            'type' => Account::ASSET, 'nature' => Account::DEBIT, 'money_kind' => Account::BANK,
        ]);
    }

    public function test_accepting_a_report_writes_one_receipt_voucher_and_no_collection(): void
    {
        $bill = $this->bill('INV-RCV-1', '30000');
        $collections = Collection::query()->withoutGlobalScopes()->count();

        $claim = app(DepositClaimService::class)->raise($this->dealer, [
            'claimed_on' => now()->subDay()->toDateString(), 'amount' => '20000', 'method' => DepositClaim::BANK,
            'reference' => 'SLIP-77821', 'bank_account_id' => $this->bank->id,
            'bills' => [['sales_invoice_id' => $bill->id, 'amount' => '20000']],
        ]);

        // ⓘ ব্যাংক ১৫ টাকা কেটে রেখেছে — এসেছে ১৯,৯৮৫
        app(DepositClaimService::class)->accept($claim, $this->bank->id, ['amount' => '19985', 'bank_charge' => '15']);

        $claim = $claim->fresh();
        $this->assertSame(DepositClaim::ACCEPTED, $claim->status, '⛔ গৃহীত খবর খাতায় উঠল না');
        $this->assertNull($claim->collection_id, '⛔ খবর থেকে আদায় (COL) বসল — টাকা ঢোকার কাগজ একটাই, রসিদ');
        $this->assertSame($collections, Collection::query()->withoutGlobalScopes()->count(), '⛔ নতুন আদায়ের সারি বসল');

        $voucher = Voucher::query()->withoutGlobalScopes()->findOrFail($claim->voucher_id);
        $this->assertSame(Voucher::RECEIPT, $voucher->type, '⛔ খবরের কাগজ রসিদ ভাউচার নয়');
        $this->assertSame(DocumentStatus::CONFIRMED, $voucher->status, '⛔ ছক বন্ধে আর মালিকের হাতে রসিদ পাকা হলো না');
        $this->assertSame('customer', $voucher->party_type);
        $this->assertSame((int) $this->dealer->id, (int) $voucher->party_id, '⛔ রসিদের পক্ষ খবরের গ্রাহক নয়');
        $this->assertSame(0, bccomp((string) $voucher->amount, '20000', 4), '⛔ রসিদের মোট "এসেছে + চার্জ" নয়');
        $this->assertSame(0, bccomp((string) $voucher->charge_amount, '15', 4), '⛔ ব্যাংক চার্জ রসিদের চার্জের ঘরে নেই');
        $this->assertSame(now()->subDay()->toDateString(), $voucher->trx_date->toDateString(), '⛔ রসিদের তারিখ খবরের তারিখ নয়');
        $this->assertSame('deposit', $voucher->instrument, '⛔ ব্যাংকে জমার রসিদের মাধ্যম "ব্যাংকে জমা" নয়');
        $this->assertSame('SLIP-77821', $voucher->instrument_no, '⛔ স্লিপের নম্বর রসিদের লেনদেন নম্বরে নেই');
        $this->assertSame('SLIP-77821', $voucher->deposit_slip_no, '⛔ স্লিপের নম্বর রসিদের জমার স্লিপের ঘরে নেই');
        $this->assertStringContainsString((string) $claim->public_id, (string) $voucher->narration, '⛔ রসিদের বিবরণে খবরের নম্বর নেই');
        $this->assertSame($voucher->document_no, $claim->receiptNo(), '⛔ খবরের রসিদ নম্বর তার রসিদ ভাউচারের নয়');

        $shares = VoucherAllocation::query()->withoutGlobalScopes()->where('voucher_id', $voucher->id)->get();
        $this->assertCount(1, $shares, '⛔ খবরে বাছা বিল রসিদের বিলের ভাগে নেই');
        $this->assertSame(SalesInvoice::drillSourceType(), $shares[0]->against_type);
        $this->assertSame((int) $bill->id, (int) $shares[0]->against_id);
        $this->assertSame(0, bccomp((string) $shares[0]->amount, '20000', 4), '⛔ বিলে পুরো পাঠানো টাকা বসেনি');
        $this->assertSame(0, bccomp(SalesInvoice::query()->withCollected()->findOrFail($bill->id)->dueAmount(), '10000', 4),
            '⛔ রসিদের পরে বিলের বাকি কমেনি');
    }

    public function test_a_receipt_waiting_for_another_hand_keeps_the_report_open_and_a_rejection_cancels_it(): void
    {
        // ⓘ লেখক ≠ পাকাকারী চালু, আর গ্রহণ করছেন মালিক নন এমন হিসাবরক্ষক — রসিদ খসড়া, খবর "অনুমোদনের অপেক্ষায়"
        app(\App\Core\Services\SettingsService::class)->set(VoucherService::MAKER_CHECKER, true);
        $clerk = User::factory()->create(['current_company_id' => $this->company->id]);
        $clerk->companies()->attach($this->company->id, ['is_active' => true]);
        $clerk->givePermissionTo(['sales.claim.view', 'sales.claim.decide']);
        $this->actingAs($clerk->fresh());

        $claim = app(DepositClaimService::class)->raise($this->dealer, [
            'claimed_on' => now()->toDateString(), 'amount' => '5000', 'method' => DepositClaim::BANK,
            'reference' => 'SLIP-WAIT-1', 'bank_account_id' => $this->bank->id,
        ]);
        app(DepositClaimService::class)->accept($claim, $this->bank->id);

        $claim = $claim->fresh();
        $this->assertTrue($claim->isOpen(), '⛔ হিসাবরক্ষকের নিজের লেখা রসিদ নিজেই খাতায়');
        $this->assertSame(DepositClaim::STAGE_AWAITING, $claim->stage());
        $voucher = Voucher::query()->withoutGlobalScopes()->findOrFail($claim->voucher_id);
        $this->assertSame(DocumentStatus::DRAFT, $voucher->status);

        app(DepositClaimService::class)->reject($claim, 'ব্যাংকে টাকা পাওয়া যায়নি');

        $this->assertSame(DocumentStatus::CANCELLED, $voucher->fresh()->status, '⛔ ফেরত খবরের অপেক্ষার রসিদ বাতিল হয়নি');
        $this->assertNull($claim->fresh()->voucher_id, '⛔ ফেরত খবর তবু বাতিল রসিদে বাঁধা');
        $this->assertSame(DepositClaim::REJECTED, $claim->fresh()->status);
    }

    public function test_a_waiting_receipt_cancelled_in_accounts_frees_the_report_and_a_second_hand_posting_it_settles_the_report(): void
    {
        app(\App\Core\Services\SettingsService::class)->set(VoucherService::MAKER_CHECKER, true);
        $clerk = User::factory()->create(['current_company_id' => $this->company->id]);
        $clerk->companies()->attach($this->company->id, ['is_active' => true]);
        $clerk->givePermissionTo(['sales.claim.view', 'sales.claim.decide']);
        $this->actingAs($clerk->fresh());

        $claim = app(DepositClaimService::class)->raise($this->dealer, [
            'claimed_on' => now()->toDateString(), 'amount' => '7000', 'method' => DepositClaim::BANK,
            'reference' => 'SLIP-FREE-1', 'bank_account_id' => $this->bank->id,
        ]);
        app(DepositClaimService::class)->accept($claim, $this->bank->id);
        $first = Voucher::query()->withoutGlobalScopes()->findOrFail($claim->fresh()->voucher_id);

        // ⓘ হিসাবে কেউ অপেক্ষার রসিদ বাতিল করলেন — খবর আবার রসিদহীন, খোলা ([[SettleTheClaimWithItsReceipt]])
        app(VoucherService::class)->cancel($first, 'ভুল খাত');
        $this->assertNull($claim->fresh()->voucher_id, '⛔ বাতিল রসিদে খবর আটকে থাকল');
        $this->assertTrue($claim->fresh()->isOpen());

        // ⓘ আবার "অনুমোদন দিন" — নতুন রসিদ; এবার অন্য হাত (মালিক) রসিদের নিজের দরজায় পাকা করেন, খবর নিজে "খাতায়"
        app(DepositClaimService::class)->accept($claim->fresh(), $this->bank->id);
        $second = Voucher::query()->withoutGlobalScopes()->findOrFail($claim->fresh()->voucher_id);
        $this->assertNotSame((int) $first->id, (int) $second->id);

        $this->actingAs($this->owner);
        $this->assertNull(app(\App\Modules\Accounts\Services\VoucherWriter::class)->post($second->fresh()),
            '⛔ খবরের অপেক্ষার রসিদ হিসাবের দরজায় পাকা হলো না — "অন্য কাগজের ভাউচার" বলে থামল?');

        $this->assertSame(DocumentStatus::CONFIRMED, $second->fresh()->status);
        $this->assertSame(DepositClaim::ACCEPTED, $claim->fresh()->status, '⛔ রসিদ খাতায়, কিন্তু খবর তবু "অপেক্ষায়"');
    }

    private function bill(string $no, string $total): SalesInvoice
    {
        return SalesInvoice::query()->create([
            'branch_id' => $this->company->defaultBranch()?->id, 'document_no' => $no,
            'customer_id' => $this->dealer->id, 'trx_date' => now()->subDays(5)->toDateString(), 'due_on' => now()->addDays(25)->toDateString(),
            'subtotal' => $total, 'discount' => '0', 'tax' => '0', 'total' => $total, 'status' => DocumentStatus::CONFIRMED,
        ]);
    }
}
