<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Approval;

use App\Core\Support\CompanyContext;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\User;
use App\Modules\Approval\Services\ApprovalFlowService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ অনুমোদনের ধাপ বদলালে খাতায় দাগ থাকে — অডিট §৩, ২৭ সেপ্টেম্বর ২০২৬।
 *
 * ── কী ভাঙা ছিল ──────────────────────────────────────────────────────
 * অডিটের কথা: *"অনুমোদন-ধাপের পরিবর্তনের কোনো খাতা থাকে না।"* ⓘ
 * [[ApprovalFlowStep]] ছিল [[EveryChangeableRowRemembersWhoChangedItTest]]-এর
 * ছাড়ের তালিকায়, কারণ হিসেবে *"ApprovalFlow নিজে অডিটে আছে"*।
 *
 * ⚠️ কিন্তু ছকের অডিট দেখে কেবল ছকের ঘর — মডিউল, কাজ, সীমা। ⛔ **কে সই
 * দেবেন**, সেটা থাকে ধাপের সারিতে। অর্থাৎ ৫ লাখের বিলের সইকারী মালিক
 * থেকে বদলে একজন কেরানি করে দেওয়া যেত, আর পরে *"কে, কবে"* জিজ্ঞেস
 * করার মতো কিছুই থাকত না।
 *
 * ── ⚠️ কেবল `use IsAudited;` কেন যথেষ্ট ছিল না ────────────────────────
 * [[ApprovalFlowService]] ধাপগুলো বসাত `$flow->steps()->delete()` দিয়ে —
 * একটা কোয়েরি-মোছা, যা মডেলের ঘটনা ডাকে না। ⓘ তাই ট্রেইট বসালেও
 * পুরনো সইকারী নিঃশব্দে যেতেন, আর খাতায় উঠত কেবল নতুনজনের "তৈরি"।
 * ⭐ এই ফাইলের প্রথম তিনটা দাবি ঠিক সেই পথটা মাপে — পর্দা যে পথে যায়।
 */
final class AStepChangeLeavesATrailTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $clerk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->clerk = User::query()->where('email', 'sales@abos.test')->firstOrFail();

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->owner);
    }

    /**
     * ⭐ সইকারী বদলালে খাতায় বসে: কে ছিলেন, কে এলেন, কে বদলালেন।
     *
     * ⓘ সারিটা **একই থাকে** (আইডি বদলায় না) — নাহলে "পুরনো → নতুন"
     * বলার মতো কোনো সারিই থাকত না, কেবল একটা মোছা আর একটা তৈরি।
     */
    public function test_changing_who_signs_a_step_records_the_old_and_the_new_signer(): void
    {
        $flow = $this->aFlow([$this->byUser(1, $this->owner)]);
        $stepId = (int) $flow->steps->sole()->id;

        $this->saveFlow($flow, [$this->byUser(1, $this->clerk)]);

        $this->assertSame($stepId, (int) $this->stepsOf($flow)->sole()->id,
            'সইকারী বদলাতে গিয়ে ধাপের সারিটাই মুছে নতুন করে বসেছে — তাহলে "কে ছিলেন" '
            .'বলার মতো কোনো সারি খাতায় থাকে না।');

        $trail = $this->trailsOf($stepId)
            ->where('action', AuditTrail::UPDATED)
            ->with('changes')
            ->sole();

        $byField = $trail->changes->keyBy('field');

        $this->assertArrayHasKey('approver_id', $byField->all(),
            'ধাপ বদলেছে, অথচ খাতায় সইকারীর ঘরটাই নেই।');
        $this->assertSame((string) $this->owner->id, $byField['approver_id']->old_value,
            'খাতা বলে না আগে কে সই দিতেন।');
        $this->assertSame((string) $this->clerk->id, $byField['approver_id']->new_value,
            'খাতা বলে না এখন কে সই দেবেন।');

        $this->assertSame($this->owner->id, $trail->user_id, 'বদলটা কে করলেন, খাতায় নেই।');
        $this->assertSame($this->company->id, $trail->company_id, 'দাগটা ভুল কোম্পানির খাতায় বসেছে।');
    }

    /**
     * ⛔ একটা স্তর তুলে দিলে তার "মোছা" খাতায় ওঠে।
     *
     * ⓘ কোয়েরি-মোছায় এটাই হারাত — দ্বিতীয় সইকারী স্রেফ উধাও, আর
     * ছক দেখে বোঝার উপায় থাকত না যে আগে দুই স্তর ছিল।
     */
    public function test_a_level_that_is_taken_away_leaves_a_deleted_row(): void
    {
        $flow = $this->aFlow([$this->byUser(1, $this->clerk), $this->byUser(2, $this->owner)]);
        $second = (int) $this->stepsOf($flow)->firstWhere('level', 2)->id;

        $this->saveFlow($flow, [$this->byUser(1, $this->clerk)]);

        $this->assertFalse(ApprovalFlowStep::query()->whereKey($second)->exists());

        $this->assertTrue(
            $this->trailsOf($second)->where('action', AuditTrail::DELETED)->exists(),
            'মালিকের স্তরটা তুলে দেওয়া হলো, আর খাতায় তার কোনো দাগ নেই।',
        );
    }

    /**
     * ⚠️ দুইজনের স্তর নতুন করে বসলে — সারি মেলানো যায় না, তবু কেউ হারায় না।
     *
     * ⓘ এখানে "কোনটা কোনটা" প্রশ্নের একটা উত্তর নেই, তাই সার্ভিস আগের
     * নিয়মেই মুছে নতুন বসায়। ⭐ দাবিটা কেবল এই: **প্রতিটা** পুরনো ধাপের
     * মোছা আর প্রতিটা নতুনের তৈরি — দুইটাই খাতায়।
     */
    public function test_a_level_rebuilt_from_scratch_still_shows_who_left_and_who_came(): void
    {
        $flow = $this->aFlow([$this->byUser(1, $this->owner), $this->byUser(1, $this->clerk)]);
        $old = $this->stepsOf($flow)->pluck('id')->map(fn ($id) => (int) $id)->all();

        $this->saveFlow($flow, [$this->byUser(1, $this->clerk)]);

        foreach ($old as $id) {
            $this->assertTrue($this->trailsOf($id)->where('action', AuditTrail::DELETED)->exists(),
                "পুরনো ধাপ #{$id} চলে গেছে, খাতায় দাগ ছাড়াই।");
        }

        $new = (int) $this->stepsOf($flow)->sole()->id;

        $this->assertTrue($this->trailsOf($new)->where('action', AuditTrail::CREATED)->exists(),
            'নতুন ধাপটা বসেছে, খাতায় তার জন্মের দাগ নেই।');
    }

    /**
     * ⓘ পর্দার পথ ছাড়াও — সারিটা নিজেই খাতা রাখে।
     *
     * ⚠️ সিডার, কনসোল, সারাইয়ের স্ক্রিপ্ট সার্ভিস দিয়ে যায় না। ট্রেইটটা
     * মডেলে বলেই ঐ পথগুলোও ঢাকা ([[IsAudited]]-এর নিজের কারণ)।
     */
    public function test_a_direct_edit_of_a_step_is_on_the_trail_too(): void
    {
        $step = $this->aFlow([$this->byUser(1, $this->owner)])->steps->sole();

        $step->update(['sla_hours' => 24]);

        $change = $this->trailsOf((int) $step->id)
            ->where('action', AuditTrail::UPDATED)
            ->with('changes')
            ->sole()
            ->changes
            ->firstWhere('field', 'sla_hours');

        $this->assertNotNull($change, 'ধাপের ঘড়ি বদলানো খাতায় ওঠেনি।');
        $this->assertNull($change->old_value);
        $this->assertSame('24', $change->new_value);
    }

    /**
     * ⛔ দাগটা ছকের কোম্পানির খাতায় — বদলকারী যে কোম্পানিতেই বসে থাকুন।
     *
     * ⓘ ধাপের নিজের `company_id` নেই। ⚠️ প্রসঙ্গ থেকে নিলে অন্য কোম্পানির
     * ঘরে বসে করা বদল **ভুল খাতায়** পড়ত — আর ভুল খাতার দাগ না-থাকা দাগের
     * চেয়েও খারাপ, কারণ খুঁজলে সেটা পাওয়াই যায় না।
     */
    public function test_the_mark_lands_in_the_flows_own_company(): void
    {
        $step = $this->aFlow([$this->byUser(1, $this->owner)])->steps->sole();

        $elsewhere = Company::query()->whereKeyNot($this->company->id)->firstOrFail();
        CompanyContext::set($elsewhere->id, $elsewhere->defaultBranch()?->id);

        ApprovalFlowStep::query()->whereKey($step->id)->firstOrFail()->update(['sla_hours' => 12]);

        $trail = $this->trailsOf((int) $step->id)->where('action', AuditTrail::UPDATED)->sole();

        $this->assertSame($this->company->id, $trail->company_id,
            'ধাপ এক কোম্পানির, দাগ বসেছে প্রসঙ্গের কোম্পানিতে।');
        $this->assertNull($trail->branch_id, 'ছক কোনো শাখার নয়; প্রসঙ্গের শাখা বসলে খাতাটা মিথ্যা বলে।');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function byUser(int $level, User $signer): array
    {
        return [
            'level' => $level,
            'approver_type' => ApprovalFlowStep::BY_USER,
            'approver_id' => $signer->id,
            'requires_all' => false,
        ];
    }

    /** @param list<array<string, mixed>> $steps */
    private function aFlow(array $steps): ApprovalFlow
    {
        return app(ApprovalFlowService::class)->create($this->flowData(), $steps);
    }

    /** ⓘ পর্দার "সংরক্ষণ" যা ডাকে ঠিক সেটাই — [[ApprovalFlowService::update()]]। */
    private function saveFlow(ApprovalFlow $flow, array $steps): void
    {
        app(ApprovalFlowService::class)->update($flow, $this->flowData(), $steps);
    }

    /** @return array<string, mixed> */
    private function flowData(): array
    {
        return ['module' => 'purchase', 'action' => 'order', 'is_active' => true];
    }

    private function stepsOf(ApprovalFlow $flow)
    {
        return ApprovalFlowStep::query()->where('approval_flow_id', $flow->id)->orderBy('level')->orderBy('id')->get();
    }

    /**
     * ⚠️ দেয়াল ছাড়া খোঁজা — শেষ দাবিটা প্রসঙ্গ বদলে দেয়, আর দাগ কোন
     * কোম্পানিতে বসেছে সেটাই তো প্রশ্ন; দেয়াল রাখলে ভুল খাতার দাগ
     * "নেই" দেখাত, "ভুল জায়গায় আছে" নয়।
     */
    private function trailsOf(int $stepId)
    {
        return AuditTrail::query()->withoutGlobalScopes()->forRecord(ApprovalFlowStep::class, $stepId);
    }
}
