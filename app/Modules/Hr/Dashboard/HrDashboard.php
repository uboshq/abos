<?php

declare(strict_types=1);

namespace App\Modules\Hr\Dashboard;

use App\Core\Contracts\ProvidesDashboard;
use App\Core\Engines\Dashboard\Breakdown;
use App\Core\Engines\Dashboard\DashboardDefinition;
use App\Core\Engines\Dashboard\Listing;
use App\Core\Engines\Dashboard\Stat;
use App\Core\Engines\Dashboard\Tile;
use App\Core\Services\DataScope;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\LeaveApplication;
use App\Modules\Hr\Models\PayrollRun;
use Illuminate\Contracts\Database\Query\Builder;
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
                    /* ⚠️ `hr.employee.create` বলে কোনো অনুমতি নেই — এই মডিউলে
                       তৈরি-সম্পাদনা-মোছা তিনটাই `manage`। ভুল নামটা কাউকে
                       ৪০৩ দিত না, বরং **টাইলটাই কারও কাছে দেখাত না**, আর
                       তাই কেউ অভিযোগও করত না (৩ সেপ্টেম্বর ২০২৬) */
                    permission: 'hr.employee.manage', icon: 'plus'),
                new Tile(label: __('hr::menu.attendance'), href: route('hr.attendance.index'),
                    permission: 'hr.attendance.view', icon: 'check-circle'),
                new Tile(label: __('hr::menu.leave'), href: route('hr.leave.index'),
                    permission: 'hr.leave.view', icon: 'calendar'),
                new Tile(label: __('hr::menu.payroll'), href: route('hr.payroll.index'),
                    permission: 'hr.payroll.view', icon: 'cash'),
            ],

            stats: [
                new Stat(
                    label: __('hr::dashboard.employees'),
                    // ⭐ দেখার শাখার কর্মী — গোটা ব্যবসার সারির মুখও এটাই (২৯ সেপ্টেম্বর ২০২৬)
                    value: (string) self::inView(Employee::query(), 'hr_employees.branch_id')->count(),
                    hint: __('hr::dashboard.employees_hint'),
                    href: route('hr.employee.index'),
                ),
                new Stat(
                    label: __('hr::dashboard.present_today'),
                    value: (string) Attendance::query()
                        ->whereIn('employee_id', self::inView(Employee::query(), 'hr_employees.branch_id')->select('id'))
                        ->where('work_date', $today)
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

            panels: [self::todaysRoll($today)],

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

    /**
     * ⭐ আজকের হাজিরা, ভাগে ভাগে — মালিকের ড্যাশবোর্ড নকশা (২ অক্টোবর ২০২৬)।
     *
     * ⓘ উপরের "আজ উপস্থিত" ঘরের একই ছাঁকনি (দেখার শাখার কর্মী, আজকের তারিখ), এক কোয়েরিতে ভাগ করা।
     * ⓘ দেরিতে আসা মানুষ উপস্থিতও — তাই "দেরি" আলাদা ভাগ, উপস্থিত থেকে বাদ দিয়ে, যাতে যোগফল কর্মীসংখ্যার সমান থাকে।
     * ⚠️ "লেখা হয়নি" অনুপস্থিত নয় — বেতনের নিয়মও তাই বলে ([[AttendanceService::unpaidDays()]])।
     */
    private static function todaysRoll(string $today): Breakdown
    {
        $staff = self::inView(Employee::query()->whereNull('leaving_date'), 'hr_employees.branch_id');
        $headcount = (clone $staff)->count();

        $rows = Attendance::query()
            ->whereIn('employee_id', (clone $staff)->select('id'))
            ->where('work_date', $today)
            ->selectRaw('status, is_late, COUNT(*) as n')
            ->groupBy('status', 'is_late')
            ->toBase()->get();

        $count = fn (callable $match) => (int) $rows->filter($match)->sum('n');

        $late = $count(fn ($r) => $r->status === Attendance::PRESENT && (bool) $r->is_late);
        $present = $count(fn ($r) => $r->status === Attendance::PRESENT) - $late;
        $leave = $count(fn ($r) => $r->status === Attendance::LEAVE);
        $absent = $count(fn ($r) => $r->status === Attendance::ABSENT);
        $off = $count(fn ($r) => $r->status === Attendance::HOLIDAY);
        $unwritten = max(0, $headcount - $present - $late - $leave - $absent - $off);

        return new Breakdown(
            label: __('hr::dashboard.todays_roll'),
            parts: [
                ['label' => __('hr::kind.present'), 'value' => (string) $present],
                ['label' => __('hr::dashboard.late'), 'value' => (string) $late],
                ['label' => __('hr::kind.leave'), 'value' => (string) $leave],
                ['label' => __('hr::kind.absent'), 'value' => (string) $absent],
                ['label' => __('hr::dashboard.not_written'), 'value' => (string) $unwritten],
            ],
            hint: __('hr::dashboard.todays_roll_hint', ['count' => $headcount]),
        );
    }

    /**
     * ⭐ দেখার শাখা — হেডারে যা বাছা (২৯ সেপ্টেম্বর ২০২৬)।
     *
     * একটা শাখা বাছা থাকলে কেবল সেটা, শাখাহীন সারি ছাড়া; "সব শাখা"-তে নাগাল,
     * শাখাহীনসহ ([[DataScope::viewBranchIds()]])। ⛔ কেবল দেখানোর সংখ্যায়।
     *
     * @template T of Builder
     *
     * @param  T  $query
     * @return T
     */
    private static function inView(Builder $query, string $column): Builder
    {
        return app(DataScope::class)->inView($query, $column);
    }
}
