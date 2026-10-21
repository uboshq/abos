<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Security\FieldSecurity;
use App\Core\Support\CompanyContext;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\User;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\SalaryHead;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\PayrollService;
use App\Modules\Hr\Services\SalaryHeadService;
use App\Modules\Hr\Services\SalaryStructureService;
use App\Modules\MasterData\Services\MasterListService;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ কে কার বেতন বা NID খুলে দেখলেন — নিরীক্ষার খাতায়, অঙ্ক ছাড়া (ঘাটতির তালিকা #১৮; ISO 27001 A.8.15; a6, ২০ অক্টোবর ২০২৬;
 * [[SensitiveViews]])।
 *
 * ⛔ আগে দেখা কোথাও লেখা থাকত না — কেউ সবার বেতন ঘুরে দেখলেও প্রমাণ থাকত না।
 *
 * দাবি:
 *  - চাবিওয়ালা কর্মীর পাতা দুইবার আর বেতনের কাঠামো একবার খুললেন: বেতন একটা লাইন, পরিচয় একটা লাইন — দিনে একবার; পরের দিন আবার।
 *  - লাইনে বদলের সারি নেই, কারণের লেখায় বেতনের অঙ্ক নেই, NID নেই (fe-র শর্ত)।
 *  - চাবি ছাড়া মানুষ (অঙ্ক দেখেননি): কোনো লাইন নয়।
 *  - বেতনের রান আর অন্যের বেতনশিট ছাপা: লাইন; নিজের বেতনশিট নিজে: লাইন নয়।
 */
final class NobodyKnewWhoReadTheSalariesTest extends TestCase
{
    use RefreshDatabase;

    private const NID = '19905512345678901';

    private Company $company;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(MasterListService::class)->installDefaults();
        app(SalaryHeadService::class)->installDefaults();

        $this->employee = app(EmployeeService::class)->create([
            'code' => 'SEEN-EMP', 'name_en' => 'Rafiq Islam', 'joining_date' => '2026-01-15', 'payment_method' => 'cash',
        ]);
        $this->employee->forceFill(['national_id' => self::NID])->save();
        app(SalaryStructureService::class)->set($this->employee, SalaryHead::query()->where('code', 'BASIC')->firstOrFail(), '2026-01-15', '23456');
    }

    public function test_the_salary_and_the_identity_are_logged_once_a_day_without_a_figure(): void
    {
        $hr = $this->staff('hr.employee.view', 'hr.salary.view', 'hr.identity.view');

        $this->as($hr)->get(route('hr.employee.show', $this->employee->id))->assertOk();
        $this->as($hr)->get(route('hr.employee.show', $this->employee->id))->assertOk();
        $this->as($hr)->get(route('hr.employee.salary', $this->employee->id))->assertOk();

        $this->assertSame(1, $this->lines($hr, $this->employee, 'salary_viewed'), '⛔ বেতন দেখা খাতায় একবার নয়');
        $this->assertSame(1, $this->lines($hr, $this->employee, 'identity_viewed'), '⛔ NID দেখা খাতায় একবার নয়');

        foreach ($this->trails($hr) as $trail) {
            $this->assertSame(0, $trail->changes()->count(), '⛔ দেখার লাইনে বদলের সারি — অঙ্ক খাতায় উঠল');
            foreach (['23456', '23,456', '২৩', self::NID] as $figure) {
                $this->assertStringNotContainsString($figure, (string) $trail->reason.(string) $trail->label, '⛔ খাতায় যা দেখলেন তার অঙ্ক');
            }
        }

        $this->travel(1)->days();
        $this->as($hr)->get(route('hr.employee.show', $this->employee->id))->assertOk();
        $this->assertSame(2, $this->lines($hr, $this->employee, 'salary_viewed'), '⛔ পরের দিনের দেখা খাতায় নেই');
    }

    public function test_a_viewer_without_the_keys_saw_no_figure_and_leaves_no_line(): void
    {
        $clerk = $this->staff('hr.employee.view');

        $this->as($clerk)->get(route('hr.employee.show', $this->employee->id))->assertOk()
            ->assertDontSee('23,456', false)->assertDontSee(self::NID, false);

        $this->assertSame([], $this->trails($clerk)->pluck('action')->all(), '⛔ যা দেখেননি তা-ও খাতায়');
    }

    public function test_the_payroll_and_someone_elses_payslip_are_logged_but_ones_own_payslip_is_not(): void
    {
        $run = app(PayrollService::class)->build('2026-08-01');
        // ⛔ এই কর্মীরই স্লিপ — ডেমোর অন্য কর্মীরাও রানে আছেন, প্রথম স্লিপটা তাঁদের কারো হতে পারে
        $slip = $run->payslips()->where('employee_id', $this->employee->id)->firstOrFail();

        $accounts = $this->staff('hr.payroll.view');
        $this->as($accounts)->get(route('hr.payroll.show', $run->id))->assertOk();
        $this->as($accounts)->get(route('hr.payslip.print', $slip->id))->assertOk();
        $this->as($accounts)->get(route('hr.payroll.payslips', $run->id))->assertOk();

        $this->assertSame(1, $this->lines($accounts, $run, 'payroll_viewed'), '⛔ রান খোলা খাতায় একবার নয়');
        $this->assertSame(1, $this->lines($accounts, $slip, 'payslip_viewed'), '⛔ অন্যের বেতনশিট ছাপা খাতায় নেই');

        // ⓘ নিজের বেতনশিট নিজে — নজরদারির বিষয় নয়
        $self = $this->staff('hr.payroll.view');
        $this->employee->forceFill(['user_id' => $self->id])->save();
        $this->as($self)->get(route('hr.payslip.print', $slip->id))->assertOk();
        $this->assertSame(0, $this->lines($self, $slip, 'payslip_viewed'), '⛔ নিজের বেতনশিট দেখাও নজরদারিতে উঠল');
    }

    /**
     * ⛔ হেডারে বাছা শাখা আর কর্মীর নিজের শাখা আলাদা হলেও নিজের বেতনশিট নিজের — কর্মীর সম্পর্ক শাখার দেয়ালে ফাঁকা আসতে পারে,
     * তাই মালিকানা পড়া হয় আইডি ধরে, দেয়াল ছাড়া ([[SensitiveViews::seen()]])।
     * ⓘ সংশোধন (hr5 দৌড়, ২১ অক্টোবর ২০২৬): আগের লাল ছিল টেস্টের ভুল — রানের প্রথম স্লিপ ডেমোর অন্য কর্মীর ছিল; এখন এই কর্মীরই স্লিপ।
     */
    public function test_ones_own_payslip_printed_under_another_header_branch_is_not_logged(): void
    {
        $run = app(PayrollService::class)->build('2026-08-01');
        $slip = $run->payslips()->where('employee_id', $this->employee->id)->firstOrFail();

        $elsewhere = \App\Models\Branch::query()->where('company_id', $this->company->id)
            ->whereKeyNot((int) $this->employee->branch_id)->orderBy('id')->firstOrFail();

        $self = $this->staff('hr.payroll.view');
        $this->employee->forceFill(['user_id' => $self->id])->save();
        $self->forceFill(['current_branch_id' => $elsewhere->id, 'view_all_branches' => false])->save();
        CompanyContext::set($this->company->id, $elsewhere->id);

        $this->as($self)->get(route('hr.payslip.print', $slip->id))->assertOk();

        $this->assertSame(0, $this->lines($self, $slip, 'payslip_viewed'),
            '⛔ অন্য শাখা বাছা থাকায় নিজের বেতনশিটও নজরদারিতে উঠল — দেয়ালে কর্মীর সম্পর্ক ফাঁকা এসেছিল।');
    }

    private function lines(User $viewer, Model $subject, string $action): int
    {
        return AuditTrail::query()->withoutGlobalScopes()->where('user_id', $viewer->id)->where('action', $action)
            ->where('auditable_type', $subject::class)->where('auditable_id', $subject->getKey())->count();
    }

    /** @return \Illuminate\Support\Collection<int, AuditTrail> */
    private function trails(User $viewer): \Illuminate\Support\Collection
    {
        return AuditTrail::query()->withoutGlobalScopes()->where('user_id', $viewer->id)
            ->whereIn('action', ['salary_viewed', 'identity_viewed', 'payslip_viewed', 'payroll_viewed'])->get();
    }

    private function staff(string ...$keys): User
    {
        $user = User::factory()->create(['current_company_id' => $this->company->id, 'is_active' => true]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);
        CompanyContext::forCompany($this->company->id, function () use ($user, $keys) {
            foreach ($keys as $key) {
                $user->givePermissionTo(Permission::findOrCreate($key, 'web'));
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        FieldSecurity::forget();

        return $user;
    }

    private function as(User $user): static
    {
        $this->app['auth']->forgetGuards();
        FieldSecurity::forget();

        return $this->actingAs($user->fresh());
    }
}
