<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Engines\Approval\DocumentFingerprint;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalCondition;
use App\Models\ApprovalDelegation;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Approval\Services\ApprovalExceptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * ছয় ধরনের নীরব ভুল — ছয়টার ছয়টাই সত্যিই ধরা পড়ে।
 *
 * ── ⛔ কেন এই ফাইলটা ছাড়া পর্দাটা অর্থহীন ────────────────────────────
 * ⓘ [[ApprovalExceptions]]-এর গোটা কারণটা হলো *"যা চুপ করে আছে তা
 * দেখানো"*। ⚠️ কিন্তু একটা খোঁজ যদি নিজেই কিছু না খোঁজে, সে **খালি
 * তালিকা** ফেরায় — আর খালি তালিকা দেখতে ঠিক *"সব ঠিক আছে"*-র মতো।
 *
 * ⛔ অর্থাৎ একটা ভাঙা খোঁজ আর একটা পরিষ্কার ব্যবস্থা পর্দায় হুবহু এক
 * দেখায়। ⓘ তাই প্রতিটা ধরনকে **তার নিজের ভুলটা খাওয়ানো হয়**, আর
 * দেখা হয় সে সত্যিই সেটা ধরে ([[guards-must-be-fed-the-dangerous-input]])।
 */
class NothingSilentSurvivesTheExceptionScreenTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $boss;

    private User $clerk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['code' => 'EX', 'name_en' => 'Exceptions Co']);
        CompanyContext::set($this->company->id);

        Branch::create(['code' => 'MAIN', 'name_en' => 'Main']);

        /*
         * ⓘ অনুমতির সারিগুলো আগে থাকতে হয়।
         *
         * ⛔ `givePermissionTo()` অচেনা নামে ছুঁড়ে ফেলে, আর সারিগুলো
         * সাধারণত ইনস্টলার প্রতিটা `module.php` ঘুরে লেখে।
         *
         * ⚠️ হাতে বানানো কোম্পানিতে কোনো ইনস্টলার নেই, তাই তিনটা
         * চাবি এখানে বসানো — আর কেবল এই তিনটাই, যাতে অন্য কিছু
         * নীরবে ঢুকে না পড়ে।
         */
        foreach (['approval.view', 'approval.decide', 'approval.flow.manage'] as $key) {
            Permission::findOrCreate($key, 'web');
        }

        $this->boss = User::factory()->create(['current_company_id' => $this->company->id]);
        $this->boss->companies()->attach($this->company->id);
        $this->boss->givePermissionTo(['approval.view', 'approval.flow.manage']);

        $this->clerk = User::factory()->create(['current_company_id' => $this->company->id]);
        $this->clerk->companies()->attach($this->company->id);
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    /**
     * ⛔ ঘড়ি বসানো, যাওয়ার জায়গা নেই।
     *
     * ⚠️ সময় পার হয়, ঘণ্টার কমান্ড চলে, আর **কিছুই হয় না** — আর মালিক
     * ভাবতেন ব্যবস্থাটা কাজ করছে।
     */
    public function test_a_clock_with_nowhere_to_go_is_found(): void
    {
        $flow = $this->flow();
        $flow->steps()->update(['sla_hours' => 4, 'escalate_to_type' => null, 'escalate_to_id' => null]);

        $this->assertHasKind('no_escalation_target');
    }

    /** ⭐ আর গন্তব্য বসালে সারিটা চলে যায় — নাহলে তালিকা চিরকাল ভরা থাকত। */
    public function test_a_clock_with_a_destination_is_left_alone(): void
    {
        $flow = $this->flow();
        $flow->steps()->update([
            'sla_hours' => 4,
            'escalate_to_type' => ApprovalFlowStep::BY_USER,
            'escalate_to_id' => $this->boss->id,
        ]);

        $this->assertLacksKind('no_escalation_target');
    }

    /** ⛔ সময় পার, এখনো কারও হাতে যায়নি। */
    public function test_a_breached_clock_is_found(): void
    {
        $this->flow();
        $this->waiting(due: now()->subDays(2));

        $this->assertHasKind('sla_breached');
    }

    /** ⭐ সময়ের ভিতরে থাকা কাগজ সারিতে আসে না — নিয়ন্ত্রণ সারি। */
    public function test_a_paper_still_in_time_is_not_listed(): void
    {
        $this->flow();
        $this->waiting(due: now()->addDays(2));

        $this->assertLacksKind('sla_breached');
    }

    /**
     * ⛔ সইয়ের পর কাগজটা বদলেছে।
     *
     * ⓘ ছাপ মেলে না মানে কোনো ঘর বদলেছে, আর মালিকের সিদ্ধান্তে সেটা সই
     * বাতিল করে। ⚠️ এখানে গোনা হয় যাতে দেখা যায় **কত ঘন ঘন** এটা ঘটছে।
     */
    public function test_a_paper_changed_after_signing_is_found(): void
    {
        $this->flow();

        $document = Branch::query()->firstOrFail();

        $approval = $this->waiting(status: Approval::APPROVED);
        $approval->update(['state_hash' => app(DocumentFingerprint::class)->of($document)]);

        $this->assertLacksKind('changed_after_approval');   // ⓘ এখনো বদলায়নি

        $document->update(['name_en' => 'Renamed after the signature']);

        $this->assertHasKind('changed_after_approval');
    }

    /** ⓘ ভারের মেয়াদ শেষ — তথ্য, ভুল নয়; কিন্তু দেখা দরকার। */
    public function test_an_expired_delegation_is_found(): void
    {
        ApprovalDelegation::create([
            'company_id' => $this->company->id,
            'from_user_id' => $this->boss->id,
            'to_user_id' => $this->clerk->id,
            'starts_on' => now()->subDays(10)->toDateString(),
            'ends_on' => now()->subDays(2)->toDateString(),
        ]);

        $this->assertHasKind('delegation_expired');
    }

    /**
     * ⛔ এমন শর্ত যা কখনো মিলতেই পারে না।
     *
     * ⚠️ শর্তটা এমন একটা ঘরের উপর বসানো যা ঐ কাজের মডিউল কখনো পাঠায় না।
     * ⓘ তখন প্রবাহটা **কখনো ধরে না**, আর মালিক ভাবেন অনুমোদন বসানো আছে।
     */
    public function test_a_condition_that_can_never_match_is_found(): void
    {
        $flow = $this->flow();

        ApprovalCondition::create([
            'company_id' => $this->company->id,
            'approval_flow_id' => $flow->id,
            'field' => 'a_field_nobody_sends',
            'operator' => '>',
            'value' => '1',
        ]);

        /*
         * ⓘ একটা অনুরোধ লাগে, আর তার `payload`-এ যে ঘরগুলো সত্যিই আসে।
         * ⚠️ একটাও অনুরোধ না থাকলে খোঁজটা **চুপ থাকে**, আর সেটাই সৎ:
         * অনুমান করে "মিলবে না" বলা একটা মিথ্যা লাল।
         */
        $this->assertLacksKind('condition_never_matches');

        $this->waiting(payload: ['discount_percent' => '5']);

        $this->assertHasKind('condition_never_matches');
    }

    /**
     * ⓘ যে কাজ অনুমোদন চায়, অথচ কোনো প্রবাহ নেই।
     *
     * ⚠️ তখন কাগজটা **কোনো সই ছাড়াই** পার হয়ে যায় — ইঞ্জিন `null`
     * ফেরায়, আর সেটা "এগিয়ে যাও" মানে।
     */
    public function test_an_action_with_no_flow_is_found(): void
    {
        // ⓘ একটাও প্রবাহ বসানো হয়নি, তাই ঘোষিত প্রতিটা কাজই এই সারিতে
        $this->assertHasKind('no_flow');
    }

    /** ⛔ আর পর্দাটা সত্যিই ছয়টা নাম চেনে — কোনোটার লেখা ফাঁকা নয়। */
    public function test_the_screen_has_a_name_for_every_kind(): void
    {
        foreach ([
            'no_escalation_target', 'sla_breached', 'changed_after_approval',
            'delegation_expired', 'condition_never_matches', 'no_flow',
        ] as $kind) {
            $key = 'approval::exception.kind.'.$kind;

            /*
             * ⛔ অনুবাদ না পেলে Laravel **চাবিটাই ছাপে** — কোনো ব্যতিক্রম
             * নেই, লগে কিছু নেই। ⚠️ তাই পর্দাটা দেখতে কাজ করার মতোই
             * লাগত, আর পাঠক `approval::exception.kind.no_flow` পড়তেন।
             */
            $this->assertNotSame($key, __($key), '⛔ এই ধরনের কোনো লেখা নেই: '.$kind);
        }
    }

    // ── সহায়ক ───────────────────────────────────────────────────────

    private function assertHasKind(string $kind): void
    {
        $this->assertTrue($this->kinds()->contains($kind), implode("\n", [
            'এই নীরব ভুলটা ব্যতিক্রমের সারিতে আসেনি: '.$kind,
            '',
            '⛔ খোঁজটা কিছু না খুঁজলে খালি তালিকা ফেরায় — আর খালি তালিকা',
            '   দেখতে ঠিক "সব ঠিক আছে"-র মতো।',
        ]));

        // ⓘ আর পর্দাটাও সত্যিই লেখাটা দেখায়, কেবল সেবাটা নয়
        $this->actingAs($this->boss)->get(route('approval.exception.index'))
            ->assertOk()
            ->assertSee(__('approval::exception.kind.'.$kind));
    }

    private function assertLacksKind(string $kind): void
    {
        $this->assertFalse($this->kinds()->contains($kind),
            '⛔ ভুলটা নেই, তবু সারিতে এসেছে: '.$kind);
    }

    /** @return Collection<int, string> */
    private function kinds(): Collection
    {
        return app(ApprovalExceptions::class)->all()->pluck('kind');
    }

    private function flow(): ApprovalFlow
    {
        $flow = ApprovalFlow::create(['module' => 'sales', 'action' => 'discount']);

        ApprovalFlowStep::create([
            'approval_flow_id' => $flow->id,
            'level' => 1,
            'approver_type' => ApprovalFlowStep::BY_USER,
            'approver_id' => $this->boss->id,
        ]);

        return $flow;
    }

    /** @param array<string, mixed>|null $payload */
    private function waiting(
        ?Carbon $due = null,
        string $status = Approval::PENDING,
        ?array $payload = null,
    ): Approval {
        return Approval::create([
            'company_id' => $this->company->id,
            'approvable_type' => Branch::class,
            'approvable_id' => Branch::query()->value('id'),
            'module' => 'sales',
            'action' => 'discount',
            'amount' => '50000',
            'status' => $status,
            'current_level' => 1,
            'requested_by' => $this->clerk->id,
            'requested_at' => now()->subDays(3),
            'due_at' => $due,
            'payload' => $payload,
            'decided_at' => $status === Approval::APPROVED ? now()->subDay() : null,
        ]);
    }
}
