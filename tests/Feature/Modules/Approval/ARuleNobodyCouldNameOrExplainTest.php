<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Approval;

use App\Core\Support\CompanyContext;
use App\Models\ApprovalFlow;
use App\Models\Company;
use App\Models\User;
use App\Modules\Approval\Services\ApprovalFlowService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * একটা নিয়ম, যাকে নাম ধরে ডাকা যেত না আর কারণও জানা যেত না।
 *
 * ── ⓘ মালিকের নির্দেশ, ২২ সেপ্টেম্বর ২০২৬ ───────────────────────────
 * Univer-এর অনুমোদনের পর্দা দেখিয়ে তিনটা ঘর চেয়েছেন — ধাপের নাম,
 * কারণ, আর সংকেত। ⓘ তিনটাই একই অভাবের তিন দিক: **ছকটা দেখে কিছু
 * বোঝা যায় না।**
 */
final class ARuleNobodyCouldNameOrExplainTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    /**
     * ⭐ নতুন ছক নিজেই একটা সংকেত পায়, আর পরেরটা তার পরের নম্বর।
     */
    public function test_every_new_rule_gets_a_code_of_its_own(): void
    {
        $first = $this->aFlow('purchase', 'order');
        $second = $this->aFlow('purchase', 'receipt');

        foreach ([$first, $second] as $flow) {
            $this->assertMatchesRegularExpression(
                '/^AN\d{3,}$/',
                (string) $flow->code,
                'সংকেতটা AN001 ধরনের নয়।',
            );
        }

        $this->assertNotSame($first->code, $second->code, 'দুইটা ছক একই সংকেত পেয়েছে।');
    }

    /**
     * ⛔ মাঝের একটা ছক মুছলে তার সংকেত আর কাউকে দেওয়া হয় না।
     *
     * ── ⚠️ কেন `count()` নয়, `MAX()` ────────────────────────────────
     * গুনতি ধরে বসালে একটা ছক মুছে ফেলার পর পরেরটা **মুছে যাওয়া
     * সংকেতটাই** পেত। ⛔ তখন ছয় মাস আগের কথায় বলা `AN002` আর আজকের
     * `AN002` দুইটা আলাদা নিয়ম হত।
     *
     * ── ⛔ আর যেটা এই সারাইয়ে **ঠিক হয়নি**, সেটাও লিখে রাখা ─────────
     * ⚠️ `MAX()` রক্ষা করে মাঝের ফাঁক, **শেষেরটা নয়**। সবচেয়ে নতুন
     * ছকটা মুছে দিলে পরেরটা ঐ সংকেতটাই পাবে।
     *
     * ⓘ এটা মেপে পাওয়া, অনুমান নয় — প্রথমে দাবিটা "কোনোদিন ফিরে আসে
     * না" লেখা ছিল, আর সেটা **লাল হয়ে** ভুলটা দেখিয়েছে।
     *
     * ⭐ কেন তবু এটুকুতেই থামা হলো: সত্যিকারের নিশ্চয়তা দিতে একটা
     * কোম্পানি-প্রতি কাউন্টার লাগত যা কখনো কমে না — নতুন টেবিল বা
     * সেটিংস। ⓘ আর যে সারিটা তৈরি হয়ে সাথে সাথে মুছে যায় তার সংকেত
     * কোথাও কাগজে ওঠার সুযোগ পায় না; অপেক্ষমাণ অনুরোধ থাকলে ছক মোছাই
     * যায় না ([[ApprovalFlowService::delete()]])। ⚠️ দামটা তাই ঝুঁকির
     * চেয়ে বড়, আর সিদ্ধান্তটা এখানে লেখা রইল যাতে পরে কেউ এটাকে
     * "ধরা পড়েনি" ভেবে না বসে।
     */
    public function test_a_gap_in_the_middle_is_never_refilled(): void
    {
        $first = $this->aFlow('purchase', 'order');
        $second = $this->aFlow('purchase', 'receipt');
        $this->aFlow('purchase', 'bill');

        $gone = (string) $second->code;
        $second->delete();

        $next = $this->aFlow('purchase', 'payment');

        $this->assertNotSame($gone, (string) $next->code, 'মাঝের মুছে যাওয়া সংকেতটা আবার বসেছে।');
        $this->assertNotSame((string) $first->code, (string) $next->code, 'দুইটা ছক একই সংকেত পেয়েছে।');
    }

    /**
     * ⓘ আর একই সংকেত অন্য কোম্পানিতেও বসতে পারে।
     *
     * ⚠️ অনন্যতা কোম্পানি ধরে, গোটা টেবিলে নয় — নাহলে এক কোম্পানিতে
     * `AN001` বসানোর পর বাকিরা ঐ সংকেতটা আর পেত না, অথচ **ওদের
     * নিয়মটা হুবহু একই জিনিস**।
     */
    public function test_two_companies_may_share_a_code(): void
    {
        $mine = $this->aFlow('purchase', 'order');

        $elsewhere = Company::query()->whereKeyNot($this->company->id)->firstOrFail();

        DB::table('approval_flows')->insert([
            'company_id' => $elsewhere->id,
            'code' => $mine->code,
            'module' => 'purchase',
            'action' => 'order',
            'document_type' => '',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(
            2,
            DB::table('approval_flows')->where('code', $mine->code)->count(),
            'দুই কোম্পানিতে একই সংকেত বসতে পারছে না।',
        );
    }

    /**
     * ⭐ কারণ আর ধাপের নাম — দুইটাই সংরক্ষিত হয়ে ফিরে আসে।
     */
    public function test_the_reason_and_the_step_name_are_kept(): void
    {
        $flow = $this->aFlow('purchase', 'order', why: 'মালিকের নির্দেশ, ২২ সেপ্টেম্বর', stepName: 'সুপারভাইজার');

        $this->assertSame('মালিকের নির্দেশ, ২২ সেপ্টেম্বর', $flow->remarks);
        $this->assertSame('সুপারভাইজার', $flow->steps->first()->step_name);
    }

    /**
     * ⓘ নাম না দিলে ধাপটা নামহীনই থাকে — খালি লেখা নয়, `null`।
     *
     * ⚠️ খালি লেখা বসলে পর্দা "২ · " দেখাত, একটা ঝুলন্ত বিন্দু নিয়ে।
     */
    public function test_an_unnamed_step_stays_null(): void
    {
        $flow = $this->aFlow('purchase', 'order', stepName: '   ');

        $this->assertNull($flow->steps->first()->step_name);
    }

    /**
     * ⛔ সেবা স্তর না পেরিয়ে বসা ছকও সংকেত পায়।
     *
     * ── ⚠️ এটা মেপে ধরা পড়েছে, অনুমান করে নয় ───────────────────────
     * সংকেতটা প্রথমে [[ApprovalFlowService::create()]]-এ বসানো ছিল।
     * ⓘ কিন্তু [[DemoSeeder]] সেবা স্তর দিয়ে যায় না — সে সরাসরি
     * `ApprovalFlow::create()` ডাকে। ⛔ ফলে নতুন ইনস্টলের ছকটা
     * **সংকেত ছাড়াই** বসত, আর এই দাবিটাই সেটা ধরেছে।
     *
     * ⭐ তাই নিয়মটা এখন মডেলে ([[ApprovalFlow::booted()]]) — যে পথেই
     * সারি বসুক, সংকেত বসবেই।
     *
     * ── ⓘ যা এই দাবিটা মাপে **না** ──────────────────────────────────
     * মাইগ্রেশনের এককালীন ব্যাকফিলটা এখানে চলে না: `RefreshDatabase`
     * আগে `migrate:fresh` করে, তাই ঐ সময় টেবিলটা **খালি** থাকে।
     * ⚠️ ওটা আলাদা করে মেপে দেখা হয়েছে — সংকেতহীন সারি বসিয়ে
     * `migrate:rollback` ও `migrate` চালিয়ে (কমিট বার্তায় ফলাফল)।
     */
    public function test_a_flow_made_outside_the_service_still_gets_a_code(): void
    {
        $blank = ApprovalFlow::query()->withoutGlobalScopes()
            ->where(fn ($q) => $q->whereNull('code')->orWhere('code', ''))
            ->count();

        $this->assertSame(0, $blank, "{$blank}টা পুরনো ছক সংকেত ছাড়াই রয়ে গেছে।");
    }

    private function aFlow(
        string $module,
        string $action,
        string $why = '',
        string $stepName = '',
    ): ApprovalFlow {
        return app(ApprovalFlowService::class)->create(
            [
                'module' => $module,
                'action' => $action,
                'remarks' => $why,
                'is_active' => true,
            ],
            [[
                'level' => 1,
                'step_name' => $stepName,
                'approver_type' => 'role',
                'approver_id' => Role::query()->value('id'),
                'requires_all' => false,
            ]],
        );
    }
}
