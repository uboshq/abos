<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Approval\ApprovalRules;
use App\Core\Engines\Approval\HeldForApproval;
use App\Core\Security\FieldSecurity;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\SalaryHead;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\PayrollService;
use App\Modules\Hr\Services\SalaryHeadService;
use App\Modules\Hr\Services\SalaryStructureService;
use App\Modules\MasterData\Services\MasterListService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\SignsMoneyOff;
use Tests\SignsTheSecondHand;
use Tests\TestCase;

/**
 * ⛔ উৎসব ভাতার রানও দ্বিতীয় সই ছাড়া খাতায় বসে না — সব ছক বন্ধ থাকা কোম্পানিতেও (UB-র মতো); মেশানোর পরের দাবি (মালিকের একমাত্র
 * ব্যতিক্রম, ২১ অক্টোবর ২০২৬; a6; [[ApprovalRules::secondHand()]], [[PayrollService::buildBonus()]])।
 *
 * ⓘ পে-শাখা ভাতার রান বানাল (`run_type` bonus), সই-শাখা বেতনের রানে "দ্বিতীয় সই" বসাল — আলাদা আলাদা। ভাতার রান একই
 * [[PayrollService::confirm()]] দিয়ে যায়, একই `hr`·`payroll` কাজে; তাই নতুন ঘোষণা লাগে না — এই দাবি সেটা আটকে রাখে, যাতে কেউ
 * ভাতার জন্য আলাদা কাজ বা পথ বানালে নিয়মটা নীরবে না হারায়।
 *
 * ── দাবি ───────────────────────────────────────────────────────────────
 *  · কেরানি ভাতার রান বানিয়ে "নিশ্চিত" চাপলেন — রান খসড়া, খাতায় কিছু নয়; অনুরোধ যন্ত্রের "দ্বিতীয় সই" ছকে, `payroll` কাজে।
 *  · তিনি নিজে সই দিতে পারেন না (ইঞ্জিনে আর ওয়েবের দরজায়), আবার "নিশ্চিত" চাপলেও নয়।
 *  · চাবি থাকা আরেকজন সই দিলে "নিশ্চিত" খাতায় বসায় — ৫২২৬ ডেবিট = স্লিপের যোগ, প্রদেয় বেতন ক্রেডিট = নিট।
 *  · মালিক নিজে বানালে নিজের ভাতার রানে সই দিতে পারেন না; রান খসড়া থাকে।
 */
final class AFestivalBonusNeedsASecondHandTest extends TestCase
{
    use \Tests\Concerns\OpensTheYears;
    use RefreshDatabase;
    use SignsMoneyOff;
    use SignsTheSecondHand;

    private const BASIC = '31234.57';

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->openTheYearsOf('2026-04-10');
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        app(StandardChart::class)->install();
        app(MasterListService::class)->installDefaults();
        app(SalaryHeadService::class)->installDefaults();
        FieldSecurity::forget();
        $this->travelTo('2026-09-05 10:00:00');
        // ⓘ ডেমোর কর্মীরা বাদ — রানে কেবল এই একজন, যাতে নিট এক মাসের মূল
        Employee::query()->delete();

        $employee = app(EmployeeService::class)->create([
            'code' => 'EMP-FB', 'name_en' => 'Festival Two Hands', 'joining_date' => '2025-01-15', 'payment_method' => 'cash',
        ]);
        app(SalaryStructureService::class)->set($employee, SalaryHead::query()->where('code', 'BASIC')->firstOrFail(), '2025-01-15', self::BASIC);

        // ⓘ UB-র মতো — ছক আছে, একটাও চালু নয় (মালিক, ৩ অক্টোবর ২০২৬)
        ApprovalFlow::query()->update(['is_active' => false]);
        $this->forgetTheEnginesFlows();
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    public function test_the_bonus_run_goes_through_the_payroll_action_that_declares_a_second_hand(): void
    {
        $this->assertSame(['hr.payroll.manage'], array_values(array_diff(
            app(ApprovalRules::class)->secondHand('hr', 'payroll'), ['hr.salary.view', ApprovalEngine::DECIDE_KEY])),
            '⛔ বেতনের রানের কাজে দ্বিতীয় সইয়ের ঘোষণা নেই।');
        $this->assertSame(0, ApprovalFlow::query()->where('is_active', true)->count(), 'প্রস্তুতি — চালু ছক রয়ে গেল।');
    }

    public function test_the_maker_cannot_post_the_bonus_and_a_second_key_holder_signs_it(): void
    {
        $maker = $this->payrollClerk();
        $second = $this->payrollClerk();

        $this->as($maker)->post(route('hr.payroll.store'), ['run_type' => PayrollRun::BONUS, 'bonus_date' => '2026-04-10', 'narration' => 'Eid'])
            ->assertSessionHasNoErrors();
        $run = PayrollRun::acrossBranches()->where('run_type', PayrollRun::BONUS)->firstOrFail();
        $this->assertSame(0, bccomp((string) $run->net_total, self::BASIC, 4), 'প্রস্তুতি — ভাতা এক মাসের মূল নয়।');

        // ── যিনি বানালেন তিনি "নিশ্চিত" চাপলেন — খাতায় কিছু নয়
        $this->as($maker)->from(route('hr.payroll.show', $run))->post(route('hr.payroll.confirm', $run))
            ->assertRedirect(route('hr.payroll.show', $run))->assertSessionHasErrors('status');
        $this->assertSame(DocumentStatus::DRAFT, (string) $run->fresh()->status, '⛔ সব সই বন্ধ, আর একজনের চাপেই উৎসব ভাতা খাতায় বসল।');
        $this->assertSame(0, $this->entries($run), '⛔ সই ছাড়া ভাতার দাখিলা বসল।');

        $approval = $this->pendingFor($run);
        $this->assertSame((int) $maker->id, (int) $approval->requested_by);
        $this->assertTrue(app(ApprovalEngine::class)->isSecondHand($approval), '⛔ ভাতার অনুরোধ যন্ত্রের "দ্বিতীয় সই" ছকে নয়।');

        // ── নিজে সই নয় — ইঞ্জিনে, দরজায়, আর দ্বিতীয় "নিশ্চিত"-এ
        $this->assertFalse(app(ApprovalEngine::class)->canDecide($approval->fresh(), $maker->fresh()), '⛔ বানানেওয়ালা নিজের ভাতায় সই দিতে পারেন।');
        $this->as($maker)->post(route('approval.inbox.approve', $approval->id));
        $this->assertSame(Approval::PENDING, (string) $approval->fresh()->status, '⛔ বানানেওয়ালার নিজের সই পড়ল।');
        $this->as($maker)->post(route('hr.payroll.confirm', $run));
        $this->assertSame(DocumentStatus::DRAFT, (string) $run->fresh()->status, '⛔ দ্বিতীয় চাপে ভাতা সই ছাড়া খাতায় বসল।');

        // ── আরেকজন সই দিলেন — এবার খাতায়
        $this->as($second)->post(route('approval.inbox.approve', $approval->id))->assertRedirect();
        $this->assertSame(Approval::APPROVED, (string) $approval->fresh()->status, '⛔ দ্বিতীয় চাবিধারীর সই পড়ল না।');

        $this->as($maker)->post(route('hr.payroll.confirm', $run))->assertSessionHasNoErrors();
        $this->assertSame(DocumentStatus::CONFIRMED, (string) $run->fresh()->status, '⛔ সই পেয়েও ভাতা নিশ্চিত হলো না।');
        $this->assertSame(0, bccomp($this->posted($run, StandardChart::FESTIVAL_BONUS_EXPENSE, 'debit'), self::BASIC, 4), '⛔ ৫২২৬-এ ভাতার খরচ নেই।');
        $this->assertSame(0, bccomp($this->posted($run, StandardChart::SALARY_PAYABLE, 'credit'), self::BASIC, 4), '⛔ প্রদেয় বেতনে ভাতার নিট নেই।');
    }

    public function test_the_owner_as_maker_cannot_sign_his_own_bonus_run(): void
    {
        $run = app(PayrollService::class)->buildBonus('2026-04-10', 'Eid');

        try {
            app(PayrollService::class)->confirm($run->fresh());
            $this->fail('⛔ মালিকের এক চাপেই উৎসব ভাতা খাতায় বসল — দ্বিতীয় সই চাওয়াই হলো না।');
        } catch (HeldForApproval) {
        }

        $own = $this->pendingFor($run);
        $this->assertSame((int) $this->owner->id, (int) $own->requested_by);
        $this->assertFalse(app(ApprovalEngine::class)->canDecide($own->fresh(), $this->owner->fresh()), '⛔ মালিক নিজের ভাতার রানে নিজেই সই।');

        $this->as($this->owner)->post(route('approval.inbox.approve', $own->id));
        $this->assertSame(Approval::PENDING, (string) $own->fresh()->status, '⛔ মালিকের নিজের সই পড়ল।');
        $this->as($this->owner)->post(route('hr.payroll.confirm', $run));
        $this->assertSame(DocumentStatus::DRAFT, (string) $run->fresh()->status, '⛔ মালিকের দ্বিতীয় চাপে ভাতা খাতায় বসল।');
        $this->assertSame(0, $this->entries($run));
    }

    // ── সাহায্যকারী ─────────────────────────────────────────────────────

    private function pendingFor(PayrollRun $run): Approval
    {
        $approval = app(ApprovalEngine::class)->latestFor($run->fresh(), 'payroll');
        $this->assertNotNull($approval, '⛔ ভাতার রানে সইয়ের অনুরোধ বসেনি।');
        $this->assertSame(Approval::PENDING, (string) $approval->status);

        return $approval;
    }

    private function entries(PayrollRun $run): int
    {
        return LedgerEntry::query()->where('source_type', PayrollRun::SOURCE_TYPE)->where('source_id', $run->id)->count();
    }

    private function posted(PayrollRun $run, string $code, string $side): string
    {
        $account = Account::query()->where('code', $code)->firstOrFail();

        return bcadd((string) LedgerEntry::query()->where('source_type', PayrollRun::SOURCE_TYPE)->where('source_id', $run->id)
            ->where('account_id', $account->id)->sum($side), '0', 4);
    }

    /** HR-এর বেতনের কেরানি — রান বানান, নিশ্চিত চান, সই দেওয়ার দরজা আছে; সুপার অ্যাডমিন নন */
    private function payrollClerk(): User
    {
        return $this->secondHandIn((int) $this->company->id,
            'approval.decide', 'hr.employee.view', 'hr.payroll.view', 'hr.payroll.manage', 'hr.salary.view');
    }

    private function as(User $user): static
    {
        $this->app['auth']->forgetGuards();
        FieldSecurity::forget();

        return $this->actingAs($user->fresh());
    }
}
