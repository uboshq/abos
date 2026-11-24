<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\SalaryHead;
use App\Modules\Hr\Models\SalaryStructure;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\ProvidentFund;
use App\Modules\Hr\Services\SalaryHeadService;
use App\Modules\Hr\Services\SalaryStructureService;
use App\Modules\MasterData\Services\MasterListService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ ভবিষ্য তহবিল — প্রস্তাব কেবল সদস্যদের, এক বছরের অ-সদস্যরা আলাদা তালিকায়, যোগ আলাদা কাজ; প্রাপ্তির ধাপ সেটিংয়ে, খালি = বসানো নেই
 * (BLA ধারা ২৬৪, ২৬৭; fe, ২১ অক্টোবর ২০২৬, দফা ১; [[ProvidentFund]])।
 */
final class TheFundTakesOnlyThoseWhoJoinTest extends TestCase
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

        app(StandardChart::class)->install();
        app(MasterListService::class)->installDefaults();
        app(SalaryHeadService::class)->installDefaults();
        Employee::query()->delete();
    }

    public function test_eligible_non_members_are_listed_apart_and_nobody_is_enrolled_by_the_proposal(): void
    {
        $this->employee('PF-M', '2025-01-01', '10');
        $candidate = $this->employee('PF-C', '2025-08-01', null);
        $this->employee('PF-N', '2026-06-01', null);

        $list = collect(app(ProvidentFund::class)->eligibleNonMembers(Carbon::parse('2026-08-01')))->map(fn ($r) => $r['employee']->code)->all();
        $this->assertSame(['PF-C'], $list, '⛔ যোগ্য অ-সদস্যের তালিকায় সদস্য বা এক বছর না হওয়া কেউ।');

        app(ProvidentFund::class)->apply(Carbon::parse('2026-08-01'), $this->owner);
        $this->assertSame(0, $this->pfRows($candidate), '⛔ প্রস্তাব বসাতেই অ-সদস্য তহবিলে ঢুকলেন।');

        $this->get(route('hr.pf.proposal', ['on' => '2026-08-01']))->assertOk()
            ->assertSee('data-pf-candidates', false)->assertSee($candidate->label());
    }

    public function test_enrolment_is_its_own_action_for_the_eligible_only(): void
    {
        $member = $this->employee('PF-M', '2025-01-01', '10');
        $candidate = $this->employee('PF-C', '2025-08-01', null);
        $newcomer = $this->employee('PF-N', '2026-06-01', null);
        $fund = app(ProvidentFund::class);

        $this->assertNull($fund->enrol($candidate, Carbon::parse('2026-08-01'), $this->owner));
        $this->assertSame(0, bccomp((string) SalaryStructure::query()->where('employee_id', $candidate->id)
            ->where('salary_head_id', $this->salaryHead('PF')->id)->value('amount'), '8', 4), '⛔ যোগ দিলেন, অথচ ৮% বসেনি।');
        $this->assertTrue(AuditTrail::query()->forRecord(Employee::class, (int) $candidate->id)->where('action', ProvidentFund::ENROLLED)->exists());

        foreach ([$newcomer, $member] as $who) {
            try {
                $fund->enrol($who, Carbon::parse('2026-08-01'), $this->owner);
                $this->fail('⛔ যোগ্য নন, অথচ তহবিলে ঢুকলেন: '.$who->code);
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }

        $clerk = $this->member(['hr.salary.view', 'hr.employee.view']);
        $this->actingAs($clerk)->post(route('hr.pf.enrol', $newcomer->id), ['effective_from' => '2026-08-01'])->assertForbidden();

        // ⓘ সেবাও নিজে দেখে — ফোন বা কনসোলের দরজা দিয়েও চাবি ছাড়া যোগ হয় না
        $other = $this->employee('PF-D', '2025-01-01', null);
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        $fund->enrol($other, Carbon::parse('2026-08-01'), $clerk);
    }

    public function test_vesting_is_not_set_until_the_company_sets_it(): void
    {
        $fund = app(ProvidentFund::class);
        $this->assertNull($fund->vestedEmployerShare('12345.67', 10), '⛔ ধাপ বসানো নেই, অথচ প্রাপ্য বলা হলো — খালি মানে ১০০% নয়।');

        app(SettingsService::class)->set(ProvidentFund::VESTING_STEPS, '5:100, 3:50');
        $this->assertSame('0.00', $fund->vestedEmployerShare('12345.67', 2));
        $this->assertSame('6172.84', $fund->vestedEmployerShare('12345.67', 3), '⛔ ৩ বছরে ৫০% = ৬১৭২.৮৪ (পয়সায় গোল)।');
        $this->assertSame('6172.84', $fund->vestedEmployerShare('12345.67', 4));
        $this->assertSame('12345.67', $fund->vestedEmployerShare('12345.67', 7));

        app(SettingsService::class)->set(ProvidentFund::VESTING_STEPS, '3:fifty');
        $this->assertNull($fund->vestedEmployerShare('12345.67', 7), '⛔ পড়া যায় না এমন ধাপকে বসানো ধরা হলো।');
    }

    private function salaryHead(string $code): SalaryHead
    {
        return SalaryHead::query()->where('code', $code)->firstOrFail();
    }

    private function pfRows(Employee $employee): int
    {
        return SalaryStructure::query()->where('employee_id', $employee->id)->where('salary_head_id', $this->salaryHead('PF')->id)->count();
    }

    private function employee(string $code, string $joined, ?string $pf): Employee
    {
        $employee = app(EmployeeService::class)->create([
            'code' => $code, 'name_en' => 'Person '.$code, 'joining_date' => $joined, 'payment_method' => 'cash',
        ]);
        $salaries = app(SalaryStructureService::class);
        $salaries->set($employee, $this->salaryHead('BASIC'), $joined, '20000');

        if ($pf !== null) {
            $salaries->set($employee, $this->salaryHead('PF'), $joined, $pf);
        }

        return $employee->fresh();
    }

    /** @param list<string> $keys */
    private function member(array $keys): User
    {
        foreach ($keys as $key) {
            Permission::findOrCreate($key, 'web');
        }

        $user = User::factory()->create(['current_company_id' => $this->company->id, 'is_active' => true]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);
        CompanyContext::forCompany($this->company->id, fn () => $user->givePermissionTo($keys));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }
}
