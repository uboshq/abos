<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Security\FieldSecurity;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Hr\Http\Controllers\PayslipPrintController;
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
 * ⛔ কর্মীর ব্যাংক-নম্বর পরিচয়ের চাবির পিছনে — পে-স্লিপ আর ব্যাংক-ফাইলেও (পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬ HR ⛔৩)।
 *
 * ⓘ মডিউল এই ঘরকে `hr.identity.view`-এর বলে, কর্মীর পাতাও তাই মানে; অথচ স্লিপে নম্বর খোলা ছাপা হত আর ব্যাংকের CSV কেবল বেতন
 * দেখার চাবিতে নামত। একই মানুষ — চাবি ছাড়া বন্ধ, চাবি দিলে খোলা।
 */
final class TheBankNumberNeedsTheIdentityKeyTest extends TestCase
{
    use RefreshDatabase;

    private const ACCOUNT = '1234567890123';

    private Company $company;

    private PayrollRun $run;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(SalaryHeadService::class)->installDefaults();
        $employee = app(EmployeeService::class)->create([
            'code' => 'EMP-BANK', 'name_en' => 'Bank Paid', 'joining_date' => '2026-01-15',
            'payment_method' => 'bank', 'bank_account_no' => self::ACCOUNT, 'bank_name' => 'Sonali', 'bank_routing_no' => '200270001',
        ]);
        // ⓘ ব্যাংকে বেতন — স্লিপ নম্বরটা এখান থেকে কপি করে
        $employee->forceFill(['payment_method' => 'bank'])->save();
        app(SalaryStructureService::class)->set($employee, SalaryHead::query()->where('code', 'BASIC')->firstOrFail(), '2026-01-15', '20000');

        $this->run = app(PayrollService::class)->confirm(app(PayrollService::class)->build('2026-08-01'));
    }

    public function test_the_same_clerk_is_shut_out_without_the_key_and_let_in_with_it(): void
    {
        $clerk = User::factory()->create(['current_company_id' => $this->company->id, 'is_active' => true]);
        $clerk->companies()->attach($this->company->id, ['is_active' => true]);
        $this->give($clerk, 'hr.payroll.view');

        $slip = $this->run->payslips()->whereHas('employee', fn ($q) => $q->where('code', 'EMP-BANK'))->firstOrFail();
        $cash = $this->run->payslips()->where('payment_method', 'cash')->firstOrFail();

        $this->as($clerk)->get(route('hr.payroll.bank_file', $this->run->id))->assertForbidden();
        $this->as($clerk)->get(route('hr.payslip.print', $slip->id))->assertOk();
        $this->assertSame(FieldSecurity::mask(), $this->printedAccount($slip), '⛔ চাবি ছাড়া স্লিপে ব্যাংক-নম্বর ছাপা হল');
        $this->assertNull($this->printedAccount($cash), '⛔ নগদের কর্মীর স্লিপে ঢাকা নম্বরের ঘর — নম্বরই নেই');
        $this->as($clerk)->get(route('hr.payroll.show', $this->run->id))->assertOk()
            ->assertDontSee(route('hr.payroll.bank_file', $this->run->id), false);

        $this->give($clerk, 'hr.identity.view');

        $file = $this->as($clerk)->get(route('hr.payroll.bank_file', $this->run->id))->assertOk();
        $this->assertStringContainsString(self::ACCOUNT, (string) $file->getContent(), 'চাবি দিলেও ফাইলে নম্বর নেই');
        $this->as($clerk);
        $this->assertSame(self::ACCOUNT, $this->printedAccount($slip), 'চাবি দিলেও স্লিপে নম্বর নেই');
        $this->as($clerk)->get(route('hr.payroll.show', $this->run->id))->assertOk()
            ->assertSee(route('hr.payroll.bank_file', $this->run->id), false);
    }

    /** স্লিপের ছাপা কাগজের ব্যাংক-নম্বরের ঘর — PDF যে নথি থেকে আঁকা হয়, সেখান থেকেই */
    private function printedAccount(Payslip $slip): ?string
    {
        $slip->load(['employee.department', 'employee.designation', 'lines', 'run']);
        $method = new \ReflectionMethod(PayslipPrintController::class, 'documentFor');

        return $method->invoke(app(PayslipPrintController::class), $slip)->meta[__('hr::field.bank_account_no')] ?? null;
    }

    private function give(User $user, string $key): void
    {
        CompanyContext::forCompany($this->company->id, fn () => $user->givePermissionTo(Permission::findOrCreate($key, 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        FieldSecurity::forget();
    }

    private function as(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user->fresh());
    }
}
