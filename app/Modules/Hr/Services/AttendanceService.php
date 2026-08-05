<?php

declare(strict_types=1);

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Employee;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * হাজিরা — দিন ধরে বসানো, আর মাস ধরে গোনা।
 *
 * ── কেন গোনাটা এখানে, বেতনের সেবায় নয় ───────────────────────────────
 * "ওই মাসে কতদিন বেতন কাটা যাবে" প্রশ্নটার উত্তর দুই জায়গায় লাগে:
 * বেতনের রান বানানোর সময়, আর হাজিরার পর্দায় মাসের নিচে যোগফল দেখানোর
 * সময়। দুইবার লিখলে একদিন একটায় বিনা বেতনের ছুটির নিয়মটা যোগ হত আর
 * অন্যটায় হত না — আর দুই পর্দায় দুই সংখ্যা দেখাত।
 */
final class AttendanceService
{
    /**
     * একজনের এক দিনের হাজিরা বসানো বা বদলানো।
     *
     * @param  array<string, mixed>  $extra  is_late, in_time, out_time, remarks
     */
    public function mark(Employee $employee, string $date, string $status, array $extra = []): Attendance
    {
        if (! in_array($status, Attendance::STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => __('hr::validation.unknown_attendance_status'),
            ]);
        }

        $day = Carbon::parse($date);

        /*
         * যেদিন সে কর্মীই ছিল না সেদিনের হাজিরা নয়।
         *
         * যোগ দেওয়ার আগের বা ছেড়ে যাওয়ার পরের দিনে সারি বসলে মাসের
         * যোগফল বেড়ে যেত, আর বেতনের হিসাবে সেটা টের পাওয়া যেত না।
         */
        if (! $employee->wasEmployedOn($day)) {
            throw ValidationException::withMessages([
                'work_date' => __('hr::validation.not_employed_then'),
            ]);
        }

        return Attendance::query()->updateOrCreate(
            [
                'employee_id' => $employee->id,
                'work_date' => $day->toDateString(),
            ],
            [
                'status' => $status,
                'is_late' => (bool) ($extra['is_late'] ?? false),
                'in_time' => $extra['in_time'] ?? null,
                'out_time' => $extra['out_time'] ?? null,
                'remarks' => $extra['remarks'] ?? null,
                'created_by' => auth()->id(),
            ],
        )->fresh();
    }

    /**
     * একদিনে অনেকজনের হাজিরা — মাসিক পর্দার সংরক্ষণ।
     *
     * @param  array<int, array{status: string, is_late?: bool, remarks?: string}>  $rows  employee_id => তথ্য
     * @return int কতগুলো সারি বসল
     */
    public function markDay(string $date, array $rows): int
    {
        return DB::transaction(function () use ($date, $rows) {
            $marked = 0;

            $employees = Employee::query()->whereKey(array_keys($rows))->get()->keyBy('id');

            foreach ($rows as $employeeId => $row) {
                $employee = $employees->get((int) $employeeId);

                // ফাঁকা রাখা মানে "এই দিনটা এখনো বসানো হয়নি" — শূন্য নয়
                if ($employee === null || blank($row['status'] ?? null)) {
                    continue;
                }

                $this->mark($employee, $date, $row['status'], $row);
                $marked++;
            }

            return $marked;
        });
    }

    /**
     * একজনের এক মাসের গোনা।
     *
     * @return array{present: int, absent: int, leave: int, holiday: int, late: int, unpaid: string, marked: int}
     */
    public function monthlySummary(Employee $employee, Carbon $month): array
    {
        $rows = $this->rowsFor($employee, $month);

        $count = fn (string $status) => $rows->where('status', $status)->count();

        return [
            'present' => $count(Attendance::PRESENT),
            'absent' => $count(Attendance::ABSENT),
            'leave' => $count(Attendance::LEAVE),
            'holiday' => $count(Attendance::HOLIDAY),
            'late' => $rows->where('is_late', true)->count(),
            'unpaid' => $this->unpaidDays($employee, $month),
            'marked' => $rows->count(),
        ];
    }

    /**
     * বেতন থেকে যত দিন কাটা যাবে।
     *
     * ── কী কাটা যায়, কী নয় ─────────────────────────────────────────
     * অনুপস্থিতি কাটে। বিনা বেতনের ছুটিও কাটে — নাম আলাদা হলেও ফল এক।
     * বেতনসহ ছুটি কাটে না, সাপ্তাহিক ছুটিও নয়।
     *
     * ── কেন হাজিরা না বসালে কিছুই কাটে না ──────────────────────────
     * যে প্রতিষ্ঠান রোজ হাজিরা লেখে না তার খাতা খালি থাকে। খালি দিনকে
     * অনুপস্থিত ধরলে প্রথম মাসেই সবার বেতন শূন্য হয়ে যেত — একটা ফিচার
     * চালু করার শাস্তি হিসেবে।
     *
     * তাই নিয়মটা উল্টো: যা লেখা আছে কেবল তা-ই গোনা হয়।
     */
    public function unpaidDays(Employee $employee, Carbon $month): string
    {
        $rows = $this->rowsFor($employee, $month);

        $unpaid = '0';

        foreach ($rows as $row) {
            if ($row->status === Attendance::ABSENT) {
                $unpaid = bcadd($unpaid, '1', 1);

                continue;
            }

            // মঞ্জুর হওয়া ছুটি, কিন্তু বিনা বেতনের ধরনে
            if ($row->status === Attendance::LEAVE
                && $row->leaveApplication?->leaveType?->is_paid === false) {
                $unpaid = bcadd($unpaid, '1', 1);
            }
        }

        return $unpaid;
    }

    /**
     * @return Collection<int, Attendance>
     */
    private function rowsFor(Employee $employee, Carbon $month): Collection
    {
        return Attendance::query()
            ->with('leaveApplication.leaveType')
            ->where('employee_id', $employee->id)
            ->forMonth(
                $month->copy()->startOfMonth()->toDateString(),
                $month->copy()->endOfMonth()->toDateString(),
            )
            ->orderBy('work_date')
            ->get();
    }
}
