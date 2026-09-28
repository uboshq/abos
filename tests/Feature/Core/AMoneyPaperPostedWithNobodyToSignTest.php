<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Approval\BulkApproval;
use App\Core\Engines\Approval\DelegationService;
use App\Core\Engines\Approval\DocumentApproval;
use App\Core\Engines\Approval\NoApprovalFlow;
use App\Core\Module\ModuleDefinition;
use App\Core\Module\ModuleRegistry;
use App\Core\Services\PermissionSyncer;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalCondition;
use App\Models\ApprovalDecision;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Branch;
use App\Models\Company;
use App\Models\NoticeCategory;
use App\Models\User;
use App\Modules\Approval\Services\MoneyFlowDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
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
 * ── ⚠️ এই ফাইলটা কীভাবে পড়তে হয় ─────────────────────────────────────
 * নকশার §৭-এর দাবিগুলো দুই রকম:
 *   · **আজই চলে** — আজকের যে আচরণ দ্বিতীয় ধাপের পরেও ঠিক থাকতে হবে
 *     (টাকার বাইরের ছাড়, bulk-এর নিষেধ, একই মানুষের চাবি)।
 *   · **দ্বিতীয় ধাপের অপেক্ষায়** — ইঞ্জিন বদলানোর আগে এগুলো লাল হত,
 *     তাই [[afterPhaseTwo()]] দিয়ে incomplete। ⭐ ইঞ্জিনে `movesMoney()`
 *     বসলেই এগুলো নিজে থেকে চালু হয় — কারো কিছু মনে রাখতে হয় না।
 *
 * ⛔ incomplete-এর ডাক **একটাই** জায়গায়: [[ASkippedTestReadsAsAPassingOneTest]]
 * প্রতিটা লেখা ডাক গোনে, আর ছাদ ২৫।
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

    // ── ১ · ছক নেই ────────────────────────────────────────────────────

    /**
     * ⛔ ছক-হীন প্রতিটা টাকার কাজ থামে — তালিকাটা `moves_money` থেকে, হাতে লেখা নয়।
     */
    public function test_every_money_action_without_a_flow_is_refused(): void
    {
        $pairs = app(MoneyFlowDefaults::class)->moneyActions();

        // ⓘ খালি তালিকায় নিচের লুপ কিছুই না দেখে সবুজ হত
        $this->assertNotEmpty($pairs, 'The registry names no money action at all — the claim would look at nothing.');

        $this->afterPhaseTwo($this->engineKnowsMoney(), 'ছক-হীন টাকার কাজ এখনো null পায়');

        $let = [];

        foreach ($pairs as $pair) {
            try {
                $out = $this->engine()->request($this->paper(), $pair['module'], $pair['action'], '1', userId: $this->maker->id);
                $let[] = $pair['module'].'.'.$pair['action'].' → '.($out === null ? 'null' : 'request #'.$out->id);
            } catch (NoApprovalFlow $refused) {
                $this->assertSame($pair['module'], $refused->module());
                $this->assertSame($pair['action'], $refused->action());
            }
        }

        $this->assertSame([], $let, 'These money actions went ahead with no flow at all.');
        $this->assertSame(0, Approval::query()->count(), 'A refused paper must leave no request row behind.');
    }

    // ── ২ · সীমা ──────────────────────────────────────────────────────

    public function test_a_threshold_does_not_wave_a_small_money_paper_through(): void
    {
        $this->afterPhaseTwo($this->engineKnowsMoney(), 'টাকার কাজে সীমা এখনো খাটে');

        [$module, $action] = $this->moneyPair();
        $this->flowOn($module, $action, threshold: '10000');

        $this->assertNotNull(
            $this->engine()->request($this->paper(), $module, $action, '1', userId: $this->maker->id),
            'One taka under a 10,000 threshold still moves money — it needs a second person.',
        );
    }

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

    // ── ৩ · শর্ত আর self_limit ────────────────────────────────────────

    public function test_a_condition_that_does_not_match_still_catches_a_money_paper(): void
    {
        $this->afterPhaseTwo($this->engineKnowsMoney(), 'টাকার কাজে শর্ত এখনো ছেড়ে দেয়');

        [$module, $action] = $this->moneyPair();
        $flow = $this->flowOn($module, $action);

        ApprovalCondition::create([
            'approval_flow_id' => $flow->id,
            'field' => 'name_en',
            'operator' => '=',
            'value' => 'nothing-on-any-paper',
        ]);

        $this->forgetTheEnginesFlows();

        $this->assertNotNull($this->engine()->request(
            $this->paper(), $module, $action, '500',
            userId: $this->maker->id,
            matchOn: ['name_en' => 'an ordinary paper'],
        ));
    }

    public function test_the_self_limit_never_lets_the_maker_sign_money(): void
    {
        $this->afterPhaseTwo($this->engineKnowsMoney(), 'টাকার কাজে self_limit এখনো খাটে');

        app(SettingsService::class)->set('approval.self_limit', 1000000);

        [$module, $action] = $this->moneyPair();
        $this->flowOn($module, $action, byUser: $this->maker);

        $approval = $this->engine()->request($this->paper(), $module, $action, '1', userId: $this->maker->id);

        $this->assertFalse($this->engine()->canDecide($approval, $this->maker));
    }

    // ── ৪ · বানানেওয়ালা ≠ সইকারী ──────────────────────────────────────

    public function test_a_super_admin_cannot_sign_their_own_money_paper(): void
    {
        $this->afterPhaseTwo($this->engineKnowsMoney(), 'সুপার অ্যাডমিন এখনো নিজের টাকার কাগজে সই দেন');

        $owner = $this->superAdmin();

        [$module, $action] = $this->moneyPair();
        $this->flowOn($module, $action, byUser: $owner);

        $approval = $this->engine()->request($this->paper(), $module, $action, '5000', userId: $owner->id);

        $this->assertFalse($this->engine()->canDecide($approval, $owner));

        $this->expectException(RuntimeException::class);
        $this->engine()->approve($approval, $owner);
    }

    /** ⭐ আজই চলে — মালিকের সিদ্ধান্ত ২: টাকার বাইরে ছাড়টা থাকে। */
    public function test_outside_money_a_super_admin_still_signs_their_own_paper(): void
    {
        $owner = $this->superAdmin();

        $this->flowOn('sales', 'discount', byUser: $owner);

        $approval = $this->engine()->request($this->paper(), 'sales', 'discount', '5000', userId: $owner->id);

        $this->assertTrue($this->engine()->canDecide($approval, $owner));
        $this->assertSame(Approval::APPROVED, $this->engine()->approve($approval, $owner)->status);
    }

    public function test_neither_the_creator_nor_the_requester_can_sign(): void
    {
        $this->afterPhaseTwo($this->engineKnowsMoney(), 'কাগজের created_by এখনো দেখা হয় না');

        [$module, $action] = $this->moneyPair();
        $this->flowOn($module, $action, byRole: $this->accountantRoleIn($this->company));

        $creator = $this->secondSignerIn($this->company, 'বানিয়েছেন');
        $requester = $this->secondSignerIn($this->company, 'পাঠিয়েছেন');
        $third = $this->secondSignerIn($this->company, 'তৃতীয়জন');

        $paper = NoticeCategory::create([
            'code' => 'M'.substr(uniqid(), -8),
            'name_en' => 'Money paper',
            'created_by' => $creator->id,
        ]);

        $approval = $this->engine()->request($paper, $module, $action, '700', userId: $requester->id);

        $this->assertFalse($this->engine()->canDecide($approval, $creator), 'The person who made the paper signed it.');
        $this->assertFalse($this->engine()->canDecide($approval, $requester), 'The person who sent it signed it.');
        $this->assertTrue($this->engine()->canDecide($approval, $third), 'A third Accountant must still be able to sign.');
    }

    public function test_a_delegation_is_no_way_round_the_rule(): void
    {
        $this->afterPhaseTwo($this->engineKnowsMoney(), 'ভার নিয়ে ঘুরপথ এখনো খোলা');

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

    public function test_a_money_action_with_no_hook_stops_the_boot(): void
    {
        $this->afterPhaseTwo(property_exists(ModuleDefinition::class, 'approvalHooks'), 'ModuleDefinition এখনো approval_hooks পড়ে না');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/hook/i');

        ModuleDefinition::fromArray(
            [
                'code' => 'pocket',
                'name' => ['en' => 'Pocket', 'bn' => 'পকেট'],
                'nav' => ['section' => 'business', 'order' => 10],
                'approvals' => ['pay' => 'pocket::approval.pay'],
                'moves_money' => ['pay'],
            ],
            'test/module.php',
            'App\\Modules\\Pocket',
        );
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
        $this->afterPhaseTwo($this->engineKnowsMoney(), 'সিদ্ধান্তে এখনো লক নেই');

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

        $this->assertSame(1, ApprovalDecision::query()->where('approval_id', $stale->id)->count());
    }

    // ── নিয়ম ৫ · পোস্টের মুহূর্তে আবার দেখা ──────────────────────────────

    public function test_an_approval_whose_only_yes_came_from_its_maker_does_not_clear_the_paper(): void
    {
        $this->afterPhaseTwo($this->engineKnowsMoney(), 'পোস্টের মুহূর্তে সইকারী এখনো দেখা হয় না');

        [$module, $action] = $this->moneyPair();
        $this->flowOn($module, $action, byRole: $this->accountantRoleIn($this->company));

        $paper = $this->paper();

        /*
         * ⓘ deploy-এর আগের একটা অনুমোদন — সুপার অ্যাডমিন নিজে সই করে
         * পাশ করেছিলেন, কাগজ পোস্ট হয়নি (নকশা §৬)।
         */
        $old = Approval::create([
            'company_id' => $this->company->id,
            'approvable_type' => $paper::class,
            'approvable_id' => $paper->getKey(),
            'module' => $module,
            'action' => $action,
            'amount' => '100',
            'status' => Approval::APPROVED,
            'current_level' => 1,
            'requested_by' => $this->maker->id,
            'requested_at' => now()->subDay(),
            'decided_at' => now()->subDay(),
        ]);

        ApprovalDecision::create([
            'approval_id' => $old->id,
            'level' => 1,
            'user_id' => $this->maker->id,
            'decision' => 'approved',
            'decided_at' => now()->subDay(),
        ]);

        $this->actingAs($this->maker);

        $stopping = app(DocumentApproval::class)->stopping($paper, $module, $action, '100');

        $this->assertNotNull($stopping, 'A self-signed approval let the money paper post.');
        $this->assertNotSame((int) $old->id, (int) $stopping->id);
        $this->assertSame(Approval::PENDING, $stopping->status);
    }

    // ── মালিকের সিদ্ধান্ত ৩ · একমাত্র Accountant ────────────────────────

    public function test_a_lone_accountants_own_paper_goes_to_the_super_admin(): void
    {
        $this->afterPhaseTwo($this->engineKnowsMoney(), 'যোগ্য সইকারী না থাকলে সুপার অ্যাডমিনের পথ এখনো নেই');

        [$module, $action] = $this->moneyPair();
        $this->flowOn($module, $action, byRole: $this->accountantRoleIn($this->company));

        $lone = $this->secondSignerIn($this->company, 'একমাত্র Accountant');
        $owner = $this->superAdmin();

        $approval = $this->engine()->request($this->paper(), $module, $action, '600', userId: $lone->id);

        $this->assertFalse($this->engine()->canDecide($approval, $lone));
        $this->assertTrue($this->engine()->canDecide($approval, $owner),
            'Nobody else can sign — the owner decided the super admin signs then.');
    }

    // ── সহায়ক ────────────────────────────────────────────────────────

    /**
     * ⚠️ incomplete-এর একমাত্র ডাক — ছাদের হিসাব রাখতে।
     *
     * ⓘ `$landed` ঠিক সেই জিনিসটা জিজ্ঞেস করে যার উপর দাবিটা দাঁড়িয়ে;
     * ⛔ একটা সাধারণ "phase 2" পতাকা হলে অর্ধেক বসানো অবস্থায় ভুল দাবি চালু হত।
     */
    private function afterPhaseTwo(bool $landed, string $what): void
    {
        if (! $landed) {
            $this->markTestIncomplete('দ্বিতীয় ধাপ এখনো বসেনি — '.$what);
        }
    }

    private function engineKnowsMoney(): bool
    {
        return method_exists(ApprovalEngine::class, 'movesMoney');
    }

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
