<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Services\PhoneModules;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use App\Modules\Hr\Models\ExpenseClaim;
use App\Modules\Hr\Services\EmployeeService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⛔ দাবির অঙ্কে "1e5" — কারণসহ ফেরত, ৫০০ নয় (পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬, HR ৪; [[ExpenseClaimController]])।
 *
 * ⓘ `numeric` নিয়ম "1e5" মেনে নেয়, আর bcmath সেটা পড়তে না পেরে পাতাটা ভেঙে দিত — ওয়েবে আর ফোনে দুই দরজাতেই।
 */
final class AClaimOfOneE5IsAReasonNotACrashTest extends TestCase
{
    use RefreshDatabase;

    private User $worker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->worker = User::factory()->create(['email' => 'e5@claims.test', 'current_company_id' => $company->id, 'is_active' => true]);
        $this->worker->companies()->attach($company->id, ['is_active' => true]);
        CompanyContext::forCompany($company->id, fn () => $this->worker->givePermissionTo(Permission::findOrCreate('hr.claim.self', 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $employee = app(EmployeeService::class)->create(['code' => 'EMP-E5', 'name_en' => 'Typed Fast', 'joining_date' => '2026-01-15', 'payment_method' => 'cash']);
        $employee->forceFill(['user_id' => $this->worker->id])->save();
    }

    public function test_the_web_door_gives_a_reason(): void
    {
        $this->actingAs($this->worker);

        foreach (['1e5', '100.555', '0x10'] as $amount) {
            $this->post(route('hr.claim.store'), ['kind' => ExpenseClaim::ADVANCE, 'amount' => $amount, 'reason' => 'Need it'])
                ->assertSessionHasErrors('amount');
        }

        $this->assertSame(0, ExpenseClaim::query()->count());

        $this->post(route('hr.claim.store'), ['kind' => ExpenseClaim::ADVANCE, 'amount' => '100000.50', 'reason' => 'Need it'])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, ExpenseClaim::query()->count(), 'ঠিক অঙ্ক আগের মতোই যায়');
    }

    public function test_the_phone_door_gives_a_reason(): void
    {
        app(SettingsService::class)->set(PhoneModules::PREFIX.'hr', true);
        Sanctum::actingAs($this->worker->fresh(), [AuthController::APP]);

        $this->postJson(route('api.hr.claim.store'), ['kind' => ExpenseClaim::ADVANCE, 'amount' => '1e5', 'reason' => 'Need it'])
            ->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->assertSame(0, ExpenseClaim::query()->count());
    }
}
