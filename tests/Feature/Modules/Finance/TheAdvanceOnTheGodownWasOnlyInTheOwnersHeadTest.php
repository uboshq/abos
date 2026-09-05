<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\RentalContract;
use App\Modules\Finance\Services\RentalContractService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * মালিকের গোডাউনের চুক্তিটা, হুবহু তাঁর সংখ্যায়।
 *
 * ── কেন তাঁর নিজের সংখ্যাগুলোই ব্যবহার করা হয় ───────────────────────
 * গোল সংখ্যা (১০০ ভাড়া, ১০ সমন্বয়) দিয়ে পরীক্ষা লিখলে অঙ্কটা মিলত,
 * কিন্তু **তিনি চিনতে পারতেন না**। ⓘ ১২,০০,০০০ · ৩০,০০০ · ২০,০০০ ·
 * ১০,০০০ · ২৪ মাস — এগুলো তাঁর মুখের কথা, তাই ফল ভুল হলে তিনি নিজেই
 * এক নজরে ধরবেন।
 */
class TheAdvanceOnTheGodownWasOnlyInTheOwnersHeadTest extends TestCase
{
    use RefreshDatabase;

    private RentalContractService $contracts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->contracts = app(RentalContractService::class);
    }

    /**
     * ক · গোডাউন — ১২ লাখ জামানত, মাসে ২০ হাজার নগদে, ১০ হাজার কাটবে।
     *
     * ⭐ শেষে ৯,৬০,০০০ ফেরত — আর ঐ সংখ্যাটাই মানুষ ভুলে যায়।
     */
    public function test_the_godown_contract_adds_up_the_way_the_owner_said(): void
    {
        $contract = $this->godown();

        $this->assertSame('20000.0000', $contract->monthlyCash());
        $this->assertSame('960000.0000', $contract->refundableAtEnd());

        /* ফেরত আসবে, তাই জামানত (১১৪০) — অগ্রিম (১১৩০) নয়। */
        $this->assertSame(
            StandardChart::SECURITY_DEPOSIT,
            Account::query()->find($contract->account_id)?->code,
            'ফেরতযোগ্য টাকা জামানতের খাতে বসেনি।',
        );

        $rent = Account::query()->find($contract->expense_account_id);
        $deposit = Account::query()->find($contract->account_id);

        /*
         * ⚠️ সংখ্যাগুলো **পার্থক্য ধরে** মাপা হয়, পরম মান ধরে নয়।
         *
         * ⓘ ডেমো ডেটায় ভাড়া বা জামানতের খাতে আগে থেকেই সারি থাকতে
         * পারে; পরম মান ধরলে পরীক্ষাটা সিডার বদলালেই লাল হত, আর কারণটা
         * এই কোডের সাথে কোনো সম্পর্কই রাখত না।
         */
        $rentBefore = $rent->balanceOn();
        $depositBefore = $deposit->balanceOn();

        $this->contracts->adjustMonth($contract, [
            'for_month' => '2026-09-01',
            'money_account_id' => $this->cash()->id,
        ]);

        $contract->refresh();

        $this->assertSame('10000.0000', $contract->adjustedSoFar());
        $this->assertSame('1190000.0000', $contract->depositLeft());

        /*
         * ⭐ সংখ্যাটা খতিয়ান থেকেই পড়া হয়, মডেল থেকে নয় — নাহলে
         * পরীক্ষাটা কেবল নিজের অঙ্ক নিজে মিলিয়ে দেখত।
         */
        $this->assertSame(
            bcadd($rentBefore, '30000', 4),
            $rent->fresh()->balanceOn(),
            'ভাড়া খরচে ৩০ হাজার বসেনি।',
        );

        /*
         * ⚠️ এখানে আমি নিজেই একবার ভুল করেছি: `$depositBefore` **চুক্তি
         * খোলার পরে** পড়া, অর্থাৎ বারো লাখ ইতিমধ্যেই ঢুকে গেছে। তাই
         * প্রত্যাশা `+১১,৯০,০০০` নয়, `−১০,০০০` — কেবল ঐ মাসের কাটাটুকু।
         *
         * ⓘ ভুলটা পরীক্ষার, কোডের নয় — আর ঠিক এই কারণেই সংখ্যা মিলিয়ে
         * দেখা হয়, "চলে তো" দেখে থামা হয় না।
         */
        $this->assertSame(
            bcsub($depositBefore, '10000', 4),
            $deposit->fresh()->balanceOn(),
            'জামানতের খাত থেকে ঠিক ১০ হাজার কমেনি।',
        );
    }

    /**
     * খ · পুরো দুই বছরের ভাড়া অগ্রিম — নগদ শূন্য, শেষে ফেরতও শূন্য।
     *
     * ⭐ আলাদা কোনো কোড নেই: এটা কেবল "সমন্বয় = পুরো ভাড়া"।
     */
    public function test_paying_the_whole_term_up_front_needs_no_second_shape(): void
    {
        $contract = $this->contracts->open([
            'counterparty' => 'বাড়িওয়ালা — পুরো মেয়াদ',
            'subject' => 'দোকান',
            'deposit_amount' => '720000',
            'monthly_rent' => '30000',
            'monthly_adjustment' => '30000',
            'starts_on' => '2026-09-01',
            'term_months' => 24,
            'money_account_id' => $this->cash()->id,
        ]);

        $this->assertSame('0.0000', $contract->monthlyCash());
        $this->assertSame('0.0000', $contract->refundableAtEnd());

        /*
         * ⛔ কিছুই ফেরত আসবে না, তাই এটা জামানত নয় — **অগ্রিম ভাড়া**।
         * এক খাতে ফেললে স্থিতিপত্র সাত লাখ টাকা "ফেরত পাব" বলত।
         */
        $this->assertSame(
            StandardChart::ADVANCE,
            Account::query()->find($contract->account_id)?->code,
            'ফেরত না আসা টাকা জামানতের খাতে বসেছে — স্থিতিপত্র মিথ্যা বলবে।',
        );

        /* নগদ শূন্য, তাই টাকার খাত ছাড়াই মাসটা করা যায়। */
        $this->contracts->adjustMonth($contract, ['for_month' => '2026-09-01']);

        $this->assertSame('690000.0000', $contract->fresh()->depositLeft());
    }

    /**
     * ⛔ জামানতে যা নেই তা কাটা যায় না।
     *
     * ⚠️ এটাই সেই নীরব ভুল: আগে কাটতেই থাকত, আর `১১৪০` — একটা সম্পদ
     * খাত — ঋণাত্মক হয়ে যেত।
     */
    public function test_it_refuses_to_eat_a_deposit_that_is_already_gone(): void
    {
        $contract = $this->contracts->open([
            'counterparty' => 'ছোট জামানত',
            'deposit_amount' => '10000',
            'monthly_rent' => '30000',
            'monthly_adjustment' => '10000',
            'starts_on' => '2026-09-01',
            'term_months' => 1,
        ]);

        $this->contracts->adjustMonth($contract, [
            'for_month' => '2026-09-01',
            'money_account_id' => $this->cash()->id,
        ]);

        $this->assertSame('0.0000', $contract->fresh()->depositLeft());

        $this->expectException(ValidationException::class);

        $this->contracts->adjustMonth($contract->fresh(), [
            'for_month' => '2026-10-01',
            'money_account_id' => $this->cash()->id,
        ]);
    }

    /**
     * ⛔ পুরো মেয়াদের সমন্বয় জামানতের চেয়ে বেশি হতে পারে না।
     *
     * ⓘ ভুলটা চুক্তি খোলার দিনেই ধরা পড়ে, দুই বছর পরে নয় — যেদিন ফেরত
     * চাইতে গিয়ে দেখা যেত টাকা নেই।
     */
    public function test_a_contract_that_would_eat_more_than_the_deposit_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->contracts->open([
            'counterparty' => 'অসম্ভব চুক্তি',
            'deposit_amount' => '100000',
            'monthly_rent' => '30000',
            'monthly_adjustment' => '10000',
            'starts_on' => '2026-09-01',
            'term_months' => 24,
        ]);
    }

    /** ⛔ একই মাস দুইবার করলে ভাড়া দুইবার খরচে বসত। */
    public function test_the_same_month_cannot_be_done_twice(): void
    {
        $contract = $this->godown();

        $this->contracts->adjustMonth($contract, [
            'for_month' => '2026-09-01',
            'money_account_id' => $this->cash()->id,
        ]);

        /*
         * ⚠️ ৫ সেপ্টেম্বর ২০২৬ — এখানে আগে `QueryException` আশা করা
         * হয়েছিল, অর্থাৎ টেবিলের unique চাবিটাই আটকাবে ধরে নেওয়া হয়েছিল।
         *
         * ⛔ সে আটকায়নি: MySQL-এ **NULL কখনো NULL-এর সমান নয়**, আর
         * চাবিতে `deleted_at` আছে। মুছে ফেলা হয়নি এমন প্রতিটা সারির
         * `deleted_at` NULL, তাই প্রতিটাকেই সে আলাদা গণ্য করত — একই মাস
         * দশবার বসানো যেত।
         *
         * ⭐ এখন যাচাইটা কোডে, তাই ব্যতিক্রমও ভিন্ন — আর বার্তাটাও
         * ব্যবহারকারী পড়তে পারেন, যেটা ডাটাবেসের ত্রুটি পারত না।
         *
         * ⓘ তারিখটা ইচ্ছে করে ১৫ তারিখ: মাসের এক তারিখে নামিয়ে আনা
         * হয় কি না, সেটাও এই একটা পরীক্ষাই মেপে নেয়।
         */
        $this->expectException(ValidationException::class);

        $this->contracts->adjustMonth($contract->fresh(), [
            'for_month' => '2026-09-15',
            'money_account_id' => $this->cash()->id,
        ]);
    }

    /** চুক্তি শেষে বাকি জামানতটা ফেরত আসে, আর কেউ গুনে দেয় না। */
    public function test_closing_returns_what_is_left_without_anyone_counting(): void
    {
        $contract = $this->godown();

        $this->contracts->adjustMonth($contract, [
            'for_month' => '2026-09-01',
            'money_account_id' => $this->cash()->id,
        ]);

        $cash = $this->cash();
        $before = $cash->balanceOn();

        $closed = $this->contracts->close($contract->fresh(), [
            'closed_on' => '2026-10-01',
            'money_account_id' => $cash->id,
        ]);

        $this->assertSame(RentalContract::CLOSED, $closed->status);

        /*
         * ⭐ ফেরতের পর "ফেরত পাব" শূন্য — নাহলে চুক্তিটা চিরকাল
         * পাওনার তালিকায় বসে থাকত, আর তালিকাটা অর্থহীন হয়ে যেত।
         */
        $this->assertSame('0.0000', $closed->fresh()->depositLeft());

        $this->assertSame(
            bcadd($before, '1190000', 4),
            $cash->fresh()->balanceOn(),
            'বাকি জামানতটা টাকার খাতে ফেরত আসেনি।',
        );
    }

    /** শেষের তারিখ — ২৪ মাসের চুক্তি ২৪ মাস পরের আগের দিনে শেষ। */
    public function test_the_last_day_is_the_day_before_not_the_day_after(): void
    {
        $this->assertSame('2028-08-31', $this->godown()->ends_on->toDateString());
    }

    /**
     * ছয় মাস পর গোডাউন ছেড়ে দিলে বাকি জামানত ফেরত আসে।
     *
     * ── মালিকের কথা ─────────────────────────────────────────────────
     * *"কেউ ছয় মাস পরে গোডাউন ছেড়ে দিবে, তখন বাকি ভাড়া ফেরত আসবে।"*
     *
     * ⓘ ছয় মাসে কাটা পড়েছে ৬০,০০০, তাই ফেরত ১১,৪০,০০০ — পুরো মেয়াদের
     * হিসাব (৯,৬০,০০০) নয়। ⚠️ পার্থক্যটা ১,৮০,০০০, আর কেউ হাতে গুনলে
     * ঠিক এখানেই ভুল করতেন।
     */
    public function test_leaving_after_six_months_returns_what_is_actually_left(): void
    {
        $contract = $this->godown();
        $cash = $this->cash();

        foreach (['2026-09', '2026-10', '2026-11', '2026-12', '2027-01', '2027-02'] as $month) {
            $this->contracts->adjustMonth($contract->fresh(), [
                'for_month' => $month.'-01',
                'money_account_id' => $cash->id,
            ]);
        }

        $contract->refresh();

        $this->assertSame('60000.0000', $contract->adjustedSoFar());
        $this->assertSame('1140000.0000', $contract->depositLeft());

        $before = $cash->fresh()->balanceOn();

        $this->contracts->close($contract, [
            'closed_on' => '2027-03-01',
            'money_account_id' => $cash->id,
        ]);

        $this->assertSame(
            bcadd($before, '1140000', 4),
            $cash->fresh()->balanceOn(),
            'ছয় মাস পর ছাড়লে বাকি ১১,৪০,০০০ ফেরত আসেনি।',
        );
    }

    /**
     * জামানত বাড়ালে ভাড়া কমানো যায় — টাকাটা খাতাতেও যায়।
     *
     * ⚠️ `deposit_amount` ঘরটা হাতে বাড়িয়ে দিলে চুক্তি বলত এক কথা আর
     * খতিয়ান বলত আরেক। তাই টাকা নড়লে ভাউচার হয়, ব্যতিক্রম নেই।
     */
    public function test_adding_to_the_deposit_moves_real_money_and_lets_the_rent_drop(): void
    {
        $contract = $this->godown();
        $cash = $this->cash();
        $deposit = Account::query()->find($contract->account_id);

        $before = $deposit->balanceOn();

        $this->contracts->addToDeposit($contract, [
            'amount' => '300000',
            'money_account_id' => $cash->id,
            'paid_on' => '2026-09-05',
        ]);

        $contract->refresh();

        $this->assertSame('1500000.0000', (string) $contract->deposit_amount);
        $this->assertSame(
            bcadd($before, '300000', 4),
            $deposit->fresh()->balanceOn(),
            'জামানত বাড়ল অথচ খতিয়ানে টাকা যায়নি।',
        );

        $this->contracts->reviseTerms($contract, ['monthly_rent' => '25000']);

        $this->assertSame('15000.0000', $contract->fresh()->monthlyCash(),
            'ভাড়া কমার পর নগদের অংশটা নিজে থেকে কমেনি।');
    }

    /**
     * ⭐ শর্ত বদলালে **গত মাসগুলো নড়ে না**।
     *
     * ⚠️ এটাই সবচেয়ে সহজ ভুল: চুক্তির উপর একটা "চলতি ভাড়া" রেখে সবাই
     * সেটা পড়লে আজকের বদল গত বছরের হিসাবও বদলে দিত, আর বন্ধ মাস নড়ত।
     */
    public function test_a_revision_does_not_touch_the_months_already_done(): void
    {
        $contract = $this->godown();

        $done = $this->contracts->adjustMonth($contract, [
            'for_month' => '2026-09-01',
            'money_account_id' => $this->cash()->id,
        ]);

        $this->contracts->reviseTerms($contract->fresh(), [
            'monthly_rent' => '35000',
            'monthly_adjustment' => '15000',
        ]);

        $this->assertSame('30000.0000', (string) $done->fresh()->rent,
            'গত মাসের ভাড়া আজকের বদলে নড়ে গেছে।');
        $this->assertSame('10000.0000', (string) $done->fresh()->from_deposit);

        $next = $this->contracts->adjustMonth($contract->fresh(), [
            'for_month' => '2026-10-01',
            'money_account_id' => $this->cash()->id,
        ]);

        $this->assertSame('35000.0000', (string) $next->rent, 'নতুন মাসে নতুন ভাড়া বসেনি।');
        $this->assertSame('15000.0000', (string) $next->from_deposit);
    }

    /**
     * ⛔ বাকি জামানতে যতটা কুলাবে না, ততটা সমন্বয় বসানো যায় না।
     *
     * ⚠️ সীমাটা **এখন যা পড়ে আছে** তার উপর, মূল জামানতের উপর নয় — নাহলে
     * সফটওয়্যার এমন শর্ত মেনে নিত যেটা মেয়াদের মাঝপথে ফুরিয়ে যেত।
     */
    public function test_a_revision_that_would_run_the_deposit_dry_is_refused(): void
    {
        $contract = $this->godown();

        $this->expectException(ValidationException::class);

        $this->contracts->reviseTerms($contract, [
            'monthly_rent' => '80000',
            'monthly_adjustment' => '80000',
        ]);
    }

    private function godown(): RentalContract
    {
        return $this->contracts->open([
            'counterparty' => 'গোডাউনের মালিক',
            'counterparty_phone' => '01700000000',
            'subject' => 'গোডাউন',
            'deposit_amount' => '1200000',
            'monthly_rent' => '30000',
            'monthly_adjustment' => '10000',
            'starts_on' => '2026-09-01',
            'term_months' => 24,
            'money_account_id' => $this->cash()->id,
        ]);
    }

    private function cash(): Account
    {
        return Account::query()->money()->postable()->active()->orderBy('code')->firstOrFail();
    }
}
