<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Finance\Models\HandLoanAccount;
use App\Modules\MasterData\Models\Person;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ধারের শর্ত খাতায় বসেছিল, পর্দা কিছু বলেনি।
 *
 * ── ⛔ লাইভে যা দেখা গেছে, ২৬ সেপ্টেম্বর ২০২৬ ─────────────────────────
 * হাতধারের ফর্মে টাকা আর খাত বসিয়ে সংরক্ষণ করার পরে সেগুলো
 * *"হারিয়ে যায়"*, আর সুদের হার *"০% হয়ে যায়"*।
 *
 * ── ⭐ কিন্তু ডেটা হারায়নি — মেপে দেখা হয়েছে ────────────────────────
 * [[HandLoanService::open()]] পাঁচটা ঘরই ঠিকঠাক লেখে: `principal`,
 * `opening_repaid`, `money_account_id`, `interest_rate`, `term_months`।
 * ⓘ যাচাইকরণেও পাঁচটাই আছে।
 *
 * ⛔ **সংরক্ষণের পর ব্যবহারকারী `show` পাতায় যান, আর সেখানে ঘরগুলো নেই।**
 * গুনে দেখা: `interest_rate` আর `term_months` গোটা মডিউলের `show`,
 * `index` আর আটটা partial-এ **শূন্যবার**।
 *
 * ⚠️ তাই এটা সেবার বাগ নয়, **পর্দার** বাগ — আর ব্যবহারকারীর চোখে দুইটা
 * হুবহু এক দেখায়। ⓘ এই প্রকল্পের সবচেয়ে চেনা ফাঁদ: কাজটা আছে, জোড়াটা
 * নেই, আর কিছুই ভাঙে না।
 *
 * ── ⭐ দুইটা শূন্য এক নয়, আর এই ফাইলের অর্ধেক কাজ সেটাই পাহারা দেওয়া ──
 * ⓘ `principal` শূন্য মানে **কেউ লেখেনি** (কলামের ডিফল্ট `0.0000`, তাই
 * "ফাঁকা" আর "শূন্য" আলাদা করা যায় না) — তাই সেটা `—` হবে।
 * ⛔ কিন্তু `interest_rate` শূন্য মানে **সুদ নেই**, আর সেটা একটা সত্যিকারের
 * উত্তর ([[HandLoanService]]-এর নিজের টীকায় লেখা)। ⚠️ ওটাকে `—` দেখালে
 * *"সুদ কত জানা নেই"* আর *"সুদ নেই"* এক হয়ে যেত — আর পরের প্রশ্নটা ওঠে
 * ঠিক তখন যখন সম্পর্কটা আর ভালো নেই।
 */
final class TheLoanRememberedItsTermsAndTheScreenDidNotTest extends TestCase
{
    use RefreshDatabase;

    private User $clerk;

    private Person $person;

    private Account $cash;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        /*
         * ⓘ ঘরের নিয়ম: বসানো কোম্পানি, বসানো ব্যবহারকারী, বসানো খাত।
         *
         * ⛔ হাতে বানানোর দুইটা দাম আছে, আমি দুইটাই দিয়েছি:
         * অনুমতি কোম্পানি-ভিত্তিক বলে নতুন কোম্পানিতে পাতা ৬০৩ দেয়,
         * আর `accounts`-এ এমন ঘর আছে (`nature`) যার কোনো ডিফল্ট নেই।
         */
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->clerk = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        /*
         * ⓘ `DemoSeeder` কোনো ব্যক্তির সারি বসায় না, তাই নিজেই বসানো।
         * ⚠️ ঘরগুলো `SHOW COLUMNS` দিয়ে **মেপে** নেওয়া — আজ আমি
         * তিনবার আন্দাজে ঘর বাদ দিয়ে তিনটা রান নষ্ট করেছি।
         */
        $this->person = Person::create([
            'company_id' => CompanyContext::id(),
            'code' => 'PSN-HL-1',
            'name_en' => 'Karim Mia',
        ]);

        $this->cash = Account::query()->postable()->money()->orderBy('code')->firstOrFail();
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    // ── ⭐ আসল দাবিগুলো ───────────────────────────────────────────────

    public function test_the_money_lent_is_on_the_page(): void
    {
        $loan = $this->aLoan(['principal' => '50000']);

        $this->page($loan)->assertSee('50,000.00', escape: false);
    }

    public function test_the_interest_rate_is_on_the_page(): void
    {
        $loan = $this->aLoan(['interest_rate' => '12.5']);

        /*
         * ⓘ `12.5`, `12.5000` নয় — ঘরটার কাস্ট `decimal:4`, তাই সোজা
         * ছাপলে `12.5000%` বেরোত। ⚠️ পাশের আমানতের পর্দা শূন্যগুলো ছেঁটে
         * দেয়, আর এই দাবিটা সেই একই আচরণ চায়।
         */
        $this->page($loan)->assertSee('12.5%', escape: false);
    }

    public function test_the_term_is_on_the_page(): void
    {
        $loan = $this->aLoan(['term_months' => 18]);

        $this->page($loan)->assertSee('18', escape: false);
    }

    public function test_the_account_the_money_came_from_is_named(): void
    {
        $loan = $this->aLoan(['money_account_id' => $this->cash->id]);

        $this->page($loan)->assertSee($this->cash->name(), escape: false);
    }

    // ── ⓘ দুইটা শূন্যের তফাত ──────────────────────────────────────────

    public function test_no_interest_is_shown_as_zero_not_as_unknown(): void
    {
        /*
         * ⭐ পরিচিত মানুষের ধার প্রায়ই সুদবিহীন, আর সেটা একটা **উত্তর**।
         * ⛔ `—` দেখালে পর্দা বলত "জানা নেই", অথচ জানা আছে।
         */
        $loan = $this->aLoan(['interest_rate' => '0']);

        /*
         * ⛔ আগে কেবল `assertSee('0%')` লেখা ছিল, আর মিউটেন্ট
         * **প্রমাণ করেছে সেটা অন্ধ** — ২৭ সেপ্টেম্বর ২০২৬।
         *
         * ⓘ শূন্য হারকে `—` দেখানোর মিউটেন্ট বসানো হয়েছিল, তবু
         * দশটা দাবিই সবুজ থেকেছে — কারণ `0%` হরফদুই পাতার
         * **অন্য কোথাও** আছে।
         *
         * ⭐ তাই লেবেলের সাথে ক্রম মিলিয়ে দেখা হয়, আর সাথে
         * `assertDontSee` দিয়ে বলা হয় হারের ঘরে `—` থাকতে পারবে না
         * — দুইটা একসাথে হলে মিউটেন্টের পালানোর জায়গা নেই।
         */
        $page = $this->page($loan);

        $page->assertSeeInOrder([
            __('finance::field.interest_rate'),
            '0%',
        ], escape: false);

        $page->assertDontSeeText(__('finance::field.interest_rate').' —');
    }

    public function test_an_unrecorded_principal_is_not_shown_as_zero_money(): void
    {
        /*
         * ⛔ কলামের ডিফল্ট `0.0000`, তাই "কেউ লেখেনি" আর "শূন্য টাকা"
         * আলাদা করা যায় না। ⚠️ `0.00` ছাপলে পর্দা একটা **ভুল সত্য** বলত:
         * ধারের অঙ্ক শূন্য, অথচ আসলে অঙ্কটা অজানা।
         */
        $loan = $this->aLoan(['principal' => '0']);

        $this->page($loan)->assertDontSee('0.00</dd>', escape: false);
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function page(HandLoanAccount $loan)
    {
        $page = $this->actingAs($this->clerk)->get('/finance/hand-loans/'.$loan->id);

        $page->assertOk();

        return $page;
    }

    /**
     * @param  array<string, mixed>  $terms
     */
    private function aLoan(array $terms = []): HandLoanAccount
    {
        return HandLoanAccount::create([
            'company_id' => CompanyContext::id(),
            'branch_id' => CompanyContext::branchId(),
            'person_id' => $this->person->id,
            'status' => HandLoanAccount::ACTIVE,
            ...$terms,
        ]);
    }
}
