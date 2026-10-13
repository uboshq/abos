<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\ExpenseClaim;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\ExpenseClaimService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⛔ চলে যাওয়া, নিষ্ক্রিয় বা মুছে ফেলা কর্মী টাকা চাইতে পারেন না — পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬ (HR ৩;
 * [[ExpenseClaimService::submit()]])।
 *
 * ⓘ কর্মীর খাতা খোঁজা হত সব ছাঁকনি সরিয়ে, তাই মুছে ফেলা খাতাও উঠত; চাকরির অবস্থা দেখা হত না। লগইন খোলা থাকলে চলে যাওয়া কর্মী
 * অগ্রিম চাইতে পারতেন — যা আর কোনো বেতন থেকে কাটা যাবে না।
 */
final class AGoneEmployeeCannotAskForMoneyTest extends TestCase
{
    use RefreshDatabase;

    private User $worker;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->worker = User::factory()->create(['email' => 'gone@claims.test', 'current_company_id' => $company->id, 'is_active' => true]);
        $this->worker->companies()->attach($company->id, ['is_active' => true]);
        CompanyContext::forCompany($company->id, fn () => $this->worker->givePermissionTo(Permission::findOrCreate('hr.claim.self', 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->employee = app(EmployeeService::class)->create(['code' => 'EMP-GONE', 'name_en' => 'Left Us', 'joining_date' => '2026-01-15', 'payment_method' => 'cash']);
        $this->employee->forceFill(['user_id' => $this->worker->id])->save();
    }

    public function test_a_working_employee_may_still_ask(): void
    {
        $this->assertSame(ExpenseClaim::ADVANCE, $this->ask()->kind);
    }

    public function test_an_employee_past_the_leaving_date_is_refused(): void
    {
        $this->employee->forceFill(['leaving_date' => now()->subDays(3)->toDateString()])->save();

        $this->assertRefused('⛔ চাকরি ছাড়ার পরেও অগ্রিম চাওয়া গেল');
    }

    public function test_an_inactive_employee_is_refused(): void
    {
        $this->employee->forceFill(['is_active' => false])->save();

        $this->assertRefused('⛔ নিষ্ক্রিয় কর্মী অগ্রিম চাইলেন');
    }

    public function test_a_deleted_employee_is_refused_on_the_web_door_too(): void
    {
        $this->employee->delete();

        $this->assertRefused('⛔ মুছে ফেলা কর্মীর নামে অগ্রিম চাওয়া গেল');

        $this->actingAs($this->worker);
        $this->post(route('hr.claim.store'), ['kind' => ExpenseClaim::ADVANCE, 'amount' => '500', 'reason' => 'Need it'])
            ->assertSessionHasErrors('employee');
        $this->assertSame(0, ExpenseClaim::query()->count());
    }

    private function ask(): ExpenseClaim
    {
        return app(ExpenseClaimService::class)->submit($this->worker, ['kind' => ExpenseClaim::ADVANCE, 'amount' => '500', 'reason' => 'Need it']);
    }

    private function assertRefused(string $why): void
    {
        try {
            $this->ask();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('employee', $e->errors(), $why);
            $this->assertSame(__('hr::claim.employee_gone'), $e->errors()['employee'][0]);
            $this->assertSame(0, ExpenseClaim::query()->count(), $why);

            return;
        }

        $this->fail($why);
    }
}
