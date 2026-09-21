<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\VoucherApproval;
use App\Modules\Accounts\Services\VoucherService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * নগদ কেবল নিজের ক্যাশবাক্সে।
 *
 * ── ⭐ মালিকের নিয়ম, ২১ সেপ্টেম্বর ২০২৬ ──────────────────────────────
 * *"cash e sudu tar nijer cash accounts e taka nite parbe, tai app er
 * dorkar nai — din sese emnite tar kachtekei buje nibe"*।
 *
 * ── ⚠️ এই পাহারাটা একটা শর্ত, একটা সুবিধা নয় ────────────────────────
 * মালিক চান নগদ আদায়ে **অনুমোদন না লাগুক** (ব্যাংক/MFS-এ লাগবে)। ⓘ সেই
 * ঢিল দেওয়াটা তখনই নিরাপদ, যখন টাকাটা সত্যিই **তাঁর নিজের বাক্সেই**
 * যায় — নাহলে দিন শেষে মেলানোর সময় গরমিলটা ধরাই পড়ে না।
 *
 * ⛔ তাই ক্রমটা উল্টো করা হয়েছে: **আগে এই সীমা, পরে অনুমোদন তোলা।**
 * ⚠️ উল্টোটা করলে একটা জাল তুলে নেওয়া হত আর বদলে কিছু বসত না — আজ
 * সকালেই ঠিক ঐ আকারের এগারোটা মরা "নিশ্চিত করবেন?" পাওয়া গেছে।
 *
 * ── ⓘ তালা দুই জায়গায়, আর কারণটা পুরনো ─────────────────────────────
 * ফর্মে তালিকাটা ছাঁকা হয়, কিন্তু সেটা কেবল **দেখানো**। ⛔ এই অ্যাপে
 * একবার রপ্তানি "বন্ধ" করা হয়েছিল শুধু বোতাম লুকিয়ে, আর ঠিকানা টাইপ
 * করলেই ফাইল নামত। তাই সার্ভারেও তালা, আর এই ফাইলটা সেটাই মাপে।
 */
final class CashOnlyLandsInYourOwnTillTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->user);

        /*
         * ⛔ ছকটা পরীক্ষা নিজেই বানায়, আর এটাই এই ফাইলের সবচেয়ে দামি লাইন।
         *
         * ⚠️ প্রথমে ছক ছাড়াই দাবিগুলো লেখা হয়েছিল, আর সেগুলো **ছাড়
         * বন্ধ করে দিলেও সবুজ থাকত**: ছক না থাকলে কিছুই আটকায় না, তাই
         * `stopping()` এমনিতেই `null` ফেরাত।
         *
         * ⓘ অর্থাৎ দাবিটা "নগদে সই লাগে না" প্রমাণ করত না — প্রমাণ করত
         * "এই ডেটাবেসে কোনো অনুমোদনই নেই"। ⭐ লাইভে ছকটা **চালু আছে**
         * (accounts/receipt), তাই পরীক্ষাটাও সেই অবস্থাতেই চলা উচিত।
         */
        $flow = ApprovalFlow::create([
            'company_id' => $this->company->id,
            'module' => 'accounts',
            'action' => 'receipt',
            'is_active' => true,
        ]);

        ApprovalFlowStep::create([
            'approval_flow_id' => $flow->id,
            'level' => 1,
            'approver_type' => 'user',
            'approver_id' => $this->user->id,
        ]);
    }

    public function test_cash_into_my_own_till_is_allowed(): void
    {
        $mine = $this->cashAccount('CASH-MINE');
        $this->till($mine, $this->user->id);

        $voucher = $this->receiptInto($mine, '500');

        $this->assertNotNull($voucher->id, 'নিজের বাক্সে নগদ বসাতেই দেওয়া হয়নি।');
    }

    /**
     * ⛔ আর অন্য কারও বাক্সে নয় — এটাই আসল দাবি।
     *
     * ⓘ উপরেরটা বলে কী চলে; এটা বলে **কী চলে না**। ⚠️ কেবল প্রথমটা
     * থাকলে কেউ শর্তটা তুলে দিলেও সবুজ থাকত।
     */
    public function test_cash_into_somebody_elses_till_is_refused(): void
    {
        $mine = $this->cashAccount('CASH-MINE');
        $this->till($mine, $this->user->id);

        $theirs = $this->cashAccount('CASH-THEIRS');
        $other = User::query()->whereKeyNot($this->user->id)->firstOrFail();
        $this->till($theirs, $other->id);

        $this->expectException(ValidationException::class);

        $this->receiptInto($theirs, '500');
    }

    /**
     * ⚠️ আর যাঁর কোনো বাক্স নেই, তিনি নগদ নিতেই পারেন না।
     *
     * ⓘ বাক্স ছাড়া মানুষের হাতে নগদ গেলে *"টাকাটা কার হেফাজতে"*
     * প্রশ্নের কোনো উত্তর থাকে না। ⛔ পর্দা তখন বলে দেয় ব্যাংক বা MFS
     * বাছতে — থামা আর দরজা বন্ধ করা এক জিনিস নয়।
     */
    public function test_a_user_with_no_till_cannot_take_cash(): void
    {
        /*
         * ⚠️ নিয়মটা জাগাতে হয়: অন্তত একটা বাক্স কারও নামে বসলে তবেই
         * সে খাটে। ⛔ শর্তটা ছাড়া দাবিটা লেখা ছিল আর লাল হলো — ডেমোর
         * কোনো বাক্সেরই মালিক নেই। ⓘ ভুলটা কোডের নয়, দাবির।
         */
        $this->till($this->cashAccount('CASH-SOMEONE'), User::query()
            ->whereKeyNot($this->user->id)->firstOrFail()->id);

        $any = $this->cashAccount('CASH-MINE');

        $this->expectException(ValidationException::class);

        $this->receiptInto($any, '500');
    }

    /**
     * ⭐ সীমাটা কেবল নগদে — ব্যাংক ও MFS অচ্ছুত নয়।
     *
     * ⓘ ওখানে টাকা প্রতিষ্ঠানের খাতেই যায় আর অনুমোদন থাকছে, তাই
     * তালিকা ছাঁকার কারণ নেই। ⚠️ এই দাবিটা না থাকলে কেউ নিয়মটা সব
     * টাকার খাতে বসিয়ে দিলে কিছুই লাল হত না, আর ব্যাংকে আদায় করাই
     * বন্ধ হয়ে যেত।
     */
    public function test_a_bank_account_is_not_restricted(): void
    {
        $bank = $this->bankAccount();

        $voucher = $this->receiptInto($bank, '500');

        $this->assertNotNull($voucher->id, 'ব্যাংকে আদায় আটকে গেছে — সীমাটা কেবল নগদের কথা।');
    }

    /**
     * ⭐ নিজের বাক্সে নগদ — সই লাগে না (ধাপ ২)।
     *
     * ⓘ মালিকের নিয়ম: *"cash e ... app er dorkar nai — din sese
     * emnite tar kachtekei buje nibe"*।
     *
     * ⚠️ ছাড়টা একা দাঁড়ায় না: উপরের দাবিগুলোই তার শর্ত — নগদ কেবল
     * নিজের বাক্সে যেতে পারে। ⛔ শর্ত ছাড়া ছাড় দিলে যে কেউ যেকোনো
     * ক্যাশ খাতে টাকা বসিয়ে সই ছাড়াই পোস্ট করে ফেলত।
     */
    public function test_cash_into_my_own_till_needs_no_signature(): void
    {
        $mine = $this->cashAccount('CASH-MINE');
        $this->till($mine, $this->user->id);

        $voucher = $this->receiptInto($mine, '500');

        $this->assertNull(app(VoucherApproval::class)->stopping($voucher),
            '⛔ নিজের বাক্সে নগদ নিতেও সই চাওয়া হচ্ছে।');
    }

    /**
     * ⛔ আর ব্যাংক বা MFS-এ সই আগের মতোই — এটাই দাবির অন্য অর্ধেক।
     *
     * ⓘ ওখানে টাকা প্রতিষ্ঠানের খাতে যায়, কারও নিজের বাক্সে নয়।
     * ⚠️ কেবল "নগদে ছাড়" মাপলে কেউ শর্তটা তুলে সব রসিদে ছাড় দিলেও
     * সবুজ থাকত, আর ব্যাংকের টাকা নীরবে সই ছাড়া বসত।
     */
    public function test_a_bank_receipt_still_needs_its_signature(): void
    {
        $bank = $this->bankAccount();

        $voucher = $this->receiptInto($bank, '500');

        $this->assertNotNull(app(VoucherApproval::class)->stopping($voucher),
            '⛔ ব্যাংকে আদায় সই ছাড়াই এগিয়ে যাচ্ছে।');
    }

    /**
     * ⭐ কারও নামে বাক্স বসানো না থাকলে নিয়মটা ঘুমায়।
     *
     * ── ⛔ লাইভে যা হয়েছিল, ২১ সেপ্টেম্বর ২০২৬ ─────────────────────
     * নিয়মটা কড়া করে বসানোর পর মালিক **নগদই বাছতে পারলেন না**:
     * তিনটা ক্যাশবাক্সের একটারও `holder_id` বসানো ছিল না, তাই "নিজের
     * বাক্স" বলে কিছুই মিলত না আর ড্রপডাউনে কেবল ব্যাংক থাকত।
     *
     * ⚠️ একটা পাহারা যা কাজটাই বন্ধ করে দেয়, সেটা পাহারা নয় — কয়েক
     * দিনেই কেউ ওটা তুলে দেয়। ⭐ তাই নিয়মটা তখনই জাগে যখন ব্যবসা
     * সেটা ব্যবহার শুরু করে: অন্তত একটা বাক্স কারও নামে বসলে।
     */
    public function test_the_rule_sleeps_until_a_till_has_a_holder(): void
    {
        CashTill::query()->update(['holder_id' => null]);

        $any = $this->cashAccount('CASH-ANY');

        $voucher = $this->receiptInto($any, '500');

        $this->assertNotNull($voucher->id, implode('
', [
            '⛔ কেউ বাক্সের মালিক না হয়েও নগদ আটকে গেছে।',
            '',
            '⚠️ তখন কেউ নগদ নিতেই পারেন না, আর পাহারাটা কাজের বদলে বাধা হয়।',
        ]));
    }

    /**
     * একটা নগদ খাত — পরীক্ষা নিজেই বানায়।
     *
     * ⚠️ প্রথমে ডেমোর খাতগুলো ব্যবহার করা হয়েছিল, আর চারটা দাবিই
     * **এড়িয়ে গেল**: ডেমোতে দুইটা নগদ খাত নেই। ⛔ সবুজ ফল, শূন্য
     * প্রমাণ — ঠিক যে ফাঁদটা এই ফাইলের মাথায় লেখা আছে।
     *
     * ⓘ `forceFill` কারণ ঘরগুলো fillable নয়। ⚠️ মডেলে ওগুলো fillable
     * করে দেওয়া যেত, কিন্তু সেটা **পাহারার সুবিধার জন্য আসল কোড ঢিলে
     * করা** — আর তখন অন্য কোথাও অনিচ্ছাকৃত বসানো সম্ভব হত।
     *
     * ⛔ আর "পোস্টযোগ্য" বলে কোনো কলাম নেই: `postable()` দেখে `is_group`
     * মিথ্যা কি না। ⓘ প্রথমে `is_postable` লিখে থেমেছি — স্কোপের নাম
     * আর কলামের নাম এক ধরে নেওয়া।
     */
    private function cashAccount(string $code): Account
    {
        $sibling = Account::query()
            ->where('money_kind', Account::CASH)
            ->postable()
            ->firstOrFail();

        /*
         * ⓘ ভাইটার প্রতিটা ঘর নকল করা হয়, ঘর ধরে ধরে আন্দাজ নয়।
         *
         * ⚠️ প্রথমে হাতে কয়েকটা ঘর লিখে তিনবার থেমেছি — `is_postable`
         * কলামই নয়, তারপর `nature`-এর ডিফল্ট নেই। ⛔ প্রতিবার একটা
         * করে ঘর যোগ করা মানে ডেটাবেসের গড়নটা আন্দাজে লেখা, আর পরের
         * মাইগ্রেশনেই আবার ভাঙত।
         *
         * ⭐ যে খাতটা ইতিমধ্যে বৈধ, তার ঘরগুলোই সত্য — কেবল পরিচয়টুকু
         * বদলে দেওয়া হয়।
         */
        $account = $sibling->replicate(['public_id']);

        $account->forceFill([
            'code' => $code,
            'name_en' => $code,
            'name_bn' => $code,
        ])->save();

        return $account;
    }

    /**
     * ⭐ একটা ব্যাংক খাত — পরীক্ষা নিজেই বানায়, ২১ সেপ্টেম্বর ২০২৬।
     *
     * ── ⛔ আগে এখানে `markTestSkipped` ছিল, আর সেটাই ফাঁদ ─────────────
     * ডেমোতে একটা ব্যাংক খাত আছে, কিন্তু সেটা **পোস্টেবল নয়** — তাই
     * উপরের দুইটা দাবি লেখা হওয়ার দিন থেকে **একবারও চলেনি**।
     *
     * ⚠️ আর রিপোর্টে এড়ানো দাবি আর সবুজ দাবি প্রায় একরকম দেখায়:
     * চারটা সুট চালিয়ে "passed" পাওয়া গেছে, অথচ ৩১টার ৩টা এড়ানো।
     * ⓘ যে নিয়ম কোনোদিন চলেনি, সে নিয়ম নয় — আশা।
     *
     * ⓘ নিচের [[cashAccount()]]-এর মতোই একটা বৈধ ভাইকে নকল করা হয়,
     * কেবল ধরনটা ব্যাংক করে — ঘর ধরে ধরে আন্দাজ করলে পরের
     * মাইগ্রেশনেই ভাঙত।
     */
    private function bankAccount(): Account
    {
        $existing = Account::query()
            ->where('money_kind', Account::BANK)
            ->postable()
            ->active()
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $account = $this->cashAccount('BANK-TEST');

        $account->forceFill(['money_kind' => Account::BANK])->save();

        return $account->refresh();
    }

    private function till(Account $account, int $holderId): CashTill
    {
        return CashTill::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->company->defaultBranch()?->id,
            'account_id' => $account->id,
            'code' => 'TILL-'.$account->code,
            'name_en' => 'Till '.$account->code,
            'holder_id' => $holderId,
            'is_active' => true,
        ]);
    }

    /**
     * একটা রসিদ বানিয়ে **খাতায় বসানো**।
     *
     * ⚠️ কেবল `create()` ডাকলে দাবিগুলো কিছুই প্রমাণ করত না: তালাটা
     * `post()`-এ, আর সেটাই ঠিক জায়গা — খসড়া কাগজ টাকা নাড়ায় না।
     * ⓘ প্রথম চালে ঠিক এই কারণেই দুইটা দাবি লাল হয়েছিল, আর ভুলটা
     * পাহারার ছিল, কোডের নয়।
     */
    private function receiptInto(Account $money, string $amount): Voucher
    {
        /*
         * ⚠️ বিপরীত লাইনটা **টাকার খাত হতে পারে না** — আর এটা শেখা হয়েছে
         * দাবিটা প্রথমবার সত্যিই চলার দিনে, ২২ সেপ্টেম্বর ২০২৬।
         *
         * ⛔ আগে এখানে প্রথম পোস্টেবল খাতটাই নেওয়া হত, আর ডেমোতে সেটা
         * "Main Counter" — একটা **নগদ** খাত। ⓘ ছাড়ের নিয়মটা দেখে
         * *"কোনো একটা লাইন নগদে পড়ছে কি না"*, তাই ব্যাংকের রসিদটাও
         * ছাড় পেয়ে যেত — অথচ দাবিটা ঠিক উল্টোটা মাপতে বসানো।
         *
         * ⓘ আসল রসিদে বিপরীত দিকটা পার্টি বা আয়ের খাত, টাকার খাত নয়।
         */
        $other = Account::query()
            ->postable()
            ->active()
            ->whereKeyNot($money->id)
            ->whereNull('money_kind')
            ->firstOrFail();

        /*
         * ⓘ ব্যাংক বা MFS হলে লেনদেন নম্বরটা লাগে — নাহলে সেবা নিজেই
         * আটকায় ([[VoucherService]], `bank_reference_required`)।
         *
         * ⚠️ এটা লেখার দরকার পড়ল কেবল আজ, যেদিন দাবিদুটো প্রথমবার
         * সত্যিই চলল। ⓘ এতদিন ওরা `markTestSkipped`-এ পালাত, তাই
         * ফিক্সচারের এই ফাঁকটা কারও চোখে পড়েনি।
         */
        $bankLike = in_array($money->money_kind, [Account::BANK, Account::MFS], true);

        $voucher = app(VoucherService::class)->create([
            'type' => Voucher::RECEIPT,
            'trx_date' => now()->toDateString(),
            'narration' => 'পরীক্ষা',
            'instrument_no' => $bankLike ? 'TRX-TEST-77' : null,
        ], [
            ['account_id' => $money->id, 'debit' => $amount, 'credit' => '0'],
            ['account_id' => $other->id, 'debit' => '0', 'credit' => $amount],
        ]);

        return app(VoucherService::class)->post($voucher);
    }
}
