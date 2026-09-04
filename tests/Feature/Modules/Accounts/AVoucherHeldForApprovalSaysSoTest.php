<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\ApprovalCenter\Models\Approval;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * অনুমোদনে আটকানো ভাউচার — পর্দা চুপ করে থাকত।
 *
 * ── কী ঘটত ───────────────────────────────────────────────────────────
 * খরচে অনুমোদনের প্রবাহ বসানো থাকলে "Post now" চাপলে ভাউচারটা খাতায়
 * যেত না, বরং অনুমোদনের সারিতে বসত। কন্ট্রোলার সেটা বলত —
 * `back()->with('warning', …)` দিয়ে।
 *
 * ⛔ **কিন্তু `warning` এই অ্যাপের একটা পাতাতেও রেন্ডার হত না।**
 * মেপে দেখা গিয়েছিল: পাঠায় ১ জায়গা, দেখায় ০ জায়গা।
 *
 * ⚠️ ফলে ব্যবহারকারীর কাছে ঘটনাটা এই রকম দেখাত:
 *
 *     বোতাম চাপলাম  →  পাতা রিলোড হলো  →  এখনো "খসড়া"  →  কেন?
 *
 * আর স্বাভাবিকভাবেই তিনি **আবার চাপতেন** — প্রতিবার একটা করে নতুন
 * অনুমোদনের সারি তৈরি হত। ⓘ কাজটা ঠিকই হচ্ছিল, কেবল কেউ জানত না।
 *
 * ── কেন এটা কোনো টেস্টে ধরা পড়ত না ───────────────────────────────────
 * সার্ভারের দিক থেকে সবকিছু নিখুঁত: সঠিক status, সঠিক approval সারি,
 * সঠিক flash key। **হারিয়ে যেত কেবল শেষ ধাপে** — ব্লেডে, যেখানে key-টা
 * কেউ পড়ত না। ⚠️ তাই এই পাহারাটা HTTP উত্তরের **শরীর** দেখে, session
 * নয়: session-এ বার্তাটা থাকা আর পর্দায় দেখানো এক জিনিস নয়, আর ঠিক
 * ওই ফাঁকেই বাগটা ছয় মাস বেঁচে ছিল।
 */
final class AVoucherHeldForApprovalSaysSoTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        /*
         * কোম্পানিটা **ব্যবহারকারীর কাছ থেকে** নেওয়া হয়, কোড ধরে নয়।
         *
         * ⚠️ এখানে আগে একটা ভুল ব্যাখ্যা লেখা ছিল — *"DemoSeeder
         * মানুষগুলোকে অন্য কোম্পানিতে বসায়"*। **সেটা মিথ্যা ছিল, আর
         * আমি যাচাই না করেই লিখেছিলাম**: সিডারের `$alpha` ভেরিয়েবলটার
         * কোডই `TDEPOT` (DemoSeeder:47-48), তাই কোনো অমিল কোনোদিন
         * ছিল না।
         *
         * ⓘ তবু ব্যবহারকারীর কোম্পানিই ধরা হয়, আর কারণটা আলাদা: HTTP
         * অনুরোধে কোম্পানি ঠিক হয় লগইন করা মানুষের
         * `current_company_id` থেকে। **সিডার কাল অন্য কোম্পানি বাছলে
         * এই টেস্ট তবু চলবে**, কারণ সে কিছু ধরে নেয় না।
         *
         * ⛔ আসল লালটা ছিল অন্য কারণে — `back()`-এর referer, যা
         * `from(...)` দিয়ে সারানো হয়েছে।
         */
        $company = Company::query()->findOrFail($this->owner->current_company_id);

        CompanyContext::set($company->id, $this->owner->current_branch_id
            ?? $company->defaultBranch()?->id);
    }

    /**
     * অনুমোদনে আটকালে পর্দা কারণটা বলে।
     */
    public function test_the_screen_explains_why_it_did_not_post(): void
    {
        $this->actingAs($this->owner);

        /*
         * ⚠️ ভাউচারটা **আগে** বানানো হয়, প্রবাহটা তার পরে।
         *
         * কারণ প্রবাহ খোঁজা হয় কোম্পানি ধরে, আর কোম্পানিটা ঠিক হয়
         * অনুরোধের ভিতরে — লগইন করা মানুষটার `current_company_id`
         * থেকে। ⛔ টেস্টে বাইরে থেকে `CompanyContext` বসিয়ে ধরে নিলে
         * প্রবাহটা অন্য কোম্পানিতে বসত আর নীরবে কিছুই আটকাত না —
         * টেস্টটা সবুজ হয়েও কিছু মাপত না।
         *
         * ⓘ তাই সত্যটা কাগজ থেকেই নেওয়া হয়: ভাউচার যে কোম্পানিতে
         * বসেছে, প্রবাহও সেখানেই।
         */
        $voucher = $this->draftExpense();

        /*
         * অনুমোদক **অন্য কেউ** — নিজের ভাউচার নিজে অনুমোদন করা যায় না,
         * তাই লেখক নিজেই একমাত্র অনুমোদক হলে ভাউচারটা আটকে থাকত আর
         * এই টেস্ট যা মাপছে তার বদলে অন্য একটা অবস্থা মাপা হত।
         */
        $approver = User::query()
            ->where('id', '!=', $voucher->created_by)
            ->firstOrFail();

        $this->flowRequiring((int) $voucher->company_id, $approver->id);

        /*
         * ⚠️ `from(...)` বাদ দেওয়া যায় না।
         *
         * কন্ট্রোলার `back()` দেয়, আর `back()` পথ ঠিক করে referer
         * থেকে। ⛔ টেস্টে referer না দিলে সে রুট-এ ফেরে — আমি
         * তখন ভাউচারের পাতা নয়, ড্যাশবোর্ড পড়তাম, আর বার্তাটা
         * না পেয়ে গার্ডটা ভুল কারণে লাল হত। ⓘ ব্রাউজার referer দেয়,
         * তাই এটা বাস্তবতার অনুকরণ, কোনো সুবিধা নয়।
         */
        $body = $this->from(route('accounts.voucher.show', $voucher))
            ->followingRedirects()
            ->post(route('accounts.voucher.post', $voucher))
            ->assertOk()
            ->getContent();

        /*
         * ⚠️ এখানেই আসল যাচাই — session-এ আছে কি না নয়, **পাতায় ছাপা
         * হয় কি না**। ওই দুইটার তফাতই ছিল বাগটা।
         */
        $expected = __('accounts::message.voucher_approval_pending', [
            'no' => $voucher->fresh()->document_no,
        ]);

        $this->assertStringContainsString(
            e($expected),
            $body,
            'অনুমোদনে আটকানো ভাউচারের পাতা কোনো কারণ দেখায়নি — '
            .'ব্যবহারকারী আবার বোতাম চাপবেন, আর আরেকটা অনুমোদন সারি হবে।',
        );

        $this->assertSame(
            Voucher::DRAFT,
            $voucher->fresh()->status,
            'অনুমোদনের অপেক্ষায় থাকা ভাউচার খাতায় ঢুকে পড়েছে।',
        );
    }

    /**
     * অনুমোদন না লাগলে আগের মতোই — সফল বার্তা, আর ভাউচার খাতায়।
     *
     * ⓘ এটা না থাকলে উপরের টেস্টটা "সব ভাউচার আটকে দাও" লিখেও সবুজ
     * হত।
     */
    public function test_without_a_flow_it_posts_as_before(): void
    {
        $this->actingAs($this->owner);

        $voucher = $this->draftExpense();

        $this->from(route('accounts.voucher.show', $voucher))
            ->followingRedirects()
            ->post(route('accounts.voucher.post', $voucher))
            ->assertOk()
            ->assertSee(e(__('accounts::message.voucher_posted', [
                'no' => $voucher->fresh()->document_no,
            ])), false);

        $this->assertSame(
            Voucher::POSTED,
            $voucher->fresh()->status,
            'অনুমোদন লাগে না, তবু ভাউচারটা খাতায় যায়নি।',
        );
    }

    /**
     * খরচে অনুমোদনের প্রবাহ — এক ধাপ, একজন অনুমোদক।
     */
    private function flowRequiring(int $companyId, int $approverId): void
    {
        $flowId = DB::table('approval_flows')->insertGetId([
            'company_id' => $companyId,
            'module' => 'accounts',
            'action' => 'expense',
            'document_type' => '',
            'threshold_amount' => null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
            'public_id' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        DB::table('approval_flow_steps')->insert([
            'approval_flow_id' => $flowId,
            'level' => 1,
            'approver_type' => 'user',
            'approver_id' => $approverId,
            'requires_all' => false,
            'created_at' => now(),
            'updated_at' => now(),
            'public_id' => (string) \Illuminate\Support\Str::uuid(),
        ]);
    }

    /**
     * একটা খসড়া খরচ ভাউচার — পর্দা যেভাবে পাঠায় সেভাবেই।
     */
    private function draftExpense(): Voucher
    {
        $this->post(route('accounts.voucher.store', Voucher::EXPENSE), [
            'type' => Voucher::EXPENSE,
            'trx_date' => now()->toDateString(),
            'from_account_id' => $this->anyMoneyAccount(),
            'to_account_id' => $this->anyExpenseAccount(),
            'amount' => '750',
            'narration' => 'APPROVAL-GUARD',
        ])->assertSessionHasNoErrors()->assertRedirect();

        return Voucher::query()->latest('id')->firstOrFail();
    }

    /*
     * ⚠️ খাত দুইটা **কোড লিখে** বাছা হয় না।
     *
     * প্রথমে `1101-CASH` লেখা ছিল — লাইভের ডাটাবেসে ওই কোডটা আছে বলে।
     * ⛔ ডেমো ডেটায় নেই, তাই `from_account_id` শূন্য হত আর ভাউচারটা
     * "ডেবিট ৭৫০, ক্রেডিট ০" বলে ফেরত আসত — একটা ভুল যার বার্তা এই
     * টেস্ট যা মাপছে তার সাথে কোনো সম্পর্কই রাখে না।
     *
     * ⭐ তাই ফর্ম যেভাবে তালিকা বানায়, ঠিক সেভাবেই: টাকার খাত ও
     * খরচের খাত — কোড নয়, **ভূমিকা** ধরে।
     */
    private function anyMoneyAccount(): int
    {
        return (int) Account::query()->money()->postable()->active()
            ->orderBy('code')->value('id');
    }

    private function anyExpenseAccount(): int
    {
        return (int) Account::query()->postable()->active()
            ->where('type', Account::EXPENSE)->orderBy('code')->value('id');
    }
}
