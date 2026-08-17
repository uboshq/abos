<?php

declare(strict_types=1);

namespace App\Modules\Hr\Dashboard;

use App\Core\Contracts\DashboardWidgets;
use App\Core\Dashboard\Widget;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\LeaveApplication;
use Illuminate\Support\Carbon;

/**
 * মানুষের সংখ্যাগুলো হোম পর্দায়।
 *
 * ── কেন আজকের হাজিরা এখানে ──────────────────────────────────────────
 * বেতন হাজিরা থেকেই হিসাব হয়, আর হাজিরা না বসালে মাস শেষে কেউ টের পায়
 * না — শুধু বেতনের অঙ্কটা ভুল হয়। সংখ্যাটা রোজ চোখের সামনে থাকলে
 * ফাঁকটা ওই দিনেই ধরা পড়ে, ত্রিশ দিন পরে নয়।
 */
final class HrWidgets implements DashboardWidgets
{
    /** @return list<Widget> */
    public static function widgets(): array
    {
        $today = Carbon::today();

        return [
            new Widget(
                group: 'today',
                label: __('hr::dashboard.present_today'),
                value: self::presentToday($today).' / '.self::onPayroll($today),
                href: route('hr.attendance.index', ['date' => $today->toDateString()]),
                permission: 'hr.attendance.view',
                tone: 'neutral',
                sort: 60,
            ),

            new Widget(
                group: 'todo',
                label: __('hr::dashboard.leave_awaiting'),
                value: (string) LeaveApplication::query()->pending()->count(),
                href: route('hr.leave.index'),
                permission: 'hr.leave.approve',
                tone: 'warn',
                sort: 95,
                icon: 'calendar',
            ),
        ];
    }

    /** আজ যাদের হাজিরা "উপস্থিত" হিসেবে বসেছে। */
    private static function presentToday(Carbon $today): int
    {
        return Attendance::query()
            ->whereDate('work_date', $today->toDateString())
            ->where('status', Attendance::PRESENT)
            ->count();
    }

    /** আজ যাদের বেতনের খাতায় থাকার কথা। */
    private static function onPayroll(Carbon $today): int
    {
        return Employee::query()->onPayrollFor($today)->count();
    }
}
