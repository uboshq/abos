<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\User;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\LeaveApplication;
use App\Modules\Hr\Models\LeaveType;
use App\Modules\Hr\Models\MaternityDelivery;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\SalaryHead;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\LeaveService;
use App\Modules\Hr\Services\MaternityBenefit;
use App\Modules\Hr\Services\PayrollService;
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
 * ⭐ প্রসবের আসল দিন — পরের ৮ সপ্তাহ সেদিন থেকে, "সই দিন"-এর পরে; দেওয়া মাসের পার্থক্য পরের রানে (fe-র উত্তর ৩, ২১ অক্টোবর ২০২৬;
 * শ্রম আইন ধারা ৪৫, ৪৭; [[MaternityDeliveryService]]; a6)।
 *
 * ── দাবি ───────────────────────────────────────────────────────────────
 *  · আসল দিন লিখলে (২০ সেপ্টেম্বর, সম্ভাব্য ছিল ২৬) কিছুই সরে না — "সইয়ের অপেক্ষায়"; যিনি লিখেছেন তিনি সই দিতে পারেন না।
 *  · আরেকজন "সই দিন" দিলে শেষ দিন ২০ → ১৪ নভেম্বর, আর অডিটে আগের → নতুন তারিখ।
 *  · নভেম্বরের রান আগেই নিশ্চিত — তার প্রতিটা অঙ্ক আর হাজিরার সারি অক্ষত; পার্থক্য −৬ দিন × (৮০০ − ২০,০০০ ÷ ৩০) = −৮০০.০০।
 *  · ডিসেম্বরের রানে ঠিক ৮০০.০০ কর্তন — আবার-বানালেও একবারই।
 */
final class TheActualDeliveryMovesTheWeeksAfterTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    public function test_the_end_moves_only_after_signing_and_the_next_run_carries_the_exact_difference(): void
    {
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->owner);
        app(MasterListService::class)->installDefaults();
        app(SalaryHeadService::class)->installDefaults();

        $mother = app(EmployeeService::class)->create(['code' => 'E-DLV', 'name_en' => 'Mother', 'joining_date' => '2025-01-01',
            'payment_method' => 'cash', 'gender' => Employee::FEMALE, 'surviving_children' => 0])->fresh();
        app(SalaryStructureService::class)->set($mother, SalaryHead::query()->where('code', 'BASIC')->firstOrFail(), '2025-01-01', '20000');

        // ⓘ মে–জুলাই নিশ্চিত, প্রতি মাসে ২০,০০০; ৭৫ দিন উপস্থিত — গড় ৮০০
        foreach (['2026-05-01', '2026-06-01', '2026-07-01'] as $i => $month) {
            $run = PayrollRun::acrossBranches()->create(['company_id' => $this->company->id, 'branch_id' => $this->company->defaultBranch()?->id,
                'document_no' => 'PRL-DL-'.$i, 'month' => $month, 'trx_date' => Carbon::parse($month)->endOfMonth()->toDateString()]);
            $run->forceFill(['status' => DocumentStatus::CONFIRMED])->save();
            Payslip::query()->create(['company_id' => $this->company->id, 'payroll_run_id' => $run->id, 'employee_id' => $mother->id,
                'gross' => '20000', 'deductions' => '0', 'net' => '20000', 'payment_method' => 'cash']);
        }
        $this->present($mother, '2026-05-01', 75);

        $this->travelTo(Carbon::parse('2026-08-01 09:00:00'));
        $maternity = LeaveType::query()->where('code', LeaveType::MATERNITY)->firstOrFail();
        $leave = app(LeaveService::class)->approve(
            app(LeaveService::class)->apply($mother, $maternity, '2026-08-01', '2026-11-20', '112', null, '2026-09-26'), $this->owner);

        // ⓘ আগস্ট–নভেম্বর নিশ্চিত
        foreach (['2026-08-01', '2026-09-01', '2026-10-01', '2026-11-01'] as $month) {
            app(PayrollService::class)->build($month)->forceFill(['status' => DocumentStatus::CONFIRMED])->save();
        }

        $november = PayrollRun::acrossBranches()->whereDate('month', '2026-11-01')->firstOrFail();
        $novSlip = fn () => $november->payslips()->where('employee_id', $mother->id)->with('lines')->firstOrFail();
        $before = [(string) $novSlip()->gross, (string) $novSlip()->net, $novSlip()->lines->pluck('amount', 'head_code')->map(fn ($a) => (string) $a)->all()];
        // ⓘ নভেম্বর: ২০ দিন সুবিধা ১৬,০০০ + কাঠামোর ১০ দিন ৬,৬৬৬.৬৭
        $this->assertSame(0, bccomp('22666.67', $before[0], 2));

        $this->travelTo(Carbon::parse('2026-12-01 09:00:00'));
        $clerk = $this->hr('clerk@delivery.test');
        $signer = $this->hr('signer@delivery.test');

        $this->actingAs($clerk)->post(route('hr.leave.delivery', $leave->id), ['actual_delivery_date' => '2026-09-20'])->assertSessionHasNoErrors();
        $change = MaternityDelivery::query()->where('leave_application_id', $leave->id)->sole();
        $this->assertSame(MaternityDelivery::PENDING, $change->status);
        $this->assertSame('2026-11-20', $leave->fresh()->to_date->toDateString(), '⛔ সই ছাড়াই ছুটির শেষ দিন সরল');
        $this->assertSame('2026-11-14', $change->new_to_date->toDateString());

        // ⛔ যিনি লিখেছেন তিনি সই দেন না
        $this->actingAs($clerk)->post(route('hr.leave.delivery_sign', $change->id))->assertSessionHasErrors('status');
        $this->assertSame(MaternityDelivery::PENDING, $change->fresh()->status);

        $this->actingAs($signer)->post(route('hr.leave.delivery_sign', $change->id))->assertSessionHasNoErrors();
        $leave = $leave->fresh();
        $this->assertSame('2026-11-14', $leave->to_date->toDateString());
        $this->assertSame('2026-09-20', $leave->actual_delivery_date->toDateString());
        $this->assertSame(0, bccomp('106', (string) $leave->days, 1));

        $trail = AuditTrail::query()->forRecord(LeaveApplication::class, (int) $leave->id)->where('action', AuditTrail::UPDATED)
            ->whereHas('changes', fn ($q) => $q->where('field', 'to_date'))->with('changes')->latest('id')->firstOrFail();
        $toChange = $trail->changes->firstWhere('field', 'to_date');
        $this->assertStringStartsWith('2026-11-20', (string) $toChange->old_value, '⛔ অডিটে আগের শেষ দিন নেই');
        $this->assertStringStartsWith('2026-11-14', (string) $toChange->new_value, '⛔ অডিটে নতুন শেষ দিন নেই');

        // ⛔ দেওয়া নভেম্বর অক্ষত — অঙ্ক আর হাজিরার সারি
        $this->assertSame($before, [(string) $novSlip()->gross, (string) $novSlip()->net, $novSlip()->lines->pluck('amount', 'head_code')->map(fn ($a) => (string) $a)->all()],
            '⛔ নিশ্চিত নভেম্বরের বেতনশিট বদলাল');
        $this->assertSame(6, Attendance::query()->where('leave_application_id', $leave->id)->whereBetween('work_date', ['2026-11-15', '2026-11-20'])->count(),
            '⛔ দেওয়া মাসের হাজিরা বদলাল');

        // ⓘ −৬ × (৮০০ − ৬৬৬.৬৬৬৬…) = −৮০০.০০
        $this->assertSame('-800.00', (string) $change->fresh()->adjustment);

        $december = app(PayrollService::class)->build('2026-12-01');
        app(PayrollService::class)->rebuild($december->fresh());
        $lines = $december->fresh()->payslips()->where('employee_id', $mother->id)->firstOrFail()->lines()->where('head_code', MaternityBenefit::ADJUSTMENT_CODE)->get();
        $this->assertCount(1, $lines, '⛔ সমন্বয় নেই, বা আবার-বানানোয় দুইবার');
        $this->assertSame('deduction', $lines->first()->kind);
        $this->assertSame(0, bccomp('800', (string) $lines->first()->amount, 2), '⛔ পরের রানে পার্থক্য পয়সায় মেলে না');
        $this->assertSame((int) $december->id, (int) $change->fresh()->payroll_run_id);

        // ⓘ ডিসেম্বর নিশ্চিত হলে জানুয়ারিতে আবার নয় — পার্থক্য একবারই মেটে
        $december->fresh()->forceFill(['status' => DocumentStatus::CONFIRMED])->save();
        $january = app(PayrollService::class)->build('2027-01-01');
        $this->assertSame(0, $january->payslips()->where('employee_id', $mother->id)->firstOrFail()->lines()
            ->where('head_code', MaternityBenefit::ADJUSTMENT_CODE)->count(), '⛔ মিটে যাওয়া পার্থক্য পরের মাসে আবার কাটল');
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

    private function present(Employee $employee, string $from, int $count): void
    {
        $day = Carbon::parse($from);
        $batch = [];

        for ($i = 0; $i < $count; $i++, $day->addDay()) {
            $batch[] = ['public_id' => (string) Str::uuid(), 'company_id' => $this->company->id, 'employee_id' => $employee->id,
                'work_date' => $day->toDateString(), 'status' => Attendance::PRESENT, 'is_late' => false, 'created_at' => now(), 'updated_at' => now()];
        }

        DB::table('hr_attendance')->insert($batch);
    }
}
