<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Approval;

use App\Core\Engines\Approval\ApprovalSla;
use App\Core\Support\CompanyContext;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ⛔ কেবল "উপরে পাঠাও (ঘণ্টা)" ভরা ধাপও সময় গোনে — Approval লাইন-ধরে নিরীক্ষা, ১৪ অক্টোবর ২০২৬ (fe-র সিদ্ধান্ত খ;
 * [[ApprovalFlowStep::clockHours()]])।
 *
 * ⓘ ঘরটা ফর্মে ছিল, সেভও হত, কিন্তু কোথাও খাটত না — দেরি আর উপরে পাঠানো কেবল SLA-র ঘণ্টায়। তাই শুধু এই ঘর ভরা ধাপ কোনোদিন উপরে
 * যেত না, আর মালিক ভাবতেন ব্যবস্থা চালু। এখন SLA খালি হলে এই ঘণ্টাই ঘড়ি; দুটোই থাকলে SLA — পর্দায় লেখা।
 */
final class AnEscalationClockAloneStillEscalatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_step_with_only_escalation_hours_gets_a_due_time_and_sla_wins_when_both_are_set(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $from = Carbon::parse('2026-10-14 10:00');
        $sla = app(ApprovalSla::class);

        $alone = (new ApprovalFlowStep)->forceFill(['level' => 1, 'escalate_hours' => 24]);
        $this->assertEquals($from->copy()->addHours(24), $sla->dueFor($alone, $from), '⛔ "উপরে পাঠাও" ভরা ধাপের কোনো ঘড়ি নেই — কোনোদিন উপরে যায় না');

        $both = (new ApprovalFlowStep)->forceFill(['level' => 1, 'sla_hours' => 8, 'escalate_hours' => 48]);
        $this->assertEquals($from->copy()->addHours(8), $sla->dueFor($both, $from), 'দুটোই থাকলে SLA-ই খাটার কথা');

        $this->assertNull($sla->dueFor((new ApprovalFlowStep)->forceFill(['level' => 1]), $from), 'ঘড়ি ছাড়া ধাপে সময়সীমা বসল');

        // ⓘ পর্দায় নিয়মটা লেখা
        $this->get(route('approval.flow.create'))->assertOk()->assertSee(__('approval::field.escalate_hours_hint'));
    }
}
