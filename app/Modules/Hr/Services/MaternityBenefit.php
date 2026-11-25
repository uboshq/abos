<?php

declare(strict_types=1);

namespace App\Modules\Hr\Services;

use App\Core\Support\DocumentStatus;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\LeaveApplication;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\Payslip;
use Illuminate\Support\Carbon;

/**
 * ⭐ মাতৃত্বকালীন সুবিধার টাকা — আগের তিন মাসের গড় দৈনিক মজুরিতে, কাঠামোর বেতনের বদলে (fe-র উত্তর ২, ২১ অক্টোবর ২০২৬; বাংলাদেশ
 * শ্রম আইন ২০০৬ ধারা ৪৮; a6, ২১ অক্টোবর ২০২৬)।
 *
 * ── ⭐ সূত্র (ধারা ৪৮(২)) ──────────────────────────────────────────────
 *     গড় দৈনিক মজুরি = নোটিশের আগের তিন মাসে অর্জিত মোট মজুরি ÷ সেই সময়ে সত্যিই কাজ করা দিন
 *  · **নোটিশের দিন:** আবেদন বসার দিন (`created_at`)।
 *  · **তিন মাস:** নোটিশের মাসের আগের তিনটা পুরো ক্যালেন্ডার মাস — বেতন মাস ধরে হয়, তাই মাসের মাঝ থেকে কাটা যায় না।
 *  · **অর্জিত মজুরি:** সেই তিন মাসের **নিশ্চিত** বেতনের রানের স্লিপের মোট আয় (`gross`) — খসড়া বা বাতিল রান নয়।
 *  · **কাজ করা দিন:** সেই তিন মাসে হাজিরার "উপস্থিত" দিন। ⓘ যে প্রতিষ্ঠান হাজিরা রাখে না তার খাতা খালি — তখন সেই মাসগুলোর
 *    চাকরির দিন (যোগদান থেকে), কারণ বেতনও সেই দিনগুলোরই।
 *  · অর্জিত মজুরি শূন্য হলে (আগের বেতন নেই) গড় নেই — তখন কাঠামোর বেতনই চলে ([[PayrollService]] কিছু বদলায় না)।
 *
 * ── ⭐ বেতনের রানে ──────────────────────────────────────────────────────
 * মাসের যে দিনগুলো বেতনসহ মাতৃত্বকালীন ছুটি ([[daysIn()]]), সেই দিনগুলোর কাঠামোর ভাগ (হাজিরায় কমে এমন আয়ের খাত) বাদ যায়, আর
 * "মাতৃত্বকালীন সুবিধা" নামে একটা আয়ের সারি বসে: দিন × গড় দৈনিক মজুরি, পয়সায় গোল ([[PayrollService::buildPayslip()]])।
 */
final class MaternityBenefit
{
    public const MONTHS = 3;

    /** বেতনশিটের সারির কোড */
    public const LINE_CODE = 'MATERNITY_BENEFIT';

    /** প্রসবের আসল দিনে সরা ছুটির দেওয়া মাসের পার্থক্যের সারি ([[MaternityDeliveryService]]) */
    public const ADJUSTMENT_CODE = 'MATERNITY_ADJUST';

    /**
     * ⭐ গড় দৈনিক মজুরি — ধারা ৪৮(২): নোটিশের আগের তিন মাসে অর্জিত **মজুরি** ÷ সেই সময়ে কাজ করা দিন।
     *
     * ⛔ **উৎসব বোনাস মজুরি নয়** — বাংলাদেশ শ্রম আইন ২০০৬ ধারা ২(৪৫) "মজুরি"-র সংজ্ঞা থেকে বোনাস বাদ দেয়; তাই যোগ হয় কেবল
     * সেই তিন মাসের **নিশ্চিত বেতনের রানের** (`run_type` salary) স্লিপের মোট আয়, উৎসব ভাতার রান (`run_type` bonus) কখনো নয়
     * (fe-র সিদ্ধান্ত, ২১ অক্টোবর ২০২৬; কোড 853aaad4e থেকেই এমন, এখানে নিয়মটা লেখা)। ⓘ নইলে নোটিশের আগের তিন মাসে ঈদ পড়লেই
     * একই বেতনের মায়ের গড় বাড়ত — সুবিধা নির্ভর করত ঈদের তারিখে। নিয়মটা পাতাতেও লেখা (`hr::message.maternity_wage_rule` —
     * ছুটির তালিকায় মাতৃত্বকালীন আবেদনের সারিতে, আর যে বেতনের রানে মাতৃত্বকালীন সুবিধার সারি আছে তার পাতায়)।
     *
     * @return string|null গড় দৈনিক মজুরি (১০ ঘর), বা আগের মজুরি না থাকলে null
     */
    public function averageDailyWage(LeaveApplication $application): ?string
    {
        $employee = Employee::query()->withoutGlobalScopes()->find($application->employee_id);

        if ($employee === null) {
            return null;
        }

        [$from, $to] = $this->window($application);

        $wages = Payslip::query()->withoutGlobalScopes()
            ->where('hr_payslips.company_id', $employee->company_id)
            ->where('hr_payslips.employee_id', $employee->id)
            ->join('hr_payroll_runs as r', 'r.id', '=', 'hr_payslips.payroll_run_id')
            ->where('r.status', DocumentStatus::CONFIRMED)
            /*
             * ⛔ কেবল মাসের বেতনের রান — উৎসব ভাতার রান নয় (মেশানোর পর, a6, ২১ অক্টোবর ২০২৬; [[TheFestivalBonusIsNotAMaternityMonthTest]])।
             * ⓘ উপরের সূত্র "বেতনের রানের স্লিপ" বলে, অথচ প্রশ্নটা সব নিশ্চিত রান যোগ করত; পে-শাখার ভাতার রান আসার পর নোটিশের আগের তিন
             * মাসে ঈদ পড়লেই এক মাসের মূল বাড়তি যোগ হত — একই বেতনের দুই মায়ের গড় দৈনিক মজুরি ঈদের তারিখে আলাদা।
             */
            ->where('r.run_type', PayrollRun::SALARY)
            ->whereNull('r.deleted_at')
            ->whereBetween('r.month', [$from->toDateString(), $to->toDateString()])
            ->pluck('hr_payslips.gross')
            ->reduce(fn (string $sum, $gross) => bcadd($sum, (string) $gross, 4), '0');

        if (bccomp($wages, '0', 4) <= 0) {
            return null;
        }

        $worked = Attendance::query()->withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->where('status', Attendance::PRESENT)
            ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])
            ->count();

        if ($worked === 0) {
            $start = $employee->joining_date !== null && $employee->joining_date->gt($from) ? $employee->joining_date->copy()->startOfDay() : $from->copy();
            $worked = $start->gt($to) ? 0 : (int) $start->diffInDays($to) + 1;
        }

        return $worked === 0 ? null : bcdiv($wages, (string) $worked, 10);
    }

    /** @return array{0: Carbon, 1: Carbon} নোটিশের মাসের আগের তিনটা পুরো মাস */
    public function window(LeaveApplication $application): array
    {
        $notice = Carbon::parse($application->created_at ?? $application->from_date)->startOfMonth();

        return [$notice->copy()->subMonthsNoOverflow(self::MONTHS), $notice->copy()->subDay()];
    }

    /**
     * এই মাসে বেতনসহ মাতৃত্বকালীন ছুটির দিন — আবেদন ধরে, হাজিরার "ছুটি"-র সারির ভাগ থেকে (ক্যালেন্ডারের দিনে)।
     *
     * @return array<int, array{application: LeaveApplication, days: string}>
     */
    public function daysIn(Employee $employee, Carbon $monthEnd): array
    {
        $out = [];

        $rows = AttendanceService::rowsBetween($monthEnd->copy()->startOfMonth()->toDateString(), $monthEnd->toDateString())
            ->with('leaveApplication.leaveType')
            ->where('employee_id', $employee->id)
            ->where('status', Attendance::LEAVE)
            ->get();

        foreach ($rows as $row) {
            $application = $row->leaveApplication;

            if ($application === null || ! $application->isApproved() || ! (bool) $application->leaveType?->isMaternity()
                || $application->leaveType->is_paid !== true) {
                continue;
            }

            $id = (int) $application->id;
            $out[$id] ??= ['application' => $application, 'days' => '0'];
            $out[$id]['days'] = bcadd($out[$id]['days'], $row->leaveShare(), 1);
        }

        return $out;
    }
}
