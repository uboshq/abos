<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Services\PhoneModules;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\PayslipLine;
use App\Modules\Hr\Models\SalaryHead;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ ফোনের বেতনের স্লিপ বলে সেটা বেতনের না উৎসব ভাতার — নতুন ঘর `run_type` (`salary` | `bonus`), কেবল যোগ (fe-র সিদ্ধান্ত ৩, ২১ অক্টোবর
 * ২০২৬; [[PayslipApiController]])।
 *
 * ⓘ উৎসব ভাতার রান আসার পর (`run_type` bonus) ফোনের তালিকায় একই মাসে দুইটা স্লিপ — অথচ কোনটা কী, অ্যাপ জানত না।
 * ⛔ অ্যাপ 0.4.42 পুরনো ঘরগুলো পড়ে — নাম, ক্রম বা মান এক অক্ষরও বদলালে সে ভাঙে।
 *
 * দাবি:
 *  - বেতনের স্লিপে তালিকা আর একটা স্লিপের উত্তর থেকে `run_type` সরালে বাকিটা আগের চুক্তির অবিকল (ঘর, ক্রম, মান, টাইপ);
 *  - `run_type` দুই ধরনেই আছে — বেতনে `salary`, উৎসব ভাতায় `bonus`, তালিকায় আর একটা স্লিপে।
 */
final class APayslipSaysWhetherItIsSalaryOrBonusTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $me;

    private Employee $mine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(SettingsService::class)->set(PhoneModules::key('hr'), true);

        $this->me = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $this->me->companies()->attach($this->company->id, ['is_active' => true]);
        CompanyContext::forCompany($this->company->id, fn () => $this->me->givePermissionTo(Permission::findOrCreate('hr.payslip.self', 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->mine = Employee::query()->create([
            'branch_id' => CompanyContext::branchId(), 'code' => 'RT-1', 'name_en' => 'Person RT-1', 'joining_date' => '2026-01-01',
        ]);
        $this->mine->forceFill(['user_id' => $this->me->id])->save();
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    public function test_the_old_fields_of_a_salary_slip_are_unchanged_and_run_type_is_added_for_both_kinds(): void
    {
        $salary = $this->slip($this->run('PAY-RT-09', PayrollRun::SALARY), '30000', '2000');
        $bonus = $this->slip($this->run('BON-RT-09', PayrollRun::BONUS), '15000', '0');

        // ── তালিকা — বেতনের সারির পুরনো ঘরগুলো অবিকল, তারপর নতুন ঘর
        $rows = collect($this->phone()->getJson('/api/v1/hr/payslips')->assertOk()->json('rows'))->keyBy('id');
        $row = $rows[$salary->public_id];
        $this->assertSame('salary', $row['run_type'] ?? null, '⛔ বেতনের স্লিপের তালিকার সারিতে run_type নেই বা ভুল।');
        unset($row['run_type']);
        $this->assertSame([
            'id' => (string) $salary->public_id,
            'month' => '2026-09-01',
            'run_no' => 'PAY-RT-09',
            'gross' => '30000.00',
            'deductions' => '2000.00',
            'net' => '28000.00',
        ], $row, '⛔ বেতনের স্লিপের তালিকার পুরনো ঘর বদলাল — অ্যাপ 0.4.42 ভাঙবে।');
        $this->assertSame('bonus', $rows[$bonus->public_id]['run_type'] ?? null, '⛔ উৎসব ভাতার স্লিপ তালিকায় বলে না সেটা ভাতা।');

        // ── একটা স্লিপ — একই
        $one = $this->phone()->getJson('/api/v1/hr/payslips/'.$salary->public_id)->assertOk()->json();
        $this->assertSame('salary', $one['run_type'] ?? null);
        unset($one['run_type']);
        $this->assertSame([
            'id' => (string) $salary->public_id,
            'month' => '2026-09-01',
            'run_no' => 'PAY-RT-09',
            'gross' => '30000.00',
            'deductions' => '2000.00',
            'net' => '28000.00',
            'name' => 'Person RT-1',
            'code' => 'RT-1',
            'payment_method' => 'cash',
            'lines' => [
                ['name' => 'মূল বেতন', 'earning' => true, 'amount' => '30000.00'],
                ['name' => 'ভবিষ্য তহবিল', 'earning' => false, 'amount' => '2000.00'],
            ],
            'employer_lines' => [],
        ], $one, '⛔ বেতনের স্লিপের পুরনো ঘর বদলাল — অ্যাপ 0.4.42 ভাঙবে।');

        $this->assertSame('bonus', $this->phone()->getJson('/api/v1/hr/payslips/'.$bonus->public_id)->assertOk()->json('run_type'),
            '⛔ উৎসব ভাতার স্লিপ বলে না সেটা ভাতা।');
    }

    private function run(string $no, string $type): PayrollRun
    {
        $run = PayrollRun::acrossBranches()->create([
            'company_id' => $this->company->id, 'branch_id' => CompanyContext::branchId(), 'document_no' => $no, 'run_type' => $type,
            'month' => '2026-09-01', 'trx_date' => Carbon::parse('2026-09-30')->toDateString(),
        ]);
        $run->forceFill(['status' => DocumentStatus::CONFIRMED])->save();

        return $run;
    }

    private function slip(PayrollRun $run, string $gross, string $cut): Payslip
    {
        $slip = Payslip::query()->create([
            'company_id' => $this->company->id, 'payroll_run_id' => $run->id, 'employee_id' => $this->mine->id,
            'gross' => $gross, 'deductions' => $cut, 'net' => bcsub($gross, $cut, 4), 'payment_method' => 'cash',
        ]);
        PayslipLine::query()->create(['payslip_id' => $slip->id, 'head_code' => 'BASIC', 'head_name_en' => 'Basic',
            'head_name_bn' => 'মূল বেতন', 'kind' => SalaryHead::EARNING, 'amount' => $gross, 'sort_order' => 1]);
        PayslipLine::query()->create(['payslip_id' => $slip->id, 'head_code' => 'PF', 'head_name_en' => 'Provident fund',
            'head_name_bn' => 'ভবিষ্য তহবিল', 'kind' => SalaryHead::DEDUCTION, 'amount' => $cut, 'sort_order' => 2]);

        return $slip;
    }

    private function phone(): self
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->me->fresh(), [AuthController::APP]);

        return $this;
    }
}
