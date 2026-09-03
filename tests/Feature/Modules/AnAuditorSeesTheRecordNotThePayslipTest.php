<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalDecision;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use App\Modules\Hr\Models\PayrollRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * নিরীক্ষক অনুমোদনের হিসাব দেখেন, বেতনের কাগজ নয়।
 *
 * ── কী ভাঙার কথা ছিল ────────────────────────────────────────────────
 * রিপোর্টের প্রতিটা সারি অনুরোধের পাতায় নিয়ে যায়। পাতাটা আগে কেবল
 * অনুরোধকারী বা সিদ্ধান্তদাতা খুলতে পারতেন, তাই **নিরীক্ষক ক্লিক করলে
 * ৪০৩** পেতেন — দেখতে জীবন্ত একটা লিংক, চাপলে বন্ধ দরজা।
 *
 * সোজা সমাধান ছিল `approval.report` থাকলেই পাতাটা খুলে দেওয়া। ⛔ **আর
 * সেখানেই ফাঁদ:** পাতাটা অনুমোদনের রেকর্ডের সাথে **অন্তর্নিহিত কাগজটাও**
 * তুলে আনে (`documentOf()`) — ক্রয় বিল, উত্তোলন, আর **বেতনের রান**।
 * তখন যে ম্যানেজারের `approval.report` আছে অথচ HR-এর কিছুই নেই, তিনি
 * অনুমোদনের পাতা দিয়ে **বেতনের অঙ্ক** দেখে ফেলতেন, কোনো ত্রুটি ছাড়াই।
 *
 * ── কেন এই টেস্টের মানুষটার HR-এর কিছুই নেই ─────────────────────────
 * ⚠️ যাঁর দুইটা অনুমতিই আছে তাঁকে দিয়ে পরীক্ষা করলে অনুমতির ফাঁক
 * **কখনোই** দেখা যায় না — সব সবুজ থাকে, আর ফাঁকটা লাইভে থেকে যায়।
 * তাই এখানে নিরীক্ষকের হাতে কেবল একটাই চাবি: `approval.report`।
 */
class AnAuditorSeesTheRecordNotThePayslipTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $clerk;

    private User $manager;

    private User $auditor;

    private Approval $waiting;

    private PayrollRun $run;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['code' => 'AU', 'name_en' => 'Audit Co']);
        CompanyContext::set($this->company->id);

        foreach (['approval.view', 'approval.decide', 'approval.report'] as $name) {
            // লাইভে `PermissionSyncer` এগুলো module.php-র ঘোষণা থেকে বসায়;
            // ফেলনা টেস্ট-ডাটাবেসে সে চলে না, তাই এখানে হাতে।
            Permission::findOrCreate($name, 'web');
        }

        $this->clerk = User::factory()->create(['name' => 'কেরানি', 'current_company_id' => $this->company->id]);
        $this->clerk->companies()->attach($this->company->id);
        $this->clerk->givePermissionTo('approval.view');

        $this->manager = User::factory()->create(['name' => 'ম্যানেজার', 'current_company_id' => $this->company->id]);
        $this->manager->companies()->attach($this->company->id);
        $this->manager->givePermissionTo(['approval.view', 'approval.decide']);

        /*
         * ⚠️ নিরীক্ষকের হাতে **একটাই** চাবি — `approval.report`।
         *
         * `approval.view` নেই, `approval.decide` নেই, আর HR-এর কিছুই
         * নেই। ফাঁকটা ঠিক এই মানুষটার চোখেই দেখা যায়।
         */
        $this->auditor = User::factory()->create(['name' => 'নিরীক্ষক', 'current_company_id' => $this->company->id]);
        $this->auditor->companies()->attach($this->company->id);
        $this->auditor->givePermissionTo('approval.report');

        $flow = ApprovalFlow::create(['module' => 'hr', 'action' => 'payroll']);
        ApprovalFlowStep::create([
            'approval_flow_id' => $flow->id,
            'level' => 1,
            'approver_type' => ApprovalFlowStep::BY_USER,
            'approver_id' => $this->manager->id,
        ]);

        $this->run = PayrollRun::create([
            'company_id' => $this->company->id,
            'document_no' => 'PRL-2026-2027-0001',
            'month' => now()->format('Y-m'),
            'trx_date' => now()->toDateString(),
            'gross_total' => '900000',
            'deduction_total' => '25000',
            'net_total' => '875000',
            'employee_count' => 12,
            'created_by' => $this->clerk->id,
        ]);

        $this->waiting = Approval::create([
            'company_id' => $this->company->id,
            'approvable_type' => PayrollRun::class,
            'approvable_id' => $this->run->id,
            'module' => 'hr',
            'action' => 'payroll',
            'amount' => '875000',
            'status' => Approval::PENDING,
            'current_level' => 1,
            'requested_by' => $this->clerk->id,
            'requested_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    /** ⭐ আসল পরীক্ষা: দরজা খোলে, কিন্তু বেতনের কাগজ পাতায় নেই। */
    public function test_the_auditor_can_open_the_page(): void
    {
        $this->actingAs($this->auditor)
            ->get(route('approval.inbox.show', ['approval' => $this->waiting->id]))
            ->assertOk();
    }

    public function test_but_the_payroll_document_is_not_on_it(): void
    {
        $page = $this->actingAs($this->auditor)
            ->get(route('approval.inbox.show', ['approval' => $this->waiting->id]));

        $page->assertOk();

        // নথিটা কন্ট্রোলার থেকেই যায় না — পর্দায় লুকানো নয়, তোলাই হয় না
        $this->assertNull($page->viewData('document'));
        $this->assertTrue($page->viewData('documentHidden'));

        // আর কাগজের নম্বরটাও কোথাও নেই
        $page->assertDontSee('PRL-2026-2027-0001');

        /*
         * ⚠️ অঙ্কটা আলাদা করে দেখা হয় না, আর কারণটা লিখে রাখা দরকার:
         * অনুমোদনের নিজের `amount`-ও ৮,৭৫,০০০ — ওটা রিপোর্টেই আছে, আর
         * ওটা লুকানোর কথা নয়। যা লুকানোর কথা সেটা **কাগজটা**: কতজন
         * কর্মী, মোট বেতন, কর্তন। তাই নম্বর ও `document` ধরে পরীক্ষা,
         * অঙ্ক ধরে নয় — নাহলে টেস্টটা ভুল জিনিস পাহারা দিত।
         */
        $page->assertDontSee('900000');
    }

    public function test_the_record_itself_is_there_because_that_is_the_point(): void
    {
        ApprovalDecision::create([
            'approval_id' => $this->waiting->id,
            'level' => 1,
            'user_id' => $this->manager->id,
            'decision' => 'approved',
            'remarks' => 'মাস মিলেছে',
            'decided_at' => now(),
        ]);

        $page = $this->actingAs($this->auditor)
            ->get(route('approval.inbox.show', ['approval' => $this->waiting->id]));

        $page->assertOk()
            ->assertSee('কেরানি')          // কে চেয়েছেন
            ->assertSee('ম্যানেজার')        // কে সিদ্ধান্ত দিয়েছেন
            ->assertSee('মাস মিলেছে')       // কী মন্তব্যে
            ->assertSee(__('approval::message.document_not_yours'));
    }

    /** নিরীক্ষক সিদ্ধান্ত দিতে পারেন না — বোতামও নেই, পথও নেই। */
    public function test_the_auditor_cannot_decide(): void
    {
        $page = $this->actingAs($this->auditor)
            ->get(route('approval.inbox.show', ['approval' => $this->waiting->id]));

        $this->assertFalse($page->viewData('canDecide'));

        $this->actingAs($this->auditor)
            ->post(route('approval.inbox.approve', ['approval' => $this->waiting->id]), [])
            ->assertForbidden();
    }

    /** যিনি সিদ্ধান্ত দেবেন, তিনি কাগজটা দেখেন — নাহলে সই দেবেন কীসের উপর। */
    public function test_the_person_who_must_sign_does_see_the_document(): void
    {
        $page = $this->actingAs($this->manager)
            ->get(route('approval.inbox.show', ['approval' => $this->waiting->id]));

        $page->assertOk();
        $this->assertFalse($page->viewData('documentHidden'));
        $this->assertNotNull($page->viewData('document'));
        $page->assertSee('PRL-2026-2027-0001');
    }

    /** আর যাঁর একটাও চাবি নেই, তাঁর কাছে দরজাটা বন্ধই। */
    public function test_a_stranger_still_gets_nothing(): void
    {
        $stranger = User::factory()->create(['current_company_id' => $this->company->id]);
        $stranger->companies()->attach($this->company->id);

        $this->actingAs($stranger)
            ->get(route('approval.inbox.show', ['approval' => $this->waiting->id]))
            ->assertForbidden();
    }
}
