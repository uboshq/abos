<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\VoucherApproval;
use App\Modules\Accounts\Services\VoucherService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * খরচ খাতায় বসার আগে কেউ "হ্যাঁ" বলেছে কি না।
 *
 * ── কেন খরচেই, আর কেন এতদিন ছিল না ──────────────────────────────────
 * খরচই একমাত্র ভাউচার যেটা রোজ লেখেন এমন একজন যিনি হিসাবরক্ষক নন —
 * ডিপো ম্যানেজার ভাড়া, হাম্মালি, জ্বালানি লেখেন। টাকাটা প্রতিষ্ঠানের,
 * সিদ্ধান্তটা একার। ইঞ্জিনটা কোরে তৈরিই ছিল ([[ApprovalEngine]]),
 * কিন্তু আজ পর্যন্ত কেবল বিক্রয়ের ছাড় ওটা ব্যবহার করত।
 *
 * ⚠️ ── এই ফাইলের সবচেয়ে জরুরি টেস্টটা প্রথমটাই ───────────────────────
 * `test_with_no_flow_declared_nothing_changes_at_all` — কোনো কোম্পানি
 * অনুমোদনের ছক না বসালে খরচ **আজকের মতোই** পোস্ট হয়। বাকি সব ঠিক
 * থাকলেও এটা ভাঙলে লাইভে খরচ পোস্ট করাই বন্ধ, প্রতিটা প্রতিষ্ঠানে।
 *
 * ⓘ প্রতিটা assertion ইচ্ছে করে ভেঙে লাল হতে দেখা হয়েছে।
 */
class AnExpenseNobodySaidYesToTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $clerk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->clerk = User::query()->firstOrFail();
        $this->manager = User::query()->skip(1)->take(1)->firstOrFail();

        /*
         * কেউ একজন লগইন করা — কারণ একটা অনুরোধের **অনুরোধকারী** থাকতেই
         * হয় (`approvals.requested_by` null নেয় না)। পর্দা থেকে এলে
         * সেটা এমনিতেই থাকে; এখানে হাতে বসাতে হয়।
         */
        $this->actingAs($this->clerk);
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    private function flow(?string $threshold = null): ApprovalFlow
    {
        $flow = ApprovalFlow::create([
            'module' => VoucherApproval::MODULE,
            'action' => Voucher::EXPENSE,
            'threshold_amount' => $threshold,
        ]);

        ApprovalFlowStep::create([
            'approval_flow_id' => $flow->id,
            'level' => 1,
            'approver_type' => ApprovalFlowStep::BY_USER,
            'approver_id' => $this->manager->id,
        ]);

        return $flow;
    }

    /**
     * একটা খরচের খসড়া — পোস্ট না করেই, কারণ পোস্টই এখানে প্রশ্ন।
     *
     * ⓘ ফ্যাক্টরি ব্যবহার করা হয়নি: এই রিপোতে ভাউচারের কোনো ফ্যাক্টরি
     * নেই, আর থাকা উচিতও নয় — একটা ভাউচার তার সারিগুলো ছাড়া অর্থহীন,
     * আর ডেবিট-ক্রেডিট না মিললে `EveryVoucherBalancesTest` লাল হয়।
     * বাকি টেস্টগুলোও `VoucherService::create()` দিয়েই বানায়।
     */
    private function expense(string $amount = '5000', string $type = Voucher::EXPENSE): Voucher
    {
        $head = Account::query()->postable()->where('name_en', 'like', '%Rent%')->firstOrFail();
        $cash = Account::query()->postable()->where('name_en', 'like', '%Cash%')->firstOrFail();

        return app(VoucherService::class)->create(
            ['type' => $type, 'trx_date' => now()->toDateString(), 'narration' => 'test'],
            [
                ['account_id' => $head->id, 'debit' => $amount, 'credit' => '0'],
                ['account_id' => $cash->id, 'debit' => '0', 'credit' => $amount],
            ],
        );
    }

    /**
     * ⚠️ সবচেয়ে জরুরি টেস্ট — ডিফল্ট পথ।
     *
     * অনুমোদনের ছক না বসানো মানে **কিছুই বদলায়নি**। আজ পর্যন্ত একটাও
     * কোম্পানি খরচে ছক বসায়নি, তাই এই লাইনটাই প্রতিটা লাইভ ইনস্টলের
     * আচরণ।
     */
    public function test_with_no_flow_declared_nothing_changes_at_all(): void
    {
        /*
         * ⚠️ শূন্যটা দেখে নেওয়া — নাহলে assertion "০ বনাম ০" মিলিয়ে
         * সবসময় সবুজ থাকত, এমনকি ছক বসানো থাকলেও।
         *
         * ⓘ `count()` নয়, খরচের ছক গোনা: `DemoSeeder` একটা
         * `sales.discount` ছক বসায় (১,০০০ টাকার উপরে মালিকের সম্মতি)।
         * গোটা টেবিল গুনলে এখানে ১ পাওয়া যায়, আর সেটাই প্রথম রানে
         * ধরা পড়েছে — প্রশ্নটা "কোনো ছক আছে কি" নয়, **"খরচে ছক আছে
         * কি"**।
         */
        $this->assertSame(0, ApprovalFlow::query()
            ->where('module', VoucherApproval::MODULE)
            ->where('action', Voucher::EXPENSE)
            ->count());

        $this->assertNull(app(VoucherApproval::class)->stopping($this->expense()));
        $this->assertSame(0, Approval::query()->count());
    }

    public function test_below_the_threshold_nothing_changes_either(): void
    {
        $this->flow(threshold: '10000');

        // ছক আছে, কিন্তু এই খরচ সীমার নিচে — ম্যানেজারের সময় নষ্ট নয়
        $this->assertNull(app(VoucherApproval::class)->stopping($this->expense('900')));
        $this->assertSame(0, Approval::query()->count());
    }

    public function test_above_the_threshold_the_expense_waits(): void
    {
        $this->flow(threshold: '1000');

        $stopping = app(VoucherApproval::class)->stopping($this->expense('5000'));

        $this->assertNotNull($stopping);
        $this->assertSame(Approval::PENDING, $stopping->status);
    }

    public function test_asking_twice_does_not_make_two_requests(): void
    {
        $this->flow();
        $voucher = $this->expense();

        app(VoucherApproval::class)->stopping($voucher);
        app(VoucherApproval::class)->stopping($voucher);

        // দুইটা অনুরোধ থাকলে অনুমোদনকারী একই কাগজ দুইবার দেখতেন, আর
        // একটা অনুমোদন করে অন্যটা ঝুলে থাকত
        $this->assertSame(1, Approval::query()->count());
    }

    /**
     * অনুমোদনের পর — আর এটাই সবচেয়ে সহজে ভুল হওয়ার জায়গা।
     *
     * `ApprovalEngine::approve()` নিজে কাজটা এগোয় না; মানুষটাকে ফিরে
     * এসে আবার "পোস্ট" চাপতে হয়। ওই দ্বিতীয় চাপে যদি আবার `request()`
     * ডাকা হত, সে পুরনো অনুরোধটা pending নয় বলে খুঁজে পেত না আর নতুন
     * একটা বানাত — **অনুমোদনটা অসীম লুপে পড়ত**।
     */
    public function test_once_approved_it_stops_stopping(): void
    {
        $this->flow();
        $voucher = $this->expense();

        $approval = app(VoucherApproval::class)->stopping($voucher);
        $this->assertNotNull($approval);

        app(ApprovalEngine::class)->approve($approval, $this->manager);

        $this->assertNull(app(VoucherApproval::class)->stopping($voucher->fresh()));
        $this->assertSame(1, Approval::query()->count());
    }

    /**
     * "না" বলার পর — আর "না"-টা যেন কিছু একটা মানে বহন করে।
     *
     * নীরবে আবার অনুরোধ পাঠালে প্রত্যাখ্যানের কোনো মানে থাকত না: যিনি
     * "না" শুনলেন তিনি আবার বোতামটা চাপতেন, আর অনুমোদনকারী একই কাগজ
     * বারবার দেখতেন।
     */
    public function test_a_rejected_expense_stays_refused_until_it_changes(): void
    {
        $this->flow();
        $voucher = $this->expense();

        $approval = app(VoucherApproval::class)->stopping($voucher);
        app(ApprovalEngine::class)->reject($approval, $this->manager, 'রসিদ নেই');

        $again = app(VoucherApproval::class)->stopping($voucher->fresh());

        $this->assertNotNull($again);
        $this->assertSame(Approval::REJECTED, $again->status);

        // আর নতুন কোনো অনুরোধ যায়নি
        $this->assertSame(1, Approval::query()->count());
    }

    public function test_changing_the_voucher_lets_you_ask_again(): void
    {
        $this->flow();
        $voucher = $this->expense();

        $approval = app(VoucherApproval::class)->stopping($voucher);
        app(ApprovalEngine::class)->reject($approval, $this->manager, 'রসিদ নেই');

        // কারণটা মেটানো মানেই কাগজে হাত দেওয়া
        $this->travel(1)->minutes();
        $voucher->forceFill(['narration' => 'রসিদ যোগ করা হয়েছে'])->save();

        $again = app(VoucherApproval::class)->stopping($voucher->fresh());

        $this->assertNotNull($again);
        $this->assertSame(Approval::PENDING, $again->status);
        $this->assertSame(2, Approval::query()->count());
    }

    /**
     * ⚠️ অনুমোদন কেবল খরচে — বাকি ভাউচার আগের মতোই।
     *
     * জাবেদা বা কন্ট্রা হিসাবরক্ষকের নিজের কাজ; সেখানে অনুমোদন বসানো
     * মানে তাঁকে নিজের কাজেই আটকে দেওয়া।
     */
    public function test_other_voucher_types_are_untouched(): void
    {
        $this->flow();

        $journal = $this->expense('90000', Voucher::JOURNAL);

        $this->assertNull(app(VoucherApproval::class)->stopping($journal));
        $this->assertSame(0, Approval::query()->count());
    }

    /**
     * পর্দাটা ঝুলে থাকা খরচ লুকায় না।
     *
     * ── কেন এটা আলাদা করে পাহারা দেওয়া দরকার ────────────────────────
     * খরচের পর্দার উপরের সংখ্যাগুলো খাত ধরে যোগফল, আর ওগুলোতে ঝুলে
     * থাকা খসড়া **নেই** — খতিয়ানে বসেনি বলে কোনো খাতে যোগও হয়নি।
     * তালিকাটা না দেখালে ম্যানেজার কম খরচ দেখে সিদ্ধান্ত নিতেন, আর
     * অনুমোদন হয়ে গেলে সংখ্যাটা মাসের মাঝখানে হঠাৎ বেড়ে যেত।
     *
     * ⚠️ **সংখ্যা কম দেখানোর চেয়ে খারাপ কিছু নেই যদি না বলা হয় কেন।**
     */
    public function test_the_expense_screen_shows_what_is_still_waiting(): void
    {
        $this->flow();
        $voucher = $this->expense();
        app(VoucherApproval::class)->stopping($voucher);

        $html = $this->get(route('finance.expense.index'))->assertOk()->getContent();

        $this->assertStringContainsString((string) $voucher->document_no, $html);
        $this->assertStringContainsString(__('finance::field.waiting_approval'), $html);
    }

    /**
     * ⚠️ আর ছক না থাকলে বাক্সটা পর্দায় **থাকেই না**।
     *
     * চিরকাল-খালি একটা বাক্স মানে প্রতিদিন একটা প্রশ্ন যার উত্তর কেউ
     * জানে না — "এখানে কী আসার কথা ছিল?"। আজ চারটা লাইভ কোম্পানির
     * একটাতেও খরচের ছক নেই, তাই আজ এটাই আসল আচরণ।
     */
    public function test_with_nothing_waiting_the_box_is_not_there_at_all(): void
    {
        $this->expense();

        $html = $this->get(route('finance.expense.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString(__('finance::field.waiting_approval'), $html);
    }
}
