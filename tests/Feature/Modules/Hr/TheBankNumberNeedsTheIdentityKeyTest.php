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

    /**
     * ⛔ চাবি ছাড়া ঠিকানার query দিয়েও নম্বর বদলায় না — `validate()` query-ও পড়ে, ছাঁকনি সরাত কেবল ফর্মের ঘর
     * (ec, Hr লাইন-নিরীক্ষা, ১৪ অক্টোবর ২০২৬; [[EmployeeController::validated()]])। চাবি থাকলে বদলায়।
     */
    public function test_a_clerk_without_the_key_cannot_change_the_bank_number_through_the_address(): void
    {
        $clerk = User::factory()->create(['current_company_id' => $this->company->id, 'is_active' => true]);
        $clerk->companies()->attach($this->company->id, ['is_active' => true]);
        $this->give($clerk, 'hr.employee.manage');
        $this->give($clerk, 'hr.employee.view');

        $employee = \App\Modules\Hr\Models\Employee::query()->where('code', 'EMP-BANK')->firstOrFail();
        $form = ['name_en' => 'Bank Paid', 'joining_date' => '2026-01-15', 'payment_method' => 'bank'];
        $query = ['bank_account_no' => '9999999999999', 'national_id' => '1999999999', 'mfs_number' => '01999999999'];

        // ⓘ কর্মীর তথ্য ছবি ছাড়া সংরক্ষণ হয় না — প্রথম হালনাগাদে একটা ছোট ছবি
        $this->as($clerk)->put(route('hr.employee.update', ['employee' => $employee->id, ...$query]), [...$form, 'photo' => $this->png()])
            ->assertSessionHasNoErrors();

        $fresh = $employee->fresh();
        $this->assertSame(self::ACCOUNT, (string) $fresh->bank_account_no, '⛔ চাবি ছাড়া ঠিকানার query দিয়ে ব্যাংকের নম্বর বদলাল — পরের ব্যাংক-ফাইল অন্য হিসাবে বেতন পাঠাত');
        $this->assertNull($fresh->mfs_number, '⛔ চাবি ছাড়া MFS নম্বর বসল');

        $this->give($clerk, 'hr.identity.view');
        $this->as($clerk)->put(route('hr.employee.update', $employee->id), [...$form, 'bank_account_no' => '5555555555555'])->assertSessionHasNoErrors();
        $this->assertSame('5555555555555', (string) $employee->fresh()->bank_account_no, 'চাবি থাকলে বদলায়, আগের মতো');
    }

    /**
     * ⛔ চাবি ছাড়া পুরো NID লিখে খুঁজলে কর্মী মেলে না — নাহলে "এই NID অমুকের" জানা যেত (ec, Hr লাইন-নিরীক্ষা, ১৪ অক্টোবর ২০২৬;
     * [[Employee::scopeSearch()]])। চাবি থাকলে মেলে, আগের মতো।
     */
    public function test_a_clerk_without_the_key_cannot_find_an_employee_by_the_full_id_card_number(): void
    {
        $nid = '19901234567890';
        $employee = \App\Modules\Hr\Models\Employee::query()->where('code', 'EMP-BANK')->firstOrFail();
        app(EmployeeService::class)->update($employee, ['national_id' => $nid]);

        $clerk = User::factory()->create(['current_company_id' => $this->company->id, 'is_active' => true]);
        $clerk->companies()->attach($this->company->id, ['is_active' => true]);
        $this->give($clerk, 'hr.employee.view');

        $this->as($clerk);
        $this->assertSame([], \App\Modules\Hr\Models\Employee::query()->search($nid)->pluck('code')->all(), '⛔ পরিচয়ের চাবি ছাড়া NID দিয়ে কর্মী মিলল');

        $this->give($clerk, 'hr.identity.view');
        $this->as($clerk);
        $this->assertSame(['EMP-BANK'], \App\Modules\Hr\Models\Employee::query()->search($nid)->pluck('code')->all(), 'চাবি থাকলে NID দিয়ে মেলে');
    }

    /** স্লিপের ছাপা কাগজের ব্যাংক-নম্বরের ঘর — PDF যে নথি থেকে আঁকা হয়, সেখান থেকেই */
    private function printedAccount(Payslip $slip): ?string
    {
        $slip->load(['employee.department', 'employee.designation', 'lines', 'run']);
        $method = new \ReflectionMethod(PayslipPrintController::class, 'documentFor');

        return $method->invoke(app(PayslipPrintController::class), $slip)->meta[__('hr::field.bank_account_no')] ?? null;
    }

    private function png(): \Illuminate\Http\UploadedFile
    {
        $image = imagecreatetruecolor(40, 40);
        imagefill($image, 0, 0, imagecolorallocate($image, 20, 120, 200));
        $path = tempnam(sys_get_temp_dir(), 'pic');
        imagepng($image, $path);
        imagedestroy($image);

        return new \Illuminate\Http\UploadedFile($path, 'face.png', 'image/png', null, true);
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
