<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\LeaveApplication;
use App\Modules\Hr\Models\LeaveType;
use App\Modules\Hr\Models\PayrollRun;
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

    /**
     * ⛔ বেতন হয়ে যাওয়া মাসে ছুটি মঞ্জুর বা হাজিরা বদল নয় — ec, Hr লাইন-নিরীক্ষা, ১৪ অক্টোবর ২০২৬ (⚠️২; [[PaidMonth]])।
     * ⓘ সেপ্টেম্বরের বেতন নিশ্চিত হওয়ার পর ২০–২১ সেপ্টেম্বরের ছুটি মঞ্জুর হত, আর দিনের পাতাও সে মাসে যা খুশি লিখত।
     */
    public function test_a_paid_month_takes_no_new_leave_and_no_attendance_change(): void
    {
        $run = PayrollRun::acrossBranches()->create([
            'company_id' => $this->company->id, 'branch_id' => $this->company->defaultBranch()?->id, 'document_no' => 'PAY-LV-09',
            'month' => '2026-09-01', 'trx_date' => '2026-09-30',
        ]);
        $run->forceFill(['status' => DocumentStatus::CONFIRMED])->save();

        $application = app(LeaveService::class)->apply($this->employee, $this->type('SICK'), '2026-09-20', '2026-09-21', '2');

        try {
            app(LeaveService::class)->approve($application, $this->owner);
            $this->fail('⛔ বেতন হয়ে যাওয়া মাসের ছুটি মঞ্জুর হল');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('PAY-LV-09', $e->errors()['status'][0]);
        }

        $this->assertSame(LeaveApplication::PENDING, $application->fresh()->status);
        $this->assertSame(0, Attendance::query()->where('employee_id', $this->employee->id)->count(), '⛔ বেতন হয়ে যাওয়া মাসে ছুটির সারি বসল');

        $this->post(route('hr.attendance.store'), [
            'work_date' => '2026-09-20',
            'rows' => [$this->employee->id => ['status' => Attendance::PRESENT]],
        ])->assertSessionHasErrors('work_date');

        $this->assertSame(0, Attendance::query()->where('employee_id', $this->employee->id)->count(), '⛔ বেতন হয়ে যাওয়া মাসের হাজিরা বদলাল');

        // ⓘ পরের মাস খোলা — আগের মতোই চলে
        $this->post(route('hr.attendance.store'), [
            'work_date' => '2026-10-05',
            'rows' => [$this->employee->id => ['status' => Attendance::PRESENT]],
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, Attendance::query()->where('employee_id', $this->employee->id)->count());
    }

    /**
     * ⛔ নামঞ্জুর আর প্রত্যাহার তালার নিচের অবস্থা দেখে — ec, Hr লাইন-নিরীক্ষা, ১৪ অক্টোবর ২০২৬ (⚠️৩; [[LeaveService::reject()]])।
     * ⓘ দুই ট্যাবের দৌড়টা এখানে পুরনো কপি দিয়ে: মঞ্জুরি বসে যাওয়ার পর পুরনো "অপেক্ষমাণ" কপি দিয়ে নামঞ্জুর চাপলে আগে সেটা পার হত।
     */
    public function test_a_stale_copy_cannot_reject_an_approved_leave_or_withdraw_it_twice(): void
    {
        $stale = app(LeaveService::class)->apply($this->employee, $this->type('CASUAL'), '2026-03-02', '2026-03-03', '2');
        app(LeaveService::class)->approve($stale->fresh(), $this->owner);

        try {
            app(LeaveService::class)->reject($stale, $this->owner, 'পরে চাপা');
            $this->fail('⛔ মঞ্জুর হয়ে যাওয়া ছুটি পুরনো কপি দিয়ে নামঞ্জুর হল');
        } catch (ValidationException $e) {
            $this->assertSame(__('hr::validation.leave_already_decided'), $e->errors()['status'][0]);
        }

        $this->assertSame(LeaveApplication::APPROVED, $stale->fresh()->status);
        $this->assertSame(2, Attendance::query()->where('leave_application_id', $stale->id)->count());

        // ⓘ প্রত্যাহার: একটা কপি দিয়ে হয়ে গেছে, তারপর আরেকটা পুরনো "মঞ্জুর" কপি দিয়ে আবার
        $approved = $stale->fresh();
        app(LeaveService::class)->cancel($approved->fresh());

        try {
            app(LeaveService::class)->cancel($approved);
            $this->fail('⛔ প্রত্যাহার হয়ে যাওয়া ছুটি পুরনো কপি দিয়ে আবার প্রত্যাহার হল');
        } catch (ValidationException $e) {
            $this->assertSame(__('hr::validation.leave_already_cancelled'), $e->errors()['status'][0]);
        }
    }

    /**
     * ⛔ একই দিনের দুইটা আবেদন দুটোই মঞ্জুর নয় — ec, Hr লাইন-নিরীক্ষা, ১৪ অক্টোবর ২০২৬ (⚠️৪; [[LeaveService::approve()]])।
     * ⓘ দুইবার চাপায় বসে যাওয়া জোড়াটা এখানে সরাসরি বসানো; আগে মঞ্জুরি আর মেলাত না, তাই দুটোই পার হত আর দিনগুলো দুইবার কাটত।
     */
    public function test_two_applications_for_the_same_days_cannot_both_be_approved(): void
    {
        [$first, $second] = collect([1, 2])->map(fn () => LeaveApplication::query()->create([
            'employee_id' => $this->employee->id, 'leave_type_id' => $this->type('CASUAL')->id,
            'from_date' => '2026-03-02', 'to_date' => '2026-03-03', 'days' => '2', 'status' => LeaveApplication::PENDING,
        ]))->all();

        try {
            app(LeaveService::class)->approve($first, $this->owner);
            $this->fail('⛔ একই দিনের আরেকটা আবেদন থাকতেও মঞ্জুর হল');
        } catch (ValidationException $e) {
            $this->assertSame(__('hr::validation.leave_overlaps'), $e->errors()['from_date'][0]);
        }

        $this->assertSame(LeaveApplication::PENDING, $first->fresh()->status);

        // ⓘ জোড়ার একটা নামঞ্জুর হলে অন্যটা আগের মতোই মঞ্জুর হয়, আর কোটা একবারই কাটে
        app(LeaveService::class)->reject($second, $this->owner, 'দুইবার চাপা');
        $this->assertSame(LeaveApplication::APPROVED, app(LeaveService::class)->approve($first->fresh(), $this->owner)->status);
        $this->assertSame('8.0', app(LeaveService::class)->balance($this->employee, $this->type('CASUAL'), Carbon::parse('2026-03-02'))['left']);
    }

    /**
     * ⛔ দিন তারিখের সাথে মেলে, আর এক আবেদন এক বছরের বেশি নয় — ec, Hr লাইন-নিরীক্ষা, ১৪ অক্টোবর ২০২৬ (⚠️৫; [[LeaveService::apply()]])।
     * ⓘ "৬ তারিখে ২ দিন" পার হত (চারটা শূন্য দিনের ছুটির সারি), আর সাল ভুল টাইপে বিনা বেতনের ছুটি এক বছরের বেশি জুড়ে বসত।
     */
    public function test_the_days_fit_the_dates_and_one_application_spans_at_most_a_year(): void
    {
        try {
            app(LeaveService::class)->apply($this->employee, $this->type('UNPAID'), '2026-10-20', '2026-10-25', '2');
            $this->fail('⛔ ছয় তারিখে দুই দিনের ছুটি পার হল');
        } catch (ValidationException $e) {
            $this->assertSame(__('hr::validation.days_below_range', ['span' => '6', 'least' => '5']), $e->errors()['days'][0]);
        }

        try {
            app(LeaveService::class)->apply($this->employee, $this->type('UNPAID'), '2026-02-01', '2027-02-10', '375');
            $this->fail('⛔ এক বছরের বেশি জুড়ে একটা আবেদন পার হল');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('to_date', $e->errors());
        }

        $this->assertSame(0, LeaveApplication::query()->where('employee_id', $this->employee->id)->count());

        // ⓘ শেষ দিনটা আধা দিন — আগের মতোই চলে
        $this->assertSame(LeaveApplication::PENDING,
            app(LeaveService::class)->apply($this->employee, $this->type('UNPAID'), '2026-10-20', '2026-10-21', '1.5')->status);
    }

    private function type(string $code): LeaveType
    {
        return LeaveType::query()->where('code', $code)->firstOrFail();
    }
}
