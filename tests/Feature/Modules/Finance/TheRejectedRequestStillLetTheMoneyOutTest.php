<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Finance\Models\Withdrawal;
use App\Modules\Finance\Services\WithdrawalService;
use App\Modules\MasterData\Models\Person;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ব্যবস্থাপক "না" বলেছিলেন, আর টাকাটা তবু বেরিয়ে গেল।
 *
 * ── ⛔ যা ভাঙা ছিল, ২৭ সেপ্টেম্বর ২০২৬ ──────────────────────────────────
 * [[WithdrawalService::post()]] অনুমোদনের পাহারাটা এভাবে লিখত:
 *
 *     if ($pending !== null && $pending->isPending()) { … আটকাও … }
 *
 * ⓘ [[Approval::isPending()]] সত্য হয় **কেবল** `pending` অবস্থায়।
 * ⚠️ অর্থাৎ প্রশ্নটা ছিল *"সিদ্ধান্ত কি এখনো বাকি?"*, অথচ প্রশ্নটা হওয়া
 * উচিত ছিল *"সিদ্ধান্তটা কি হ্যাঁ?"*।
 *
 * ⛔ ফলে `rejected` সারিটা পাহারার ফাঁক দিয়ে বেরিয়ে যেত: ব্যবস্থাপক
 * স্পষ্ট করে "না" লিখে দেওয়ার **পরেই** পোস্ট করা যেত, আর ভাউচার,
 * খতিয়ান, সব বসে যেত।
 *
 * ── ⚠️ কেন এটা নীরব ছিল ────────────────────────────────────────────────
 * ⓘ প্রত্যাখ্যানের পথটা সবুজ দেখাত সবখানে: অনুরোধ বাতিল হয়েছে, খবরও
 * গেছে, ইনবক্সে সারিটা নেই। ⛔ কেবল কাগজটা তখনো খসড়া অবস্থায় বসে থাকত
 * আর "খাতায় বসান" বোতামটা কাজ করত — অর্থাৎ ভাঙনটা ছিল **অনুপস্থিত একটা
 * জোড়ে**, ভুল কোনো হিসাবে নয়।
 *
 * ⚠️ আর নিরীক্ষায় ধরাও পড়ত না: ভাউচারটা দেখতে হুবহু অনুমোদিত উত্তোলনের
 * মতো, কারণ প্রত্যাখ্যানটা লেখা থাকে অন্য টেবিলে।
 *
 * ── ⓘ এই ফাইলটা কী মাপে, আর কী মাপে না ─────────────────────────────────
 * ⭐ মাপে: সবশেষ সিদ্ধান্ত `rejected` হলে `post()` আটকায়, আর আটকানোর পর
 *    খাতায় **কিছুই** বসে না (ভাউচার নেই, খতিয়ান নেই, সারিটা খসড়াই)।
 * ⭐ মাপে: `pending` অবস্থাটা আগের মতোই আটকানো থাকে — সারাইটা যেন পুরনো
 *    পাহারাটা কেড়ে না নেয়।
 * ⛔ মাপে না: প্রবাহ **বসানোই নেই** (`latestFor()` → `null`) সেই দশাটা।
 *    ওটা আজ সরাসরি পোস্ট হয়, আর নিরীক্ষার §১.৩-এ ওটা আলাদাভাবে বদলাচ্ছে।
 *    ⚠️ আজকের আচরণটা এখানে দাবি করে বসালে ঐ সারাইয়ের দিনে এই ফাইলটা
 *    মিথ্যা লাল হত, তাই কথাটা লেখা আছে, দাবি করা নেই।
 * ⛔ মাপে না: `cancelled` অবস্থা — বাতিল করা অনুরোধ পর্দা থেকে আসে না,
 *    আর সেটা আলাদা প্রশ্ন।
 */
final class TheRejectedRequestStillLetTheMoneyOutTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Person $partner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->owner->switchCompany((int) $company->id);
        $this->be($this->owner);

        // ⚠️ কার নামে তোলা — একজন মানুষ ছাড়া অনুরোধই বসে না
        $this->partner = Person::query()->create([
            'code' => 'WDR-REJ',
            'name_en' => 'Rejected partner',
            'name_bn' => 'Rejected partner',
            'is_active' => true,
        ]);

        /*
         * ⓘ ডেমোতে কেবল `sales.discount`-এর ছক বসানো আছে, তাই উত্তোলনের
         * ছকটা এখানেই বসাতে হয় — নাহলে [[ApprovalEngine::request()]]
         * `null` ফেরাত আর দাবিগুলো **কিছুই মাপত না**, তবু সবুজ থাকত।
         *
         * ⭐ সইকারী মালিক নিজেই, আর তিনি super_admin — তাই নিজের অনুরোধে
         * নিজে সিদ্ধান্ত দিতে পারেন ([[ApprovalEngine::isSuperAdmin()]])।
         * ⚠️ দ্বিতীয় একজন বানালে তাঁর রোল আর কোম্পানি-বরাদ্দ হাতে বসাতে
         * হত, আর ভুল হলে লালটা আসত `canDecide()` থেকে — সারাইয়ের সাথে
         * যার কোনো সম্পর্ক নেই।
         */
        $flow = ApprovalFlow::query()->create([
            'module' => 'finance',
            'action' => 'withdrawal',
            'threshold_amount' => '1000.0000',
        ]);

        ApprovalFlowStep::query()->create([
            'approval_flow_id' => $flow->id,
            'level' => 1,
            'approver_type' => ApprovalFlowStep::BY_USER,
            'approver_id' => $this->owner->id,
        ]);

        /*
         * ⛔ ইঞ্জিনটা `scoped`, আর ছকগুলো ভেতরে জমিয়ে রাখে। ⚠️ বীজ
         * বপনের সময় কেউ একবার ইঞ্জিনটা চেয়ে থাকলে উপরের নতুন ছকটা
         * ঐ জমানো তালিকায় নেই — আর তখন অনুরোধই তৈরি হত না।
         */
        $this->app->forgetInstance(ApprovalEngine::class);
    }

    // ── আসল দাবিটা ─────────────────────────────────────────────────────

    /**
     * ⛔ "না" বলা অনুরোধের টাকা খাতায় বসে না।
     */
    public function test_a_rejected_withdrawal_cannot_be_posted(): void
    {
        $withdrawal = $this->aRequestFor('5000');

        $approval = app(ApprovalEngine::class)->latestFor($withdrawal, 'withdrawal');

        /*
         * ⓘ প্রথমে প্রমাণ করা হয় অনুরোধটা সত্যিই তৈরি হয়েছে — নাহলে
         * নিচের প্রত্যাখ্যানটা কিছুতেই বসত না, আর দাবিটা একটা
         * অনুমোদনহীন উত্তোলন মাপত।
         */
        $this->assertNotNull($approval, 'উত্তোলনের অনুরোধে কোনো অনুমোদন চাওয়াই হয়নি।');
        $this->assertTrue($approval->isPending());

        app(ApprovalEngine::class)->reject($approval, $this->owner, 'এখন দরকার নেই');

        $this->assertSame(Approval::REJECTED, $approval->fresh()->status,
            'প্রত্যাখ্যানটাই বসেনি — তাহলে নিচের দাবিটা অন্য কিছু মাপছে।');

        // ⓘ আগের অবস্থাটা গোনা হয় — ডেমোর নিজের ভাউচারগুলোও খাতায় আছে
        $vouchersBefore = Voucher::query()->count();
        $entriesBefore = LedgerEntry::query()->count();

        try {
            app(WithdrawalService::class)->post($withdrawal->fresh(), $this->cashAccount());

            $this->fail('ব্যবস্থাপক "না" বলার পরেও টাকাটা খাতায় বসে গেছে।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors(),
                'আটকেছে, কিন্তু অন্য কোনো ঘরের নিয়মে।');
        }

        /*
         * ⭐ ব্যতিক্রমটাই যথেষ্ট প্রমাণ নয়।
         *
         * ⚠️ `post()`-এর ভেতরে ভাউচার বসানো আর সারি হালনাগাদ একই
         * লেনদেনে — কিন্তু পাহারাটা ভুল জায়গায় বসালে ভাউচার বসে
         * যেত আর ছোঁড়াটা হত তার **পরে**। ⓘ তখন ব্যতিক্রম দেখে
         * দাবিটা সবুজ হত, অথচ খাতায় একটা এতিম দাখিলা পড়ে থাকত।
         */
        $this->assertSame($vouchersBefore, Voucher::query()->count(),
            'আটকানোর পরেও একটা ভাউচার তৈরি হয়েছে।');

        $this->assertSame($entriesBefore, LedgerEntry::query()->count(),
            'আটকানোর পরেও খতিয়ানে দাখিলা বসেছে।');

        $fresh = $withdrawal->fresh();

        $this->assertFalse($fresh->isPosted(), 'সারিটা নিজেকে "খাতায় বসেছে" বলছে।');
        // ⭐ "না" পাওয়া অনুরোধ বাতিল — আর খসড়া হয়ে ঝুলে থাকে না (অডিট ম২৮, ৪ অক্টোবর ২০২৬)
        $this->assertSame(DocumentStatus::CANCELLED, $fresh->status, '⛔ "না" পাওয়া অনুরোধ এখনো খসড়া — মাসের সীমায় গোনা হচ্ছে।');
        $this->assertNull($fresh->voucher_id, 'সারিটা একটা ভাউচারের দিকে তাকিয়ে আছে।');
        $this->assertNull($fresh->posted_at);
    }

    /**
     * ⭐ ঝুলে থাকা অনুরোধ আগের মতোই আটকানো।
     *
     * ── ⚠️ কেন এই দাবিটা লাগে ──────────────────────────────────────────
     * উপরের সারাইটা ঠিক ঐ শর্তটাই বদলায় যেটা আজ `pending` আটকায়। ⛔
     * শর্তটা অসাবধানে লিখলে প্রত্যাখ্যান আটকাত আর **ঝুলে থাকা অনুরোধ
     * ছেড়ে দিত** — অর্থাৎ একটা ফাঁক বন্ধ করে আরেকটা খোলা।
     *
     * ⓘ তাই এটা সারাইয়ের আগে-পরে দুইবারই সবুজ থাকার কথা।
     */
    public function test_a_pending_withdrawal_is_still_refused(): void
    {
        $withdrawal = $this->aRequestFor('7000');

        $approval = app(ApprovalEngine::class)->latestFor($withdrawal, 'withdrawal');

        $this->assertNotNull($approval, 'উত্তোলনের অনুরোধে কোনো অনুমোদন চাওয়াই হয়নি।');
        $this->assertTrue($approval->isPending());

        try {
            app(WithdrawalService::class)->post($withdrawal->fresh(), $this->cashAccount());

            $this->fail('অনুমোদন ঝুলে থাকা অবস্থায়ও টাকাটা খাতায় বসে গেছে।');
        } catch (ValidationException $e) {
            $this->assertSame(
                [__('finance::validation.withdrawal_awaits_approval', [
                    'no' => $withdrawal->document_no,
                ])],
                $e->errors()['status'] ?? [],
                'আটকেছে, কিন্তু "অনুমোদনের অপেক্ষায়" বার্তাটা দিয়ে নয়।',
            );
        }

        $this->assertFalse($withdrawal->fresh()->isPosted());
    }

    // ── হাতিয়ার ────────────────────────────────────────────────────────

    private function aRequestFor(string $amount): Withdrawal
    {
        return app(WithdrawalService::class)->request([
            'person_id' => $this->partner->id,
            'kind' => Withdrawal::DRAWING,
            'in_kind' => 'cash',
            'trx_date' => now()->toDateString(),
            'amount' => $amount,
            'reason' => 'অনুমোদনের পরীক্ষা',
        ]);
    }

    /**
     * ⛔ `CASH_IN_HAND` ('1101') একটা **মাথা**, পোস্টযোগ্য খাত নয় — তাই
     * ছাঁকনিতে `is_group = false`, নাহলে পাহারা অন্য নিয়মে আটকাত আর
     * দাবিটা ভুল কারণে সবুজ হত।
     */
    private function cashAccount(): Account
    {
        return Account::query()->money()->where('is_group', false)->firstOrFail();
    }
}
