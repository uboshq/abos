<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\Company;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * একজনের জন্য একটা ব্যতিক্রম, আর নিজের কাগজে নিজের সই।
 *
 * ── দুইটা আলাদা ফাঁক, একই দিনের কাজ ─────────────────────────────────
 *
 * **এক ·** অনুমতি বসে রোল ধরে। **দেওয়াটা** Spatie পারে — ব্যবহারকারীর
 * গায়ে সরাসরি অনুমতি বসানো যায়। **কেড়ে নেওয়াটা পারে না**: রোল যেটা
 * দিয়েছে সেটা একজনের কাছ থেকে তুলে নেওয়ার কোনো উপায় ছিল না।
 *
 * ফলে একজনের একটা ক্ষমতা কাড়তে হলে **তাঁর জন্য আস্ত একটা নতুন রোল**
 * বানাতে হত। তিনজনের তিনটা ব্যতিক্রম মানে তিনটা রোল, আর ছয় মাস পরে
 * কেউ বলতে পারত না কোন রোলটা কেন আছে।
 *
 * **দুই ·** নিজের অনুরোধ নিজে অনুমোদন করা যেত না — কখনোই। বড় অঙ্কে
 * ঠিক, কিন্তু এক টাকার চায়ের বিল দ্বিতীয় একজনের সইয়ের অপেক্ষায় বসে
 * থাকলে বাস্তবে চা-টা কেউ নিজের পকেট থেকে কিনে ফেলেন, আর খাতা নীরবে
 * দিনের সাথে মেলা বন্ধ করে।
 */
class OnePersonOneExceptionTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $salesman;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->salesman = User::query()->where('email', 'sales@abos.test')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        app(StandardChart::class)->install();
    }

    private function override(User $user, string $permission, bool $granted): UserPermissionOverride
    {
        return UserPermissionOverride::query()->create([
            'company_id' => $this->company->id,
            'user_id' => $user->id,
            'permission' => $permission,
            'granted' => $granted,
            'reason' => 'পরীক্ষার জন্য',
            'created_by' => $this->owner->id,
        ]);
    }

    // ── এক · ব্যতিক্রম ──────────────────────────────────────────────

    /**
     * রোল যেটা দিয়েছে, ব্যতিক্রম সেটা কেড়ে নিতে পারে।
     *
     * এটাই আসল অভাব ছিল: আজ পর্যন্ত একজনের একটা ক্ষমতা তুলতে হলে
     * তাঁর জন্য নতুন রোল বানানো ছাড়া উপায় ছিল না।
     */
    public function test_an_exception_can_take_away_what_the_role_gave(): void
    {
        $this->assertTrue($this->salesman->can('sales.invoice.create'),
            'বিক্রয়কর্মীর রোলেই এই অনুমতিটা নেই — পরীক্ষাটা অন্য অনুমতি ধরে লিখতে হবে।');

        $this->override($this->salesman, 'sales.invoice.create', false);

        $this->assertFalse($this->salesman->fresh()->can('sales.invoice.create'),
            'ব্যতিক্রমটা রোলের অনুমতি কাড়তে পারেনি।');
    }

    /** আর রোল যা দেয়নি, ব্যতিক্রম সেটা দিতে পারে। */
    public function test_an_exception_can_give_what_the_role_withheld(): void
    {
        $this->assertFalse($this->salesman->can('accounts.backdate.override'));

        $this->override($this->salesman, 'accounts.backdate.override', true);

        $this->assertTrue($this->salesman->fresh()->can('accounts.backdate.override'),
            'ব্যতিক্রমটা নতুন অনুমতি দিতে পারেনি।');
    }

    /**
     * ব্যতিক্রম কেবল যাঁর নামে বসানো, তাঁরই।
     *
     * এক মানুষের ব্যতিক্রম অন্যের উপর খাটলে ওটা ব্যতিক্রম নয়, নিয়ম।
     */
    public function test_an_exception_touches_only_its_own_person(): void
    {
        $this->override($this->salesman, 'sales.invoice.create', false);

        $other = User::query()->where('email', 'accounts@abos.test')->firstOrFail();

        $this->assertNull(
            UserPermissionOverride::query()->where('user_id', $other->id)->first(),
            'অন্য মানুষের নামেও সারি বসেছে।',
        );
    }

    /** ব্যতিক্রম না থাকলে হুকটার কোনো প্রভাব নেই — রোলই শেষ কথা। */
    public function test_without_an_exception_nothing_changes(): void
    {
        $this->assertTrue($this->owner->can('sales.invoice.create'));
        $this->assertFalse($this->salesman->can('accounts.period.reopen'));
    }

    /** একজনের একটা অনুমতির জন্য একটাই সারি — দুইটা হলে উত্তর নির্ভরযোগ্য থাকত না। */
    public function test_the_same_exception_cannot_be_written_twice(): void
    {
        $this->override($this->salesman, 'sales.invoice.create', false);

        $this->expectException(QueryException::class);

        $this->override($this->salesman, 'sales.invoice.create', true);
    }

    // ── দুই · নিজের কাগজে নিজের সই ──────────────────────────────────

    /** একটা অনুমোদনের অনুরোধ — দেওয়া অঙ্কে। */
    private function requestFor(string $amount, User $by): Approval
    {
        $voucher = app(VoucherService::class)->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString()],
            [
                ['account_id' => StandardChart::find(StandardChart::RECEIVABLE)->id, 'debit' => $amount],
                ['account_id' => StandardChart::find(StandardChart::PAYABLE)->id, 'credit' => $amount],
            ],
        );

        return Approval::query()->create([
            'company_id' => $this->company->id,
            'approvable_type' => Voucher::class,
            'approvable_id' => $voucher->id,
            'module' => 'sales',
            'action' => 'discount',
            'amount' => $amount,
            'status' => Approval::PENDING,
            'current_level' => 1,
            'requested_by' => $by->id,
            'requested_at' => now(),
        ]);
    }

    /**
     * ডিফল্টে নিজের কাগজে নিজের সই চলে না — আজকের আচরণ অবিকল।
     *
     * সীমাটা শূন্য, আর শূন্য মানে "কখনো নয়"। মালিক সংখ্যাটা না বসানো
     * পর্যন্ত কিছুই বদলায় না।
     */
    public function test_by_default_nobody_signs_their_own(): void
    {
        $approval = $this->requestFor('50', $this->owner);

        $this->assertFalse(app(ApprovalEngine::class)->canDecide($approval, $this->owner),
            'সীমা শূন্য, তবু নিজের অনুরোধ নিজে অনুমোদন করা যাচ্ছে।');
    }

    /** সীমা বসালে তার নিচের ছোট অঙ্কে নিজের সই চলে। */
    public function test_below_the_limit_a_small_claim_signs_itself(): void
    {
        app(SettingsService::class)->set('approval.self_limit', 500);

        $approval = $this->requestFor('50', $this->owner);

        $this->assertTrue(app(ApprovalEngine::class)->canDecide($approval, $this->owner),
            'সীমার নিচের ছোট অঙ্কেও নিজের সই আটকে গেছে।');
    }

    /** আর সীমার উপরে গেলে আবার অন্য কাউকে লাগে। */
    public function test_at_or_above_the_limit_somebody_else_must_sign(): void
    {
        app(SettingsService::class)->set('approval.self_limit', 500);

        $this->assertFalse(
            app(ApprovalEngine::class)->canDecide($this->requestFor('500', $this->owner), $this->owner),
            'ঠিক সীমার অঙ্কেই নিজের সই চলে গেছে — সীমাটা "এর নিচে" হওয়ার কথা।',
        );

        $this->assertFalse(
            app(ApprovalEngine::class)->canDecide($this->requestFor('900', $this->owner), $this->owner),
        );
    }

    /**
     * অঙ্ক জানা না থাকলে নিজের সই চলে না।
     *
     * কত টাকা জানা নেই মানে সীমার নিচে কি না তাও জানা নেই, আর সন্দেহে
     * কড়া দিকটাই নিরাপদ।
     */
    public function test_an_unknown_amount_never_signs_itself(): void
    {
        app(SettingsService::class)->set('approval.self_limit', 500);

        $approval = $this->requestFor('50', $this->owner);
        $approval->update(['amount' => null]);

        $this->assertFalse(app(ApprovalEngine::class)->canDecide($approval->fresh(), $this->owner));
    }

    /** অন্যের অনুরোধে সীমার কোনো ভূমিকা নেই — ওটা আগের মতোই চলে। */
    public function test_somebody_elses_request_is_unaffected(): void
    {
        $approval = $this->requestFor('900', $this->salesman);

        $this->assertTrue(app(ApprovalEngine::class)->canDecide($approval, $this->owner),
            'অন্যের অনুরোধ অনুমোদন করাও আটকে গেছে।');
    }
}
