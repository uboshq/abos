<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * অন্য কারো ইনবক্স — কে দেখতে পান, আর সংখ্যাগুলো কার।
 *
 * ── কেন এটা লাগল ────────────────────────────────────────────────────
 * "কে কয়টা সিদ্ধান্ত দিলেন" রিপোর্টটা গণনা দেখায় কিন্তু কোথাও নিয়ে যায়
 * না। এই ছাঁকনিটা সেই গন্তব্যটা দেয়: রহিমের নাম দেখে ম্যানেজার জানতে
 * চান **তাঁর সইয়ের অপেক্ষায় এখন কী কী**।
 *
 * ── দুইটা নিয়ম, আর দুইটাই এখানে বাঁধা ───────────────────────────────
 *   **ব্যক্তি ভিত্তি বদলায়, মডিউল দৃশ্য ছাঁকে।**
 *   গণনা সবসময় ভিত্তি থেকে — তাই চিপ আর তালিকা কখনো দুই কথা বলে না।
 *
 * ⚠️ আর অনুমতির পরীক্ষাটা করা হয় **যাঁর অনুমতি নেই তাঁকে দিয়ে** — নাহলে
 * ফাঁকটা দেখাই যেত না। আজ ঠিক এই ভুলেই বেতনের কাগজ ফাঁস হতে বসেছিল।
 */
class WhoseInboxAmILookingAtTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $rahim;

    private User $karim;

    private User $boss;

    private User $clerk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['code' => 'WI', 'name_en' => 'Whose Inbox Co']);
        CompanyContext::set($this->company->id);

        foreach (['approval.view', 'approval.decide', 'approval.report'] as $name) {
            // লাইভে `PermissionSyncer` বসায়; ফেলনা ডাটাবেসে সে চলে না।
            Permission::findOrCreate($name, 'web');
        }

        $this->rahim = $this->member('রহিম', ['approval.view', 'approval.decide']);
        $this->karim = $this->member('করিম', ['approval.view', 'approval.decide']);
        $this->clerk = $this->member('কেরানি', ['approval.view']);

        // ⚠️ মালিকের হাতে `approval.report` আছে, কিন্তু তিনি কোনো ছকে নেই
        $this->boss = $this->member('মালিক', ['approval.view', 'approval.report']);

        /*
         * দুইটা ছক, দুই মডিউলে, দুইজন আলাদা মানুষের।
         *
         * একই মানুষ দুইটাতে থাকলে "ব্যক্তি ভিত্তি বদলায়" কথাটা প্রমাণ
         * করা যেত না — সংখ্যাটা একই থাকত।
         */
        $this->flowFor('purchase', 'order', $this->rahim);
        $this->flowFor('hr', 'payroll', $this->karim);

        // রহিমের তিনটা, করিমের একটা
        $this->waiting('A', 'purchase', 'order');
        $this->waiting('B', 'purchase', 'order');
        $this->waiting('C', 'purchase', 'order');
        $this->waiting('D', 'hr', 'payroll');
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    /** @param list<string> $can */
    private function member(string $name, array $can): User
    {
        $user = User::factory()->create(['name' => $name, 'current_company_id' => $this->company->id]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);
        $user->givePermissionTo($can);

        return $user;
    }

    private function flowFor(string $module, string $action, User $approver): void
    {
        $flow = ApprovalFlow::create(['module' => $module, 'action' => $action]);

        ApprovalFlowStep::create([
            'approval_flow_id' => $flow->id,
            'level' => 1,
            'approver_type' => ApprovalFlowStep::BY_USER,
            'approver_id' => $approver->id,
        ]);
    }

    private function waiting(string $code, string $module, string $action): Approval
    {
        return Approval::create([
            'company_id' => $this->company->id,
            'approvable_type' => Branch::class,
            'approvable_id' => Branch::create([
                'company_id' => $this->company->id,
                'code' => $code,
                'name_en' => 'Branch '.$code,
            ])->id,
            'module' => $module,
            'action' => $action,
            'status' => Approval::PENDING,
            'current_level' => 1,
            'requested_by' => $this->clerk->id,
            'requested_at' => now(),
        ]);
    }

    public function test_without_the_permission_there_is_no_chooser_at_all(): void
    {
        $page = $this->actingAs($this->rahim)->get(route('approval.inbox.index'));

        $page->assertOk();
        $this->assertSame([], $page->viewData('signers'));
        $this->assertCount(3, $page->viewData('approvals'));
    }

    /**
     * ⛔ আর ঠিকানায় হাতে লিখে দিলেও নয়।
     *
     * ⚠️ চুপচাপ উপেক্ষা করলে রহিম `?person=<করিম>` বসিয়ে **নিজেরই**
     * তালিকা দেখতেন আর ভাবতেন করিমেরটা দেখছেন — সবচেয়ে খারাপ ধরনের
     * ভুল, কারণ সংখ্যাটা সত্যি, কেবল কার সংখ্যা তা নয়।
     */
    public function test_a_hand_typed_person_is_refused_not_ignored(): void
    {
        $this->actingAs($this->rahim)
            ->get(route('approval.inbox.index', ['person' => $this->karim->id]))
            ->assertForbidden();
    }

    public function test_the_boss_can_look_at_someone_elses_inbox(): void
    {
        $mine = $this->actingAs($this->boss)->get(route('approval.inbox.index'));

        // ⚠️ মালিক নিজে কোনো ছকে নেই — তাই তাঁর নিজের ইনবক্স খালি
        $mine->assertOk();
        $this->assertCount(0, $mine->viewData('approvals'));
        $this->assertSame(0, $mine->viewData('person'));

        $his = $this->actingAs($this->boss)
            ->get(route('approval.inbox.index', ['person' => $this->rahim->id]));

        $his->assertOk();
        $this->assertCount(3, $his->viewData('approvals'));
        $this->assertSame($this->rahim->id, $his->viewData('person'));
        $his->assertSee(__('approval::menu.inbox_of', ['name' => 'রহিম']));
    }

    /** ছকে যাঁদের নাম আছে কেবল তাঁরাই তালিকায় — বাকিরা নয়। */
    public function test_only_the_people_who_actually_sign_are_offered(): void
    {
        $signers = $this->actingAs($this->boss)
            ->get(route('approval.inbox.index'))
            ->viewData('signers');

        $this->assertArrayHasKey($this->rahim->id, $signers);
        $this->assertArrayHasKey($this->karim->id, $signers);

        // কেরানি ও মালিক কোনো ছকে নেই — তাঁদের ইনবক্স সবসময় খালি,
        // তাই নামটা রাখলে কেবল বিভ্রান্তি বাড়ত
        $this->assertArrayNotHasKey($this->clerk->id, $signers);
        $this->assertArrayNotHasKey($this->boss->id, $signers);
    }

    /** রোল ধরে বসানো ছক — ওই রোলের সবাই তালিকায় আসেন। */
    public function test_a_rule_set_by_role_offers_everyone_in_that_role(): void
    {
        $auditors = Role::findOrCreate('stock-checker');
        $this->clerk->assignRole($auditors);

        $flow = ApprovalFlow::create(['module' => 'inventory', 'action' => 'transfer']);
        ApprovalFlowStep::create([
            'approval_flow_id' => $flow->id,
            'level' => 1,
            'approver_type' => ApprovalFlowStep::BY_ROLE,
            'approver_id' => $auditors->id,
        ]);

        $signers = $this->actingAs($this->boss)
            ->get(route('approval.inbox.index'))
            ->viewData('signers');

        $this->assertArrayHasKey($this->clerk->id, $signers, 'রোল ধরে বসানো ছকের মানুষটা তালিকায় নেই');
    }

    /**
     * ⭐ দুইটা ছাঁকনি একসাথে — আর গণনাগুলো ভিত্তি থেকেই।
     *
     * ⚠️ গণনাটা ছাঁকা তালিকা থেকে নিলে "ক্রয় ৩" বেছে নেওয়ার পর বাকি
     * চিপগুলো শূন্য দেখাত, আর মানুষ ভাবতেন ওখানে কিছু নেই।
     */
    public function test_the_person_changes_the_base_and_the_module_narrows_the_view(): void
    {
        $page = $this->actingAs($this->boss)->get(route('approval.inbox.index', [
            'person' => $this->rahim->id,
            'module' => 'purchase',
        ]));

        $page->assertOk();
        $this->assertCount(3, $page->viewData('approvals'));
        $this->assertSame('purchase', $page->viewData('selected'));

        // রহিমের ভিত্তিতে মোট তিনটা, আর সবগুলোই ক্রয়ের
        $this->assertSame(3, $page->viewData('total'));
        $this->assertSame(3, $page->viewData('modules')['purchase']['count']);

        // ⚠️ করিমের বেতনেরটা রহিমের ভিত্তিতে নেই — ভিত্তি সত্যিই বদলেছে
        $this->assertArrayNotHasKey('hr', $page->viewData('modules'));

        // আর করিমের দিকে গেলে ঠিক উল্টোটা
        $his = $this->actingAs($this->boss)
            ->get(route('approval.inbox.index', ['person' => $this->karim->id]));

        $this->assertCount(1, $his->viewData('approvals'));
        $this->assertArrayHasKey('hr', $his->viewData('modules'));
        $this->assertArrayNotHasKey('purchase', $his->viewData('modules'));
    }

    /** অচেনা কারো আইডি — ৪০৪, নীরবে নিজের তালিকা নয়। */
    public function test_a_person_who_signs_nothing_is_not_a_valid_choice(): void
    {
        $this->actingAs($this->boss)
            ->get(route('approval.inbox.index', ['person' => $this->clerk->id]))
            ->assertNotFound();
    }
}
