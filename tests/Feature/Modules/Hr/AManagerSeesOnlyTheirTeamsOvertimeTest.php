<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\OvertimeSheet;
use App\Modules\Hr\Services\EmployeeService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ অধিকালের তালিকা — হাজিরার চাবি ছাড়া ব্যবস্থাপক কেবল নিজের দলের শিট দেখেন আর তাতেই সই দেন; অন্য দলের শিটে সই ৪০৩; দল নেই,
 * চাবি নেই — তালিকা ৪০৩ (ছুটির একই নিয়ম; fe, ২১ অক্টোবর ২০২৬, দফা ৩; [[OvertimeSheetPolicy]])।
 */
final class AManagerSeesOnlyTheirTeamsOvertimeTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        Employee::query()->delete();
    }

    public function test_a_manager_without_the_key_sees_and_signs_only_their_own_team(): void
    {
        [$managerA, $userA] = $this->manager('OTM-A');
        [$managerB] = $this->manager('OTM-B');
        $mine = $this->sheet($this->worker('OTW-1', 'Mine Worker', $managerA));
        $theirs = $this->sheet($this->worker('OTW-2', 'Other Worker', $managerB));

        $this->actingAs($userA)->get(route('hr.overtime.index', ['month' => '2026-07']))->assertOk()
            ->assertSee('Mine Worker')->assertDontSee('Other Worker');

        $this->actingAs($userA)->post(route('hr.overtime.sign', $theirs->id))->assertForbidden();
        $this->assertFalse($theirs->fresh()->isSigned(), '⛔ অন্য দলের শিটে সই বসল।');

        $this->actingAs($userA)->post(route('hr.overtime.sign', $mine->id))->assertRedirect();
        $this->assertTrue($mine->fresh()->isSigned(), '⛔ নিজের দলের শিটে সই বসেনি।');
    }

    public function test_someone_with_no_team_and_no_key_cannot_open_the_list(): void
    {
        $this->actingAs($this->user())->get(route('hr.overtime.index'))->assertForbidden();
    }

    /** @return array{0: Employee, 1: User} */
    private function manager(string $code): array
    {
        $user = $this->user();
        $employee = app(EmployeeService::class)->create([
            'code' => $code, 'name_en' => 'Manager '.$code, 'joining_date' => '2025-01-01', 'payment_method' => 'cash', 'user_id' => $user->id,
        ]);

        return [$employee, $user];
    }

    private function worker(string $code, string $name, Employee $manager): Employee
    {
        return app(EmployeeService::class)->create([
            'code' => $code, 'name_en' => $name, 'joining_date' => '2025-01-01', 'payment_method' => 'cash',
            'reports_to_employee_id' => $manager->id,
        ]);
    }

    private function sheet(Employee $employee): OvertimeSheet
    {
        return OvertimeSheet::query()->create([
            'company_id' => $this->company->id, 'employee_id' => $employee->id, 'month' => '2026-07-01',
            'hours' => '3.5', 'days' => 2, 'status' => OvertimeSheet::WAITING,
            'created_by' => User::query()->where('email', 'owner@abos.test')->value('id'),
        ]);
    }

    private function user(): User
    {
        $user = User::factory()->create(['current_company_id' => $this->company->id, 'is_active' => true]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);

        return $user->fresh();
    }
}
