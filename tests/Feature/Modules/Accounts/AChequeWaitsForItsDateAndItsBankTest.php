<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Core\Support\DateFormat;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Cheque;
use App\Modules\Accounts\Services\ChequeService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * চেক জমা পড়ে কেবল ব্যাংকে, আর টাকা হয় কেবল নিজের তারিখে।
 *
 * ── ⭐ মালিকের হিসাবের নিয়ম, ২৭ সেপ্টেম্বর ২০২৬ ──────────────────────
 *   • চেক জমা বা পাশ হয় কেবল **ব্যাংক** হিসাবে। ⛔ আগে যেকোনো খাত নেওয়া
 *     হত — নগদ বাক্স বা বিকাশে "পাশ" করালে বাক্সে এমন টাকা দেখাত যা
 *     কোনো ক্যাশিয়ার কোনোদিন গোনেনি, আর ব্যাংক-মিলকরণে চেকটা হারিয়ে যেত।
 *   • আগাম তারিখের চেক তারিখের আগে টাকা নয়। ⛔ আগে তারিখের আগেই জমা
 *     বা পাশ বসানো যেত — ডিলারের বকেয়া আগেভাগে কমত, আর ব্যাংক এমন
 *     টাকা দেখাত যা ব্যাংক তখনো দিতেই পারে না।
 *
 * ⓘ তারিখ মেলানো হয় কেবল দিন ধরে, সময় নয় — চেকের তারিখের দিনটাতেই চলে।
 */
final class AChequeWaitsForItsDateAndItsBankTest extends TestCase
{
    use RefreshDatabase;

    private Customer $dealer;

    private Account $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();

        $this->dealer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();

        $this->bank = $this->moneyAccount('1102-EBL', 'Eastern Bank', StandardChart::BANK, Account::BANK);
    }

    // ── ১ · কেবল ব্যাংকে ────────────────────────────────────────────────

    public function test_depositing_into_a_cash_till_is_refused(): void
    {
        $till = $this->moneyAccount('1101-TILL', 'Counter till', StandardChart::CASH_IN_HAND, Account::CASH);
        $cheque = $this->receive('20000');

        $this->assertRefusedOn('bank_account_id', fn () => app(ChequeService::class)->deposit($cheque, $till->id),
            'নগদ বাক্সে চেক জমা নেওয়া হয়েছে');

        $this->assertUntouched($cheque);
    }

    public function test_depositing_into_a_mobile_wallet_is_refused(): void
    {
        $wallet = $this->moneyAccount('1105-BKASH', 'bKash', StandardChart::MOBILE_MONEY, Account::MFS);
        $cheque = $this->receive('20000');

        $this->assertRefusedOn('bank_account_id', fn () => app(ChequeService::class)->deposit($cheque, $wallet->id),
            'বিকাশে চেক জমা নেওয়া হয়েছে');

        $this->assertUntouched($cheque);
    }

    public function test_clearing_into_a_cash_till_is_refused(): void
    {
        $till = $this->moneyAccount('1101-TILL', 'Counter till', StandardChart::CASH_IN_HAND, Account::CASH);
        $cheque = $this->receive('20000');

        $this->assertRefusedOn('bank_account_id', fn () => app(ChequeService::class)->clear($cheque, $till->id),
            'চেক নগদ বাক্সে পাশ হয়েছে');

        $this->assertUntouched($cheque);
        $this->assertSame(0, LedgerEntry::query()->where('account_id', $till->id)->count(),
            'ফেরানো পাশ নগদ বাক্সে টাকা বসিয়ে গেছে।');
    }

    public function test_clearing_an_issued_cheque_out_of_a_cash_till_is_refused(): void
    {
        $till = $this->moneyAccount('1101-TILL', 'Counter till', StandardChart::CASH_IN_HAND, Account::CASH);
        $cheque = $this->issue('9000');
        $entriesBefore = $this->entriesFor($cheque);

        $this->assertRefusedOn('bank_account_id', fn () => app(ChequeService::class)->clear($cheque, $till->id),
            'দেওয়া চেক নগদ বাক্স থেকে ভাঙানো হয়েছে');

        $this->assertSame(Cheque::PENDING, $cheque->fresh()->status);
        $this->assertSame($entriesBefore, $this->entriesFor($cheque), 'ফেরানো ভাঙানো খাতায় দাখিলা রেখে গেছে।');
    }

    public function test_clearing_into_a_bank_still_moves_the_money(): void
    {
        /* ⓘ পাল্টা দাবি — পাহারা যেন সবকিছুই না ফেরায় */
        $dueBefore = $this->due();
        $cheque = $this->receive('20000');

        app(ChequeService::class)->deposit($cheque, $this->bank->id);
        $cleared = app(ChequeService::class)->clear($cheque->fresh(), $this->bank->id);

        $this->assertSame(Cheque::CLEARED, $cleared->status);
        $this->assertSame($this->bank->id, $cleared->bank_account_id);
        $this->assertSame(0, bccomp($this->balanceOf($this->bank->id), '20000', 2),
            'ব্যাংকে পাশ হওয়া চেকের টাকা ব্যাংকে বসেনি।');
        $this->assertSame(0, bccomp(bcsub($dueBefore, $this->due(), 4), '20000', 2),
            'ব্যাংকে পাশ হওয়া চেকে ডিলারের বকেয়া ২০,০০০ কমেনি।');
        $this->assertSame(['cheque:cleared'], $this->sourcesOf($cheque));
    }

    // ── ২ · নিজের তারিখের আগে নয় ──────────────────────────────────────

    public function test_depositing_a_post_dated_cheque_before_its_date_is_refused(): void
    {
        $cheque = $this->receive('15000', now()->addDays(5)->toDateString());

        $this->assertRefusedOn('deposited_on',
            fn () => app(ChequeService::class)->deposit($cheque, $this->bank->id, now()->toDateString()),
            'আগাম তারিখের চেক তারিখের আগেই জমা নেওয়া হয়েছে');

        $this->assertUntouched($cheque);
    }

    public function test_clearing_a_post_dated_cheque_before_its_date_is_refused(): void
    {
        $dueBefore = $this->due();
        $cheque = $this->receive('15000', now()->addDays(5)->toDateString());

        $this->assertRefusedOn('cleared_on',
            fn () => app(ChequeService::class)->clear($cheque, $this->bank->id, now()->toDateString()),
            'আগাম তারিখের চেক তারিখের আগেই পাশ হয়েছে');

        $this->assertUntouched($cheque);
        $this->assertSame(0, bccomp($this->due(), $dueBefore, 2), 'তারিখের আগেই ডিলারের বকেয়া কমেছে।');
    }

    public function test_an_issued_post_dated_cheque_is_not_cleared_before_its_date(): void
    {
        $cheque = $this->issue('9000', now()->addDays(5)->toDateString());
        $entriesBefore = $this->entriesFor($cheque);

        $this->assertRefusedOn('cleared_on',
            fn () => app(ChequeService::class)->clear($cheque, $this->bank->id, now()->toDateString()),
            'দেওয়া আগাম চেক তারিখের আগেই ভাঙানো হয়েছে');

        $this->assertSame(Cheque::PENDING, $cheque->fresh()->status);
        $this->assertSame($entriesBefore, $this->entriesFor($cheque));
        $this->assertSame(0, LedgerEntry::query()->where('account_id', $this->bank->id)->count());
    }

    public function test_the_message_names_the_cheque_date(): void
    {
        $date = now()->addDays(5);
        $cheque = $this->receive('15000', $date->toDateString());

        try {
            app(ChequeService::class)->clear($cheque, $this->bank->id, now()->toDateString());
        } catch (ValidationException $e) {
            $this->assertStringContainsString(DateFormat::format($date->toDateString()), $e->errors()['cleared_on'][0] ?? '',
                'বার্তায় চেকের তারিখটা নেই — কবে জমা দেওয়া যাবে, মানুষটা জানবে কী করে?');

            return;
        }

        $this->fail('আগাম তারিখের চেক তারিখের আগেই পাশ হয়েছে।');
    }

    public function test_on_its_own_date_the_cheque_deposits_and_clears(): void
    {
        /*
         * ⓘ সীমার দিন — ঠিক চেকের তারিখে চলে। তারিখটা একটা সময় সহ পাঠানো,
         * যাতে কেবল দিন মেলানো হচ্ছে তা-ও প্রমাণ হয়।
         */
        $date = now()->addDays(5);
        $cheque = $this->receive('15000', $date->toDateString());

        app(ChequeService::class)->deposit($cheque, $this->bank->id, $date->copy()->startOfDay());
        $cleared = app(ChequeService::class)->clear($cheque->fresh(), $this->bank->id, $date->copy()->setTime(0, 0, 1));

        $this->assertSame(Cheque::CLEARED, $cleared->status);
        $this->assertSame($date->toDateString(), $cleared->deposited_on->toDateString());
        $this->assertSame($date->toDateString(), $cleared->cleared_on->toDateString());
        $this->assertSame(0, bccomp($this->balanceOf($this->bank->id), '15000', 2));
    }

    // ── ৩ · পর্দার দরজা ────────────────────────────────────────────────

    public function test_the_screen_refuses_to_clear_into_a_cash_till(): void
    {
        $till = $this->moneyAccount('1101-TILL', 'Counter till', StandardChart::CASH_IN_HAND, Account::CASH);
        $cheque = $this->receive('12000');

        $this->from(route('accounts.cheque.index'))
            ->post(route('accounts.cheque.clear', $cheque), ['bank_account_id' => $till->id])
            ->assertRedirect(route('accounts.cheque.index'))
            ->assertSessionHasErrors('bank_account_id');

        $this->assertUntouched($cheque);
    }

    public function test_the_screen_refuses_to_deposit_a_post_dated_cheque_early(): void
    {
        $cheque = $this->receive('12000', now()->addDays(5)->toDateString());

        $this->from(route('accounts.cheque.index'))
            ->post(route('accounts.cheque.deposit', $cheque), ['bank_account_id' => $this->bank->id])
            ->assertRedirect(route('accounts.cheque.index'))
            ->assertSessionHasErrors('deposited_on');

        $this->assertUntouched($cheque);
    }

    // ── প্রস্তুতি ────────────────────────────────────────────────────────

    private function moneyAccount(string $code, string $name, string $parent, string $kind): Account
    {
        return Account::query()->create([
            'company_id' => CompanyContext::id(),
            'code' => $code,
            'name_en' => $name,
            'name_bn' => $name,
            'parent_id' => StandardChart::find($parent)->id,
            'type' => Account::ASSET,
            'nature' => Account::DEBIT,
            'money_kind' => $kind,
            'is_active' => true,
            'status' => DocumentStatus::CONFIRMED,
        ]);
    }

    private function receive(string $amount, ?string $chequeDate = null): Cheque
    {
        return app(ChequeService::class)->create([
            'direction' => Cheque::RECEIVED,
            'cheque_no' => 'W'.random_int(100000, 999999),
            'bank_name' => 'Sonali Bank',
            'cheque_date' => $chequeDate ?? now()->toDateString(),
            'amount' => $amount,
            'party_type' => 'customer',
            'party_id' => $this->dealer->id,
        ]);
    }

    private function issue(string $amount, ?string $chequeDate = null): Cheque
    {
        return app(ChequeService::class)->create([
            'direction' => Cheque::ISSUED,
            'cheque_no' => 'I'.random_int(100000, 999999),
            'bank_name' => 'Eastern Bank',
            'cheque_date' => $chequeDate ?? now()->toDateString(),
            'amount' => $amount,
            'party_type' => 'supplier',
            'party_id' => Supplier::query()->firstOrFail()->id,
        ]);
    }

    private function assertRefusedOn(string $field, callable $work, string $case): void
    {
        try {
            $work();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors(), "{$case} — ফেরানো হয়েছে, কিন্তু অন্য ঘরে: "
                .implode(', ', array_keys($e->errors())));

            return;
        }

        $this->fail("⛔ {$case}।");
    }

    /** অবস্থা আগের মতো, আর চেকের নামে খাতায় কিছুই নয়। */
    private function assertUntouched(Cheque $cheque): void
    {
        $fresh = $cheque->fresh();

        $this->assertSame(Cheque::PENDING, $fresh->status, 'ফেরানো কাজের পরেও চেকের অবস্থা বদলে গেছে।');
        $this->assertNull($fresh->deposited_on, 'ফেরানো কাজের পরেও জমার তারিখ বসে গেছে।');
        $this->assertSame(0, $this->entriesFor($cheque), 'ফেরানো কাজের পরেও চেকের নামে খাতায় দাখিলা বসেছে।');
    }

    private function entriesFor(Cheque $cheque): int
    {
        return LedgerEntry::query()
            ->where('source_id', $cheque->id)
            ->where('source_type', 'like', Cheque::STOCK_SOURCE.'%')
            ->count();
    }

    /** @return list<string> */
    private function sourcesOf(Cheque $cheque): array
    {
        return LedgerEntry::query()
            ->where('source_id', $cheque->id)
            ->where('source_type', 'like', Cheque::STOCK_SOURCE.'%')
            ->pluck('source_type')->unique()->values()->all();
    }

    private function due(): string
    {
        return (string) (LedgerEntry::query()
            ->where('party_type', 'customer')->where('party_id', $this->dealer->id)
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as due')
            ->value('due') ?? '0');
    }

    private function balanceOf(int $accountId): string
    {
        return (string) (LedgerEntry::query()
            ->where('account_id', $accountId)
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as bal')
            ->value('bal') ?? '0');
    }
}
