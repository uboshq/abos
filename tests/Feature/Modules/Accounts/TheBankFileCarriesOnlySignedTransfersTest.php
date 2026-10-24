<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\AccountService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Models\SupplierBankDetail;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Js;
use Tests\TestCase;

/**
 * ⭐ ব্যাংক ফাইল (BEFTN) — এক ব্যাংক খাতের এক দিনের ট্রান্সফার-পরিশোধ, প্রাপকের সই-পাওয়া হিসাবে (cb-ma-4, ২৫ অক্টোবর ২০২৬;
 * নকশার "যা পরের ধাপে: ব্যাংক ফাইল", নিয়ন্ত্রণের "ব্যাংক ফাইলে (BEFTN) যাবে"; SAP F110-এর payment medium)।
 *
 * দাবি:
 *   ১. পাকা ট্রান্সফার-পরিশোধ ফাইলে এক সারি: সই-পাওয়া হিসাব নম্বর, নাম, রাউটিং, ব্যাংক-শাখা, অঙ্ক নিট (চার্জ বাদ — চার্জ আমাদের খরচ),
 *      রেফারেন্সে কাগজের নম্বর আর ট্রান্সফারের নম্বর; উত্তরটা CSV, ফাইলের নামে খাতের কোড আর তারিখ; সইয়ের অপেক্ষায় থাকা নতুন বদল ফাইলে যায় না
 *   ২. নগদ, চেক, খসড়া, অন্য খাতের, অন্য দিনের পরিশোধ ফাইলে নেই
 *   ৩. সই-পাওয়া হিসাব নেই এমন প্রাপকের সারি যায় না, কিন্তু গোনা হয় (`X-Skipped`)
 *   ৪. সূত্রের মতো শুরু হওয়া নাম এক্সেলে সূত্র হয়ে চলে না — আগে '
 *   ৫. ব্যাংক নয় এমন খাত (নগদ) → ৪০৪; ভুল তারিখ → ৪২২
 *   ৬. পরিশোধের পর্দার নিয়ন্ত্রণে ফাইলের লিংক (বাছা খাতের, আজকের); রসিদে নেই — রসিদে টাকা আসে, ফাইল যায় না
 */
final class TheBankFileCarriesOnlySignedTransfersTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Supplier $supplier;

    private Account $bank;

    private string $day;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();

        // ⓘ এক তারিখ পুরো দাবিতে — মাঝরাত পেরোলেও কাগজ আর ফাইল একই দিনের
        $this->day = now()->toDateString();
        $this->supplier = Supplier::query()->orderBy('id')->firstOrFail();
        SupplierBankDetail::query()->where('supplier_id', $this->supplier->id)->delete();
        $this->bank = $this->bankAccount('-01', 'BEFTN bank');
        $this->moneyIn($this->bank, '100000');
    }

    public function test_the_ground_is_there(): void
    {
        $this->sign('Mollah Traders');
        $voucher = $this->pay('1000', '10', 'transfer', 'TT-BEFTN-0');

        $this->assertSame(DocumentStatus::CONFIRMED, $voucher->status, 'দৃশ্যটাই ঠিক নয় — পরিশোধ পাকা হয়নি');
        $this->assertSame(Account::BANK, $this->bank->money_kind, 'দৃশ্যটাই ঠিক নয় — খাতটা ব্যাংক নয়');
    }

    /** ১ — এক সারি, নিট অঙ্ক, সই-পাওয়া হিসাব */
    public function test_a_posted_transfer_is_one_row_in_the_payees_signed_account(): void
    {
        $this->sign('Mollah Traders');
        $voucher = $this->pay('1000', '10', 'transfer', 'TT-BEFTN-1');
        // ⛔ কেউ নতুন হিসাব চেয়েছে, দ্বিতীয় সই এখনো পায়নি — টাকা সেখানে যাবে না (দুই হাতের নিয়ম, [[SupplierBank::signedFor()]])
        SupplierBankDetail::query()->create([
            'company_id' => $this->company->id, 'supplier_id' => $this->supplier->id, 'bank_name' => 'Other Bank', 'account_name' => 'Someone Else',
            'account_no' => '99990000111', 'account_last4' => '0111', 'routing_no' => '111111111', 'status' => SupplierBankDetail::PENDING,
            'requested_by' => $this->owner->id,
        ]);

        $response = $this->get($this->url($this->bank))->assertOk();

        $this->assertStringStartsWith('text/csv', (string) $response->headers->get('Content-Type'), '⛔ ব্যাংক ফাইল CSV নয়');
        $this->assertStringContainsString('beftn-'.$this->bank->code.'-'.$this->day.'.csv', (string) $response->headers->get('Content-Disposition'),
            '⛔ ফাইলের নামে খাত আর দিন নেই');
        $this->assertSame('0', $response->headers->get('X-Skipped'), '⛔ কিছু বাদ না পড়লেও বাদ গোনা হল');

        $rows = $this->rows((string) $response->getContent());
        $this->assertSame(['Account No', 'Account Name', 'Amount', 'Routing No', 'Bank', 'Payee Code', 'Reference'], $rows[0] ?? null,
            '⛔ ব্যাংক ফাইলের মাথা বেতনের ফাইলের কলাম নয়');
        $this->assertCount(2, $rows, '⛔ এক পরিশোধে ফাইলে এক সারি নয়: '.$response->getContent());
        $this->assertSame([
            '20501234567', 'Mollah Traders', '1000.00', '090261234', 'Dutch-Bangla Motijheel', (string) $this->supplier->id,
            $voucher->document_no.' TT-BEFTN-1',
        ], $rows[1], '⛔ সারিটা প্রাপকের সই-পাওয়া হিসাব আর নিট অঙ্ক নয় (চার্জ ব্যাংককে পাঠানো টাকা নয়; সইয়ের অপেক্ষার হিসাবে টাকা নয়)');
    }

    /** ২ — নগদ, চেক, খসড়া, অন্য খাত, অন্য দিন নয় */
    public function test_cash_drafts_other_accounts_and_other_days_stay_out(): void
    {
        $this->sign('Mollah Traders');
        $other = $this->bankAccount('-02', 'Other bank');
        $this->moneyIn($other, '100000');

        $this->pay('300', '0', 'cash', null, $this->till());
        $this->pay('200', '0', 'cheque', '7788990');
        $this->pay('400', '0', 'transfer', 'TT-DRAFT', post: false);
        $this->pay('500', '0', 'transfer', 'TT-OTHER', $other);
        $this->pay('600', '0', 'transfer', 'TT-YESTERDAY', date: now()->subDay()->toDateString());
        $this->pay('700', '0', 'transfer', 'TT-IN');

        $rows = $this->rows((string) $this->get($this->url($this->bank))->assertOk()->getContent());

        $this->assertSame(['700.00'], array_column(array_slice($rows, 1), 2),
            '⛔ ব্যাংক ফাইলে এমন পরিশোধ ঢুকল যা এই খাতের আজকের পাকা ট্রান্সফার নয়: '.json_encode($rows, JSON_UNESCAPED_UNICODE));
    }

    /** ৩ — সই-পাওয়া হিসাব নেই → সারি নেই, কিন্তু গোনা */
    public function test_a_payee_without_a_signed_account_is_counted_not_written(): void
    {
        // ⓘ পক্ষ ছাড়া ট্রান্সফার (সরাসরি খরচের খাতে) — কার হিসাবে যাবে ব্যবস্থা জানে না
        $voucher = app(VoucherService::class)->create(
            ['type' => Voucher::PAYMENT, 'trx_date' => $this->day, 'narration' => 'পক্ষ ছাড়া', 'instrument' => 'transfer', 'instrument_no' => 'TT-NOBODY'],
            [['account_id' => StandardChart::find(StandardChart::ENTERTAINMENT)->id, 'debit' => '800'], ['account_id' => $this->bank->id, 'credit' => '800']],
        );
        app(VoucherService::class)->post($voucher);
        $this->assertSame(DocumentStatus::CONFIRMED, $voucher->fresh()->status, 'দৃশ্যটাই ঠিক নয় — পক্ষ ছাড়া পরিশোধ পাকা হয়নি');

        $response = $this->get($this->url($this->bank))->assertOk();

        $this->assertCount(1, $this->rows((string) $response->getContent()), '⛔ সই-পাওয়া হিসাব ছাড়া প্রাপকের সারি ফাইলে গেল');
        $this->assertSame('1', $response->headers->get('X-Skipped'), '⛔ বাদ পড়া পরিশোধ গোনা হয়নি — ফাইলটা পুরো মনে হত');
    }

    /** ৪ — সূত্র-পাহারা */
    public function test_a_name_that_looks_like_a_formula_does_not_run(): void
    {
        $this->sign('=HYPERLINK("http://x","y")');
        $this->pay('1000', '0', 'transfer', 'TT-FORMULA');

        $rows = $this->rows((string) $this->get($this->url($this->bank))->assertOk()->getContent());

        $this->assertSame('\'=HYPERLINK("http://x","y")', $rows[1][1] ?? null, '⛔ সূত্রের মতো নাম ব্যাংক ফাইলে সূত্র হয়ে চলত');
    }

    /** ৫ — নগদ খাত বা ভুল তারিখ */
    public function test_a_cash_account_or_a_bad_date_is_refused(): void
    {
        $this->get($this->url($this->till()))->assertNotFound();
        $this->get(route('accounts.voucher.bank_file', ['account' => $this->bank->id, 'date' => '25-10-2026']))->assertStatus(422);
    }

    /** ৬ — পর্দার লিংক */
    public function test_the_payment_screen_links_the_file_and_the_receipt_does_not(): void
    {
        $payment = (string) $this->get(route('accounts.voucher.create', ['type' => Voucher::PAYMENT]))->assertOk()->getContent();
        $receipt = (string) $this->get(route('accounts.voucher.create', ['type' => Voucher::RECEIPT]))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-control="bank-file"[^>]*>[^<]*<a x-bind:href="bankFileHref"[^>]*data-bank-file/', $payment,
            '⛔ পরিশোধের নিয়ন্ত্রণে ব্যাংক ফাইলের লিংক নেই');
        $this->assertStringContainsString((string) Js::from(route('accounts.voucher.bank_file')), $payment,
            '⛔ পরিশোধের পর্দা ব্যাংক ফাইলের ঠিকানা জানে না');
        $this->assertStringNotContainsString('data-bank-file', $receipt, '⛔ রসিদে ব্যাংক ফাইলের লিংক — রসিদে টাকা আসে, ফাইল যায় না');
    }

    // ── প্রস্তুতি ────────────────────────────────────────────────────────

    private function sign(string $name): void
    {
        SupplierBankDetail::query()->create([
            'company_id' => $this->company->id, 'supplier_id' => $this->supplier->id, 'bank_name' => 'Dutch-Bangla', 'branch_name' => 'Motijheel',
            'account_name' => $name, 'account_no' => '20501234567', 'account_last4' => '4567', 'routing_no' => '090261234',
            'status' => SupplierBankDetail::SIGNED, 'requested_by' => $this->owner->id, 'signed_by' => $this->owner->id, 'signed_at' => now(),
        ]);
    }

    private function pay(string $amount, string $charge, string $method, ?string $reference, ?Account $from = null, bool $post = true, ?string $date = null): Voucher
    {
        $from ??= $this->bank;
        $out = bcadd($amount, $charge, 4);
        $lines = [
            ['account_id' => StandardChart::find(StandardChart::PAYABLE)->id, 'debit' => $amount, 'party_type' => 'supplier', 'party_id' => $this->supplier->id],
            ['account_id' => $from->id, 'credit' => $out],
        ];
        if (bccomp($charge, '0', 4) > 0) {
            $lines[] = ['account_id' => StandardChart::find(StandardChart::BANK_CHARGES)->id, 'debit' => $charge];
        }

        $voucher = app(VoucherService::class)->create(array_filter([
            'type' => Voucher::PAYMENT, 'trx_date' => $date ?? $this->day, 'narration' => 'ব্যাংক ফাইলের দাবি', 'party_type' => 'supplier',
            'party_id' => $this->supplier->id, 'instrument' => $method, 'instrument_no' => $reference, 'charge_amount' => $charge,
        ], fn ($v) => $v !== null), $lines);

        return $post ? app(VoucherService::class)->post($voucher)->fresh() : $voucher;
    }

    private function moneyIn(Account $bank, string $amount): void
    {
        $voucher = app(VoucherService::class)->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => now()->subDays(2)->toDateString(), 'narration' => 'ব্যাংকে মূলধন', 'instrument_no' => 'CAP-'.$bank->code],
            [['account_id' => $bank->id, 'debit' => $amount], ['account_id' => StandardChart::find(StandardChart::OWNER_CAPITAL)->id, 'credit' => $amount]],
        );
        app(VoucherService::class)->post($voucher);
    }

    private function bankAccount(string $suffix, string $name): Account
    {
        return Account::query()->where('code', StandardChart::BANK.$suffix)->first()
            ?? app(AccountService::class)->create(['code' => StandardChart::BANK.$suffix, 'name_en' => $name,
                'parent_id' => Account::query()->where('code', StandardChart::BANK)->value('id')]);
    }

    private function till(): Account
    {
        return Account::query()->where('money_kind', Account::CASH)->postable()->active()->orderBy('code')->firstOrFail();
    }

    private function url(Account $account): string
    {
        return route('accounts.voucher.bank_file', ['account' => $account->id, 'date' => $this->day]);
    }

    /** @return list<list<string>> */
    private function rows(string $csv): array
    {
        return array_values(array_map(fn (string $line) => str_getcsv($line, ',', '"', ''), array_filter(explode("\r\n", $csv), fn ($l) => $l !== '')));
    }
}
