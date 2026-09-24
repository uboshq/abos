<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Approval\ApprovalSla;
use App\Core\Engines\Approval\AuthorityService;
use App\Core\Engines\Approval\BulkApproval;
use App\Core\Engines\Approval\DelegationService;
use App\Core\Engines\Approval\DocumentApproval;
use App\Core\Engines\Approval\DocumentFingerprint;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalCondition;
use App\Models\ApprovalDecision;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\ApprovalLimit;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Approval\Services\ApprovalFlowService;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * অনুমোদনের ইঞ্জিনে আটটা নতুন নিয়ম বসল — ২৪ সেপ্টেম্বর ২০২৬।
 *
 * ── ⭐ স্পেকটা যা চেয়েছিল, আর যা সত্যিই ফাঁকা ছিল ────────────────────
 * ⓘ স্পেকটা পড়লে মনে হয় কিছুই নেই। অনেক কিছুই ছিল — কেন্দ্রীয় ইঞ্জিন,
 * ধাপে ধাপে সই, অনুরোধকারী ≠ অনুমোদনকারী, পিছনে কর্তৃত্ব যাচাই।
 *
 * ⚠️ যা **সত্যিই** ফাঁকা ছিল সেটা সংকীর্ণ আর বেশি বিপজ্জনক: ভার দেওয়ার
 * তারিখ নেই · শর্ত কেবল টাকার অঙ্ক · রোলের কোনো সীমা নেই · এক ধাপে হয়
 * সবাই নয় একজন · আর সবচেয়ে খারাপ — **সই দেওয়ার পর অঙ্ক ঠিক রেখে পণ্য
 * বদলে দিলে পুরনো সইটাই চলত**।
 *
 * ⓘ এই ফাইলটা ঐ নিয়মগুলোর প্রতিটাকে **তার বিপজ্জনক ইনপুট** খাওয়ায় —
 * আর প্রতিটা নিয়মের পাশে তার উল্টো সারিটাও, কারণ কেবল লাল সারি দিয়ে
 * প্রমাণ হয় না যে পুরনো আচরণ অটুট আছে।
 *
 * ⓘ [[ApprovalEngineTest]]-এর মতোই `Branch`-কে কাগজ ধরা হয়েছে: ইঞ্জিন
 * polymorphic, কোন কাগজ সেটা তার জানার কথা নয়।
 */
class TheApprovalEngineGrewEightNewRulesTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $clerk;

    private User $boss;

    private User $deputy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['code' => 'A8', 'name_en' => 'Eight Rules Co']);
        CompanyContext::set($this->company->id);

        $this->clerk = User::create(['name' => 'Clerk', 'email' => 'c@a8.test', 'password' => 'x']);
        $this->boss = User::create(['name' => 'Boss', 'email' => 'b@a8.test', 'password' => 'x']);
        $this->deputy = User::create(['name' => 'Deputy', 'email' => 'd@a8.test', 'password' => 'x']);
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    // ── ধাপ ২ · সইয়ের ভার ───────────────────────────────────────────

    /**
     * ⛔ পর্দা থেকে বসানো ছকেও `currentStep()` ধাপটা খুঁজে পায়।
     *
     * ── ⚠️ এটাই আজকের সবচেয়ে চওড়া ফাঁক ছিল ─────────────────────────
     * ⓘ ছক সংরক্ষণের সময় *"সব ধরনে"* লেখা হয় **খালি স্ট্রিং** দিয়ে, আর
     * সেটা ইচ্ছাকৃত ([[ApprovalFlowService]]-এ কারণ: unique index `null`
     * দুইবার আটকাতে পারে না)।
     *
     * ⛔ কিন্তু `currentStep()` fallback-এ `whereNull('document_type')`
     * খুঁজত — অর্থাৎ পর্দা থেকে বসানো **প্রতিটা** ছকে `null` ফেরাত।
     *
     * ⚠️ আর তার উপর যা যা দাঁড়ানো, সব নীরবে মরে যেত: `warn_hours` কখনো
     * খাটত না · প্রতিটা দেরি `no_target` হত · গন্তব্যের মানুষ কোনোদিন সই
     * দিতে পারতেন না। ⓘ কোথাও কিছু লাল হত না।
     */
    public function test_the_step_is_found_for_a_flow_saved_from_the_screen(): void
    {
        /*
         * ⓘ ছকটা পর্দার পথেই বসানো হয় — হাতে নয়।
         * ⚠️ `ApprovalFlow::create()` দিয়ে বসালে `document_type` খালিই থাকে
         * (মডেলের ডিফল্ট), আর তখন দাবিটা ঠিক ঐ পার্থক্যটাই মাপত না।
         */
        app(ApprovalFlowService::class)->create(
            ['module' => 'sales', 'action' => 'discount', 'is_active' => true],
            [[
                'level' => 1,
                'approver_type' => ApprovalFlowStep::BY_USER,
                'approver_id' => $this->boss->id,
                'sla_hours' => 4,
                'escalate_to_type' => ApprovalFlowStep::BY_USER,
                'escalate_to_id' => $this->deputy->id,
            ]],
        );

        $this->assertSame('', (string) ApprovalFlow::query()->value('document_type'),
            'ⓘ ধরে নেওয়াটা মেপে দেখা: সেবা খালি স্ট্রিং বসায়, `null` নয়।');

        $approval = $this->ask();

        $this->assertNotNull($approval->currentStep(), implode("\n", [
            'পর্দা থেকে বসানো ছকে ধাপটা খুঁজে পাওয়া যায়নি।',
            '',
            '⛔ তাহলে ঘড়ি, সতর্কতা আর উপরে পাঠানো — তিনটাই নীরবে মরা।',
        ]));

        $this->assertSame(4, (int) $approval->currentStep()->sla_hours);
    }

    /**
     * ⛔ উপরে পাঠানোর পর গন্তব্যের মানুষটা সত্যিই সই দিতে পারেন।
     *
     * ── ⚠️ এই জোড়টা না থাকলে গোটা ব্যবস্থাটা সাজসজ্জা ───────────────
     * ⓘ `abos:approvals-due` সময় পার হলে `escalated_at` বসায় আর গন্তব্যের
     * মানুষটাকে খবর পাঠায়। ⛔ কিন্তু `canDecide()` দেখত হয় `assigned_to`,
     * নয় প্রবাহের ধাপে ইনার নাম আছে কি না — আর তিনি ঐ ধাপে **নেই**
     * (থাকলে উপরে পাঠানোরই দরকার হত না)।
     *
     * ⚠️ ফল: তিনি খবর পেতেন, পাতাটা খুলতেন, আর বোতামটা থাকত না। ⓘ কোনো
     * ত্রুটি নেই, কোথাও কিছু লাল হয় না — কেবল কাগজটা আর কোনোদিন এগোয় না।
     */
    public function test_the_escalation_target_can_actually_sign(): void
    {
        $flow = $this->flow();

        /* ⓘ ঘড়ি বসানো, আর গন্তব্য deputy — যিনি ঐ ধাপে নেই */
        $flow->steps()->update([
            'sla_hours' => 4,
            'escalate_to_type' => ApprovalFlowStep::BY_USER,
            'escalate_to_id' => $this->deputy->id,
        ]);

        $approval = $this->ask();

        $this->assertFalse(app(ApprovalEngine::class)->canDecide($approval, $this->deputy),
            '⛔ উপরে পাঠানোর আগেই গন্তব্যের মানুষ সই দিতে পারছেন — তাহলে ঘড়িটার মানে নেই।');

        /*
         * ⓘ সময় পার, আর কমান্ড চালানো — আসল পথেই।
         * ⚠️ হাতে `escalated_at` বসালে দাবিটা কমান্ডটাকে ছুঁত না।
         */
        $approval->update(['due_at' => now()->subHour()]);

        $this->artisan('abos:approvals-due')->assertSuccessful();

        $this->assertNotNull($approval->fresh()->escalated_at, 'কাগজটা উপরেই পাঠানো হয়নি।');

        $this->assertTrue(app(ApprovalEngine::class)->canDecide($approval->fresh(), $this->deputy), implode("\n", [
            'কাগজটা ইনার কাছে পাঠানো হয়েছে, তবু সই দিতে পারছেন না।',
            '',
            '⛔ তাহলে খবরটা একটা মৃত চিঠি, আর কাগজটা আর কোনোদিন এগোয় না।',
        ]));
    }

    /**
     * ⭐ আর কাগজটা ইনার ইনবক্সেও আসে — দুইটা অংশ এক কথা বলে।
     *
     * ── ⛔ কেন এই দাবিটা আলাদা করে লাগে ──────────────────────────────
     * ⓘ নিয়মটা এখন দুই ভাষায় লেখা: `canDecide()` একটা সারি ধরে PHP-তে,
     * আর `pendingQueryFor()` পুরো তালিকাটা SQL-এ।
     *
     * ⚠️ আলাদা হলে ফলটা নীরব: কাগজটা সত্যিই ইনার সইয়ের অপেক্ষায়, অথচ
     * তালিকাতেই আসে না — আর **চিরকাল ঝুলে থাকে**। ⛔ কেউ বলে না কেন।
     */
    public function test_the_inbox_shows_what_was_escalated_to_me(): void
    {
        $flow = $this->flow();

        $flow->steps()->update([
            'sla_hours' => 4,
            'escalate_to_type' => ApprovalFlowStep::BY_USER,
            'escalate_to_id' => $this->deputy->id,
        ]);

        $approval = $this->ask();

        $this->assertFalse(
            app(ApprovalEngine::class)->pendingQueryFor($this->deputy)->whereKey($approval->id)->exists(),
            '⛔ উপরে পাঠানোর আগেই কাগজটা গন্তব্যের ইনবক্সে বসে আছে।',
        );

        $approval->update(['due_at' => now()->subHour()]);

        $this->artisan('abos:approvals-due')->assertSuccessful();

        $this->assertTrue(
            app(ApprovalEngine::class)->pendingQueryFor($this->deputy)->whereKey($approval->id)->exists(),
            implode("\n", [
                'কাগজটা ইনার কাছে পাঠানো হয়েছে, তবু ইনবক্সে নেই।',
                '',
                '⛔ সিদ্ধান্তের দরজা হ্যাঁ বলে, আর তালিকা কাগজটাই দেখায় না —',
                '   দুইটা আলাদা হলে কাগজ চিরকাল ঝুলে থাকে।',
            ]),
        );
    }

    /**
     * ⭐ আর গন্তব্য একটা **রোল** হলেও — কারণ `escalated_to` একজনের ঘর।
     *
     * ⛔ প্রথম জনের নাম বসে শুধু *"কার হাতে গেল"* জানার জন্য। ⚠️ ঐ ঘরটা
     * দিয়ে অনুমতি মাপলে রোলের বাকিরা কাগজটা দেখতেন আর সই দিতে পারতেন না।
     */
    public function test_an_escalation_to_a_role_reaches_everyone_in_it(): void
    {
        $role = Role::findOrCreate('escalation-role');
        $this->deputy->assignRole($role);

        $flow = $this->flow();

        $flow->steps()->update([
            'sla_hours' => 4,
            'escalate_to_type' => ApprovalFlowStep::BY_ROLE,
            'escalate_to_id' => $role->id,
        ]);

        $approval = $this->ask();
        $approval->update(['due_at' => now()->subHour()]);

        $this->artisan('abos:approvals-due')->assertSuccessful();

        $this->assertTrue(
            app(ApprovalEngine::class)->canDecide($approval->fresh(), $this->deputy->fresh()),
            '⛔ রোলে গন্তব্য বসানো, তবু ঐ রোলের মানুষ সই দিতে পারছেন না।',
        );
    }

    /** ⭐ মেয়াদের ভিতরে ভারপ্রাপ্ত জন সই দিতে পারেন, আর দুইজনের নামই বসে। */
    public function test_a_delegate_can_sign_and_both_names_are_kept(): void
    {
        $this->flow();

        app(DelegationService::class)->grant(
            from: $this->boss,
            to: $this->deputy,
            startsOn: now()->toDateString(),
            endsOn: now()->addDays(3)->toDateString(),
        );

        $approval = $this->ask();

        $this->assertTrue(app(ApprovalEngine::class)->canDecide($approval, $this->deputy),
            'মেয়াদের ভিতরেও ভারপ্রাপ্ত জন সই দিতে পারছেন না।');

        app(ApprovalEngine::class)->approve($approval, $this->deputy);

        $decision = ApprovalDecision::query()->where('approval_id', $approval->id)->firstOrFail();

        $this->assertSame((int) $this->deputy->id, (int) $decision->user_id, 'যিনি চাপ দিলেন তাঁর নাম নেই।');

        $this->assertSame((int) $this->boss->id, (int) $decision->on_behalf_of, implode("\n", [
            'কার হয়ে সই হলো, সেটা লেখা নেই।',
            '',
            '⛔ ছয় মাস পরে "এটা কে দিয়েছিল" প্রশ্নের উত্তর অর্ধেক হত।',
        ]));
    }

    /** ⛔ মেয়াদের বাইরে পারেন না — আর এটাই ভারের একমাত্র বিপদ। */
    public function test_a_delegate_cannot_sign_outside_the_dates(): void
    {
        $this->flow();

        app(DelegationService::class)->grant(
            from: $this->boss,
            to: $this->deputy,
            startsOn: now()->addDays(5)->toDateString(),
            endsOn: now()->addDays(9)->toDateString(),
        );

        $this->assertFalse(app(ApprovalEngine::class)->canDecide($this->ask(), $this->deputy), implode("\n", [
            'ভার এখনো শুরু হয়নি, তবু সই দেওয়া যাচ্ছে।',
            '',
            '⛔ তাহলে তারিখ দুইটা সাজসজ্জা।',
        ]));
    }

    /** ⛔ নিজেকে ভার দেওয়া যায় না — নাহলে নিজের অনুরোধে নিজেই সই। */
    public function test_nobody_can_delegate_to_themselves(): void
    {
        $this->expectException(ValidationException::class);

        app(DelegationService::class)->grant(
            from: $this->boss,
            to: $this->boss,
            startsOn: now()->toDateString(),
            endsOn: now()->addDay()->toDateString(),
        );
    }

    /** ⛔ ভার প্রত্যাহারের পর আর সই দেওয়া যায় না, আর সারিটা মোছা হয় না। */
    public function test_a_revoked_delegation_stops_working_but_is_kept(): void
    {
        $this->flow();

        $grant = app(DelegationService::class)->grant(
            from: $this->boss,
            to: $this->deputy,
            startsOn: now()->toDateString(),
            endsOn: now()->addDays(3)->toDateString(),
        );

        app(DelegationService::class)->revoke($grant);

        $this->assertFalse(app(ApprovalEngine::class)->canDecide($this->ask(), $this->deputy),
            '⛔ ভার তুলে নেওয়ার পরেও সই দেওয়া যাচ্ছে।');

        /*
         * ⓘ সারিটা থেকে যায়, কারণ ওই ভারে দেওয়া সইগুলো ইতিহাসে আছে।
         * ⛔ মুছে দিলে পুরনো সিদ্ধান্তের `on_behalf_of` একটা অনুপস্থিত
         * সারির দিকে তাকিয়ে থাকত।
         */
        $this->assertDatabaseHas('approval_delegations', ['id' => $grant->id]);
    }

    // ── ধাপ ৩ · অঙ্ক ছাড়াও শর্ত ──────────────────────────────────────

    /** ⭐ শর্ত মিললে প্রবাহ ধরে, না মিললে ধরে না। */
    public function test_a_condition_decides_whether_the_flow_catches(): void
    {
        $flow = $this->flow();

        ApprovalCondition::query()->create([
            'company_id' => $this->company->id,
            'approval_flow_id' => $flow->id,
            'field' => 'discount_percent',
            'operator' => '>',
            'value' => '10',
        ]);

        $engine = app(ApprovalEngine::class);

        $this->assertNull(
            $engine->request($this->document(), 'sales', 'discount', '50000',
                ['discount_percent' => '5'], 'test', $this->clerk->id),
            '⛔ ছাড় ৫% — শর্ত মেলে না, তবু অনুমোদন চাওয়া হয়েছে।',
        );

        $this->assertNotNull(
            $engine->request($this->document(), 'sales', 'discount', '50000',
                ['discount_percent' => '15'], 'test', $this->clerk->id),
            '⛔ ছাড় ১৫% — শর্ত মেলে, তবু কাগজটা পার হয়ে গেছে।',
        );
    }

    /**
     * ⛔ ঘরটা না পাঠালে শর্ত মেলে না — তাই না-পাঠানো ঘরটা খুঁজে বের করা যায়।
     *
     * ⓘ [[ApprovalFlow::unknownFields()]] ঐ ফাঁকটা ধরে, আর ব্যতিক্রমের
     * পর্দা সেটা দেখায়।
     */
    public function test_a_condition_on_a_field_nobody_sends_is_findable(): void
    {
        $flow = $this->flow();

        ApprovalCondition::query()->create([
            'company_id' => $this->company->id,
            'approval_flow_id' => $flow->id,
            'field' => 'a_field_nobody_sends',
            'operator' => '>',
            'value' => '1',
        ]);

        $this->assertSame(
            ['a_field_nobody_sends'],
            $flow->fresh()->unknownFields(['discount_percent', 'amount']),
            'যে ঘরটা কেউ পাঠায় না, সেটা ধরা পড়ল না।',
        );

        $this->assertSame([], $flow->fresh()->unknownFields(['a_field_nobody_sends']), implode("\n", [
            'চেনা ঘরকেও অচেনা বলছে।',
            '',
            '⛔ তাহলে ব্যতিক্রমের তালিকা সবসময় ভরা থাকত, আর কেউ ওটা পড়ত না।',
        ]));
    }

    /**
     * ⭐ শর্তটা কাজ করে যদিও ডাকা জায়গা একটাও ঘর পাঠায় না।
     *
     * ── ⛔ এটাই ধাপ ৩-এর সবচেয়ে বিপজ্জনক ফাঁক ছিল ───────────────────
     * ⓘ সাতটা সেবা [[DocumentApproval::assertClear()]] ডাকে, আর **একটাও
     * ঘর পাঠায় না**। ⚠️ আর [[ApprovalCondition::matches()]] ঘর না পেলে
     * *"মেলে না"* বলে।
     *
     * ⛔ ফল সবচেয়ে খারাপ দিকে: একটা শর্ত লেখা মানে ঐ কাজে অনুমোদন
     * **উঠে যাওয়া** — কাগজগুলো সই ছাড়াই পার হত, আর মালিক ভাবতেন নিয়ম
     * আরও কড়া হলো।
     *
     * ⭐ এখন ঘরগুলো কাগজ থেকেই আসে ([[ApprovalEngine::fieldsOf()]])।
     */
    public function test_a_condition_works_even_when_the_caller_sends_no_fields(): void
    {
        $flow = $this->flow();

        /* ⓘ শর্তটা কাগজের নিজের একটা ঘরে — `Branch::code` */
        $document = $this->document();

        ApprovalCondition::query()->create([
            'company_id' => $this->company->id,
            'approval_flow_id' => $flow->id,
            'field' => 'code',
            'operator' => '=',
            'value' => $document->code,
        ]);

        $this->actingAs($this->clerk);

        $stopping = app(DocumentApproval::class);

        $this->assertNotNull(
            $stopping->stopping($document, 'sales', 'discount', '50000', 'test'),
            implode("\n", [
                'শর্তটা কাগজের ঘরেই মেলে, তবু প্রবাহটা ধরল না।',
                '',
                '⛔ মানে ঘরগুলো কেউ পাঠায়নি — আর তখন একটা শর্ত লেখা মানেই',
                '   ঐ কাজে অনুমোদন উঠে যাওয়া।',
            ]),
        );

        /* ⛔ আর অন্য কাগজে, যেখানে ঘরটা মেলে না — প্রবাহ ধরে না */
        $other = $this->document();

        $this->assertNull(
            $stopping->stopping($other, 'sales', 'discount', '50000', 'test'),
            '⛔ শর্তটা মেলে না, তবু অনুমোদন চাওয়া হয়েছে।',
        );
    }

    /**
     * ⭐ আর জমা থাকে ঘরের **নাম**, মান নয়।
     *
     * ⛔ গোটা কাগজ `payload`-এ রাখলে প্রতিটা সারি মোটা হত, আর **বেতনের
     * অঙ্ক** একটা দ্বিতীয় টেবিলে জমা হত — যেটা কেউ চায়নি।
     *
     * ⓘ [[ApprovalExceptions::flowsThatCanNeverCatch()]]-এর দরকার কেবল
     * নামগুলো, তাই ওগুলোই রাখা হয়।
     */
    public function test_the_payload_keeps_the_field_names_and_not_their_values(): void
    {
        $this->flow();
        $this->actingAs($this->clerk);

        $document = $this->document();

        $approval = app(DocumentApproval::class)
            ->stopping($document, 'sales', 'discount', '50000', 'test');

        $this->assertNotNull($approval);

        $payload = (array) $approval->payload;

        $this->assertContains('code', $payload['fields_seen'] ?? [],
            '⛔ ঘরের নামগুলো জমা হয়নি — ব্যতিক্রমের খোঁজ তখন অন্ধ।');

        $this->assertArrayNotHasKey('code', $payload, implode("\n", [
            'ঘরের মানটাও জমা হয়ে গেছে।',
            '',
            '⛔ তাহলে বেতনের অঙ্কও এখানে জমা হবে — আর সেটা কেউ চায়নি।',
        ]));
    }

    /** ⛔ তুলনাটা অঙ্কের — `bccomp`, স্ট্রিং নয়। */
    public function test_a_numeric_condition_is_compared_as_a_number(): void
    {
        $flow = $this->flow();

        ApprovalCondition::query()->create([
            'company_id' => $this->company->id,
            'approval_flow_id' => $flow->id,
            'field' => 'qty',
            'operator' => '>',
            'value' => '9',
        ]);

        $flow = $flow->fresh();

        /*
         * ⛔ স্ট্রিং হিসেবে মিলালে "10" < "9" হত — অর্থাৎ শর্তটা ঠিক সেই
         * জায়গায় উল্টো উত্তর দিত যেখানে সংখ্যাটা দুই অঙ্কের।
         */
        $this->assertTrue($flow->catches(null, ['qty' => '10']), '"১০ > ৯" মেলেনি।');
        $this->assertFalse($flow->catches(null, ['qty' => '8']), '"৮ > ৯" মিলে গেছে।');
    }

    // ── ধাপ ৪ · কর্তৃত্বের সীমা ──────────────────────────────────────

    /** ⛔ সীমার বেশি অঙ্কে ঐ রোল সই দিতে পারে না। */
    public function test_a_role_cannot_sign_beyond_its_limit(): void
    {
        $this->flow();

        $role = Role::findOrCreate('small-boss');
        $this->boss->assignRole($role);

        ApprovalLimit::query()->create([
            'company_id' => $this->company->id,
            'role_id' => $role->id,
            'module' => 'sales',
            'max_amount' => '10000',
        ]);

        $this->assertFalse(app(ApprovalEngine::class)->canDecide($this->ask(), $this->boss), implode("\n", [
            'সীমা ১০,০০০, অঙ্ক ৫০,০০০ — তবু সই দেওয়া যাচ্ছে।',
            '',
            '⛔ এটাই আগের অবস্থা: প্রবাহে নাম থাকলেই যেকোনো অঙ্কে সই।',
        ]));
    }

    /** ⭐ সীমা না বসালে আচরণ অবিকল আগের মতো। */
    public function test_no_limit_means_no_change(): void
    {
        $this->flow();

        $this->assertTrue(app(ApprovalEngine::class)->canDecide($this->ask(), $this->boss),
            'কোনো সীমা বসানো নেই, তবু সই আটকে গেছে।');
    }

    /**
     * ⭐ দুইটা রোল থাকলে **উঁচু** সীমাটা জেতে।
     *
     * ⛔ নিচুটা জিতলে একটা ছোট রোল যোগ করলেই মানুষটার ক্ষমতা কমে যেত —
     * অর্থাৎ রোল যোগ করা শাস্তি হত, আর কেউ সেটা অনুমান করত না।
     */
    public function test_adding_a_second_role_never_lowers_the_limit(): void
    {
        $small = Role::findOrCreate('limit-small');
        $big = Role::findOrCreate('limit-big');

        $this->boss->assignRole($small);

        foreach ([[$small, '1000'], [$big, '99000']] as [$role, $max]) {
            ApprovalLimit::query()->create([
                'company_id' => $this->company->id,
                'role_id' => $role->id,
                'module' => 'sales',
                'max_amount' => $max,
            ]);
        }

        $authority = app(AuthorityService::class);

        $this->assertSame('1000.0000', $authority->limitFor($this->boss, 'sales', 'discount', null));

        $this->boss->assignRole($big);
        $this->boss->unsetRelation('roles');

        $this->assertSame('99000.0000', $authority->limitFor($this->boss, 'sales', 'discount', null),
            '⛔ দ্বিতীয় রোল যোগ করার পর সীমা কমে গেছে।');
    }

    /**
     * ⛔ শাখার সীমাটা **কাগজ থেকে** শাখাটা পড়ে — আর সেই পথটা মরা ছিল।
     *
     * ── ⚠️ কেন উপরের দাবিটা এটা ধরত না ──────────────────────────────
     * ⓘ [[test_a_branch_row_beats_the_general_one]] `limitFor()`-কে শাখার
     * আইডি **হাতে দিয়ে** ডাকে, তাই সে কেবল বাছাইয়ের নিয়মটা মাপে।
     *
     * ⛔ কিন্তু বাস্তবে শাখাটা কেউ হাতে দেয় না — [[AuthorityService::allows()]]
     * ওটা `$approval->approvable->branch_id` থেকে পড়ে। ⚠️ আর `approvable`
     * কোনো সম্পর্কই ছিল না, তাই মানটা **সবসময় `null`** হত: শাখার সীমা
     * পর্দায় লেখা থাকত, আর সাধারণ সারিটা চলত।
     *
     * ⓘ ভুলটা পড়ে ধরা যায়নি, মেপে ধরা পড়েছে — Eloquent অচেনা
     * অ্যাট্রিবিউটে ব্যতিক্রম ছোঁড়ে না, চুপচাপ `null` দেয়।
     */
    public function test_a_branch_limit_reads_the_branch_off_the_paper(): void
    {
        $role = Role::findOrCreate('warehouse-boss');
        $this->boss->assignRole($role);

        $branch = Branch::create(['code' => 'B2', 'name_en' => 'Second']);

        /* ⓘ ঐ শাখায় ছোট সীমা, আর সবখানে বড় — উল্টো করে বসানো ইচ্ছাকৃত */
        ApprovalLimit::query()->create([
            'company_id' => $this->company->id,
            'role_id' => $role->id,
            'max_amount' => '99000',
        ]);

        ApprovalLimit::query()->create([
            'company_id' => $this->company->id,
            'role_id' => $role->id,
            'branch_id' => $branch->id,
            'max_amount' => '100',
        ]);

        $this->flow();

        /*
         * ⓘ কাগজটা `Warehouse`, কারণ তার গায়ে `branch_id` আছে।
         * ⚠️ `Branch`-এ নেই, তাই ওটা দিয়ে এই পথটা মাপাই যায় না।
         */
        $warehouse = Warehouse::create([
            'company_id' => $this->company->id,
            'branch_id' => $branch->id,
            'code' => 'WH'.uniqid(),
            'name_en' => 'Shed',
        ]);

        $approval = app(ApprovalEngine::class)->request(
            $warehouse, 'sales', 'discount', '50000', null, 'test', $this->clerk->id,
        );

        $this->assertNotNull($approval, 'অনুরোধটাই বসেনি।');

        $this->assertFalse(app(ApprovalEngine::class)->canDecide($approval, $this->boss), implode("\n", [
            'ঐ শাখায় সীমা ১০০, অঙ্ক ৫০,০০০ — তবু সই দেওয়া যাচ্ছে।',
            '',
            '⛔ মানে শাখাটা কাগজ থেকে পড়া হয়নি, আর সাধারণ সারিটাই চলেছে।',
        ]));
    }

    /** ⭐ অঙ্ক জানা না থাকলে সীমা আটকায় না — নাহলে বছর বন্ধ করা যেত না। */
    public function test_a_paper_with_no_amount_is_not_blocked_by_a_limit(): void
    {
        $role = Role::findOrCreate('tiny-boss');
        $this->boss->assignRole($role);

        ApprovalLimit::query()->create([
            'company_id' => $this->company->id,
            'role_id' => $role->id,
            'max_amount' => '1',
        ]);

        $approval = new Approval(['module' => 'accounts', 'action' => 'year_end', 'amount' => null]);

        $this->assertTrue(app(AuthorityService::class)->allows($this->boss, $approval),
            'অঙ্কহীন কাগজও সীমায় আটকেছে।');
    }

    // ── ধাপ ৫ · কয়জনের মধ্যে কয়জন ───────────────────────────────────

    /** ⭐ দুইজনের সই লাগলে একজনে এগোয় না, দুইজনে এগোয়। */
    public function test_two_signatures_are_waited_for_when_two_are_asked(): void
    {
        $flow = ApprovalFlow::create(['module' => 'sales', 'action' => 'discount']);

        foreach ([$this->boss, $this->deputy] as $user) {
            ApprovalFlowStep::create([
                'approval_flow_id' => $flow->id,
                'level' => 1,
                'approver_type' => ApprovalFlowStep::BY_USER,
                'approver_id' => $user->id,
                'requires_all' => false,
                'min_approvals' => 2,
            ]);
        }

        $approval = $this->ask();

        app(ApprovalEngine::class)->approve($approval, $this->boss);

        $this->assertSame(Approval::PENDING, $approval->fresh()->status,
            '⛔ একজনের সইতেই কাজ হয়ে গেছে, অথচ দুইজন লাগার কথা।');

        app(ApprovalEngine::class)->approve($approval->fresh(), $this->deputy);

        $this->assertSame(Approval::APPROVED, $approval->fresh()->status,
            'দুইজন সই দিলেও এগোয়নি।');
    }

    /**
     * ⛔ একই স্তরের দুইটা সারিতে আলাদা সংখ্যা — কড়াটাই জেতে।
     *
     * ── ⚠️ এই ভুলটা সারির ক্রমের উপর দাঁড়িয়ে ছিল ───────────────────
     * ⓘ `min_approvals` ঘরটা **প্রতিটা সারিতে** আলাদা করে বসে (ফর্মে
     * প্রতি সারিতে একটা ঘর), অথচ প্রশ্নটা স্তরের। ⛔ আগে প্রথম সারিটা
     * পড়া হত — তাই তিনজনের ধাপে কেউ প্রথমে ১ আর দ্বিতীয়তে ২ লিখলে
     * উত্তরটা নির্ভর করত **কোন সারিটা আগে এল** তার উপর।
     *
     * ⚠️ আর ঐ ক্রমটা কোথাও বাঁধা নেই। ⓘ ফল নীরব: একদিন একজনের সইতেই
     * কাগজ এগিয়ে যেত, আর কেউ বুঝত না কেন।
     */
    public function test_the_strictest_row_at_a_level_wins(): void
    {
        $flow = ApprovalFlow::create(['module' => 'sales', 'action' => 'discount']);

        /*
         * ⓘ ইচ্ছাকৃতভাবে ভিন্ন: প্রথম সারিতে ১, দ্বিতীয়তে ২।
         * ⚠️ পুরনো কোড প্রথমটা পড়ত, তাই একজনের সইতেই এগোত।
         */
        foreach ([[$this->boss, 1], [$this->deputy, 2]] as [$user, $needs]) {
            ApprovalFlowStep::create([
                'approval_flow_id' => $flow->id,
                'level' => 1,
                'approver_type' => ApprovalFlowStep::BY_USER,
                'approver_id' => $user->id,
                'min_approvals' => $needs,
            ]);
        }

        $approval = $this->ask();

        app(ApprovalEngine::class)->approve($approval, $this->boss);

        $this->assertSame(Approval::PENDING, $approval->fresh()->status, implode("\n", [
            'একজনের সইতেই কাজ হয়ে গেছে, অথচ ঐ স্তরের একটা সারি দুইজন চায়।',
            '',
            '⛔ তাহলে উত্তরটা সারির ক্রমের উপর দাঁড়িয়ে — আর সেটা কোথাও বাঁধা নেই।',
        ]));

        app(ApprovalEngine::class)->approve($approval->fresh(), $this->deputy);

        $this->assertSame(Approval::APPROVED, $approval->fresh()->status);
    }

    /**
     * ⛔ আর একটা সারিতেও `requires_all` থাকলে **সবার** সই লাগে।
     *
     * ⓘ পুরনো আচরণ, আর সেটাই কড়া দিক — তিনজনের ধাপে কেউ একটা সারিতে
     * "সবার সম্মতি" বসালে সেটা `min_approvals = 2` কে ছাপিয়ে যায়।
     */
    public function test_one_requires_all_row_binds_the_whole_level(): void
    {
        $flow = ApprovalFlow::create(['module' => 'sales', 'action' => 'discount']);

        foreach ([[$this->boss, true], [$this->deputy, false]] as [$user, $all]) {
            ApprovalFlowStep::create([
                'approval_flow_id' => $flow->id,
                'level' => 1,
                'approver_type' => ApprovalFlowStep::BY_USER,
                'approver_id' => $user->id,
                'requires_all' => $all,
                'min_approvals' => 1,
            ]);
        }

        $approval = $this->ask();

        app(ApprovalEngine::class)->approve($approval, $this->boss);

        $this->assertSame(Approval::PENDING, $approval->fresh()->status,
            '⛔ একটা সারিতে "সবার সম্মতি" বসানো, তবু একজনের সইতেই এগিয়ে গেছে।');

        app(ApprovalEngine::class)->approve($approval->fresh(), $this->deputy);

        $this->assertSame(Approval::APPROVED, $approval->fresh()->status);
    }

    /**
     * ⛔ দ্বিতীয় ধাপে পৌঁছানোর সাথে সাথেই "সময় ফুরিয়ে আসছে" বলত না।
     *
     * ── ⚠️ ঘড়িটা ধাপের, অনুরোধের দিনের নয় ───────────────────────────
     * ⓘ ধাপ এগোলে `due_at` নতুন করে বসে, কিন্তু `requested_at` দিন-কয়েকের
     * পুরনো। ⛔ সতর্কতার সময়টা অনুরোধের দিন থেকে গুনলে দ্বিতীয় ধাপের
     * মানুষ কাগজটা **হাতে পাওয়ার মুহূর্তেই** বার্তা পেতেন।
     *
     * ⚠️ ক্ষতিটা একটা অর্থহীন বার্তার চেয়ে বড়: বার্তাগুলো এত ঘন হত যে
     * কেউ আর পড়ত না — আর তখন সত্যিকারের দেরির বার্তাটাও ঐ ভিড়ে হারাত।
     */
    public function test_the_warning_clock_starts_when_the_step_starts(): void
    {
        $flow = ApprovalFlow::create(['module' => 'sales', 'action' => 'discount']);

        /* ⓘ দুই ধাপ, দুইটাতেই দশ ঘণ্টার সময়সীমা */
        foreach ([[1, $this->boss], [2, $this->deputy]] as [$level, $user]) {
            ApprovalFlowStep::create([
                'approval_flow_id' => $flow->id,
                'level' => $level,
                'approver_type' => ApprovalFlowStep::BY_USER,
                'approver_id' => $user->id,
                'sla_hours' => 10,
            ]);
        }

        /*
         * ⓘ অনুরোধটা তিন দিনের পুরনো, আর প্রথম ধাপে সই হয়ে গেছে।
         * ⚠️ পুরনো হিসাবে সতর্কতার সময় ছিল অনুরোধ + ৫ ঘণ্টা — অর্থাৎ
         * দুই দিনের বেশি আগে, তাই দ্বিতীয় ধাপ শুরুতেই "কাছাকাছি"।
         */
        $approval = $this->ask();
        $approval->update(['requested_at' => now()->subDays(3)]);

        app(ApprovalEngine::class)->approve($approval->fresh(), $this->boss);

        $approval = $approval->fresh();

        $this->assertSame(2, (int) $approval->current_level, 'কাগজটা দ্বিতীয় ধাপে যায়নি।');

        $this->assertSame(ApprovalSla::FINE,
            app(ApprovalSla::class)->stateOf($approval), implode("\n", [
                'দ্বিতীয় ধাপ শুরু হওয়ার মুহূর্তেই "সময় ফুরিয়ে আসছে" বলছে।',
                '',
                '⛔ মানে ঘড়িটা অনুরোধের দিন থেকে গুনছে, ধাপের শুরু থেকে নয়।',
            ]));

        /* ⭐ আর অর্ধেক সময় পার হলে সত্যিই "কাছাকাছি" */
        $approval->update(['due_at' => now()->addHours(4)]);

        $this->assertSame(ApprovalSla::NEAR,
            app(ApprovalSla::class)->stateOf($approval->fresh()),
            '⛔ দশ ঘণ্টার ছয় ঘণ্টা পার, তবু সতর্ক করছে না — তাহলে সতর্কতাটাই নেই।');
    }

    /**
     * ⭐ আর কিছু না বসালে একজনেই এগোয় — পুরনো আচরণ অটুট।
     *
     * ⛔ এই সারিটা না থাকলে উপরেরটা বিপজ্জনক: `min_approvals`-এর ডিফল্ট
     * ভুল হলে **প্রতিটা পুরনো প্রবাহ** চিরকাল ঝুলে থাকত, আর কোথাও কিছু
     * লাল হত না।
     */
    public function test_one_signature_is_still_enough_by_default(): void
    {
        $this->flow();

        $approval = $this->ask();

        app(ApprovalEngine::class)->approve($approval, $this->boss);

        $this->assertSame(Approval::APPROVED, $approval->fresh()->status,
            '⛔ ডিফল্টেই একাধিক সই চাওয়া হচ্ছে — পুরনো সব প্রবাহ থমকে যেত।');
    }

    // ── ধাপ ৬ · সইয়ের পর কাগজ বদলালে ────────────────────────────────

    /**
     * ⛔ অঙ্ক **না বদলে** অন্য ঘর বদলালেও ছাপ বদলায়।
     *
     * ── ⭐ মালিকের সিদ্ধান্ত: "যেকোনো ঘর বদলালেই" ───────────────────
     * ⚠️ এটাই সবচেয়ে বিপজ্জনক ফাঁক ছিল: অঙ্ক ঠিক রেখে পণ্য বা ক্রেতা
     * বদলে দিলে পুরনো সইটাই চলত, আর কাগজটা অনুমোদিত দেখাত।
     */
    public function test_changing_any_field_changes_the_fingerprint(): void
    {
        $document = $this->document();
        $print = app(DocumentFingerprint::class);

        $before = $print->of($document);

        $document->update(['name_en' => 'Doc, but different']);

        $this->assertNotSame($before, $print->of($document->fresh()), implode("\n", [
            'কাগজের একটা ঘর বদলেছে, অথচ ছাপটা একই।',
            '',
            '⛔ তাহলে অঙ্ক ঠিক রেখে যা খুশি বদলে দেওয়া যেত।',
        ]));
    }

    /**
     * ⭐ আর যে ঘরগুলো ইচ্ছাকৃতভাবে বাদ, সেগুলো বদলালে ছাপ বদলায় না।
     *
     * ⛔ এই দাবিটা ছাড়া উপরেরটা বিপজ্জনক: `updated_at` ধরলে **প্রতিটা
     * সংরক্ষণেই** নতুন অনুমোদন লাগত, আর কেউ ব্যবস্থাটা ব্যবহার করত না।
     */
    public function test_the_bookkeeping_fields_do_not_count(): void
    {
        $document = $this->document();
        $print = app(DocumentFingerprint::class);

        $before = $print->of($document);

        $document->forceFill(['updated_at' => now()->addHour()])->saveQuietly();

        $this->assertSame($before, $print->of($document->fresh()),
            '`updated_at` বদলানোয় ছাপ বদলে গেছে — প্রতিটা সংরক্ষণে নতুন সই লাগত।');
    }

    /** ⭐ আর ছাপটা অনুরোধের সারিতেই বসে, নাহলে পরে মেলানোর কিছু থাকত না। */
    public function test_the_fingerprint_is_kept_on_the_request(): void
    {
        $this->flow();
        $this->actingAs($this->clerk);

        $document = $this->document();
        $print = app(DocumentFingerprint::class);

        $approval = app(DocumentApproval::class)->stopping($document, 'sales', 'discount', '50000', 'test');

        $this->assertNotNull($approval, 'অনুরোধটাই বসেনি।');

        $this->assertSame($print->of($document), (string) $approval->state_hash,
            '⛔ ছাপটা সংরক্ষিত হয়নি — কাগজ বদলালে কেউ জানত না।');

        $this->assertTrue($approval->stillCovers('50000', $print->of($document)),
            'কাগজ বদলায়নি, তবু সইটা আর ঢাকছে না।');

        $document->update(['name_en' => 'Changed after asking']);

        $this->assertFalse($approval->stillCovers('50000', $print->of($document->fresh())),
            '⛔ কাগজ বদলানোর পরেও পুরনো সইটা কাগজটাকে ঢেকে রাখছে।');
    }

    // ── ধাপ ৭ · একসাথে অনেকগুলো ──────────────────────────────────────

    /** ⛔ টাকা নড়ার কাজ bulk-এ ঢোকে না — মালিকের সিদ্ধান্ত ৫। */
    public function test_money_work_never_enters_bulk(): void
    {
        $bulk = app(BulkApproval::class);

        $this->assertTrue($bulk->movesMoney(new Approval(['module' => 'accounts', 'action' => 'payment'])),
            '⛔ পরিশোধ bulk-এ ঢুকে পড়ছে।');

        $this->assertFalse($bulk->movesMoney(new Approval(['module' => 'purchase', 'action' => 'order'])),
            'ক্রয়াদেশও bulk থেকে বাদ পড়ছে — তাহলে ব্যবস্থাটার কোনো মানে থাকত না।');
    }

    /** ⛔ অচেনা মডিউল হলে কড়া দিকটা — সন্দেহে bulk বন্ধ। */
    public function test_an_unknown_module_is_kept_out_of_bulk(): void
    {
        $this->assertTrue(
            app(BulkApproval::class)->movesMoney(new Approval(['module' => 'nope', 'action' => 'x'])),
            'অচেনা মডিউলের কাগজ bulk-এ ঢুকছে।',
        );
    }

    /**
     * ⭐ একটা সারি বাদ পড়লে পুরো দলটা বাতিল হয় না, আর কারণটা গোনা হয়।
     *
     * ⛔ পুরো দল বাতিল হলে একটা টাকার কাগজ ভুলে বাছলেই বাকি উনিশটা কাজ
     * আটকে যেত, আর মানুষ কারণটা কখনো জানতেন না।
     */
    public function test_a_skipped_row_does_not_take_the_batch_down(): void
    {
        $this->flow();

        $good = $this->ask();

        $money = Approval::create([
            'company_id' => $this->company->id,
            'approvable_type' => Branch::class,
            'approvable_id' => $this->document()->id,
            'module' => 'accounts',
            'action' => 'payment',
            'amount' => '500',
            'status' => Approval::PENDING,
            'current_level' => 1,
            'requested_by' => $this->clerk->id,
            'requested_at' => now(),
        ]);

        $result = app(BulkApproval::class)->approve([(int) $good->id, (int) $money->id], $this->boss);

        $this->assertSame(1, $result['done'], 'যে সারিটা পারত সেটাও হয়নি।');

        $this->assertSame(1, $result['skipped']['money'] ?? 0,
            '⛔ টাকার সারিটা কোন কারণে বাদ পড়ল, সেটা লেখা নেই।');

        $this->assertSame(Approval::APPROVED, $good->fresh()->status);
        $this->assertSame(Approval::PENDING, $money->fresh()->status, '⛔ টাকার কাগজটা bulk-এ সই হয়ে গেছে।');
    }

    // ── ধাপ ৮ · সিদ্ধান্ত পাথরে ──────────────────────────────────────

    /** ⛔ একটা সিদ্ধান্ত বদলানো যায় না। */
    public function test_a_decision_cannot_be_edited(): void
    {
        $decision = $this->aDecision();

        $this->expectException(\RuntimeException::class);

        $decision->update(['remarks' => 'বদলে দিলাম']);
    }

    /** ⛔ আর মোছাও যায় না। */
    public function test_a_decision_cannot_be_deleted(): void
    {
        $decision = $this->aDecision();

        $this->expectException(\RuntimeException::class);

        $decision->delete();
    }

    // ── ধাপ ১০ · বাতিলের কারণ ────────────────────────────────────────

    /** ⭐ বাতিলের কারণটা সারিতে বসে। */
    public function test_a_refusal_carries_a_reason_code(): void
    {
        $this->flow();

        app(ApprovalEngine::class)->reject($this->ask(), $this->boss, 'দাম ঠিক নেই', 'price');

        $this->assertSame('price', ApprovalDecision::query()->latest('id')->value('reason_code'));
    }

    /** ⛔ আর অচেনা কোড "অন্য" হয়ে যায় — নাহলে রিপোর্টে দশ রকম বানান জমত। */
    public function test_an_unknown_reason_code_becomes_other(): void
    {
        $this->flow();

        app(ApprovalEngine::class)->reject($this->ask(), $this->boss, 'কেন যেন', 'a_code_nobody_knows');

        $this->assertSame('other', ApprovalDecision::query()->latest('id')->value('reason_code'),
            '⛔ অচেনা কারণ কোডটাই বসে গেছে।');
    }

    /** ⭐ কারণ না দিলে কিছুই বসে না — পুরনো ডাকা জায়গাগুলো অটুট। */
    public function test_a_refusal_without_a_code_stays_empty(): void
    {
        $this->flow();

        app(ApprovalEngine::class)->reject($this->ask(), $this->boss, 'শুধু না');

        $this->assertNull(ApprovalDecision::query()->latest('id')->value('reason_code'),
            '⛔ কারণ না দিলেও কিছু একটা বসে যাচ্ছে।');
    }

    // ── সহায়ক ───────────────────────────────────────────────────────

    /**
     * ⛔ দুইটা শর্ত AND — একটা মিললেই যথেষ্ট নয়।
     *
     * ⚠️ এই সারিটা ছাড়া AND-টা একটা অনুমান: OR হলে প্রবাহটা মালিকের লেখা
     * শর্তের চেয়ে অনেক বেশি কাগজ ধরত, আর কোথাও কিছু লাল হত না — বাড়তি
     * অনুমোদনগুলো দেখতে ব্যবস্থাটার সতর্কতার মতোই লাগত।
     */
    public function test_two_conditions_are_both_required(): void
    {
        $flow = $this->flow();

        foreach ([['discount_percent', '>', '10'], ['qty', '>', '5']] as [$field, $op, $value]) {
            ApprovalCondition::query()->create([
                'company_id' => $this->company->id,
                'approval_flow_id' => $flow->id,
                'field' => $field,
                'operator' => $op,
                'value' => $value,
            ]);
        }

        $flow = $flow->fresh();

        $this->assertTrue($flow->catches(null, ['discount_percent' => '15', 'qty' => '9']),
            'দুইটা শর্তই মেলে, তবু প্রবাহটা ধরছে না।');

        $this->assertFalse($flow->catches(null, ['discount_percent' => '15', 'qty' => '2']), implode("\n", [
            'একটা শর্ত মেলে, তবু প্রবাহটা ধরেছে।',
            '',
            '⛔ তাহলে এটা AND নয়, OR।',
        ]));
    }

    /**
     * ⛔ ঘরটা না এলে শর্ত মেলে না — আর এটাই কড়া দিক।
     *
     * ⓘ মডিউল যে ঘরটা কখনো পাঠায় না, তার উপর শর্ত বসালে প্রবাহটা
     * **কিছুই ধরে না** — আর ঐ নীরব ভুলটাই [[ApprovalExceptions]] খোঁজে।
     *
     * ⚠️ উল্টো ডিফল্ট আরও খারাপ হত: একটা টাইপো করলে **প্রতিটা** কাগজে
     * অনুমোদন লাগত, আর মানুষ ব্যবস্থাটা এড়ানোর পথ খুঁজতেন।
     */
    public function test_a_missing_field_does_not_match(): void
    {
        $flow = $this->flow();

        ApprovalCondition::query()->create([
            'company_id' => $this->company->id,
            'approval_flow_id' => $flow->id,
            'field' => 'discount_percent',
            'operator' => '>',
            'value' => '10',
        ]);

        $this->assertFalse($flow->fresh()->catches(null, []),
            '⛔ ঘরটা পাঠানোই হয়নি, তবু শর্তটা মিলে গেছে।');
    }

    /**
     * ⭐ একই রোল অন্য শাখায় আলাদা সীমা পায়।
     *
     * ⛔ সাধারণ সারিটাকে নির্দিষ্ট সারির কাছে হারতেই হবে। ⚠️ জিতে গেলে
     * শাখার নিয়মটা **লেখা থাকত আর কোনোদিন চলত না** — যা না-লেখার চেয়ে
     * খারাপ, কারণ সবাই ভাবতেন নিয়মটা বলবৎ আছে।
     */
    public function test_a_branch_row_beats_the_general_one(): void
    {
        $role = Role::findOrCreate('branch-boss');
        $this->boss->assignRole($role);

        $branch = $this->document();

        ApprovalLimit::query()->create([
            'company_id' => $this->company->id,
            'role_id' => $role->id,
            'module' => 'sales',
            'max_amount' => '1000',
        ]);

        ApprovalLimit::query()->create([
            'company_id' => $this->company->id,
            'role_id' => $role->id,
            'module' => 'sales',
            'branch_id' => $branch->id,
            'max_amount' => '90000',
        ]);

        $authority = app(AuthorityService::class);

        $this->assertSame('90000.0000',
            $authority->limitFor($this->boss, 'sales', 'discount', (int) $branch->id),
            '⛔ ঐ শাখায় সাধারণ সারিটা জিতেছে, অর্থাৎ শাখার নিয়মটা কোনোদিন চলত না।');

        $this->assertSame('1000.0000',
            $authority->limitFor($this->boss, 'sales', 'discount', null),
            '⛔ শাখার বাইরেও শাখার ছাদটা চুইয়ে ঢুকছে।');
    }

    /**
     * ⛔ ভার যে কাজ ঢাকে না, সেখানে ভারপ্রাপ্ত জন সই দিতে পারেন না।
     *
     * ⚠️ এই সারিটা ছাড়া মডিউলের তালিকাটা সাজসজ্জা: যাঁকে *"আমার ক্রয়ের
     * অনুমোদন"* দেওয়া হলো তিনি বেতনের রানেও সই দিতে পারতেন — আর পর্দা
     * ঠিক যা তিনি বেছেছিলেন তা-ই দেখাত।
     */
    public function test_a_delegate_is_held_to_the_work_that_was_handed_over(): void
    {
        $this->flow();

        app(DelegationService::class)->grant(
            from: $this->boss,
            to: $this->deputy,
            startsOn: now()->toDateString(),
            endsOn: now()->addDays(3)->toDateString(),
            modules: ['purchase'],
        );

        $this->assertFalse(app(ApprovalEngine::class)->canDecide($this->ask(), $this->deputy), implode("\n", [
            'ভারটা কেবল ক্রয়ে, তবু বিক্রয়ের কাগজে সই দেওয়া যাচ্ছে।',
            '',
            '⛔ তাহলে মডিউলের তালিকাটা সাজসজ্জা।',
        ]));
    }

    /** ⭐ আর খালি তালিকা মানে সব — এটাই সবচেয়ে সাধারণ ব্যবহার। */
    public function test_an_empty_module_list_hands_over_everything(): void
    {
        $this->flow();

        app(DelegationService::class)->grant(
            from: $this->boss,
            to: $this->deputy,
            startsOn: now()->toDateString(),
            endsOn: now()->addDays(3)->toDateString(),
        );

        $this->assertTrue(app(ApprovalEngine::class)->canDecide($this->ask(), $this->deputy),
            '⛔ খালি তালিকাকে "কিছুই না" পড়া হয়েছে, তাহলে ভার দেওয়া জিনিসটাই কাজ করত না।');
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

    private function document(): Branch
    {
        return Branch::create(['code' => 'D'.uniqid(), 'name_en' => 'Doc']);
    }

    private function ask(): Approval
    {
        $approval = app(ApprovalEngine::class)->request(
            $this->document(), 'sales', 'discount', '50000', null, 'test', $this->clerk->id,
        );

        $this->assertNotNull($approval, 'অনুরোধটাই বসেনি।');

        return $approval;
    }

    private function aDecision(): ApprovalDecision
    {
        $this->flow();

        $approval = $this->ask();

        app(ApprovalEngine::class)->approve($approval, $this->boss);

        return ApprovalDecision::query()->where('approval_id', $approval->id)->firstOrFail();
    }
}
