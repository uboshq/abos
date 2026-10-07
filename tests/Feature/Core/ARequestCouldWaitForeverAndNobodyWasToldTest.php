<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Approval\ApprovalSla;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\Notification;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * একটা অনুরোধ চিরকাল পড়ে থাকতে পারত, আর কাউকে বলা হত না।
 *
 * ── ⛔ যা ভাঙা ছিল, ২৪ সেপ্টেম্বর ২০২৬ ───────────────────────────────
 * অনুমোদনের অনুরোধ বসত, আর তারপর **কিছুই হত না**। ⓘ যিনি সই দেবেন তিনি
 * ইনবক্স না খুললে কোনোদিন জানতেনই না।
 *
 * ⚠️ রিপোর্টে `waiting_days` দেখা যেত — কিন্তু **দেখা** আর **জানানো**
 * এক নয়। যে সংখ্যাটা কেউ খুলে দেখে না, সেটা থাকা আর না থাকা সমান।
 *
 * ── ⭐ মালিকের দুইটা সিদ্ধান্ত এখানেই মাপা হয় ───────────────────────
 * ১ · সময় বসে **ধাপের গায়ে**, প্রবাহের নয় — ছুটির আবেদন আর পাঁচ লাখের
 * অর্ডার এক সময় পায় না।
 * ২ · দেরি হলে কাগজ যায় **প্রবাহে নাম-ধরে বসানো** মানুষের কাছে, পরের
 * ধাপের জনের কাছে নয়।
 */
final class ARequestCouldWaitForeverAndNobodyWasToldTest extends TestCase
{
    use RefreshDatabase;

    private User $clerk;

    private User $boss;

    private User $chief;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set((int) $company->id, $company->defaultBranch()?->id);

        $users = User::query()->orderBy('id')->take(3)->get();

        $this->clerk = $users[0];
        $this->boss = $users[1];
        $this->chief = $users[2];

        foreach ([$this->clerk, $this->boss, $this->chief] as $user) {
            $user->switchCompany((int) $company->id);
        }
    }

    /**
     * ⭐ ঘড়িটা ধাপের গা থেকে আসে, আর অনুরোধের গায়ে বসে।
     */
    public function test_the_clock_comes_from_the_step(): void
    {
        $this->flow(slaHours: 6);

        $approval = $this->ask();

        $this->assertNotNull($approval->due_at, implode("\n", [
            'অনুরোধটার কোনো সময়সীমা বসেনি।',
            '',
            '⛔ তাহলে কমান্ডটা ওটাকে কোনোদিন খুঁজেই পাবে না — সে',
            '`due_at` ধরে খোঁজে।',
        ]));

        $this->assertSame(6, (int) round($approval->requested_at->diffInHours($approval->due_at)),
            'ধাপে ৬ ঘণ্টা বসানো, অথচ সময়সীমা বসেছে অন্য হিসাবে।');
    }

    /**
     * ⛔ ধাপে সময় না বসালে ঘড়িও নেই — পুরনো প্রবাহ অবিকল আগের মতো।
     *
     * ── ⚠️ কেন এই দাবিটা ছাড়া উপরেরটা বিপজ্জনক ─────────────────────
     * ⓘ ডিফল্ট শূন্য বসালে **প্রতিটা পুরনো অনুরোধ সঙ্গে সঙ্গে দেরি**
     * হয়ে যেত, আর প্রথম দিনেই সব কাগজ উপরে চলে যেত।
     */
    public function test_a_step_with_no_clock_has_no_deadline(): void
    {
        $this->flow(slaHours: null);

        $this->assertNull($this->ask()->due_at,
            'ধাপে কোনো সময় বসানো নেই, তবু সময়সীমা বসেছে।');
    }

    /**
     * ⭐ সময় পার হলে কাগজ প্রবাহে বসানো মানুষের কাছে যায়।
     */
    public function test_a_late_request_reaches_the_named_person(): void
    {
        $this->flow(slaHours: 4, escalateTo: $this->chief);

        $approval = $this->ask();
        $approval->update(['requested_at' => now()->subHours(9), 'due_at' => now()->subHours(5)]);

        $this->artisan('abos:approvals-due')->assertSuccessful();

        $approval->refresh();

        $this->assertNotNull($approval->escalated_at, implode("\n", [
            'সময় পার হয়েছে, অথচ কাগজটা কোথাও যায়নি।',
            '',
            '⛔ এটাই আগের অবস্থা — অনুরোধ পড়ে থাকত, আর কেউ জানত না।',
        ]));

        $this->assertSame($this->chief->id, (int) $approval->escalated_to,
            'কাগজটা ভুল মানুষের কাছে গেছে।');

        $this->assertTrue($this->heard($this->chief, 'approval.escalated'),
            'খাতায় লেখা হয়েছে, কিন্তু মানুষটাকে বলা হয়নি।');
    }

    /**
     * ⛔ সময়ের **ভিতরে** থাকা কাগজ কমান্ড ছোঁয় না।
     *
     * ── ⚠️ নিয়ন্ত্রণ সারি ─────────────────────────────────────────
     * ⓘ এটা ছাড়া উপরের দাবিটা একটা **সবাইকে-পাঠানো** কমান্ডও পাস
     * করত, আর তখন প্রতিটা অনুরোধ জন্মের মুহূর্তেই উপরে চলে যেত।
     */
    public function test_a_request_still_in_time_is_left_alone(): void
    {
        $this->flow(slaHours: 4, escalateTo: $this->chief);

        $approval = $this->ask();

        $this->artisan('abos:approvals-due')->assertSuccessful();

        $this->assertNull($approval->fresh()->escalated_at, implode("\n", [
            'এখনো সময় আছে, তবু কাগজটা উপরে পাঠানো হয়েছে।',
            '',
            '⛔ তাহলে কমান্ডটা সময় দেখে না — সবাইকে পাঠায়।',
        ]));
    }

    /**
     * ⛔ দুইবার চললে দুইবার পাঠায় না।
     *
     * ⓘ কমান্ডটা প্রতি ঘণ্টায় চলে। ⚠️ এই পাহারাটা না থাকলে একটা দেরি
     * করা কাগজ **প্রতি ঘণ্টায়** একটা করে বার্তা পাঠাত, আর মানুষ
     * বার্তা পড়াই বন্ধ করে দিতেন — যা বার্তা না পাঠানোর চেয়েও খারাপ।
     */
    public function test_running_twice_does_not_tell_twice(): void
    {
        $this->flow(slaHours: 4, escalateTo: $this->chief);

        $approval = $this->ask();
        $approval->update(['requested_at' => now()->subHours(9), 'due_at' => now()->subHours(5)]);

        $this->artisan('abos:approvals-due')->assertSuccessful();
        $first = Notification::query()->where('type', 'approval.escalated')->count();

        $this->artisan('abos:approvals-due')->assertSuccessful();

        $this->assertSame($first, Notification::query()->where('type', 'approval.escalated')->count(),
            'দ্বিতীয়বার চালানোয় আবার বার্তা গেছে।');
    }

    /**
     * ⛔ ঘড়ি বসানো, অথচ যাওয়ার জায়গা নেই — এটা চুপচাপ পার হয় না।
     *
     * ── ⚠️ মালিকের বাছাইয়ের দাম ───────────────────────────────────
     * ⓘ গন্তব্য **প্রবাহে বসাতে হয়** (পরের ধাপের জন নয়)। ⛔ কেউ
     * সময় বসিয়ে গন্তব্য বসাতে ভুলে গেলে কাগজ কোথাও যেত না, আর
     * মালিক ভাবতেন ব্যবস্থাটা কাজ করছে।
     */
    public function test_a_clock_with_nowhere_to_go_is_reported(): void
    {
        $this->flow(slaHours: 4, escalateTo: null);

        $approval = $this->ask();
        $approval->update(['requested_at' => now()->subHours(9), 'due_at' => now()->subHours(5)]);

        $this->artisan('abos:approvals-due')
            ->expectsOutputToContain('no_target=1')
            ->assertSuccessful();

        $this->assertNull($approval->fresh()->escalated_at,
            'গন্তব্য নেই, তবু কাগজটা কোথাও পাঠানো হয়েছে।');
    }

    /**
     * ⭐ ধাপ এগোলে ঘড়ি নতুন করে শুরু হয়।
     *
     * ⛔ না হলে তিন ধাপের কাগজে **প্রথম ধাপের ঘড়িই** শেষ পর্যন্ত চলত,
     * আর দ্বিতীয় ধাপের মানুষ জন্মের মুহূর্তেই দেরি করে ফেলতেন।
     */
    public function test_the_clock_restarts_when_the_step_advances(): void
    {
        $this->flow(slaHours: 4, escalateTo: $this->chief, second: true);

        $approval = $this->ask();
        $approval->update(['requested_at' => now()->subHours(9), 'due_at' => now()->subHours(5)]);

        app(ApprovalEngine::class)->approve($approval->fresh(), $this->boss);

        $approval->refresh();

        $this->assertSame(2, $approval->current_level, 'ধাপ এগোয়নি।');

        $this->assertTrue($approval->due_at->isFuture(), implode("\n", [
            'দ্বিতীয় ধাপের মানুষ জন্মের মুহূর্তেই দেরি করে ফেলেছেন।',
            '',
            '⛔ প্রথম ধাপের ঘড়িটাই চলছে।',
        ]));

        $this->assertNull($approval->escalated_at, 'নতুন ধাপে পুরনো ছাপটা রয়ে গেছে।');
    }

    /**
     * ⭐ অবস্থাটা এক জায়গা থেকেই আসে।
     *
     * ⓘ ইনবক্সের চিহ্ন, দেরির ছাঁকনি আর কমান্ড — তিনটাই
     * [[ApprovalSla::stateOf()]] জিজ্ঞেস করে। ⛔ তিন জায়গায় আলাদা
     * হিসাব হলে পর্দা বলত "সময়ের ভিতরে" আর কমান্ড ওটাকে উপরে পাঠাত।
     */
    public function test_the_state_says_fine_near_and_late(): void
    {
        $this->flow(slaHours: 10);

        $sla = app(ApprovalSla::class);
        $approval = $this->ask();

        $this->assertSame(ApprovalSla::FINE, $sla->stateOf($approval));

        $approval->update(['requested_at' => now()->subHours(6), 'due_at' => now()->addHours(4)]);
        $this->assertSame(ApprovalSla::NEAR, $sla->stateOf($approval->fresh()),
            'অর্ধেক সময় পার, তবু সতর্ক করা হয়নি।');

        $approval->update(['due_at' => now()->subHour()]);
        $this->assertSame(ApprovalSla::LATE, $sla->stateOf($approval->fresh()));

        /* ⚠️ শেষ হয়ে যাওয়া কাগজকে "দেরি" বলা মানে রিপোর্টে চিরকালের লাল */
        $approval->update(['status' => Approval::APPROVED]);
        $this->assertSame(ApprovalSla::NONE, $sla->stateOf($approval->fresh()),
            'শেষ হয়ে যাওয়া কাগজও দেরি দেখাচ্ছে।');
    }

    // ── সহায়ক ───────────────────────────────────────────────────────

    private function flow(?int $slaHours, ?User $escalateTo = null, bool $second = false): ApprovalFlow
    {
        $flow = ApprovalFlow::query()->create([
            'company_id' => CompanyContext::id(),
            'module' => 'customer',
            'action' => 'credit_limit',
            'document_type' => 'Customer',
            'threshold_amount' => '0',
            'is_active' => true,
        ]);

        ApprovalFlowStep::query()->create([
            'approval_flow_id' => $flow->id,
            'level' => 1,
            'approver_type' => ApprovalFlowStep::BY_USER,
            'approver_id' => $this->boss->id,
            'requires_all' => false,
            'sla_hours' => $slaHours,
            'escalate_to_type' => $escalateTo === null ? null : 'user',
            'escalate_to_id' => $escalateTo?->id,
        ]);

        if ($second) {
            ApprovalFlowStep::query()->create([
                'approval_flow_id' => $flow->id,
                'level' => 2,
                'approver_type' => ApprovalFlowStep::BY_USER,
                'approver_id' => $this->chief->id,
                'requires_all' => false,
                'sla_hours' => 12,
            ]);
        }

        return $flow;
    }

    private function ask(): Approval
    {
        $customer = Customer::query()->firstOrFail();

        $approval = app(ApprovalEngine::class)->request(
            $customer, 'customer', 'credit_limit', '50000', null, 'test', $this->clerk->id,
        );

        $this->assertNotNull($approval, 'অনুরোধটাই বসেনি — ছকটা কি ধরেনি?');

        return $approval;
    }

    private function heard(User $user, string $type): bool
    {
        return Notification::query()
            ->where('user_id', $user->id)
            ->where('type', $type)
            ->exists();
    }
}
