<?php

declare(strict_types=1);

namespace Tests\Feature\Widgets;

use App\Core\Security\FieldSecurity;
use App\Core\Services\PhoneModules;
use App\Core\Support\CompanyContext;
use App\Core\Widgets\WidgetSummaries;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\LeaveType;
use App\Modules\Hr\Services\AttendanceService;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\LeaveService;
use App\Modules\Hr\Widgets\AttendanceSummary;
use App\Modules\MasterData\Services\MasterListService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ "আমার উপস্থিতি" উইজেট — কেবল নিজের দিন, বেতন ছাড়া (১৯ নম্বর; a6, ২০ অক্টোবর ২০২৬; [[AttendanceSummary]])।
 *
 * দাবি (আসল দরজা দিয়ে — `GET /api/v1/widgets/summary?keys=attendance`):
 *  - আজ ০৯:১২-এ দেরিতে এলেন, মাসে দুই দিন কামাই, এক দিন ছুটি: আজকের অবস্থা, সময়, দেরি আর মাসের গোনা ঠিক;
 *    `leave_left` [[LeaveService::balance()]]-এর বাকির যোগের হুবহু।
 *  - সহকর্মীর হাজিরা কখনো আসে না — প্রত্যেকে নিজেরটা।
 *  - চাবি (`hr.attendance.self`) ছাড়া উত্তরে `attendance` নেই।
 *  - লগইনের সাথে কর্মীর খাতা নেই: কেবল `{"employee": false}`।
 *  - ⛔ উত্তরে salary, gross, net, pay — কোনো শব্দই নেই।
 */
final class MyAttendanceWidgetShowsOnlyMyOwnDaysTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $worker;

    private User $colleague;

    private Employee $workerFile;

    private LeaveType $type;

    protected function setUp(): void
    {
        parent::setUp();
        // ⓘ দিন স্থির — মাসের আগের দিনগুলোয় কামাই আর ছুটি বসানো যায়
        $this->travelTo(Carbon::parse('2026-10-20 10:00:00'));
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        app(MasterListService::class)->installDefaults();

        $this->worker = $this->staff('hr.attendance.self');
        $this->colleague = $this->staff('hr.attendance.self');
        $this->workerFile = $this->employee('WGT-W', $this->worker);
        $colleagueFile = $this->employee('WGT-C', $this->colleague);

        $this->type = LeaveType::create(['code' => 'WGTLV', 'name_en' => 'Widget Leave', 'name_bn' => 'উইজেট ছুটি', 'days_per_year' => 10, 'is_paid' => true, 'is_active' => true]);

        $marks = app(AttendanceService::class);
        $marks->mark($this->workerFile, '2026-10-20', 'present', ['is_late' => true, 'in_time' => '09:12']);
        $marks->mark($this->workerFile, '2026-10-06', 'absent');
        $marks->mark($this->workerFile, '2026-10-07', 'absent');
        $marks->mark($colleagueFile, '2026-10-20', 'present', ['is_late' => false, 'in_time' => '08:01']);
        $marks->mark($colleagueFile, '2026-10-08', 'absent');

        $leave = app(LeaveService::class);
        $leave->approve($leave->apply($this->workerFile, $this->type, '2026-10-05', '2026-10-05', '1'), $this->owner);

        WidgetSummaries::$testing = [AttendanceSummary::class];
    }

    protected function tearDown(): void
    {
        WidgetSummaries::$testing = [];
        parent::tearDown();
    }

    public function test_my_day_my_month_and_my_leave_left_come_from_the_hr_screens(): void
    {
        $this->assertTrue(app(PhoneModules::class)->isOn('hr'), 'প্রস্তুতিটাই ভুল — ফোনে HR বন্ধ');

        $expectedLeft = null;
        foreach (LeaveType::query()->active()->get() as $type) {
            $left = app(LeaveService::class)->balance($this->workerFile, $type)['left'];
            if ($left !== null) {
                $expectedLeft = bcadd($expectedLeft ?? '0', $left, 1);
            }
        }
        $this->assertSame('9.0', app(LeaveService::class)->balance($this->workerFile, $this->type)['left'], 'প্রস্তুতিটাই ভুল — ছুটি মঞ্জুর হয়নি');

        $widget = $this->widget($this->worker);

        $this->assertSame(['status' => 'present', 'in_time' => '09:12', 'late' => true], $widget['today'], '⛔ আজকের দিন ভুল');
        $this->assertSame(['present' => 1, 'absent' => 2, 'leave' => 1, 'late' => 1], $widget['month'], '⛔ মাসের গোনা HR-এর পর্দার সাথে মেলে না');
        $this->assertSame($expectedLeft, $widget['leave_left'], '⛔ ছুটি বাকি LeaveService::balance-এর সাথে মেলে না');
        $this->assertSame('/home/attendance', $widget['link']);

        $json = strtolower((string) json_encode($widget));
        foreach (['salary', 'gross', 'net', 'pay'] as $word) {
            $this->assertStringNotContainsString($word, $json, "⛔ উপস্থিতির উইজেটে বেতনের শব্দ «{$word}»");
        }
    }

    public function test_a_colleagues_attendance_never_shows(): void
    {
        $mine = $this->widget($this->worker);
        $this->assertSame('09:12', $mine['today']['in_time'], '⛔ সহকর্মীর ঢোকার সময় আমার উইজেটে');

        $theirs = $this->widget($this->colleague);
        $this->assertSame(['status' => 'present', 'in_time' => '08:01', 'late' => false], $theirs['today'], '⛔ সহকর্মী নিজের দিন পাননি');
        $this->assertSame(['present' => 1, 'absent' => 1, 'leave' => 0, 'late' => 0], $theirs['month'], '⛔ সহকর্মীর গোনায় অন্যের দিন');
    }

    public function test_without_the_key_there_is_no_attendance_widget(): void
    {
        $nobody = $this->staff();
        $this->employee('WGT-N', $nobody);

        $this->phone($nobody);
        $widgets = $this->getJson('/api/v1/widgets/summary?keys=attendance')->assertOk()->json('widgets');
        $this->assertArrayNotHasKey('attendance', $widgets, '⛔ চাবি ছাড়াই উপস্থিতির উইজেট');
    }

    public function test_a_login_without_an_employee_file_says_only_so(): void
    {
        $this->assertSame(['employee' => false], $this->widget($this->staff('hr.attendance.self')), '⛔ কর্মীর খাতা নেই, তবু হাজিরার ঘর');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function widget(User $user): array
    {
        $this->phone($user);
        $widgets = $this->getJson('/api/v1/widgets/summary?keys=attendance')->assertOk()->json('widgets');
        $this->assertArrayHasKey('attendance', $widgets, 'প্রস্তুতিটাই ভুল — চাবিওয়ালা কর্মী উইজেট পাননি');

        return $widgets['attendance'];
    }

    private function phone(User $user): void
    {
        $this->app['auth']->forgetGuards();
        FieldSecurity::forget();
        Sanctum::actingAs($user->fresh(), [AuthController::APP]);
    }

    private function employee(string $code, User $user): Employee
    {
        $employee = app(EmployeeService::class)->create([
            'code' => $code, 'name_en' => 'Widget '.$code, 'joining_date' => '2026-01-15', 'payment_method' => 'cash',
        ]);
        $employee->forceFill(['user_id' => $user->id])->save();

        return $employee->fresh();
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
}
