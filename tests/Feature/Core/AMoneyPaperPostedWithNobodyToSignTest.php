<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Approval\BulkApproval;
use App\Core\Engines\Approval\DelegationService;
use App\Core\Module\ModuleRegistry;
use App\Core\Services\PermissionSyncer;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalDecision;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Branch;
use App\Models\Company;
use App\Models\NoticeCategory;
use App\Models\User;
use App\Modules\Approval\Services\MoneyFlowDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\SignsMoneyOff;
use Tests\TestCase;

/**
 * ⛔ টাকার কাগজ পোস্ট হত, অথচ সই দেওয়ার কেউ ছিল না — অডিট §১.৩।
 *
 * ── ⓘ আজ কী হয় ────────────────────────────────────────────────────────
 * ছক না থাকলে [[ApprovalEngine::request()]] `null` ফেরায় — *"এগিয়ে
 * যাও"*। ⚠️ সীমা, শর্ত আর `approval.self_limit` টাকার কাগজকেও ছেড়ে
 * দেয়, আর সুপার অ্যাডমিন নিজের যেকোনো কাগজে সই দেন। ⛔ অর্থাৎ একজন
 * মানুষ একাই টাকা নড়াতে পারেন, আর খাতা সেটাকে "অনুমোদিত" বলে।
 *
 * ── ⭐ মালিকের সিদ্ধান্ত, ২৭ সেপ্টেম্বর ২০২৬ ──────────────────────────
 * ১ · শেষ সইয়ের পর কাগজ নিজে পোস্ট হয়।
 * ২ · টাকার বাইরে সুপার অ্যাডমিন নিজের কাগজে সই দিতে পারেন (আজকের মতো)।
 * ৩ · যোগ্য সইকারী না থাকলে (একমাত্র Accountant-এর নিজের কাগজ) সুপার
 *     অ্যাডমিন সই দেন।
 * ৪ · POS-এর নগদ বিক্রি ছাড়া প্রতিটা টাকার পোস্টিংয়ে সই লাগে।
 *
 * ── ⚠️ দরজা তোলা হলো (fe, ২৪ অক্টোবর ২০২৬) ─────────────────────────
 * আগে "দ্বিতীয় ধাপের" এগারোটা দাবি `method_exists(ApprovalEngine::class, 'movesMoney')`-এর দরজার পিছনে
 * incomplete থাকত। ⛔ সেই নামটা ইঞ্জিনে কখনো বসেনি (বসেছে [[BulkApproval::movesMoney()]]-এ), তাই ২৮ সেপ্টেম্বর থেকে
 * এগুলো একবারও চলেনি। দরজা ছাড়া চালালে দুইটা আজই সবুজ (ভার, পুরনো কপি) — সেগুলো এখানে, সবসময় চলে।
 * ⓘ বাকি নয়টা লাল — ইঞ্জিনে টাকার নিয়মই বসেনি; তারা নিজেদের সারাইয়ের সাথে a5/money-sign-claims-এ আসে (fe)।
 *
 * ⓘ কাগজ হিসেবে [[Branch]] আর [[NoticeCategory]] — ইঞ্জিন polymorphic,
 * কোন কাগজ সেটা তার জানার কথা নয় ([[ApprovalEngineTest]]-এর একই কৌশল)।
 * `NoticeCategory` কেবল সেখানে যেখানে `created_by` ঘরটা লাগে।
 */
class AMoneyPaperPostedWithNobodyToSignTest extends TestCase
{
    use RefreshDatabase;
    use SignsMoneyOff;

    private Company $company;

    private User $maker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['code' => 'MN', 'name_en' => 'Money Paper Co']);
        CompanyContext::set($this->company->id);

        $this->maker = $this->personIn('বানানেওয়ালা');
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    // ── ২ · সীমা ──────────────────────────────────────────────────────

    /** ⭐ আজই চলে — টাকার বাইরে সীমা আগের মতো। */
    public function test_outside_money_a_small_paper_under_the_threshold_still_goes_ahead(): void
    {
        $this->assertNotContains('discount', app(ModuleRegistry::class)->get('sales')?->movesMoney ?? ['discount'],
            'sales.discount became a money action — this claim no longer measures the non-money side.');

        $this->flowOn('sales', 'discount', threshold: '10000');

        $this->assertNull(
            $this->engine()->request($this->paper(), 'sales', 'discount', '1', userId: $this->maker->id),
        );
    }

    // ── ৪ · বানানেওয়ালা ≠ সইকারী ──────────────────────────────────────

    /** ⭐ আজই চলে — মালিকের সিদ্ধান্ত ২: টাকার বাইরে ছাড়টা থাকে। */
    // ── ⭐ চেকলিস্ট ভা-১৪ (৭ নভেম্বর ২০২৬) — মালিকের ২৭ সেপ্টেম্বরের সিদ্ধান্ত ২: টাকার কাগজে নিজের সই নয়, সুপার অ্যাডমিনেরও নয় ──
    // ⓘ a5-এর ২৪ অক্টোবরের দাবি (a5/money-sign-claims) থেকে নেওয়া — যেগুলো মালিকের নিয়মের; সীমা ৳5,000 যেমন আছে তেমন (মালিক, ২৪ অক্টোবর), তাই সীমার দাবি বাদ

    public function test_the_self_limit_never_lets_the_maker_sign_money(): void
    {
        app(\App\Core\Services\SettingsService::class)->set('approval.self_limit', 1000000);

        [$module, $action] = $this->moneyPair();
        $this->flowOn($module, $action, byUser: $this->maker);

        $approval = $this->engine()->request($this->paper(), $module, $action, '1', userId: $this->maker->id);

        $this->assertFalse($this->engine()->canDecide($approval, $this->maker), '⛔ নিজের সীমার নিচে বলে টাকার কাগজে নিজে সই দেওয়া গেল।');
    }

    public function test_a_super_admin_cannot_sign_their_own_money_paper(): void
    {
        $owner = $this->superAdmin();

        [$module, $action] = $this->moneyPair();
        $this->flowOn($module, $action, byUser: $owner);

        $approval = $this->engine()->request($this->paper(), $module, $action, '5000', userId: $owner->id);

        $this->assertFalse($this->engine()->canDecide($approval, $owner), '⛔ সুপার অ্যাডমিন নিজের টাকার কাগজে নিজে সই দিতে পারেন।');
    }

    public function test_neither_the_creator_nor_the_requester_can_sign_money(): void
    {
        [$module, $action] = $this->moneyPair();
        $this->flowOn($module, $action, byRole: $this->accountantRoleIn($this->company));

        $creator = $this->secondSignerIn($this->company, 'বানিয়েছেন');
        $requester = $this->secondSignerIn($this->company, 'পাঠিয়েছেন');
        $third = $this->secondSignerIn($this->company, 'তৃতীয়জন');

        $paper = NoticeCategory::create(['code' => 'M'.substr(uniqid(), -8), 'name_en' => 'Money paper', 'created_by' => $creator->id]);
        $approval = $this->engine()->request($paper, $module, $action, '700', userId: $requester->id);

        $this->assertFalse($this->engine()->canDecide($approval, $creator), '⛔ যিনি টাকার কাগজটা বানালেন তিনিই সই দিলেন।');
        $this->assertFalse($this->engine()->canDecide($approval, $requester), '⛔ যিনি সই চাইলেন তিনিই সই দিলেন।');
        $this->assertTrue($this->engine()->canDecide($approval, $third), '⛔ তৃতীয় একজন সইকারীও সই দিতে পারছেন না — নিয়ম বেশি কড়া হয়ে গেল।');
    }

    public function test_an_approval_whose_only_yes_came_from_its_maker_does_not_clear_the_money_paper(): void
    {
        [$module, $action] = $this->moneyPair();
        $this->flowOn($module, $action, byRole: $this->accountantRoleIn($this->company));
        $paper = $this->paper();

        // ⓘ নিয়মের আগের একটা অনুমোদন — নিজে সই দিয়ে পাশ, কাগজ পোস্ট হয়নি
        $old = Approval::create([
            'company_id' => $this->company->id, 'approvable_type' => $paper::class, 'approvable_id' => $paper->getKey(),
            'module' => $module, 'action' => $action, 'amount' => '100', 'status' => Approval::APPROVED, 'current_level' => 1,
            'requested_by' => $this->maker->id, 'requested_at' => now()->subDay(), 'decided_at' => now()->subDay(),
        ]);
        ApprovalDecision::create(['approval_id' => $old->id, 'level' => 1, 'user_id' => $this->maker->id, 'decision' => 'approved', 'decided_at' => now()->subDay()]);

        $this->actingAs($this->maker);
        $stopping = app(\App\Core\Engines\Approval\DocumentApproval::class)->stopping($paper, $module, $action, '100');

        $this->assertNotNull($stopping, '⛔ নিজের সইয়ে পাশ হওয়া অনুমোদনে টাকার কাগজ পোস্ট হয়ে গেল।');
        $this->assertNotSame((int) $old->id, (int) $stopping->id, '⛔ পুরনো নিজে-সই অনুমোদনটাই ফেরত এল।');
        $this->assertSame(Approval::PENDING, $stopping->status, '⛔ নতুন অনুরোধটা অপেক্ষায় নেই।');
    }

    public function test_outside_money_a_super_admin_still_signs_their_own_paper(): void
    {
        $owner = $this->superAdmin();

        $this->flowOn('sales', 'discount', byUser: $owner);

        $approval = $this->engine()->request($this->paper(), 'sales', 'discount', '5000', userId: $owner->id);

        $this->assertTrue($this->engine()->canDecide($approval, $owner));
        $this->assertSame(Approval::APPROVED, $this->engine()->approve($approval, $owner)->status);
    }

    public function test_a_delegation_is_no_way_round_the_rule(): void
    {
        [$module, $action] = $this->moneyPair();
        $this->flowOn($module, $action, byRole: $this->accountantRoleIn($this->company));

        $maker = $this->secondSignerIn($this->company, 'Accountant যিনি বানালেন');
        $standIn = $this->personIn('ভারপ্রাপ্ত');

        app(DelegationService::class)->grant(
            $maker, $standIn, now()->toDateString(), now()->addWeek()->toDateString(),
        );

        $approval = $this->engine()->request($this->paper(), $module, $action, '900', userId: $maker->id);

        $this->assertFalse(
            $this->engine()->canDecide($approval, $standIn),
            'Signing on behalf of the maker is the maker signing.',
        );
    }

    // ── ৫ · একই মানুষ, চাবি বন্ধ তারপর চালু ───────────────────────────

    /** ⭐ আজই চলে — একই মানুষ, একই কাগজ; কেবল রোলটা বদলায়। */
    public function test_the_same_accountant_is_shut_out_without_the_role_and_let_in_with_it(): void
    {
        [$module, $action] = $this->moneyPair();
        $role = $this->accountantRoleIn($this->company);
        $this->flowOn($module, $action, byRole: $role);

        $signer = $this->personIn('চাবির অপেক্ষায়');

        $approval = $this->engine()->request($this->paper(), $module, $action, '300', userId: $this->maker->id);

        $this->assertFalse($this->engine()->canDecide($approval, $signer), 'Without the Accountant role the door must stay shut.');

        CompanyContext::forCompany((int) $this->company->id, fn () => $signer->assignRole($role));
        $signer->unsetRelation('roles');
        $this->forgetTheEnginesFlows();

        $this->assertTrue($this->engine()->canDecide($approval->fresh(), $signer), 'With the role, the same person on the same paper must get in.');
    }

    // ── ৬ · bulk আর hook ──────────────────────────────────────────────

    /** ⭐ আজই চলে — টাকার কাগজ একসাথে সইয়ে কখনো পাশ হয় না। */
    public function test_bulk_never_signs_a_money_paper(): void
    {
        [$module, $action] = $this->moneyPair();
        $this->flowOn($module, $action, byRole: $this->accountantRoleIn($this->company));

        $signer = $this->secondSignerIn($this->company);

        $approval = $this->engine()->request($this->paper(), $module, $action, '400', userId: $this->maker->id);

        $outcome = app(BulkApproval::class)->approve([(int) $approval->id], $signer);

        $this->assertSame(0, $outcome['done']);
        $this->assertSame(1, $outcome['skipped']['money'] ?? 0);
        $this->assertSame(Approval::PENDING, $approval->fresh()->status);
    }

    // ── ৭ · লক ────────────────────────────────────────────────────────

    /**
     * ⛔ পুরনো কপির উপর দ্বিতীয় সই — আজ দ্বিতীয় একটা সিদ্ধান্ত বসে যায়।
     *
     * ⓘ দুই সংযোগের দৌড় এখানে ধরা হয়নি; ⚠️ কিন্তু একই ভুলের সরল রূপটা
     * ধরা যায়: `assertPending` হাতের কপিটা দেখে, ডাটাবেজের সারি নয়।
     */
    public function test_a_second_signature_on_a_stale_copy_is_refused(): void
    {
        [$module, $action] = $this->moneyPair();
        $this->flowOn($module, $action, byRole: $this->accountantRoleIn($this->company));

        $first = $this->secondSignerIn($this->company, 'প্রথম');
        $second = $this->secondSignerIn($this->company, 'দ্বিতীয়');

        $stale = $this->engine()->request($this->paper(), $module, $action, '800', userId: $this->maker->id);

        $this->engine()->approve($stale, $first);

        try {
            $this->engine()->approve($stale, $second);
            $this->fail('A second yes on an already approved request went through.');
        } catch (RuntimeException) {
            // ⓘ এটাই চাওয়া
        }

        $this->assertSame(1, ApprovalDecision::query()->where('approval_id', $stale->id)->count(),
            '⛔ পুরনো কপিতে দ্বিতীয় একটা সিদ্ধান্ত বসে গেছে।');
    }

    // ── সহায়ক ────────────────────────────────────────────────────────

    /** ⓘ প্রতিবার নতুন করে — ইঞ্জিন `scoped`, আর ছক ও রোল জমিয়ে রাখে। */
    private function engine(): ApprovalEngine
    {
        return app(ApprovalEngine::class);
    }

    /** @return array{0: string, 1: string} রেজিস্ট্রির প্রথম টাকার কাজ */
    private function moneyPair(): array
    {
        $pairs = app(MoneyFlowDefaults::class)->moneyActions();

        $this->assertNotEmpty($pairs);

        return [$pairs[0]['module'], $pairs[0]['action']];
    }

    private function paper(): Branch
    {
        return Branch::create(['code' => 'D'.uniqid(), 'name_en' => 'Doc']);
    }

    private function personIn(string $label): User
    {
        $user = User::create([
            'name' => $label,
            'email' => 'p-'.uniqid('', true).'@t.test',
            'password' => 'x',
        ]);

        $user->companies()->syncWithoutDetaching([$this->company->id]);

        return $user;
    }

    private function superAdmin(): User
    {
        $owner = $this->personIn('মালিক');

        CompanyContext::forCompany((int) $this->company->id, function () use ($owner): void {
            $owner->assignRole(Role::findOrCreate(PermissionSyncer::SUPER_ADMIN_ROLE, 'web'));
        });

        $owner->unsetRelation('roles');
        $this->forgetTheEnginesFlows();

        return $owner;
    }

    private function flowOn(
        string $module,
        string $action,
        ?string $threshold = null,
        ?User $byUser = null,
        ?Role $byRole = null,
    ): ApprovalFlow {
        $flow = ApprovalFlow::create([
            'module' => $module,
            'action' => $action,
            'document_type' => '',
            'threshold_amount' => $threshold,
        ]);

        $byUser ??= $byRole === null ? $this->personIn('ছকের সইকারী') : null;

        ApprovalFlowStep::create([
            'approval_flow_id' => $flow->id,
            'level' => 1,
            'approver_type' => $byRole !== null ? ApprovalFlowStep::BY_ROLE : ApprovalFlowStep::BY_USER,
            'approver_id' => $byRole !== null ? $byRole->id : $byUser->id,
        ]);

        $this->forgetTheEnginesFlows();

        return $flow;
    }
}
