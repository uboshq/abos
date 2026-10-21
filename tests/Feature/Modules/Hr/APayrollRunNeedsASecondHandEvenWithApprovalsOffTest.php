<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Approval\HeldForApproval;
use App\Core\Module\ModuleDefinition;
use App\Core\Security\FieldSecurity;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Http\Controllers\Api\AuthController;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Approval\Services\MoneyFlowDefaults;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\SalaryHead;
use App\Modules\Hr\Models\SalaryStructure;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\PayrollService;
use App\Modules\Hr\Services\SalaryHeadService;
use App\Modules\Hr\Services\SalaryStructureService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\SignsMoneyOff;
use Tests\SignsTheSecondHand;
use Tests\TestCase;

/**
 * ⛔ মালিকের একমাত্র ব্যতিক্রম — "সই বন্ধ মানে বন্ধ", কেবল বেতনের রান আর বেতনের কাঠামো (আর বেতনের ঠিকানা, আলাদা ফাইলে) ছাড়া
 * (মালিক, ২১ অক্টোবর ২০২৬; a6)।
 *
 * ⓘ UB-র মতো কোম্পানি — সব ছক বন্ধ। আগে সেখানে যিনি রান বানাতেন তিনিই "নিশ্চিত" চেপে গোটা মাসের বেতন খাতায় বসাতেন, আর যিনি
 * কাঠামো বসাতেন তাঁর অঙ্কই সাথে সাথে বসত — একজনের হাতে (SOX/ITGC maker-checker নেই)।
 * ⭐ এখন ([[ApprovalRules::secondHand()]], [[ApprovalEngine::flows()]]): ছক না থাকলে যন্ত্রের নিজের এক ধাপ "দ্বিতীয় সই" —
 *  · রান: যিনি "নিশ্চিত" চাপলেন তিনি সই দিতে পারেন না, রান খসড়া থাকে; `hr.payroll.manage` + `hr.salary.view` থাকা আরেকজন "সই দিন"
 *    চাপলে তবে "নিশ্চিত" খাতায় বসায়;
 *  · কাঠামো: `hr.salary.manage` + `hr.salary.view` থাকা আরেকজন;
 *  · চাবি ছাড়া কেউ সইকারী নন, ইনবক্সেও দেখেন না; ফোন এই কাগজগুলো দেখায়ই না, সইও নেয় না (চুক্তি §৪);
 *  · মালিক নিজের চাওয়ায় নিজে সই দিলে পার হয়, অডিটে `owner_self_signed`;
 *  · [[MoneyFlowDefaults]] আগের মতোই কিছু বসায় না; ছক চালু থাকা কোম্পানিতে সেই ছকই খাটে।
 */
final class APayrollRunNeedsASecondHandEvenWithApprovalsOffTest extends TestCase
{
    use RefreshDatabase;
    use SignsMoneyOff;
    use SignsTheSecondHand;

    private const BASIC = '37219.40';

    private const RAISED = '45678.25';

    private Company $company;

    private User $owner;

    private Employee $employee;

    private SalaryHead $basic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        app(StandardChart::class)->install();
        app(SalaryHeadService::class)->installDefaults();
        FieldSecurity::forget();
        $this->travelTo('2026-09-05 10:00:00');

        $this->basic = SalaryHead::query()->where('code', 'BASIC')->firstOrFail();
        $this->employee = app(EmployeeService::class)->create([
            'code' => 'EMP-2H', 'name_en' => 'Two Hands', 'joining_date' => '2026-01-15', 'payment_method' => 'cash',
        ]);
        app(SalaryStructureService::class)->set($this->employee, $this->basic, '2026-01-15', self::BASIC);

        $this->switchEveryApprovalOff();
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    // ── ছক বসে না ──────────────────────────────────────────────────────

    public function test_nothing_is_seeded_where_every_approval_is_off(): void
    {
        $report = $this->moneyFlowsFor($this->company);

        $this->assertSame([], $report['created'], '⛔ সব সই বন্ধ, তবু ছক বসল: '.implode(', ', $report['created']));
        $this->assertContains('hr.payroll', $report['switched_off']);
        $this->assertSame(0, ApprovalFlow::query()->where('module', 'hr')->count(),
            '⛔ দ্বিতীয় সইয়ের জন্য HR-এর ছক ডাটাবেসে বসল — নিয়মটা ছক বসিয়ে নয়, যন্ত্রের নিজের।');
        $this->assertSame(0, ApprovalFlow::query()->where('is_active', true)->count(), '⛔ বন্ধ কোম্পানিতে চালু ছক।');
    }

    // ── রান ────────────────────────────────────────────────────────────

    public function test_the_maker_cannot_post_the_run_and_a_second_key_holder_signs_it_on_the_web(): void
    {
        $maker = $this->payrollClerk();
        $second = $this->payrollClerk();
        $run = app(PayrollService::class)->build('2026-08-01');

        // ── যিনি বানালেন তিনি "নিশ্চিত" চাপলেন — খাতায় কিছু নয়, সইয়ের অপেক্ষায়
        $this->as($maker)->from(route('hr.payroll.show', $run))->post(route('hr.payroll.confirm', $run))
            ->assertRedirect(route('hr.payroll.show', $run))->assertSessionHasErrors('status');

        $this->assertSame(DocumentStatus::DRAFT, (string) $run->fresh()->status, '⛔ সব সই বন্ধ, আর একজনের চাপেই রান খাতায় বসল।');
        $approval = $this->pendingFor($run);
        $this->assertSame((int) $maker->id, (int) $approval->requested_by);
        $this->assertTrue(app(ApprovalEngine::class)->isSecondHand($approval), '⛔ অনুরোধটা যন্ত্রের "দ্বিতীয় সই" ছকে নয়।');
        $this->assertNull($approval->amount, '⛔ রানের নিট অনুরোধে খোলা।');

        // ── যিনি চাইলেন তিনি সই দিতে পারেন না — ইঞ্জিনে, আর ওয়েবের দরজায়
        $this->assertFalse(app(ApprovalEngine::class)->canDecide($approval->fresh(), $maker->fresh()), '⛔ বানানেওয়ালা নিজের রানে সই দিতে পারেন।');
        $this->as($maker)->post(route('approval.inbox.approve', $approval->id));
        $this->assertSame(Approval::PENDING, (string) $approval->fresh()->status, '⛔ বানানেওয়ালার নিজের সই পড়ল।');
        $this->as($maker)->post(route('hr.payroll.confirm', $run));
        $this->assertSame(DocumentStatus::DRAFT, (string) $run->fresh()->status, '⛔ সই ছাড়া দ্বিতীয় চাপে রান খাতায় বসল।');

        // ── আরেকজন — ইনবক্সে দেখেন, "সই দিন" চাপেন
        $this->as($second)->get(route('approval.inbox.index'))->assertOk()->assertSee((string) $run->document_no, false);
        $this->as($second)->post(route('approval.inbox.approve', $approval->id), ['remarks' => 'দেখে সই'])->assertRedirect();
        $this->assertSame(Approval::APPROVED, (string) $approval->fresh()->status, '⛔ দ্বিতীয় চাবিধারীর সই পড়ল না।');

        // ── এবার "নিশ্চিত" খাতায় বসায়
        $this->as($maker)->post(route('hr.payroll.confirm', $run))->assertSessionHasNoErrors();
        $this->assertSame(DocumentStatus::CONFIRMED, (string) $run->fresh()->status, '⛔ সই পেয়েও রান নিশ্চিত হলো না।');
        $this->assertFalse(AuditTrail::query()->forRecord(PayrollRun::class, (int) $run->id)
            ->where('action', SalaryStructureService::OWNER_SELF_SIGNED)->exists(), '⛔ আরেকজনের সইয়ে মালিকের নিজে-সইয়ের চিহ্ন।');
    }

    public function test_a_signer_without_every_key_is_no_signer_and_never_sees_it(): void
    {
        $run = app(PayrollService::class)->build('2026-08-01');
        $this->askOnce(fn () => app(PayrollService::class)->confirm($run->fresh()));
        $approval = $this->pendingFor($run);

        foreach ([
            'no payroll key' => ['approval.decide', 'hr.payroll.view', 'hr.salary.view'],
            'no salary key' => ['approval.decide', 'hr.payroll.view', 'hr.payroll.manage'],
            'no signing door' => ['hr.payroll.view', 'hr.payroll.manage', 'hr.salary.view'],
        ] as $why => $keys) {
            $user = $this->secondHandIn((int) $this->company->id, ...$keys);
            $this->as($user);

            $this->assertFalse(app(ApprovalEngine::class)->canDecide($approval->fresh(), $user), "⛔ [{$why}] তবু সইকারী।");
            $this->assertFalse(app(ApprovalEngine::class)->pendingQueryFor($user)->whereKey($approval->id)->exists(),
                "⛔ [{$why}] তবু ইনবক্সে — খুললে বোতাম নেই।");
        }
    }

    public function test_the_phone_neither_shows_nor_signs_it(): void
    {
        $second = $this->payrollClerk();
        $run = app(PayrollService::class)->build('2026-08-01');
        $this->askOnce(fn () => app(PayrollService::class)->confirm($run->fresh()));
        $approval = $this->pendingFor($run);

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($second, [AuthController::APP]);

        $ids = collect($this->getJson('/api/v1/approvals/pending')->assertOk()->json('rows') ?? [])->pluck('id')->all();
        $this->assertNotContains((string) $approval->public_id, $ids, '⛔ বেতনের রান ফোনের ইনবক্সে (চুক্তি §৪)।');

        $status = $this->postJson('/api/v1/approvals/'.$approval->public_id.'/approve')->status();
        $this->assertNotSame(200, $status, '⛔ ফোন থেকে বেতনের রানে সই পড়ল।');
        $this->assertSame(Approval::PENDING, (string) $approval->fresh()->status, '⛔ ফোনের সইয়ে অনুরোধ এগোল।');
    }

    /**
     * ⛔ যিনি চাইলেন তিনি কখনো দ্বিতীয় সই নন — মালিকও নন (fe, ২১ অক্টোবর ২০২৬; [[ApprovalEngine::twoHands()]])। ⓘ মালিক অন্যের চাওয়ায়
     * দ্বিতীয় সইকারী হতে পারেন। ⛔ `owner_self_signed` এই তিন কাজে আর বসে না।
     */
    public function test_the_owner_cannot_sign_his_own_run_but_may_sign_a_clerks(): void
    {
        $run = app(PayrollService::class)->build('2026-08-01');
        $this->askOnce(fn () => app(PayrollService::class)->confirm($run->fresh()));
        $own = $this->pendingFor($run);
        $this->assertSame((int) $this->owner->id, (int) $own->requested_by);

        $engine = app(ApprovalEngine::class);
        $this->assertFalse($engine->canDecide($own->fresh(), $this->owner->fresh()), '⛔ মালিক নিজের রানে নিজেই দ্বিতীয় সই।');
        $this->assertFalse($engine->pendingQueryFor($this->owner->fresh())->whereKey($own->id)->exists(), '⛔ মালিকের নিজের রান তাঁর ইনবক্সে।');
        $this->as($this->owner)->post(route('approval.inbox.approve', $own->id));
        $this->assertSame(Approval::PENDING, (string) $own->fresh()->status, '⛔ মালিকের নিজের সই পড়ল।');
        $this->askOnce(fn () => app(PayrollService::class)->confirm($run->fresh()));
        $this->assertSame(DocumentStatus::DRAFT, (string) $run->fresh()->status);

        // ⓘ মালিক ফিরিয়ে নিলেন; কেরানি চাইলেন — মালিক এবার দ্বিতীয় সই
        $engine->cancel($own->fresh(), $this->owner->fresh());
        $clerk = $this->payrollClerk();
        $this->as($clerk);
        $this->askOnce(fn () => app(PayrollService::class)->confirm($run->fresh()));
        $asked = $this->pendingFor($run);
        $this->assertSame((int) $clerk->id, (int) $asked->requested_by);

        $this->as($this->owner)->post(route('approval.inbox.approve', $asked->id))->assertRedirect();
        $this->assertSame(Approval::APPROVED, (string) $asked->fresh()->status, '⛔ অন্যের চাওয়ায় মালিক দ্বিতীয় সই দিতে পারলেন না।');
        $this->assertFalse(AuditTrail::query()->where('action', SalaryStructureService::OWNER_SELF_SIGNED)->exists(),
            '⛔ বেতনের তিন কাজে "নিজে-সই" চিহ্ন বসল — পথটাই থাকার কথা নয়।');
        $this->assertSame(DocumentStatus::CONFIRMED, (string) app(PayrollService::class)->confirm($run->fresh())->status);
    }

    /**
     * ⛔ ১ টাকার রানও একজনে নয় — অনুমোদন বন্ধ কোম্পানিতে, আর কোম্পানির নিজের সীমার ছক (১ লাখের উপরে) থাকলেও; ছকের সীমা নিয়মটা
     * নামায় না, ছকটা তবু খাটে (fe, ২১ অক্টোবর ২০২৬)।
     */
    public function test_a_one_taka_run_needs_two_people_with_approvals_off_and_with_a_threshold_flow(): void
    {
        app(SalaryStructureService::class)->set($this->employee, $this->basic, '2026-01-15', '1');
        $maker = $this->payrollClerk();
        $this->as($maker);
        $run = app(PayrollService::class)->build('2026-08-01');
        $this->assertSame(-1, bccomp((string) $run->fresh()->net_total, '2', 4), 'প্রস্তুতি — রানটা ছোট নয়।');

        // ── অনুমোদন বন্ধ
        $this->askOnce(fn () => app(PayrollService::class)->confirm($run->fresh()));
        $off = $this->pendingFor($run);
        $this->assertTrue(app(ApprovalEngine::class)->isSecondHand($off));
        app(ApprovalEngine::class)->cancel($off->fresh(), $maker->fresh());

        // ── কোম্পানির নিজের ছক — ১ লাখের উপরে সই, নামধারী একজন
        $named = $this->secondHandIn((int) $this->company->id, 'approval.decide', 'hr.salary.view');
        $flow = ApprovalFlow::query()->create(['company_id' => $this->company->id, 'module' => 'hr', 'action' => 'payroll',
            'document_type' => '', 'threshold_amount' => '100000', 'is_active' => true]);
        ApprovalFlowStep::query()->create(['approval_flow_id' => $flow->id, 'level' => 1,
            'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $named->id]);
        $this->forgetTheEnginesFlows();
        $this->assertTrue(app(ApprovalEngine::class)->requires('hr', 'payroll', '1', 'PayrollRun'), '⛔ সীমার নিচে বলে সই লাগে না বলল।');

        $this->as($maker);
        $this->askOnce(fn () => app(PayrollService::class)->confirm($run->fresh()));
        $this->assertSame(DocumentStatus::DRAFT, (string) $run->fresh()->status, '⛔ সীমার নিচে বলে ১ টাকার রান একজনেই খাতায় বসল।');
        $asked = $this->pendingFor($run);
        $engine = app(ApprovalEngine::class);
        $this->assertFalse($engine->isSecondHand($asked), '⛔ কোম্পানির ছক থাকতে যন্ত্রের ছক খাটল।');
        $this->assertFalse($engine->canDecide($asked->fresh(), $maker->fresh()));
        $this->assertTrue($engine->canDecide($asked->fresh(), $named->fresh()), '⛔ কোম্পানির ছকের সইকারী সই দিতে পারেন না।');

        $engine->approve($asked->fresh(), $named->fresh());
        $this->as($maker);
        $this->assertSame(DocumentStatus::CONFIRMED, (string) app(PayrollService::class)->confirm($run->fresh())->status);
    }

    /** ⛔ নিজের রান বন্ধুকে ভার দিয়েও নয় — মালিক নিজের রানের সই কাউকে ভার দিলে সেই ভার এই অনুরোধে খাটে না */
    public function test_the_maker_cannot_sign_through_a_stand_in(): void
    {
        $run = app(PayrollService::class)->build('2026-08-01');
        $this->askOnce(fn () => app(PayrollService::class)->confirm($run->fresh()));
        $own = $this->pendingFor($run);

        $friend = $this->secondHandIn((int) $this->company->id, 'approval.decide');
        app(\App\Core\Engines\Approval\DelegationService::class)->grant($this->owner->fresh(), $friend, '2026-09-01', '2026-09-30');

        $this->assertFalse(app(ApprovalEngine::class)->canDecide($own->fresh(), $friend->fresh()),
            '⛔ মালিকের ভারপ্রাপ্ত মালিকের নিজের রানে সই দিতে পারেন — দুই হাত আসলে একজনের।');
    }

    /** ⛔ একজন দুইবার সই দিয়ে "দুইজন" হন না — দুইজনের সই লাগে এমন ধাপে একই মানুষের দ্বিতীয় চাপ ফেরে */
    public function test_one_person_cannot_sign_twice_to_make_two(): void
    {
        $a = $this->secondHandIn((int) $this->company->id, 'approval.decide', 'hr.salary.view');
        $b = $this->secondHandIn((int) $this->company->id, 'approval.decide', 'hr.salary.view');
        $flow = ApprovalFlow::query()->create(['company_id' => $this->company->id, 'module' => 'hr', 'action' => 'payroll',
            'document_type' => '', 'threshold_amount' => null, 'is_active' => true]);
        foreach ([$a, $b] as $person) {
            ApprovalFlowStep::query()->create(['approval_flow_id' => $flow->id, 'level' => 1, 'min_approvals' => 2,
                'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $person->id]);
        }
        $this->forgetTheEnginesFlows();

        $run = app(PayrollService::class)->build('2026-08-01');
        $this->askOnce(fn () => app(PayrollService::class)->confirm($run->fresh()));
        $asked = $this->pendingFor($run);
        $engine = app(ApprovalEngine::class);

        $engine->approve($asked->fresh(), $a->fresh());
        $this->assertSame(Approval::PENDING, (string) $asked->fresh()->status);
        $this->assertFalse($engine->canDecide($asked->fresh(), $a->fresh()), '⛔ একই মানুষ একই ধাপে দ্বিতীয়বার সই দিতে পারেন।');

        try {
            $engine->approve($asked->fresh(), $a->fresh());
            $this->fail('⛔ একই মানুষের দ্বিতীয় সই পড়ল।');
        } catch (\RuntimeException) {
        }

        $this->assertSame(Approval::PENDING, (string) $asked->fresh()->status, '⛔ একজনের দুই চাপে "দুইজন" হলো।');
        $engine->approve($asked->fresh(), $b->fresh());
        $this->assertSame(Approval::APPROVED, (string) $asked->fresh()->status);
    }

    public function test_a_company_with_its_own_active_flow_keeps_using_it(): void
    {
        $named = $this->secondHandIn((int) $this->company->id, 'approval.decide', 'hr.salary.view');
        $keyHolder = $this->payrollClerk();

        $flow = ApprovalFlow::query()->create(['company_id' => $this->company->id, 'module' => 'hr', 'action' => 'payroll',
            'document_type' => '', 'threshold_amount' => null, 'is_active' => true]);
        ApprovalFlowStep::query()->create(['approval_flow_id' => $flow->id, 'level' => 1,
            'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $named->id]);
        $this->forgetTheEnginesFlows();

        $run = app(PayrollService::class)->build('2026-08-01');
        $this->askOnce(fn () => app(PayrollService::class)->confirm($run->fresh()));
        $approval = $this->pendingFor($run);

        $engine = app(ApprovalEngine::class);
        $this->assertFalse($engine->isSecondHand($approval), '⛔ কোম্পানির নিজের ছক থাকতেও যন্ত্রের ছক খাটল।');
        $this->assertTrue($engine->canDecide($approval->fresh(), $named->fresh()), '⛔ ছকে নাম থাকা মানুষ সই দিতে পারেন না।');
        $this->assertFalse($engine->canDecide($approval->fresh(), $keyHolder->fresh()),
            '⛔ ছকে নেই, শুধু চাবি আছে — তবু সইকারী; কোম্পানির ছক উপেক্ষা হলো।');
    }

    // ── কাঠামো ─────────────────────────────────────────────────────────

    public function test_a_salary_structure_change_waits_for_a_second_key_holder(): void
    {
        $maker = $this->salaryClerk();
        $second = $this->salaryClerk();

        $this->as($maker)->post(route('hr.employee.salary.store', $this->employee), [
            'effective_from' => '2026-09-01',
            'amounts' => [$this->basic->id => self::RAISED],
        ])->assertRedirect()->assertSessionHas('saved');

        $this->assertContains((string) session('saved'),
            [(string) __('hr::message.salary_change_waiting', [], 'bn'), (string) __('hr::message.salary_change_waiting', [], 'en')]);
        $this->assertFalse($this->landed(), '⛔ সব সই বন্ধ, আর একজনের চাপেই বেতনের কাঠামো বদলাল।');

        $approval = app(SalaryStructureService::class)->waitingChange($this->employee);
        $this->assertNotNull($approval, '⛔ সইয়ের অনুরোধ বসেনি।');
        $this->assertTrue(app(ApprovalEngine::class)->isSecondHand($approval));
        $this->assertFalse(app(ApprovalEngine::class)->canDecide($approval->fresh(), $maker->fresh()), '⛔ যিনি চাইলেন তিনিই সই দিতে পারেন।');

        $this->as($maker)->post(route('approval.inbox.approve', $approval->id));
        $this->assertFalse($this->landed(), '⛔ চাওয়া মানুষের নিজের সইয়ে বদলটা বসল।');

        // ⓘ বেতন দেখার চাবি আছে, বসানোর চাবি নেই — সইকারী নন
        $viewer = $this->secondHandIn((int) $this->company->id, 'approval.decide', 'hr.employee.view', 'hr.salary.view');
        $this->assertFalse(app(ApprovalEngine::class)->canDecide($approval->fresh(), $viewer), '⛔ বসানোর চাবি ছাড়া সইকারী।');

        $this->as($second)->post(route('approval.inbox.approve', $approval->id))->assertRedirect();

        $this->assertTrue($this->landed(), '⛔ দ্বিতীয় চাবিধারীর সইয়েও বদলটা বসল না।');
        $row = SalaryStructure::query()->where('employee_id', $this->employee->id)->whereDate('effective_from', '2026-09-01')->firstOrFail();
        $this->assertSame((int) $maker->id, (int) $row->created_by, '⛔ সারিটা যিনি চেয়েছিলেন তাঁর নামে নয়।');
    }

    public function test_the_owner_cannot_sign_his_own_structure_change_but_may_sign_a_clerks(): void
    {
        $approval = app(SalaryStructureService::class)->change($this->employee, '2026-09-01', [$this->basic->id => self::RAISED]);
        $this->assertInstanceOf(Approval::class, $approval, '⛔ মালিকের বদলও সাথে সাথে বসল — দ্বিতীয় সই চাওয়াই হলো না।');

        $this->assertFalse(app(ApprovalEngine::class)->canDecide($approval->fresh(), $this->owner->fresh()), '⛔ মালিক নিজের বদলে নিজেই সই।');
        $this->as($this->owner)->post(route('approval.inbox.approve', $approval->id));
        $this->assertFalse($this->landed(), '⛔ মালিকের নিজের সইয়ে বদলটা বসল।');

        app(ApprovalEngine::class)->cancel($approval->fresh(), $this->owner->fresh());
        $clerk = $this->salaryClerk();
        $this->as($clerk)->post(route('hr.employee.salary.store', $this->employee), [
            'effective_from' => '2026-09-01', 'amounts' => [$this->basic->id => self::RAISED],
        ])->assertSessionHasNoErrors();
        $asked = app(SalaryStructureService::class)->waitingChange($this->employee);

        $this->as($this->owner)->post(route('approval.inbox.approve', $asked->id))->assertRedirect();
        $this->assertTrue($this->landed(), '⛔ কেরানির চাওয়ায় মালিক দ্বিতীয় সই দিতে পারলেন না।');
        $this->assertFalse(AuditTrail::query()->forRecord(Employee::class, (int) $this->employee->id)
            ->where('action', SalaryStructureService::OWNER_SELF_SIGNED)->exists(), '⛔ "নিজে-সই" চিহ্ন বসল।');
    }

    // ── সই দেওয়ার কেউ নেই ──────────────────────────────────────────────

    /**
     * ⛔ অনুরোধকারী ছাড়া চাবি কারও হাতে নেই — অনুরোধ নীরবে ঝোলে না: রানের পাতা, অনুমোদনের পাতা আর "আমার অনুরোধ"-এর সারি বলে
     * কোন তিনটা চাবি কাকে দিতে হবে; চাবি দিলে লেখাটা যায়, আর তিনি সই দিতে পারেন (cb-র রিভিউ, ২১ অক্টোবর ২০২৬)।
     */
    public function test_a_run_nobody_else_can_sign_says_which_keys_to_give_until_someone_holds_them(): void
    {
        $run = app(PayrollService::class)->build('2026-08-01');
        $this->askOnce(fn () => app(PayrollService::class)->confirm($run->fresh()));
        $approval = $this->pendingFor($run);
        $this->assertNotNull(app(ApprovalEngine::class)->noSecondSignerNote($approval->fresh()),
            'প্রস্তুতি — ডেমোতে মালিক ছাড়াও কেউ রানের তিন চাবি ধরে আছেন।');

        foreach ([route('hr.payroll.show', $run->id), route('approval.inbox.show', $approval->id), route('approval.inbox.mine')] as $page) {
            $this->as($this->owner)->get($page)->assertOk()
                ->assertSee((string) __('core.approval.no_second_signer', ['keys' => 'hr.payroll.manage, hr.salary.view, approval.decide']), false);
        }

        $clerk = $this->payrollClerk();
        $this->as($this->owner)->get(route('hr.payroll.show', $run->id))->assertOk()->assertDontSee('data-no-second-signer', false);
        $this->assertNull(app(ApprovalEngine::class)->noSecondSignerNote($approval->fresh()));

        $this->as($clerk)->post(route('approval.inbox.approve', $approval->id))->assertRedirect();
        $this->assertSame(Approval::APPROVED, (string) $approval->fresh()->status, '⛔ চাবি পেয়েও সই দিতে পারলেন না।');
    }

    public function test_a_salary_change_and_a_bank_change_nobody_else_can_sign_say_which_keys_to_give(): void
    {
        $change = app(SalaryStructureService::class)->change($this->employee, '2026-09-01', [$this->basic->id => self::RAISED]);
        $this->as($this->owner)->get(route('hr.employee.salary', $this->employee))->assertOk()
            ->assertSee((string) __('core.approval.no_second_signer', ['keys' => 'hr.salary.manage, hr.salary.view, approval.decide']), false);
        $this->salaryClerk();
        $this->as($this->owner)->get(route('hr.employee.salary', $this->employee))->assertOk()->assertDontSee('data-no-second-signer', false);
        $this->assertInstanceOf(Approval::class, $change);

        $this->as($this->owner);
        app(EmployeeService::class)->update($this->employee->fresh(), [
            'payment_method' => 'bank', 'bank_name' => 'Sonali', 'bank_account_no' => '7777777777777', 'bank_routing_no' => '200270001',
        ]);
        $this->assertNotNull(app(\App\Modules\Hr\Services\PaymentChanges::class)->pendingFor($this->employee), 'প্রস্তুতি — বদলটা অপেক্ষায় নয়।');
        $this->as($this->owner)->get(route('hr.employee.show', $this->employee))->assertOk()
            ->assertSee((string) __('core.approval.no_second_signer', ['keys' => 'hr.employee.manage, hr.identity.view, approval.decide']), false);

        $this->secondHandIn((int) $this->company->id, 'hr.employee.view', 'hr.employee.manage', 'hr.identity.view', 'approval.decide');
        $this->as($this->owner)->get(route('hr.employee.show', $this->employee))->assertOk()->assertDontSee('data-no-second-signer', false);
    }

    // ── মডিউলের ঘোষণা ─────────────────────────────────────────────────

    public function test_a_module_cannot_name_a_second_hand_key_it_does_not_declare(): void
    {
        $raw = fn (array $keys): array => [
            'code' => 'hr', 'name' => ['en' => 'HR', 'bn' => 'কর্মী'], 'permissions' => ['hr.payroll.manage'],
            'approvals' => ['payroll' => 'hr::approval.payroll'],
            'approval_rules' => ['payroll' => ['second_hand' => $keys]],
        ];

        $ok = ModuleDefinition::fromArray($raw(['hr.payroll.manage']), 'test/module.php', 'App\\Modules\\Hr');
        $this->assertSame(['hr.payroll.manage'], $ok->approvalRules['payroll']['second_hand']);

        foreach (['unknown key' => ['hr.nobody.has.this'], 'empty list' => []] as $why => $keys) {
            try {
                ModuleDefinition::fromArray($raw($keys), 'test/module.php', 'App\\Modules\\Hr');
                $this->fail("⛔ [{$why}] দ্বিতীয় সইয়ের চাবি বুটে ধরা পড়ল না — কেউ সই দিতে পারতেন না, নীরবে।");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('second_hand', $e->getMessage());
            }
        }
    }

    // ── সাহায্যকারী ─────────────────────────────────────────────────────

    /** ⓘ UB-র মতো — ছক আছে, একটাও চালু নয় (মালিক, ৩ অক্টোবর ২০২৬) */
    private function switchEveryApprovalOff(): void
    {
        ApprovalFlow::query()->update(['is_active' => false]);
        ApprovalFlow::query()->create(['company_id' => $this->company->id, 'module' => 'sales', 'action' => 'discount',
            'document_type' => '', 'threshold_amount' => null, 'is_active' => false]);
        $this->forgetTheEnginesFlows();
    }

    private function askOnce(callable $confirm): void
    {
        try {
            $confirm();
            $this->fail('⛔ সব সই বন্ধ, আর রানটা এক চাপেই খাতায় বসল — দ্বিতীয় সই চাওয়াই হলো না।');
        } catch (HeldForApproval) {
        }
    }

    private function pendingFor(PayrollRun $run): Approval
    {
        $approval = app(ApprovalEngine::class)->latestFor($run->fresh(), 'payroll');
        $this->assertNotNull($approval, '⛔ সইয়ের অনুরোধ বসেনি।');
        $this->assertSame(Approval::PENDING, (string) $approval->status);

        return $approval;
    }

    private function landed(): bool
    {
        return SalaryStructure::query()->where('employee_id', $this->employee->id)
            ->where('salary_head_id', $this->basic->id)->whereDate('effective_from', '2026-09-01')->exists();
    }

    /** HR-এর বেতনের কেরানি — রান বানান, নিশ্চিত চান, সই দেওয়ার দরজা আছে; সুপার অ্যাডমিন নন */
    private function payrollClerk(): User
    {
        return $this->secondHandIn((int) $this->company->id,
            'approval.decide', 'hr.employee.view', 'hr.payroll.view', 'hr.payroll.manage', 'hr.salary.view');
    }

    private function salaryClerk(): User
    {
        return $this->secondHandIn((int) $this->company->id,
            'approval.decide', 'hr.employee.view', 'hr.salary.view', 'hr.salary.manage');
    }

    private function as(User $user): static
    {
        $this->app['auth']->forgetGuards();
        FieldSecurity::forget();

        return $this->actingAs($user->fresh());
    }
}
