<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Engines\Sync\SyncService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\SyncChange;
use App\Models\User;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Employee;
use App\Modules\MasterData\Models\Department;
use App\Modules\MasterData\Models\Designation;
use App\Modules\MasterData\Models\EmploymentType;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * মাঠের হাজিরা সিঙ্ক — নিজেরটা, একবার, আর সরু একটা চাবিতে।
 *
 * সবচেয়ে জরুরি তিনটা: (১) মাঠকর্মী কেবল নিজের হাজিরা দেন, অন্যের নয়;
 * (২) চাবিটা (`hr.attendance.self`) সত্যিই সেলসম্যানের কাছে পৌঁছেছে —
 * নাহলে সৎ সিঙ্ক লাইভে আটকাত; (৩) দাবি-করা দিন অনেক পুরনো হলে সেটা
 * পর্দায় চোখে পড়ে (ফোনের ঘড়ি বদলানো যায়, তাই চিহ্ন থাকে)।
 */
class AttendanceSyncTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $salesman;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->salesman = User::query()->where('email', 'sales@abos.test')->firstOrFail();
        $this->actingAs($this->salesman);

        // সেলসম্যানের নিজের কর্মী-রেকর্ড — ডেমোতে নেই, তাই এখানে বসানো
        $this->employee = Employee::query()->create([
            'code' => 'E-SR',
            'name_en' => 'Field Rep',
            'name_bn' => 'মাঠকর্মী',
            'user_id' => $this->salesman->id,
            'department_id' => Department::query()->value('id'),
            'designation_id' => Designation::query()->value('id'),
            'employment_type_id' => EmploymentType::query()->value('id'),
            'joining_date' => '2026-01-01',
        ]);
    }

    private function sync(): SyncService
    {
        return app(SyncService::class);
    }

    /** @param array<string, mixed> $payload */
    private function change(string $id, array $payload): array
    {
        return [
            'changeId' => $id,
            'entityType' => 'Attendance',
            'operation' => 'CREATE',
            'payloadJson' => json_encode($payload),
        ];
    }

    /** ★ চাবিটা সত্যিই পৌঁছেছে — নতুন চাবি বসালেই কেউ পায় না, তাই মেপে দেখা। */
    public function test_the_salesman_actually_holds_the_self_permission(): void
    {
        $this->assertTrue($this->salesman->can('hr.attendance.self'),
            'নতুন চাবি hr.attendance.self সেলসম্যানের রোলে পৌঁছায়নি — লাইভে সৎ সিঙ্ক REJECT হত।');
    }

    /** মাঠকর্মী নিজের এক দিনের হাজিরা বসান। */
    public function test_a_worker_marks_their_own_attendance(): void
    {
        $today = now()->toDateString();

        $out = $this->sync()->push($this->salesman, 'phone-a', 'hr', [
            $this->change('a-1', ['workDate' => $today, 'status' => 'present', 'inTime' => '09:05', 'outTime' => '18:00']),
        ]);

        $this->assertSame(SyncChange::APPLIED, $out[0]['status']);

        $attendance = Attendance::query()
            ->where('employee_id', $this->employee->id)
            ->whereDate('work_date', $today)
            ->firstOrFail();

        $this->assertSame('present', $attendance->status);
        $this->assertStringContainsString('09:05', (string) $attendance->in_time);
    }

    /** ⛔ অন্য কারো হাজিরা নয় — নীরবে নিজেরটা নয়, প্রত্যাখ্যান। */
    public function test_marking_someone_elses_attendance_is_refused(): void
    {
        $out = $this->sync()->push($this->salesman, 'phone-a', 'hr', [
            $this->change('a-2', [
                'workDate' => now()->toDateString(),
                'status' => 'present',
                'employeeId' => 'someone-elses-public-id',
            ]),
        ]);

        $this->assertSame(SyncChange::REJECTED, $out[0]['status']);
        $this->assertSame(0, Attendance::query()->where('employee_id', $this->employee->id)->count(),
            'অন্যের employee পাঠানো হলেও নীরবে নিজের হাজিরা বসে গেছে।');
    }

    /** ★ অনুমতি ছাড়া push REJECTED, কিছুই বসে না — গার্ড ভেঙে দেখা। */
    public function test_a_push_without_the_self_permission_is_rejected(): void
    {
        $stranger = User::factory()->create();
        $before = Attendance::query()->count();

        $out = $this->sync()->push($stranger, 'phone-z', 'hr', [
            $this->change('a-3', ['workDate' => now()->toDateString(), 'status' => 'present']),
        ]);

        $this->assertSame(SyncChange::REJECTED, $out[0]['status']);
        $this->assertSame($before, Attendance::query()->count());
    }

    /** একই দিন দুইবার নয় — সংশোধন নেটওয়ার্কে। */
    public function test_the_same_day_cannot_be_marked_twice(): void
    {
        $day = now()->toDateString();

        $first = $this->sync()->push($this->salesman, 'phone-a', 'hr', [
            $this->change('a-4', ['workDate' => $day, 'status' => 'present']),
        ]);
        $second = $this->sync()->push($this->salesman, 'phone-a', 'hr', [
            $this->change('a-5', ['workDate' => $day, 'status' => 'present']),
        ]);

        $this->assertSame(SyncChange::APPLIED, $first[0]['status']);
        // একই দিন = দ্বন্দ্ব (CONFLICT), নিছক প্রত্যাখ্যান নয় — সংশোধন নেটওয়ার্কে
        $this->assertSame(SyncChange::CONFLICT, $second[0]['status']);
    }

    /** ★ ঘড়ি-ফাঁদ — অনেক পুরনো দিন সিঙ্ক হলে remark-এ চিহ্ন, পর্দায় দেখা যায়। */
    public function test_a_late_synced_day_carries_a_visible_note(): void
    {
        $threeDaysAgo = now()->subDays(3)->toDateString();

        $this->sync()->push($this->salesman, 'phone-a', 'hr', [
            $this->change('a-6', ['workDate' => $threeDaysAgo, 'status' => 'present']),
        ]);

        $attendance = Attendance::query()
            ->where('employee_id', $this->employee->id)
            ->whereDate('work_date', $threeDaysAgo)
            ->firstOrFail();

        $this->assertNotNull($attendance->remarks, 'দেরিতে সিঙ্ক হওয়া দিনে ঘড়ির চিহ্ন বসেনি।');
        $this->assertStringContainsString('3', (string) $attendance->remarks);
    }
}
