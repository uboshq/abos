<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\LeaveApplication;
use App\Modules\Hr\Models\LeaveType;
use App\Modules\Hr\Models\MaternityDelivery;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\PayslipLine;
use App\Modules\Hr\Models\SalaryHead;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\LeaveService;
use App\Modules\Hr\Services\MaternityBenefit;
use App\Modules\Hr\Services\SalaryHeadService;
use App\Modules\Hr\Services\SalaryStructureService;
use App\Modules\MasterData\Services\MasterListService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⛔ উৎসব ভাতার রান মাতৃত্বকালীন হিসাবে "বেতনের মাস" নয় — মেশানোর পরের দাবি (শ্রম আইন ধারা ৪৫, ৪৮; a6, ২১ অক্টোবর ২০২৬;
 * [[MaternityBenefit::averageDailyWage()]], [[MaternityDeliveryService::paid()]])।
 *
 * ⓘ ছুটি-শাখা "নিশ্চিত রান" মানেই মাসের বেতন ধরেছিল; পে-শাখা একই টেবিলে উৎসব ভাতার রান আনল (`run_type` bonus)। দুটো একসাথে হলে:
 *  · গড় দৈনিক মজুরিতে ঈদের ভাতা যোগ হত — নোটিশের আগের তিন মাসে ঈদ পড়লে ৬০,০০০-এর জায়গায় ৮০,০০০ ÷ ৭৫;
 *  · ঈদের মাসটা "দেওয়া" ধরে প্রসবের আসল দিনের পার্থক্য এখনই বসত, আর সেই মাসের বেতনের রান নতুন তারিখে আবার দিত — দুইবার;
 *    হাজিরার "ছুটি" সারিও সরত না।
 *
 * ── দাবি ───────────────────────────────────────────────────────────────
 *  · মে–জুলাই বেতন ২০,০০০ করে, জুনে নিশ্চিত উৎসব ভাতা ২০,০০০; ৭৫ দিন উপস্থিত → গড় ঠিক ৮০০ (ভাতা বাদ)।
 *  · আগস্ট–অক্টোবরের বেতন নিশ্চিত, নভেম্বরে কেবল উৎসব ভাতা নিশ্চিত; আসল দিন ২০ সেপ্টেম্বর, শেষ দিন ২০ → ১৪ নভেম্বর:
 *    পার্থক্য ০.০০ (নভেম্বরের বেতন এখনো দেওয়া হয়নি — তার রান নিজে নতুন তারিখে বানাবে), আর ১৫–২০ নভেম্বরের "ছুটি" সারি সরে যায়।
 */
final class TheFestivalBonusIsNotAMaternityMonthTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Employee $mother;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->owner);
        app(MasterListService::class)->installDefaults();
        app(SalaryHeadService::class)->installDefaults();

        $this->mother = app(EmployeeService::class)->create(['code' => 'E-FBM', 'name_en' => 'Mother', 'joining_date' => '2025-01-01',
            'payment_method' => 'cash', 'gender' => Employee::FEMALE, 'surviving_children' => 0])->fresh();
        app(SalaryStructureService::class)->set($this->mother, SalaryHead::query()->where('code', 'BASIC')->firstOrFail(), '2025-01-01', '20000');

        // ⓘ মে–জুলাই বেতন, প্রতি মাসে ২০,০০০; জুনে উৎসব ভাতা ২০,০০০ — নিশ্চিত; ৭৫ দিন উপস্থিত
        foreach (['2026-05-01', '2026-06-01', '2026-07-01'] as $month) {
            $this->confirmedRun($month, PayrollRun::SALARY);
        }
        $this->confirmedRun('2026-06-01', PayrollRun::BONUS);
        $this->present('2026-05-01', 75);
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    public function test_the_average_daily_wage_leaves_the_festival_bonus_out(): void
    {
        $this->travelTo(Carbon::parse('2026-08-01 09:00:00'));
        $leave = $this->approvedLeave();

        $this->assertSame(0, bccomp('800', (string) app(MaternityBenefit::class)->averageDailyWage($leave), 4),
            '⛔ গড় দৈনিক মজুরি ৬০,০০০ ÷ ৭৫ = ৮০০ নয় — উৎসব ভাতা গড়ে ঢুকল।');
    }

    public function test_a_month_with_only_a_festival_bonus_is_not_a_paid_month_for_the_actual_delivery(): void
    {
        $this->travelTo(Carbon::parse('2026-08-01 09:00:00'));
        $leave = $this->approvedLeave();

        foreach (['2026-08-01', '2026-09-01', '2026-10-01'] as $month) {
            $this->confirmedRun($month, PayrollRun::SALARY);
        }
        $this->confirmedRun('2026-11-01', PayrollRun::BONUS);
        $this->assertSame(6, $this->leaveRows($leave, '2026-11-15', '2026-11-20'), 'প্রস্তুতি — নভেম্বরের "ছুটি" সারি নেই।');

        $this->travelTo(Carbon::parse('2026-12-01 09:00:00'));
        $clerk = $this->hr('clerk@bonus-delivery.test');
        $signer = $this->hr('signer@bonus-delivery.test');

        $this->actingAs($clerk)->post(route('hr.leave.delivery', $leave->id), ['actual_delivery_date' => '2026-09-20'])->assertSessionHasNoErrors();
        $change = MaternityDelivery::query()->where('leave_application_id', $leave->id)->sole();
        $this->actingAs($signer)->post(route('hr.leave.delivery_sign', $change->id))->assertSessionHasNoErrors();

        $this->assertSame('2026-11-14', $leave->fresh()->to_date->toDateString(), 'প্রস্তুতি — শেষ দিন সরল না।');
        $this->assertSame(0, bccomp('0', (string) $change->fresh()->adjustment, 2),
            '⛔ কেবল উৎসব ভাতার নভেম্বরকে "দেওয়া মাস" ধরে পার্থক্য বসল — নভেম্বরের বেতনের রান আবার দিলে দুইবার।');
        $this->assertSame(0, $this->leaveRows($leave, '2026-11-15', '2026-11-20'),
            '⛔ নভেম্বরের বেতন এখনো দেওয়া হয়নি, অথচ বাদ পড়া দিনের "ছুটি" সারি রয়ে গেল।');
    }

    /**
     * ⭐ নিয়মটা পাতায় লেখা — "গড় দৈনিক মজুরি: নোটিশের আগের 3 মাসের বেতন রান থেকে; উৎসব বোনাস মজুরি নয় (ধারা 2(45), 48)"
     * (fe-র সিদ্ধান্ত ১, ২১ অক্টোবর ২০২৬): ছুটির তালিকায় মাতৃত্বকালীন আবেদনের সারিতে, আর মাতৃত্বকালীন সুবিধার সারি থাকা বেতনের
     * রানের পাতায়; সেই সারি ছাড়া রানের পাতায় নয়।
     */
    public function test_the_leave_list_and_the_run_page_say_the_festival_bonus_is_not_wages(): void
    {
        $this->travelTo(Carbon::parse('2026-08-01 09:00:00'));
        $leave = $this->approvedLeave();

        $list = $this->actingAs($this->owner)->get(route('hr.leave.index'))->assertOk();
        $rule = __('hr::message.maternity_wage_rule');
        $list->assertSee('data-maternity-wage-rule', false);
        $list->assertSee($rule);
        $this->assertStringContainsString($this->mother->code, $list->getContent(), 'প্রস্তুতি — আবেদনটা তালিকায় নেই।');

        $july = PayrollRun::acrossBranches()->where('document_no', 'PRL-FBM-salary-2026-07-01')->firstOrFail();
        $slip = Payslip::query()->where('payroll_run_id', $july->id)->sole();
        PayslipLine::query()->create(['company_id' => $this->company->id, 'payslip_id' => $slip->id,
            'head_code' => MaternityBenefit::LINE_CODE, 'head_name_en' => 'Maternity benefit', 'head_name_bn' => 'মাতৃত্বকালীন সুবিধা',
            'kind' => SalaryHead::EARNING, 'amount' => '800', 'sort_order' => 900]);

        $page = $this->actingAs($this->owner)->get(route('hr.payroll.show', $july->id))->assertOk();
        $page->assertSee('data-maternity-wage-rule', false);
        $page->assertSee(__('hr::message.maternity_wage_rule'));

        $june = PayrollRun::acrossBranches()->where('document_no', 'PRL-FBM-salary-2026-06-01')->firstOrFail();
        $this->actingAs($this->owner)->get(route('hr.payroll.show', $june->id))->assertOk()
            ->assertDontSee('data-maternity-wage-rule', false);
    }

    private function approvedLeave(): LeaveApplication
    {
        $maternity = LeaveType::query()->where('code', LeaveType::MATERNITY)->firstOrFail();

        return app(LeaveService::class)->approve(
            app(LeaveService::class)->apply($this->mother, $maternity, '2026-08-01', '2026-11-20', '112', null, '2026-09-26'), $this->owner);
    }

    private function confirmedRun(string $month, string $type): void
    {
        $run = PayrollRun::acrossBranches()->create(['company_id' => $this->company->id, 'branch_id' => $this->company->defaultBranch()?->id,
            'document_no' => 'PRL-FBM-'.$type.'-'.$month, 'run_type' => $type, 'month' => $month,
            'trx_date' => Carbon::parse($month)->endOfMonth()->toDateString()]);
        $run->forceFill(['status' => DocumentStatus::CONFIRMED])->save();
        Payslip::query()->create(['company_id' => $this->company->id, 'payroll_run_id' => $run->id, 'employee_id' => $this->mother->id,
            'gross' => '20000', 'deductions' => '0', 'net' => '20000', 'payment_method' => 'cash']);
    }

    private function leaveRows(LeaveApplication $leave, string $from, string $to): int
    {
        return Attendance::query()->where('leave_application_id', $leave->id)->whereBetween('work_date', [$from, $to])->count();
    }

    private function hr(string $email): User
    {
        $user = User::factory()->create(['email' => $email, 'is_active' => true, 'current_company_id' => $this->company->id]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);
        CompanyContext::forCompany($this->company->id, function () use ($user): void {
            foreach (['hr.leave.view', 'hr.leave.manage', 'hr.leave.approve'] as $key) {
                $user->givePermissionTo(Permission::findOrCreate($key, 'web'));
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    private function present(string $from, int $count): void
    {
        $day = Carbon::parse($from);
        $batch = [];

        for ($i = 0; $i < $count; $i++, $day->addDay()) {
            $batch[] = ['public_id' => (string) Str::uuid(), 'company_id' => $this->company->id, 'employee_id' => $this->mother->id,
                'work_date' => $day->toDateString(), 'status' => Attendance::PRESENT, 'is_late' => false, 'created_at' => now(), 'updated_at' => now()];
        }

        DB::table('hr_attendance')->insert($batch);
    }
}
