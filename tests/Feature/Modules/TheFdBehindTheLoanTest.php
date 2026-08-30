<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Loan;
use App\Modules\Accounts\Services\LoanService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\Deposit;
use App\Modules\Finance\Models\DepositKind;
use App\Modules\Finance\Services\DepositKindInstaller;
use App\Modules\Finance\Services\DepositService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ঋণের পেছনে বাঁধা FD — এখন জমার পর্দা থেকে।
 *
 * ── এই ফাইলটা আগে কী পাহারা দিত ──────────────────────────────────────
 * FD ছিল `acc_loans`-এর একটা ধরন। যুক্তিটা খারাপ ছিল না: FD মানে আমরা
 * ব্যাংককে টাকা ধার দিয়েছি নির্দিষ্ট মেয়াদে নির্দিষ্ট সুদে, তাই
 * সুদ-খতিয়ান-বকেয়ার পুরো যন্ত্রটা আবার লিখতে হয়নি।
 *
 * ── কেন সেটা বদলাল, ৩০ আগস্ট ২০২৬ ────────────────────────────────────
 * জমা নিজের পর্দা পাওয়ার পর ব্যাংকে টাকা রাখার **দুইটা দরজা** হয়ে
 * গেল। দুই দরজা মানে দুই হিসাব: একজন ঋণের পাতা খুলে যোগ করেন, আরেকজন
 * জমার পাতা — দুইজনের সংখ্যা মেলে না, আর কোনটা সত্যি তা বলার উপায়
 * থাকে না। মালিক তাই ঋণের দরজাটা বন্ধ করতে বললেন।
 *
 * ── কিন্তু সবচেয়ে দামি কথাটা রয়ে গেছে ────────────────────────────────
 * ব্যাংক প্রায়ই ঋণ দেয় FD বন্ধক রেখে। কাগজে দুইটা আলাদা জিনিস — একটা
 * সম্পদ, একটা দায়। কিন্তু ওই FD-র টাকাটা আমাদের হাতে নেই: ঋণ শোধ না
 * হওয়া পর্যন্ত ভাঙানো যায় না।
 *
 * সম্পর্কটা না রাখলে তালিকায় FD-টা "আছে" দেখাত, আর কেউ দরকারের দিনে
 * ধরে নিত ওটা ভাঙিয়ে ফেলা যাবে। তাই বন্ধনটা জমার সাথে সাথেই এসেছে,
 * আর এই ফাইলটা এখন **দুইটা** জিনিস পাহারা দেয়: দরজাটা সত্যিই বন্ধ,
 * আর বন্ধনটা সত্যিই টিকে আছে।
 *
 * টাকা নড়াচড়া, কিস্তি, মেয়াদ ভাঙা — ওসব জমার নিজের ফাইলে
 * ([[MoneyPutAwayIsNotMoneyLentTest]])। এখানে কেবল যা এই বদলটার নিজের।
 */
class TheFdBehindTheLoanTest extends TestCase
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

        app(StandardChart::class)->install();
        app(DepositKindInstaller::class)->install();
    }

    private function cash(): Account
    {
        return Account::query()->postable()->where('name_en', 'like', '%Cash%')->firstOrFail();
    }

    private function payable(): Account
    {
        return Account::query()->where('code', '2210')->firstOrFail();
    }

    private function interestExpense(): Account
    {
        return Account::query()->where('type', Account::EXPENSE)->postable()->orderBy('code')->firstOrFail();
    }

    /**
     * একটা নেওয়া ব্যাংক-ঋণ, টাকা তোলা সহ।
     *
     * ---- কেন টার্ম, সিসি নয় ----
     * "শোধ করলে বাঁধন খোলে" পরীক্ষাটা সিসি দিয়ে লেখা ছিল, আর সেটা
     * ভুল উত্তর দিত: সিসিতে ব্যালেন্স শূন্যে নামা মানে সীমাটা শেষ নয়
     * ([[Loan::isRevolving()]])। টার্ম লোনে শোধ মানে সত্যিই শেষ।
     */
    private function bankLoan(string $amount, string $kind = Loan::TERM): Loan
    {
        $loan = app(LoanService::class)->create(
            data: [
                'lender' => 'Sonali Bank',
                'kind' => $kind,
                'sanctioned' => $amount,
                'interest_rate' => '12',
                'tenure_months' => 12,
                'interest_method' => 'flat',
                'start_date' => '2026-08-01',
                'principal_account_id' => $this->payable()->id,
                'interest_account_id' => $this->interestExpense()->id,
            ],
            intoAccountId: $this->cash()->id,
        );

        return $loan->refresh();
    }

    private function fd(string $amount, ?int $pledgedTo = null, string $heldBy = Deposit::BUSINESS): Deposit
    {
        return app(DepositService::class)->open([
            'kind_id' => DepositKind::query()->where('code', 'FDR')->firstOrFail()->id,
            'institution' => 'সোনালী ব্যাংক',
            'held_by' => $heldBy,
            'principal' => $amount,
            'return_word' => 'interest',
            'opened_on' => '2026-08-01',
            'matures_on' => '2027-08-01',
            'funded_from_account_id' => $this->cash()->id,
            'pledged_to_loan_id' => $pledgedTo,
        ]);
    }

    /* ── দরজাটা বন্ধ ────────────────────────────────────────────── */

    /**
     * ঋণের ফর্ম আর FD বা DPS নেয় না।
     *
     * ── কেন এটা সবচেয়ে জরুরি পরীক্ষা ────────────────────────────────
     * `kind` কলামটা একটা সাধারণ string — ডাটাবেজ `'fd'` লিখতে বাধা
     * দেয় না। ধরনটা কোড থেকে সরিয়ে দিলেও যাচাইয়ের নিয়মে থেকে গেলে
     * পুরনো একটা বুকমার্ক, একটা ইমপোর্ট, বা কারও খোলা রাখা ট্যাব
     * দিব্যি একটা FD-সারি বসিয়ে দিত — আর ওটা কোনো পর্দাতেই দেখা যেত
     * না, কারণ ঋণের তালিকা ওটাকে চেনে না আর জমার তালিকা ওটাকে খোঁজে
     * না। টাকাটা খাতায় থাকত, চোখের বাইরে।
     */
    public function test_the_loan_form_no_longer_takes_a_deposit(): void
    {
        foreach (['fd', 'dps'] as $kind) {
            $this->post(route('accounts.loan.store'), [
                'lender' => 'Sonali Bank',
                'kind' => $kind,
                'sanctioned' => '200000',
                'interest_rate' => '9',
                'start_date' => '2026-08-01',
                'principal_account_id' => $this->payable()->id,
                'interest_account_id' => $this->interestExpense()->id,
                'into_account_id' => $this->cash()->id,
            ])->assertSessionHasErrors('kind');
        }

        $this->assertSame(0, Loan::query()->whereIn('kind', ['fd', 'dps'])->count(),
            'ঋণের টেবিলে একটা জমা বসে গেছে — দরজাটা তাহলে খোলাই আছে।');
    }

    /**
     * ঋণের পর্দায় জমার ঘরগুলোও আর নেই।
     *
     * নিয়ম সরিয়ে ঘরটা রেখে দিলে ব্যবহারকারী ওটা ভরে Save চাপতেন আর
     * একটা যাচাই-ভুল পেতেন — অর্থাৎ পর্দা একটা কাজ করতে বলছে যেটা
     * সে নিজেই নেয় না।
     */
    public function test_the_loan_screen_offers_no_deposit_fields(): void
    {
        $html = (string) $this->get(route('accounts.loan.create'))->assertOk()->getContent();

        $this->assertStringNotContainsString('name="pledged_against_id"', $html,
            'ঋণের ফর্মে এখনো বন্ধকের ঘর আছে।');
        $this->assertStringNotContainsString('value="fd"', $html);
        $this->assertStringNotContainsString('value="dps"', $html);
    }

    /* ── বন্ধনটা টিকে আছে ───────────────────────────────────────── */

    /** বাঁধা না থাকলে জমাটা হাতের টাকা। */
    public function test_an_unpledged_fd_is_money_we_can_reach(): void
    {
        $this->assertFalse($this->fd('200000')->isLocked());
    }

    /**
     * চালু ঋণের বিপরীতে বাঁধা FD হাতের টাকা নয়।
     */
    public function test_an_fd_pledged_against_a_live_loan_is_out_of_reach(): void
    {
        $loan = $this->bankLoan('300000');

        $fd = $this->fd('200000', $loan->id);

        $this->assertTrue($fd->isLocked(),
            'বাঁধা FD-টাকে হাতের টাকা দেখাচ্ছে — অথচ ঋণ শোধ না হলে ওটা ভাঙানো যায় না।');

        $this->assertSame($loan->id, $fd->pledgedToLoan->id);
    }

    /**
     * ঋণ শোধ হলে বাঁধনও খোলে।
     *
     * বন্ধক থাকে দায়ের জন্য; দায় না থাকলে বন্ধকেরও কারণ নেই। এটা না
     * থাকলে শোধ করা ঋণের FD চিরকাল আটকে দেখাত, আর মালিক জানতেন না
     * তাঁর টাকাটা আসলে ছাড়া পেয়ে গেছে।
     */
    public function test_paying_off_the_loan_frees_the_fd(): void
    {
        $loan = $this->bankLoan('300000');

        $fd = $this->fd('200000', $loan->id);
        $this->assertTrue($fd->isLocked());

        app(LoanService::class)->repay($loan, '300000', $this->cash()->id, '2026-09-01');

        $this->assertFalse($fd->refresh()->isLocked());
    }

    /**
     * ঘোরানো সীমায় আজ শূন্য থাকলেও জামানত ছাড়া পায় না।
     *
     * ---- কেন এটার নিজের পরীক্ষা, ৩০ আগস্ট ২০২৬ ----
     * লাইভে ধরা পড়ল: একটা সিসি সীমা খুলে তার বিপরীতে FD বন্ধক রাখার
     * পর তালিকা বলল **"ভাঙানো যায়"** -- কারণ সেদিন সীমা থেকে কিছু
     * তোলা ছিল না, তাই বকেয়া শূন্য, তাই "চুকে গেছে"।
     *
     * সিসিতে ব্যালেন্স রোজ শূন্যে নামে, ওটাই তার স্বভাব। ব্যাংক
     * প্রতিবার FD ফেরত দেয় না।
     *
     * ভুলটা এদিকেই ভয়ানক: মিথ্যা "ভাঙানো যায়" পড়ে কেউ ওই টাকার উপর
     * ভরসা করে সিদ্ধান্ত নেন, আর ব্যাংকে গিয়ে জানতে পারেন যায় না।
     */
    public function test_an_undrawn_credit_line_still_holds_the_deposit(): void
    {
        $cc = app(LoanService::class)->create(
            data: [
                'lender' => 'Sonali Bank',
                'kind' => Loan::CC,
                'sanctioned' => '300000',
                'interest_rate' => '12',
                'start_date' => '2026-08-01',
                'principal_account_id' => $this->payable()->id,
                'interest_account_id' => $this->interestExpense()->id,
            ],
        );

        $this->assertSame(0, bccomp($cc->outstanding(), '0', 4),
            'পরীক্ষার শর্তই ভাঙল — সীমা থেকে কিছু তোলা হয়ে গেছে।');

        $this->assertTrue($this->fd('200000', $cc->id)->isLocked(),
            'সীমা থেকে আজ কিছু তোলা নেই বলে বাঁধা FD-টাকে হাতের টাকা দেখাচ্ছে।');
    }

    /** একটা ঋণের বিপরীতে একাধিক জমা বাঁধা থাকতে পারে। */
    public function test_a_loan_can_have_more_than_one_fd_behind_it(): void
    {
        $loan = $this->bankLoan('500000');

        $this->fd('200000', $loan->id);
        $this->fd('150000', $loan->id);

        /*
         * গোনাটা জমার দিক থেকে, ঋণ থেকে নয়।
         *
         * accounts কারও উপর নির্ভর করে না, তাই [[Loan]] থেকে
         * [[Deposit]]-এর দিকে তাকানো যায় না -- তাকালে মডিউলের ক্রমটাই
         * উল্টে যেত।
         */
        $this->assertSame(2, Deposit::query()->where('pledged_to_loan_id', $loan->id)->count());
    }

    /**
     * মালিকের নিজের নামের জমা ব্যবসার ধারের জামানত হয় না।
     *
     * ── কেন ─────────────────────────────────────────────────────────
     * মালিক নিজের নামে যেটা রেখেছেন সেটা ব্যবসার সম্পদ নয় — খাতায় ওটা
     * উত্তোলন। ব্যবসার ধারের বিপরীতে ওটা বাঁধা দেখানো মানে জামানত
     * হিসেবে এমন কিছু গোনা যা ব্যবসার নয়, আর ব্যাংকের কাছে দেওয়া
     * হিসাবটাই তখন ভুল হত।
     */
    public function test_a_deposit_in_the_owners_name_is_never_pledged_to_the_firm(): void
    {
        $loan = $this->bankLoan('300000');

        $own = $this->fd('200000', $loan->id, Deposit::OWNER);

        $this->assertNull($own->pledged_to_loan_id,
            'মালিকের নিজের জমাটা ব্যবসার ধারের জামানত হয়ে বসেছে।');

        $this->assertFalse($own->isLocked());
    }

    /**
     * পর্দাটা বন্ধনটা বলে, চুপ থাকে না।
     *
     * উপরের পরীক্ষাগুলো মডেল দেখে; এটা দেখে **পাতাটা**। বাঁধা অবস্থাটা
     * কেবল কোডে থাকলে ব্যবহারকারীর কাছে ওটা নেই — আর সিদ্ধান্তটা তো
     * তিনিই নেন।
     */
    public function test_the_list_says_which_loan_holds_the_deposit(): void
    {
        $loan = $this->bankLoan('300000');
        $this->fd('200000', $loan->id);

        $this->get(route('finance.deposit.index', ['issuer' => 'bank']))
            ->assertOk()
            ->assertSee($loan->document_no);
    }
}
