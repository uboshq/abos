<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\MoneyCategory;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\AccountService;
use App\Modules\Accounts\Services\AccountsFacts;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * রসিদের পর্দা বারোটা প্রশ্ন করার কথা, জানত ছয়টা।
 *
 * ── মালিকের তালিকা, ১৪ সেপ্টেম্বর ২০২৬ ───────────────────────────────
 * মালিক নিজে লিখে দিয়েছেন আদায় ভাউচারে কোন ঘরগুলো থাকা দরকার। মিলিয়ে
 * দেখা গেছে অর্ধেকই ছিল না: পক্ষের ধরন, শ্রেণি, উপ-শ্রেণি, দাতার
 * ব্যাংক, তাঁর হিসাব নম্বর, আর কাগজের তারিখ।
 *
 * ⭐ এই ফাইলটা ঘরগুলোর **উপস্থিতি** মাপে না — ঘর থাকা সহজ, ঘরটা কাজ
 * করা কঠিন। প্রতিটা পরীক্ষা একটা করে দাবি করে যে ঘরটা **খাতায় কিছু
 * বদলায়**, আর সেটাই আসল প্রশ্ন।
 */
class TheReceiptAskedTwelveQuestionsAndKnewSixTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->user);
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /**
     * টাকা যেখানে জমা হবে — নগদ, ব্যাংক বা MFS।
     *
     * ⚠️ `money_kind` ধরে ছাঁকা হয়, `scopeMoney()` দিয়ে নয় — ওটা তিন
     * ধরনই মেলায়, আর তখন "নগদ" চেয়ে একটা ব্যাংক খাত ফিরত। ঠিক এই
     * ফাঁদে এই রিপো একবার পড়েছে, আর ব্যর্থতাটা তখন একটা **নির্দোষ
     * পথের** দিকে আঙুল তুলেছিল।
     */
    private function moneyAccount(string $kind): Account
    {
        return Account::query()
            ->where('money_kind', $kind)
            ->postable()->active()
            ->orderBy('code')
            ->firstOrFail();
    }

    /**
     * একটা সত্যিকারের ব্যাংক খাত — না থাকলে বানিয়ে নেওয়া হয়।
     *
     * ⛔ নগদে ফেরত যাওয়া যায় না: এই পরীক্ষাটাই চার্জ নিয়ে, আর
     * চার্জ কেবল ব্যাংক বা MFS-এ হয় ([[VoucherService]])। ⚠️ নগদে নেমে
     * গেলে পরীক্ষাটা নিজেই নিজের বিরুদ্ধে যেত — যে জিনিসটা নিষিদ্ধ,
     * সেটা দিয়েই দাবিটা প্রমাণ করতে চাইত।
     *
     * ⓘ ডেমোর ছকে `money_kind = bank` বসানো কোনো খাত নেই, আর
     * সেটাই এই দাবিটাকে লাল রাখত — তাও এমন একটা ভুলে যার সাথে
     * চার্জের কোনো সম্পর্কই নেই।
     */
    private function aBankAccount(): Account
    {
        $found = Account::query()->where('money_kind', Account::BANK)
            ->postable()->active()->orderBy('code')->first();

        if ($found !== null) {
            return $found;
        }

        return app(AccountService::class)->create([
            'code' => StandardChart::BANK.'-01',
            'name_en' => 'Test bank account',
            'parent_id' => Account::query()->where('code', StandardChart::BANK)->value('id'),
        ]);
    }

    private function receivable(): Account
    {
        return StandardChart::find(StandardChart::RECEIVABLE)
            ?? throw new RuntimeException('প্রাপ্য খাত ছকে নেই — সেটআপই ভাঙা।');
    }

    private function customer(): Customer
    {
        return Customer::query()->orderBy('id')->firstOrFail();
    }

    /**
     * একটা শ্রেণি, তার সাথে একটা খাত বাঁধা।
     */
    private function category(string $code, ?int $accountId, ?int $parentId = null): MoneyCategory
    {
        return MoneyCategory::query()->create([
            'company_id' => $this->company->id,
            'code' => $code,
            'name_en' => $code,
            'context' => MoneyCategory::RECEIPT,
            'parent_id' => $parentId,
            'account_id' => $accountId,
            'is_active' => true,
        ]);
    }

    /**
     * একটা আদায় ভাউচার জমা দেওয়া।
     *
     * ⚠️ নামটা `post()` **নয়** ইচ্ছাকৃতভাবে — ওটা [[TestCase::post()]]
     * কে ঢেকে দিত, আর তখন নিচের `$this->post(...)` নিজেকেই ডেকে
     * অসীম চক্রে পড়ত। এই ফাঁদে এই রিপো একবার পড়েছে।
     *
     * @param  array<string, mixed>  $extra
     */
    private function postReceipt(array $extra = []): TestResponse
    {
        return $this->post(
            route('accounts.voucher.store', ['type' => Voucher::RECEIPT]),
            [
                /*
                 * ⚠️ ঠিকানার `{type}` মেনুর জন্য — যাচাই পড়ে **ফর্মের**
                 * ঘরটা ([[VoucherRequest::rules()]]-এ `type` required)।
                 *
                 * ⛔ ঘরটা না পাঠানোয় এই ফাইলের ছয়টা দাবি লাল ছিল, আর
                 * বার্তাটা বলত "ধরন দিতেই হবে" — অর্থাৎ অনুরোধটা
                 * যাচাইয়েই আটকে যেত, আর পরীক্ষাগুলো যা মাপতে চেয়েছিল
                 * তার কাছেই পৌঁছাত না। ⓘ সহোদর পরীক্ষাগুলো শুরু থেকেই
                 * দুই জায়গাতেই পাঠায় ([[TheOwnersCapitalWasBookedAsACustomerPayingTest]])।
                 */
                'type' => Voucher::RECEIPT,
                'trx_date' => now()->toDateString(),
                'amount' => '1000',
                'from_account_id' => $this->receivable()->id,
                'to_account_id' => $this->moneyAccount(Account::CASH)->id,
                ...$extra,
            ],
        );
    }

    // ── দাবিগুলো ──────────────────────────────────────────────────────

    /**
     * ⛔ সেটআপের দাবি, আর এটা **সবার আগে**।
     *
     * নিচের প্রতিটা পরীক্ষা ধরে নেয় ছক বসানো আছে আর টাকার খাত আছে।
     * না থাকলে `firstOrFail()` ছুঁড়ত আর ব্যর্থতাটা এমন এক জায়গায়
     * আঙুল তুলত যেখানে কোনো দোষ নেই। তাই অনুমানটা আগে যাচাই।
     */
    public function test_the_ground_this_file_stands_on_is_really_there(): void
    {
        $this->assertNotNull(StandardChart::find(StandardChart::RECEIVABLE));
        $this->assertNotNull($this->moneyAccount(Account::CASH));
        $this->assertGreaterThan(0, Customer::query()->count(),
            'কোনো গ্রাহক নেই — পক্ষ ও বকেয়ার দাবিগুলো তখন কিছুই মাপে না।');
    }

    /**
     * ⭐ মালিকের ছয়টা নতুন ঘর খাতায় সত্যিই পৌঁছায়।
     *
     * ── কেন একটাই পরীক্ষা, ছয়টা নয় ──────────────────────────────────
     * ছয়টা আলাদা পরীক্ষা লিখলে ছয়বার একই ভাউচার বানাতে হত আর ছয়গুণ
     * সময় যেত, অথচ দাবিটা একটাই: **অনুরোধ থেকে কলামে**।
     *
     * ⛔ আর এই পথটাই একবার নীরবে ভেঙেছিল — [[VoucherService::create()]]
     * প্রতিটা ঘর হাতে লেখে, তাই তালিকায় নাম না থাকলে ঘরটা যাচাইয়ে
     * পাশ করেও কলামে পৌঁছাত না।
     */
    public function test_every_new_field_reaches_the_ledger(): void
    {
        $category = $this->category('RC-IN', $this->receivable()->id);
        $sub = $this->category('RC-IN-DLR', null, (int) $category->getKey());
        $customer = $this->customer();

        $this->postReceipt([
            'ref_date' => now()->subDays(5)->toDateString(),
            'party_type' => 'customer',
            'party_id' => $customer->id,
            'money_category_id' => $category->getKey(),
            'money_subcategory_id' => $sub->getKey(),
            'from_bank' => 'Sonali Bank, Feni',
            'from_account_no' => '0102-3344-5566',
        ])->assertRedirect();

        $voucher = Voucher::query()->latest('id')->firstOrFail();

        $this->assertSame(now()->subDays(5)->toDateString(), $voucher->ref_date?->toDateString());
        $this->assertSame('customer', $voucher->party_type);
        $this->assertSame((int) $customer->id, (int) $voucher->party_id);
        $this->assertSame((int) $category->getKey(), (int) $voucher->money_category_id);
        $this->assertSame((int) $sub->getKey(), (int) $voucher->money_subcategory_id);
        $this->assertSame('Sonali Bank, Feni', $voucher->from_bank);
        $this->assertSame('0102-3344-5566', $voucher->from_account_no);
    }

    /**
     * ⭐ শ্রেণি বাছলেই খাতটা বসে — ব্যবহারকারী খাত না পাঠালেও।
     *
     * এটাই মালিকের সিদ্ধান্তের পুরো বিন্দু (১৪ সেপ্টেম্বর ২০২৬): *শ্রেণিই
     * খাত ঠিক করবে*। কাউন্টারের লোক হিসাবের ছক পড়েন না।
     *
     * ⚠️ দাবিটা **সার্ভারে**, ব্রাউজারে নয় — এখানে কোনো JS চলছে না, তবু
     * খাতটা বসতে হবে। নাহলে নিয়মটা কেবল একটা সুবিধা হত, পাহারা নয়।
     */
    public function test_the_category_puts_the_money_in_its_account_without_the_browser(): void
    {
        $category = $this->category('RC-RENT', $this->receivable()->id);

        $this->postReceipt([
            'from_account_id' => '',
            'money_category_id' => $category->getKey(),
        ])->assertRedirect();

        $voucher = Voucher::query()->latest('id')->firstOrFail();

        $this->assertTrue(
            $voucher->lines->contains(
                fn ($line) => (int) $line->account_id === (int) $this->receivable()->id
                    && bccomp((string) $line->credit, '0', 4) > 0,
            ),
            'শ্রেণির খাতে ক্রেডিট সারিটা নেই — অর্থাৎ খাতটা শ্রেণি থেকে বসেনি।',
        );
    }

    /**
     * উপ-শ্রেণির নিজের খাত না থাকলে মায়ের খাতেই বসে।
     *
     * ⓘ বাস্তবে বেশিরভাগ উপ-শ্রেণিই খাতহীন থাকবে — দশটা উপ-শ্রেণি একই
     * প্রাপ্য খাতে যায়, আর দশ জায়গায় একই খাত বসাতে বললে নয় জায়গায়
     * ঠিক বসত আর দশম জায়গায় একদিন কেউ অন্যটা বাছত।
     */
    public function test_a_subcategory_without_an_account_falls_back_to_its_parent(): void
    {
        $parent = $this->category('RC-P', $this->receivable()->id);
        $child = $this->category('RC-P-X', null, (int) $parent->getKey());

        $this->assertSame(
            (int) $this->receivable()->id,
            $child->resolvedAccountId(),
            'উপ-শ্রেণিটা মায়ের খাত পায়নি — তাহলে খাতহীন উপ-শ্রেণি বসানোই অর্থহীন।',
        );
    }

    /**
     * ⭐ চার্জ কাটলে তিনটা সারি, আর **মূলধন পুরোটাই থাকে**।
     *
     * মালিকের নিয়ম: যা পাঠানো হলো তাই, যা ঢুকল তা নয়। ৮,০০০ পাঠালেন,
     * ২০ কাটল → ব্যাংকে ৭,৯৮০ · চার্জে ২০ · উৎসে ৮,০০০।
     *
     * ⛔ এটাই সেই ভুল যা ধরা পড়ত না: মূলধন ৭,৯৮০ লিখলে খাতা মিলত,
     * কেবল বিনিয়োগকারীর অংশ % ভুল হত — আর ওটা সোজা মুনাফা ভাগের হিসাব।
     */
    public function test_a_bank_charge_leaves_the_contribution_whole(): void
    {
        $bank = $this->aBankAccount();

        $this->postReceipt([
            'amount' => '8000',
            'charge_amount' => '20',
            'to_account_id' => $bank->id,
            'instrument' => 'transfer',
            'instrument_no' => 'TRF-CHARGE-1',
        ])->assertRedirect();

        $voucher = Voucher::query()->latest('id')->firstOrFail();
        $lines = $voucher->lines;

        $this->assertCount(3, $lines, 'চার্জ থাকলে তিনটা সারি হওয়ার কথা।');

        $this->assertSame('7980.0000', (string) $lines
            ->firstWhere('account_id', $bank->id)?->debit);

        $charges = StandardChart::find(StandardChart::BANK_CHARGES);
        $this->assertNotNull($charges, 'ব্যাংক চার্জের খাত ছকে নেই।');
        $this->assertSame('20.0000', (string) $lines
            ->firstWhere('account_id', $charges->id)?->debit);

        $this->assertSame('8000.0000', (string) $lines
            ->firstWhere('account_id', $this->receivable()->id)?->credit);
    }

    /**
     * ⛔ নগদে চার্জ হয় না — আর নীরবে ব্যাংক-চার্জের খাতে বসানোর চেয়ে
     * থেমে যাওয়াই ভালো।
     *
     * নাহলে ঐ খাতটায় এমন টাকা জমত যা কোনো ব্যাংক কোনোদিন কাটেনি, আর
     * *"ব্যাংকে বছরে কত গেল"* উত্তরটা ভুল হত।
     */
    public function test_cash_cannot_carry_a_charge(): void
    {
        $this->postReceipt([
            'amount' => '500',
            'charge_amount' => '5',
            'to_account_id' => $this->moneyAccount(Account::CASH)->id,
        ])->assertSessionHasErrors('charge_amount');
    }

    /**
     * ⭐ "Collectable" খতিয়ান থেকে আসে, আর চিহ্নটা দিক বলে।
     *
     * ⚠️ দাবিটা দুইমুখী: আগে শূন্য, তারপর বিক্রয়ের পর ধনাত্মক। কেবল
     * দ্বিতীয়টা দাবি করলে পরীক্ষাটা এমন এক কোডেও সবুজ থাকত যা সবসময়
     * একটা ধ্রুবক ফেরত দেয়।
     */
    public function test_the_collectable_is_counted_from_the_ledger_not_stored(): void
    {
        $customer = $this->customer();
        $facts = app(AccountsFacts::class);

        $before = $facts->dueFrom('customer', (int) $customer->id);

        // বকেয়া বিক্রয় নয়, সরাসরি একটা দাখিলা — দাবিটা খতিয়ান নিয়ে,
        // বিক্রয়ের পথ নিয়ে নয়
        $this->postReceipt([
            'amount' => '1500',
            'party_type' => 'customer',
            'party_id' => $customer->id,
        ])->assertRedirect();

        $after = $facts->dueFrom('customer', (int) $customer->id);

        $this->assertSame(
            bcsub($before, '1500', 4),
            $after,
            'আদায়ের পর গ্রাহকের বকেয়া ঠিক ১৫০০ কমার কথা — খতিয়ান থেকে গোনা হলে।',
        );
    }

    /**
     * অচেনা ধরনের পক্ষ চাইলে সংখ্যা নয়, "জানি না" ফেরে।
     *
     * ⓘ ৪২২ নয়, কারণ এটা একটা সহায়ক ঘর — একটা সহায়ক ঘরের জন্য ফর্ম
     * ভাঙা উচিত নয়। কিন্তু চুপচাপ `0` ও নয়: `known` মিথ্যা হলে পর্দা
     * "—" দেখায়, "০.০০" নয়। ⚠️ পার্থক্যটা বড়: "০ পাওনা" আর "জানি না"
     * এক কথা নয়।
     */
    public function test_an_unknown_party_type_says_it_does_not_know(): void
    {
        $this->getJson(route('accounts.voucher.due', [
            'party_type' => 'martian',
            'party_id' => 7,
        ]))->assertOk()->assertJson(['known' => false]);
    }

    /**
     * ⛔ অন্য কোম্পানির পক্ষের বকেয়া পড়া যায় না।
     *
     * ⚠️ [[App\Core\Services\PartyRegistry::exists()]] ছাড়া ঠিকানাটা
     * একটা নীরব ফাঁস হত: আইডি বাড়িয়ে বাড়িয়ে পাঠালে অন্য কোম্পানির
     * প্রতিটা গ্রাহকের বকেয়া পড়ে ফেলা যেত।
     */
    public function test_a_party_from_another_company_is_not_readable(): void
    {
        /*
         * ⭐ অন্য কোম্পানির গ্রাহকটা পরীক্ষা নিজেই বানায় — ২১ সেপ্টেম্বর ২০২৬।
         *
         * ── ⛔ আগে এখানে দুইটা `markTestSkipped` ছিল ─────────────────────
         * ডেমোর দ্বিতীয় কোম্পানিতে (FMART) একজনও গ্রাহক নেই, তাই এই
         * দাবিটা লেখা হওয়ার দিন থেকে **একবারও চলেনি**।
         *
         * ⚠️ আর এটা যেনতেন দাবি নয় — এটাই প্রমাণ করে আইডি এক এক করে
         * বাড়িয়ে অন্য কোম্পানির বকেয়া পড়ে ফেলা যায় না। ⓘ মেপে দেখা
         * গেছে চারটা টাকার সুট "passed" বলছিল, অথচ ৩১টার ৩টা এড়ানো।
         *
         * ⓘ গ্রাহকটা নকল করে বানানো হয়, ঘর ধরে ধরে আন্দাজ করে নয় —
         * তাতে পরের মাইগ্রেশনেই ভাঙত।
         */
        $stranger = $this->customerInAnotherCompany();

        $this->getJson(route('accounts.voucher.due', [
            'party_type' => 'customer',
            'party_id' => $stranger->id,
        ]))->assertOk()->assertJson(['known' => false]);
    }

    /**
     * ⭐ "কীসের বিপরীতে" ঘর দুইটা লিংক থেকে এসে খাতায় বসে।
     *
     * এটাই মালিকের স্থাপত্যগত সিদ্ধান্তের ভিত্তি: অর্থ কেবল লেখে "কে কত
     * দেবেন", টাকা গ্রহণ করে একাই রসিদের পর্দা। ⛔ ঘর দুইটা ছাড়া
     * অর্থের সারিটা **চিরকাল খসড়া থেকে যেত** — এক সত্যের দুইটা উৎস।
     */
    public function test_a_receipt_can_say_which_document_it_settles(): void
    {
        $this->postReceipt([
            'against_type' => 'capital_entry',
            'against_id' => 42,
        ])->assertRedirect();

        $voucher = Voucher::query()->latest('id')->firstOrFail();

        $this->assertSame('capital_entry', $voucher->against_type);
        $this->assertSame(42, (int) $voucher->against_id);
    }

    /**
     * অর্ধেক লেখা "কীসের বিপরীতে" থেমে যায়।
     *
     * ⚠️ ধরন ছাড়া আইডি মানে একটা সংখ্যা যার কোনো অর্থ নেই, আর আইডি
     * ছাড়া ধরন মানে একটা ধরন যার কোনো সারি নেই — দুইটাই খতিয়ানে বসে
     * থাকা আবর্জনা, আর দুইটাই নীরব।
     */
    public function test_half_an_against_reference_is_refused(): void
    {
        $this->postReceipt(['against_id' => 42])->assertSessionHasErrors('against_type');
        $this->postReceipt(['against_type' => 'capital_entry'])->assertSessionHasErrors('against_id');
    }

    /**
     * অন্য একটা কোম্পানির একজন গ্রাহক — না থাকলে বানানো হয়।
     *
     * ⚠️ দ্বিতীয় কোম্পানিটাও না থাকলে সেটা আর এড়িয়ে যাওয়ার কারণ নয়,
     * ব্যর্থতার কারণ: তখন এই ফাইলের কোম্পানি-সংক্রান্ত দাবিগুলোর
     * কোনোটাই মাপা যায় না, আর সেটা জানা দরকার।
     */
    private function customerInAnotherCompany(): Customer
    {
        $other = Company::query()->where('id', '!=', $this->company->id)->first();

        $this->assertNotNull($other, implode(PHP_EOL, [
            'দ্বিতীয় কোনো কোম্পানিই নেই।',
            '',
            'তাহলে কোম্পানির দেয়াল মাপার কোনো উপায় নেই — আর সেটা',
            'এড়িয়ে যাওয়ার কারণ নয়, থামার কারণ।',
        ]));

        $stranger = Customer::query()
            ->withoutGlobalScopes()
            ->where('company_id', $other->id)
            ->first();

        if ($stranger !== null) {
            return $stranger;
        }

        /*
         * ⓘ এই কোম্পানির একজনকে নকল করে অন্য কোম্পানিতে বসানো হয় —
         * তাতে প্রতিটা ঘর বৈধ থাকে, আর কেবল মালিকানা বদলায়।
         */
        $mine = Customer::query()->withoutGlobalScopes()
            ->where('company_id', $this->company->id)->firstOrFail();

        $stranger = $mine->replicate(['public_id']);

        $stranger->forceFill([
            'company_id' => $other->id,
            'branch_id' => $other->defaultBranch()?->id,
            'code' => 'STRANGER-'.$other->id,
            'name_en' => 'Stranger Traders',
            'name_bn' => 'অন্য কোম্পানির ক্রেতা',
        ])->save();

        return $stranger->refresh();
    }
}
