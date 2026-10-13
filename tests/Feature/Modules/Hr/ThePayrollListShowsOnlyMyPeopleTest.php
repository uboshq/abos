<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\Payslip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * ⛔ বেতনের রানের তালিকা — সীমিত ম্যানেজার কেবল নিজের নাগালের কর্মীদের মোট দেখেন (পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬, HR ৫;
 * [[PayrollController::index()]])।
 *
 * ⓘ রানের পাতা নাগাল মানত ([[AnotherBranchesPayslipWasOneAddressAwayTest]]-এর পরে), তালিকা মানত না: রানটা গোটা কোম্পানির, তাই
 * ঢাকার ম্যানেজারের তালিকায় নেত্রকোনার কর্মীদের বেতনও মোটে মিশে থাকত।
 */
final class ThePayrollListShowsOnlyMyPeopleTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $clerk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['code' => 'HRL', 'name_en' => 'List Co']);
        CompanyContext::set($this->company->id);

        $dhaka = Branch::query()->create(['company_id' => $this->company->id, 'code' => 'DHK', 'name_en' => 'Dhaka', 'is_active' => true]);
        $netrokona = Branch::query()->create(['company_id' => $this->company->id, 'code' => 'NTK', 'name_en' => 'Netrokona', 'is_active' => true]);

        Permission::findOrCreate('hr.payroll.view', 'web');
        $this->clerk = User::factory()->create(['current_company_id' => $this->company->id]);
        $this->clerk->companies()->attach($this->company->id);
        $this->clerk->givePermissionTo('hr.payroll.view');
        UserDataScope::query()->create(['company_id' => $this->company->id, 'user_id' => $this->clerk->id,
            'scope_type' => UserDataScope::BRANCH, 'scope_id' => $dhaka->id]);
        app(DataScope::class)->forget();

        // ⓘ গোটা কোম্পানির রান, ঢাকায় বসে বানানো — ঢাকার একজন (১১,১১১), নেত্রকোনার একজন (৭৭,৭৭৭)
        $run = PayrollRun::acrossBranches()->create([
            'company_id' => $this->company->id, 'branch_id' => $dhaka->id, 'document_no' => 'PAY-ALL',
            'month' => '2026-08-01', 'trx_date' => '2026-08-31',
            'employee_count' => 2, 'gross_total' => '88888', 'deduction_total' => '0', 'net_total' => '88888',
        ]);

        foreach ([[$dhaka, 'E-1', '11111'], [$netrokona, 'E-2', '77777']] as [$branch, $code, $amount]) {
            $employee = Employee::query()->create(['company_id' => $this->company->id, 'branch_id' => $branch->id,
                'code' => $code, 'name_en' => 'Person '.$code, 'joining_date' => '2026-01-01']);
            Payslip::query()->create(['company_id' => $this->company->id, 'payroll_run_id' => $run->id,
                'employee_id' => $employee->id, 'gross' => $amount, 'deductions' => '0', 'net' => $amount]);
        }
    }

    public function test_a_branch_limited_manager_sees_only_their_peoples_totals(): void
    {
        $this->actingAs($this->clerk)->get(route('hr.payroll.index'))->assertOk()
            ->assertSee('PAY-ALL')
            ->assertSee(Money::format('11111'))
            ->assertDontSee(Money::format('88888'))
            ->assertDontSee(Money::format('77777'));
    }

    public function test_someone_without_a_limit_still_sees_the_whole_run(): void
    {
        $manager = User::factory()->create(['current_company_id' => $this->company->id]);
        $manager->companies()->attach($this->company->id);
        $manager->givePermissionTo('hr.payroll.view');

        $this->actingAs($manager)->get(route('hr.payroll.index'))->assertOk()
            ->assertSee(Money::format('88888'));
    }
}
