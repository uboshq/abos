<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * অনুমোদনের ছকের ফর্মে অন্য কোম্পানির মানুষ নেই।
 *
 * ── কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * `ApprovalFlowController` ব্যবহারকারীদের তালিকা তুলত সরল
 * `User::query()` দিয়ে, আর `User`-এ কোনো global scope নেই — একজন মানুষ
 * একাধিক কোম্পানিতে থাকতে পারেন, তাই সম্পর্কটা pivot-এ। ফল: **ছকের
 * ফর্মে গোটা ইনস্টলেশনের সবার নাম।**
 *
 * ⛔ আর ক্ষতিটা নাম দেখার চেয়ে বড়: ওই তালিকা থেকে অন্য কোম্পানির কাউকে
 * অনুমোদনের ছকে **বসিয়েও দেওয়া যেত**, আর তখন তাঁর কাছে এই কোম্পানির
 * কাগজ সইয়ের জন্য যেত।
 *
 * ── কেন চোখে দেখা যথেষ্ট নয় ─────────────────────────────────────────
 * ⚠️ dev ডাটাবেসে সাধারণত একটাই কোম্পানি থাকে, তাই পর্দায় সবকিছু ঠিক
 * দেখায়। **দুইটা কোম্পানি না বানিয়ে এই ফাঁক দেখা যায় না** — আর
 * বহু-টেন্যান্ট পণ্যে বিচ্ছিন্নতা সুবিধা নয়, শর্ত।
 */
class OneCompanyDoesNotSeeAnothersPeopleTest extends TestCase
{
    use RefreshDatabase;

    private Company $ours;

    private Company $theirs;

    private User $ourManager;

    private User $theirManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ours = Company::create(['code' => 'US', 'name_en' => 'Our Depot']);
        $this->theirs = Company::create(['code' => 'THEM', 'name_en' => 'Another Depot']);

        // লাইভে `PermissionSyncer` বসায়; ফেলনা ডাটাবেসে সে চলে না
        Permission::findOrCreate('approval.flow.manage', 'web');

        $this->ourManager = $this->personOf($this->ours, 'আমাদের ম্যানেজার');
        $this->theirManager = $this->personOf($this->theirs, 'ওদের ম্যানেজার');

        CompanyContext::set($this->ours->id);
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    private function personOf(Company $company, string $name): User
    {
        $user = User::factory()->create(['name' => $name, 'current_company_id' => $company->id]);
        $user->companies()->attach($company->id, ['is_active' => true]);
        $user->givePermissionTo('approval.flow.manage');

        return $user;
    }

    public function test_the_flow_form_offers_only_our_own_people(): void
    {
        $page = $this->actingAs($this->ourManager)->get(route('approval.flow.create'));

        $page->assertOk();

        $names = $page->viewData('users')->pluck('name')->all();

        $this->assertContains('আমাদের ম্যানেজার', $names);
        $this->assertNotContains('ওদের ম্যানেজার', $names, 'অন্য কোম্পানির মানুষ ছকের ফর্মে দেখা যাচ্ছে');
    }

    public function test_the_flow_list_names_only_our_own_people(): void
    {
        $names = $this->actingAs($this->ourManager)
            ->get(route('approval.flow.index'))
            ->viewData('names');

        $this->assertArrayHasKey($this->ourManager->id, $names['user']);
        $this->assertArrayNotHasKey($this->theirManager->id, $names['user']);
    }

    /**
     * আর উল্টো দিক থেকেও — নাহলে পরীক্ষাটা কেবল একদিক দেখত।
     *
     * ⚠️ একদিক দেখা গার্ড অর্ধেক: ছাঁকনিটা ভুল করে **চলতি কোম্পানির
     * বদলে ব্যবহারকারীর নিজের কোম্পানি** ধরলেও প্রথম পরীক্ষাটা সবুজ
     * থাকত।
     */
    public function test_and_the_other_company_sees_only_its_own(): void
    {
        CompanyContext::set($this->theirs->id);

        $names = $this->actingAs($this->theirManager)
            ->get(route('approval.flow.index'))
            ->viewData('names');

        $this->assertArrayHasKey($this->theirManager->id, $names['user']);
        $this->assertArrayNotHasKey($this->ourManager->id, $names['user']);
    }

    /**
     * দুই কোম্পানিতেই থাকা একজন — দুই তালিকাতেই থাকেন।
     *
     * ⓘ এটা ব্যতিক্রম নয়, নকশা: হিসাবরক্ষক প্রায়ই দুইটা প্রতিষ্ঠান
     * একসাথে দেখেন। ছাঁকনিটা যদি "একজন মানুষ একটাই কোম্পানির" ধরে
     * নিত, তাহলে তাঁকে কোনো ছকেই বসানো যেত না।
     */
    public function test_someone_in_both_companies_shows_in_both(): void
    {
        $shared = User::factory()->create(['name' => 'দুই দিকের হিসাবরক্ষক']);
        $shared->companies()->attach($this->ours->id, ['is_active' => true]);
        $shared->companies()->attach($this->theirs->id, ['is_active' => true]);

        $here = $this->actingAs($this->ourManager)
            ->get(route('approval.flow.index'))->viewData('names');

        CompanyContext::set($this->theirs->id);

        $there = $this->actingAs($this->theirManager)
            ->get(route('approval.flow.index'))->viewData('names');

        $this->assertArrayHasKey($shared->id, $here['user']);
        $this->assertArrayHasKey($shared->id, $there['user']);
    }
}
