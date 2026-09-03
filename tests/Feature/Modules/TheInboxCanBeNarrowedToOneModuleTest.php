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
use Tests\TestCase;

/**
 * ইনবক্সে মডিউল ধরে ছাঁকনি — চেকলিস্ট §২.২।
 *
 * ── কেন এটা লাগে ────────────────────────────────────────────────────
 * মালিকের চাওয়াটা ছিল *"একটাই জায়গা থেকে সব দেখব"*, আর সেটা আজ পূরণ।
 * কিন্তু "সব একসাথে" আর "শুধু ক্রয়েরগুলো" দুইটা আলাদা প্রশ্ন: মাসের
 * শেষে হিসাবরক্ষক কেবল হিসাবেরগুলো দেখতে চান, আর তখন বিশটা সারির
 * ভেতর থেকে সাতটা খুঁজে নেওয়াটাই আসল কাজ হয়ে দাঁড়ায়।
 *
 * ── সংখ্যাটা কেন পরীক্ষা করা হয় ─────────────────────────────────────
 * চিপের গায়ের সংখ্যা আর তালিকার সারি — দুইটা দুই জায়গা থেকে এলে একদিন
 * আলাদা হয়ে যাবে, আর তখন কোনটা সত্যি তা বলার উপায় থাকবে না। এখানে
 * দুইটাই একই তালিকা থেকে আসে, আর এই পরীক্ষা সেটাই ধরে রাখে।
 */
class TheInboxCanBeNarrowedToOneModuleTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['code' => 'FL', 'name_en' => 'Filter Co']);
        CompanyContext::set($this->company->id);

        $this->manager = User::factory()->create(['current_company_id' => $this->company->id]);
        $this->manager->companies()->attach($this->company->id);

        /*
         * অনুমতির সারিগুলো এখানেই বানানো — আর কেন, সেটা লিখে রাখা দরকার।
         *
         * ⚠️ ── এটা পড়ে ভুল সিদ্ধান্ত নেওয়া সহজ ──────────────────────
         * সারিটা লাইভে **থাকে**: `approval.view` ও `approval.decide`
         * দুইটাই `Approval/module.php`-এ ঘোষিত, আর `PermissionSyncer`
         * ঘোষণা থেকেই টেবিলে বসায় — নতুন ইনস্টলে `FirstRun` থেকে, আর
         * প্রতিটা deploy-এ `SyncPermissions` কমান্ড থেকে।
         *
         * ফাঁকটা কেবল **ফেলনা টেস্ট-ডাটাবেসে**: `RefreshDatabase` শুধু
         * মাইগ্রেশন চালায়, syncer চালায় না। তাই এখানে হাতে।
         *
         * ⓘ **`ApprovalTest` কেন এই লাইনটা ছাড়াই চলে:** সে
         * `DemoSeeder` চালায়, আর সিডারটা ভেতরে `PermissionSyncer::sync()`
         * ডাকে। এখানে ডেমো ডেটা চাওয়া হয়নি — এলে "চারটা অপেক্ষমাণ"
         * গোনাটা এই টেস্টের বসানো সারির নয়, ডেমোরও হত, আর সংখ্যাগুলো
         * একদিন নিঃশব্দে বদলে যেত।
         */
        foreach (['approval.view', 'approval.decide'] as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $this->manager->givePermissionTo(['approval.view', 'approval.decide']);

        foreach ([['purchase', 'order'], ['hr', 'payroll']] as [$module, $action]) {
            $flow = ApprovalFlow::create(['module' => $module, 'action' => $action]);
            ApprovalFlowStep::create([
                'approval_flow_id' => $flow->id,
                'level' => 1,
                'approver_type' => ApprovalFlowStep::BY_USER,
                'approver_id' => $this->manager->id,
            ]);
        }

        // ক্রয়ে তিনটা, বেতনে একটা — সংখ্যাগুলো আলাদা, নাহলে ছাঁকনি
        // কাজ করছে কি না বোঝাই যেত না
        $asker = User::factory()->create(['current_company_id' => $this->company->id]);

        foreach ([['purchase', 'order'], ['purchase', 'order'], ['purchase', 'order'], ['hr', 'payroll']] as $i => [$m, $a]) {
            Approval::create([
                'company_id' => $this->company->id,
                'approvable_type' => Branch::class,
                'approvable_id' => Branch::create([
                    'company_id' => $this->company->id,
                    'code' => 'B'.$i,
                    'name_en' => 'Branch '.$i,
                ])->id,
                'module' => $m,
                'action' => $a,
                'status' => Approval::PENDING,
                'current_level' => 1,
                'requested_by' => $asker->id,
                'requested_at' => now(),
            ]);
        }
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    public function test_without_a_filter_every_module_is_there(): void
    {
        $page = $this->actingAs($this->manager)->get(route('approval.inbox.index'));

        $page->assertOk();
        $this->assertCount(4, $page->viewData('approvals'));
        $this->assertSame(4, $page->viewData('total'));
        $this->assertSame('', $page->viewData('selected'));
    }

    public function test_one_module_narrows_the_list_but_not_the_counts(): void
    {
        $page = $this->actingAs($this->manager)
            ->get(route('approval.inbox.index', ['module' => 'purchase']));

        $page->assertOk();
        $this->assertCount(3, $page->viewData('approvals'));
        $this->assertSame('purchase', $page->viewData('selected'));

        /*
         * ⚠️ সংখ্যাগুলো পুরো তালিকার, ছাঁকা তালিকার নয়।
         *
         * ছাঁকার পর গুনলে "বেতন ০" দেখাত, আর মানুষ ভাবতেন ওখানে কিছু
         * নেই — অথচ একটা অপেক্ষা করছে।
         */
        $modules = $page->viewData('modules');
        $this->assertSame(3, $modules['purchase']['count']);
        $this->assertSame(1, $modules['hr']['count']);
        $this->assertSame(4, $page->viewData('total'));
    }

    public function test_a_module_with_nothing_waiting_is_not_offered(): void
    {
        $modules = $this->actingAs($this->manager)
            ->get(route('approval.inbox.index'))
            ->viewData('modules');

        // ঘোষিত, কিন্তু এই মানুষটার কাছে কিছুই অপেক্ষায় নেই
        $this->assertArrayNotHasKey('sales', $modules);
        $this->assertArrayNotHasKey('inventory', $modules);
    }

    /**
     * পুরনো একটা লিংক ধরে আসা — তালিকা খালি, আর সেটাই সৎ।
     *
     * ছাঁকনিটা চুপচাপ ফেলে দিয়ে "সব" দেখালে মানুষ ভাবতেন ছাঁকনি কাজ
     * করেনি, আর ভুল সংখ্যাটা নিয়ে কাজ করতেন।
     */
    public function test_a_stale_link_shows_an_empty_list_not_everything(): void
    {
        $page = $this->actingAs($this->manager)
            ->get(route('approval.inbox.index', ['module' => 'restaurant']));

        $page->assertOk();
        $this->assertCount(0, $page->viewData('approvals'));
        $this->assertSame('restaurant', $page->viewData('selected'));
    }
}
