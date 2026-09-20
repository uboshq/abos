<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Finance\Models\BankFacility;
use App\Modules\Finance\Services\BankFacilityService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ব্যাংক সীমা দিল, কিন্তু আজ কত তোলা যাবে তা ঠিক করল স্টক।
 *
 * ── ⛔ কেন এই মডিউলটা লাগল, ১৬ সেপ্টেম্বর ২০২৬ ───────────────────────
 * অর্থের পাঁচটা খাতা গুনে দেখা গেল চারটার কোড আছে — পুঁজি, রাখা টাকা,
 * ধার, ভাড়া। ⚠️ **ব্যাংক ঋণের একটাও মডেল ছিল না**, অথচ ডিপো ব্যবসায়
 * ঐটাই সবচেয়ে বড় দায়।
 *
 * ── ⭐ আর এই ফাইলের সবচেয়ে জরুরি দাবিটা ড্রয়িং পাওয়ারের ─────────────
 * দোকানদার প্রায়ই ভাবেন **মঞ্জুরিকৃত সীমাটাই তাঁর টাকা**। ⛔ তা নয়:
 * ব্যাংক প্রতি মাসে স্টক স্টেটমেন্ট নেয়, মার্জিন বাদ দেয়, আর যা থাকে
 * ততটাই তোলা যায়।
 *
 * ⚠️ মাসের শেষে স্টক কমে গেলে ড্রয়িং পাওয়ার নেমে যায়, আর হঠাৎ একটা
 * চেক ফেরত আসে — সাধারণত সরবরাহকারীর সামনে। ⓘ সংখ্যাটা কোথাও না
 * দেখালে ঐ ধাক্কাটা প্রতিবারই অপ্রত্যাশিত।
 */
final class TheBankGaveALimitAndTheStockDecidedTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private BankFacilityService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($user);

        $this->service = app(BankFacilityService::class);
    }

    /**
     * ⭐ স্টক সীমার চেয়ে কম হলে **স্টকই আজকের সীমা**।
     *
     * ⓘ ৫০ লাখ মঞ্জুরি, কিন্তু স্টক ৭২ লাখ আর মার্জিন ৩০% →
     * ড্রয়িং পাওয়ার ৫০.৪ লাখ, যা সীমার চেয়ে **বেশি** — তাই সীমাই জেতে।
     * নিচের দ্বিতীয় দাবিতে উল্টোটা।
     */
    public function test_the_sanctioned_limit_caps_the_drawing_power(): void
    {
        $cc = $this->cc(stock: '7200000.0000', margin: '30.00', limit: '5000000.0000');

        $this->assertSame('5000000.0000', $cc->drawingPower(), 'সীমার বেশি তোলা যাচ্ছে — ব্যাংক কখনো দেবে না।');
    }

    /**
     * ⛔ স্টক কমলে সীমাও কমে — আর এটাই ব্যবসায়ীকে অবাক করে।
     *
     * ⓘ স্টক ৪০ লাখে নামলে মার্জিন বাদে থাকে ২৮ লাখ। ⚠️ মঞ্জুরি ৫০
     * লাখই আছে, কিন্তু আজ তোলা যাবে ২৮ লাখ — **২২ লাখ কম**।
     */
    public function test_when_the_stock_falls_the_limit_falls_with_it(): void
    {
        $cc = $this->cc(stock: '4000000.0000', margin: '30.00', limit: '5000000.0000');

        $this->assertSame('2800000.0000', $cc->drawingPower(), 'স্টক কমার পরেও পুরনো সীমা দেখাচ্ছে।');
    }

    /**
     * বাকি চার ধরনে প্রশ্নটাই ওঠে না।
     *
     * ⚠️ `null` ফেরানো হয়, শূন্য নয় — *"তোলা যাবে না"* আর *"প্রশ্নটাই
     * অপ্রাসঙ্গিক"* এক জিনিস নয়। ⓘ শূন্য দেখালে মেয়াদি ঋণের পাতায়
     * কেউ ভাবতেন সীমা ফুরিয়ে গেছে।
     */
    public function test_drawing_power_is_meaningless_outside_a_cash_credit(): void
    {
        $this->assertNull($this->term()->drawingPower());
    }

    /**
     * ⛔ CC-র দেনা এখানে রাখা হয় না — ওটা ব্যাংক হিসাবের ব্যালান্স।
     *
     * ⭐ এই দাবিটা একটা **কলাম না থাকার** দাবি, আর সেটাই আসল সিদ্ধান্ত:
     * "আজ কত তোলা" কলাম রাখলে ওটা খতিয়ানের দ্বিতীয় কপি হত, আর দুই কপি
     * একদিন আলাদা হয়ই — সাধারণত যেদিন কিছু বাতিল হয়।
     */
    public function test_the_facility_never_keeps_a_second_copy_of_the_balance(): void
    {
        foreach (['drawn_amount', 'outstanding', 'balance', 'paid_amount'] as $forbidden) {
            $this->assertFalse(
                Schema::hasColumn('fin_bank_facilities', $forbidden),
                "`{$forbidden}` কলামটা বসানো হয়েছে — ওটা খতিয়ানের দ্বিতীয় কপি।"
            );
        }
    }

    /**
     * ⭐ কোনটা সত্যিকারের দায়, কোনটা নয়।
     *
     * ⛔ গ্যারান্টি দায় নয় — যতক্ষণ না কেউ ভাঙায়। CC-ও নয়: ওর দেনা
     * ব্যাংক হিসাবের ঋণাত্মক ব্যালান্স, আলাদা খাত নয়।
     *
     * ⚠️ দুইটাকে ঋণ ধরে বসালে ব্যবসাটা নিজের চেয়ে বেশি ঋণগ্রস্ত দেখাত,
     * আর ব্যাংক পরের সুবিধা দিতে দ্বিধা করত।
     */
    public function test_a_guarantee_is_not_debt_and_neither_is_a_cash_credit(): void
    {
        $this->assertFalse($this->cc()->isBalanceSheetDebt(), 'CC স্থিতিপত্রে ঋণ হিসেবে বসছে।');
        $this->assertFalse($this->guarantee()->isBalanceSheetDebt(), 'গ্যারান্টি ঋণ হিসেবে বসছে — ওটা সম্ভাব্য দায়।');
        $this->assertTrue($this->term()->isBalanceSheetDebt(), 'মেয়াদি ঋণ দায় হিসেবে গোনা হচ্ছে না।');
    }

    /**
     * ⛔ ধরনটা যা ছাড়া অর্থহীন, সেটা না দিলে সেবা থামে।
     *
     * ⓘ CC-তে ব্যাংক হিসাব ছাড়া টাকাটা কোথায় চলবে তা বলা যায় না, আর
     * স্টক-মার্জিন ছাড়া আজকের সীমা বের করা যায় না।
     */
    public function test_a_cash_credit_without_its_bank_account_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->open([
            'kind' => BankFacility::CC,
            'bank' => 'IBBL',
            'sanctioned_on' => now()->toDateString(),
            'limit_amount' => '5000000.0000',
            // ⛔ money_account_id · stock_value · margin_percent — তিনটাই নেই
        ]);
    }

    /**
     * ⚠️ গ্যারান্টিতে দায়ের খাত **চাওয়াই হয় না**।
     *
     * ⓘ এই দাবিটা উল্টো দিক থেকে লেখা: ঘরটা না দিয়েও সারিটা তৈরি হয়,
     * কারণ গ্যারান্টি দায় নয়। ⛔ চাওয়া হলে ব্যবহারকারী একটা ঋণের খাত
     * বাছতে বাধ্য হতেন, আর সেটাই ভুলটাকে জন্ম দিত।
     */
    public function test_a_guarantee_needs_no_liability_account(): void
    {
        $bg = $this->service->open([
            'kind' => BankFacility::GUARANTEE,
            'bank' => 'IBBL',
            'sanctioned_on' => now()->toDateString(),
            'limit_amount' => '2000000.0000',
            'margin_percent' => '15.00',
        ]);

        $this->assertNull($bg->liability_account_id);
        $this->assertSame(DocumentStatus::CONFIRMED, $bg->status);
    }

    /**
     * নবায়নের সময় হয়ে এলে তালিকায় আসে।
     *
     * ⛔ এটা না থাকলে যা ঘটে তা নীরব: মঞ্জুরি ফুরিয়ে যায়, ব্যাংক নতুন
     * করে তুলতে দেয় না, আর টের পাওয়া যায় একটা চেক ফেরত এলে।
     */
    public function test_a_facility_about_to_expire_shows_up_in_the_renewal_list(): void
    {
        $soon = $this->cc();
        $soon->forceFill(['renews_on' => now()->addDays(10)->toDateString()])->save();

        $later = $this->term();
        $later->forceFill(['renews_on' => now()->addMonths(8)->toDateString()])->save();

        $due = $this->service->dueForRenewal();

        $this->assertTrue($due->contains('id', $soon->id), 'দশ দিনে ফুরাচ্ছে এমন সুবিধা তালিকায় নেই।');
        $this->assertFalse($due->contains('id', $later->id), 'আট মাস বাকি এমন সুবিধাও তালিকায় এসেছে।');
        $this->assertTrue($soon->fresh()->renewalIsNear());
    }

    /* ── নমুনা ──────────────────────────────────────────────────── */

    private function accountId(): int
    {
        return (int) DB::table('accounts')->where('company_id', $this->company->id)->value('id');
    }

    private function cc(string $stock = '7200000.0000', string $margin = '30.00', string $limit = '5000000.0000'): BankFacility
    {
        return $this->service->open([
            'kind' => BankFacility::CC,
            'bank' => 'Islami Bank Bangladesh PLC',
            'sanctioned_on' => now()->toDateString(),
            'limit_amount' => $limit,
            'money_account_id' => $this->accountId(),
            'stock_value' => $stock,
            'margin_percent' => $margin,
        ]);
    }

    private function term(): BankFacility
    {
        return $this->service->open([
            'kind' => BankFacility::TERM,
            'bank' => 'Islami Bank Bangladesh PLC',
            'sanctioned_on' => now()->toDateString(),
            'limit_amount' => '5000000.0000',
            'liability_account_id' => $this->accountId(),
            'instalments' => 60,
        ]);
    }

    private function guarantee(): BankFacility
    {
        return $this->service->open([
            'kind' => BankFacility::GUARANTEE,
            'bank' => 'Islami Bank Bangladesh PLC',
            'sanctioned_on' => now()->toDateString(),
            'limit_amount' => '2000000.0000',
            'margin_percent' => '15.00',
        ]);
    }

    /**
     * ⛔ পর্দাটা সত্যিই খোলে, আর ফর্মটা সত্যিই কাজ করে — শেষ কড়ি।
     *
     * ⚠️ এটাই সেই দাবি যা আজ বারবার লাগত: মডেল আছে, সেবা আছে, অনুমতি
     * আছে — অথচ রুট, মেনু বা ভাষার ঘরের একটা বাদ পড়লে পাতাটা খোলেই না।
     * ⓘ আর মডেলের পরীক্ষা তখনও সবুজ থাকত।
     */
    public function test_the_screen_opens_and_the_form_creates_a_facility(): void
    {
        $this->get(route('finance.bank_facility.create'))
            ->assertOk()
            ->assertSee('name="kind"', false)
            ->assertSee('name="stock_value"', false);

        /*
         * ⭐ ব্যাংকটা এখন তালিকা থেকে, হাতে লেখা নাম থেকে নয় (২০ সেপ্টেম্বর
         * ২০২৬)। ⓘ `institution_new` হলো ফর্মের ইনলাইন "+" — তালিকায়
         * ব্যাংকটা না থাকলে ওখানেই খোলা যায়, আর দোকানে সেটাই আসল পথ।
         *
         * ⚠️ আগে এখানে `'bank' => '…'` ছিল, আর ফর্মের নিয়ম কড়া হওয়ার পর
         * পরীক্ষাটা লাল হয়। ⛔ নিয়ম শিথিল না করে পরীক্ষাটাই শোধরানো হলো:
         * "কার কাছে সীমা" প্রশ্নের উত্তর ছাড়া সীমাটা কেবল একটা সংখ্যা।
         *
         * ⓘ সেবার স্তরে (`$this->service->open()`) `bank` লেখাটা এখনো
         * চলে — কড়াকড়িটা ফর্মের, কারণ ভুলটা হয় ফর্মেই।
         */
        $this->post(route('finance.bank_facility.store'), [
            'kind' => BankFacility::CC,
            'institution_new' => 'Islami Bank Bangladesh PLC',
            'sanctioned_on' => now()->toDateString(),
            'limit_amount' => '5000000',
            'money_account_id' => $this->accountId(),
            'stock_value' => '7200000',
            'margin_percent' => '30',
        ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $facility = BankFacility::query()->latest('id')->firstOrFail();

        $this->assertSame(BankFacility::CC, $facility->kind);
        $this->assertSame('5000000.0000', $facility->drawingPower());

        $this->get(route('finance.bank_facility.show', $facility))->assertOk();
    }
}
