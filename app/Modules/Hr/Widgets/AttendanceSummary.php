<?php

declare(strict_types=1);

namespace App\Modules\Hr\Widgets;

use App\Core\Widgets\WidgetSummary;
use App\Models\User;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\LeaveType;
use App\Modules\Hr\Services\AttendanceService;
use App\Modules\Hr\Services\ExpenseClaimService;
use App\Modules\Hr\Services\LeaveService;
use Illuminate\Support\Carbon;

/**
 * ⭐ "আমার উপস্থিতি" উইজেট (১৯ নম্বর, ২×১) — আজ কখন এলাম, এই মাসের গোনা, ছুটি কত বাকি (a6, ২০ অক্টোবর ২০২৬;
 * [[WidgetSummary]])।
 *
 * ⛔ কেবল নিজের: কর্মী আসে লগইন থেকে ([[ExpenseClaimService::employeeOf()]] — ফোনের ছুটি আর বেতনশিটের একই প্রশ্ন), ফোনের
 * পাঠানো কিছু থেকে নয়। লগইনের সাথে কর্মীর খাতা বাঁধা না থাকলে কেবল `{"employee": false}`।
 *
 * ⭐ অঙ্ক HR-এর পর্দার একই উৎস থেকে: মাসের গোনা [[AttendanceService::monthlySummary()]], ছুটি [[LeaveService::balance()]]
 * — ফোনের ছুটির পর্দার ([[LeaveApiController::mine()]]) একই চালু ধরনগুলো।
 *
 * ⛔ বেতন নয় — monthlySummary-র বিনা-বেতনের দিন (`unpaid`) ইচ্ছা করে বাদ; কেবল উপস্থিত, অনুপস্থিত, ছুটি আর দেরি।
 * ⓘ `leave_left`: বছরে সীমা আছে এমন চালু ধরনগুলোর বাকির যোগ; সীমাওয়ালা ধরন একটাও না থাকলে null।
 * ⓘ `link`: নিজের হাজিরার ওয়েব-পাতা নেই (ওয়েবের `/hr/attendance` সবার রেজিস্টার, `hr.attendance.view`-এর), তাই ফোনের নিজের
 * পর্দা `/home/attendance` — [[QuickActionsSummary]]-এর একই নিয়ম।
 */
final class AttendanceSummary implements WidgetSummary
{
    public const LINK = '/home/attendance';

    public function __construct(
        private readonly ExpenseClaimService $claims,
        private readonly AttendanceService $attendance,
        private readonly LeaveService $leave,
    ) {}

    public function key(): string
    {
        return 'attendance';
    }

    public function module(): string
    {
        return 'hr';
    }

    /** নিজের হাজিরার চাবি — "সবার হাজিরা দেখা" (`hr.attendance.view`) নয় ([[AttendanceSync]]-এর একই চাবি) */
    public function permission(): string
    {
        return 'hr.attendance.self';
    }

    public function data(User $user): array
    {
        $employee = $this->claims->employeeOf($user);

        if ($employee === null) {
            return ['employee' => false];
        }

        $today = Carbon::today();

        $row = Attendance::query()
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $today->toDateString())
            ->first();

        $month = $this->attendance->monthlySummary($employee, $today);

        $left = null;
        foreach (LeaveType::query()->active()->orderBy('code')->get() as $type) {
            $balance = $this->leave->balance($employee, $type, $today);
            if ($balance['left'] !== null) {
                $left = bcadd($left ?? '0', $balance['left'], 1);
            }
        }

        return [
            'today' => [
                'status' => $row?->status,
                'in_time' => $row?->in_time === null ? null : substr((string) $row->in_time, 0, 5),
                'late' => (bool) ($row?->is_late ?? false),
            ],
            'month' => [
                'present' => $month['present'],
                'absent' => $month['absent'],
                'leave' => $month['leave'],
                'late' => $month['late'],
            ],
            'leave_left' => $left,
            'link' => self::LINK,
        ];
    }
}
