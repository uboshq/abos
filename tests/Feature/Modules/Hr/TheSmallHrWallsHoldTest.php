<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Hr\Http\Controllers\PayslipPrintController;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\LeaveApplication;
use App\Modules\Hr\Models\LeaveType;
use App\Modules\Hr\Models\SalaryHead;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\LeaveService;
use App\Modules\Hr\Services\PayrollService;
use App\Modules\Hr\Services\SalaryHeadService;
use App\Modules\Hr\Services\SalaryStructureService;
use App\Modules\MasterData\Services\MasterListService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⛔ HR-এর ছোট চারটা ফাঁক — পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬ (HR ১০)।
 *
 * ⓘ (ক) কর্মীর সাথে যেকোনো কোম্পানির লগইন জোড়া যেত; (খ) "যাঁর অধীনে" তালিকায় অন্য শাখার সবার নাম আসত; (গ) বেতনের পাতায় মাসের
 * নাম ইংরেজিতে ("August 2026") — মালিক কেবল বাংলা পড়েন; (ঘ) বেতন নিশ্চিত হয়ে যাওয়া মাসের ছুটি প্রত্যাহারে সেই মাসের হাজিরা মুছত।
 */
final class TheSmallHrWallsHoldTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

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
    }

    public function test_an_employee_can_be_tied_only_to_a_user_of_this_company(): void
    {
        $stranger = User::factory()->create(['email' => 'stranger@other.test']);
        $other = Company::create(['code' => 'OTHR', 'name_en' => 'Other Co']);
        $stranger->companies()->attach($other->id, ['is_active' => true]);

        $this->post(route('hr.employee.store'), $this->form('EMP-X', ['user_id' => $stranger->id]))->assertSessionHasErrors('user_id');
        $this->assertFalse(Employee::query()->where('code', 'EMP-X')->exists(), '⛔ অন্য কোম্পানির লগইন এই কোম্পানির কর্মী হল');

        $ours = User::factory()->create(['email' => 'ours@abos.test']);
        $ours->companies()->attach($this->company->id, ['is_active' => true]);
        $this->post(route('hr.employee.store'), $this->form('EMP-Y', ['user_id' => $ours->id]))->assertSessionHasNoErrors();
    }

    public function test_the_managers_list_stays_inside_the_branch_wall(): void
    {
        $mine = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'MMS')->firstOrFail();
        $theirs = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'NTK')->firstOrFail();
        $far = app(EmployeeService::class)->create(['code' => 'EMP-FAR', 'name_en' => 'Faraway Boss', 'joining_date' => '2026-01-15', 'payment_method' => 'cash']);
        $far->forceFill(['branch_id' => $theirs->id])->save();
        $near = app(EmployeeService::class)->create(['code' => 'EMP-NEAR', 'name_en' => 'Nearby Boss', 'joining_date' => '2026-01-15', 'payment_method' => 'cash']);
        $near->forceFill(['branch_id' => $mine->id])->save();

        $clerk = User::factory()->create(['email' => 'clerk@hr.test', 'current_company_id' => $this->company->id, 'is_active' => true]);
        $clerk->companies()->attach($this->company->id, ['is_active' => true]);
        CompanyContext::forCompany($this->company->id, fn () => $clerk->givePermissionTo(array_map(fn ($k) => Permission::findOrCreate($k, 'web'), ['hr.employee.view', 'hr.employee.manage'])));
        UserDataScope::query()->create(['company_id' => $this->company->id, 'user_id' => $clerk->id, 'scope_type' => UserDataScope::BRANCH, 'scope_id' => $mine->id]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        app(DataScope::class)->forget();

        $this->actingAs($clerk)->get(route('hr.employee.create'))->assertOk()
            ->assertSee('Nearby Boss')
            ->assertDontSee('Faraway Boss');

        $this->post(route('hr.employee.store'), $this->form('EMP-Z', ['branch_id' => $mine->id, 'reports_to_employee_id' => $far->id]))
            ->assertSessionHasErrors('reports_to_employee_id');
    }

    public function test_the_payroll_pages_name_the_month_in_bengali_and_a_paid_months_leave_stays(): void
    {
        $employee = app(EmployeeService::class)->create(['code' => 'EMP-PAID', 'name_en' => 'Paid Person', 'joining_date' => '2026-01-15', 'payment_method' => 'cash']);
        app(SalaryStructureService::class)->set($employee, SalaryHead::query()->where('code', 'BASIC')->firstOrFail(), '2026-01-15', '20000');
        $this->post(route('hr.leave_type.install'));
        $leave = app(LeaveService::class)->approve(app(LeaveService::class)->apply($employee,
            LeaveType::query()->where('code', 'CASUAL')->firstOrFail(), '2026-08-10', '2026-08-11', '2'), $this->owner);

        $run = app(PayrollService::class)->confirm(app(PayrollService::class)->build('2026-08-01'));
        app()->setLocale('bn');

        $this->get(route('hr.payroll.show', $run))->assertOk()->assertSee('আগস্ট')->assertDontSee('August 2026');
        $this->get(route('hr.payroll.index'))->assertOk()->assertSee('আগস্ট')->assertDontSee('Aug 2026');
        // ⓘ ছাপা PDF — কাগজের মাথার ঘরগুলো সরাসরি পড়া
        $document = (new \ReflectionMethod(PayslipPrintController::class, 'documentFor'))
            ->invoke(app(PayslipPrintController::class), $run->payslips()->firstOrFail());
        $this->assertContains('আগস্ট 2026', $document->meta, '⛔ বেতনশিটে মাসের নাম বাংলায় নয়');

        try {
            app(LeaveService::class)->cancel($leave);
            $this->fail('⛔ বেতন হয়ে যাওয়া মাসের ছুটি প্রত্যাহার হল');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }

        $this->assertSame(LeaveApplication::APPROVED, $leave->fresh()->status);
        $this->assertSame(2, Attendance::query()->where('leave_application_id', $leave->id)->count(), '⛔ বেতন হয়ে যাওয়া মাসের হাজিরা মুছে গেল');
    }

    /** @param  array<string, mixed>  $extra */
    private function form(string $code, array $extra): array
    {
        $image = imagecreatetruecolor(40, 40);
        imagefill($image, 0, 0, imagecolorallocate($image, 20, 120, 200));
        $path = tempnam(sys_get_temp_dir(), 'pic');
        imagepng($image, $path);
        imagedestroy($image);

        return ['code' => $code, 'name_en' => 'Person '.$code, 'joining_date' => '2026-02-01', 'payment_method' => 'cash',
            'photo' => new UploadedFile($path, 'face.png', 'image/png', null, true)] + $extra;
    }
}
