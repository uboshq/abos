<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\LeaveApplication;
use App\Modules\Hr\Models\LeaveType;
use App\Modules\Hr\Services\AttendanceService;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\LeaveService;
use App\Modules\MasterData\Services\MasterListService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⛔ ছুটি — মঞ্জুরের মুহূর্তে কোটা, নিজের ছুটি নিজে নয়, আধা দিন আধা দিনই (পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬, HR ৮;
 * [[LeaveService::approve()]], [[Attendance::shareOf()]])।
 *
 * ⓘ কোটা মাপা হত কেবল আবেদনের সময়, মঞ্জুর হওয়াগুলো ধরে — অপেক্ষমাণ তিনটা আবেদন একে একে মঞ্জুর করলে বছরের সীমা ছাড়াত।
 * ছুটি মঞ্জুরের চাবি থাকলে নিজের আবেদনও মঞ্জুর করা যেত। আর "০.৫ দিন" বিনা বেতনের ছুটি বেতন থেকে পুরো দিন কাটত।
 */
final class LeaveIsCountedWhenItIsApprovedTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->owner);

        app(MasterListService::class)->installDefaults();
        $this->post(route('hr.leave_type.install'));

        $this->employee = app(EmployeeService::class)->create(['code' => 'EMP-LV', 'name_en' => 'On Leave', 'joining_date' => '2026-01-15', 'payment_method' => 'cash']);
    }

    public function test_pending_applications_approved_one_after_another_cannot_pass_the_yearly_limit(): void
    {
        // ⓘ নৈমিত্তিক ছুটি বছরে ১০ দিন — চারটা অপেক্ষমাণ আবেদন, প্রতিটা ৩ দিন; আবেদনের সময় প্রত্যেকটা আলাদাভাবে পার হয়
        $applications = collect(['2026-03-02', '2026-04-06', '2026-05-04', '2026-06-01'])->map(fn (string $from) => app(LeaveService::class)
            ->apply($this->employee, $this->type('CASUAL'), $from, Carbon::parse($from)->addDays(2)->toDateString(), '3'));

        foreach ($applications->take(3) as $application) {
            app(LeaveService::class)->approve($application, $this->owner);
        }

        $this->assertThrows(fn () => app(LeaveService::class)->approve($applications->last(), $this->owner), ValidationException::class);
        $this->assertSame(LeaveApplication::PENDING, $applications->last()->fresh()->status, '⛔ বছরের ১০ দিনের সীমা ছাড়িয়ে ১২ দিন মঞ্জুর হল');
        $this->assertSame('1.0', app(LeaveService::class)->balance($this->employee, $this->type('CASUAL'), Carbon::parse('2026-07-01'))['left']);
    }

    public function test_a_manager_cannot_approve_their_own_leave(): void
    {
        $manager = User::factory()->create(['email' => 'mgr@leave.test', 'current_company_id' => $this->company->id, 'is_active' => true]);
        $manager->companies()->attach($this->company->id, ['is_active' => true]);
        CompanyContext::forCompany($this->company->id, fn () => $manager->givePermissionTo(Permission::findOrCreate('hr.leave.approve', 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->employee->forceFill(['user_id' => $manager->id])->save();

        $application = app(LeaveService::class)->apply($this->employee, $this->type('CASUAL'), '2026-03-02', '2026-03-02', '1');

        try {
            app(LeaveService::class)->approve($application, $manager);
            $this->fail('⛔ ব্যবস্থাপক নিজের ছুটি নিজে মঞ্জুর করলেন');
        } catch (ValidationException $e) {
            $this->assertSame(__('hr::validation.leave_own_approval'), $e->errors()['status'][0]);
        }

        $this->assertSame(LeaveApplication::PENDING, $application->fresh()->status);

        // ⓘ আরেকজন (মালিক) মঞ্জুর করতে পারেন
        $this->assertSame(LeaveApplication::APPROVED, app(LeaveService::class)->approve($application->fresh(), $this->owner)->status);
    }

    public function test_a_half_day_of_unpaid_leave_takes_half_a_day_of_salary(): void
    {
        // ⓘ বিনা বেতনের ছুটি: ১ আগস্ট আধা দিন, আর ১০–১১ আগস্ট দেড় দিন — মোট ২ দিন, তারিখ ৩টা
        foreach ([['2026-08-01', '2026-08-01', '0.5'], ['2026-08-10', '2026-08-11', '1.5']] as [$from, $to, $days]) {
            app(LeaveService::class)->approve(app(LeaveService::class)->apply($this->employee, $this->type('UNPAID'), $from, $to, $days), $this->owner);
        }

        $this->assertSame('2.0', app(AttendanceService::class)->unpaidDays($this->employee, Carbon::parse('2026-08-01')),
            '⛔ আধা দিনের ছুটি পুরো দিন কাটল');
    }

    private function type(string $code): LeaveType
    {
        return LeaveType::query()->where('code', $code)->firstOrFail();
    }
}
