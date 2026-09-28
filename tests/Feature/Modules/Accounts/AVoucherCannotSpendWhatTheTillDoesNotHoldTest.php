<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * টিলে যা নেই, ভাউচার দিয়েও তা বের হয় না — ২৮ সেপ্টেম্বর ২০২৬।
 *
 * ── ⛔ কী ঘটেছিল, লাইভ QA (hp2, TCL), ২৭ সেপ্টেম্বর ২০২৬ ──────────────
 * খালি নগদ টিল থেকে ১,০০০ টাকার পরিশোধ পোস্ট হয়ে গেল (PMT-0001) — খাতা
 * মিলল, অথচ টিলের জের −১,০০০। ⓘ ক্রয়ের পরিশোধে পাহারা আগেই বসেছিল;
 * পরিশোধ, খরচ আর কন্ট্রা ভাউচার একই টিল থেকে টাকা বের করে, আর ওরা
 * [[VoucherService::post()]] দিয়ে আসে — পাহারা এখন সেখানেও
 * ([[VoucherService::assertMoneyIsThere()]], নিয়ম [[CashOnHand]]-এ)।
 *
 * ── এই ফাইল যা মাপে ────────────────────────────────────────────────
 *   ১. খালি টিল থেকে পরিশোধ — আটকায়, খাতায় কিছু ওঠে না, খসড়াই থাকে
 *   ২. সীমানা — ঠিক যা আছে তা যায়, এক টাকা বেশি যায় না
 *   ৩. ব্যাংক পাহারার বাইরে — CC/OD ঋণাত্মক হতে পারে
 *   ৪. MFS (বিকাশ) পাহারার ভিতরে
 *   ৫. পিছনের তারিখ — আজ টাকা আছে, গতকাল ছিল না
 *   ৬. টাকা ঢোকা কখনো আটকায় না
 *   ৭. কন্ট্রা নগদ → ব্যাংক — নগদের খাতের নাম ধরে বলে
 *
 * ⓘ সেবাটা সরাসরি ডাকা হয়, পর্দা নয় — প্রশ্নটা নিয়মের, আর পর্দার
 * দরজাগুলো নিজের ফাইলে মাপা। ⚠️ প্রত্যাশিত বার্তা সবসময় `__()` থেকে,
 * একই চাবি আর একই মান দিয়ে — হাতে লেখা বাক্য একদিন নীরবে পুরনো হত।
 */
final class AVoucherCannotSpendWhatTheTillDoesNotHoldTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private User $owner;

    private Account $cash;

    private Account $bank;

    private Account $mfs;

    /** টাকা যেখানে যায় — খরচের খাত, টাকার খাত নয়। */
    private Account $expense;

    private VoucherService $vouchers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        app(StandardChart::class)->install();

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        /*
         * ⚠️ নগদের খাতটা মালিকের নামে (`held_by`) — [[CashTill::mayUse()]]
         * তখন বাক্সের প্রশ্ন না তুলেই হ্যাঁ বলে। ⛔ নাহলে DemoSeeder কোনো
         * বাক্স অন্যের নামে বসালে পোস্ট "আপনার টিল নয়" বলে আটকাত, আর
         * দাবিগুলো ভুল কারণে লাল বা (উল্টো দাবিতে) সবুজ হত।
         */
        $this->cash = $this->moneyAccount('1101-SPEND', StandardChart::CASH_IN_HAND, Account::CASH);
        $this->cash->forceFill(['held_by' => $this->owner->id])->save();

        $this->bank = $this->moneyAccount('1102-SPEND', StandardChart::BANK, Account::BANK);
        $this->mfs = $this->moneyAccount('1105-SPEND', '1105', Account::MFS);

        $this->expense = StandardChart::find(StandardChart::BANK_CHARGES);
        $this->assertNotNull($this->expense, 'প্রস্তুতি: খরচের খাত ৫২১০ নেই।');
        $this->assertFalse((bool) $this->expense->is_group, 'প্রস্তুতি: ৫২১০ একটা গ্রুপ।');
        $this->assertNull($this->expense->money_kind, 'প্রস্তুতি: ৫২১০ টাকার খাত — তাহলে সে নিজেই পাহারায় পড়ত।');

        $this->vouchers = app(VoucherService::class);
    }

    // ── ১ · খালি টিল ────────────────────────────────────────────────────

    public function test_a_payment_out_of_an_empty_till_says_the_till_does_not_hold_it(): void
    {
        $voucher = $this->payment($this->cash, '1000');

        $this->assertRefused($voucher, $this->cash, held: '0', amount: '1000');

        $this->assertSame(0, $this->ledgerRowsOf($voucher),
            '⛔ আটকানো পরিশোধের সারি খাতায় উঠে গেছে — লেনদেনটা ফেরানো হয়নি।');
        $this->assertSame(DocumentStatus::DRAFT, $voucher->fresh()->status,
            '⛔ আটকানো পরিশোধটা আর খসড়া নেই।');
        $this->assertSame('0.0000', $this->balanceOf($this->cash),
            '⛔ টিলের জের বদলে গেছে, অথচ পরিশোধ আটকানোর কথা।');
    }

    // ── ২ · সীমানা ─────────────────────────────────────────────────────

    public function test_exactly_what_the_till_holds_goes_out_and_one_taka_more_does_not(): void
    {
        $this->putMoneyIn($this->cash, '1000');

        // ⓘ একসাথে এক টাকা বেশি — আটকায়, আর বলে ঠিক কত আছে
        $tooMuch = $this->payment($this->cash, '1001');
        $this->assertRefused($tooMuch, $this->cash, held: '1000', amount: '1001');

        // ⭐ ঠিক যতটা আছে — যায় (সমান মানে যথেষ্ট)
        $exact = $this->payment($this->cash, '1000');
        $this->assertPosts($exact);
        $this->assertSame('0.0000', $this->balanceOf($this->cash), 'পুরো টাকা দেওয়ার পর টিল শূন্য নয়।');

        // ⛔ এখন টিল শূন্য — আর এক টাকাও নয়
        $oneMore = $this->payment($this->cash, '1');
        $this->assertRefused($oneMore, $this->cash, held: '0', amount: '1');
        $this->assertSame(0, $this->ledgerRowsOf($oneMore));
    }

    // ── ৩ · ব্যাংক পাহারার বাইরে ─────────────────────────────────────────

    public function test_an_empty_bank_account_can_still_pay(): void
    {
        /*
         * ⓘ ইচ্ছাকৃত — চলতি ঋণের (CC/OD) খাত আইনত ঋণাত্মক হয়
         * ([[CashOnHand::guards()]])। ⛔ ব্যাংক পাহারায় পড়লে ঋণের সীমা
         * থেকে দেওয়া প্রতিটা পরিশোধ আটকাত।
         */
        $voucher = $this->payment($this->bank, '5000', 'TRX-BANK-5001');

        $this->assertPosts($voucher);
        $this->assertSame('-5000.0000', $this->balanceOf($this->bank), 'ব্যাংকের জের −৫,০০০ হওয়ার কথা।');
    }

    // ── ৪ · MFS পাহারার ভিতরে ────────────────────────────────────────────

    public function test_an_empty_bkash_wallet_says_it_does_not_hold_the_money(): void
    {
        $voucher = $this->payment($this->mfs, '700', 'TRX-BKASH-7001');

        $this->assertRefused($voucher, $this->mfs, held: '0', amount: '700');
        $this->assertSame(0, $this->ledgerRowsOf($voucher), '⛔ আটকানো বিকাশ পরিশোধ খাতায় উঠেছে।');
        $this->assertSame(DocumentStatus::DRAFT, $voucher->fresh()->status);
    }

    // ── ৫ · পিছনের তারিখ ────────────────────────────────────────────────

    public function test_a_payment_dated_yesterday_is_refused_when_the_money_came_in_today(): void
    {
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();

        $this->putMoneyIn($this->cash, '1000', $today);

        /*
         * ⛔ কেবল আজকের জের দেখলে এটা পাশ করত — আর গতকালের টিল −৫০০
         * হয়ে থাকত, সেদিনের দিনশেষের গোনা আর কখনো মিলত না।
         */
        $backdated = $this->payment($this->cash, '500', date: $yesterday);
        $this->assertRefused($backdated, $this->cash, held: '0', amount: '500');
        $this->assertSame(0, $this->ledgerRowsOf($backdated));

        // ⓘ একই অঙ্ক, আজকের তারিখে — যায়; অর্থাৎ আটকানোর কারণ তারিখটাই
        $this->assertPosts($this->payment($this->cash, '500', date: $today));
    }

    // ── ৬ · টাকা ঢোকা ──────────────────────────────────────────────────

    public function test_money_coming_into_an_empty_till_is_never_blocked(): void
    {
        $capital = StandardChart::find(StandardChart::OWNER_CAPITAL);
        $this->assertNotNull($capital);

        $voucher = $this->vouchers->create(
            ['type' => Voucher::RECEIPT, 'trx_date' => now()->toDateString(), 'narration' => 'SPEND-IN'],
            $this->vouchers->twoLineEntry(Voucher::RECEIPT, (int) $capital->id, (int) $this->cash->id, '2500'),
        );

        $this->assertPosts($voucher);
        $this->assertSame('2500.0000', $this->balanceOf($this->cash), 'রসিদের পর টিলে ২,৫০০ থাকার কথা।');
    }

    // ── ৭ · কন্ট্রা নগদ → ব্যাংক ──────────────────────────────────────────

    public function test_a_contra_from_cash_to_bank_needs_the_cash_and_names_the_till(): void
    {
        $this->putMoneyIn($this->cash, '1000');

        $enough = $this->contra('600', 'DEP-SPEND-0600');
        $this->assertPosts($enough);

        // ⓘ এখন টিলে ৪০০ — ৫০০ জমা দেওয়া যায় না, আর বার্তা নগদের খাতের নাম বলে
        $tooMuch = $this->contra('500', 'DEP-SPEND-0500');
        $message = $this->assertRefused($tooMuch, $this->cash, held: '400', amount: '500');

        $this->assertStringContainsString($this->cash->label(), $message,
            '⛔ বার্তা নগদের খাতের নাম বলে না — কোন টিলে টাকা কম, মানুষটা বুঝবেন না।');
        $this->assertStringNotContainsString($this->bank->label(), $message,
            '⛔ বার্তা ব্যাংকের নাম বলছে — ব্যাংকে তো টাকা ঢুকছিল।');
        $this->assertSame(0, $this->ledgerRowsOf($tooMuch));
        $this->assertSame('400.0000', $this->balanceOf($this->cash));
    }

    // ── সাহায্যকারী ──────────────────────────────────────────────────────

    private function payment(Account $from, string $amount, ?string $reference = null, ?string $date = null): Voucher
    {
        $voucher = $this->vouchers->create(
            [
                'type' => Voucher::PAYMENT,
                'trx_date' => $date ?? now()->toDateString(),
                'narration' => 'SPEND-'.$from->code.'-'.$amount,
                'instrument_no' => $reference,
            ],
            $this->vouchers->twoLineEntry(Voucher::PAYMENT, (int) $from->id, (int) $this->expense->id, $amount),
        );

        $this->assertSame(DocumentStatus::DRAFT, $voucher->status, 'প্রস্তুতি: খসড়া তৈরি হয়নি।');

        return $voucher;
    }

    private function contra(string $amount, string $reference): Voucher
    {
        return $this->vouchers->create(
            [
                'type' => Voucher::CONTRA,
                'trx_date' => now()->toDateString(),
                'instrument' => 'transfer',
                'instrument_no' => $reference,
            ],
            $this->vouchers->twoLineEntry(Voucher::CONTRA, (int) $this->cash->id, (int) $this->bank->id, $amount),
        );
    }

    /**
     * পোস্ট চাপলে ঠিক এই বার্তাটা, `lines` চাবিতে — ফেরত দেয় বার্তাটা।
     */
    private function assertRefused(Voucher $voucher, Account $account, string $held, string $amount): string
    {
        try {
            $this->vouchers->post($voucher);
        } catch (ValidationException $e) {
            $errors = $e->errors();

            $this->assertArrayHasKey('lines', $errors,
                'অন্য কারণে আটকেছে, টাকার পাহারায় নয়: '.json_encode($errors, JSON_UNESCAPED_UNICODE));

            $expected = __('accounts::validation.not_enough_money_in', [
                'account' => $account->label(),
                'held' => Money::format($held),
                'amount' => Money::format($amount),
            ]);

            $this->assertSame($expected, $errors['lines'][0]);

            return $errors['lines'][0];
        }

        $this->fail("⛔ {$voucher->document_no} পোস্ট হয়ে গেছে — {$account->label()}-এ যা নেই তা বেরিয়ে গেল।");
    }

    private function assertPosts(Voucher $voucher): void
    {
        try {
            $this->vouchers->post($voucher);
        } catch (ValidationException $e) {
            $this->fail("⛔ {$voucher->document_no} আটকে গেছে, অথচ যাওয়ার কথা: "
                .json_encode($e->errors(), JSON_UNESCAPED_UNICODE));
        }

        $this->assertSame(DocumentStatus::CONFIRMED, $voucher->fresh()->status);
        $this->assertGreaterThan(0, $this->ledgerRowsOf($voucher),
            'পোস্ট হওয়া ভাউচারের সারি খুঁজে পাওয়া যায়নি — তাহলে "শূন্য সারি" দাবিটাও কিছু মাপে না।');
    }

    private function ledgerRowsOf(Voucher $voucher): int
    {
        return LedgerEntry::query()
            ->where('source_type', Voucher::SOURCE_TYPES[$voucher->type])
            ->where('source_id', $voucher->id)
            ->count();
    }

    private function balanceOf(Account $account): string
    {
        $row = LedgerEntry::query()
            ->where('account_id', $account->id)
            ->selectRaw('COALESCE(SUM(debit), 0) as d, COALESCE(SUM(credit), 0) as c')
            ->first();

        return bcsub((string) $row->d, (string) $row->c, 4);
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
