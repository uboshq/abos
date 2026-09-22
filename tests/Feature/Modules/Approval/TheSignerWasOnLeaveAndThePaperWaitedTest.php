<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Approval;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalDecision;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * সইকারী ছুটিতে, আর কাগজটা বসে রইল।
 *
 * ── ⛔ কী আটকাত ──────────────────────────────────────────────────────
 * ছকে লেখা *"ম্যানেজার সই দেবেন"*। ⚠️ ম্যানেজার ছুটিতে গেলে কাগজটা তাঁর
 * ফেরার দিন পর্যন্ত বসে থাকত — আর বাস্তবে তখন মানুষ ছক এড়িয়ে কাজ সারার
 * পথ খোঁজেন, যেটা ছক থাকা আর না থাকার চেয়েও খারাপ।
 *
 * ── ⭐ মালিকের নিয়ম (২২ সেপ্টেম্বর ২০২৬) ──────────────────────────────
 * পাঠানো যাবে **কেবল তাঁদের কাছে যাঁদের নাম কোনো সচল ছকে আছে**।
 *
 * ⓘ মালিকের কারণ: যে কারো কাছে পাঠানো গেলে ছকটা আর *"কে সই দিতে
 * পারেন"* প্রশ্নের উত্তর থাকত না — যে কেউ যে কাউকে দিয়ে সই করিয়ে
 * নিতে পারতেন। ⚠️ আর কড়া থেকে ঢিলা করা সহজ, উল্টোটা কঠিন।
 *
 * ── ⛔ এই ফাইলের আসল পাহারা ──────────────────────────────────────────
 * *"পাঠানো যায়"* দাবিটা সহজ। ⚠️ আসল ঝুঁকি দুইটা, আর দুইটাই নীরব:
 *
 *   ১. যাঁর নাম কোনো ছকেই নেই, তাঁর কাছে পাঠানো **যাবে না**
 *   ২. পাঠানোর পর **মূল সইকারীও** আর ঐ স্তরে সই দিতে পারবেন না
 *
 * ⓘ (২) ছাড়া ফরওয়ার্ড ক্ষমতা **ছড়াত**, সরাত না — দুইজন একই কাগজে সই
 * দিতে পারতেন, আর ছকের "এক ধাপ" কথাটা মিথ্যা হত।
 */
final class TheSignerWasOnLeaveAndThePaperWaitedTest extends TestCase
{
    use RefreshDatabase;

    private User $clerk;

    private User $manager;

    private User $deputy;

    private User $stranger;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::create(['code' => 'FWD', 'name_en' => 'Forward Co']);
        CompanyContext::set($company->id);

        foreach (['clerk', 'manager', 'deputy', 'stranger'] as $who) {
            $this->{$who} = User::create([
                'name' => ucfirst($who),
                'email' => $who.'@fwd.test',
                'password' => 'x',
            ]);

            $this->{$who}->companies()->attach($company->id);
        }
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    // ── ১ · কাজটা সত্যিই হয় ───────────────────────────────────────────

    public function test_the_paper_moves_to_the_other_desk(): void
    {
        [$approval] = $this->waitingOnTheManager();

        $moved = $this->engine()->forward($approval, $this->manager, $this->deputy, 'ছুটিতে আছি');

        $this->assertSame($this->deputy->id, (int) $moved->assigned_to);

        // ⚠️ ধাপটা হাত বদলায়, নতুন ধাপ বসে না — নাহলে ছকের "এক ধাপ" মিথ্যা হত।
        $this->assertSame(1, (int) $moved->current_level);

        $this->assertTrue($this->engine()->canDecide($moved, $this->deputy),
            'যাঁর কাছে পাঠানো হলো তিনিই সই দিতে পারছেন না।');
    }

    /** ⓘ খাতায় থাকে কে পাঠালেন, কার কাছে, আর কেন। */
    public function test_the_book_remembers_who_passed_it_and_to_whom(): void
    {
        [$approval] = $this->waitingOnTheManager();

        $this->engine()->forward($approval, $this->manager, $this->deputy, 'ছুটিতে আছি');

        $row = ApprovalDecision::query()->where('approval_id', $approval->id)->firstOrFail();

        $this->assertSame(ApprovalDecision::FORWARDED, $row->decision);
        $this->assertSame($this->manager->id, (int) $row->user_id);
        $this->assertSame($this->deputy->id, (int) $row->forwarded_to);
        $this->assertSame('ছুটিতে আছি', $row->remarks);
    }

    // ── ২ · ⛔ আর যা ঘটতে পারে না ─────────────────────────────────────

    /**
     * ⛔ মালিকের নিয়ম — ছকে নাম নেই এমন কারো কাছে পাঠানো যায় না।
     *
     * ⚠️ এটাই এই ফাইলের আসল পাহারা। ⓘ এখানে ফাঁক থাকলে ছকটা আর কিছুই
     * বলে না: যে কেউ যে কাউকে দিয়ে সই করিয়ে নিতে পারতেন, আর খাতায়
     * সেটা বৈধ সই হিসেবেই বসত।
     */
    public function test_it_cannot_be_passed_to_someone_no_flow_names(): void
    {
        [$approval] = $this->waitingOnTheManager();

        $this->assertArrayNotHasKey($this->stranger->id, $this->engine()->signers(),
            'যাঁকে বাইরের লোক ধরে মাপা হচ্ছে, তিনিই ছকে আছেন — দৃশ্যটাই ভুল।');

        $this->expectException(RuntimeException::class);

        $this->engine()->forward($approval, $this->manager, $this->stranger, 'আপনি দেখে নিন');
    }

    /**
     * ⛔ পাঠানোর পর মূল সইকারীও আর ঐ স্তরে সই দিতে পারেন না।
     *
     * ⚠️ নাহলে ফরওয়ার্ড ক্ষমতা **ছড়াত**, সরাত না — দুইজন একই কাগজে
     * সই দিতে পারতেন। ⓘ "এক ধাপ, একটা সই" কথাটা তখন আর সত্যি থাকত না।
     */
    public function test_the_original_signer_steps_back(): void
    {
        [$approval] = $this->waitingOnTheManager();

        $this->assertTrue($this->engine()->canDecide($approval, $this->manager),
            'শুরুতেই ম্যানেজার সই দিতে পারছেন না — তাহলে পরের মাপটার মানে নেই।');

        $moved = $this->engine()->forward($approval, $this->manager, $this->deputy, 'ছুটিতে আছি');

        $this->assertFalse($this->engine()->canDecide($moved, $this->manager),
            'পাঠানোর পরেও মূল সইকারী সই দিতে পারছেন — অর্থাৎ এখন দুইজন পারেন।');
    }

    /** ⓘ নিজের কাছে পাঠানোর কোনো মানে নেই। */
    public function test_it_cannot_be_passed_to_oneself(): void
    {
        [$approval] = $this->waitingOnTheManager();

        $this->expectException(RuntimeException::class);

        $this->engine()->forward($approval, $this->manager, $this->manager, 'নিজেই দেখি');
    }

    // ── ৩ · ইনবক্স দুই দিকেই ঠিক থাকে ────────────────────────────────

    /**
     * ⭐ কাগজটা এক ইনবক্স থেকে আরেকটায় যায় — দুইটাতে থাকে না।
     *
     * ⛔ দুইজনের তালিকাতেই থাকলে দুইজনই ভাবতেন অন্যজন দেখছেন, আর কেউ
     * ধরত না। ⚠️ ঠিক এভাবেই কাগজ হারায়।
     */
    public function test_it_leaves_one_inbox_and_lands_in_the_other(): void
    {
        [$approval] = $this->waitingOnTheManager();

        $this->assertContains($approval->id, $this->inboxOf($this->manager));
        $this->assertNotContains($approval->id, $this->inboxOf($this->deputy));

        $this->engine()->forward($approval, $this->manager, $this->deputy, 'ছুটিতে আছি');

        $this->assertNotContains($approval->id, $this->inboxOf($this->manager),
            'পাঠানোর পরেও কাগজটা ম্যানেজারের তালিকায় — দুইজন একই জিনিস দেখছেন।');

        $this->assertContains($approval->id, $this->inboxOf($this->deputy),
            'যাঁর কাছে পাঠানো হলো তাঁর তালিকায় কাগজটা নেই — অর্থাৎ ওটা হারিয়ে গেল।');
    }

    /**
     * ⛔ ফেরত এলে মূল সইকারী আবার সই দিতে পারেন।
     *
     * ⚠️ ফরওয়ার্ড একটা **সিদ্ধান্ত নয়**। ⓘ "ইনি এই স্তরে আগেই সিদ্ধান্ত
     * দিয়েছেন" গোনায় ওটাও ধরলে কাগজটা ফেরত এসে **চিরকাল আটকে** থাকত —
     * কেউ সই দিতে পারতেন না, আর কারণটা কোথাও লেখা থাকত না।
     */
    public function test_a_paper_that_comes_back_can_still_be_signed(): void
    {
        [$approval] = $this->waitingOnTheManager();

        $moved = $this->engine()->forward($approval, $this->manager, $this->deputy, 'ছুটিতে আছি');
        $back = $this->engine()->forward($moved, $this->deputy, $this->manager, 'আপনারই বিষয়');

        $this->assertTrue($this->engine()->canDecide($back, $this->manager),
            'ফেরত আসা কাগজে মূল সইকারী আর সই দিতে পারছেন না — কাগজটা চিরকাল আটকে থাকবে।');

        $this->assertContains($approval->id, $this->inboxOf($this->manager));
    }

    // ── ৪ · পর্দাটা ───────────────────────────────────────────────────

    public function test_the_screen_passes_it_on(): void
    {
        [$approval] = $this->waitingOnTheManager();

        $this->manager->givePermissionTo($this->permission('approval.decide'));

        $this->actingAs($this->manager)
            ->post(route('approval.inbox.forward', $approval->id), [
                'to' => $this->deputy->id,
                'remarks' => 'ছুটিতে আছি',
            ])
            ->assertRedirect();

        $this->assertSame($this->deputy->id, (int) $approval->fresh()->assigned_to);
    }

    /** ⛔ পর্দা দিয়েও বাইরের লোকের কাছে পাঠানো যায় না। */
    public function test_the_screen_refuses_a_stranger(): void
    {
        [$approval] = $this->waitingOnTheManager();

        $this->manager->givePermissionTo($this->permission('approval.decide'));

        $this->expectException(RuntimeException::class);
        $this->withoutExceptionHandling();

        $this->actingAs($this->manager)
            ->post(route('approval.inbox.forward', $approval->id), [
                'to' => $this->stranger->id,
                'remarks' => 'আপনি দেখে নিন',
            ]);
    }

    // ── হাতিয়ার ────────────────────────────────────────────────────────

    /** @return array{0: Approval} */
    private function waitingOnTheManager(): array
    {
        $flow = ApprovalFlow::create([
            'module' => 'sales',
            'action' => 'discount',
            'threshold_amount' => null,
        ]);

        ApprovalFlowStep::create([
            'approval_flow_id' => $flow->id,
            'level' => 1,
            'approver_type' => ApprovalFlowStep::BY_USER,
            'approver_id' => $this->manager->id,
        ]);

        /*
         * ⓘ ডেপুটিরও নাম একটা ছকে আছে — নাহলে মালিকের নিয়ম অনুযায়ী
         * তাঁর কাছে পাঠানোই যেত না। ⚠️ দ্বিতীয় একটা ছক, যাতে প্রথমটার
         * ধাপের সংখ্যা না বদলায়।
         */
        $other = ApprovalFlow::create([
            'module' => 'purchase',
            'action' => 'order',
            'threshold_amount' => null,
        ]);

        ApprovalFlowStep::create([
            'approval_flow_id' => $other->id,
            'level' => 1,
            'approver_type' => ApprovalFlowStep::BY_USER,
            'approver_id' => $this->deputy->id,
        ]);

        $approval = $this->engine()->request(
            document: Branch::create(['code' => 'F'.uniqid(), 'name_en' => 'Doc']),
            module: 'sales',
            action: 'discount',
            amount: '50000',
            userId: $this->clerk->id,
        );

        $this->assertNotNull($approval, 'অনুরোধই তৈরি হয়নি — পরীক্ষাটা কিছু মাপছে না।');

        return [$approval];
    }

    /**
     * ⚠️ প্রতিবার নতুন ইঞ্জিন — ওর ভিতরে ছক ও রোলের ক্যাশ আছে, আর
     * পুরনোটা আগের উত্তরই ফেরত দিত।
     */
    private function engine(): ApprovalEngine
    {
        return app()->make(ApprovalEngine::class);
    }

    /** @return list<int> */
    private function inboxOf(User $user): array
    {
        return $this->engine()->pendingQueryFor($user)->pluck('id')->all();
    }

    private function permission(string $name): Permission
    {
        return Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }
}
