<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\HandLoanAccount;
use App\Modules\Finance\Models\HandLoanMovement;
use App\Modules\Finance\Services\HandLoanService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * হাতধার ঋণ নয় — আর সেটা কেবল কথার কথা নয়।
 *
 * ── মালিকের নির্দেশ, ২৯ আগস্ট ২০২৬ ───────────────────────────────────
 * *"এটা ঋণের ধরন হইলে হবে না, এটা টোটালি আলাদা একটা জিনিস। hand loan
 * আলাদা মেনু করো মানে পূর্ণাঙ্গ হিসাব আলাদা।"*
 *
 * ── আর HP সেই ভুলটার ফল ধরেছে ────────────────────────────────────────
 * ২৯ আগস্টের রিপোর্ট: ঋণের ফর্মে "Hand loan" বেছে সেভ করলে সেটা
 * **"Cash credit (CC)" হিসেবে সেভ ও প্রদর্শিত** হত। জিনিসটা ওখানে
 * থাকারই কথা নয় — ওই ফর্মটা ব্যাংকের ঋণের, যেখানে কিস্তি, সুদ ও
 * জামানত থাকে। হাতধারে একটাও নয়।
 *
 * ── এই ফাইলটা দুইটা জিনিস পাহারা দেয় ────────────────────────────────
 * ১. হিসাবটা ঠিক — টাকা বাইরে গেলে ক্যাশ কমে, খাতে বসে, ব্যালেন্স
 *    ঠিক চিহ্নে থাকে।
 * ২. **একই ঘটনার দুইটা দরজা নেই** — ঋণে হাতধারের কোনো ধরন থাকা চলবে না।
 */
class MoneyLentOnAWordIsNotALoanTest extends TestCase
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
        app(CashTillService::class)->ensurePrimaryTill();
    }

    private function service(): HandLoanService
    {
        return app(HandLoanService::class);
    }

    private function cash(): Account
    {
        return Account::query()->money()->postable()->active()->firstOrFail();
    }

    private function ledger(string $code): string
    {
        $account = Account::query()->where('code', $code)->firstOrFail();

        $row = LedgerEntry::query()
            ->where('company_id', $this->company->id)
            ->where('account_id', $account->id)
            ->selectRaw('COALESCE(SUM(debit), 0) as d, COALESCE(SUM(credit), 0) as c')
            ->first();

        return bcsub((string) $row->d, (string) $row->c, 4);
    }

    private function karim(): HandLoanAccount
    {
        return $this->service()->open([
            'person_name' => 'করিম',
            'mobile' => '01711000000',
        ]);
    }

    /** @param  array<string, mixed>  $extra */
    private function move(HandLoanAccount $account, string $direction, string $amount, array $extra = []): void
    {
        $this->service()->move($account, array_merge([
            'direction' => $direction,
            'amount' => $amount,
            'moved_on' => now()->toDateString(),
            'money_account_id' => $this->cash()->id,
        ], $extra));
    }

    /**
     * ধার দিলে ক্যাশ কমে আর হাতধারের খাতে বসে।
     *
     * ── কেন খাতায় যায়, অথচ কোনো কাগজ নেই ────────────────────────────
     * টাকাটা ব্যবসার — টিল থেকে বেরোয়। খাতায় না বসালে ক্যাশ বই ঠিক ওই
     * পরিমাণ কম দেখাত, আর **ঘাটতি দেখতে চুরির মতো**, ধার দেওয়ার মতো নয়।
     */
    public function test_lending_moves_the_money_out_of_the_till(): void
    {
        $cashBefore = $this->ledger($this->cash()->code);

        $this->move($this->karim(), HandLoanMovement::OUT, '5000');

        $this->assertSame(0, bccomp($this->ledger(StandardChart::HAND_LOAN), '5000', 4),
            'হাতধারের খাতে টাকাটা বসেনি।');

        $this->assertSame(0, bccomp(
            bcsub($this->ledger($this->cash()->code), $cashBefore, 4), '-5000', 4),
            'টিল থেকে টাকাটা বেরোয়নি — ক্যাশ বই বেশি দেখাবে।');
    }

    /**
     * একটাই সম্পর্ক, একটাই ব্যালেন্স — তিনটা ঋণ নয়।
     *
     * পাঁচ দিলাম, দুই ফেরত এল, আরও তিন দিলাম → বাকি ছয়।
     */
    public function test_give_take_give_is_one_running_balance(): void
    {
        $karim = $this->karim();

        $this->move($karim, HandLoanMovement::OUT, '5000');
        $this->move($karim, HandLoanMovement::IN, '2000');
        $this->move($karim, HandLoanMovement::OUT, '3000');

        $this->assertSame(0, bccomp($this->service()->balanceOf($karim), '6000', 4),
            'তিনটা চলাচলের পর ব্যালেন্স ৬,০০০ হয়নি।');

        $this->assertSame(3, $karim->movements()->count(),
            'চলাচলের সারি তিনটা থাকার কথা।');
    }

    /**
     * ডিপো ধার নিলে ব্যালেন্স ঋণাত্মক — আর সেটাই ঠিক।
     *
     * ── কেন দ্বিতীয় কোনো খাত নয় ────────────────────────────────────
     * দুইটা খাত (পাওনা ও দেনা) রাখলে বছরশেষে দুইটা যোগ-বিয়োগ করে নিট
     * বের করতে হত, অথচ প্রশ্নটা সবসময় নিট নিয়েই। আর একই মানুষ দুই
     * তালিকায় থাকতে পারতেন।
     */
    public function test_borrowing_shows_as_a_negative_balance(): void
    {
        $mama = $this->service()->open(['person_name' => 'মামা']);

        $this->move($mama, HandLoanMovement::IN, '10000');

        $this->assertSame(0, bccomp($this->service()->balanceOf($mama), '-10000', 4),
            'ডিপো ধার নিলে ব্যালেন্স ঋণাত্মক হওয়ার কথা।');

        $standing = $this->service()->standing();

        $this->assertSame(0, bccomp($standing['we_owe'], '10000', 4),
            '"আমাদের দিতে হবে" যোগফলে টাকাটা নেই।');

        $this->assertSame(0, bccomp($standing['owed_to_us'], '0', 4),
            'ধার নেওয়া টাকা ভুল করে পাওনার ঘরে গেছে।');
    }

    /**
     * ঋণাত্মক অঙ্ক নেওয়া হয় না — উল্টো দিকটা উল্টো দিক হিসেবেই লিখতে হবে।
     *
     * মেনে নিলে ব্যালেন্সটা নির্ভর করত কেউ কোন দুইভাবে একই কথা বলল
     * তার উপর, আর কোনো পর্দায় পার্থক্যটা দেখা যেত না।
     */
    public function test_a_negative_amount_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->move($this->karim(), HandLoanMovement::OUT, '-500');
    }

    /**
     * বাকি থাকতে "চুকে গেছে" বলা যায় না।
     *
     * বললে টাকাটা তালিকা থেকে হারাত অথচ খাতায় থেকে যেত — আর ঠিক ওই
     * টাকাটা ভুলে যাওয়া ঠেকাতেই ফিচারটা।
     */
    public function test_it_cannot_be_settled_while_money_is_out(): void
    {
        $karim = $this->karim();
        $this->move($karim, HandLoanMovement::OUT, '5000');

        $this->expectException(ValidationException::class);

        $this->service()->settle($karim);
    }

    /**
     * শূন্য হলে চুকে যায়, আর ইতিহাস থাকে।
     *
     * "তুমি তো ফেরত দাওনি" কথাটার উত্তর ওই পুরনো সারিগুলোই — তাই
     * চুকে যাওয়া মানে মুছে ফেলা নয়।
     */
    public function test_when_it_comes_back_it_settles_and_the_history_stays(): void
    {
        $karim = $this->karim();

        $this->move($karim, HandLoanMovement::OUT, '5000');
        $this->move($karim, HandLoanMovement::IN, '5000');

        $this->service()->settle($karim);

        $this->assertSame(HandLoanAccount::SETTLED, $karim->fresh()->status);
        $this->assertSame(2, $karim->movements()->count(), 'ইতিহাসটা মুছে গেছে।');

        $this->assertSame(0, bccomp($this->ledger(StandardChart::HAND_LOAN), '0', 4),
            'ফেরত আসার পরেও হাতধারের খাতে টাকা রয়ে গেছে।');
    }

    /**
     * চুকে যাওয়া খাতায় আর কিছু বসে না।
     */
    public function test_nothing_more_goes_into_a_settled_account(): void
    {
        $karim = $this->karim();
        $this->move($karim, HandLoanMovement::OUT, '1000');
        $this->move($karim, HandLoanMovement::IN, '1000');
        $this->service()->settle($karim);

        $this->expectException(ValidationException::class);

        $this->move($karim->fresh(), HandLoanMovement::OUT, '500');
    }

    /**
     * প্রতিটা চলাচল তার ভাউচারে নামায় (নিয়ম ১)।
     */
    public function test_every_movement_leads_to_its_voucher(): void
    {
        $karim = $this->karim();
        $this->move($karim, HandLoanMovement::OUT, '2500');

        $movement = $karim->movements()->firstOrFail();

        $this->assertNotNull($movement->voucher_id, 'চলাচলটা কোনো ভাউচারে নামায় না।');
        $this->assertNotNull($movement->money_account_id, 'কোন খাত থেকে গেল তা লেখা নেই।');
    }

    /**
     * নাম ছাড়া খাতা খোলা যায় না।
     */
    public function test_it_needs_a_name(): void
    {
        $this->expectException(ValidationException::class);

        $this->service()->open(['person_name' => '   ']);
    }

    /**
     * পর্দাগুলো খোলে, আর ওখান থেকেই টাকা নাড়ানো যায়।
     */
    public function test_the_screens_work(): void
    {
        $this->get(route('finance.hand_loan.index'))->assertOk();

        $this->post(route('finance.hand_loan.store'), ['person_name' => 'রহিম'])
            ->assertRedirect();

        $rahim = HandLoanAccount::query()->where('person_name', 'রহিম')->firstOrFail();

        $this->get(route('finance.hand_loan.show', $rahim))->assertOk()->assertSee('রহিম');

        $this->post(route('finance.hand_loan.move', $rahim), [
            'direction' => HandLoanMovement::OUT,
            'amount' => '1500',
            'moved_on' => now()->toDateString(),
            'money_account_id' => $this->cash()->id,
        ])->assertRedirect();

        $this->assertSame(0, bccomp($this->service()->balanceOf($rahim), '1500', 4),
            'পর্দা থেকে দেওয়া টাকাটা খাতায় বসেনি।');
    }
}
