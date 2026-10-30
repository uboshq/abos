<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Support\CompanyContext;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ⛔ এক প্রক্রিয়ায় বসানো ছক অন্য প্রক্রিয়ার ইঞ্জিন দেখত না — চালান সই ছাড়াই চলে যেত (fe-র পর্যালোচনা, ২১ অক্টোবর ২০২৬)।
 *
 * ⓘ ইঞ্জিন ছকগুলো একবার তুলে জমিয়ে রাখে। ⚠️ আগে জমানোটা কখনো পুরনো হত না: কিউ-কর্মী বা লম্বা অনুরোধ প্রথম প্রশ্নের
 * ছক নিয়েই চলত। ⭐ এখন ছক বদলের পালা অ্যাপের ডিফল্ট ক্যাশে ([[ApprovalEngine::FLOWS_VERSION_KEY]]) — প্রতিটা প্রক্রিয়া
 * একই পালা দেখে। এখানে দুইটা আলাদা ইঞ্জিন দুই প্রক্রিয়া; তাদের মাঝে ভাগ করা কিছু নেই, কেবল ডাটাবেস আর ক্যাশ।
 */
final class AFlowSetInOneProcessIsSeenByEveryEngineTest extends TestCase
{
    use RefreshDatabase;

    private const TYPE = 'DeliveryChallan';

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->assertSame(0, ApprovalFlow::query()->where('module', 'sales')->where('action', 'challan')->count(),
            'দৃশ্যটাই ভুল — ডেমোতে আগে থেকেই চালানের ছক আছে।');
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    public function test_a_flow_set_after_both_engines_answered_is_seen_by_both_and_a_switch_off_at_once(): void
    {
        $first = new ApprovalEngine;
        $second = new ApprovalEngine;

        // ⓘ দুইজনই আগে উত্তর দেয় — দুইজনের জমানো তালিকা ভরা
        $this->assertFalse($first->requires('sales', 'challan', '500', self::TYPE));
        $this->assertFalse($second->requires('sales', 'challan', '500', self::TYPE));

        /*
         * ⭐ ফিক্সচার — জমানোটা সত্যিই জমানো: পালা না বদলে (মডেল এড়িয়ে, flowsChanged() ছাড়া) বসানো ছক প্রথমজন দেখে না।
         * ⓘ এটা ছাড়া নিচের "দেখে" কথাটা প্রমাণ করত না যে পালাই দেখাল — ইঞ্জিন হয়তো প্রতিবার তুলত।
         */
        $sneaked = DB::table('approval_flows')->insertGetId([
            'company_id' => $this->company->id, 'code' => 'ANX01', 'module' => 'sales', 'action' => 'challan',
            'document_type' => '', 'is_active' => true, 'public_id' => (string) Str::uuid7(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertFalse($first->requires('sales', 'challan', '500', self::TYPE),
            'দৃশ্যটাই ভুল — ইঞ্জিন ছক জমায়ই না, তাই পালার দাবি কিছু মাপে না।');
        DB::table('approval_flows')->where('id', $sneaked)->delete();
        ApprovalEngine::flowsChanged();
        $this->assertFalse($first->requires('sales', 'challan', '500', self::TYPE));
        $this->assertFalse($second->requires('sales', 'challan', '500', self::TYPE));

        $before = Cache::get(ApprovalEngine::FLOWS_VERSION_KEY);

        // ⛔ অন্য প্রক্রিয়ায় মডেল দিয়ে ছক বসানো — ওয়েবের নিয়ন্ত্রণ-পাতার মতো
        $flow = ApprovalFlow::query()->create([
            'company_id' => $this->company->id, 'module' => 'sales', 'action' => 'challan',
            'document_type' => '', 'threshold_amount' => null, 'is_active' => true,
        ]);
        ApprovalFlowStep::query()->create([
            'approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => ApprovalFlowStep::BY_USER,
            'approver_id' => User::query()->where('email', 'owner@abos.test')->value('id'),
        ]);

        $this->assertNotSame($before, Cache::get(ApprovalEngine::FLOWS_VERSION_KEY), '⛔ ছক বসল, অথচ ভাগ করা পালা বদলাল না।');
        $this->assertTrue($second->requires('sales', 'challan', '500', self::TYPE), '⛔ দ্বিতীয় প্রক্রিয়া নতুন ছক দেখে না — চালান সই ছাড়াই যেত।');
        $this->assertTrue($first->requires('sales', 'challan', '500', self::TYPE), '⛔ প্রথম প্রক্রিয়া পুরনো তালিকা নিয়েই চলে।');

        // ⛔ ছক বন্ধ (is_active উল্টানো) — প্রথমজন সাথে সাথেই দেখে
        $flow->forceFill(['is_active' => false])->save();
        $this->assertFalse($first->requires('sales', 'challan', '500', self::TYPE), '⛔ বন্ধ করা ছক এখনো সই চায়।');

        $flow->forceFill(['is_active' => true])->save();
        $this->assertTrue($first->requires('sales', 'challan', '500', self::TYPE), '⛔ আবার চালু করা ছক দেখা যায় না।');
    }

    /** ⭐ মডেল এড়িয়ে লেখা পথ (যেমন ডেমো-সিডারের DB::table মোছা) লেখার পরে পালা বদলায় — জমানো তালিকা থেকে মোছা ছক যায় */
    public function test_a_writer_that_skips_the_models_turns_the_version_too(): void
    {
        $flow = ApprovalFlow::query()->create([
            'company_id' => $this->company->id, 'module' => 'sales', 'action' => 'challan',
            'document_type' => '', 'threshold_amount' => null, 'is_active' => true,
        ]);
        ApprovalFlowStep::query()->create([
            'approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => ApprovalFlowStep::BY_USER,
            'approver_id' => User::query()->where('email', 'owner@abos.test')->value('id'),
        ]);

        $engine = new ApprovalEngine;
        $this->assertTrue($engine->requires('sales', 'challan', '500', self::TYPE), 'দৃশ্যটাই ভুল — ছকটা চলে না।');

        DB::table('approval_flow_steps')->where('approval_flow_id', $flow->id)->delete();
        DB::table('approval_flows')->where('id', $flow->id)->delete();
        ApprovalEngine::flowsChanged();

        $this->assertFalse($engine->requires('sales', 'challan', '500', self::TYPE), '⛔ মুছে ফেলা ছক জমানো তালিকায় রয়ে গেল।');
    }
}
