<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Security\FieldSecurity;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\ApprovalFlow;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\FinalSettlement;
use App\Modules\Hr\Models\SalaryHead;
use App\Modules\Hr\Services\EarnedLeave;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\FinalSettlements;
use App\Modules\Hr\Services\Gratuity;
use App\Modules\Hr\Services\PayrollService;
use App\Modules\Hr\Services\SalaryHeadService;
use App\Modules\Hr\Services\SalaryStructureService;
use App\Modules\MasterData\Services\MasterListService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\SignsMoneyOff;
use Tests\SignsTheSecondHand;
use Tests\TestCase;

/**
 * ⭐ চাকরি ছাড়া কর্মীর চূড়ান্ত পাওনা — এক কাগজ, মালিকের অনুমোদনে (মালিক, ২১ অক্টোবর ২০২৬: "plan onujayi koro"; [[FinalSettlements]])।
 *
 * দাবি (কর্মী 2023-03-15 যোগ, মূল 30000, জুন পর্যন্ত বেতন নিশ্চিত, শেষ দিন 2026-07-20, অগ্রিম বাকি 5000):
 *  · বকেয়া মজুরি = 1–20 জুলাই, 20 দিন × 30000 ÷ 30 = 20000.00; গ্র্যাচুইটি 3 বছর × 30 দিন = 90000.00; ছুটির টাকা = [[EarnedLeave::encashment()]];
 *    অগ্রিম 5000 কাটা; নিট = মোট − 5000।
 *  · কাগজ বানালে খাতায় কিছু বসে না; যিনি বানালেন তিনি অনুমোদন দিতে পারেন না, বেতনের রানের চাবিধারী ব্যবস্থাপকও নন — কেবল মালিক;
 *    সব ছক বন্ধ কোম্পানিতেও।
 *  · মালিকের অনুমোদনে: Dr 5201 মজুরি+ছুটি, Dr 2134 গ্র্যাচুইটি, Cr 1131 অগ্রিম (কর্মীর নামে), Cr 2130 নিট (কর্মীর নামে) — সমান দুই পাশ।
 *  · "না" হলে খাতায় কিছু নয়; শেষ দিন না থাকলে বা খোলা কাগজ থাকলে নতুন কাগজ হয় না।
 *  · পরের মাসের গ্র্যাচুইটির সঞ্চিতি মেটানো দায় আবার ছাড়ে না — 2134 শূন্যেই থাকে।
 *  · তহবিলের প্রাপ্যতার ধাপ না থাকলে প্রতিষ্ঠানের ভাগ দেওয়া যায় না — কাগজ থামে।
 */
final class ALeaverIsSettledOnOnePaperTest extends TestCase
{
    use \Tests\Concerns\OpensTheYears;
    use RefreshDatabase;
    use \Tests\Concerns\SeesTheOwnersOwnMark;
    use SignsMoneyOff;
    use SignsTheSecondHand;

    private Company $company;

    private User $owner;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->openTheYearsOf('2026-06-30');
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        app(StandardChart::class)->install();
        app(MasterListService::class)->installDefaults();
        app(SalaryHeadService::class)->installDefaults();
        FieldSecurity::forget();
        Employee::query()->delete();
        $this->travelTo('2026-07-25 10:00:00');

        app(SettingsService::class)->set(Gratuity::ENABLED, true);
        app(SettingsService::class)->set('hr.pf.enabled', false);

        $this->employee = app(EmployeeService::class)->create([
            'code' => 'FS-1', 'name_en' => 'Leaving Person', 'joining_date' => '2023-03-15', 'payment_method' => 'cash',
        ]);
        app(SalaryStructureService::class)->set($this->employee, SalaryHead::query()->where('code', 'BASIC')->firstOrFail(), '2023-03-15', '30000');

        $this->confirmedPayroll(app(PayrollService::class)->build('2026-06-01'));

        // ⓘ জুনের বেতনের পরে নেওয়া অগ্রিম — বেতন থেকে কাটা হয়নি, তাই শেষ দিনে বাকি
        app(PostingEngine::class)->post(sourceType: 'journal_voucher', sourceId: random_int(1, 9_999_999), trxDate: '2026-07-05', lines: [
            ['account_id' => $this->id(StandardChart::EMPLOYEE_ADVANCE), 'debit' => '5000',
                'party_type' => Employee::drillSourceType(), 'party_id' => $this->employee->id],
            ['account_id' => $this->cash(), 'credit' => '5000'],
        ]);

        $this->employee->forceFill(['leaving_date' => '2026-07-20'])->save();
        $this->employee = $this->employee->fresh();
    }

    public function test_the_paper_counts_each_due_to_the_last_day(): void
    {
        $f = app(FinalSettlements::class)->compute($this->employee);
        $leave = app(EarnedLeave::class)->encashment($this->employee, Carbon::parse('2026-07-20'));

        $this->assertSame('2026-07-01', $f['details']['unpaid_from'], '⛔ বকেয়া মজুরি শেষ নিশ্চিত মাসের পর থেকে নয়');
        $this->assertSame(20, $f['details']['wage_days']);
        $this->assertSame(0, bccomp($f['wages'], '20000.00', 2), '⛔ 20 দিন × 30000 ÷ 30 নয়');
        $this->assertSame(0, bccomp($f['gratuity'], '90000.00', 2), '⛔ 3 বছর × 30 দিনের মূল নয়');
        $this->assertSame(0, bccomp($f['leave_encashment'], $leave['amount'], 2), '⛔ ছুটির টাকা ধারা 11-এর হিসাব নয়');
        $this->assertSame(0, bccomp($f['advance_recovered'], '5000', 2), '⛔ বাকি অগ্রিম কাটা হয়নি');
        $this->assertSame(0, bccomp($f['net'], bcsub($f['earnings'], '5000', 2), 2));
    }

    public function test_only_the_owner_approves_even_with_every_approval_off_and_then_it_is_booked(): void
    {
        $this->switchEveryApprovalOff();

        $clerk = $this->secondHandIn($this->company->id, 'hr.salary.view', 'hr.final_settlement.manage', 'approval.decide');
        $manager = $this->secondHandIn($this->company->id, 'hr.salary.view', 'hr.payroll.manage', 'hr.salary.manage', 'approval.decide');

        $this->actingAs($clerk)->post(route('hr.final_settlement.store'), [
            'employee_id' => $this->employee->id, 'separation' => 'resignation', 'note' => 'Resigned',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $settlement = FinalSettlement::query()->latest('id')->firstOrFail();
        $this->assertSame(FinalSettlement::PENDING, $settlement->status);
        $this->assertSame(0, LedgerEntry::query()->where('source_type', FinalSettlements::SOURCE_TYPE)->count(), '⛔ অনুমোদনের আগেই খাতায় বসল');

        $service = app(FinalSettlements::class);
        $this->assertFalse($service->canDecide($settlement, $clerk), '⛔ যিনি বানালেন তিনিই অনুমোদন দিতে পারেন');
        $this->assertFalse($service->canDecide($settlement, $manager), '⛔ মালিক ছাড়া আরেকজন অনুমোদন দিতে পারেন');
        $this->actingAs($manager)->post(route('hr.final_settlement.approve', $settlement))->assertSessionHasErrors('settlement');
        $this->assertSame(FinalSettlement::PENDING, $settlement->fresh()->status);

        $this->assertTrue($service->canDecide($settlement, $this->owner), '⛔ মালিক অনুমোদন দিতে পারেন না');
        $this->actingAs($this->owner)->post(route('hr.final_settlement.approve', $settlement))->assertSessionHasNoErrors();

        $settlement = $settlement->fresh();
        $this->assertSame(FinalSettlement::APPROVED, $settlement->status, '⛔ অনুমোদনের পরেও কাগজ অপেক্ষায়');

        $lines = LedgerEntry::query()->where('source_type', FinalSettlements::SOURCE_TYPE)->where('source_id', $settlement->id)->get();
        $this->assertSame(0, bccomp((string) $lines->sum('debit'), (string) $lines->sum('credit'), 2), '⛔ দুই পাশ সমান নয়');
        $at = fn (string $code, string $side) => (string) $lines->where('account_id', $this->id($code))->sum($side);
        $this->assertSame(0, bccomp($at(StandardChart::SALARY_EXPENSE, 'debit'), bcadd((string) $settlement->wages, (string) $settlement->leave_encashment, 2), 2));
        $this->assertSame(0, bccomp($at(StandardChart::GRATUITY_LIABILITY, 'debit'), '90000', 2), '⛔ গ্র্যাচুইটি দায় থেকে মেটেনি');
        $this->assertSame(0, bccomp($at(StandardChart::EMPLOYEE_ADVANCE, 'credit'), '5000', 2), '⛔ অগ্রিম কাটা খাতায় নেই');
        $this->assertSame(0, bccomp($at(StandardChart::SALARY_PAYABLE, 'credit'), (string) $settlement->net, 2), '⛔ নিট প্রদেয় নয়');

        // ⭐ খাতার সারি কাগজটা খোলে (মালিকের লিংকের নিয়ম, ২৩ নভেম্বর ২০২৬)
        $target = app(\App\Core\Engines\Drill\DrillResolver::class)->describe(FinalSettlements::SOURCE_TYPE, $settlement->id);
        $this->assertTrue($target['resolved'], '⛔ খাতার সারি থেকে চূড়ান্ত পাওনার কাগজ মেলে না');
        $this->get(route($target['route'][0], $target['route'][1]))->assertOk()->assertSee('data-settlement-head', false);

        $payable = $lines->firstWhere('account_id', $this->id(StandardChart::SALARY_PAYABLE));
        // ⓘ পুঞ্জ খাত পক্ষ রাখে না — কর্মী বিবরণে; অগ্রিমের সারি কর্মীর নামে
        $this->assertStringContainsString($this->employee->name(), (string) $payable->narration, '⛔ প্রদেয়ের সারিতে কর্মীর নাম নেই');
        $this->assertSame((int) $this->employee->id, (int) $lines->firstWhere('account_id', $this->id(StandardChart::EMPLOYEE_ADVANCE))->party_id);
    }

    public function test_the_owner_settles_his_own_paper_with_the_mark_and_no_figure_in_the_report(): void
    {
        $service = app(FinalSettlements::class);
        $settlement = $service->prepare($this->employee, 'resignation', null, $this->owner);
        $this->actingAs($this->owner)->post(route('hr.final_settlement.approve', $settlement))->assertSessionHasNoErrors();

        $this->assertSame(FinalSettlement::APPROVED, $settlement->fresh()->status);
        $this->assertOwnerSelfMarked($service->approvalOf($settlement->fresh()));
        $this->get(route('hr.final_settlement.show', $settlement))->assertOk()->assertSee('data-owner-self-mark', false);
        $this->get(route('accounts.report.show', ['slug' => 'owner-self-approved']))->assertOk()
            ->assertDontSee(\App\Core\Support\Money::format((string) $settlement->net));
    }

    public function test_a_rejection_books_nothing_and_a_second_paper_waits_for_the_first(): void
    {
        $service = app(FinalSettlements::class);
        $clerk = $this->secondHandIn($this->company->id, 'hr.salary.view', 'hr.final_settlement.manage');
        $settlement = $service->prepare($this->employee, 'termination', null, $clerk);

        try {
            $service->prepare($this->employee, 'termination', null, $clerk);
            $this->fail('⛔ একই কর্মীর দ্বিতীয় খোলা কাগজ হলো');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('employee', $e->errors());
        }

        $service->reject($settlement, $this->owner, 'Figures to be checked again');
        $this->assertSame(FinalSettlement::REJECTED, $settlement->fresh()->status);
        $this->assertSame(0, LedgerEntry::query()->where('source_type', FinalSettlements::SOURCE_TYPE)->count(), '⛔ প্রত্যাখ্যাত কাগজ খাতায় বসল');

        // ⓘ প্রত্যাখ্যানের পরে নতুন কাগজ বানানো যায়
        $this->assertSame(FinalSettlement::PENDING, $service->prepare($this->employee, 'termination', null, $clerk)->status);
    }

    public function test_no_leaving_date_means_no_paper(): void
    {
        $this->employee->forceFill(['leaving_date' => null])->save();

        $this->expectException(ValidationException::class);
        app(FinalSettlements::class)->prepare($this->employee->fresh(), 'resignation', null, $this->owner);
    }

    public function test_the_next_gratuity_true_up_does_not_release_the_settled_gratuity_again(): void
    {
        $gratuity = app(Gratuity::class);
        $gratuity->accrue(Carbon::parse('2026-06-01'));
        $this->assertSame(0, bccomp($this->liability(), '90000', 2), 'প্রস্তুতিটাই ভুল — জুনের দায় 90000 নয়');

        $service = app(FinalSettlements::class);
        $service->approve($service->prepare($this->employee, 'resignation', null, $this->secondHandIn($this->company->id, 'hr.salary.view', 'hr.final_settlement.manage')), $this->owner);
        $this->assertSame(0, bccomp($this->liability(), '0', 2), '⛔ মেটানোর পরে গ্র্যাচুইটির দায় শূন্য নয়');

        $gratuity->accrue(Carbon::parse('2026-07-01'));
        $this->assertSame(0, bccomp($this->liability(), '0', 2), '⛔ পরের মাসের সঞ্চিতি মেটানো দায় আবার ছাড়ল');
    }

    public function test_without_the_funds_vesting_steps_the_employer_share_is_not_paid(): void
    {
        $fund = app(\App\Modules\Hr\Services\ProvidentFund::class);
        app(SettingsService::class)->set(\App\Modules\Hr\Services\ProvidentFund::VESTING_STEPS, '');

        $this->assertNull($fund->vestedEmployerShare('1000', 5), '⛔ ধাপ না থাকলেও প্রতিষ্ঠানের ভাগ গোনা হলো');
        $this->assertSame('500.00', \App\Modules\Hr\Services\ProvidentFund::vest('1000', 5, [['years' => 3, 'percent' => '50']]));
    }

    public function test_the_pages_need_the_salary_key(): void
    {
        $this->get(route('hr.final_settlement.index'))->assertOk()->assertSee('data-settlement-leavers', false);
        $this->get(route('hr.final_settlement.preview', ['employee' => $this->employee->id]))->assertOk()->assertSee('data-settlement-net', false);

        $clerk = $this->secondHandIn($this->company->id, 'hr.employee.view');
        $this->actingAs($clerk)->get(route('hr.final_settlement.index'))->assertForbidden();
    }

    /** ⓘ UB-র মতো — ছক আছে, একটাও চালু নয় ([[APayrollRunNeedsASecondHandEvenWithApprovalsOffTest]]-এর একই) */
    private function switchEveryApprovalOff(): void
    {
        ApprovalFlow::query()->update(['is_active' => false]);
        // ⓘ the demo already has this row since 1.3.10 — make it exist and stay off, never a second copy (unique flow scope)
        ApprovalFlow::query()->updateOrCreate(['company_id' => $this->company->id, 'module' => 'sales', 'action' => 'discount',
            'document_type' => ''], ['threshold_amount' => null, 'is_active' => false]);
        $this->forgetTheEnginesFlows();
    }

    private function id(string $code): int
    {
        return (int) Account::query()->where('code', $code)->value('id');
    }

    /** ⓘ টিল বসলে 1101 দল — টাকা তখন তার নিচের খাতে (ডেমোর বীজ টিল বসায়) */
    private function cash(): int
    {
        $cash = Account::query()->where('code', StandardChart::CASH_IN_HAND)->firstOrFail();

        return $cash->is_group
            ? (int) Account::query()->where('parent_id', $cash->id)->where('is_group', false)->orderBy('id')->value('id')
            : (int) $cash->id;
    }

    /** ⓘ 2134-এর জের — ক্রেডিট − ডেবিট */
    private function liability(): string
    {
        $id = $this->id(StandardChart::GRATUITY_LIABILITY);

        return bcsub(bcadd((string) LedgerEntry::query()->where('account_id', $id)->sum('credit'), '0', 4),
            bcadd((string) LedgerEntry::query()->where('account_id', $id)->sum('debit'), '0', 4), 4);
    }
}
