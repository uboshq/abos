<?php

declare(strict_types=1);

namespace App\Modules\Hr\Dashboard;

use App\Core\Contracts\ProvidesDashboard;
use App\Core\Engines\Dashboard\DashboardDefinition;
use App\Core\Engines\Dashboard\Listing;
use App\Core\Engines\Dashboard\Stat;
use App\Core\Engines\Dashboard\Tile;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\LeaveApplication;
use App\Modules\Hr\Models\PayrollRun;
use Illuminate\Support\Carbon;

/**
 * কর্মী ও বেতন মডিউলের ড্যাশবোর্ড।
 *
 * ── কেন "আজ কে এলেন" সবচেয়ে উপরে ────────────────────────────────────
 * বেতনের হিসাব মাসে একবার লাগে; **আজ কে আছেন** প্রশ্নটা রোজ সকালে
 * লাগে, আর সেটা না জানলে কাজ ভাগ করা যায় না।
 */
final class HrDashboard implements ProvidesDashboard
{
    public static function dashboard(): DashboardDefinition
    {
        $today = Carbon::today()->toDateString();

        return new DashboardDefinition(
            title: __('hr::dashboard.title'),
            subtitle: __('hr::dashboard.subtitle'),

            tiles: [
                new Tile(label: __('hr::action.new_employee'), href: route('hr.employee.create'),
                    permission: 'hr.employee.create', icon: 'people'),
                new Tile(label: __('hr::menu.attendance'), href: route('hr.attendance.index'),
                    permission: 'hr.attendance.view', icon: 'check'),
                new Tile(label: __('hr::menu.leave'), href: route('hr.leave.index'),
                    permission: 'hr.leave.view', icon: 'plus'),
                new Tile(label: __('hr::menu.payroll'), href: route('hr.payroll.index'),
                    permission: 'hr.payroll.view', icon: 'money'),
            ],

            stats: [
                new Stat(
                    label: __('hr::dashboard.employees'),
                    value: (string) Employee::query()->count(),
                    hint: __('hr::dashboard.employees_hint'),
                    href: route('hr.employee.index'),
                ),
                new Stat(
                    label: __('hr::dashboard.present_today'),
                    value: (string) Attendance::query()->where('work_date', $today)
                        ->where('status', 'present')->count(),
                    hint: __('hr::dashboard.present_hint'),
                    href: route('hr.attendance.index'),
                    tone: Stat::GOOD,
                ),
                /*
                 * অপেক্ষমাণ ছুটি একটা **করণীয়**, খবর নয়। কেউ অপেক্ষা
                 * করছেন, আর সিদ্ধান্ত না দিলে তিনি জানেন না কাল আসবেন
                 * কি না।
                 */
                new Stat(
                    label: __('hr::dashboard.pending_leave'),
                    value: (string) LeaveApplication::query()->where('status', 'pending')->count(),
                    hint: __('hr::dashboard.pending_leave_hint'),
                    href: route('hr.leave.index'),
                    tone: Stat::WARN,
                ),
                new Stat(
                    label: __('hr::dashboard.payroll_runs'),
                    value: (string) PayrollRun::query()->count(),
                    hint: __('hr::dashboard.payroll_hint'),
                    href: route('hr.payroll.index'),
                ),
            ],

            listings: [
                new Listing(
                    label: __('hr::dashboard.pending_leave'),
                    columns: [
                        ['key' => 'employee', 'label' => __('hr::dashboard.employee'),
                            'render' => fn ($l) => $l->employee?->name() ?? '—'],
                        ['key' => 'from', 'label' => __('hr::dashboard.from_date'), 'width' => '8rem',
                            'render' => fn ($l) => $l->from_date],
                        ['key' => 'days', 'label' => __('hr::dashboard.days'), 'width' => '5rem',
                            'render' => fn ($l) => $l->days],
                    ],
                    rows: LeaveApplication::query()->where('status', 'pending')
                        ->with('employee')->latest('id')->limit(8)->get(),
                    empty: __('hr::dashboard.no_pending_leave'),
                    href: route('hr.leave.index'),
                ),
            ],
        );
    }
}
