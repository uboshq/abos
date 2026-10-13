<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Services\PhoneModules;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\LeaveApplication;
use App\Modules\Hr\Models\LeaveType;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⛔ মুছে ফেলা বা নিষ্ক্রিয় কর্মী ফোনে ছুটি চান না, আর ওয়েবের ফর্ম বন্ধ ধরনের ছুটি নেয় না — ec, Hr লাইন-নিরীক্ষা, ১৪ অক্টোবর ২০২৬
 * (ⓘ১৬, ⓘ১৭; [[LeaveApiController::store()]], [[LeaveController::store()]])।
 *
 * ⓘ ফোনের কর্মী-খোঁজা সব ছাঁকনি সরিয়ে চলে, তাই মুছে ফেলা খাতাও উঠত; ছাড়ার তারিখ না থাকলে আর লগইন খোলা থাকলে তিনি আবেদন
 * করতে পারতেন — খরচের দাবিতে এই পাহারা আগেই ছিল ([[AGoneEmployeeCannotAskForMoneyTest]])। ওয়েবের ফর্ম ধরনের কেবল কোম্পানি
 * দেখত, চালু কি না দেখত না; ফোন দেখত।
 */
final class AGoneEmployeeOrAClosedLeaveTypeTakesNoLeaveTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $worker;

    private Employee $employee;

    private LeaveType $annual;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        app(SettingsService::class)->set(PhoneModules::key('hr'), true);

        $this->worker = User::factory()->create(['email' => 'gone@leave.test', 'current_company_id' => $this->company->id, 'is_active' => true]);
        $this->worker->companies()->attach($this->company->id, ['is_active' => true]);
        CompanyContext::forCompany($this->company->id, fn () => $this->worker->givePermissionTo(Permission::findOrCreate('hr.leave.self', 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->employee = Employee::query()->create([
            'branch_id' => CompanyContext::branchId(), 'code' => 'EMP-LGONE', 'name_en' => 'Gone On Leave', 'joining_date' => '2026-01-01',
        ]);
        $this->employee->forceFill(['user_id' => $this->worker->id])->save();

        $this->annual = LeaveType::query()->create([
            'code' => 'GN-AL', 'name_en' => 'Annual', 'name_bn' => 'বার্ষিক', 'days_per_year' => 5, 'is_paid' => true, 'is_active' => true,
        ]);
    }

    public function test_a_working_employee_may_still_ask_from_the_phone(): void
    {
        $this->ask()->assertCreated();
    }

    public function test_an_inactive_employee_is_refused_on_the_phone(): void
    {
        $this->employee->forceFill(['is_active' => false])->save();

        $this->ask()->assertStatus(422)->assertJsonPath('errors.employee.0', __('hr::message.leave_employee_gone'));
        $this->assertSame(0, LeaveApplication::query()->count(), '⛔ নিষ্ক্রিয় কর্মী ফোনে ছুটির আবেদন বসালেন');
    }

    public function test_a_deleted_employee_is_refused_on_the_phone(): void
    {
        $this->employee->delete();

        $this->ask()->assertStatus(422)->assertJsonPath('errors.employee.0', __('hr::message.leave_employee_gone'));
        $this->assertSame(0, LeaveApplication::query()->withTrashed()->count(), '⛔ মুছে ফেলা কর্মীর নামে ছুটির আবেদন বসল');
    }

    public function test_the_web_form_refuses_a_switched_off_leave_type(): void
    {
        $this->annual->forceFill(['is_active' => false])->save();

        $this->actingAs($this->owner)->post(route('hr.leave.store'), [
            'employee_id' => $this->employee->id, 'leave_type_id' => $this->annual->id,
            'from_date' => '2026-10-20', 'to_date' => '2026-10-20', 'days' => '1',
        ])->assertSessionHasErrors(['leave_type_id' => __('hr::validation.leave_type_closed')]);

        $this->assertSame(0, LeaveApplication::query()->count(), '⛔ বন্ধ করা ধরনের ছুটি ওয়েবে বসল');

        // ⓘ চালু ধরন আগের মতোই চলে
        $this->annual->forceFill(['is_active' => true])->save();
        $this->post(route('hr.leave.store'), [
            'employee_id' => $this->employee->id, 'leave_type_id' => $this->annual->id,
            'from_date' => '2026-10-20', 'to_date' => '2026-10-20', 'days' => '1',
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, LeaveApplication::query()->count());
    }

    private function ask(): TestResponse
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->worker->fresh(), [AuthController::APP]);

        return $this->postJson('/api/v1/hr/leave', [
            'leave_type' => $this->annual->public_id,
            'from_date' => Carbon::today()->addDays(10)->toDateString(),
            'to_date' => Carbon::today()->addDays(10)->toDateString(),
            'days' => 1,
        ]);
    }
}
