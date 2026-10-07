<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Accounts\Services\AccountService;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * টাকার ধরন গাছ থেকে আসে, হাতের টিক থেকে নয়।
 *
 * ── কী ঘটেছিল, ১৩ সেপ্টেম্বর ২০২৬ ────────────────────────────────────
 * ফর্মে দুইটা টিক ছিল — "নগদ খাত" আর "ব্যাংক বা MFS খাত"। তার তিনটা
 * ফল ছিল, আর তিনটাই মালিক এক বসায় দেখেছেন:
 *
 *   ১. প্রমিত ছকের একটা সারিও টিক দুইটা পাঠাত না, তাই নতুন কোম্পানিতে
 *      প্রতিটা খাত "টাকার খাত নয়" হয়ে বসত
 *   ২. ফলে Finance-এর পর্দা খাত বাছত গাছ ধরে, আর Accounts-এর পাহারা
 *      নম্বর চাইত পতাকা ধরে — এক প্রশ্নের দুইটা উত্তর
 *   ৩. ব্যাংক আর MFS একই টিকে বসত
 *
 * ⭐ এই ফাইলটা প্রমাণ করে উত্তরটা এখন **একটাই জায়গা থেকে** আসে।
 */
class BankAndMfsWoreTheSameFlagTest extends TestCase
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
        $user = $this->user;

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($user);
    }

    private function service(): AccountService
    {
        return app(AccountService::class);
    }

    /*
     * ⚠️ `string` নয়, `int|string` — আর কারণটা PHP-র নিজের নিয়ম।
     *
     * নিচের লুপে চাবিগুলো `'1101'`, `'1102'`, `'1105'` — দেখতে স্ট্রিং,
     * কিন্তু PHP অ্যারের **সংখ্যা-দেখতে স্ট্রিং চাবি নীরবে int-এ বদলে
     * দেয়**। ⓘ `declare(strict_types=1)` থাকায় সেটা আর নীরব থাকেনি,
     * আর টেস্টটাই থেমে গেছে — যা ভালো: মানটা ঠিকই ছিল, কেবল ধরনটা নয়।
     */
    private function mother(int|string $code): Account
    {
        return Account::query()->where('code', (string) $code)->firstOrFail();
    }

    /**
     * প্রমিত ছক বসানোর সাথে সাথেই তিনটা মা তিন ধরন পায়।
     *
     * ⛔ এটাই সেই দাবি যা আগে **শূন্যের উপর সবুজ** থাকত: পতাকাটা কেউ
     * বসাত না, তাই "সব খাত ঠিক আছে" বলা যেত কারণ গোনার মতো কিছুই ছিল না।
     * তাই এখানে আগে **সংখ্যাটা** দাবি করা হয়, তারপর ধরনগুলো।
     */
    public function test_the_standard_chart_gives_the_three_money_mothers_their_kind(): void
    {
        $withKind = Account::query()->whereNotNull('money_kind')->count();

        // ⚠️ সেটআপের দাবি — শূন্য হলে নিচের প্রতিটা দাবি নিজে থেকেই সত্য
        $this->assertGreaterThanOrEqual(3, $withKind,
            'ছক বসার পরও কোনো খাতে টাকার ধরন নেই — দাবিগুলো তখন কিছুই মাপে না।');

        $this->assertSame(Account::CASH, $this->mother(StandardChart::CASH_IN_HAND)->money_kind);
        $this->assertSame(Account::BANK, $this->mother(StandardChart::BANK)->money_kind);
        $this->assertSame(Account::MFS, $this->mother(StandardChart::MOBILE_MONEY)->money_kind);
    }

    /** মায়ের নিচে বসলেই সন্তান ধরনটা পায় — কেউ কিছু না বলেই। */
    public function test_a_new_account_inherits_its_kind_from_the_mother_it_sits_under(): void
    {
        foreach ([
            StandardChart::CASH_IN_HAND => Account::CASH,
            StandardChart::BANK => Account::BANK,
            StandardChart::MOBILE_MONEY => Account::MFS,
        ] as $code => $expected) {
            $child = $this->service()->create([
                'name_en' => 'Child of '.$code,
                'parent_id' => $this->mother($code)->id,

                /*
                 * ⓘ নগদের ক্ষেত্রে নিয়ন্ত্রকের নাম বাধ্যতামূলক
                 * ([[AccountService::assertCashHasAKeeper()]]), তাই
                 * তিনটাতেই পাঠানো হয় — ব্যাংক ও MFS ওটা উপেক্ষা করে।
                 */
                'held_by' => $this->user->id,
            ]);

            $this->assertSame($expected, $child->money_kind,
                "{$code}-এর নিচে বসা খাত {$expected} হওয়ার কথা ছিল।");
        }
    }

    /**
     * ⛔ ইনপুট যা-ই পাঠাক, ধরনটা গাছ থেকেই আসে।
     *
     * ⓘ এটাই পুরনো রোগের আসল ওষুধ: আগে টিকটা ইনপুট ছিল, তাই "ব্যাংক"
     * মাথার নিচে বসিয়ে "নগদ খাত" টিক দেওয়া যেত — আর তখন কোন উত্তরটা
     * সত্যি তা বলার কোনো উপায় ছিল না।
     */
    public function test_the_kind_cannot_be_sent_in_from_outside(): void
    {
        $account = $this->service()->create([
            'name_en' => 'A bank branch that lies',
            'parent_id' => $this->mother(StandardChart::BANK)->id,
            'money_kind' => Account::CASH,
        ]);

        $this->assertSame(Account::BANK, $account->money_kind);
    }

    /** খাতটা অন্য মায়ের নিচে সরালে নিচের সবার ধরনও সরে। */
    public function test_moving_a_branch_moves_the_kind_of_everything_under_it(): void
    {
        $branch = $this->service()->create([
            'name_en' => 'bKash Merchant',
            'parent_id' => $this->mother(StandardChart::BANK)->id,
        ]);

        $this->assertSame(Account::BANK, $branch->money_kind);

        $this->service()->update($branch, [
            'name_en' => 'bKash Merchant',
            'parent_id' => $this->mother(StandardChart::MOBILE_MONEY)->id,
        ]);

        $this->assertSame(Account::MFS, $branch->fresh()->money_kind,
            'মা বদলেছে অথচ ধরন পুরনোই — তাহলে বিকাশের টাকা ব্যাংক বইয়ে দেখাত।');
    }

    /**
     * টাকার খাতের তালিকায় মাথাগুলো আসে না।
     *
     * ⚠️ মা নিজেও ধরনটা বহন করে (নাহলে শিকল ভেঙে যেত), তাই শর্তটা না
     * থাকলে প্রতিটা টাকার খাতের ড্রপডাউনে হঠাৎ তিনটা শিরোনাম বাছাইযোগ্য
     * হয়ে উঠত — আর কেউ সেখানে টাকা বসালে দলের নিচের যোগফল ভুল হত।
     */
    public function test_a_heading_is_never_offered_as_a_money_account(): void
    {
        $leaf = $this->service()->create([
            'name_en' => 'Islami Bank Current',
            'parent_id' => $this->mother(StandardChart::BANK)->id,
        ]);

        $money = Account::query()->money()->pluck('id');

        $this->assertTrue($money->contains($leaf->id));
        $this->assertFalse($money->contains($this->mother(StandardChart::BANK)->id));
        $this->assertFalse($money->contains($this->mother(StandardChart::MOBILE_MONEY)->id));
    }

    /**
     * ⭐ ব্যাংক আর MFS আলাদা — আর এই দাবিটাই পুরো বদলের কারণ।
     *
     * আগে দুইটাই `is_bank` পতাকা পরত, তাই "ব্যাংকে কত আছে" সংখ্যায়
     * বিকাশের টাকা মিশে থাকত, আর একটা MFS খাত দিয়ে ব্যাংক মিলকরণও
     * খোলা যেত — যেখানে মেলানোর কাগজটাই আলাদা।
     */
    public function test_mobile_money_is_not_counted_as_a_bank(): void
    {
        $bank = $this->service()->create([
            'name_en' => 'Islami Bank Current',
            'parent_id' => $this->mother(StandardChart::BANK)->id,
        ]);

        $bkash = $this->service()->create([
            'name_en' => 'bKash Merchant',
            'parent_id' => $this->mother(StandardChart::MOBILE_MONEY)->id,
        ]);

        $banks = Account::query()->ofMoneyKind(Account::BANK)->pluck('id');

        $this->assertTrue($banks->contains($bank->id));
        $this->assertFalse($banks->contains($bkash->id),
            'বিকাশ ব্যাংকের তালিকায় এসেছে — তাহলে ব্যাংক ব্যালেন্সের সংখ্যাটাই মিথ্যা।');

        $this->assertTrue($bank->isBank());
        $this->assertFalse($bkash->isBank());
        $this->assertTrue($bkash->isMfs());

        // দুইটাই টাকার খাত — পার্থক্যটা ধরনে, টাকা ধরায় নয়
        $this->assertTrue($bank->isMoney());
        $this->assertTrue($bkash->isMoney());
    }

    /**
     * ⛔ নগদ খাতে কে ধরবেন সেটা না বললে খাতটা জন্মায় না।
     *
     * ── মালিকের প্রশ্ন ও সংশোধন, ১৩ সেপ্টেম্বর ২০২৬ ───────────────────
     * *"এই অ্যাকাউন্টে নিয়ন্ত্রক কে? সেটাই নাই।"*
     *
     * ছক থেকে ১১০১-এর নিচে খাত বানালে সত্যিকারের টাকা বসত **কারো নামে
     * না**, আর "টাকা ও হেফাজত" পর্দায় সারিটা আসতই না।
     *
     * ⛔ প্রথম সারাইটা ভুল ছিল — নগদ খাত বানানোই বন্ধ করে সবাইকে টিলের
     * পর্দায় পাঠানো হচ্ছিল। মালিক ধরিয়ে দিলেন: *"টিল তো POS-এর জন্য।
     * অফিসে অ্যাকাউন্টসের ক্যাশ কীভাবে হবে?"* ⭐ প্রশ্নটা "কার হাতে",
     * আর টিল তার একটা উত্তর মাত্র।
     */
    public function test_a_cash_account_must_name_who_holds_it(): void
    {
        try {
            $this->service()->create([
                'name_en' => 'A drawer nobody owns',
                'parent_id' => $this->mother(StandardChart::CASH_IN_HAND)->id,
            ]);

            $this->fail('নিয়ন্ত্রকের নাম ছাড়াই নগদ খাত বসে গেল।');
        } catch (ValidationException $e) {
            $this->assertSame(
                __('accounts::validation.cash_needs_a_keeper'),
                $e->errors()['held_by'][0] ?? '',
            );
        }
    }

    /**
     * ⭐ নাম দিলে বসে — আর এটাই দাবিটার অন্য অর্ধেক।
     *
     * ⛔ শুধু উপরেরটা থাকলে একটা "সব নগদ খাত আটকাও" কোডও পাস করত, আর
     * তখন অফিসের সিন্দুকই বানানো যেত না — সারানোটা রোগের চেয়ে খারাপ।
     */
    public function test_an_office_cash_account_is_fine_once_somebody_holds_it(): void
    {
        $office = $this->service()->create([
            'name_en' => 'Office Cash',
            'name_bn' => 'অফিসের নগদ',
            'parent_id' => $this->mother(StandardChart::CASH_IN_HAND)->id,
            'held_by' => $this->user->id,
        ]);

        $this->assertSame(Account::CASH, $office->money_kind);
        $this->assertSame($this->user->id, $office->held_by);

        // ⭐ আর এটা কোনো কাউন্টার নয় — টিল ছাড়াই নগদ ধরা যায়
        $this->assertSame(0, CashTill::query()
            ->where('account_id', $office->id)->count());
    }

    /** ⓘ ব্যাংকে নিয়ন্ত্রক লাগে না — টাকাটা কারও ড্রয়ারে নেই। */
    public function test_a_bank_account_needs_nobody_to_hold_it(): void
    {
        $bank = $this->service()->create([
            'name_en' => 'Islami Bank Current',
            'parent_id' => $this->mother(StandardChart::BANK)->id,
        ]);

        $this->assertSame(Account::BANK, $bank->money_kind);
        $this->assertNull($bank->held_by);
    }

    /**
     * ⭐ কাউন্টার খুললে খাতটা নিয়ন্ত্রকসহ বসে।
     *
     * ⓘ দুই জায়গায় একই তথ্য মনে হলেও প্রশ্ন দুইটা আলাদা: টিলের
     * `holder_id` বলে কাউন্টারটা কার, খাতের `held_by` বলে ঐ টাকাটা
     * কার হাতে। অফিসের সিন্দুকের টিল নেই, তবু তারও একজন থাকেন।
     */
    public function test_a_counter_still_creates_its_own_cash_account(): void
    {
        $before = Account::query()->ofMoneyKind(Account::CASH)->count();

        $till = app(CashTillService::class)->create([
            'name_en' => 'Front Counter',
            'name_bn' => 'সামনের কাউন্টার',
            'holder_id' => $this->user->id,
        ]);

        $this->assertSame($before + 1, Account::query()->ofMoneyKind(Account::CASH)->count());
        $this->assertSame(Account::CASH, $till->account->money_kind);
        $this->assertSame($this->user->id, $till->holder_id);

        // ⭐ প্রশ্নটার উত্তর খাতেও আছে, কেবল টিলে নয়
        $this->assertSame($this->user->id, $till->account->held_by);
    }

    /** টাকার মায়ের বাইরের খাত টাকার খাত নয় — খরচ, বিক্রয়, ভাড়া। */
    public function test_an_ordinary_account_holds_no_money(): void
    {
        $expense = $this->service()->create([
            'name_en' => 'Shop Rent',
            'type' => Account::EXPENSE,
        ]);

        $this->assertNull($expense->money_kind);
        $this->assertFalse($expense->isMoney());
    }
}
