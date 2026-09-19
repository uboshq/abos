<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
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
 * অন্য শাখার বেতনের পাতা — ঠিকানা বদলানোর দূরত্বে ছিল।
 *
 * ── ⛔ নিরীক্ষার ফলাফল ৩.৪ ─────────────────────────────────────────────
 * *"HR-এ রেকর্ড-স্তরের পলিসি — বেতনের মতো তথ্যে কেবল মিডলওয়্যার যথেষ্ট
 * নয়।"* ⓘ মিডলওয়্যার বলত "আপনি বেতনের রান দেখতে পারেন"; **কার** রান,
 * সেটা বলত না।
 *
 * ⚠️ রানগুলো শাখা ধরে সীমিত ছিল, কিন্তু রানের **সন্তানেরা** — কর্মীর
 * পাতা, বেতনের কাঠামো, একটা বেতনের পাতা ছাপা — ছিল না। ঢাকার
 * হিসাবরক্ষক নেত্রকোনার রান দেখতেন না, অথচ `/hr/employees/{id}/salary`
 * বা `/hr/payslips/{id}/print`-এ নম্বর বসালেই পেতেন।
 *
 * ⭐ এখন [[EmployeePolicy]] আর [[PayslipPolicy]] রানের নিয়মটাই মানে।
 */
final class AnotherBranchesPayslipWasOneAddressAwayTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Branch $dhaka;

    private Branch $netrokona;

    private User $clerk;

    private Employee $ours;

    private Employee $theirs;

    private Employee $headOffice;

    private Payslip $theirSlip;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['code' => 'HRB', 'name_en' => 'Branch Co']);
        CompanyContext::set($this->company->id);

        $this->dhaka = Branch::query()->create([
            'company_id' => $this->company->id, 'code' => 'DHK', 'name_en' => 'Dhaka', 'is_active' => true,
        ]);
        $this->netrokona = Branch::query()->create([
            'company_id' => $this->company->id, 'code' => 'NTK', 'name_en' => 'Netrokona', 'is_active' => true,
        ]);

        foreach (['hr.employee.view', 'hr.employee.manage', 'hr.salary.view', 'hr.salary.manage', 'hr.payroll.view'] as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $this->clerk = User::factory()->create(['current_company_id' => $this->company->id]);
        $this->clerk->companies()->attach($this->company->id);
        $this->clerk->givePermissionTo(['hr.employee.view', 'hr.employee.manage', 'hr.salary.view', 'hr.salary.manage', 'hr.payroll.view']);

        UserDataScope::query()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->clerk->id,
            'scope_type' => UserDataScope::BRANCH,
            'scope_id' => $this->dhaka->id,
        ]);
        app(DataScope::class)->forget();

        $this->ours = $this->employee('E-1', $this->dhaka);
        $this->theirs = $this->employee('E-2', $this->netrokona);
        $this->headOffice = $this->employee('E-3', null);

        $run = PayrollRun::acrossBranches()->create([
            'company_id' => $this->company->id,
            'branch_id' => $this->netrokona->id,
            'document_no' => 'PAY-NTK',
            'month' => '2026-08-01',
            'trx_date' => '2026-08-31',
        ]);

        $this->theirSlip = Payslip::query()->create([
            'company_id' => $this->company->id,
            'payroll_run_id' => $run->id,
            'employee_id' => $this->theirs->id,
            'net' => '25000',
        ]);
    }

    private function employee(string $code, ?Branch $branch): Employee
    {
        return Employee::query()->create([
            'company_id' => $this->company->id,
            'branch_id' => $branch?->id,
            'code' => $code,
            'name_en' => 'Person '.$code,
            'joining_date' => '2026-01-01',
        ]);
    }

    /** ⭐ নিজের শাখার কর্মী — আগের মতোই */
    public function test_the_clerk_still_reaches_their_own_branch_and_the_head_office(): void
    {
        $this->assertTrue($this->clerk->can('view', $this->ours));
        $this->assertTrue($this->clerk->can('viewSalary', $this->ours));
        $this->assertTrue($this->clerk->can('update', $this->ours));

        // ⓘ শাখা লেখা নেই মানে প্রধান অফিস — সবার নাগালে, DataScope-এর নিয়মে
        $this->assertTrue($this->clerk->can('view', $this->headOffice));
    }

    /** ⛔ অন্য শাখার কর্মী — পাতা, বেতন, সম্পাদনা সব বন্ধ */
    public function test_another_branches_person_is_closed_to_the_clerk(): void
    {
        $this->assertFalse($this->clerk->can('view', $this->theirs));
        $this->assertFalse($this->clerk->can('viewSalary', $this->theirs));
        $this->assertFalse($this->clerk->can('manageSalary', $this->theirs));
        $this->assertFalse($this->clerk->can('update', $this->theirs));

        $this->actingAs($this->clerk)
            ->get(route('hr.employee.salary', $this->theirs))
            ->assertForbidden();

        $this->actingAs($this->clerk)
            ->get(route('hr.employee.show', $this->theirs))
            ->assertForbidden();
    }

    /** ⛔ অন্য শাখার রানের বেতনের পাতা — ছাপার ঠিকানাতেও */
    public function test_another_branches_payslip_cannot_be_printed_by_its_number(): void
    {
        $this->assertFalse($this->clerk->can('view', $this->theirSlip));

        $this->actingAs($this->clerk)
            ->get(route('hr.payslip.print', $this->theirSlip))
            ->assertForbidden();
    }

    /**
     * ⓘ সীমা না থাকলে কিছুই বদলায় না — পরীক্ষাটা অন্ধ নয়, নিয়মটা কেবল
     * সীমিত মানুষের জন্য।
     */
    public function test_someone_without_a_branch_limit_still_sees_everyone(): void
    {
        $manager = User::factory()->create(['current_company_id' => $this->company->id]);
        $manager->companies()->attach($this->company->id);
        $manager->givePermissionTo(['hr.employee.view', 'hr.salary.view', 'hr.payroll.view']);

        $this->assertTrue($manager->can('view', $this->theirs));
        $this->assertTrue($manager->can('viewSalary', $this->theirs));
        $this->assertTrue($manager->can('view', $this->theirSlip));
    }

    /** ⛔ নিজের নাগালের বাইরের শাখায় নতুন কর্মী বসানো যায় না */
    public function test_the_clerk_cannot_place_someone_in_a_branch_they_cannot_see(): void
    {
        $this->actingAs($this->clerk)
            ->post(route('hr.employee.store'), [
                'code' => 'E-9',
                'name_en' => 'Placed elsewhere',
                'joining_date' => '2026-02-01',
                'branch_id' => $this->netrokona->id,
                'payment_method' => 'cash',
            ])
            ->assertSessionHasErrors('branch_id');
    }
}
