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
use App\Modules\Accounts\Services\AccountsFacts;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * টাকা বেরোনোর চার্জ কোম্পানির, সরবরাহকারীর নয় — ২৮ সেপ্টেম্বর ২০২৬।
 *
 * ── ⭐ সমন্বয়কারীর সিদ্ধান্ত (abos-69) ──────────────────────────────────
 *   বিকাশে ৯০০ দেওয়া · চার্জ ৫
 *   → দেনা ডেবিট ৯০০ · ৫২১১ ডেবিট ৫ · বিকাশ ক্রেডিট ৯০৫
 *
 * ── ⛔ আগে কী হত ───────────────────────────────────────────────────────
 * সব চার্জ [[VoucherService::withCharge()]]-এ যেত — যা লেখা টাকা **ঢোকার**
 * জন্য: চার্জ কাটা হত যা পৌঁছাল তা থেকে, আর চার্জের খাত বাছা হত যেখানে
 * টাকা **গেল** তা দেখে। পরিশোধে সেটা দেনার খাত — তাই হয় ভুলবার্তা, নয়
 * সরবরাহকারীর খাতায় এমন টাকা "পরিশোধ" যা তিনি কোনোদিন পাননি।
 *
 * ── ⓘ এই ফাইল কী মাপে ─────────────────────────────────────────────────
 * আসল ফর্মের পথ (`accounts.voucher.store`) দিয়ে ভাউচার পাঠিয়ে **খতিয়ানে**
 * কী বসল তা দেখা — ভাউচারের সারি নয়, কারণ বকেয়া আর ব্যালান্স খতিয়ান
 * থেকেই গোনা হয়। ⚠️ রসিদের পুরনো নিয়ম (চার্জ পৌঁছানো টাকা থেকে) অক্ষত
 * আছে কি না, সেটাও এখানে পাহারায়।
 *
 * ⚠️ নগদ ও MFS শূন্যের নিচে নামে না ([[CashOnHand]]) — তাই বিকাশে আগে টাকা
 * রাখা হয় ([[PutsMoneyInTheTill]]), মালিকের পুঁজি থেকে, খাতা মেনে।
 */
final class ThePaymentChargeIsTheCompanysNotTheSuppliersTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Account $bank;

    private Account $mfs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        app(StandardChart::class)->install();

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        $this->bank = $this->moneyAccount('1102-CHG', StandardChart::BANK, Account::BANK);
        $this->mfs = $this->moneyAccount('1105-CHG', StandardChart::MOBILE_MONEY, Account::MFS);
    }

    // ── ১ · সমন্বয়কারীর হুবহু ঘটনা ─────────────────────────────────────

    /**
     * ⭐ বিকাশে সরবরাহকারীকে ৯০০, চার্জ ৫।
     *
     * ⛔ দেনা কমে **৯০০**, ৯০৫ নয় — ৫ টাকা সরবরাহকারী কোনোদিন পাননি।
     * ⓘ বিকাশ থেকে বেরোয় ৯০৫ — ওয়ালেটের ব্যালান্স ঠিক এটাই কমে।
     */
    public function test_a_bkash_payment_books_the_charge_to_mfs_charges_and_the_supplier_gets_the_amount(): void
    {
        $this->putMoneyIn($this->mfs, '2000');

        $supplier = Supplier::query()->orderBy('id')->firstOrFail();
        $facts = app(AccountsFacts::class);
        $before = $facts->dueFrom('supplier', (int) $supplier->id);

        $this->pay('900', '5', $this->mfs, 'mfs', 'BKPAY9001', $supplier)
            ->assertSessionHasNoErrors();

        $voucher = $this->lastConfirmed(Voucher::PAYMENT);
        $book = $this->ledgerOf($voucher);

        $this->assertMoved($book, $this->mfs, credit: '905',
            message: '⛔ বিকাশ থেকে ৯০৫ বেরোয়নি — চার্জটা ওয়ালেট থেকে যায়নি।');
        $this->assertMoved($book, $this->account(StandardChart::MFS_CHARGES), debit: '5',
            message: '⛔ চার্জ ৫ টাকা ৫২১১ (MFS চার্জ) খাতে বসেনি।');
        $this->assertMoved($book, $this->account(StandardChart::PAYABLE), debit: '900',
            message: '⛔ দেনা ঠিক ৯০০ কমেনি — চার্জ দেনায় মিশে গেছে বা হারিয়েছে।');

        $this->assertCount(3, $book, 'চার্জসহ পরিশোধে খতিয়ানে ঠিক তিনটা খাত নড়ার কথা: '.json_encode($book));
        $this->assertBalanced($voucher);

        $payable = LedgerEntry::query()
            ->where('source_type', Voucher::SOURCE_TYPES[Voucher::PAYMENT])
            ->where('source_id', $voucher->id)
            ->where('account_id', $this->account(StandardChart::PAYABLE)->id)
            ->firstOrFail();

        $this->assertSame('supplier', $payable->party_type, '⛔ দেনার সারিতে পক্ষ বসেনি।');
        $this->assertSame((int) $supplier->id, (int) $payable->party_id);

        $after = $facts->dueFrom('supplier', (int) $supplier->id);

        $this->assertSame(0, bccomp(bcsub($after, $before, 4), '900', 4),
            "⛔ সরবরাহকারীর বকেয়া ঠিক ৯০০ কমেনি (আগে {$before}, পরে {$after}) — ৯০৫ কমলে তিনি "
            .'এমন ৫ টাকা "পেয়েছেন" দেখায় যা বিকাশ কেটে রেখেছে।');
    }

    // ── ২ · ব্যাংক থেকে — চার্জ ৫২১০-এ ──────────────────────────────────

    /**
     * ⓘ একই ঘটনা ব্যাংক থেকে। ⚠️ চার্জের খাত আসে **যেখান থেকে** টাকা
     * বেরোল তা দেখে — ব্যাংক হলে ৫২১০, ৫২১১ নয়।
     */
    public function test_the_same_payment_from_a_bank_books_the_charge_to_bank_charges(): void
    {
        $supplier = Supplier::query()->orderBy('id')->firstOrFail();

        $this->pay('900', '5', $this->bank, 'transfer', 'NPSB-CHG-2001', $supplier)
            ->assertSessionHasNoErrors();

        $voucher = $this->lastConfirmed(Voucher::PAYMENT);
        $book = $this->ledgerOf($voucher);

        $this->assertMoved($book, $this->bank, credit: '905');
        $this->assertMoved($book, $this->account(StandardChart::BANK_CHARGES), debit: '5',
            message: '⛔ ব্যাংকের চার্জ ৫২১০ খাতে বসেনি।');
        $this->assertMoved($book, $this->account(StandardChart::PAYABLE), debit: '900');

        $this->assertArrayNotHasKey((int) $this->account(StandardChart::MFS_CHARGES)->id, $book,
            '⛔ ব্যাংকের চার্জ MFS-চার্জের খাতে (৫২১১) গেছে — "বিকাশে বছরে কত গেল" তখন মিথ্যা।');
        $this->assertBalanced($voucher);
    }

    // ── ৩ · নগদে চার্জ নেই ────────────────────────────────────────────

    /**
     * ⛔ নগদ থেকে পরিশোধে চার্জ লিখলে থামে, `charge_amount` ঘরে কারণ বলে,
     * আর কিছুই খাতায় ওঠে না — না ভাউচার, না খতিয়ানের সারি।
     */
    public function test_a_charge_on_a_cash_payment_is_refused_and_nothing_posts(): void
    {
        $cash = Account::query()->where('money_kind', Account::CASH)
            ->postable()->active()->orderBy('code')->firstOrFail();
        $supplier = Supplier::query()->orderBy('id')->firstOrFail();

        $vouchers = Voucher::query()->count();
        $entries = LedgerEntry::query()->count();

        $this->pay('900', '5', $cash, 'cash', null, $supplier)
            ->assertSessionHasErrors([
                'charge_amount' => __('accounts::validation.charge_needs_a_bank_or_mfs'),
            ]);

        $this->assertSame($vouchers, Voucher::query()->count(), '⛔ থামা পরিশোধের পরেও একটা ভাউচার রয়ে গেছে।');
        $this->assertSame($entries, LedgerEntry::query()->count(), '⛔ থামা পরিশোধ খতিয়ানে কিছু লিখে গেছে।');
    }

    // ── ৪ · খরচ ভাউচার — পরিশোধের মতোই ────────────────────────────────

    /**
     * ⭐ বিকাশ থেকে ভাড়া ৯০০, চার্জ ৫ → ভাড়া ৯০০ · ৫২১১-এ ৫ · বিকাশ ৯০৫।
     *
     * ⚠️ ভাড়া ৮৯৫ দেখালে বাড়িওয়ালার পাওনা মিথ্যা, আর ৯০৫ দেখালে ভাড়ার খরচ।
     */
    public function test_an_expense_from_bkash_with_a_charge_splits_like_a_payment(): void
    {
        $this->putMoneyIn($this->mfs, '2000');
        $rent = $this->account(StandardChart::RENT);

        $this->submit(Voucher::EXPENSE, [
            'from_account_id' => $this->mfs->id,
            'to_account_id' => $rent->id,
            'amount' => '900',
            'charge_amount' => '5',
            'instrument' => 'mfs',
            'instrument_no' => 'BKEXP4001',
        ])->assertSessionHasNoErrors();

        $voucher = $this->lastConfirmed(Voucher::EXPENSE);
        $book = $this->ledgerOf($voucher);

        $this->assertMoved($book, $rent, debit: '900', message: '⛔ ভাড়ার খরচ ঠিক ৯০০ বসেনি।');
        $this->assertMoved($book, $this->account(StandardChart::MFS_CHARGES), debit: '5');
        $this->assertMoved($book, $this->mfs, credit: '905');
        $this->assertCount(3, $book);
        $this->assertBalanced($voucher);
    }

    // ── ৫ · রসিদের পুরনো নিয়ম অক্ষত ───────────────────────────────────

    /**
     * ⚠️ পাহারা: বিকাশে **আসা** টাকায় নিয়ম আগের মতোই — ১০০০ পাঠালেন, ২০ কাটল
     * → বিকাশ ৯৮০ · ৫২১১-এ ২০ · গ্রাহকের প্রাপ্য ১০০০ কমে।
     *
     * ⛔ নতুন পথটা রসিদেও চললে বিকাশে ১০০০ আর উৎসে ১০২০ বসত — গ্রাহক এমন
     * ২০ টাকা "দিয়েছেন" দেখাত যা তিনি দেননি।
     */
    public function test_a_receipt_into_bkash_still_takes_the_charge_out_of_what_arrives(): void
    {
        $customer = Customer::query()->orderBy('id')->firstOrFail();
        $receivable = $this->account(StandardChart::RECEIVABLE);

        $this->submit(Voucher::RECEIPT, [
            'party_type' => 'customer',
            'party_id' => $customer->id,
            'from_account_id' => $receivable->id,
            'to_account_id' => $this->mfs->id,
            'amount' => '1000',
            'charge_amount' => '20',
            'instrument' => 'mfs',
            'instrument_no' => 'BKRCV5001',
        ])->assertSessionHasNoErrors();

        $voucher = $this->lastConfirmed(Voucher::RECEIPT);
        $book = $this->ledgerOf($voucher);

        $this->assertMoved($book, $this->mfs, debit: '980',
            message: '⛔ রসিদের পুরনো নিয়ম ভেঙেছে — বিকাশে ৯৮০ ঢোকার কথা।');
        $this->assertMoved($book, $this->account(StandardChart::MFS_CHARGES), debit: '20');
        $this->assertMoved($book, $receivable, credit: '1000',
            message: '⛔ গ্রাহকের প্রাপ্য ঠিক ১০০০ কমেনি।');
        $this->assertCount(3, $book);
        $this->assertBalanced($voucher);
    }

    // ── ৬ · চার্জ ছাড়া — দুই সারিই ────────────────────────────────────

    /**
     * ⓘ চার্জহীন লক্ষ ভাউচারের পথ এক চুলও বদলায়নি: দুইটা সারি, দুইটা খাত।
     */
    public function test_a_payment_without_a_charge_still_makes_exactly_two_lines(): void
    {
        $this->putMoneyIn($this->mfs, '2000');
        $supplier = Supplier::query()->orderBy('id')->firstOrFail();

        $this->pay('900', null, $this->mfs, 'mfs', 'BKPAY6001', $supplier)
            ->assertSessionHasNoErrors();

        $voucher = $this->lastConfirmed(Voucher::PAYMENT);

        $this->assertCount(2, $voucher->lines, '⛔ চার্জ ছাড়া পরিশোধে দুইটার বেশি সারি হয়েছে।');

        $book = $this->ledgerOf($voucher);

        $this->assertCount(2, $book);
        $this->assertMoved($book, $this->mfs, credit: '900');
        $this->assertMoved($book, $this->account(StandardChart::PAYABLE), debit: '900');
        $this->assertBalanced($voucher);
    }

    // ── প্রস্তুতি ────────────────────────────────────────────────────────

    /**
     * সরবরাহকারীকে পরিশোধ — ফর্মের মতো: `to_account_id` খালি, সার্ভার
     * পক্ষ থেকে দেনার খাত (২১১১) বসায় ([[VoucherRequest::fillAccountFromParty()]])।
     */
    private function pay(string $amount, ?string $charge, Account $from, string $way, ?string $reference, Supplier $supplier): TestResponse
    {
        return $this->submit(Voucher::PAYMENT, array_filter([
            'party_type' => 'supplier',
            'party_id' => $supplier->id,
            'from_account_id' => $from->id,
            'amount' => $amount,
            'charge_amount' => $charge,
            'instrument' => $way,
            'instrument_no' => $reference,
        ], fn ($v) => $v !== null));
    }

    /**
     * ⚠️ `type` ঘরটাও পাঠানো হয় — যাচাই ফর্মের ঘরটা পড়ে, ঠিকানারটা নয়
     * ([[TheReceiptAskedTwelveQuestionsAndKnewSixTest::postReceipt()]])।
     *
     * @param  array<string, mixed>  $fields
     */
    private function submit(string $type, array $fields): TestResponse
    {
        return $this->from(route('accounts.voucher.create', ['type' => $type]))
            ->post(route('accounts.voucher.store', ['type' => $type]), [
                'type' => $type,
                'trx_date' => now()->toDateString(),
                'narration' => 'পরীক্ষার বিবরণ', // ⓘ বিবরণ বাধ্যতামূলক (ভাউচারের পরিকল্পনা ৩খ, ৭ অক্টোবর ২০২৬)
                ...$fields,
            ]);
    }

    private function lastConfirmed(string $type): Voucher
    {
        $voucher = Voucher::query()->where('type', $type)->latest('id')->firstOrFail();

        $this->assertSame(DocumentStatus::CONFIRMED, $voucher->status,
            "⛔ {$type} ভাউচারটা খাতায় বসেনি (অবস্থা: {$voucher->status}).");

        return $voucher;
    }

    /**
     * খতিয়ানে এই ভাউচার কোন খাতে কত নাড়াল — খাত ধরে যোগ।
     *
     * @return array<int, array{debit: string, credit: string}>
     */
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

    /**
     * @param  array<int, array{debit: string, credit: string}>  $book
     */
    private function assertMoved(array $book, Account $account, string $debit = '0', string $credit = '0', string $message = ''): void
    {
        $row = $book[(int) $account->id] ?? null;

        $this->assertNotNull($row, trim($message.' — খাত '.$account->code.' খতিয়ানে নড়েইনি: '.json_encode($book)));
        $this->assertSame(0, bccomp($row['debit'], $debit, 4),
            trim($message." — {$account->code} ডেবিট {$row['debit']}, চাওয়া {$debit}"));
        $this->assertSame(0, bccomp($row['credit'], $credit, 4),
            trim($message." — {$account->code} ক্রেডিট {$row['credit']}, চাওয়া {$credit}"));
    }

    private function assertBalanced(Voucher $voucher): void
    {
        $row = LedgerEntry::query()
            ->where('source_type', Voucher::SOURCE_TYPES[$voucher->type])
            ->where('source_id', $voucher->id)
            ->selectRaw('COALESCE(SUM(debit), 0) as d, COALESCE(SUM(credit), 0) as c')
            ->first();

        $this->assertSame(0, bccomp((string) $row->d, (string) $row->c, 4),
            "⛔ খতিয়ান মেলেনি: ডেবিট {$row->d}, ক্রেডিট {$row->c}।");
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
