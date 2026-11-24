<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Security\FieldSecurity;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Approval\Services\ApprovalFlowService;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⚠️ §৮ ⚠️৫ — বেতনের কাঠামো: দেওয়া মাস বদলায় না, সংশোধন নীরব নয়, আর ছক চালু করলে দ্বিতীয় হাত; a6, ২১ অক্টোবর ২০২৬।
 *
 * ⛔ আগে: `updateOrCreate` একই খাত আর তারিখের সারি চুপচাপ নতুন অঙ্কে লিখত — আগস্টের বেতন দেওয়ার পরও "১৫ জানুয়ারি থেকে"
 * অঙ্কটা বদলে যেত, দেওয়া বেতনশিট আর কাঠামো দুই কথা বলত; আর `hr.salary.manage` থাকা একজনই সব বসাতেন (SOX maker-checker নেই)।
 *
 * ⭐ এখন ([[SalaryStructureService::change()]], [[SalaryStructureService::set()]], [[PaidMonth]]):
 *  · যে মাসের বেতন এই কর্মীকে দেওয়া হয়ে গেছে, সেই মাস বা তার আগের তারিখে বদল নয় — সেবা আর ওয়েব দুইখানেই, বাংলা কারণসহ;
 *  · এখনো-না-দেওয়া সারির একই দিনের সংশোধন চলে, অডিটে পুরনো আর নতুন অঙ্ক (`updated`), ঢাকা বেতনের চাবি ছাড়া;
 *  · ভিন্ন তারিখে বসালে দুইটা সারিই ইতিহাসে থাকে;
 *  · `hr` · `salary_change` ছক চালু থাকলে বদলটা "সইয়ের অপেক্ষায়" — আরেকজন "সই দিন" চাপলে তবে বসে, যিনি চেয়েছিলেন তাঁর নামে;
 *    "প্রত্যাখ্যাত" হলে কিছুই বসে না; অনুরোধে অঙ্ক খোলা থাকে না।
 */
final class ASalaryChangeNeedsASecondHandTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\SignsTheSecondHand;

    private const PAID_BASIC = '24680';

    private const RAISED_BASIC = '41234.5';

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

        // ⓘ ৫ সেপ্টেম্বর — আগস্টের বেতন দেওয়া যায়, সেপ্টেম্বর এখনো খোলা
        $this->travelTo('2026-09-05 10:00:00');

        $this->basic = SalaryHead::query()->where('code', 'BASIC')->firstOrFail();
        $this->employee = app(EmployeeService::class)->create([
            'code' => 'EMP-2ND', 'name_en' => 'Second Hand', 'joining_date' => '2026-01-15', 'payment_method' => 'cash',
        ]);
        $this->salaries()->set($this->employee, $this->basic, '2026-01-15', self::PAID_BASIC);
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    // ── দেওয়া মাস ───────────────────────────────────────────────────────

    public function test_the_service_refuses_a_change_from_a_paid_month_or_before_it(): void
    {
        $run = $this->paidAugust();

        foreach (['2026-08-15', '2026-08-01', '2026-01-15', '2026-03-01'] as $date) {
            try {
                $this->salaries()->set($this->employee, $this->basic, $date, self::RAISED_BASIC);
                $this->fail("⛔ আগস্টের বেতন দেওয়ার পর {$date} থেকে অঙ্ক বদলানো গেল।");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('effective_from', $e->errors());
                $this->assertStringContainsString((string) $run->document_no, (string) $e->errors()['effective_from'][0],
                    '⛔ কারণে কোন রানটা দেওয়া, তা নেই।');
            }
        }

        $this->assertSame([self::PAID_BASIC], $this->amounts(), '⛔ থামার পরও কাঠামো বদলেছে।');
    }

    public function test_the_web_door_refuses_it_with_the_reason_in_bengali(): void
    {
        $run = $this->paidAugust();

        $this->from(route('hr.employee.salary', $this->employee))
            ->post(route('hr.employee.salary.store', $this->employee), [
                'effective_from' => '2026-01-15',
                'amounts' => [$this->basic->id => self::RAISED_BASIC],
            ])
            ->assertRedirect(route('hr.employee.salary', $this->employee))
            ->assertSessionHasErrors('effective_from');

        $this->assertStringContainsString((string) $run->document_no, (string) session('errors')->first('effective_from'),
            '⛔ ওয়েবের কারণে কোন রানটা দেওয়া, তা নেই।');
        $this->assertStringContainsString('দেওয়া হয়ে গেছে',
            (string) __('hr::validation.salary_month_paid', ['month' => 'আগস্ট ২০২৬', 'no' => 'PRL-1'], 'bn'), '⛔ বাংলা কারণ নেই।');
        $this->assertSame([self::PAID_BASIC], $this->amounts(), '⛔ ওয়েবের পথে দেওয়া মাসের অঙ্ক বদলাল।');
    }

    public function test_the_next_open_month_is_allowed_and_history_keeps_both_rows(): void
    {
        $this->paidAugust();

        $this->post(route('hr.employee.salary.store', $this->employee), [
            'effective_from' => '2026-09-01',
            'amounts' => [$this->basic->id => self::RAISED_BASIC],
        ])->assertRedirect(route('hr.employee.salary', $this->employee))->assertSessionHasNoErrors();

        $this->assertSame(['2026-01-15' => self::PAID_BASIC], $this->rowsByDate(), '⛔ অনুমোদনের আগেই বদল বসল।');
        $this->signTheSalaryChange($this->salaries()->waitingChange($this->employee));

        $this->assertSame(['2026-01-15' => self::PAID_BASIC, '2026-09-01' => self::RAISED_BASIC], $this->rowsByDate(),
            '⛔ ভিন্ন তারিখের বদলে পুরনো সারি হারাল, বা নতুনটা বসল না।');
    }

    public function test_an_employee_who_was_not_in_the_paid_run_can_still_be_set_for_that_month(): void
    {
        $this->paidAugust();

        $late = app(EmployeeService::class)->create([
            'code' => 'EMP-LATE', 'name_en' => 'Late Joiner', 'joining_date' => '2026-08-10', 'payment_method' => 'cash',
        ]);

        $row = $this->notRefused(fn () => $this->salaries()->set($late, $this->basic, '2026-08-10', '15000'),
            '⛔ আগস্টের রানে না-থাকা কর্মীকে আগস্টের জন্য আটকানো হলো');

        $this->assertSame(0, bccomp('15000', (string) $row->amount, 4), '⛔ আগস্টের রানে না-থাকা কর্মীর আগস্টের কাঠামো বসল না।');
    }

    public function test_a_cancelled_run_does_not_lock_the_month(): void
    {
        $run = $this->paidAugust();
        app(PayrollService::class)->cancel($run->fresh(), 'Wrong amounts');

        $this->notRefused(fn () => $this->salaries()->set($this->employee, $this->basic, '2026-08-01', self::RAISED_BASIC),
            '⛔ বাতিল রান মাসটা আটকে দিল');

        $this->assertSame(['2026-01-15' => self::PAID_BASIC, '2026-08-01' => self::RAISED_BASIC], $this->rowsByDate());
    }

    // ── একই দিনের সংশোধন ─────────────────────────────────────────────────

    public function test_a_same_day_correction_of_an_unpaid_row_is_audited_with_old_and_new_masked_without_the_key(): void
    {
        // ⓘ খসড়া রান — দেওয়া নয়, তাই সংশোধন চলে
        app(PayrollService::class)->build('2026-08-01');

        $row = $this->salaries()->set($this->employee, $this->basic, '2026-01-15', self::RAISED_BASIC);

        $this->assertSame(['2026-01-15' => self::RAISED_BASIC], $this->rowsByDate(), '⛔ সংশোধনে দ্বিতীয় সারি, বা অঙ্ক বসেনি।');

        $trail = AuditTrail::query()
            ->forRecord(SalaryStructure::class, (int) $row->id)
            ->where('action', AuditTrail::UPDATED)
            ->whereHas('changes', fn ($q) => $q->where('field', 'amount'))
            ->latest('id')
            ->first();

        $this->assertNotNull($trail, '⛔ একই দিনের সংশোধন অডিটে নেই — নীরবে পুরনো অঙ্ক ঢাকা পড়ল।');
        $change = $trail->changes->firstWhere('field', 'amount');
        $this->assertSame(0, bccomp(self::PAID_BASIC, (string) $change->old_value, 4), '⛔ অডিটে পুরনো অঙ্ক নেই।');
        $this->assertSame(0, bccomp(self::RAISED_BASIC, (string) $change->new_value, 4), '⛔ অডিটে নতুন অঙ্ক নেই।');

        $auditor = $this->staff('governance.audit.view');
        $body = (string) $this->as($auditor)->get(route('governance.audit.show', $trail->id))->assertOk()->getContent();
        $this->assertStringContainsString('amount', $body);
        $this->assertStringNotContainsString('41234', $body, '⛔ বেতনের চাবি ছাড়া নিরীক্ষক সংশোধনের অঙ্ক দেখছেন।');
        $this->assertStringNotContainsString('24680', $body, '⛔ বেতনের চাবি ছাড়া নিরীক্ষক পুরনো অঙ্ক দেখছেন।');

        $keyed = $this->staff('governance.audit.view', 'hr.salary.view');
        $this->as($keyed)->get(route('governance.audit.show', $trail->id))->assertOk()
            ->assertSee('41234', false)->assertSee('24680', false);
    }

    // ── দ্বিতীয় সই ──────────────────────────────────────────────────────

    public function test_the_flow_builder_offers_the_action_and_nothing_is_seeded_on(): void
    {
        $this->assertArrayHasKey('hr.salary_change', app(ApprovalFlowService::class)->labels(),
            '⛔ ছকের পর্দায় "বেতনের কাঠামো বদল" নেই — কোম্পানি দ্বিতীয় সই চালুই করতে পারত না।');
        $this->assertStringContainsString((string) __('hr::approval.salary_change'), (string) app(ApprovalFlowService::class)->labels()['hr.salary_change'],
            '⛔ ছকের পর্দায় বেতন-বদলের নাম অন্য কাজের নামে দেখায়।');
        $this->assertSame(0, ApprovalFlow::query()->withoutGlobalScopes()->where('module', 'hr')->where('action', 'salary_change')->count(),
            '⛔ বেতন-বদলের ছক নিজে থেকে বসে গেছে — অনুমোদন বন্ধ রাখা কোম্পানিতে বেতন আটকাত।');

        // ⛔ `moves_money`-এ গেলে প্রতিটা কোম্পানিতে হিসাবরক্ষকের ছক নিজে বসত ([[MoneyFlowDefaults]])
        $this->assertNotContains(['module' => 'hr', 'action' => 'salary_change'], app(MoneyFlowDefaults::class)->moneyActions(),
            '⛔ বেতন-বদল টাকার কাজের তালিকায় — পরের deploy-এ ছক নিজে বসে যেত।');
    }

    /**
     * ⛔ মালিকের ব্যতিক্রম (a6, ২১ অক্টোবর ২০২৬) — আগে এখানে দাবি ছিল "ছক নেই, বদল সাথে সাথে বসে"; মালিক এখন বলেছেন বেতনের কাঠামো
     * বদলে সবসময় আরেকজনের সই, ছক না থাকলেও ([[ApprovalRules::secondHand()]], [[APayrollRunNeedsASecondHandEvenWithApprovalsOffTest]])।
     */
    public function test_with_no_flow_the_change_still_waits_for_the_built_in_second_signature(): void
    {
        $maker = $this->maker();

        $this->as($maker)->post(route('hr.employee.salary.store', $this->employee), [
            'effective_from' => '2026-09-01',
            'amounts' => [$this->basic->id => self::RAISED_BASIC],
        ])->assertRedirect()->assertSessionHas('saved');
        $this->assertSaid('hr::message.salary_change_waiting');

        $this->assertArrayNotHasKey('2026-09-01', $this->rowsByDate(), '⛔ ছক নেই, আর একজনের চাপেই বদলটা বসল।');
        $approval = $this->salaries()->waitingChange($this->employee);
        $this->assertNotNull($approval, '⛔ ছক নেই, আর দ্বিতীয় সইয়ের অনুরোধও বসেনি।');
        $this->assertTrue(app(ApprovalEngine::class)->isSecondHand($approval), '⛔ অনুরোধটা যন্ত্রের "দ্বিতীয় সই" ছকে নয়।');
        $this->assertSame(0, ApprovalFlow::query()->where('module', 'hr')->count(), '⛔ দ্বিতীয় সইয়ের জন্য ছক বসানো হলো।');
    }

    public function test_with_the_flow_on_the_change_waits_and_lands_only_on_another_persons_signature(): void
    {
        $maker = $this->maker();
        // ⓘ যিনি চান তিনিও ছকে সইকারী — তবু নিজের অনুরোধে নয়
        $signer = $this->signerFlow($maker);

        $this->as($maker)->post(route('hr.employee.salary.store', $this->employee), [
            'effective_from' => '2026-09-01',
            'amounts' => [$this->basic->id => self::RAISED_BASIC],
        ])->assertRedirect()->assertSessionHas('saved');
        $this->assertSaid('hr::message.salary_change_waiting');

        $this->assertArrayNotHasKey('2026-09-01', $this->rowsByDate(), '⛔ সইয়ের আগেই বদলটা বসে গেল।');

        $approval = $this->salaries()->waitingChange($this->employee);
        $this->assertNotNull($approval, '⛔ সইয়ের অনুরোধ বসেনি।');
        $this->assertSame(Approval::PENDING, $approval->status);
        $this->assertNull($approval->amount, '⛔ অনুরোধের অঙ্কের ঘরে বেতন — ইনবক্স আর রিপোর্টে খোলা ছাপত।');
        $this->assertStringNotContainsString('41234', (string) DB::table('approvals')->where('id', $approval->id)->value('payload'),
            '⛔ অনুরোধের payload-এ বেতনের অঙ্ক খোলা।');
        $this->assertStringNotContainsString('41234', (string) $approval->requested_reason);

        // ⓘ বেতনের পাতায় "সইয়ের অপেক্ষায়" অঙ্কসহ — চাবি থাকা মানুষের জন্য
        $this->as($maker)->get(route('hr.employee.salary', $this->employee))->assertOk()
            ->assertSee('data-salary-waiting', false)->assertSee('41,234.50', false);

        $engine = app(ApprovalEngine::class);
        $this->assertFalse($engine->canDecide($approval->fresh(), $maker->fresh()), '⛔ যিনি চাইলেন তিনিই সই দিতে পারেন।');

        $this->actingAs($signer);
        $engine->approve($approval->fresh(), $signer->fresh(), 'ok');

        $this->assertSame(self::RAISED_BASIC, $this->rowsByDate()['2026-09-01'] ?? null, '⛔ সই পড়ার পরও বদলটা বসল না।');
        $row = SalaryStructure::query()->where('employee_id', $this->employee->id)->whereDate('effective_from', '2026-09-01')->firstOrFail();
        $this->assertSame((int) $maker->id, (int) $row->created_by, '⛔ সারিটা সইকারীর নামে — যিনি চেয়েছিলেন তাঁর নামে বসার কথা।');
        $this->assertTrue($approval->fresh()->isUsed(), '⛔ সইটা খাটানো হয়েছে বলে লেখা নেই — আবার খাটতে পারত।');
        $this->assertNull($this->salaries()->waitingChange($this->employee));
    }

    public function test_with_the_flow_on_a_paid_month_is_refused_before_anyone_is_asked(): void
    {
        $this->signerFlow();
        $this->paidAugust();

        try {
            $this->salaries()->change($this->employee, '2026-08-01', [$this->basic->id => self::RAISED_BASIC]);
            $this->fail('⛔ দেওয়া মাসের বদলের জন্য সই চাওয়া হলো — সই পড়লেও বসত না, সইকারী মিছে সময় দিতেন।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('effective_from', $e->errors());
        }

        $this->assertSame(0, Approval::query()->where('action', SalaryStructureService::ACTION_CHANGE)->count());
    }

    public function test_a_rejected_change_lands_nowhere(): void
    {
        $signer = $this->signerFlow();
        $approval = $this->salaries()->change($this->employee, '2026-09-01', [$this->basic->id => self::RAISED_BASIC]);
        $this->assertInstanceOf(Approval::class, $approval);

        $this->actingAs($signer);
        app(ApprovalEngine::class)->reject($approval->fresh(), $signer->fresh(), 'Not agreed');

        $this->assertSame(Approval::REJECTED, $approval->fresh()->status);
        $this->assertSame(['2026-01-15' => self::PAID_BASIC], $this->rowsByDate(), '⛔ প্রত্যাখ্যাত বদলও বসে গেল।');
    }

    public function test_a_second_change_while_one_waits_is_refused(): void
    {
        $this->signerFlow();
        $this->salaries()->change($this->employee, '2026-09-01', [$this->basic->id => self::RAISED_BASIC]);

        try {
            $this->salaries()->change($this->employee, '2026-10-01', [$this->basic->id => '50000']);
            $this->fail('⛔ একটা বদল অপেক্ষায় থাকতেই আরেকটা — ইঞ্জিন পুরনোটা ফেরাত, নতুনটা নীরবে হারাত।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('amounts', $e->errors());
        }

        $this->assertSame(1, Approval::query()->where('action', SalaryStructureService::ACTION_CHANGE)->pending()->count());
    }

    public function test_a_signature_that_lands_after_the_month_was_paid_does_not_change_it(): void
    {
        $signer = $this->signerFlow();
        $approval = $this->salaries()->change($this->employee, '2026-09-01', [$this->basic->id => self::RAISED_BASIC]);
        $this->assertInstanceOf(Approval::class, $approval);

        // ⓘ সই পড়ার আগেই সেপ্টেম্বরের বেতন দেওয়া হলো — পুরনো অঙ্কে
        $this->travelTo('2026-10-03 10:00:00');
        $september = $this->confirmedPayroll(app(PayrollService::class)->build('2026-09-01'));
        $this->assertSame('confirmed', (string) $september->status);

        $this->actingAs($signer);
        $this->notRefused(fn () => app(ApprovalEngine::class)->approve($approval->fresh(), $signer->fresh(), 'late'),
            '⛔ দেরির সই প্রত্যাখ্যানে ভেঙে পড়ল — না-বসার কথা অডিটে না লিখে');

        $this->assertArrayNotHasKey('2026-09-01', $this->rowsByDate(), '⛔ দেওয়া মাসে দেরির সই অঙ্ক বদলে দিল।');
        $this->assertFalse($approval->fresh()->isUsed());
        $this->assertTrue(AuditTrail::query()->forRecord(Employee::class, (int) $this->employee->id)
            ->where('action', 'salary_change_late')->exists(), '⛔ সই পেয়েও না-বসার কথা কর্মীর অডিটে নেই।');
    }

    // ── সাহায্যকারী ─────────────────────────────────────────────────────

    /**
     * ⓘ যে কাজ প্রত্যাখ্যাত হওয়ার কথা নয়, তা প্রত্যাখ্যাত হলে দাবির নিজের বার্তায় লাল হয় — ভুল ধরা পড়ে দাবিতে, অচেনা
     * exception-এ নয় (fe, ২১ অক্টোবর ২০২৬)।
     */
    private function notRefused(callable $do, string $why): mixed
    {
        try {
            return $do();
        } catch (ValidationException $e) {
            $this->fail($why.' — '.collect($e->errors())->flatten()->first());
        }
    }

    /** ⓘ ঝলকের লেখা অনুরোধের ভাষায় — দুই ভাষার যেকোনোটা */
    private function assertSaid(string $key): void
    {
        $this->assertContains((string) session('saved'), [(string) __($key, [], 'bn'), (string) __($key, [], 'en')],
            "⛔ পর্দার খবর `{$key}` নয়।");
    }

    private function salaries(): SalaryStructureService
    {
        return app(SalaryStructureService::class);
    }

    private function paidAugust(): PayrollRun
    {
        $run = $this->confirmedPayroll(app(PayrollService::class)->build('2026-08-01'));

        $this->assertSame('confirmed', (string) $run->status, 'প্রস্তুতি — আগস্টের রান নিশ্চিত হয়নি।');
        $this->assertTrue($run->payslips()->where('employee_id', $this->employee->id)->exists(), 'প্রস্তুতি — কর্মী রানে নেই।');

        return $run;
    }

    /** @return list<string> */
    private function amounts(): array
    {
        return array_values($this->rowsByDate());
    }

    /** @return array<string, string> তারিখ => অঙ্ক (শেষের শূন্য ছাড়া) */
    private function rowsByDate(): array
    {
        return SalaryStructure::query()
            ->where('employee_id', $this->employee->id)
            ->where('salary_head_id', $this->basic->id)
            ->orderBy('effective_from')
            ->get()
            ->mapWithKeys(fn (SalaryStructure $r) => [
                $r->effective_from->toDateString() => rtrim(rtrim(bcadd((string) $r->amount, '0', 4), '0'), '.'),
            ])
            ->all();
    }

    /**
     * ⓘ `hr` · `salary_change` ছক — এক স্তর, যেকোনো একজন (বেতনের চাবিসহ সইকারী, আর চাইলে আরও কেউ); কোডে নয়, এখানে হাতে বসানো।
     */
    private function signerFlow(User ...$alsoSigners): User
    {
        $signer = $this->staff('hr.employee.view', 'hr.salary.view');

        $flow = ApprovalFlow::query()->create([
            'company_id' => $this->company->id, 'module' => 'hr', 'action' => SalaryStructureService::ACTION_CHANGE,
            'document_type' => '', 'threshold_amount' => null, 'is_active' => true,
        ]);

        foreach ([$signer, ...$alsoSigners] as $person) {
            ApprovalFlowStep::query()->create([
                'approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $person->id,
            ]);
        }
        app()->forgetInstance(ApprovalEngine::class);

        return $signer;
    }

    /** HR-এর কেরানি — বেতন দেখেন ও বসান, সুপার অ্যাডমিন নন */
    private function maker(): User
    {
        return $this->staff('hr.employee.view', 'hr.salary.view', 'hr.salary.manage');
    }

    private function staff(string ...$keys): User
    {
        $user = User::factory()->create(['current_company_id' => $this->company->id, 'is_active' => true]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);
        CompanyContext::forCompany($this->company->id, function () use ($user, $keys) {
            foreach ($keys as $key) {
                $user->givePermissionTo(Permission::findOrCreate($key, 'web'));
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        FieldSecurity::forget();

        return $user->fresh();
    }

    private function as(User $user): static
    {
        $this->app['auth']->forgetGuards();
        FieldSecurity::forget();

        return $this->actingAs($user->fresh());
    }
}
