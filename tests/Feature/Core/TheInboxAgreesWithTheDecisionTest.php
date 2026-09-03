<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ইনবক্স যা দেখায়, সিদ্ধান্তের দরজা তা-ই মানে।
 *
 * ── কেন এই পাহারাটা লাগল ────────────────────────────────────────────
 * নিয়মটা এখন দুই ভাষায় লেখা: `canDecide()` একটা সারি ধরে PHP-তে বলে,
 * আর `pendingFor()` পুরো তালিকাটা SQL-এ ছাঁকে। আগে দ্বিতীয়টা প্রথমটাকেই
 * ডাকত, তাই দুইটা আলাদা হওয়ার উপায় ছিল না — এখন আছে।
 *
 * ⚠️ আলাদা হলে যা হত: ইনবক্সে এমন সারি দেখা যেত যেটা খুলে সিদ্ধান্ত
 * দেওয়া যায় না ("Approve" চাপলে ব্যতিক্রম), অথবা উল্টোটা — যে অনুরোধ
 * সত্যিই ইনার সইয়ের অপেক্ষায়, সেটা তালিকাতেই আসত না আর **চিরকাল ঝুলে
 * থাকত**। দ্বিতীয়টা নীরব, তাই বেশি বিপজ্জনক।
 *
 * ── আর গতির দিকটা ───────────────────────────────────────────────────
 * তালিকাটা তিন জায়গা থেকে ডাকা হয় — ইনবক্স, হোম পর্দার উইজেট, আর
 * স্ট্যাটাস বার। খরচটা সারির সংখ্যার সাথে বাড়লে সেটা ধরা পড়ত সবচেয়ে
 * ব্যস্ত গ্রাহকের কাছে, সবচেয়ে ব্যস্ত দিনে। তাই কোয়েরির সংখ্যাটাও
 * এখানে গোনা হয়।
 */
class TheInboxAgreesWithTheDecisionTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $clerk;

    private User $manager;

    private User $director;

    private User $stranger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['code' => 'IB', 'name_en' => 'Inbox Co']);
        CompanyContext::set($this->company->id);

        $this->clerk = User::create(['name' => 'Clerk', 'email' => 'c@ib.test', 'password' => 'x']);
        $this->manager = User::create(['name' => 'Manager', 'email' => 'm@ib.test', 'password' => 'x']);
        $this->director = User::create(['name' => 'Director', 'email' => 'd@ib.test', 'password' => 'x']);
        $this->stranger = User::create(['name' => 'Stranger', 'email' => 'x@ib.test', 'password' => 'x']);

        /*
         * দুইটা ছক, আর ইচ্ছে করেই দুই রকম।
         *
         * ক্রয়াদেশে দুই স্তর — প্রথমটা **রোল ধরে** (যে কেউ ওই রোলে
         * থাকলেই), দ্বিতীয়টা **ব্যক্তি ধরে**। দুইটা পথই আলাদা কোড, আর
         * SQL-এ নামানোর সময় রোলেরটাই সবচেয়ে সহজে ভুল হত: আগে প্রতিটা
         * ধাপে `$user->roles()` কোয়েরি হত, এখন রোলগুলো একবার তুলে
         * মেমরিতে মেলানো হয়।
         */
        $approvers = Role::findOrCreate('purchase-approver');
        $this->manager->assignRole($approvers);

        $order = ApprovalFlow::create(['module' => 'purchase', 'action' => 'order']);
        ApprovalFlowStep::create([
            'approval_flow_id' => $order->id,
            'level' => 1,
            'approver_type' => ApprovalFlowStep::BY_ROLE,
            'approver_id' => $approvers->id,
        ]);
        ApprovalFlowStep::create([
            'approval_flow_id' => $order->id,
            'level' => 2,
            'approver_type' => ApprovalFlowStep::BY_USER,
            'approver_id' => $this->director->id,
        ]);

        $transfer = ApprovalFlow::create(['module' => 'inventory', 'action' => 'transfer']);
        ApprovalFlowStep::create([
            'approval_flow_id' => $transfer->id,
            'level' => 1,
            'approver_type' => ApprovalFlowStep::BY_USER,
            'approver_id' => $this->manager->id,
        ]);
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    private function engine(): ApprovalEngine
    {
        // ইঞ্জিনটা `scoped`, ভেতরে ছক জমিয়ে রাখে। প্রতিটা মাপে টাটকা
        // একটা না নিলে দ্বিতীয় মাপটা কেবল জমানো জিনিস পড়ত।
        app()->forgetInstance(ApprovalEngine::class);

        return app(ApprovalEngine::class);
    }

    private function document(string $code): Branch
    {
        return Branch::create([
            'company_id' => $this->company->id,
            'code' => $code,
            'name_en' => 'Branch '.$code,
        ]);
    }

    /**
     * নিয়মটা হাতে লেখা — `canDecide()` ধরে, একটা একটা সারি।
     *
     * @return list<int>
     */
    private function theSlowWay(User $user): array
    {
        $engine = $this->engine();

        return Approval::query()->pending()->orderBy('requested_at')->get()
            ->filter(fn (Approval $a) => $engine->canDecide($a, $user))
            ->map(fn (Approval $a) => $a->id)
            ->values()
            ->all();
    }

    /** @return list<int> */
    private function theFastWay(User $user): array
    {
        return $this->engine()->pendingFor($user)->map(fn (Approval $a) => $a->id)->values()->all();
    }

    private function ask(string $code, string $module, string $action, ?string $amount, User $by): Approval
    {
        $this->actingAs($by);

        $approval = $this->engine()->request(
            document: $this->document($code),
            module: $module,
            action: $action,
            amount: $amount,
        );

        $this->assertNotNull($approval, "{$module}.{$action} — অনুরোধই তৈরি হয়নি, তাহলে বাকি পরীক্ষার কোনো মানে নেই");

        return $approval;
    }

    public function test_the_two_ways_of_asking_give_the_same_answer(): void
    {
        $this->ask('A1', 'purchase', 'order', '5000', $this->clerk);
        $this->ask('A2', 'inventory', 'transfer', null, $this->clerk);

        // ইনি নিজেই অনুমোদনকারী, আর নিজের অনুরোধ — সীমা শূন্য, তাই বাদ
        $this->ask('A3', 'inventory', 'transfer', null, $this->manager);

        // দ্বিতীয় স্তরে উঠে যাওয়া একটা
        $second = $this->ask('A4', 'purchase', 'order', '9000', $this->clerk);
        $second->update(['current_level' => 2]);

        // কোনো ছক নেই এমন একটা কাজ — কেউ সিদ্ধান্ত দিতে পারবে না
        Approval::create([
            'company_id' => $this->company->id,
            'approvable_type' => Branch::class,
            'approvable_id' => $this->document('A5')->id,
            'module' => 'sales',
            'action' => 'discount',
            'status' => Approval::PENDING,
            'current_level' => 1,
            'requested_by' => $this->clerk->id,
            'requested_at' => now(),
        ]);

        foreach ([$this->manager, $this->director, $this->clerk, $this->stranger] as $user) {
            $this->assertSame(
                $this->theSlowWay($user),
                $this->theFastWay($user),
                "{$user->name} — ইনবক্স আর canDecide() দুই কথা বলছে"
            );
        }

        // ⚠️ আর তালিকাটা যেন খালি না হয় — খালি হলে উপরের মিলটা অর্থহীন
        $this->assertNotEmpty($this->theFastWay($this->manager));
        $this->assertNotEmpty($this->theFastWay($this->director));
        $this->assertSame([], $this->theFastWay($this->stranger));
    }

    /**
     * ⭐ কেবল একটা নথি-নির্দিষ্ট ছক — তবু সিদ্ধান্ত দেওয়া যায়।
     *
     * ── এটাই সেই বাগ যা ক্রেতার অফিসে ধরা পড়ত ───────────────────────
     * `request()` নথিটা হাতে পেত, তাই নথি-নির্দিষ্ট ছক খুঁজে অনুরোধ
     * বানাত। কিন্তু `canDecide()` ও `approve()` নথিটা দিত না, তাই তারা
     * কেবল মডিউল-ব্যাপী ছক (`document_type = ''`) খুঁজত — আর সেটা
     * এখানে নেই।
     *
     * ফল: অনুরোধ তৈরি হত, ইনবক্সে আসত না, কেউ সিদ্ধান্ত দিতে পারত না।
     * **কাগজটা চিরকাল ঝুলে থাকত, আর কেউ বুঝত না কেন।**
     *
     * ⛔ আমাদের কোনো ডাটাবেসে এটা দেখা যেত না — সব seeded ছক
     * মডিউল-ব্যাপী। তাই পরীক্ষাটা ইচ্ছে করে **কেবল** নির্দিষ্ট ছকটাই
     * বসায়, মডিউল-ব্যাপী কোনোটা নয়।
     */
    public function test_a_flow_set_for_one_kind_of_document_can_still_be_decided(): void
    {
        $only = ApprovalFlow::create([
            'module' => 'sales',
            'action' => 'discount',
            'document_type' => class_basename(Branch::class),
        ]);
        ApprovalFlowStep::create([
            'approval_flow_id' => $only->id,
            'level' => 1,
            'approver_type' => ApprovalFlowStep::BY_USER,
            'approver_id' => $this->director->id,
        ]);

        $this->assertNull(
            ApprovalFlow::query()->where('module', 'sales')->where('document_type', '')->first(),
            'মডিউল-ব্যাপী ছক থেকে গেলে পরীক্ষাটা আসল জিনিসটা মাপে না'
        );

        $waiting = $this->ask('D1', 'sales', 'discount', '700', $this->clerk);

        $this->assertTrue(
            $this->engine()->canDecide($waiting->fresh(), $this->director),
            'নথি-নির্দিষ্ট ছকে বসা অনুরোধে কেউ সিদ্ধান্ত দিতে পারছে না'
        );
        $this->assertContains($waiting->id, $this->theFastWay($this->director));
        $this->assertSame($this->theSlowWay($this->director), $this->theFastWay($this->director));

        // আর সিদ্ধান্তটা সত্যিই বসে — এক স্তরের ছক, তাই সাথে সাথেই অনুমোদিত
        $this->engine()->approve($waiting->fresh(), $this->director);
        $this->assertSame(Approval::APPROVED, $waiting->fresh()->status);
    }

    public function test_a_decision_already_given_leaves_the_inbox(): void
    {
        $one = $this->ask('B1', 'inventory', 'transfer', null, $this->clerk);

        $this->assertContains($one->id, $this->theFastWay($this->manager));

        $this->engine()->approve($one->fresh(), $this->manager);

        $this->assertNotContains($one->id, $this->theFastWay($this->manager));
        $this->assertSame($this->theSlowWay($this->manager), $this->theFastWay($this->manager));
    }

    public function test_the_cost_does_not_grow_with_the_number_of_rows(): void
    {
        foreach (range(1, 3) as $n) {
            $this->ask('C'.$n, 'purchase', 'order', '1000', $this->clerk);
        }

        /*
         * ⚠️ প্রথমে একবার ফেলে দেওয়া রান — মাপার আগে সব ঠান্ডা ক্যাশ গরম।
         *
         * ইঞ্জিনের নিজের জমানো জিনিস প্রতিবার ফেলে দেওয়া হয়, কিন্তু
         * অ্যাপে আরও কিছু জিনিস **প্রক্রিয়াভিত্তিক** — সেটিংসের মান,
         * অনুমতির রেজিস্ট্রার। ওগুলো প্রথম ডাকে একবার কোয়েরি করে, তারপর
         * আর করে না। ফলে প্রথম মাপটা সবসময় এক-দুইটা বেশি দেখাত, আর
         * তুলনাটা "৩ সারিতে ৮, ২৫ সারিতে ৭" — অর্থহীন।
         *
         * ⓘ এখানে প্রশ্নটা "প্রথম পাতাটা কত খরচের" নয়, **"সারি বাড়লে
         * খরচ বাড়ে কি না"**। তাই দুইটা মাপই গরম অবস্থায় নেওয়া হয়।
         */
        $this->inboxOfAFreshUser();

        $few = $this->countQueries(fn () => $this->inboxOfAFreshUser());

        foreach (range(4, 25) as $n) {
            $this->ask('C'.$n, 'purchase', 'order', '1000', $this->clerk);
        }

        $many = $this->countQueries(fn () => $this->inboxOfAFreshUser());

        // ⚠️ পাহারাটা অন্ধ কি না তা এখানেই: সারির সংখ্যা সত্যিই বেড়েছে
        $this->assertCount(25, $this->theFastWay($this->manager));

        $this->assertSame(
            $few,
            $many,
            "তিনটা সারিতে {$few}টা কোয়েরি, পঁচিশটায় {$many}টা — খরচ সারির সংখ্যার সাথে বাড়ছে"
        );
    }

    /**
     * টাটকা একজন ব্যবহারকারীর ইনবক্স।
     *
     * ⚠️ ── কেন প্রতিবার ব্যবহারকারীটাও নতুন করে তোলা হয় ──────────────
     * প্রথম মাপে ৬টা কোয়েরি এল, দ্বিতীয়টায় ৪টা — অথচ সারি বেড়েছে।
     * কারণ Eloquent সম্পর্কটা **মডেলের উপরেই** জমিয়ে রাখে: প্রথমবার
     * `$user->roles` পড়তে একটা কোয়েরি যায়, পরে আর যায় না। অর্থাৎ
     * দুইটা মাপ দুই অবস্থায় নেওয়া হচ্ছিল, আর তুলনাটা অর্থহীন ছিল।
     *
     * ⓘ কমে যাওয়াটা ভুল ধরা পড়েনি বলে ছেড়ে দেওয়া যেত ("৪ < ৬, তাই
     * বাড়েনি") — কিন্তু তখন পাহারাটা ঢিলা হয়ে যেত: একটা সত্যিকারের
     * N+1 পরে ওই ফাঁক দিয়েই ঢুকত। **সমান সংখ্যাই একমাত্র শক্ত দাবি।**
     */
    private function inboxOfAFreshUser(): int
    {
        return $this->engine()->pendingFor(User::findOrFail($this->manager->id))->count();
    }

    private function countQueries(callable $work): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $work();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
