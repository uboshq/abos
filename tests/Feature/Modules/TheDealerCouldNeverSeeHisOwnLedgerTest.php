<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Models\DepositClaim;
use App\Modules\Sales\Services\DepositClaimService;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ডিলার নিজের খাতাটাই কোনোদিন দেখতে পেতেন না।
 *
 * ── কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * ডিলার নিজের বকেয়া জানতে ফোন করেন। টাকা জমা দিয়ে আবার ফোন করেন —
 * "স্লিপটা পাঠালাম, দেখে নিয়েন"। ডিপোর কেউ একজন হোয়াটসঅ্যাপে ছবিটা
 * দেখে, খাতায় বসায়, বা ভুলে যায়।
 *
 * ভুলে গেলে টাকাটা খাতায় বসে না, পরের বিলে বকেয়া বেশি দেখায়, আর
 * তর্কটা শুরু হয় দুই সপ্তাহ পরে — যখন কারো হাতে আর স্লিপটা নেই।
 *
 * ── এই ফাইলের সবচেয়ে জরুরি অংশ ──────────────────────────────────────
 * "অন্যের কিছু দেখা যায় না" পরীক্ষাগুলো। বাইরের মানুষকে লগইন দেওয়ার
 * মুহূর্তে ওটাই একমাত্র সত্যিকারের ঝুঁকি: একটা URL-এর সংখ্যা বদলে
 * অন্য ডিলারের খাতা দেখে ফেলা।
 */
class TheDealerCouldNeverSeeHisOwnLedgerTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Customer $karim;

    private Customer $rahim;

    private Account $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        app(StandardChart::class)->install();

        $this->bank = Account::query()->create([
            'company_id' => $this->company->id,
            'code' => '1102-PORTAL',
            'name_en' => 'City Bank',
            'name_bn' => 'সিটি ব্যাংক',
            'parent_id' => StandardChart::find(StandardChart::BANK)->id,
            'type' => Account::ASSET,
            'nature' => Account::DEBIT,
            'is_bank' => true,
        ]);

        $this->karim = $this->dealer('PORTAL-K', 'Karim Dealer');
        $this->rahim = $this->dealer('PORTAL-R', 'Rahim Dealer');
    }

    private function dealer(string $code, string $name): Customer
    {
        $customer = Customer::query()->create([
            'company_id' => $this->company->id,
            'branch_id' => $this->company->defaultBranch()?->id,
            'code' => $code,
            'name_en' => $name,
            'status' => DocumentStatus::CONFIRMED,
            'is_active' => true,
        ]);

        $customer->forceFill([
            'portal_password' => Hash::make('dealer-pass'),
            'portal_enabled' => true,
        ])->save();

        return $customer;
    }

    private function claimFor(Customer $dealer, string $amount = '50000', ?string $ref = 'TRX-1'): DepositClaim
    {
        return app(DepositClaimService::class)->raise($dealer, [
            'claimed_on' => '2026-08-10',
            'amount' => $amount,
            'method' => DepositClaim::BANK,
            'reference' => $ref,
            'bank_account_id' => $this->bank->id,
        ]);
    }

    /* ── লগইন ───────────────────────────────────────────────────── */

    public function test_a_dealer_can_sign_in_with_the_code_from_his_bill(): void
    {
        $this->post(route('sales.portal.login.attempt'), [
            'code' => 'PORTAL-K',
            'password' => 'dealer-pass',
        ])->assertRedirect(route('sales.portal.home'));

        $this->assertAuthenticatedAs($this->karim, 'portal');
    }

    public function test_a_wrong_password_does_not_get_in(): void
    {
        $this->post(route('sales.portal.login.attempt'), [
            'code' => 'PORTAL-K',
            'password' => 'not-it',
        ])->assertSessionHasErrors('code');

        $this->assertGuest('portal');
    }

    /**
     * পোর্টাল বন্ধ থাকলে সঠিক পাসওয়ার্ডেও ঢোকা যায় না।
     *
     * সুইচটাই একমাত্র নিয়ন্ত্রণ: ডিপো যাঁকে দিতে চায় কেবল তিনিই ঢোকেন।
     * না দেখলে পাসওয়ার্ড বসানো প্রতিটা সারিই খোলা দরজা হত।
     */
    public function test_a_dealer_whose_portal_is_off_cannot_get_in(): void
    {
        $this->karim->forceFill(['portal_enabled' => false])->save();

        $this->post(route('sales.portal.login.attempt'), [
            'code' => 'PORTAL-K',
            'password' => 'dealer-pass',
        ])->assertSessionHasErrors('code');

        $this->assertGuest('portal');
    }

    public function test_the_portal_is_closed_to_anyone_not_signed_in(): void
    {
        $this->get(route('sales.portal.home'))->assertRedirect(route('sales.portal.login'));
    }

    /**
     * কর্মীর লগইন পোর্টাল খোলে না।
     *
     * দুইটা আলাদা গার্ড, তাই একজনের সেশন অন্যটায় চলে না — আর সেটাই
     * চাই: কর্মী পোর্টালে ঢুকলে "ইনি কোন ডিলার" প্রশ্নের কোনো উত্তর
     * থাকত না, আর `$this->dealer()` যেকোনো কিছু ফেরত দিতে পারত।
     */
    public function test_a_staff_login_does_not_open_the_dealer_portal(): void
    {
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->get(route('sales.portal.home'))->assertRedirect(route('sales.portal.login'));
    }

    /* ── দেয়ালটা ────────────────────────────────────────────────── */

    /**
     * এক ডিলার আরেকজনের দাবি দেখতে পান না।
     *
     * আইডিটা URL-এ, তাই সংখ্যাটা বদলে দেখার চেষ্টা করাই স্বাভাবিক
     * প্রথম আক্রমণ। মালিকানা হাতে যাচাই না করলে ওটাই কাজ করে যেত।
     */
    public function test_one_dealer_cannot_open_another_dealers_claim(): void
    {
        $rahimsClaim = $this->claimFor($this->rahim, '30000', 'TRX-RAHIM');

        $this->actingAs($this->karim, 'portal')
            ->get(route('sales.portal.claim.show', $rahimsClaim))
            ->assertForbidden();
    }

    public function test_a_dealer_sees_only_his_own_claims_on_his_page(): void
    {
        $this->claimFor($this->karim, '50000', 'TRX-KARIM');
        $this->claimFor($this->rahim, '30000', 'TRX-RAHIM');

        $mine = app(DepositClaimService::class)->forCustomer($this->karim);

        $this->assertCount(1, $mine);
        $this->assertSame('TRX-KARIM', $mine->first()->reference);
    }

    public function test_a_dealer_can_open_his_own_claim(): void
    {
        $claim = $this->claimFor($this->karim, '50000', 'TRX-KARIM');

        $this->actingAs($this->karim, 'portal')
            ->get(route('sales.portal.claim.show', $claim))
            ->assertOk();
    }

    /* ── দাবি তোলা ──────────────────────────────────────────────── */

    /**
     * দাবি তোলা মানে খাতায় টাকা বসা নয়।
     *
     * এটাই পুরো নকশার কেন্দ্র। দাবি সরাসরি আদায় হলে যে কেউ বসে বসে
     * নিজের বকেয়া শূন্য করে ফেলতে পারতেন, আর ধরা পড়ত মাস শেষে —
     * যদি কেউ ব্যাংক মিলকরণটা করত।
     */
    public function test_raising_a_claim_does_not_touch_the_books(): void
    {
        $before = Collection::query()->count();

        $claim = $this->claimFor($this->karim);

        $this->assertTrue($claim->isPending());
        $this->assertNull($claim->collection_id);
        $this->assertSame($before, Collection::query()->count(),
            'দাবি তোলার সাথে সাথেই একটা আদায় বসে গেছে।');
    }

    public function test_a_deposit_cannot_be_dated_in_the_future(): void
    {
        $this->expectException(ValidationException::class);

        app(DepositClaimService::class)->raise($this->karim, [
            'claimed_on' => now()->addDay()->toDateString(),
            'amount' => '1000',
            'method' => DepositClaim::BANK,
        ]);
    }

    /**
     * একই রেফারেন্স দুইবার দাবি করা যায় না।
     *
     * না আটকালে একটাই জমা দুইবার দাবি করা যেত, আর ডিপোর দুইজন দুইটা
     * দেখে দুইবার গ্রহণ করলে বকেয়া দ্বিগুণ কমে যেত।
     */
    public function test_the_same_reference_cannot_be_claimed_twice(): void
    {
        $this->claimFor($this->karim, '50000', 'TRX-SAME');

        $this->expectException(QueryException::class);
        $this->claimFor($this->karim, '50000', 'TRX-SAME');
    }

    /** রেফারেন্সবিহীন নগদ জমা একাধিকবার হতেই পারে। */
    public function test_two_cash_deposits_without_a_reference_are_both_allowed(): void
    {
        $this->claimFor($this->karim, '5000', null);
        $second = $this->claimFor($this->karim, '7000', null);

        $this->assertNotNull($second->id);
        $this->assertSame(2, DepositClaim::query()->where('customer_id', $this->karim->id)->count());
    }

    /* ── ডিপোর সিদ্ধান্ত ────────────────────────────────────────── */

    public function test_accepting_a_claim_puts_the_collection_on_the_books(): void
    {
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $claim = $this->claimFor($this->karim, '50000');
        $before = Collection::query()->count();

        $accepted = app(DepositClaimService::class)->accept($claim, $this->bank->id);

        $this->assertTrue($accepted->isAccepted());
        $this->assertNotNull($accepted->collection_id);
        $this->assertSame($before + 1, Collection::query()->count());
    }

    /**
     * ব্যাংকে যা এসেছে তাই বসে, ডিলার যা বলেছেন তা নয়।
     *
     * ডিলার ৫০,০০০ লিখেছেন, ব্যাংক চার্জ কেটে এসেছে ৪৯,৯৫০ — ওরকম
     * হয়ই। দাবির সারিটা অক্ষত থাকে, তাই তফাতটাও পরে দেখা যায়।
     */
    public function test_the_depot_can_correct_the_amount_before_accepting(): void
    {
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $claim = $this->claimFor($this->karim, '50000');
        $accepted = app(DepositClaimService::class)->accept($claim, $this->bank->id, ['amount' => '49950']);

        $this->assertSame('49950.0000', (string) $accepted->collection->amount);
        $this->assertSame('50000.0000', (string) $accepted->amount,
            'দাবির সারিটাও বদলে গেছে — তফাতটা তাহলে আর দেখা যাবে না।');
    }

    public function test_a_decided_claim_cannot_be_decided_again(): void
    {
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $claim = $this->claimFor($this->karim);
        app(DepositClaimService::class)->accept($claim, $this->bank->id);

        $this->expectException(ValidationException::class);
        app(DepositClaimService::class)->reject($claim->refresh(), 'ব্যাংকে নেই');
    }

    /** কারণ ছাড়া প্রত্যাখ্যান করা যায় না — নাহলে ডিলার আবার ফোন করবেন। */
    public function test_a_rejection_needs_a_reason(): void
    {
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $claim = $this->claimFor($this->karim);

        $this->expectException(ValidationException::class);
        app(DepositClaimService::class)->reject($claim, '   ');
    }

    public function test_a_rejected_claim_never_reaches_the_books(): void
    {
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $claim = $this->claimFor($this->karim);
        $before = Collection::query()->count();

        $rejected = app(DepositClaimService::class)->reject($claim, 'ব্যাংকের কাগজে পাওয়া যায়নি');

        $this->assertNull($rejected->collection_id);
        $this->assertSame($before, Collection::query()->count());
        $this->assertSame('ব্যাংকের কাগজে পাওয়া যায়নি', $rejected->decision_reason);
    }

    /* ── নিজের খতিয়ান ───────────────────────────────────────────── */

    /**
     * খতিয়ানের একটা সারি — সরাসরি, কারণ এখানে প্রশ্নটা **দেখানো**, বসানো নয়।
     *
     * ⓘ ভাউচার দিয়ে বসালে টেস্টটা পোস্টিং-ইঞ্জিনেরও পরীক্ষা হয়ে যেত, আর
     * ওটা অন্য ফাইলের কাজ ([[EveryVoucherBalancesTest]])। এখানে কেবল
     * খতিয়ানের পাতাটা মাপা হচ্ছে।
     */
    private function entry(Customer $dealer, string $on, string $debit, string $credit): LedgerEntry
    {
        return LedgerEntry::query()->create([
            'company_id' => $this->company->id,
            'branch_id' => $this->company->defaultBranch()?->id,

            /*
             * ⚠️ অর্থবছরটা বাধ্যতামূলক, আর ডাটাবেসেই — কলামটার কোনো
             * ডিফল্ট নেই।
             *
             * ⓘ এটা প্রথম রানে ধরা পড়েছে (৩ সেপ্টেম্বর ২০২৬), আর
             * ভালোই হয়েছে: **কোন বছরের সারি** সেটা না জেনে খতিয়ান
             * লেখা যায় না, আর বছর-শেষের কাজগুলো ঠিক ওই কলামটা ধরেই
             * চলে। ডিফল্ট থাকলে ভুল বছরে সারি বসত, নীরবে।
             */
            'financial_year_id' => \App\Models\FinancialYear::query()
                ->where('is_current', true)->value('id'),

            'account_id' => StandardChart::find(StandardChart::RECEIVABLE)->id,

            /*
             * ⚠️ `source_type` ও `source_id` — দুইটাই বাধ্যতামূলক, আর
             * ডাটাবেসেই (nullable নয়, ডিফল্টও নেই)।
             *
             * ⭐ এটা একটা নকশার সিদ্ধান্ত, খুঁতখুঁতে যাচাই নয়: **প্রতিটা
             * খতিয়ান-সারি বলতে বাধ্য সে কোন কাগজ থেকে এল**। ওটাই
             * `LedgerEntry::drill()`-এর ভিত্তি, আর ওটাই এই রিপোর ১ নম্বর
             * নিয়ম — প্রতিটা সংখ্যা তার উৎসে নিয়ে যায়।
             *
             * ডিফল্ট থাকলে উৎসহীন সারি বসত, আর ছয় মাস পর কেউ একটা
             * অঙ্কে ক্লিক করে কোথাও পৌঁছাত না।
             *
             * ⓘ এখানে `collection` বেছে নেওয়া হয়েছে কারণ ডিলারের
             * খতিয়ানে ডেবিট আসে বিল থেকে আর ক্রেডিট আসে আদায় থেকে —
             * টেস্টের জন্য একটাই ধরন যথেষ্ট, আর সারিটা তখনো সৎ থাকে।
             */
            'source_type' => 'collection',
            'source_id' => 1,

            'trx_date' => $on,
            'party_type' => 'customer',
            'party_id' => $dealer->id,
            'debit' => $debit,
            'credit' => $credit,
            'narration' => 'test',
        ]);
    }

    /**
     * ⭐ দুই পথে গোনা এক সংখ্যা — এই ফাইলের সবচেয়ে দামি পাহারা।
     *
     * খতিয়ানের শেষ সারির জের আর `Customer::outstanding()` **দুইটা আলাদা
     * কোয়েরি, আলাদা কোড**। মিলতে বাধ্য, আর না মিললে **একটা মিথ্যা বলছে**
     * — কিন্তু কোনটা তা পর্দা দেখে বোঝার উপায় নেই।
     *
     * ⚠️ ৩ সেপ্টেম্বর ২০২৬-এ ঠিক এই ধরনের একটা গরমিল ধরা পড়েছে: সরাসরি
     * বিক্রয়ের পর্দা বলছিল বকেয়া ২০.১০, খাতা বলছিল ১৭০ — কাগজ-পর্যায়ের
     * ছাড় ও খরচ বিলে যাচ্ছিল না। **এই নিয়মটাই ওটা ধরত।**
     */
    public function test_the_last_balance_matches_the_outstanding_figure(): void
    {
        $this->entry($this->karim, '2026-08-01', '100000', '0');
        $this->entry($this->karim, '2026-08-05', '0', '30000');

        $this->actingAs($this->karim, 'portal')
            ->get(route('sales.portal.ledger'))
            ->assertOk()
            ->assertSee(\App\Core\Support\Money::format('70000.0000'));

        $this->assertSame(
            \App\Core\Support\Money::format($this->karim->fresh()->outstanding()),
            \App\Core\Support\Money::format('70000.0000'),
            'খতিয়ানের জের আর বকেয়ার হিসাব আলাদা হয়ে গেছে — একটা মিথ্যা বলছে।',
        );
    }

    /**
     * ⚠️ এক ডিলারের পাতায় অন্যজনের একটাও সারি নেই।
     *
     * বাইরের মানুষ লগইন করেন বলেই এটাই এই মডিউলের একমাত্র সত্যিকারের
     * ঝুঁকি। **সংখ্যা মেলানো যথেষ্ট নয়** — করিমের জের ঠিক থাকলেও রহিমের
     * একটা সারি পাতায় থাকতে পারে; তাই সারিটাও খোঁজা হয়।
     */
    public function test_one_dealer_never_sees_another_dealers_entries(): void
    {
        $this->entry($this->karim, '2026-08-01', '1000', '0');
        $this->entry($this->rahim, '2026-08-02', '777777', '0');

        $this->actingAs($this->karim, 'portal')
            ->get(route('sales.portal.ledger'))
            ->assertOk()
            ->assertSee(\App\Core\Support\Money::format('1000.0000'))
            ->assertDontSee(\App\Core\Support\Money::format('777777.0000'));
    }

    /**
     * তারিখে ছাঁকলে আগের জের ধরা হয়।
     *
     * ⚠️ না ধরলে জের শূন্য থেকে শুরু হত, আর ডিলার পড়তেন **"আমার কোনো
     * বকেয়া ছিল না"** — যেটা প্রায় সবসময়ই মিথ্যা, আর ঠিক ওই ভুল ধারণা
     * থেকেই ফোনটা আসে।
     */
    public function test_filtering_by_date_still_counts_what_came_before(): void
    {
        $this->entry($this->karim, '2026-07-01', '50000', '0');
        $this->entry($this->karim, '2026-08-10', '20000', '0');

        $this->actingAs($this->karim, 'portal')
            ->get(route('sales.portal.ledger', ['from' => '2026-08-01', 'to' => '2026-08-31']))
            ->assertOk()
            // আগের ৫০,০০০ খোলার জেরে, আর শেষ জের ৭০,০০০
            ->assertSee(\App\Core\Support\Money::format('50000.0000'))
            ->assertSee(\App\Core\Support\Money::format('70000.0000'));
    }

    /**
     * ⚠️ ক্রেডিট-সীমার সুইচ বন্ধ থাকলে পর্দায় সীমার কথাই নেই।
     *
     * "০" দেখালে ডিলার পড়তেন তাঁর সীমা শেষ, তারপর ফোন করতেন — অর্থাৎ
     * এই পোর্টালের গোটা উদ্দেশ্যের উল্টো।
     */
    public function test_the_credit_limit_is_hidden_when_the_company_does_not_use_it(): void
    {
        app(\App\Core\Services\SettingsService::class)
            ->set('customer.credit_limit_enabled', false);

        $this->actingAs($this->karim, 'portal')
            ->get(route('sales.portal.ledger'))
            ->assertOk()
            ->assertDontSee(__('sales::portal.credit_limit'));
    }

    /** সীমা ০ মানে "শেষ" নয় — "নগদ/অগ্রিম"। */
    public function test_a_zero_limit_reads_as_cash_only_not_as_zero(): void
    {
        app(\App\Core\Services\SettingsService::class)
            ->set('customer.credit_limit_enabled', true);

        $this->actingAs($this->karim, 'portal')
            ->get(route('sales.portal.ledger'))
            ->assertOk()
            ->assertSee(__('sales::portal.cash_only'));
    }

    /** লগইন ছাড়া খতিয়ান খোলা যায় না। */
    public function test_the_ledger_is_closed_to_anyone_not_signed_in(): void
    {
        $this->get(route('sales.portal.ledger'))->assertRedirect(route('sales.portal.login'));
    }
}
