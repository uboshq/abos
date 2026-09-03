<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Engines\Drill\DrillResolver;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalDecision;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Approval\Http\Controllers\ApprovalReportController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * §২.৮-এর চারটা রিপোর্ট — সংখ্যাগুলো সত্যি বলে কি না।
 *
 * ── কেন এই পরীক্ষাগুলো এভাবে লেখা ───────────────────────────────────
 * একটা রিপোর্ট ভুল হলে কিছুই ভাঙে না — সে একটা সংখ্যা দেখায়, আর
 * পাঠকের কাছে সেটা যাচাই করার কোনো উপায় থাকে না। তাই এখানে প্রতিটা
 * সংখ্যা **হাতে গোনা ডেটার বিপরীতে** মেলানো হয়: তিনটা অপেক্ষমাণ,
 * একটা অনুমোদিত, একটা ফেরত — আর রিপোর্ট ঠিক তা-ই বলে কি না।
 *
 * ⚠️ আর একটা জিনিস আলাদা করে পরীক্ষা করা হয়: **সারিটা ক্লিকযোগ্য কি
 * না**। "প্রতিটা সংখ্যা তার উৎসে যায়" নিয়মটা কেবল কলামে
 * `type => DOCUMENT` লিখে দিলে হয় না — ড্রিল-রেজলভারকে উৎসের নামটাও
 * চিনতে হয়, নাহলে সারিটা দেখতে লিংকের মতো হয়ে চাপলে কিছুই হয় না।
 */
class TheApprovalReportsSayWhoIsWaitingTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $clerk;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['code' => 'RP', 'name_en' => 'Report Co']);
        CompanyContext::set($this->company->id);

        $this->clerk = User::factory()->create(['name' => 'কেরানি', 'current_company_id' => $this->company->id]);
        $this->manager = User::factory()->create(['name' => 'ম্যানেজার', 'current_company_id' => $this->company->id]);
        $this->manager->companies()->attach($this->company->id);

        // লাইভে `PermissionSyncer` এটা বসায় (module.php-র ঘোষণা থেকে);
        // ফেলনা টেস্ট-ডাটাবেসে সে চলে না, তাই এখানে হাতে।
        Permission::findOrCreate('approval.report', 'web');
        $this->manager->givePermissionTo('approval.report');
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    private function approval(string $code, string $status, string $amount, ?int $daysAgo = 3): Approval
    {
        $branch = Branch::create([
            'company_id' => $this->company->id,
            'code' => $code,
            'name_en' => 'Branch '.$code,
        ]);

        return Approval::create([
            'company_id' => $this->company->id,
            'approvable_type' => Branch::class,
            'approvable_id' => $branch->id,
            'module' => 'purchase',
            'action' => 'order',
            'amount' => $amount,
            'status' => $status,
            'current_level' => 1,
            'requested_by' => $this->clerk->id,
            'requested_at' => now()->subDays($daysAgo),
            'decided_at' => $status === Approval::PENDING ? null : now(),
        ]);
    }

    private function rows(string $key): array
    {
        // ⓘ `ReportResult::$rows` সাধারণ array, Collection নয় — ইঞ্জিন
        // পাতা ধরে ফল দেয়, আর রপ্তানি ও ছাপা একই আকারটাই পড়ে।
        return app(ReportEngine::class)->run($key, [
            'from' => now()->subMonth()->toDateString(),
            'to' => now()->toDateString(),
        ])->rows;
    }

    public function test_the_pending_report_counts_only_what_is_still_waiting(): void
    {
        $this->approval('P1', Approval::PENDING, '1000');
        $this->approval('P2', Approval::PENDING, '2000');
        $this->approval('P3', Approval::APPROVED, '9000');

        $rows = $this->rows('approval.pending');

        $this->assertCount(2, $rows, 'অনুমোদিত সারিটাও অপেক্ষমাণ তালিকায় এসেছে');
        $this->assertSame('কেরানি', $rows[0]['requester']);

        // ⚠️ কাজের নামটা কাঁচা কী নয় — module.php-র ঘোষণা থেকে
        $this->assertSame('ক্রয়াদেশ নিশ্চিত করা', $rows[0]['what']);

        // তিন দিন আগে চাওয়া হয়েছিল, তাই তিন দিন ধরে ঝুলছে
        $this->assertSame(3, (int) $rows[0]['waiting_days']);
    }

    public function test_a_row_really_opens_the_request(): void
    {
        $waiting = $this->approval('D1', Approval::PENDING, '500');

        $row = $this->rows('approval.pending')[0];

        /*
         * ⚠️ এখানেই আসল পরীক্ষা: কলামে DOCUMENT লেখা থাকলেই সারিটা
         * ক্লিকযোগ্য হয় না। রেজলভারকে `approval` নামটা চিনতে হয়, আর
         * সেটা আসে module.php-র `drill_sources` থেকে।
         */
        $this->assertSame('approval', $row['approval_source']);
        $this->assertTrue(app(DrillResolver::class)->knows($row['approval_source']));
        $this->assertSame($waiting->id, (int) $row['approval_id']);

        $this->assertSame(
            ['approval.inbox.show', ['approval' => $waiting->id]],
            $waiting->drillRoute()
        );
    }

    public function test_the_rejected_report_carries_the_reason(): void
    {
        $refused = $this->approval('R1', Approval::REJECTED, '7000');

        ApprovalDecision::create([
            'approval_id' => $refused->id,
            'level' => 1,
            'user_id' => $this->manager->id,
            'decision' => 'rejected',
            'remarks' => 'দর বেশি',
            'decided_at' => now(),
        ]);

        $rows = $this->rows('approval.rejected');

        $this->assertCount(1, $rows);
        $this->assertSame('দর বেশি', $rows[0]['reason']);
        $this->assertSame('ম্যানেজার', $rows[0]['deciders']);
        $this->assertSame(3, (int) $rows[0]['took_days']);

        // আর অনুমোদিতের তালিকায় এটা নেই
        $this->assertSame([], $this->rows('approval.approved'));
    }

    public function test_the_person_report_counts_each_decision_not_each_request(): void
    {
        $one = $this->approval('U1', Approval::APPROVED, '100');
        $two = $this->approval('U2', Approval::REJECTED, '200');

        foreach ([[$one, 'approved'], [$two, 'rejected']] as [$approval, $decision]) {
            ApprovalDecision::create([
                'approval_id' => $approval->id,
                'level' => 1,
                'user_id' => $this->manager->id,
                'decision' => $decision,
                'decided_at' => now(),
            ]);
        }

        $rows = $this->rows('approval.by_user');

        $this->assertCount(1, $rows);
        $this->assertSame('ম্যানেজার', $rows[0]['decider']);
        $this->assertSame(1, (int) $rows[0]['approved_count']);
        $this->assertSame(1, (int) $rows[0]['rejected_count']);
        $this->assertSame(2, (int) $rows[0]['total_count']);
    }

    /**
     * চারটা পর্দাই খোলে, আর slug-গুলো মেনুর সাথে মেলে।
     *
     * ⓘ slug ভুল হলে `route()` দিব্যি একটা ঠিকানা বানায় আর কন্ট্রোলার
     * ৪০৪ করে — সেটাই `ALinkThatLooksAliveAndIsNot` ধরে। এখানে
     * উল্টো দিকটা: ঘোষিত প্রতিটা slug সত্যিই খোলে কি না।
     */
    public function test_every_declared_slug_opens(): void
    {
        $slugs = (new ReflectionClass(ApprovalReportController::class))
            ->getConstant('SLUGS');

        $this->assertNotSame([], $slugs, 'একটাও slug নেই — পাহারাটা কি অন্ধ?');

        foreach (array_keys($slugs) as $slug) {
            $this->actingAs($this->manager)
                ->get(route('approval.report.show', ['slug' => $slug]))
                ->assertOk();
        }

        // আর অচেনা slug ৪০৪, নীরবে "সব" নয়
        $this->actingAs($this->manager)
            ->get(route('approval.report.show', ['slug' => 'no-such-report']))
            ->assertNotFound();
    }

    /**
     * গণনার পর্দাটা তার নিজের সীমা বলে দেয়।
     *
     * ⚠️ এটা প্রসাধন নয়: বাকি তিনটায় প্রতিটা সারি খোলা যায়, তাই মানুষ
     * ক্লিক করতে অভ্যস্ত। এখানে কিছু না হলে তাঁরা ভাববেন পাতাটা ভাঙা।
     */
    public function test_the_counting_report_says_it_cannot_be_clicked(): void
    {
        $this->actingAs($this->manager)
            ->get(route('approval.report.show', ['slug' => 'by-user']))
            ->assertOk()
            ->assertSee(__('approval::message.report_counts_only'));

        // আর যেখানে সারি খোলা যায়, সেখানে ওই লাইনটা নেই
        $this->actingAs($this->manager)
            ->get(route('approval.report.show', ['slug' => 'pending']))
            ->assertOk()
            ->assertDontSee(__('approval::message.report_counts_only'));
    }
}
