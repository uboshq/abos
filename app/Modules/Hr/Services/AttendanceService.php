<?php

declare(strict_types=1);

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Support\PaidMonth;
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

        /*
         * ⛔ বেতন হয়ে যাওয়া মাসের হাজিরা বদলায় না — ec, Hr লাইন-নিরীক্ষা, ১৪ অক্টোবর ২০২৬ (⚠️২; [[PaidMonth]])।
         * ⓘ বেতনশিট এই হাজিরা ধরেই হিসাব হয়ে খাতায় বসে গেছে; পরে "কামাই"-কে "উপস্থিত" করলে পাতা আর বেতনশিট আলাদা কথা বলত।
         */
        PaidMonth::assertOpen($day, $day, 'work_date', 'hr::validation.attendance_month_paid');

        $values = [
            'status' => $status,
            'is_late' => (bool) ($extra['is_late'] ?? false),
            'remarks' => $extra['remarks'] ?? null,
            'created_by' => auth()->id(),
        ];

        /*
         * ⛔ ঢোকা-বেরোনোর সময় কেবল পাঠানো হলে — ec, Hr লাইন-নিরীক্ষা, ১৪ অক্টোবর ২০২৬ (⚠️১; [[AttendanceAndLeaveStayInsideTheBranchTest]])।
         * ⓘ অফিসের দিনের পাতায় সময়ের ঘর নেই, তবু প্রতিটা সংরক্ষণ সময় দুইটা ফাঁকা করে লিখত — সকালে ফোনে ৯:১২-এ ঢোকা সবার সময়
         * বিকেলে দুইজনের কামাই বসাতেই মুছে যেত। এখন ঘরটা পাঠানো হলে তবেই লেখা হয়; না পাঠালে আগের সময় থাকে।
         */
        foreach (['in_time', 'out_time'] as $clock) {
            if (array_key_exists($clock, $extra)) {
                $values[$clock] = $extra[$clock];
            }
        }

        /*
         * ⛔ কর্মীর সারিতে তালা, তারপর দিনের সারি — ec, Hr লাইন-নিরীক্ষা, ১৪ অক্টোবর ২০২৬ (ⓘ১২; [[Attendance::writeDay()]])।
         * ⓘ একই দিন দুইজন একসাথে সংরক্ষণ করলে দ্বিতীয়জন এক-দিনে-এক-সারির চাবিতে ঠেকে ৫০০ পেতেন; এখন একই কর্মীর লেখা
         * একটার পর একটা, আর দ্বিতীয়টা আগেরটার উপর বদল হয়ে বসে। ছুটির মঞ্জুরি আর প্রত্যাহারও আগে এই সারিতেই তালা দেয়।
         */
        return DB::transaction(function () use ($employee, $day, $values) {
            Employee::query()->withoutGlobalScopes()->whereKey($employee->id)->lockForUpdate()->first();

            return Attendance::writeDay($employee->id, $day->toDateString(), $values)->fresh();
        });
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

            // ⓘ কর্মীর তালা সবসময় id-র ক্রমে — দুইটা পাতা উল্টো ক্রমে পাঠালেও একে অন্যকে আটকে না রাখে (ec, Hr লাইন-নিরীক্ষা, ১৪ অক্টোবর ২০২৬)
            ksort($rows);

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
            // ⓘ আধা দিনের ছুটি আধা দিনই কাটে ([[Attendance::leaveShare()]], পুনঃঅডিট ৯ অক্টোবর ২০২৬, HR ৮)
            if ($row->status === Attendance::LEAVE
                && $row->leaveApplication?->leaveType?->is_paid === false) {
                $unpaid = bcadd($unpaid, $row->leaveShare(), 1);
            }
        }

        return $unpaid;
    }

    /**
     * একজনের এক মাসের দিনগুলো — কর্মীর প্রোফাইলের হাজিরার ক্যালেন্ডারের জন্য (১ অক্টোবর ২০২৬)।
     *
     * ⓘ [[monthlySummary()]]-এর একই সারি, দিন ধরে — তাই ক্যালেন্ডার আর উপরের গোনা কখনো আলাদা হয় না।
     * ⚠️ যে দিনের সারি নেই সেটা এখানে নেই; "লেখা হয়নি" আর "অনুপস্থিত" এক কথা নয় ([[unpaidDays()]]-এর নিয়ম)।
     *
     * @return array<string, array{status: string, late: bool}> তারিখ (Y-m-d) => অবস্থা
     */
    public function days(Employee $employee, Carbon $month): array
    {
        $out = [];

        foreach ($this->rowsFor($employee, $month) as $row) {
            $out[$row->work_date->toDateString()] = ['status' => (string) $row->status, 'late' => (bool) $row->is_late];
        }

        return $out;
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
