<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Approval\DocumentApproval;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalCondition;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\ApprovalLimit;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * নিয়মগুলো পর্দায় পৌঁছেছে কি না — ২৪ সেপ্টেম্বর ২০২৬।
 *
 * ── ⛔ কেন এই ফাইলটা আলাদা করে দরকার ────────────────────────────────
 * ⓘ [[TheApprovalEngineGrewEightNewRulesTest]] প্রমাণ করে নিয়মগুলো
 * **কাজ করে**। ⚠️ কিন্তু এই রিপোতে বারবার যেটা ভাঙে সেটা যুক্তি নয় —
 * **জোড়**: ঘরটা ডাটাবেজে আছে, ফর্মে নেই; সেবাটা লেখা আছে, কেউ ডাকে না;
 * কলামটা বসে, পর্দা কখনো পড়ে না।
 *
 * ⛔ ঐ ভুলগুলোর একটাও কিছু ভাঙে না। ⓘ ইঞ্জিনের টেস্ট সবুজ থাকে, কারণ
 * সে সেবাটাকে সরাসরি ডাকে — আর ব্যবহারকারী পর্দায় ঘরটাই পান না।
 *
 * ⚠️ তাই এখানে প্রতিটা দাবি **HTTP দিয়ে** যায়: ফর্ম জমা দিয়ে দেখা হয়
 * মানটা সারিতে পৌঁছেছে কি না, আর পাতা খুলে দেখা হয় লেখাটা আছে কি না।
 */
class TheApprovalScreensCarryTheNewRulesTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $boss;

    private User $clerk;

    private Role $role;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['code' => 'SC', 'name_en' => 'Screens Co']);
        CompanyContext::set($this->company->id);

        Branch::create(['code' => 'MAIN', 'name_en' => 'Main']);

        /*
         * ⓘ দুইটাই লাগে: পর্দা খোলার জন্য সরাসরি অনুমতি, আর সীমার জন্য
         * একটা রোল — [[ApprovalLimit]] রোল ধরেই খাটে।
         *
         * ⚠️ রোলে কোনো অনুমতি বসানো হয় না, আর সেটা ইচ্ছাকৃত: বসালে সীমার
         * দাবিটা অনুমতির সাথে জড়াত, আর রোল সরালে পর্দাই খুলত না — তখন
         * লালটা কেন লাল সেটা বলা কঠিন হত।
         */
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

        $this->role = Role::findOrCreate('approval-boss');

        $this->boss = User::factory()->create([
            'name' => 'Boss',
            'current_company_id' => $this->company->id,
        ]);
        $this->boss->companies()->attach($this->company->id);
        $this->boss->givePermissionTo(['approval.view', 'approval.decide', 'approval.flow.manage']);
        $this->boss->assignRole($this->role);

        $this->clerk = User::factory()->create([
            'name' => 'Clerk',
            'current_company_id' => $this->company->id,
        ]);
        $this->clerk->companies()->attach($this->company->id);

        /*
         * ⓘ অনুরোধকারীরও একটা চাবি লাগে — `approval.view`।
         *
         * ⚠️ *"আমার অনুরোধ"* পর্দাটা ওই চাবির পিছনে, আর সেটা ঠিক:
         * যিনি ছাড় চেয়েছেন তিনি জানতে চান কী হলো।
         */
        $this->clerk->givePermissionTo('approval.view');
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    // ── ছকের ফর্ম: ঘড়ি, গন্তব্য, কয়জন, শর্ত ─────────────────────────

    /**
     * ⭐ ফর্ম থেকে পাঠানো ঘড়ি ও গন্তব্য সত্যিই সারিতে বসে।
     *
     * ⛔ এটাই সেই জোড়টা যা না থাকলে ধাপ ১ পুরো মরা তার হত: `sla_hours`
     * কলামটা থাকত, [[ApprovalSla]] ওটা পড়ত, `abos:approvals-due` প্রতি
     * ঘণ্টায় চলত — আর কোনোদিন কোনো সারিতে সংখ্যাটা বসত না।
     */
    public function test_the_form_can_set_a_clock_and_where_it_goes(): void
    {
        $this->actingAs($this->boss)
            ->post(route('approval.flow.store'), $this->flowInput([
                'sla_hours' => 24,
                'warn_hours' => 12,
                'escalate_hours' => 48,
                'escalate_to' => 'user|'.$this->boss->id,
                'min_approvals' => 2,
            ]))
            ->assertRedirect(route('approval.flow.index'));

        $step = ApprovalFlowStep::query()->firstOrFail();

        $this->assertSame(24, $step->sla_hours, '⛔ সময়সীমাটা সারিতে পৌঁছায়নি।');
        $this->assertSame(12, $step->warn_hours);
        $this->assertSame(48, $step->escalate_hours);
        $this->assertSame('user', $step->escalate_to_type, '⛔ গন্তব্যের ধরনটা ভাঙা হয়নি।');
        $this->assertSame((int) $this->boss->id, (int) $step->escalate_to_id);
        $this->assertSame(2, $step->min_approvals, '⛔ কয়জনের সই লাগবে সেটা বসেনি।');
    }

    /**
     * ⭐ আর ঘরগুলো খালি রাখলে `null` বসে, শূন্য নয়।
     *
     * ⛔ শূন্য বসলে ওটা *"সাথে সাথে দেরি"* মানে দাঁড়াত, আর একটা পুরনো
     * প্রবাহ কেবল খুলে-সংরক্ষণ করলেই তার প্রতিটা কাগজ জন্মেই লাল হত —
     * কোনো ত্রুটি ছাড়া।
     */
    public function test_an_empty_clock_field_means_no_clock(): void
    {
        $this->actingAs($this->boss)
            ->post(route('approval.flow.store'), $this->flowInput([
                'sla_hours' => '',
                'warn_hours' => '',
                'escalate_hours' => '',
                'escalate_to' => '',
                'min_approvals' => '',
            ]))
            ->assertRedirect();

        $step = ApprovalFlowStep::query()->firstOrFail();

        $this->assertNull($step->sla_hours, '⛔ খালি ঘরে শূন্য বসে গেছে।');
        $this->assertNull($step->warn_hours);
        $this->assertNull($step->escalate_hours);
        $this->assertNull($step->escalate_to_type);

        // ⓘ এই একটার ডিফল্ট ১ — কলামটা `NOT NULL`
        $this->assertSame(1, $step->min_approvals, '⛔ কয়জনের সই ডিফল্টে ১ থাকেনি।');
    }

    /**
     * ⛔ একটা নথি-নির্দিষ্ট ছক সম্পাদনা করলেই সে সব নথিতে ছড়িয়ে যেত।
     *
     * ── ⚠️ ভুলটা সম্পূর্ণ নীরব ─────────────────────────────────────
     * ⓘ ফর্মে `document_type`-এর কোনো দৃশ্যমান ঘর নেই, আর সেবা লিখত
     * `$data['document_type'] ?? ''` — অর্থাৎ *"সব ধরনে"*।
     *
     * ⛔ ফল: `document_type = 'SalesInvoice'` বসানো একটা ছক কেবল খুলে
     * সংরক্ষণ করলেই **সব ধরনের কাগজ ধরতে শুরু করত**। ⚠️ কোনো ত্রুটি নেই,
     * পর্দায় কিছু বদলায় না — কেবল ঐ ছকটা হঠাৎ আরও অনেক কাগজ আটকায়,
     * আর কেউ জানত না কেন হঠাৎ সব বিলে সই লাগছে।
     */
    public function test_editing_a_document_specific_flow_does_not_widen_it(): void
    {
        $flow = ApprovalFlow::create([
            'module' => 'sales',
            'action' => 'discount',
            'document_type' => 'SalesInvoice',
        ]);

        ApprovalFlowStep::create([
            'approval_flow_id' => $flow->id,
            'level' => 1,
            'approver_type' => ApprovalFlowStep::BY_USER,
            'approver_id' => $this->boss->id,
        ]);

        /*
         * ⓘ ফর্মটা যা পাঠায়, হুবহু তাই — `document_type` ছাড়া।
         * ⚠️ লুকানো ঘরটা এখন মানটা ফিরিয়ে দেয়, আর সেবাও অনুপস্থিত
         * চাবিকে "মুছে দাও" পড়ে না। ⓘ দুইটার একটা থাকলেই দাবিটা সবুজ,
         * আর সেটাই উদ্দেশ্য: পর্দা কখনো শেষ কথা নয়।
         */
        $this->actingAs($this->boss)
            ->put(route('approval.flow.update', $flow->id), $this->flowInput())
            ->assertRedirect();

        $this->assertSame('SalesInvoice', $flow->fresh()->document_type, implode("\n", [
            'সম্পাদনার পর নথি-ধরনটা মুছে গেছে।',
            '',
            '⛔ অর্থাৎ ছকটা এখন সব ধরনের কাগজ ধরে — আর কেউ সেটা চায়নি।',
        ]));
    }

    /** ⭐ শর্তের সারিগুলোও ফর্ম থেকেই বসে ও মোছে। */
    public function test_the_form_can_set_and_clear_conditions(): void
    {
        $this->actingAs($this->boss)
            ->post(route('approval.flow.store'), $this->flowInput() + [
                'conditions' => [
                    ['field' => 'discount_percent', 'operator' => '>', 'value' => '10'],

                    // ⓘ খালি সারিটা ছাঁকা হতেই হবে — ফর্মে সবসময় তিনটা থাকে
                    ['field' => '', 'operator' => '>', 'value' => ''],
                ],
            ])
            ->assertRedirect();

        $this->assertSame(1, ApprovalCondition::query()->count(), '⛔ খালি সারিটাও বসে গেছে।');
        $this->assertSame('discount_percent', ApprovalCondition::query()->value('field'));

        $flow = ApprovalFlow::query()->firstOrFail();

        /*
         * ⛔ আর তুলে নেওয়াও যায়।
         *
         * ⚠️ শর্ত বসানো গেলেও তোলা না গেলে সেটা ফাঁদ: একটা ভুল ঘরের নাম
         * বসিয়ে দিলে ঐ প্রবাহ **চিরকাল** আর কিছু ধরত না, আর সারানোর
         * কোনো পথ থাকত না।
         */
        $this->actingAs($this->boss)
            ->put(route('approval.flow.update', $flow->id), $this->flowInput())
            ->assertRedirect();

        $this->assertSame(0, ApprovalCondition::query()->count(), '⛔ শর্তটা তুলে নেওয়া যায় না।');
    }

    // ── ইনবক্স: ঘড়ির চিহ্ন, দেরির ছাঁকনি, একসাথে সই ──────────────────

    /** ⭐ দেরি হওয়া কাগজে ইনবক্স "সময় পার" লেখে, আর ছাঁকনিটা কাজ করে। */
    public function test_the_inbox_shows_the_clock_and_filters_the_late_ones(): void
    {
        $late = $this->waiting(due: now()->subDay());
        $fine = $this->waiting(due: now()->addDay());

        $page = $this->actingAs($this->boss)->get(route('approval.inbox.index'));

        $page->assertOk()
            ->assertSee(__('approval::message.sla_late'))
            ->assertSee(__('approval::field.late'));

        /*
         * ⛔ ছাঁকনিটা সত্যিই ছাঁকে — শিরোনামের সংখ্যাটাসহ।
         *
         * ⚠️ চিপটা এঁকে ছাঁকনিটা না বসালে সংখ্যাটা বদলাত না, আর মানুষ
         * ভাবতেন দেরি হওয়া কাগজ একটাও নেই।
         */
        $filtered = $this->actingAs($this->boss)->get(route('approval.inbox.index', ['late' => 1]));

        $filtered->assertOk()
            ->assertSee('name="ids[]" value="'.$late->id.'"', escape: false)
            ->assertDontSee('name="ids[]" value="'.$fine->id.'"', escape: false);
    }

    /**
     * ⛔ টাকার কাগজে বাছাইয়ের ঘরটা আঁকাই হয় না।
     *
     * ⓘ মালিকের সিদ্ধান্ত ৫। ⚠️ আর সার্ভারও আলাদা করে দেখে, কারণ একটা
     * লুকানো চেকবক্স হাতে বানিয়ে পাঠানো যায়।
     */
    public function test_money_papers_get_no_checkbox_and_no_bulk_signature(): void
    {
        $paper = $this->waiting();
        $money = $this->waiting(module: 'accounts', action: 'payment');

        $this->actingAs($this->boss)->get(route('approval.inbox.index'))
            ->assertOk()
            ->assertSee('name="ids[]" value="'.$paper->id.'"', escape: false)
            ->assertDontSee('name="ids[]" value="'.$money->id.'"', escape: false);

        $this->actingAs($this->boss)
            ->post(route('approval.inbox.bulk'), ['ids' => [$paper->id, $money->id]])
            ->assertRedirect(route('approval.inbox.index'));

        $this->assertSame(Approval::APPROVED, $paper->fresh()->status);

        $this->assertSame(Approval::PENDING, $money->fresh()->status, implode("\n", [
            'টাকার কাগজটা একসাথে সইয়ে পাশ হয়ে গেছে।',
            '',
            '⛔ পর্দা থেকে ঘরটা তুলে নেওয়া নিরাপত্তা নয় — কেউ সরাসরি POST করলেও আটকাতে হবে।',
        ]));
    }

    // ── সিদ্ধান্তের পর্দা: যাত্রাপথ, কারণ, তীর ───────────────────────

    /**
     * ⭐ যাত্রাপথে সেই ধাপটাও দেখা যায় যেখানে **এখনো কেউ কিছু করেননি**।
     *
     * ⛔ সিদ্ধান্তের ছকটা ঐ ধাপটা দেখায় না, আর যিনি সই দিচ্ছেন তাঁর
     * প্রশ্ন ঠিক সেটাই: *"আমার পরে আর কে আছেন?"*
     */
    public function test_the_journey_shows_the_step_nobody_has_reached(): void
    {
        $flow = $this->flow();

        ApprovalFlowStep::create([
            'approval_flow_id' => $flow->id,
            'level' => 2,
            'step_name' => 'Director',
            'approver_type' => ApprovalFlowStep::BY_USER,
            'approver_id' => $this->clerk->id,
        ]);

        $approval = $this->waiting(flow: false);

        $this->actingAs($this->boss)->get(route('approval.inbox.show', $approval->id))
            ->assertOk()
            ->assertSee(__('approval::field.journey'))
            ->assertSee('Director')
            ->assertSee(__('approval::message.step_waiting'));
    }

    /**
     * ⭐ নামহীন ধাপেও যাত্রাপথ আঁকা হয়।
     *
     * ⛔ এই সারিটা ছাড়া উপরেরটা বিপজ্জনক: প্রথমে যাত্রাপথটা
     * `stepNamesFor()` দিয়ে বানানো হয়েছিল, আর সেটা **নামহীন ধাপ বাদ
     * দেয়** — অর্থাৎ নাম না বসানো প্রবাহে পর্দাটা নীরবে খালি থাকত, আর
     * সেটা দেখতে *"কোনো ধাপ নেই"*-এর মতো।
     */
    public function test_the_journey_is_drawn_even_when_no_step_has_a_name(): void
    {
        $this->flow(named: false);

        $approval = $this->waiting(flow: false);

        $this->actingAs($this->boss)->get(route('approval.inbox.show', $approval->id))
            ->assertOk()
            ->assertSee(__('approval::field.journey'))
            ->assertSee(__('approval::message.step_now'));
    }

    /** ⭐ ফেরত পাঠানোর কারণ-কোডটা পর্দা থেকে সারিতে পৌঁছায়। */
    public function test_a_refusal_carries_its_reason_from_the_screen(): void
    {
        $approval = $this->waiting();

        $this->actingAs($this->boss)
            ->post(route('approval.inbox.reject', $approval->id), [
                'remarks' => 'দাম বেশি',
                'reason_code' => 'price',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('approval_decisions', [
            'approval_id' => $approval->id,
            'reason_code' => 'price',
        ]);
    }

    /** ⭐ আর কারণ না বাছলেও ফেরত পাঠানো যায় — পুরনো আচরণ অটুট। */
    public function test_a_refusal_still_works_with_no_reason_picked(): void
    {
        $approval = $this->waiting();

        $this->actingAs($this->boss)
            ->post(route('approval.inbox.reject', $approval->id), ['remarks' => 'শুধু না'])
            ->assertRedirect();

        $this->assertSame(Approval::REJECTED, $approval->fresh()->status);
    }

    /**
     * ⛔ সইয়ের ফর্মে নিশ্চিতকরণ বসানো আছে।
     *
     * ⚠️ প্রশ্নটা [[actions.js]]-এর `data-confirm`-এ, অর্থাৎ ফর্মটা
     * যেখান থেকেই জমা হোক — বোতাম, Enter, বা স্ক্রিপ্ট — প্রশ্নটা
     * আসবেই। ⓘ তাই কোনো শর্টকাট ওটা এড়াতে পারে না।
     */
    public function test_signing_asks_first_and_a_shortcut_cannot_skip_it(): void
    {
        $approval = $this->waiting();

        $page = $this->actingAs($this->boss)->get(route('approval.inbox.show', $approval->id));

        $page->assertOk()->assertSee('data-confirm', escape: false);

        /*
         * ⛔ আর কি-বোর্ডটা কখনো ফর্ম জমা দেয় না।
         *
         * ⚠️ একটা অক্ষরে পাঁচ লাখ টাকা পাশ করা দ্রুততা নয়, দুর্ঘটনা —
         * আর ঐ ভুলটা ফেরানো যায় না।
         */
        $this->assertStringNotContainsString('requestSubmit', $page->getContent(), implode("\n", [
            'সিদ্ধান্তের পর্দার স্ক্রিপ্ট একটা ফর্ম জমা দিতে পারে।',
            '',
            '⛔ শর্টকাট কেবল পৌঁছানোর, কখনো করার নয়।',
        ]));
    }

    // ── সীমার পর্দা ─────────────────────────────────────────────────

    /** ⭐ সীমার পর্দা থেকে বসানো সারিটা সত্যিই সই আটকায়। */
    public function test_a_limit_set_on_the_screen_really_stops_a_signature(): void
    {
        $this->actingAs($this->boss)
            ->post(route('approval.limit.store'), [
                'role_id' => $this->role->id,
                'module' => 'sales',
                'max_amount' => '100',
            ])
            ->assertRedirect(route('approval.limit.index'));

        $this->assertSame(1, ApprovalLimit::query()->count());

        $approval = $this->waiting();   // ৫০,০০০

        /*
         * ⛔ পর্দা থেকে বসানো সীমাটা ইঞ্জিনে পৌঁছেছে কি না — সেটাই আসল দাবি।
         *
         * ⚠️ সারিটা বসল অথচ [[AuthorityService]] ওটা পড়ল না, এমন হলে
         * পর্দাটা একটা মিথ্যা প্রতিশ্রুতি হত: মালিক সীমা লিখে ভাবতেন
         * ব্যবস্থাটা আটকাচ্ছে।
         */
        $this->assertFalse(
            app(ApprovalEngine::class)->canDecide($approval, $this->boss->fresh()),
            '⛔ সীমা ১০০, অঙ্ক ৫০,০০০ — তবু সই দেওয়া যায়ে।',
        );
    }

    /** ⭐ আর খালি ছাদ মানে "সীমা নেই" — পর্দায় সেটা লেখাই হয়। */
    public function test_an_empty_ceiling_is_written_out_not_left_blank(): void
    {
        ApprovalLimit::create(['role_id' => $this->role->id, 'max_amount' => null]);

        $this->actingAs($this->boss)->get(route('approval.limit.index'))
            ->assertOk()
            ->assertSee(__('approval::field.limit_no_ceiling'));
    }

    // ── সহায়ক ───────────────────────────────────────────────────────

    /**
     * ⭐ তিনটা কলামই সত্যিই আঁকা হয় — কেবল ক্লাস নয়।
     *
     * ⛔ `lg:grid-cols-[…]` লিখে দিলে পর্দাটা তিন কলামের **প্রতিশ্রুতি**
     * দেয়। ⚠️ কিন্তু কলামের ভিতরে কিছু না থাকলে ওটা তিনটা ফাঁকা জায়গা,
     * আর সেটা দেখতে ভাঙা লাগে — অথচ কোনো টেস্ট লাল হয় না।
     */
    public function test_all_three_columns_really_have_something_in_them(): void
    {
        $approval = $this->waiting();

        $page = $this->actingAs($this->boss)->get(route('approval.inbox.show', $approval->id));

        $page->assertOk()
            /* ⓘ বাঁ কলাম — যাত্রাপথ */
            ->assertSee(__('approval::field.journey'))
            /* ⓘ মাঝখানের কলাম — কাগজ */
            ->assertSee(__('approval::field.document'))
            /* ⓘ ডান কলাম — সিদ্ধান্ত, আর সেটা আটকানো */
            ->assertSee(__('approval::action.approve'))
            ->assertSee('lg:sticky', escape: false);
    }

    /**
     * ⭐ সংশোধনে ফেরত পাওয়া কাগজ অনুরোধকারী আবার পাঠাতে পারেন।
     *
     * ── ⚠️ আর পথটা একটাই: কাগজটা বদলানো ─────────────────────────────
     * ⛔ নীরবে আবার অনুরোধ পাঠানো গেলে *"না"*-র কোনো মানে থাকত না —
     * যিনি ফেরত পেলেন তিনি আবার বোতামটা চেপে আবার পাঠাতেন।
     *
     * ⓘ তাই বদল না করলে পুরনো ফেরতটাই ফেরে, আর বদল করলেই নতুন অনুরোধ।
     */
    public function test_a_paper_sent_back_can_be_fixed_and_sent_again(): void
    {
        $this->flow();

        $document = Branch::query()->firstOrFail();

        /*
         * ⓘ `stopping()`-এর একজন লগইন করা অনুরোধকারী লাগে।
         *
         * ⛔ `approvals.requested_by` NOT NULL, আর লগইন না পেলে সে
         * কাগজের `created_by` খোঁজে — `Branch`-এ ওটা নেই।
         *
         * ⚠️ ইঞ্জিনটা ঠিকই আটকায়: যে অনুরোধের কোনো অনুরোধকারী
         * নেই, তার নিরীক্ষা হয় না — ভুলটা এই দাবির, নিয়মের নয়।
         */
        $this->actingAs($this->clerk);

        $stopping = app(DocumentApproval::class);

        /*
         * ⓘ অনুরোধটা আসল পথে বসানো হয়, হাতে নয়।
         *
         * ⛔ `Approval::create()` দিয়ে বসালে `state_hash` খালি থাকত, আর
         * তখন [[DocumentApproval::changedSince()]] পুরনো ঘড়ির নিয়মে পড়ত —
         * অর্থাৎ দাবিটা ঠিক যে নিয়মটা মাপতে চায় সেটা মাপত না।
         */
        $approval = $stopping->stopping($document, 'sales', 'discount', '50000', 'test');

        $this->assertNotNull($approval, 'অনুরোধটাই বসেনি।');

        $this->actingAs($this->boss)
            ->post(route('approval.inbox.reject', $approval->id), [
                'remarks' => 'চালানের তারিখ ভুল',
                'reason_code' => 'document',
            ])
            ->assertRedirect();

        /* ⛔ কাগজ না বদলে আবার চাইলে পুরনো ফেরতটাই ফেরে */
        $again = $stopping->stopping($document, 'sales', 'discount', '50000', 'test');

        $this->assertSame((int) $approval->id, (int) $again?->id, implode("\n", [
            'কাগজ না বদলেও একটা নতুন অনুরোধ বসে গেছে।',
            '',
            '⛔ তাহলে "না" বলার কোনো মানে থাকত না — একই বোতামে আবার পাঠানো যেত।',
        ]));

        /* ⭐ আর সংশোধন করলেই নতুন অনুরোধ — অনুরোধকারীকে কিছু খুঁজতে হয় না */
        $document->update(['name_en' => 'Fixed the challan date']);

        $fresh = $stopping->stopping($document->fresh(), 'sales', 'discount', '50000', 'test');

        $this->assertNotSame((int) $approval->id, (int) $fresh?->id,
            '⛔ কাগজ সংশোধনের পরেও নতুন অনুমোদন চাওয়া হয়নি।');
    }

    /**
     * ⭐ আর অনুরোধকারীর পর্দা বলে কী ঠিক করতে হবে।
     *
     * ⛔ অবস্থার ব্যাজটা বলত *"ফেরত"*, আর কেন তা জানতে সারিটা খুলতে হত।
     * ⚠️ ফল দুই রকম, দুইটাই খারাপ: হয় মানুষ বারোটা সারি একে একে
     * খুলতেন, নয় কেউ খুলতেন না আর কাগজটা সারানোই হত না।
     */
    public function test_the_requester_sees_what_to_fix_without_opening_the_row(): void
    {
        $this->flow();

        $approval = $this->waiting();

        $this->actingAs($this->boss)
            ->post(route('approval.inbox.reject', $approval->id), [
                'remarks' => 'চালানের তারিখ ভুল',
                'reason_code' => 'document',
            ])
            ->assertRedirect();

        $this->actingAs($this->clerk)->get(route('approval.inbox.mine'))
            ->assertOk()
            ->assertSee(__('approval::reason.document'))
            ->assertSee(__('approval::message.fix_and_resend'));
    }

    /**
     * ⭐ আর SLA-র ফাঁকটা `flow/coverage`-এও দেখা যায়।
     *
     * ⓘ ব্যতিক্রমের পর্দাও এটা বলে। ⚠️ তবু এখানে লাগে, আর কারণটা
     * মানুষের: মালিক সময়সীমাটা বসান **এই** পর্দার সম্পাদনা থেকে, আর
     * তখনই তিনি ধরে নেন দেরি হলে কিছু একটা হবে।
     */
    public function test_the_coverage_screen_marks_a_clock_with_nowhere_to_go(): void
    {
        $flow = $this->flow();
        $flow->steps()->update(['sla_hours' => 4]);

        $this->actingAs($this->boss)->get(route('approval.flow.coverage'))
            ->assertOk()
            ->assertSee(__('approval::message.coverage_hole', ['levels' => '1']));
    }

    /**
     * ⭐ যে ধাপে সত্যিই বেশি জমে, রিপোর্ট তাকেই উপরে রাখে।
     *
     * ⛔ ক্রমটা না বসালে রিপোর্টটা একটা তালিকা হত, উত্তর নয় — আর
     * *"কোথায় জট"* প্রশ্নের উত্তর খুঁজতে মালিককে চোখে সংখ্যা মেলাতে হত।
     */
    public function test_the_bottleneck_report_puts_the_worst_step_on_top(): void
    {
        $this->flow();

        // ⓘ স্তর ২-এ তিনটা, স্তর ১-এ একটা — জটটা স্তর ২-এ
        $this->waiting();

        foreach (range(1, 3) as $ignored) {
            $this->waiting(level: 2);
        }

        $result = app(ReportEngine::class)->run('approval.bottleneck', []);

        $rows = collect($result->rows);

        /*
         * ⓘ সারিগুলো array, object নয় — [[ReportEngine]] সাজানো সারি
         * ফেরায়, `stdClass` নয়। ⚠️ প্রথমে `->level` লেখা হয়েছিল, আর
         * দাবিটা ভুল কারণে লাল হত — নিয়ম ঠিক, পড়ার পথ ভুল।
         */
        $first = (array) $rows->first();

        $this->assertSame(2, (int) $first['level'], implode("\n", [
            'সবচেয়ে বেশি জমে থাকা ধাপটা উপরে নেই।',
            '',
            '⛔ তাহলে রিপোর্টটা একটা তালিকা, উত্তর নয়।',
        ]));

        $this->assertSame(3, (int) $first['waiting_count']);
    }

    /**
     * ছকের ফর্মের ইনপুট — একটা ধাপ, আর তাতে যা যা বসানো হচ্ছে।
     *
     * @param  array<string, mixed>  $step
     * @return array<string, mixed>
     */
    private function flowInput(array $step = []): array
    {
        return [
            'module' => 'sales',
            'action' => 'discount',
            'is_active' => 1,
            'steps' => [
                [
                    'level' => 1,
                    'step_name' => 'Supervisor',

                    // ⓘ ফর্মে ধরন ও ব্যক্তি একটাই ঘরে — কারণটা Request-এ
                    'approver' => 'user|'.$this->boss->id,
                    ...$step,
                ],
            ],
        ];
    }

    private function flow(bool $named = true): ApprovalFlow
    {
        $flow = ApprovalFlow::create(['module' => 'sales', 'action' => 'discount']);

        ApprovalFlowStep::create([
            'approval_flow_id' => $flow->id,
            'level' => 1,
            'step_name' => $named ? 'Supervisor' : null,
            'approver_type' => ApprovalFlowStep::BY_USER,
            'approver_id' => $this->boss->id,
        ]);

        return $flow;
    }

    /**
     * একটা অপেক্ষমাণ অনুরোধ — ঘড়িসহ বা ছাড়া।
     *
     * ⓘ সারিটা সোজা বসানো হয়, [[ApprovalEngine::request()]] দিয়ে নয় —
     * কারণ এখানে দাবিটা পর্দার, ইঞ্জিনের নয়, আর `due_at` হাতে বসাতে হয়।
     */
    private function waiting(
        ?Carbon $due = null,
        string $module = 'sales',
        string $action = 'discount',
        bool $flow = true,
        int $level = 1,
    ): Approval {
        /*
         * ⛔ প্রবাহটা কেবল `sales`-এর — তাই শর্তটাও `sales`-এর।
         *
         * ⚠️ আগে লেখা ছিল `where('module', $module)` — অর্থাৎ
         * `accounts`-এর একটা কাগজ চাইলে সে দেখত `accounts`-এর
         * প্রবাহ নেই, আর দ্বিতীয়বার **একটা `sales` প্রবাহ** বানাত।
         * ⓘ `approval_flow_scope` unique index তখন ছুঁড়ে ফেলত।
         */
        if ($flow && ApprovalFlow::query()->where('module', 'sales')->doesntExist()) {
            $this->flow();
        }

        return Approval::create([
            'company_id' => $this->company->id,
            'approvable_type' => Branch::class,
            'approvable_id' => Branch::query()->value('id'),
            'module' => $module,
            'action' => $action,
            'amount' => '50000',
            'status' => Approval::PENDING,
            'current_level' => $level,
            'requested_by' => $this->clerk->id,
            'requested_at' => now()->subDays(2),
            'due_at' => $due,
        ]);
    }
}
