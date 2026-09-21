<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Accounts\Models\Voucher;
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
        $bank = Account::query()->where('money_kind', Account::BANK)->postable()->active()->first();

        if ($bank === null) {
            $this->markTestSkipped('ডেমোতে কোনো ব্যাংক খাত নেই।');
        }

        $voucher = $this->receiptInto($bank, '500');

        $this->assertNotNull($voucher->id, 'ব্যাংকে আদায় আটকে গেছে — সীমাটা কেবল নগদের কথা।');
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
        $other = Account::query()->postable()->active()->whereKeyNot($money->id)->firstOrFail();

        $voucher = app(VoucherService::class)->create([
            'type' => Voucher::RECEIPT,
            'trx_date' => now()->toDateString(),
            'narration' => 'পরীক্ষা',
        ], [
            ['account_id' => $money->id, 'debit' => $amount, 'credit' => '0'],
            ['account_id' => $other->id, 'debit' => '0', 'credit' => $amount],
        ]);

        return app(VoucherService::class)->post($voucher);
    }
}
