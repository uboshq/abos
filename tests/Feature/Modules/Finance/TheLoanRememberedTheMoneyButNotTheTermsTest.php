<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Finance\Models\Deposit;
use App\Modules\Finance\Models\DepositKind;
use App\Modules\Finance\Models\HandLoanAccount;
use App\Modules\Finance\Models\RentalContract;
use App\Modules\Finance\Models\Withdrawal;
use App\Modules\MasterData\Models\Person;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ধারটা টাকাটা মনে রাখত, শর্তগুলো রাখত না — ১৫ সেপ্টেম্বর ২০২৬।
 *
 * ── ⛔ কী মেপে পাওয়া গেল ─────────────────────────────────────────────
 * অর্থের পাঁচটা খাতার নকশা কোডের সাথে মিলিয়ে দেখতে গিয়ে সবচেয়ে বড়
 * ফাঁকটা ছিল ধারে:
 *
 *     fin_hand_loan_accounts-এ ১২টা কলাম, আর **একটাও টাকার নয়**
 *     কে · কোন শাখা · কী নোট · অবস্থা — ব্যস
 *
 * ⚠️ টাকার গতিবিধি `fin_hand_loan_movements`-এ ঠিকই বসত। কিন্তু
 * *"সুদ কত"*, *"কবে ফেরত দেওয়ার কথা"*, *"কী কাগজে দাঁড়ানো"* — তিনটার
 * একটারও উত্তর কোথাও ছিল না।
 *
 * ── ⭐ কেন এটা "সুবিধা" নয়, সত্যিকারের ক্ষতি ─────────────────────────
 *     সুদ লেখা নেই   → বছরশেষে সুদ খরচে ওঠে না, মুনাফা বেশি দেখায়
 *     মেয়াদ লেখা নেই → কেউ মনে করিয়ে দেয় না
 *     কাগজ লেখা নেই  → অস্বীকার করলে প্রমাণ কী ছিল কেউ জানে না
 *
 * ⓘ আর ঐ তিনটা প্রশ্নই ওঠে ছয় মাস পরে, ঠিক তখন যখন সম্পর্কটা আর
 * ভালো নেই।
 *
 * ── ⚠️ এই ফাইলের প্রতিটা দাবি শিকলের একটা কড়ি ধরে ────────────────────
 * কলাম → মডেল → ভ্যালিডেশন → সেবা → পর্দা। ⛔ যেকোনো একটা কড়ি খসে
 * পড়লে বাকিগুলো **সবুজ থাকতে পারত**, আর ঘরটা নীরবে হারিয়ে যেত — এই
 * রিপোর সবচেয়ে চেনা রোগ।
 */
final class TheLoanRememberedTheMoneyButNotTheTermsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($user);
    }

    /**
     * ⛔ তেরোটা ঘরই সত্যিই টেবিলে বসেছে।
     *
     * ⓘ প্রথম কড়ি। ⚠️ মাইগ্রেশন না চললে নিচের সব দাবি ভিন্ন কারণে
     * ভাঙত, আর আসল কারণটা খুঁজে পেতে সময় যেত।
     */
    public function test_every_term_column_exists(): void
    {
        $expected = [
            'fin_hand_loan_accounts' => ['interest_rate', 'term_months', 'due_on', 'repayment', 'security', 'next_due_on'],
            'fin_deposits' => ['tax_rate', 'on_maturity'],
            'fin_rental_contracts' => ['advance_months', 'tax_rate', 'rent_day'],
            'fin_withdrawals' => ['kind'],
        ];

        $counted = 0;

        foreach ($expected as $table => $columns) {
            foreach ($columns as $column) {
                $this->assertTrue(
                    Schema::hasColumn($table, $column),
                    "{$table}.{$column} নেই — মাইগ্রেশনটা চলেনি।"
                );
                $counted++;
            }
        }

        $this->assertSame(12, $counted, 'গুনতিটাই বদলে গেছে — দাবিটা আর যা মাপার কথা তা মাপছে না।');
    }

    /**
     * ⭐ মডেল ঘরগুলো সত্যিই **লেখে** — `$fillable`-এ আছে।
     *
     * ⚠️ কলাম থাকা আর মডেল লিখতে পারা এক নয়। ⛔ `$fillable`-এ না থাকলে
     * Eloquent ঘরটা **চুপচাপ ফেলে দেয়** — কোনো ত্রুটি নয়, সারিটা
     * সংরক্ষিত হয়, কেবল ঐ ঘরটা খালি থাকে।
     */
    public function test_the_models_actually_write_the_terms(): void
    {
        $loan = HandLoanAccount::query()->create([
            'company_id' => $this->company->id,
            'branch_id' => $this->company->defaultBranch()?->id,
            'person_id' => $this->personId(),
            'status' => HandLoanAccount::ACTIVE,
            'interest_rate' => '2.5000',
            'term_months' => 6,
            'due_on' => '2027-03-15',
            'repayment' => HandLoanAccount::MONTHLY,
            'security' => HandLoanAccount::STAMPED,
        ]);

        $fresh = $loan->fresh();

        $this->assertSame('2.5000', (string) $fresh->interest_rate);
        $this->assertSame(6, (int) $fresh->term_months);
        $this->assertSame('2027-03-15', $fresh->due_on?->toDateString());
        $this->assertSame(HandLoanAccount::MONTHLY, $fresh->repayment);
        $this->assertSame(HandLoanAccount::STAMPED, $fresh->security);
    }

    /**
     * ⭐ শূন্য সুদ একটা **সত্যিকারের উত্তর**, "লেখা হয়নি" নয়।
     *
     * ⓘ পরিচিত মানুষের ধার প্রায়ই সুদবিহীন। ⚠️ ঘরটা nullable হলে
     * *"সুদ নেই"* আর *"কেউ লেখেনি"* এক দেখাত, আর বকেয়া হিসাব করতে
     * গিয়ে থামতে হত।
     */
    public function test_a_loan_with_no_interest_still_says_zero_not_nothing(): void
    {
        $loan = HandLoanAccount::query()->create([
            'company_id' => $this->company->id,
            'person_id' => $this->personId(),
            'status' => HandLoanAccount::ACTIVE,
        ]);

        $this->assertNotNull($loan->fresh()->interest_rate, 'সুদের ঘরটা null — "সুদ নেই" আর "লেখা হয়নি" আলাদা করা যাচ্ছে না।');
        $this->assertSame(0.0, (float) $loan->fresh()->interest_rate);

        // ⓘ মেয়াদ উল্টো — "যখন পারো ফেরত দিও" ধরনের ধার সত্যিই আছে
        $this->assertNull($loan->fresh()->term_months, 'মেয়াদ শূন্য মাস লেখা হয়েছে — ওটা কোনো উত্তরই নয়।');
    }

    /**
     * ⛔ পর্দায় ঘরগুলো সত্যিই আঁকা হয় — শেষ কড়ি।
     *
     * ⚠️ এটাই সেই দাবি যা আজ বারবার লাগত: কলাম বসেছে, মডেল লেখে,
     * ভ্যালিডেশন মানে — অথচ **ফর্মে ঘরটা কেউ আঁকেনি**। ⓘ তখন সব
     * সবুজ, আর ব্যবহারকারী কোনোদিন সুদের হার লিখতেই পারেন না।
     */
    public function test_the_form_actually_draws_the_term_fields(): void
    {
        // ⓘ ফর্মটা ২০ সেপ্টেম্বর ২০২৬ থেকে নিজের পাতায় — তালিকার উপরে আর বসে না
        $page = $this->get(route('finance.hand_loan.create'));

        $page->assertOk();

        foreach (['interest_rate', 'term_months', 'due_on', 'repayment', 'security'] as $field) {
            $page->assertSee('name="'.$field.'"', false);
        }
    }

    /**
     * ⭐ ফর্ম থেকে পাঠানো শর্তগুলো সত্যিই খাতায় পৌঁছায়।
     *
     * ⓘ মাঝের কড়িগুলো — ভ্যালিডেশন ও সেবা — এই একটা দাবিতেই মাপা হয়।
     * ⚠️ কন্ট্রোলারে ঘরটা `validate()`-এ না থাকলে Laravel সেটা
     * **ফেলে দেয়**, আর সেবা কখনো দেখতেই পায় না।
     */
    public function test_the_terms_survive_the_round_trip_from_the_form(): void
    {
        $person = $this->personId();

        $this->post(route('finance.hand_loan.store'), [
            'person_id' => $person,
            'interest_rate' => '1.75',
            'term_months' => '9',
            'due_on' => '2027-06-30',
            'repayment' => HandLoanAccount::MONTHLY,
            'security' => HandLoanAccount::BLANK_CHEQUE,
            'note' => 'ঈদের আগে স্টক তোলার জন্য',
        ])
            /*
             * ⚠️ `assertRedirect()` একাই যথেষ্ট নয় — **ভ্যালিডেশন ব্যর্থ
             * হলেও রিডাইরেক্ট হয়** (ফিরে, ভুলের বার্তাসহ)।
             *
             * ⛔ প্রথমে কেবল ওটাই লেখা ছিল, আর দাবিটা সবুজ দেখিয়ে পরের
             * লাইনে *"No query results"* বলে ভাঙল — অর্থাৎ ব্যর্থতার
             * বার্তাটা আসল কারণ থেকে এক ধাপ দূরে ছিল।
             *
             * ⭐ তাই আগে ভুল আছে কি না জিজ্ঞেস করা হয়: থাকলে PHPUnit
             * ভুলটাই ছাপে, আর কারণ খুঁজতে হয় না।
             */
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $loan = HandLoanAccount::query()->latest('id')->firstOrFail();

        $this->assertSame('1.7500', (string) $loan->interest_rate, 'সুদের হার পথে হারিয়ে গেছে।');
        $this->assertSame(9, (int) $loan->term_months);
        $this->assertSame('2027-06-30', $loan->due_on?->toDateString());
        $this->assertSame(HandLoanAccount::MONTHLY, $loan->repayment);
        $this->assertSame(HandLoanAccount::BLANK_CHEQUE, $loan->security);
    }

    /**
     * বানানো ছাঁদ বা কাগজ গ্রহণ করা হয় না।
     *
     * ⚠️ `Rule::in()` ছাড়া যে কেউ অনুরোধে `security=whatever` পাঠাতে
     * পারত, আর সেটা খাতায় বসে যেত — পর্দায় তখন খালি ঘর দেখাত।
     */
    public function test_an_invented_repayment_shape_is_refused(): void
    {
        $person = $this->personId();

        $this->post(route('finance.hand_loan.store'), [
            'person_id' => $person,
            'repayment' => 'whenever_i_feel_like_it',
        ])->assertSessionHasErrors('repayment');
    }

    /**
     * ⭐ বাকি তিনটা খাতার ঘরগুলোও মডেল লেখে।
     *
     * ⓘ আমানত ও ভাড়ায় **উৎসে কর**, আর উত্তোলনে **ধরন** — তিনটাই
     * আইনি বা হিসাবি কারণে আলাদা, আর তিনটাই এতদিন ছিল না।
     */
    public function test_tax_and_kind_reach_the_other_three_ledgers(): void
    {
        $branchId = $this->company->defaultBranch()?->id;
        $accountId = DB::table('accounts')->where('company_id', $this->company->id)->value('id');
        $kindId = DB::table('fin_deposit_kinds')->where('company_id', $this->company->id)->value('id');

        if ($kindId === null) {
            $kindId = DepositKind::query()->create([
                'company_id' => $this->company->id,
                'code' => 'FDR-T', 'name_en' => 'FDR', 'name_bn' => 'এফডিআর',
                'shape' => 'lump', 'issuer' => 'bank',
                'personal_only' => false, 'is_active' => true, 'sort' => 1,
            ])->id;
        }

        $deposit = Deposit::query()->create([
            'company_id' => $this->company->id, 'branch_id' => $branchId,
            'document_no' => 'DEP-TERMS-1', 'kind_id' => $kindId,
            'institution' => 'IBBL', 'principal' => '1000000.0000',
            'opened_on' => now()->toDateString(),
            'tax_rate' => '15.00', 'on_maturity' => 'encash',
        ]);

        $this->assertSame('15.00', (string) $deposit->fresh()->tax_rate, 'আমানতে উৎসে কর পৌঁছায়নি।');
        $this->assertSame('encash', $deposit->fresh()->on_maturity);

        $rent = RentalContract::query()->create([
            'company_id' => $this->company->id, 'branch_id' => $branchId,
            'document_no' => 'RNT-TERMS-1', 'counterparty' => 'হাজী আলী',
            'account_id' => $accountId, 'expense_account_id' => $accountId,
            'deposit_amount' => '200000.0000', 'monthly_rent' => '45000.0000',
            'starts_on' => now()->startOfMonth()->toDateString(),
            'term_months' => 36,
            'ends_on' => now()->startOfMonth()->addMonths(36)->toDateString(),
            'advance_months' => 3, 'tax_rate' => '5.00', 'rent_day' => 5,
        ]);

        $this->assertSame(3, (int) $rent->fresh()->advance_months, 'অগ্রিম ভাড়ার মাস পৌঁছায়নি।');
        $this->assertSame('5.00', (string) $rent->fresh()->tax_rate);
        $this->assertSame(5, (int) $rent->fresh()->rent_day);

        $out = Withdrawal::query()->create([
            'company_id' => $this->company->id, 'branch_id' => $branchId,
            'document_no' => 'WDR-TERMS-1',
            'person_id' => $this->personId(),
            'amount' => '50000.0000', 'kind' => Withdrawal::SALARY,
            'trx_date' => now()->toDateString(),
        ]);

        $this->assertSame(Withdrawal::SALARY, $out->fresh()->kind, 'উত্তোলনের ধরন পৌঁছায়নি।');

        /*
         * ⭐ আর ধরনটার আসল কাজ: কোনটা মুনাফা কমায়।
         *
         * ⚠️ মালিকের বেতন খরচ; উত্তোলন ও মুনাফার ভাগ নয়। ⓘ এটা কোথাও
         * লেখা না থাকলে প্রত্যেকে নিজের মতো ধরে নিত, আর দুইটা পর্দা
         * দুই রকম মুনাফা দেখাত।
         */
        $this->assertTrue($out->fresh()->isExpense(), 'মালিকের বেতন খরচ হিসেবে গোনা হচ্ছে না।');

        $out->forceFill(['kind' => Withdrawal::DRAWING])->save();
        $this->assertFalse($out->fresh()->isExpense(), 'উত্তোলন খরচ হিসেবে গোনা হচ্ছে — মুনাফা ভুল দেখাবে।');
    }

    /**
     * ⛔ ডেমোর ব্যক্তিটা এই কোম্পানির নয়।
     *
     * ⓘ মেপে দেখা: `mdm_people`-এ একটাই সারি, আর সেটা `company_id = 1`
     * (Demo owner)। এই পরীক্ষার কোম্পানি TDEPOT, অন্য আইডি।
     *
     * ⚠️ তাই `->where('company_id', ...)` সবসময় `null` দিত, আর ফর্মে
     * খালি `person_id` গিয়ে ভ্যালিডেশনে আটকাত — অথচ ব্যর্থতার বার্তা
     * বলত *"No query results"*, যা আসল কারণ থেকে দুই ধাপ দূরে।
     *
     * ⭐ তাই নিজেরটা বানানো হয়, ডেমোর বিষয়বস্তুর উপর না দাঁড়িয়ে।
     */
    private function personId(): int
    {
        /*
         * ⚠️ `code` বাধ্যতামূলক — মেপে দেখা: `mdm_people`-এ তিনটা ঘর
         * `NOT NULL` আর default নেই (`company_id` · `code` · `name_en`)।
         *
         * ⓘ সত্যিকারের পথে কোডটা [[App\Core\Services\ListExport]]-এর
         * `create()` বসায়; সরাসরি মডেল ডাকলে সেটা হয় না। ⭐ পরীক্ষার
         * নমুনায় নিজেরটা দেওয়াই সৎ — নাহলে বাস্তবের চেয়ে আলাদা সারি
         * তৈরি হয় আর ব্যর্থতাটা বিভ্রান্ত করে।
         */
        return (int) Person::query()->firstOrCreate(
            ['company_id' => $this->company->id, 'code' => 'PRS-TEST-1'],
            ['name_en' => 'Karim Uddin', 'name_bn' => 'করিম উদ্দিন', 'mobile' => '01712-345678', 'is_active' => true],
        )->id;
    }
}
