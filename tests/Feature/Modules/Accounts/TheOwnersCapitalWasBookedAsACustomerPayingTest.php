<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\MoneyCategory;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\MasterData\Models\Person;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ মালিকের মূলধন গ্রাহকের বাকি আদায় হয়ে বসত।
 *
 * ── কী ঘটেছিল, ১৯ সেপ্টেম্বর ২০২৬ ────────────────────────────────────
 * মালিক ব্যাংকে ২৫,০০,০০০ মূলধন ঢোকালেন। লাইভের খাতায়:
 *
 *   1106 IBBL                  +2,498,500.00   ← টাকা ঠিকই ঢুকেছে
 *   1110 Accounts Receivable   −2,500,280.56   ⛔ ক্রেডিট এখানে
 *   1000 Assets মোট                4,647.86    ← দুইটা কাটাকাটি
 *
 * ── কেন ───────────────────────────────────────────────────────────────
 * মূলধন ঢোকে রসিদ ভাউচার দিয়ে, মালিককে পক্ষ (`person`) করে।
 * [[VoucherRequest::fillAccountFromParty()]] রসিদে পক্ষ থাকলেই অন্য পাশ
 * বসাত ১১১০-এ — **পক্ষের ধরন না দেখে**। ⚠️ আর সেটা টাকার শ্রেণির
 * **আগে** চলত, তাই মালিক "মূলধন" বেছে থাকলেও সেটা নীরবে মুছে যেত।
 *
 * ⭐ তাই এখানে চারটা দাবি — দুইটা ভুলটা ঠেকায়, দুইটা নিশ্চিত করে যে
 * সারাইটা কাউন্টারের রোজকার আদায় ভাঙেনি।
 */
final class TheOwnersCapitalWasBookedAsACustomerPayingTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    private function bank(): Account
    {
        return Account::query()->where('money_kind', Account::BANK)->postable()->active()
            ->orderBy('code')->first()
            ?? Account::query()->where('money_kind', Account::CASH)->postable()->active()->orderBy('code')->firstOrFail();
    }

    private function owner(): Person
    {
        return Person::query()->create([
            'company_id' => $this->company->id,
            'code' => 'OWN1',
            'name_en' => 'The Owner',
            'is_active' => true,
        ]);
    }

    private function capitalCategory(): MoneyCategory
    {
        return MoneyCategory::query()->create([
            'company_id' => $this->company->id,
            'code' => 'CAPITAL',
            'name_en' => 'Capital',
            'context' => MoneyCategory::RECEIPT,
            'account_id' => StandardChart::find(StandardChart::OWNER_CAPITAL)->id,
            'is_active' => true,
        ]);
    }

    /**
     * ভাউচারের ক্রেডিট পাশটা কোন খাতে বসল — কোড ধরে।
     */
    private function creditedCode(): ?string
    {
        $voucher = Voucher::query()->latest('id')->first();

        $line = $voucher?->lines()->where('credit', '>', 0)->first();

        return $line === null ? null : Account::query()->whereKey($line->account_id)->value('code');
    }

    private function receipt(array $extra)
    {
        return $this->post(route('accounts.voucher.store', ['type' => Voucher::RECEIPT]), [
            // ⚠️ ঠিকানার ধরনটা মেনুর জন্য; যাচাই পড়ে ফর্মের ঘরটা
            'type' => Voucher::RECEIPT,
            'trx_date' => now()->toDateString(),
            'amount' => '2500000',
            'to_account_id' => $this->bank()->id,
            'instrument_no' => 'CAP-1',
            ...$extra,
        ]);
    }

    /**
     * ⛔ মালিক মূলধন দিলেন, "মূলধন" শ্রেণি বাছলেন — ক্রেডিট যায় মূলধনে।
     *
     * ⚠️ আগে এটাই ভাঙা ছিল: পক্ষ আগে বসে ১১১০ দিত, আর শ্রেণিটা নীরবে বাদ।
     */
    public function test_capital_from_the_owner_goes_to_capital_not_receivable(): void
    {
        $owner = $this->owner();

        $this->receipt([
            'party_type' => 'person',
            'party_id' => $owner->id,
            'money_category_id' => $this->capitalCategory()->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame(StandardChart::OWNER_CAPITAL, $this->creditedCode(),
            'মালিকের মূলধন মূলধন খাতে যায়নি।');
    }

    /**
     * ⛔ মালিকের টাকা, কিন্তু কোন খাত বলা নেই — আন্দাজ নয়, থামা।
     *
     * ⓘ মালিকের টাকা মূলধন হতে পারে, ঋণও হতে পারে; একটা খাত দিয়ে বলা
     * যায় না। ⚠️ তাই প্রাপ্যে চুপচাপ বসার বদলে যাচাই বলে "খাতটা বাছুন",
     * আর কোনো ভাউচারই তৈরি হয় না।
     */
    public function test_money_from_a_person_with_no_account_is_refused_not_guessed(): void
    {
        $owner = $this->owner();
        $before = Voucher::query()->count();

        $this->receipt([
            'party_type' => 'person',
            'party_id' => $owner->id,
        ])->assertSessionHasErrors('from_account_id');

        $this->assertSame($before, Voucher::query()->count(), 'খাত ছাড়াই একটা ভাউচার বসে গেছে।');
    }

    /**
     * ⭐ কাউন্টারের রোজকার আদায় — গ্রাহক, শ্রেণি ছাড়া — আগের মতোই প্রাপ্যে।
     *
     * ⚠️ এই দাবিটা না থাকলে উপরের সারাই "পক্ষ থেকে খাত ভরাই বন্ধ করে দিলে"
     * সবুজ থাকত, আর প্রতিটা সাধারণ আদায় "খাতটা বাছুন" বলে আটকে যেত।
     */
    public function test_a_customer_paying_at_the_counter_still_reaches_receivable(): void
    {
        $customer = Customer::query()->orderBy('id')->firstOrFail();

        $this->receipt([
            'amount' => '1000',
            'party_type' => 'customer',
            'party_id' => $customer->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame(StandardChart::RECEIVABLE, $this->creditedCode(),
            'গ্রাহকের আদায় আর প্রাপ্যে যাচ্ছে না — রোজকার পথটা ভেঙেছে।');
    }

    /**
     * ⭐ শ্রেণি বাছলে সেটাই জেতে — গ্রাহকের বেলাতেও।
     *
     * ⓘ ব্যবহারকারীর স্পষ্ট বাছাই কোনো ডিফল্টের কাছে হারে না।
     */
    public function test_a_chosen_category_beats_the_party_default(): void
    {
        $customer = Customer::query()->orderBy('id')->firstOrFail();

        $this->receipt([
            'amount' => '1000',
            'party_type' => 'customer',
            'party_id' => $customer->id,
            'money_category_id' => $this->capitalCategory()->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame(StandardChart::OWNER_CAPITAL, $this->creditedCode(),
            'বাছা শ্রেণিটা পক্ষের ডিফল্টের কাছে হেরে গেছে।');
    }
}
