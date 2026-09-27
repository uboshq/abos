<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Finance\Models\BankFacility;
use App\Modules\Finance\Services\BankFacilityService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * হার লেখা হলো ১২.৫, আর পর্দায় উঠল ১২.৫০০০।
 *
 * ── ⛔ যা মাপা হয়েছে, ২৭ সেপ্টেম্বর ২০২৬ ─────────────────────────────
 * `bank-facility/show.blade.php`-এ সুদের হার সোজা ছাপা হত:
 * `{{ $facility->interest_rate }}%`। ⓘ মডেলে ঘরটার কাস্ট `decimal:4`
 * ([[BankFacility::casts]]), তাই অ্যাট্রিবিউটটা **স্ট্রিং** `"12.5000"` —
 * সংখ্যা নয়। ⚠️ ফল: ব্যবহারকারী `12.5` লিখলেন, পর্দা বলল `12.5000%`,
 * আর সুদবিহীন ঋণে বলল `0.0000%`।
 *
 * ── ⭐ কেন এটা নীরব ছিল ───────────────────────────────────────────────
 * ⛔ সংখ্যাটা **ভুল নয়** — `12.5000` আর `12.5` একই হার। তাই কোনো হিসাব
 * মেলেনি এমন হয়নি, কোনো দাবি লাল হয়নি, কোনো ব্যতিক্রমও ওঠেনি। ⓘ এই
 * প্রকল্পের চেনা ফাঁদ: কাজটা আছে, জোড়াটা নেই, আর কিছুই ভাঙে না।
 * ⚠️ ধরা পড়ে কেবল চোখে — মঞ্জুরিপত্রের পাশে পর্দাটা রাখলে।
 *
 * ── ⭐ শূন্য এখানে একটা উত্তর, ফাঁকা নয় ──────────────────────────────
 * ⓘ মাইগ্রেশনে ঘরটা `decimal('interest_rate', 8, 4)->default(0)` — অর্থাৎ
 * **NOT NULL, ডিফল্ট ০**। তাই *"হার জানা নেই"* বলে কোনো অবস্থা এই
 * খাতায় নেই, আর `—` দেখানো হবে একটা **মিথ্যা অনিশ্চয়তা**।
 * ⛔ সুদবিহীন ঋণ সত্যিই হয় ([[HandLoanService]]-এর টীকায় লেখা), তাই
 * শূন্যের সঠিক চেহারা `0%`।
 *
 * ── ⓘ এই ফাইল যা মাপে না ─────────────────────────────────────────────
 * ⚠️ পাশের ঘর `margin_percent` (লাইন ~২১৯) একই ভাবে সোজা ছাপা হয়,
 * কাস্ট `decimal:2`, তাই ওটা `30.00%` দেখায়। ⛔ সেটা এই ফাইলের বাইরে —
 * ইচ্ছাকৃত, কারণ ওটা CC-র ঘর আর এখানকার সারিগুলো মেয়াদি।
 * ⓘ এখানে কোনো হিসাবও মাপা হয় না — কেবল **ছাপার চেহারা**।
 */
final class TheRateWasTypedAsTwelvePointFiveAndPrintedAsTwelvePointFiveThousandTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $clerk;

    private BankFacilityService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        /*
         * ⓘ ঘরের নিয়ম: বসানো কোম্পানি, বসানো ব্যবহারকারী।
         * ⛔ হাতে কোম্পানি বানালে প্রতিটা পাতা ৪০৩ দেয় — অনুমতি
         * কোম্পানি-ভিত্তিক (`team_foreign_key => company_id`)।
         */
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->clerk = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        /*
         * ⓘ সারিটা সেবার হাত দিয়ে বসে, `BankFacility::create` দিয়ে নয় —
         * ⚠️ `document_no` সেবাই বসায় ([[BankFacilityService::open]]), আর
         * ওটা হাতে বাদ পড়লে ড্রিলের পরিচয়টাই স্রেফ একটা আইডি হত।
         */
        $this->service = app(BankFacilityService::class);
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    // ── ⭐ আসল দাবি — যা লেখা হয়েছে তাই ছাপা হয় ──────────────────────

    /**
     * ⛔ যা মাপা হলো: `13.55` লেখা হার পর্দায় `13.5500%` হয়ে উঠত।
     *
     * ⓘ দুই দিক থেকেই ধরা হচ্ছে — কী দেখা যাবে, আর কী দেখা যাবে **না**।
     * ⚠️ কেবল `assertSee('13.55%')` যথেষ্ট নয়: ওটা একদিন এমন কোনো
     * চেহারাতেও মিলে যেত যেখানে পুরনো লেজটা থেকে গেছে।
     */
    public function test_a_typed_rate_is_printed_the_way_it_was_typed(): void
    {
        $facility = $this->term(rate: '13.55');

        $page = $this->page($facility);

        $page->assertSee('>13.55%</dd>', escape: false);
        $page->assertDontSee('13.5500%', escape: false);
    }

    /**
     * ⭐ শূন্য হার একটা **উত্তর**, আর তার চেহারা `0%`।
     *
     * ⛔ নোঙরটা `>0%</dd>` — খালি `'0%'` খুঁজলে দাবিটা **কখনো লাল হত না**,
     * কারণ ভাঙা পর্দার `0.0000%`-এর শেষেই `0%` বসে আছে। ⚠️ এটাই সেই
     * ফাঁদ যা একটা দাবিকে সবুজ রাখে অথচ সে কিছুই পাহারা দেয় না।
     */
    public function test_no_interest_is_printed_as_a_plain_zero(): void
    {
        $facility = $this->term(rate: '0');

        $page = $this->page($facility);

        $page->assertSee('>0%</dd>', escape: false);
        $page->assertDontSee('0.0000%', escape: false);
    }

    /**
     * ⭐ উল্টো দিকের পাহারা — গুরুত্বপূর্ণ শূন্যটা যেন কাটা না পড়ে।
     *
     * ⛔ আশঙ্কাটা সত্যিকারের: `rtrim('10', '0')` দেয় `'1'` — অর্থাৎ ১০%
     * হার ১% হয়ে যেত, আর সেটা একটা **ভুল সংখ্যা**, স্রেফ কুশ্রী চেহারা নয়।
     *
     * ⓘ কেন তা ঘটে না: কাস্ট `decimal:4` বলে অ্যাট্রিবিউটটা সর্বদা
     * `"10.0000"`, কখনো `"10"` নয় — দশমিক বিন্দুটা **সবসময় থাকে**, তাই
     * প্রথম `rtrim` বিন্দুর ডানের শূন্যগুলোতেই থামে।
     * ⚠️ তবু দাবিটা এখানে রইল: কাস্টটা একদিন সরালে এই সারিটাই লাল হবে,
     * নাহলে ভুল হারটা নীরবে ছাপা হত।
     */
    public function test_a_whole_number_rate_keeps_its_significant_zero(): void
    {
        $facility = $this->term(rate: '10');

        $page = $this->page($facility);

        $page->assertSee('>10%</dd>', escape: false);
        $page->assertDontSee('>1%</dd>', escape: false);
        $page->assertDontSee('10.0000%', escape: false);
    }

    /**
     * ⓘ ভগ্নাংশের শেষ শূন্যটাও যায়, কিন্তু অঙ্কটা থাকে — `12.50` → `12.5`।
     * ⚠️ মালিকের লেখা `12.5`, আর মঞ্জুরিপত্রেও ওটাই লেখা থাকে।
     */
    public function test_a_trailing_zero_in_the_fraction_goes_but_the_digit_stays(): void
    {
        $facility = $this->term(rate: '12.5');

        $page = $this->page($facility);

        $page->assertSee('>12.5%</dd>', escape: false);
        $page->assertDontSee('>12%</dd>', escape: false);
        $page->assertDontSee('12.5000%', escape: false);
    }

    // ── ⭐ একই ভুল, পাশের ঘরে ─────────────────────────────────────────

    /**
     * ⛔ চলতি মূলধনের **মার্জিনও** কাঁচা রূপে ছাপত: `30` লেখা মার্জিন
     * পর্দায় `30.00%`।
     *
     * ⓘ এটা উপরের হারের হুবহু একই ভুল, একই ফাইলে, কিন্তু আলাদা কাস্ট —
     * `margin_percent` হলো `decimal:2`, `interest_rate` হলো `decimal:4`।
     * ⚠️ তাই উপরের চারটা দাবি এই ঘরটাকে পাহারা দিত না, আর দিচ্ছিল না:
     * ঐ দাবিগুলো মেয়াদি ঋণের সারিতে চলে, আর মার্জিনের ঘরটা কেবল
     * চলতি মূলধনের পর্দাতেই আঁকা হয়।
     */
    public function test_the_cash_credit_margin_is_printed_the_way_it_was_typed(): void
    {
        $page = $this->page($this->cc(margin: '30'));

        $page->assertSee('>30%</dd>', escape: false);
        $page->assertDontSee('30.00%', escape: false);
    }

    public function test_a_zero_margin_is_printed_as_a_plain_zero(): void
    {
        /*
         * ⚠️ শূন্য মার্জিন একটা **উত্তর**, ফাঁকা ঘর নয় — অর্থাৎ ব্যাংক
         * পুরো স্টকের বিপরীতেই টাকা দিচ্ছে। ⛔ তাই এখানে `—` নয়, `0%`।
         * ⓘ আর `assertDontSee('0.00%')` ছাড়া দাবিটা অন্ধ হত, কারণ ভাঙা
         * পাতার `0.00%`-এর শেষেও `0%` অক্ষরগুলো আছে।
         */
        $page = $this->page($this->cc(margin: '0'));

        $page->assertSee('>0%</dd>', escape: false);
        $page->assertDontSee('0.00%', escape: false);
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function page(BankFacility $facility): TestResponse
    {
        /*
         * ⓘ সত্যিকারের রুট ধরে — পাতাটা সেবা থেকে `$instalments`,
         * `$outstanding` আর `$settlement` পায়, আর ওগুলো হাতে বানানো
         * ভিউ-রেন্ডারে থাকত না। ⛔ পর্দাটা `finance.bank_facility.view`
         * চায় ([[BankFacilityController::middleware]])।
         */
        $page = $this->actingAs($this->clerk)->get('/finance/bank-facilities/'.$facility->id);

        $page->assertOk();

        return $page;
    }

    /**
     * ⓘ মেয়াদি ঋণ — সুদের হার যেখানে রোজকার প্রশ্ন।
     * ⚠️ `liability_account_id` আর `instalments` দুইটাই বাধ্যতামূলক
     * ([[BankFacilityService::assertKindHasWhatItNeeds]]), তাই আন্দাজ নয়।
     */
    private function term(string $rate): BankFacility
    {
        return $this->service->open([
            'kind' => BankFacility::TERM,
            'bank' => 'Islami Bank Bangladesh PLC',
            'sanctioned_on' => now()->toDateString(),
            'limit_amount' => '5000000.0000',
            'liability_account_id' => $this->accountId(),
            'instalments' => 60,
            'interest_rate' => $rate,
        ]);
    }

    /**
     * ⓘ চলতি মূলধন — মার্জিনের ঘরটা কেবল এই ধরনেই আঁকা হয়।
     * ⚠️ CC-তে দায়ের খাত লাগে **না**, বদলে `money_account_id`, `stock_value`
     * আর `margin_percent` তিনটাই বাধ্যতামূলক
     * ([[BankFacilityService::assertKindHasWhatItNeeds]]) — আন্দাজ নয়, পড়া।
     */
    private function cc(string $margin): BankFacility
    {
        return $this->service->open([
            'kind' => BankFacility::CC,
            'bank' => 'Islami Bank Bangladesh PLC',
            'sanctioned_on' => now()->toDateString(),
            'limit_amount' => '5000000.0000',
            'money_account_id' => $this->moneyAccountId(),
            'stock_value' => '8000000.0000',
            'margin_percent' => $margin,
        ]);
    }

    private function accountId(): int
    {
        return (int) DB::table('accounts')->where('company_id', $this->company->id)->value('id');
    }

    /** ⓘ টাকার হিসাব — ঘরের নিয়ম [[Account]]-এর স্কোপ ধরে, হাতে বাছা নয়। */
    private function moneyAccountId(): int
    {
        return Account::query()->money()->postable()->active()->orderBy('code')->firstOrFail()->id;
    }
}
