<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\MasterData;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\MasterData\Models\PaymentMethod;
use App\Modules\MasterData\Services\MethodFitsAccount;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Models\DepositClaim;
use App\Modules\Sales\Services\DepositClaimService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * পেমেন্ট-পদ্ধতির ধরন আর তার টাকার খাতের ধরন এক — নইলে টাকা ভুল বাক্সে।
 *
 * ── ⛔ কী ভাঙা ছিল (২৭ সেপ্টেম্বর ২০২৬) ────────────────────────────────
 * "নগদ" ধরনের পদ্ধতিকে বিকাশের খাতে বাঁধা যেত, আর কাউন্টারের ভাউচারে
 * `instrument`-এ পদ্ধতির **কোড** বসে বলে ভাউচারের মাধ্যম-পাহারা ওই পথে
 * কখনো চলত না। ⚠️ গ্রাহকের জমার দাবি মঞ্জুরেও খাতটা কেবল
 * `exists:accounts,id` দেখত — "বিকাশে দিয়েছি" দাবি নগদের বাক্সে, আর
 * বন্ধ করা ব্যাংক হিসাবেও টাকা বসত।
 *
 * ── ⭐ এই ফাইলে যা এখনই প্রমাণযোগ্য ────────────────────────────────────
 *   ১. নিয়মটা নিজে ([[MethodFitsAccount]]) — প্রতিটা বিপজ্জনক জোড়া খাওয়ানো
 *   ২. দাবি মঞ্জুরের আসল HTTP দরজা ([[DepositClaimController::accept]])
 *
 * ⓘ সেটিংসে পদ্ধতি সেভ আর কাউন্টারের দাবি আলাদা ফাইলে
 * (`APaymentMethodFitsItsMoneyAccountAfterThePatchTest`) — ঐ দুই ফাইল
 * সমন্বয়কের খোলা কাজের ভিতরে, তাই পরিবর্তনটা প্যাচ-স্ক্রিপ্টে রাখা।
 */
final class APaymentMethodFitsItsMoneyAccountTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Account $cash;

    private Account $bank;

    private Account $bkash;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        app(StandardChart::class)->install();

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        $this->cash = $this->moneyAccount('1101-FIT', StandardChart::CASH_IN_HAND, Account::CASH);
        $this->bank = $this->moneyAccount('1102-FIT', StandardChart::BANK, Account::BANK);
        $this->bkash = $this->moneyAccount('1105-FIT', StandardChart::MOBILE_MONEY, Account::MFS);
    }

    // ── ১ · নিয়মটা নিজে ─────────────────────────────────────────────────

    /** ⛔ লাইভের ঘটনাটাই: "নগদ" পদ্ধতি, খাত বিকাশ। */
    public function test_a_cash_method_on_a_bkash_account_is_refused(): void
    {
        $this->assertRefused($this->method('cash'), $this->bkash);
    }

    /** ⛔ উল্টোটাও: বিকাশের টাকা ড্রয়ারের খাতে — ড্রয়ারে বাড়তি দেখাত। */
    public function test_an_mfs_method_on_a_cash_account_is_refused(): void
    {
        $this->assertRefused($this->method('mfs'), $this->cash);
    }

    /** ⛔ ব্যাংক ট্রান্সফার নগদের খাতে। */
    public function test_a_bank_method_on_a_cash_account_is_refused(): void
    {
        $this->assertRefused($this->method('bank'), $this->cash);
    }

    /**
     * ⛔ ব্যাংক মানে ব্যাংক — MFS নয় ([[Account::isBank]])।
     *
     * ⚠️ সবচেয়ে সহজে ফসকে যাওয়া জোড়া: দুইটাই "নগদ নয়", তাই "নগদ কি না"
     * দেখা একটা আলসে নিয়ম এটাকে ছেড়ে দিত।
     */
    public function test_a_bank_method_on_a_bkash_account_is_refused(): void
    {
        $this->assertRefused($this->method('bank'), $this->bkash);
    }

    /** ⚠️ বদ্ধ তালিকার বাইরের ধরন নিঃশব্দে ছাড় পায় না। */
    public function test_a_kind_outside_the_closed_list_is_refused(): void
    {
        $this->assertFalse(app(MethodFitsAccount::class)->fits($this->bank, $this->method('card')),
            '⛔ তালিকার বাইরের ধরন ("card") যেকোনো খাতে বসে যাচ্ছে।');
    }

    /** ⭐ মিলে গেলে কিছুই থামে না — তিন জোড়াই। */
    public function test_matching_pairs_pass(): void
    {
        $fit = app(MethodFitsAccount::class);

        foreach ([['cash', $this->cash], ['mfs', $this->bkash], ['bank', $this->bank]] as [$kind, $account]) {
            $this->assertTrue($fit->fits($account, $this->method($kind)), "{$kind} পদ্ধতি নিজের ধরনের খাতে আটকে গেছে।");
            $fit->assert($account, $this->method($kind));
        }
    }

    /**
     * ⓘ চেক, খালি ধরন আর পদ্ধতি-না-থাকা — তিনটাতেই প্রশ্ন নেই।
     *
     * ⚠️ চেক আগে ১১০৪/২১১৫-এ বসে, ব্যাংকে পরে — তাই যেকোনো খাতে। খালি ধরন
     * কোম্পানির নিজের "সাধারণ" সারি (৪ সেপ্টেম্বরের সিদ্ধান্ত)।
     */
    public function test_cheque_empty_kind_and_no_method_are_not_questioned(): void
    {
        $fit = app(MethodFitsAccount::class);

        foreach ([$this->cash, $this->bank, $this->bkash] as $account) {
            $this->assertTrue($fit->fits($account, $this->method('cheque')));
            $this->assertTrue($fit->fits($account, $this->method(null)));
            $this->assertTrue($fit->fits($account, null));
            $fit->assert($account, null);
        }
    }

    /** ⭐ বার্তা পদ্ধতি আর খাত দুইটারই নাম বলে — কাঁচা কী নয়। */
    public function test_the_refusal_names_the_method_and_the_account(): void
    {
        app()->setLocale('bn');

        try {
            app(MethodFitsAccount::class)->assert($this->bkash, $this->method('cash'), 'account_id');
            $this->fail('⛔ নগদ পদ্ধতি বিকাশের খাতে থামেনি।');
        } catch (ValidationException $e) {
            $message = $e->errors()['account_id'][0] ?? '';

            $this->assertStringNotContainsString('method_does_not_fit_account', $message, '⛔ কাঁচা কী দেখাচ্ছে।');
            $this->assertStringContainsString('নগদ-FIT', $message, 'বার্তায় পদ্ধতির নাম নেই।');
            $this->assertStringContainsString('1105-FIT', $message, 'বার্তায় খাতের নাম নেই।');
        }
    }

    // ── ২ · দাবি মঞ্জুরের দরজা ──────────────────────────────────────────

    /**
     * ⛔ খরচের খাতে দাবি মঞ্জুর — বকেয়া মুছত, টাকা কোথাও আসত না।
     */
    public function test_a_claim_cannot_be_accepted_into_an_expense_account(): void
    {
        $claim = $this->pendingClaim(DepositClaim::BANK);
        $expense = Account::query()->postable()->where('type', Account::EXPENSE)->firstOrFail();

        $this->post(route('sales.claim.accept', $claim), ['account_id' => $expense->id])
            ->assertSessionHasErrors('account_id');

        $this->assertNothingPosted($claim);
    }

    /**
     * ⛔ বন্ধ (নিষ্ক্রিয়) ব্যাংক হিসাবে দাবি মঞ্জুর।
     *
     * ⚠️ তালিকার পর্দা কেবল সক্রিয় খাত দেখায়, কিন্তু দরজাটা তা মানত না —
     * পুরনো পাতা বা হাতে বানানো অনুরোধে বন্ধ হিসাবেও টাকা বসত।
     */
    public function test_a_claim_cannot_be_accepted_into_a_closed_bank_account(): void
    {
        $closed = $this->moneyAccount('1102-SHUT', StandardChart::BANK, Account::BANK);
        $closed->forceFill(['is_active' => false])->save();

        $claim = $this->pendingClaim(DepositClaim::BANK);

        $this->post(route('sales.claim.accept', $claim), ['account_id' => $closed->id])
            ->assertSessionHasErrors('account_id');

        $this->assertNothingPosted($claim);
    }

    /** ⛔ "বিকাশে দিয়েছি" দাবি নগদের বাক্সে — ড্রয়ারে এমন টাকা যা কখনো আসেনি। */
    public function test_an_mfs_claim_cannot_be_accepted_into_a_cash_account(): void
    {
        $claim = $this->pendingClaim(DepositClaim::MFS);

        $this->post(route('sales.claim.accept', $claim), ['account_id' => $this->cash->id])
            ->assertSessionHasErrors('account_id');

        $this->assertNothingPosted($claim);
    }

    /** ⛔ "ব্যাংকে দিয়েছি" দাবি বিকাশের খাতে — ব্যাংক মানে ব্যাংক, MFS নয়। */
    public function test_a_bank_claim_cannot_be_accepted_into_an_mfs_account(): void
    {
        $claim = $this->pendingClaim(DepositClaim::BANK);

        $this->post(route('sales.claim.accept', $claim), ['account_id' => $this->bkash->id])
            ->assertSessionHasErrors('account_id');

        $this->assertNothingPosted($claim);
    }

    /** ⭐ পাল্টা দাবি: একই দরজা, মেলা খাত — টাকা বসে। */
    public function test_a_bank_claim_into_a_bank_account_posts(): void
    {
        $claim = $this->pendingClaim(DepositClaim::BANK);

        $this->post(route('sales.claim.accept', $claim), ['account_id' => $this->bank->id])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $claim = $claim->fresh();
        $this->assertSame(DepositClaim::ACCEPTED, $claim->status);
        $this->assertNotNull($claim->collection_id);
        $this->assertSame($this->bank->id, (int) Collection::query()->findOrFail($claim->collection_id)->account_id);
    }

    /** ⭐ পাল্টা দাবি: বিকাশের দাবি বিকাশের খাতে বসে। */
    public function test_an_mfs_claim_into_an_mfs_account_posts(): void
    {
        $claim = $this->pendingClaim(DepositClaim::MFS);

        $this->post(route('sales.claim.accept', $claim), ['account_id' => $this->bkash->id])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame(DepositClaim::ACCEPTED, $claim->fresh()->status);
    }

    /**
     * ⓘ "নগদ" দাবি ব্যাংকে বসতে পারে — ব্যাংকের কাউন্টারে নগদ জমা।
     *
     * ⚠️ ডিলাররা কোম্পানির ব্যাংকের শাখায় নগদ জমা দেন; পোর্টালে সেটা
     * "নগদ", আর টাকা বসে ব্যাংকে। ⛔ এটা থামালে সবচেয়ে সাধারণ দাবিটাই
     * আটকাত (`AClaimAcceptedTwiceTookTheMoneyTwiceTest` ঠিক এভাবেই দাবি তোলে)।
     */
    public function test_a_cash_claim_may_land_in_the_bank(): void
    {
        $claim = $this->pendingClaim(DepositClaim::CASH);

        $this->post(route('sales.claim.accept', $claim), ['account_id' => $this->bank->id])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame(DepositClaim::ACCEPTED, $claim->fresh()->status);
    }

    /** ⛔ কিন্তু "নগদ" দাবি বিকাশে নয় — ওটা নগদ জমার কোনো পথ নয়। */
    public function test_a_cash_claim_cannot_land_in_bkash(): void
    {
        $claim = $this->pendingClaim(DepositClaim::CASH);

        $this->post(route('sales.claim.accept', $claim), ['account_id' => $this->bkash->id])
            ->assertSessionHasErrors('account_id');

        $this->assertNothingPosted($claim);
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function assertRefused(PaymentMethod $method, Account $account): void
    {
        $fit = app(MethodFitsAccount::class);

        $this->assertFalse($fit->fits($account, $method),
            "⛔ {$method->kind} পদ্ধতি {$account->code} খাতে মিলে গেছে বলে ধরা হচ্ছে।");

        try {
            $fit->assert($account, $method, 'deposits.0.account_id');
            $this->fail("⛔ {$method->kind} পদ্ধতি {$account->code} খাতে থামেনি।");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('deposits.0.account_id', $e->errors(), 'ত্রুটিটা চাওয়া ঘরে বসেনি।');
        }
    }

    /** সারিটা সেভ হয় না — নিয়মটা সেভের পাহারা ছাড়াই মাপা হয়। */
    private function method(?string $kind): PaymentMethod
    {
        return new PaymentMethod([
            'company_id' => $this->company->id,
            'code' => 'FIT-'.($kind ?? 'none'),
            'name_en' => 'Cash-FIT',
            'name_bn' => 'নগদ-FIT',
            'kind' => $kind,
        ]);
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

    private function pendingClaim(string $method): DepositClaim
    {
        return app(DepositClaimService::class)->raise(Customer::query()->firstOrFail(), [
            'claimed_on' => now()->subDay()->toDateString(),
            'amount' => '5000',
            'method' => $method,
            'reference' => 'TRX-FIT-'.$method,
            'bank_account_id' => $this->bank->id,
        ]);
    }

    private function assertNothingPosted(DepositClaim $claim): void
    {
        $this->assertSame(DepositClaim::PENDING, $claim->fresh()->status, '⛔ ভুল খাতে দাবিটা মঞ্জুর হয়ে গেছে।');
        $this->assertSame(0, Collection::query()->count(), '⛔ ভুল খাতে আদায়ের সারি বসে গেছে — গ্রাহকের বকেয়া কমেছে।');
    }
}
