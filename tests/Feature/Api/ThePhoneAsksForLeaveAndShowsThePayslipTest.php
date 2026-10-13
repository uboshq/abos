<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Services\PhoneModules;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Http\Controllers\Api\AuthController;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\LeaveApplication;
use App\Modules\Hr\Models\LeaveType;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\PayslipLine;
use App\Modules\Hr\Models\SalaryHead;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ ফোনে নিজের ছুটি আর নিজের বেতনের স্লিপ (মালিকের আদেশ, ১৩ অক্টোবর ২০২৬; [[LeaveApiController]], [[PayslipApiController]])।
 *
 * দাবি:
 *  - আবেদন বসে নিজের নামে, ওয়েবের একই সেবায় — অপেক্ষায়; মেলে-যাওয়া দিন আর বছরের সীমার বেশি ফেরে (৪২২); অন্যের আবেদন তালিকায় নেই।
 *  - কর্মীর খাতা না থাকলে আবেদন ফেরে, কারণসহ।
 *  - স্লিপ কেবল নিজের আর পাকা রানের — খসড়া রানের নয়, অন্যেরটা ৪০৪; অঙ্ক স্লিপের নিজের সারি।
 *  - চাবি ছাড়া ৪০৩; যে ভূমিকা নিজের হাজিরা দেয় সে নতুন দুই চাবি পায় (তথ্য-ধাপ)।
 */
final class ThePhoneAsksForLeaveAndShowsThePayslipTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $me;

    private Employee $mine;

    private Employee $someoneElse;

    private LeaveType $annual;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(SettingsService::class)->set(PhoneModules::key('hr'), true);

        $this->me = $this->staff(['hr.leave.self', 'hr.payslip.self']);
        $this->mine = $this->employee('PH-1', $this->me);
        $this->someoneElse = $this->employee('PH-2', null);

        $this->annual = LeaveType::query()->create([
            'code' => 'PH-AL', 'name_en' => 'Annual', 'name_bn' => 'বার্ষিক', 'days_per_year' => 5, 'is_paid' => true, 'is_active' => true,
        ]);
    }

    public function test_leave_is_asked_in_ones_own_name_and_waits_for_approval(): void
    {
        $from = Carbon::today()->addDays(10)->toDateString();
        $to = Carbon::today()->addDays(11)->toDateString();

        $sent = $this->phone($this->me)->postJson('/api/v1/hr/leave', [
            'leave_type' => $this->annual->public_id, 'from_date' => $from, 'to_date' => $to, 'days' => 2, 'reason' => 'বিয়ে',
            // ⛔ বিপজ্জনক: অন্যের কর্মী-id পাঠানো — উপেক্ষিত হতে হবে
            'employee_id' => $this->someoneElse->id,
        ])->assertCreated()->json();

        $this->assertSame(LeaveApplication::PENDING, $sent['status']);
        $row = LeaveApplication::query()->where('public_id', $sent['id'])->firstOrFail();
        $this->assertSame($this->mine->id, (int) $row->employee_id, '⛔ আবেদন নিজের নামে বসেনি।');

        // ⓘ একই দিনে আবার — ওয়েবের সেবার "মেলে গেছে"
        $this->phone($this->me)->postJson('/api/v1/hr/leave', [
            'leave_type' => $this->annual->public_id, 'from_date' => $to, 'to_date' => $to, 'days' => 1,
        ])->assertStatus(422);

        // ⓘ বছরের সীমার বেশি
        $this->phone($this->me)->postJson('/api/v1/hr/leave', [
            'leave_type' => $this->annual->public_id, 'from_date' => Carbon::today()->addDays(30)->toDateString(),
            'to_date' => Carbon::today()->addDays(38)->toDateString(), 'days' => 9,
        ])->assertStatus(422);

        LeaveApplication::query()->create([
            'employee_id' => $this->someoneElse->id, 'leave_type_id' => $this->annual->id,
            'from_date' => $from, 'to_date' => $from, 'days' => 1, 'status' => LeaveApplication::PENDING,
        ]);

        $body = $this->phone($this->me)->getJson('/api/v1/hr/leave')->assertOk()->json();
        $this->assertTrue($body['employee']);
        $this->assertSame([$sent['id']], array_column($body['applications'], 'id'), '⛔ অন্যের আবেদন নিজের তালিকায়।');
        $type = collect($body['types'])->firstWhere('id', $this->annual->public_id);
        $this->assertSame('5.0', (string) $type['left'], 'মঞ্জুর হয়নি, তাই বাকি কমেনি — ওয়েবের balance()-এর নিয়ম।');
    }

    public function test_with_no_employee_record_the_leave_is_refused_with_its_reason(): void
    {
        $loner = $this->staff(['hr.leave.self']);

        $this->phone($loner)->getJson('/api/v1/hr/leave')->assertOk()->assertJsonPath('employee', false);
        $this->phone($loner)->postJson('/api/v1/hr/leave', [
            'leave_type' => $this->annual->public_id, 'from_date' => Carbon::today()->toDateString(),
            'to_date' => Carbon::today()->toDateString(), 'days' => 1,
        ])->assertStatus(422)->assertJsonValidationErrors('employee');
    }

    public function test_only_ones_own_payslips_of_a_posted_run_are_shown(): void
    {
        // ⓘ রানটা অন্য শাখার — নিজের স্লিপে শাখার দেয়াল খাটে না
        $other = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)
            ->where('id', '!=', CompanyContext::branchId())->orderBy('id')->firstOrFail();
        $posted = $this->payrollRun('PAY-PH-09', '2026-09-01', DocumentStatus::CONFIRMED, $other);
        $draft = $this->payrollRun('PAY-PH-10', '2026-10-01', DocumentStatus::DRAFT, $other);

        $slip = $this->slip($posted, $this->mine, '30000', '2000');
        $this->slip($draft, $this->mine, '31000', '2000');
        $theirs = $this->slip($posted, $this->someoneElse, '50000', '0');

        $rows = $this->phone($this->me)->getJson('/api/v1/hr/payslips')->assertOk()->json('rows');
        $this->assertSame([$slip->public_id], array_column($rows, 'id'), '⛔ খসড়া রানের বা অন্যের স্লিপ ফোনে।');
        $this->assertSame(0, bccomp((string) $rows[0]['net'], '28000', 2));

        $one = $this->phone($this->me)->getJson('/api/v1/hr/payslips/'.$slip->public_id)->assertOk()->json();
        $this->assertCount(2, $one['lines']);
        $this->assertSame(0, bccomp((string) $one['gross'], '30000', 2));

        $this->phone($this->me)->getJson('/api/v1/hr/payslips/'.$theirs->public_id)->assertNotFound();
        $this->phone($this->me)->getJson('/api/v1/hr/payslips/'.$slip->id)->assertNotFound();
    }

    public function test_without_the_self_keys_both_doors_are_shut(): void
    {
        $plain = $this->staff([]);
        $this->employee('PH-3', $plain);

        $this->phone($plain)->getJson('/api/v1/hr/leave')->assertForbidden();
        $this->phone($plain)->getJson('/api/v1/hr/payslips')->assertForbidden();
    }

    public function test_a_role_that_marks_its_own_attendance_gets_the_two_self_keys(): void
    {
        $role = CompanyContext::forCompany($this->company->id, function () {
            $role = Role::findOrCreate('PH Field '.uniqid(), 'web');
            $role->givePermissionTo(Permission::findOrCreate('hr.attendance.self', 'web'));

            return $role;
        });

        $migration = require base_path('app/Modules/Hr/Database/Migrations/2027_02_24_100000_whoever_marks_their_own_attendance_may_ask_for_leave.php');
        $migration->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $keys = $role->fresh()->permissions->pluck('name')->all();
        $this->assertContains('hr.leave.self', $keys);
        $this->assertContains('hr.payslip.self', $keys);
        $this->assertNotContains('hr.leave.manage', $keys, '⛔ অন্যের নামে ছুটি বসানোর চাবি দেওয়া হলো।');
    }

    /** @param list<string> $keys */
    private function staff(array $keys): User
    {
        $user = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);
        CompanyContext::forCompany($this->company->id, function () use ($user, $keys): void {
            foreach ($keys as $key) {
                $user->givePermissionTo(Permission::findOrCreate($key, 'web'));
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    private function employee(string $code, ?User $user): Employee
    {
        $employee = Employee::query()->create([
            'branch_id' => CompanyContext::branchId(), 'code' => $code, 'name_en' => 'Person '.$code, 'joining_date' => '2026-01-01',
        ]);
        $employee->forceFill(['user_id' => $user?->id])->save();

        return $employee;
    }

    private function payrollRun(string $no, string $month, string $status, Branch $branch): PayrollRun
    {
        $run = PayrollRun::acrossBranches()->create([
            'company_id' => $this->company->id, 'branch_id' => $branch->id, 'document_no' => $no,
            'month' => $month, 'trx_date' => Carbon::parse($month)->endOfMonth()->toDateString(),
        ]);
        $run->forceFill(['status' => $status])->save();

        return $run;
    }

    private function slip(PayrollRun $run, Employee $employee, string $gross, string $cut): Payslip
    {
        $slip = Payslip::query()->create([
            'company_id' => $this->company->id, 'payroll_run_id' => $run->id, 'employee_id' => $employee->id,
            'gross' => $gross, 'deductions' => $cut, 'net' => bcsub($gross, $cut, 4), 'payment_method' => 'cash',
        ]);
        PayslipLine::query()->create(['payslip_id' => $slip->id, 'head_code' => 'BASIC', 'head_name_en' => 'Basic',
            'head_name_bn' => 'মূল বেতন', 'kind' => SalaryHead::EARNING, 'amount' => $gross, 'sort_order' => 1]);
        PayslipLine::query()->create(['payslip_id' => $slip->id, 'head_code' => 'PF', 'head_name_en' => 'Provident fund',
            'head_name_bn' => 'ভবিষ্য তহবিল', 'kind' => SalaryHead::DEDUCTION, 'amount' => $cut, 'sort_order' => 2]);

        return $slip;
    }

    private function phone(User $user): self
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh(), [AuthController::APP]);

        return $this;
    }
}
