<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Services\DataScope;
use App\Core\Services\MenuBuilder;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Services\EmployeeService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ অধিকাল আর ছুটির মেনুর সারি দেখা যায় ঠিক তখনই, যখন পাতাটা খোলে — দুই দিকেই (a6, ২১ অক্টোবর ২০২৬; [[MenuBuilder::pageLets()]],
 * [[OvertimeSheetPolicy::viewAny()]], [[LeaveApplicationPolicy::viewAny()]])।
 *
 * দাবি: মালিক, HR, দল থাকা ব্যবস্থাপক, দল ছাড়া ব্যবস্থাপক আর সাধারণ কর্মী — প্রতিজনের বেলায় সারি দেখা যায় ⇔ পাতা ২০০; দল থাকা
 * ব্যবস্থাপক চাবি ছাড়াই দুটোই পান, দল ছাড়া ব্যবস্থাপক আর সাধারণ কর্মী কোনোটাই নয়।
 */
final class TheMenuRowShowsExactlyWhenThePageOpensTest extends TestCase
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

    public function test_the_row_is_visible_if_and_only_if_the_page_opens(): void
    {
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $hr = $this->user(['hr.attendance.view', 'hr.leave.view', 'hr.employee.view']);
        $boss = $this->user([]);
        $loner = $this->user([]);
        $plain = $this->user([]);

        $managed = $this->employee('MN-A', $boss);
        $this->employee('MN-B', $loner);
        $this->employee('MN-C', $plain);
        app(EmployeeService::class)->create([
            'code' => 'MN-W', 'name_en' => 'Team Member', 'joining_date' => '2025-01-01', 'payment_method' => 'cash',
            'reports_to_employee_id' => $managed->id,
        ]);

        $expected = ['owner' => true, 'hr' => true, 'manager with a team' => true, 'manager without a team' => false, 'plain employee' => false];
        $people = ['owner' => $owner, 'hr' => $hr, 'manager with a team' => $boss, 'manager without a team' => $loner, 'plain employee' => $plain];

        foreach (['hr.overtime.index', 'hr.leave.index'] as $route) {
            foreach ($people as $who => $user) {
                $visible = $this->rowVisible($user, $route);
                $status = $this->statusOf($user, $route);

                $this->assertSame($visible, $status === 200, "⛔ {$route} — {$who}: সারি ".($visible ? 'আছে' : 'নেই')." অথচ পাতা {$status}।");
                $this->assertSame($expected[$who], $visible, "⛔ {$route} — {$who}: সারি থাকার কথা ".($expected[$who] ? 'হ্যাঁ' : 'না').'।');
            }
        }
    }

    private function rowVisible(User $user, string $route): bool
    {
        $this->app['auth']->forgetGuards();
        app(DataScope::class)->forget();
        $this->actingAs($user->fresh());

        // ⓘ উদ্ধৃতিসহ — "/hr/leave" যেন "/hr/leave-types"-এর সাথে না মেলে
        return str_contains((string) json_encode(app(MenuBuilder::class)->forUser($user->fresh()), JSON_UNESCAPED_SLASHES), '"'.route($route).'"');
    }

    private function statusOf(User $user, string $route): int
    {
        $this->app['auth']->forgetGuards();
        app(DataScope::class)->forget();

        return $this->actingAs($user->fresh())->get(route($route))->getStatusCode();
    }

    private function employee(string $code, User $user): Employee
    {
        return app(EmployeeService::class)->create([
            'code' => $code, 'name_en' => 'Person '.$code, 'joining_date' => '2025-01-01', 'payment_method' => 'cash', 'user_id' => $user->id,
        ]);
    }

    /** @param list<string> $keys */
    private function user(array $keys): User
    {
        foreach ($keys as $key) {
            Permission::findOrCreate($key, 'web');
        }

        $user = User::factory()->create(['current_company_id' => $this->company->id, 'is_active' => true]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);

        if ($keys !== []) {
            CompanyContext::forCompany($this->company->id, fn () => $user->givePermissionTo($keys));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }
}
