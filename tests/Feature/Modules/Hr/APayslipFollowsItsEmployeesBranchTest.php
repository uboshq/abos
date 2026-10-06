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
use App\Modules\Hr\Models\SalaryHead;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\PayrollService;
use App\Modules\Hr\Services\SalaryHeadService;
use App\Modules\Hr\Services\SalaryStructureService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⛔ বেতন কর্মীর শাখা ধরে দেখা আর ছাপা — রান যেখানেই চালানো হোক (পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬ HR ⛔২)।
 *
 * ⓘ রান পুরো কোম্পানির; আগে দেয়াল কেবল রানটা কোন শাখায় বানানো তা দেখত, তাই এক শাখায় সীমিত ম্যানেজার রানটা দেখতে পেলে সব শাখার
 * কর্মীর মোট, কর্তন আর নিট দেখতেন, ছাপতেন, ব্যাংক-ফাইলও নামাতেন — যাঁদের কর্মীর তালিকা তাঁর কাছ থেকে লুকায়।
 */
final class APayslipFollowsItsEmployeesBranchTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Employee $here;

    private Employee $there;

    private PayrollRun $run;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->branch('MMS')->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(SalaryHeadService::class)->installDefaults();
        $this->here = $this->employee('EMP-MMS', 'Here Person', 'MMS', '20000', '1111111111');
        $this->there = $this->employee('EMP-NTK', 'There Person', 'NTK', '35000', '2222222222');

        $this->run = app(PayrollService::class)->confirm(app(PayrollService::class)->build('2026-08-01'));
    }

    public function test_a_manager_of_one_branch_sees_prints_and_pays_only_that_branchs_people(): void
    {
        $manager = User::factory()->create(['current_company_id' => $this->company->id, 'is_active' => true]);
        $manager->companies()->attach($this->company->id, ['is_active' => true]);
        UserDataScope::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'user_id' => $manager->id, 'scope_type' => UserDataScope::BRANCH, 'scope_id' => $this->branch('MMS')->id,
        ]);
        CompanyContext::forCompany($this->company->id, fn () => $manager->givePermissionTo([
            Permission::findOrCreate('hr.payroll.view', 'web'), Permission::findOrCreate('hr.identity.view', 'web'),
        ]));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        app(DataScope::class)->forget();

        $this->as($manager);
        $page = $this->get(route('hr.payroll.show', $this->run->id))->assertOk();
        $page->assertSee('Here Person')->assertDontSee('There Person');
        $page->assertDontSee(Money::format($this->run->net_total));

        $this->get(route('hr.payslip.print', $this->slip($this->there)->id))->assertForbidden();
        $this->get(route('hr.payslip.print', $this->slip($this->here)->id))->assertOk();

        $file = (string) $this->get(route('hr.payroll.bank_file', $this->run->id))->assertOk()->getContent();
        $this->assertStringContainsString('1111111111', $file);
        $this->assertStringNotContainsString('2222222222', $file, '⛔ অন্য শাখার কর্মীর ব্যাংক-সারি ফাইলে');

        // ⓘ মালিক — সীমা নেই, সবাই আর রানের নিজের মোট
        $this->as(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        $this->get(route('hr.payroll.show', $this->run->id))->assertOk()->assertSee('There Person')
            ->assertSee(Money::format($this->run->net_total));
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function employee(string $code, string $name, string $branch, string $basic, string $account): Employee
    {
        $employee = app(EmployeeService::class)->create([
            'code' => $code, 'name_en' => $name, 'joining_date' => '2026-01-15', 'payment_method' => 'cash',
            'branch_id' => $this->branch($branch)->id,
        ]);
        $employee->forceFill(['payment_method' => 'bank', 'bank_account_no' => $account, 'branch_id' => $this->branch($branch)->id])->save();
        app(SalaryStructureService::class)->set($employee, SalaryHead::query()->where('code', 'BASIC')->firstOrFail(), '2026-01-15', $basic);

        return $employee;
    }

    private function slip(Employee $employee): Payslip
    {
        return $this->run->payslips()->where('employee_id', $employee->id)->firstOrFail();
    }

    private function as(User $user): void
    {
        $this->app['auth']->forgetGuards();
        app(DataScope::class)->forget();
        $this->actingAs($user->fresh());
    }

    private function branch(string $code): Branch
    {
        return Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', $code)->firstOrFail();
    }
}
