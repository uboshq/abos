<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ⛔ উৎসে কর্তিত কর (AIT/VDS) খাতায় উঠত না — আন্তর্জাতিক মানের ফাঁক #১ (হিসাব, গুরুতর), ২০ অক্টোবর ২০২৬ (fe; gaps-accounts.md
 * সারি ১; আয়কর আইন ২০২৩ সপ্তম অংশ, মূসক ও SD আইন ২০১২ ধারা ৪৯; [[WithheldTax]])।
 *
 * দাবি (খরচ আর পরিশোধ ভাউচারের আসল ফর্ম দিয়ে, খতিয়ানের অঙ্কে):
 *  - ১০,০০০ টাকার ভাড়ার বিল, AIT ৫০০ আর VDS ৫০০ কেটে বিকাশে ৯,০০০: ভাড়া Dr ১০,০০০ (মোট, IAS 1 ¶৩২ — কাটাকাটি নয়) ·
 *    বিকাশ Cr ৯,০০০ (নিট) · ২১২১ Cr ৫০০ (AIT) আর ৫০০ (VDS) আলাদা সারিতে; খাতা মেলে;
 *  - বিলের মোট ভুল লিখলে (১০,৫০০) থামে, খতিয়ানে কিছু বসে না;
 *  - কর্তন না থাকলে আগের মতো দুই সারি, ভাড়া নিটে;
 *  - সরকারকে জমা (পরিশোধ ভাউচার, Dr ২১২১ / Cr ব্যাংক) দিলে ২১২১-এর দায় শূন্যে নামে।
 */
final class TheTaxHeldBackNeverReachedTheBooksTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private Account $mfs;

    private Account $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        app(StandardChart::class)->install();
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->mfs = $this->moneyAccount('1105-AIT', StandardChart::MOBILE_MONEY, Account::MFS);
        $this->bank = $this->moneyAccount('1102-AIT', StandardChart::BANK, Account::BANK);
        $this->putMoneyIn($this->mfs, '20000');
        $this->putMoneyIn($this->bank, '20000');
    }

    public function test_the_expense_is_booked_gross_the_payee_gets_net_and_the_withheld_tax_is_owed_until_deposited(): void
    {
        $rent = $this->account(StandardChart::RENT);
        $withheld = $this->account(StandardChart::TAX_DEDUCTED_AT_SOURCE);
        // ⭐ VDS নিজের খাতে (২১২২) — মূসক আর আয়কর আলাদা রিটার্ন (fe, ২১ অক্টোবর ২০২৬)
        $vds = $this->account(StandardChart::VDS_PAYABLE);

        $this->expense(['amount' => '9000', 'gross_amount' => '10000', 'ait_amount' => '500', 'vds_amount' => '500'])
            ->assertSessionHasNoErrors();

        $voucher = $this->lastConfirmed(Voucher::EXPENSE);
        $book = $this->ledgerOf($voucher);

        $this->assertMoved($book, $rent, debit: '10000', message: '⛔ খরচ মোটে বসেনি — কর্তনটা খরচ থেকে হারাল');
        $this->assertMoved($book, $this->mfs, credit: '9000', message: '⛔ হাতে দেওয়া টাকা নিট নয়');
        $this->assertMoved($book, $withheld, credit: '500', message: '⛔ কেটে রাখা আয়কর (AIT) ২১২১-এ বসেনি');
        $this->assertMoved($book, $vds, credit: '500', message: '⛔ কেটে রাখা ভ্যাট (VDS) নিজের খাতে (২১২২) বসেনি');
        $this->assertBalanced($voucher);
        $this->assertSame('500.0000', $this->owed($withheld), '২১২১-এ দায় ৫০০ নয়');
        $this->assertSame('500.0000', $this->owed($vds), '২১২২-এ দায় ৫০০ নয়');

        // ⓘ সরকারকে জমা — দায় শূন্যে। ⛔ ব্যাংক থেকে, তাই রেফারেন্স (চালানের নম্বর) লাগে — ব্যাংকের খাতের পুরনো নিয়ম, Mac-এ ধরা
        $this->submit(Voucher::PAYMENT, ['from_account_id' => $this->bank->id, 'to_account_id' => $withheld->id, 'amount' => '500',
            'instrument' => 'transfer', 'instrument_no' => 'A-CHALLAN-2026-0001'])
            ->assertSessionHasNoErrors();
        $this->lastConfirmed(Voucher::PAYMENT);
        $this->assertSame('0.0000', $this->owed($withheld), '⛔ সরকারকে জমার পরেও দায় রইল');
    }

    public function test_a_wrong_bill_total_is_refused_and_nothing_posts(): void
    {
        $entries = LedgerEntry::query()->count();

        $this->expense(['amount' => '9000', 'gross_amount' => '10500', 'ait_amount' => '500', 'vds_amount' => '500'])
            ->assertSessionHasErrors('gross_amount');

        $this->assertSame($entries, LedgerEntry::query()->count(), '⛔ মোট না মেলা খরচ খতিয়ানে বসল');
    }

    public function test_without_withholding_the_expense_is_two_lines_as_before(): void
    {
        $this->expense(['amount' => '9000'])->assertSessionHasNoErrors();

        $voucher = $this->lastConfirmed(Voucher::EXPENSE);
        $book = $this->ledgerOf($voucher);

        $this->assertCount(2, $book);
        $this->assertMoved($book, $this->account(StandardChart::RENT), debit: '9000');
        $this->assertMoved($book, $this->mfs, credit: '9000');
    }

    // ── প্রস্তুতি ──

    /** @param array<string, string> $fields */
    private function expense(array $fields): TestResponse
    {
        return $this->submit(Voucher::EXPENSE, [
            'from_account_id' => $this->mfs->id,
            'to_account_id' => $this->account(StandardChart::RENT)->id,
            'instrument' => 'mfs',
            'instrument_no' => 'BKAIT'.random_int(1000, 9999),
            ...$fields,
        ]);
    }

    /** @param array<string, mixed> $fields */
    private function submit(string $type, array $fields): TestResponse
    {
        return $this->from(route('accounts.voucher.create', ['type' => $type]))
            ->post(route('accounts.voucher.store', ['type' => $type]), [
                'type' => $type,
                'trx_date' => now()->toDateString(),
                'narration' => 'বাড়িভাড়া, অক্টোবর',
                ...$fields,
            ]);
    }

    private function lastConfirmed(string $type): Voucher
    {
        $voucher = Voucher::query()->where('type', $type)->latest('id')->firstOrFail();
        $this->assertSame(DocumentStatus::CONFIRMED, $voucher->status, "⛔ {$type} ভাউচারটা খাতায় বসেনি (অবস্থা: {$voucher->status}).");

        return $voucher;
    }

    /** ⓘ দায়ের জের — ক্রেডিট − ডেবিট */
    private function owed(Account $account): string
    {
        $row = LedgerEntry::query()->where('account_id', $account->id)
            ->selectRaw('COALESCE(SUM(credit), 0) - COALESCE(SUM(debit), 0) as net')->first();

        return bcadd((string) $row->net, '0', 4);
    }

    /** @return array<int, array{debit: string, credit: string}> */
    private function ledgerOf(Voucher $voucher): array
    {
        $book = [];

        LedgerEntry::query()
            ->where('source_type', Voucher::SOURCE_TYPES[$voucher->type])
            ->where('source_id', $voucher->id)
            ->get()
            ->each(function (LedgerEntry $entry) use (&$book): void {
                $id = (int) $entry->account_id;
                $book[$id] ??= ['debit' => '0', 'credit' => '0'];
                $book[$id]['debit'] = bcadd($book[$id]['debit'], (string) $entry->debit, 4);
                $book[$id]['credit'] = bcadd($book[$id]['credit'], (string) $entry->credit, 4);
            });

        return $book;
    }

    /** @param array<int, array{debit: string, credit: string}> $book */
    private function assertMoved(array $book, Account $account, string $debit = '0', string $credit = '0', string $message = ''): void
    {
        $row = $book[(int) $account->id] ?? null;

        $this->assertNotNull($row, trim($message.' — খাত '.$account->code.' খতিয়ানে নড়েইনি: '.json_encode($book)));
        $this->assertSame(0, bccomp($row['debit'], $debit, 4), trim($message." — {$account->code} ডেবিট {$row['debit']}, চাওয়া {$debit}"));
        $this->assertSame(0, bccomp($row['credit'], $credit, 4), trim($message." — {$account->code} ক্রেডিট {$row['credit']}, চাওয়া {$credit}"));
    }

    private function assertBalanced(Voucher $voucher): void
    {
        $row = LedgerEntry::query()
            ->where('source_type', Voucher::SOURCE_TYPES[$voucher->type])
            ->where('source_id', $voucher->id)
            ->selectRaw('COALESCE(SUM(debit), 0) as d, COALESCE(SUM(credit), 0) as c')
            ->first();

        $this->assertSame(0, bccomp((string) $row->d, (string) $row->c, 4), "⛔ খতিয়ান মেলেনি: ডেবিট {$row->d}, ক্রেডিট {$row->c}।");
    }

    private function account(string $code): Account
    {
        return StandardChart::find($code) ?? $this->fail("খাত {$code} ছকে নেই — সেটআপই ভাঙা।");
    }

    private function moneyAccount(string $code, string $parentCode, string $kind): Account
    {
        return Account::query()->create([
            'company_id' => CompanyContext::id(),
            'code' => $code,
            'name_en' => $code,
            'name_bn' => $code,
            'parent_id' => StandardChart::find($parentCode)->id,
            'type' => Account::ASSET,
            'nature' => Account::DEBIT,
            'money_kind' => $kind,
            'is_active' => true,
            'status' => DocumentStatus::CONFIRMED,
        ]);
    }
}
