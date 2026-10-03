<?php

declare(strict_types=1);

namespace App\Modules\Hr\Dashboard;

use App\Core\Contracts\ProvidesDashboard;
use App\Core\Engines\Dashboard\Breakdown;
use App\Core\Engines\Dashboard\DashboardDefinition;
use App\Core\Engines\Dashboard\Listing;
use App\Core\Engines\Dashboard\Series;
use App\Core\Engines\Dashboard\Stat;
use App\Core\Engines\Dashboard\Tile;
use App\Core\Services\DataScope;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
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

            panels: [self::todaysRoll($today), ...self::byDepartment(), ...self::leaveThisMonth(), ...self::comingAndGoing(), ...self::salaryCost()],

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
     * ⭐ বিভাগ অনুযায়ী চালু কর্মী — মালিকের ড্যাশবোর্ড নকশা, ৩ অক্টোবর ২০২৬।
     *
     * ⓘ উপরের "কর্মী" সংখ্যার একই ভিত (দেখার শাখা), কেবল চলতি কর্মী (`leaving_date` নেই); বিভাগহীনরা আলাদা ভাগে,
     * যাতে যোগফল কর্মীসংখ্যার সমান থাকে আর কেউ চুপচাপ হারিয়ে না যান। বড় থেকে ছোট, প্রথম ছয়টা; বাকি "অন্যান্য"-তে।
     * ⓘ নতুন ড্যাশবোর্ডের অংশ — বাকিগুলোর সাথে একসাথে চালু হবে (config abos.dashboards_v2)।
     *
     * @return list<Breakdown>
     */
    private static function byDepartment(): array
    {
        if (! config('abos.dashboards_v2')) {
            return [];
        }

        $rows = self::inView(Employee::query()->whereNull('leaving_date'), 'hr_employees.branch_id')
            ->selectRaw('department_id, COUNT(*) as n')
            ->groupBy('department_id')
            ->toBase()->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $names = \App\Modules\MasterData\Models\Department::query()
            ->whereIn('id', $rows->pluck('department_id')->filter()->all())
            ->get()->mapWithKeys(fn ($d) => [$d->id => $d->name()]);

        $parts = $rows->map(fn ($r) => [
            'label' => $r->department_id === null ? __('hr::dashboard.no_department') : ($names[$r->department_id] ?? '—'),
            'n' => (int) $r->n,
        ])->sortByDesc('n')->values();

        $shown = $parts->take(6);
        $rest = $parts->slice(6)->sum('n');

        if ($rest > 0) {
            $shown->push(['label' => __('hr::dashboard.other_departments'), 'n' => $rest]);
        }

        return [new Breakdown(
            label: __('hr::dashboard.by_department'),
            parts: $shown->map(fn ($p) => ['label' => $p['label'], 'value' => (string) $p['n']])->all(),
            hint: __('hr::dashboard.by_department_hint', ['count' => $parts->sum('n')]),
        )];
    }

    /**
     * ⭐ ছুটির আবেদন — এ মাস, অবস্থা ধরে (মালিকের ড্যাশবোর্ড নকশা, ৩ অক্টোবর ২০২৬)।
     *
     * ⓘ "এ মাসের" মানে ছুটিটা এ মাসে শুরু (`from_date`) — কবে লেখা হলো তা নয়; মালিক জানতে চান এ মাসে কে কে নেই।
     * ⓘ দেখার শাখার কর্মীদের আবেদন — উপরের "কর্মী" সংখ্যার একই ভিত। ⛔ ছুটির চাবি (`hr.leave.view`) ছাড়া চার্টই নেই।
     * ⓘ নতুন ড্যাশবোর্ডের অংশ (config abos.dashboards_v2)।
     *
     * @return list<Breakdown>
     */
    private static function leaveThisMonth(): array
    {
        if (! config('abos.dashboards_v2') || ! auth()->user()?->can('hr.leave.view')) {
            return [];
        }

        $start = Carbon::today()->startOfMonth();

        $byStatus = LeaveApplication::query()
            ->whereIn('employee_id', self::inView(Employee::query(), 'hr_employees.branch_id')->select('id'))
            ->whereBetween('from_date', [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()])
            ->selectRaw('status, COUNT(*) as n')
            ->groupBy('status')
            ->toBase()->pluck('n', 'status');

        return [new Breakdown(
            label: __('hr::dashboard.leave_this_month'),
            parts: array_map(fn (string $status) => [
                'label' => __('hr::dashboard.leave_'.$status),
                'value' => (string) (int) ($byStatus[$status] ?? 0),
            ], [LeaveApplication::PENDING, LeaveApplication::APPROVED, LeaveApplication::REJECTED, LeaveApplication::CANCELLED]),
            hint: __('hr::dashboard.leave_this_month_hint'),
        )];
    }

    /**
     * ⭐ কর্মী চলাচল — এ বছর কতজন এলেন, কতজন গেলেন (মালিকের ড্যাশবোর্ড নকশা, ৩ অক্টোবর ২০২৬)।
     *
     * ⓘ যোগদান = `joining_date` এ বছরে; বিদায় = `leaving_date` এ বছরে; দেখার শাখার কর্মী। ⓘ বদলি আর পদোন্নতি
     * আলাদা খাতায় নেই — প্রোফাইলের চাকরির ইতিহাসে ([[JobHistory]]) আছে, এখানে বানিয়ে গোনা হয় না।
     * ⓘ নতুন ড্যাশবোর্ডের অংশ (config abos.dashboards_v2)।
     *
     * @return list<Breakdown>
     */
    private static function comingAndGoing(): array
    {
        if (! config('abos.dashboards_v2')) {
            return [];
        }

        $from = Carbon::today()->startOfYear()->toDateString();
        $to = Carbon::today()->endOfYear()->toDateString();
        $staff = fn () => self::inView(Employee::query(), 'hr_employees.branch_id');

        return [new Breakdown(
            label: __('hr::dashboard.coming_and_going', ['year' => Carbon::today()->year]),
            parts: [
                ['label' => __('hr::dashboard.joined'), 'value' => (string) $staff()->whereBetween('joining_date', [$from, $to])->count()],
                ['label' => __('hr::dashboard.left'), 'value' => (string) $staff()->whereBetween('leaving_date', [$from, $to])->count()],
            ],
            hint: __('hr::dashboard.coming_and_going_hint'),
        )];
    }

    /**
     * ⭐ বেতন খরচ — গত ছয় মাস, মোট আয় বনাম হাতে পাওয়া (মালিকের ড্যাশবোর্ড নকশা, ২ অক্টোবর ২০২৬)।
     *
     * ⓘ নিশ্চিত বেতনশিটের `gross_total` আর `net_total` — বেতনশিটের পর্দা যা বলে, এখানেও তাই; দুইটার ফাঁক = কর্তন।
     * ⛔ টাকার অঙ্ক — কেবল `hr.payroll.view` যাঁর আছে; হাজিরার চাবিতে বেতনের খরচ খোলে না।
     * ⓘ নতুন ড্যাশবোর্ডের অংশ — বাকিগুলোর সাথে একসাথে চালু হবে (config abos.dashboards_v2)।
     *
     * @return list<Series>
     */
    private static function salaryCost(): array
    {
        if (! config('abos.dashboards_v2') || ! auth()->user()?->can('hr.payroll.view')) {
            return [];
        }

        $start = Carbon::today()->startOfMonth()->subMonths(5);
        $expr = "DATE_FORMAT(month, '%Y-%m')";

        $rows = self::inView(PayrollRun::query(), 'hr_payroll_runs.branch_id')
            ->whereIn('status', DocumentStatus::POSTED)
            ->where('month', '>=', $start->toDateString())
            ->selectRaw("{$expr} as ym, COALESCE(SUM(gross_total), 0) as gross, COALESCE(SUM(net_total), 0) as net")
            ->groupByRaw($expr)
            ->toBase()->get()->keyBy('ym');

        $points = [];

        for ($month = $start->copy(); $month->lessThanOrEqualTo(Carbon::today()); $month->addMonth()) {
            $row = $rows[$month->format('Y-m')] ?? null;
            $gross = (string) ($row->gross ?? '0');
            $net = (string) ($row->net ?? '0');

            $points[] = [
                'label' => $month->translatedFormat('M'),
                'first' => $gross,
                'second' => $net,
                'firstTitle' => Money::format($gross),
                'secondTitle' => Money::format($net),
            ];
        }

        return [new Series(
            label: __('hr::dashboard.salary_cost'),
            points: $points,
            firstLabel: __('hr::field.gross'),
            secondLabel: __('hr::field.net'),
        )];
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
