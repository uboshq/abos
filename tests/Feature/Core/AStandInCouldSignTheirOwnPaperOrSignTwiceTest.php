<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Approval\DelegationService;
use App\Core\Events\ApprovalDecided;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalDecision;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

/**
 * ভারপ্রাপ্ত বা উপরে-পাঠানোর গন্তব্যের মানুষ নিজের কাগজে সই দিতে পারতেন, আর একই
 * মানুষ দুইবার সই দিতে পারতেন — ২৯ সেপ্টেম্বর ২০২৬।
 *
 * ── ⛔ কী ভাঙা ছিল ───────────────────────────────────────────────────
 * [[ApprovalEngine::canDecide()]]-এ ভার আর উপরে-পাঠানো — দুই পথই সরাসরি
 * `true` ফেরাত। ⚠️ তাই পরের তিনটা পাহারা ঐ মানুষদের জন্য কখনো চলত না:
 * অনুরোধকারী নিজে সই দিতে পারেন না · কর্তৃত্বের সীমা · এই স্তরে আগেই সই।
 *
 * ── ⛔ আর দ্বিতীয় ফাঁক ─────────────────────────────────────────────
 * `approve()`/`reject()` পুরনো কপি দেখে *"অপেক্ষমাণ"* ধরত, সারিতে কোনো তালা
 * ছিল না। ⚠️ দুই পর্দা একসাথে চাপলে একটা অনুমোদিত কাগজে দ্বিতীয় সই বসত,
 * শেষ-সইয়ের খবর দুইবার যেত — বা অনুমোদিত কাগজ *"বাতিল"* হয়ে যেত।
 */
final class AStandInCouldSignTheirOwnPaperOrSignTwiceTest extends TestCase
{
    use RefreshDatabase;

    private User $clerk;

    private User $boss;

    private User $deputy;

    private User $second;

    private User $third;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::create(['code' => 'SIN', 'name_en' => 'Stand In Co']);
        CompanyContext::set($company->id);

        foreach (['clerk', 'boss', 'deputy', 'second', 'third'] as $who) {
            $this->{$who} = User::create([
                'name' => ucfirst($who),
                'email' => $who.'@sin.test',
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

    // ── ১ · ⛔ নিজের কাগজে নিজে — ভারের পথে ─────────────────────────────

    public function test_the_maker_cannot_sign_their_own_paper_as_a_delegate(): void
    {
        $this->flow([$this->boss]);

        $this->delegate($this->boss, $this->clerk);
        $this->delegate($this->boss, $this->deputy);

        $approval = $this->ask();

        $this->assertTrue($this->engine()->canDecide($approval, $this->deputy),
            'অন্য একজন ভারপ্রাপ্ত সই দিতে পারছেন না — তাহলে নিচের "না"-র কোনো মানে নেই।');

        $this->assertFalse($this->engine()->canDecide($approval, $this->clerk), implode("\n", [
            'অনুরোধকারী নিজেই ভারপ্রাপ্ত হয়ে নিজের কাগজে সই দিতে পারছেন।',
            '',
            '⛔ তাহলে "যে চায় সে দেয় না" নিয়মটা একটা ভার দিয়েই এড়ানো যায়।',
        ]));

        $this->assertRefused(fn () => $this->engine()->approve($approval, $this->clerk),
            'অনুরোধকারী ভারপ্রাপ্ত হয়ে নিজের কাগজ অনুমোদন করে ফেললেন।');

        $this->assertSame(0, ApprovalDecision::query()->where('approval_id', $approval->id)->count());
    }

    // ── ২ · ⛔ নিজের কাগজে নিজে — উপরে-পাঠানোর পথে ────────────────────

    public function test_the_maker_cannot_sign_their_own_paper_as_the_escalation_target(): void
    {
        $this->flow([$this->boss], escalateTo: $this->clerk);

        $approval = $this->ask();
        $approval->update(['escalated_at' => now()]);

        $this->assertFalse($this->engine()->canDecide($approval->fresh(), $this->clerk), implode("\n", [
            'কাগজটা উপরে গেল অনুরোধকারীর কাছেই, আর তিনি নিজের কাগজে সই দিতে পারছেন।',
        ]));
    }

    // ── ৩ · ⛔ তিনজনের দুইজন — একজন দুইবার ────────────────────────────

    public function test_a_delegate_signing_twice_on_a_two_of_three_level_counts_once(): void
    {
        $this->flow([$this->boss, $this->second, $this->third], minApprovals: 2);

        $this->delegate($this->boss, $this->deputy);

        $approval = $this->ask();

        $this->engine()->approve($approval, $this->deputy);

        $this->assertRefused(fn () => $this->engine()->approve($approval->fresh(), $this->deputy),
            'একই ভারপ্রাপ্ত একই স্তরে দুইবার সই দিলেন — দুইজনের কাজ একজনে হলো।');

        $this->assertSame(1, ApprovalDecision::query()->where('approval_id', $approval->id)->count(),
            'একজনের দুইটা সই খাতায় বসেছে।');

        $this->assertSame(Approval::PENDING, $approval->fresh()->status,
            '⛔ তিনজনের দুইজন লাগে, অথচ একজনের দুই সইয়েই কাগজ পাশ।');
    }

    // ── ৪ · ⛔ পুরনো কপি দিয়ে দ্বিতীয় সিদ্ধান্ত ────────────────────────

    public function test_a_stale_copy_cannot_approve_a_paper_already_approved(): void
    {
        Event::fake([ApprovalDecided::class]);

        $this->flow([$this->boss, $this->deputy]);

        $first = $this->ask();
        $stale = Approval::query()->findOrFail($first->id);

        $this->engine()->approve($first, $this->boss);

        $this->assertRefused(fn () => $this->engine()->approve($stale, $this->deputy),
            'পুরনো কপি দেখে অনুমোদিত কাগজে আবার সই বসল।');

        $this->assertSame(1, ApprovalDecision::query()->where('approval_id', $first->id)->count(),
            'একটা কাগজে দুইটা শেষ-সই।');

        Event::assertDispatchedTimes(ApprovalDecided::class, 1);
    }

    public function test_a_stale_copy_cannot_reject_a_paper_already_approved(): void
    {
        $this->flow([$this->boss, $this->deputy]);

        $first = $this->ask();
        $stale = Approval::query()->findOrFail($first->id);

        $this->engine()->approve($first, $this->boss);

        $this->assertRefused(fn () => $this->engine()->reject($stale, $this->deputy, 'না'),
            'পুরনো কপি দেখে অনুমোদিত কাগজ বাতিল হয়ে গেল।');

        $this->assertSame(Approval::APPROVED, $first->fresh()->status);
        $this->assertSame(1, ApprovalDecision::query()->where('approval_id', $first->id)->count());
    }

    // ── হাতিয়ার ────────────────────────────────────────────────────────

    /** @param  list<User>  $signers  সবাই স্তর ১-এ */
    private function flow(array $signers, int $minApprovals = 1, ?User $escalateTo = null): void
    {
        $flow = ApprovalFlow::create(['module' => 'sales', 'action' => 'discount']);

        foreach ($signers as $signer) {
            ApprovalFlowStep::create([
                'approval_flow_id' => $flow->id,
                'level' => 1,
                'approver_type' => ApprovalFlowStep::BY_USER,
                'approver_id' => $signer->id,
                'min_approvals' => $minApprovals,
                'escalate_to_type' => $escalateTo ? ApprovalFlowStep::BY_USER : null,
                'escalate_to_id' => $escalateTo?->id,
            ]);
        }
    }

    private function delegate(User $from, User $to): void
    {
        app(DelegationService::class)->grant(
            from: $from,
            to: $to,
            startsOn: now()->toDateString(),
            endsOn: now()->addDays(3)->toDateString(),
        );
    }

    private function ask(): Approval
    {
        $approval = $this->engine()->request(
            Branch::create(['code' => 'S'.uniqid(), 'name_en' => 'Doc']),
            'sales', 'discount', '50000', null, 'test', $this->clerk->id,
        );

        $this->assertNotNull($approval, 'অনুরোধটাই বসেনি — পরীক্ষাটা কিছু মাপছে না।');

        return $approval;
    }

    /**
     * ⚠️ `try { ... $this->fail() } catch (RuntimeException)` চলে না — PHPUnit-এর
     * নিজের ব্যর্থতাও RuntimeException, তাই `fail()` নিজেই গিলে ফেলা হত।
     */
    private function assertRefused(callable $act, string $message): void
    {
        $refused = false;

        try {
            $act();
        } catch (RuntimeException) {
            $refused = true;
        }

        $this->assertTrue($refused, $message);
    }

    private function engine(): ApprovalEngine
    {
        return app()->make(ApprovalEngine::class);
    }
}
