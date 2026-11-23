<?php

declare(strict_types=1);

namespace App\Modules\Hr\Dashboard;

use App\Core\Contracts\ProvidesDashboard;
use App\Core\Dashboard\Viz\Card;
use App\Core\Dashboard\Viz\Svg;
use App\Core\Engines\Dashboard\Breakdown;
use App\Core\Engines\Dashboard\DashboardDefinition;
use App\Core\Engines\Dashboard\DateRange;
use App\Core\Engines\Dashboard\Listing;
use App\Core\Engines\Dashboard\Series;
use App\Core\Engines\Dashboard\Stat;
use App\Core\Engines\Dashboard\Tile;
use App\Core\Services\DataScope;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Core\Support\PhoneAnswer;
use App\Models\Branch;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\LeaveApplication;
use App\Modules\Hr\Models\LeaveType;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\PayslipLine;
use App\Modules\Hr\Models\SalaryHead;
use App\Modules\MasterData\Models\Department;
use App\Modules\MasterData\Models\Designation;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

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
                // ⛔ বেতনের বোতাম ফোনে নয় — বেতনের কিছুই ফোনে যায় না (ড্যা-৭; মালিক, ১৭ অক্টোবর ২০২৬ "ক"; [[PhoneAnswer]])
                ...(PhoneAnswer::active() ? [] : [new Tile(label: __('hr::menu.payroll'), href: route('hr.payroll.index'),
                    permission: 'hr.payroll.view', icon: 'cash')]),
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
                    // ⭐ দেখার শাখার কর্মীর ছুটি — ছুটির সারিতে শাখা নেই, কর্মীর আছে (পুনঃনিরীক্ষা, ৯ অক্টোবর ২০২৬)
                    value: (string) self::leaveInView()->where('status', 'pending')->count(),
                    hint: __('hr::dashboard.pending_leave_hint'),
                    href: route('hr.leave.index'),
                    tone: Stat::WARN,
                    // ⛔ ছুটির চাবি ছাড়া ঢাকা — ec, Hr লাইন-নিরীক্ষা, ১৪ অক্টোবর ২০২৬ (ⓘ২০; [[ThePendingLeaveCountWentOverTheBranchWallTest]])
                    permission: 'hr.leave.view',
                ),
                // ⛔ বেতনের রানের গোনাও বেতনের খবর — ফোনের উত্তরে নয় (মালিক, ১৭ অক্টোবর ২০২৬; [[PhoneAnswer]])
                ...(PhoneAnswer::active() ? [] : [new Stat(
                    label: __('hr::dashboard.payroll_runs'),
                    value: (string) PayrollRun::query()->count(),
                    hint: __('hr::dashboard.payroll_hint'),
                    href: route('hr.payroll.index'),
                )]),
            ],

            panels: [self::todaysRoll($today), ...self::byDepartment(), ...self::byBranch(), ...self::byDesignation(), ...self::leaveThisMonth(),
                ...self::comingAndGoing(), ...self::salaryCost(), ...self::allowancesAndDeductions(), ...self::costByDepartment()],

            /*
             * ⛔ অপেক্ষমাণ ছুটির তালিকা কেবল ছুটির চাবিতে — ec, Hr লাইন-নিরীক্ষা, ১৪ অক্টোবর ২০২৬ (ⓘ২০)। ⓘ কর্মী দেখার চাবিওয়ালা
             * কেউ ছুটির চাবি ছাড়াই নাম, তারিখ আর দিন দেখতেন; এ মাসের ছুটির চার্ট ([[leaveThisMonth()]]) আগেই চাবি মানত।
             */
            listings: ! auth()->user()?->can('hr.leave.view') ? [] : [
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
                    rows: self::leaveInView()->where('status', 'pending')
                        ->with('employee')->latest('id')->limit(8)->get(),
                    empty: __('hr::dashboard.no_pending_leave'),
                    href: route('hr.leave.index'),
                ),
            ],

            // ⭐ খোলার পর্দায় ছয়টা গ্রাফ — মালিক, ২০ নভেম্বর ২০২৬ (নমুনা boards15 "ok"); যে সংখ্যা-চার্ট গ্রাফে এল, পাতায় আর আলাদা নয়
            // ⓘ হাতে পাওয়া ও কর্তনের মাস ধরে কলাম আর খাত ধরে টাকা ঢাকা নয় — গ্রাফ এখন মূল বেতন ও ভাতা দেখায় আর প্রবাহে খাতের অঙ্ক নেই,
            //   তাই ঐ দুটো নিচে থাকে (নিরীক্ষা, ২৩ নভেম্বর ২০২৬: কিছু হারায় না)
            sections: [Card::board(fn (): array => self::charts($today), covers: [
                __('hr::dashboard.present_today'), __('hr::dashboard.todays_roll'),
                __('hr::dashboard.by_department'), __('hr::dashboard.cost_by_department'), __('hr::dashboard.leave_this_month'),
                __('hr::dashboard.coming_and_going', ['year' => Carbon::today()->year]),
            ])],
        );
    }

    /**
     * ⭐ কর্মী ও বেতনের গ্রাফের বোর্ড — আজকের হাজিরা (কাঁটা-মিটার), ছয় মাসের বেতন খরচ (স্তূপ-এলাকা), বিভাগ ধরে কর্মী ও বেতন
     *   (আড়াআড়ি কলাম), এ মাসের ছুটি ধরন × সপ্তাহ (হিটম্যাপ), ছয় মাসের যোগদান বনাম বিদায় (জোড়া কলাম), ভাতা ও কর্তন (প্রবাহ)।
     * ⓘ সবই দেখার শাখার কর্মী ([[inView()]]); ⛔ বেতনের অঙ্ক কেবল [[showsPay()]]-এ (বেতনের চাবি, ফোনে কখনো নয়), ছুটি কেবল ছুটির চাবিতে।
     *
     * @return list<array<string, mixed>>
     */
    private static function charts(string $today): array
    {
        $pay = self::showsPay();
        $cards = [];

        // ── আজকের হাজিরা — নিচের পুরনো ভাগের একই গোনা ──────────────────────────────
        $roll = self::rollCounts($today);
        $here = $roll['present'] + $roll['late'];
        $rate = $roll['headcount'] > 0 ? $here / $roll['headcount'] : null;
        $cards[] = Card::make(__('hr::dashboard.todays_roll'), 'gauge', $rate === null ? [] : [
            'v' => $rate, 'label' => round($rate * 100).'%',
            'sub' => __('hr::dashboard.viz_roll_sub', ['late' => $roll['late'], 'leave' => $roll['leave'], 'absent' => $roll['absent'], 'unwritten' => $roll['unwritten']]),
        ], value: $rate === null ? '' : round($rate * 100).'%',
            sub: __('hr::dashboard.viz_roll_hint', ['n' => number_format($here), 'of' => number_format($roll['headcount'])]),
            href: route('hr.attendance.index'), colour: '#16a34a');

        // ── বেতন খরচ — ছয় মাস, নমুনার মতো মূল বেতন + ভাতা = মোট আয়; মাথায় প্রতি মাসের মোট আয় ─────────────────
        // ⓘ মূল বেতন = বেতনশিটের যে আয়ের খাত "মূল" (`is_basic`); ভাতা = মোট আয় − মূল। হাতে পাওয়া আর কর্তন নিচের কলামে ([[salaryCost()]])
        if ($pay) {
            $months = self::salaryMonths();
            $last = '0';
            foreach ($months as $m) {
                $last = bccomp($m['gross'], '0', 4) !== 0 ? $m['gross'] : $last;
            }
            $cards[] = Card::make(__('hr::dashboard.viz_salary'), 'stackedArea', [
                'series' => [array_column($months, 'basic'), array_map(fn (array $m) => bcsub($m['gross'], $m['basic'], 4), $months)],
                'colors' => ['#2160d8', '#7c3aed'], 'l' => array_column($months, 'label'),
                'top' => array_map(fn (array $m) => bccomp($m['gross'], '0', 4) === 0 ? '' : self::short($m['gross']), $months),
            ], value: bccomp($last, '0', 4) === 0 ? '' : self::short($last), sub: __('hr::dashboard.viz_salary_sub'), legend: [
                [__('hr::dashboard.viz_basic'), '#2160d8'], [__('hr::dashboard.viz_allowances'), '#7c3aed'],
            ], href: route('hr.payroll.index'), colour: '#2160d8');
        }

        // ── বিভাগ ধরে কর্মী (আর বেতনের চাবিতে পাশে মাসের বেতন) ────────────────────────────
        $depts = self::departmentCounts();
        $cost = $pay ? self::departmentCost() : null;
        $costOf = [];
        foreach ($cost['rows'] ?? [] as $r) {
            $costOf[$r['label']] = $r['cost'];
        }
        // ⓘ "অন্যান্য বিভাগ"-এর দণ্ডে বাকি সবার বেতন — দণ্ডগুলোর বেতন মিলে মোটের সমান (দণ্ডে নেই এমন বিভাগের বেতনও এতে)
        $otherLabel = __('hr::dashboard.other_departments');
        if ($cost !== null && in_array($otherLabel, array_column($depts, 'label'), true)) {
            $named = array_filter(array_column($depts, 'label'), fn (string $label) => $label !== $otherLabel);
            $costOf[$otherLabel] = array_reduce($named, fn (string $c, string $label) => bcsub($c, $costOf[$label] ?? '0', 4), $cost['total']);
        }
        $heads = array_sum(array_column($depts, 'n'));
        $cards[] = Card::make(__('hr::dashboard.viz_departments'), 'hbars', [
            'v' => array_column($depts, 'n'),
            'l' => array_map(fn (array $d) => Str::limit($d['label'], 14), $depts),
            'top' => array_map(fn (array $d) => isset($costOf[$d['label']])
                ? number_format($d['n']).' · '.self::short($costOf[$d['label']]) : number_format($d['n']), $depts),
        ], value: $heads === 0 ? '' : __('hr::dashboard.viz_people', ['n' => number_format($heads)]),
            sub: $cost === null ? __('hr::dashboard.viz_departments_sub')
                : __('hr::dashboard.viz_departments_pay_sub', ['total' => self::short($cost['total']), 'month' => $cost['month']]),
            href: route('hr.employee.index'), colour: '#0ea5e9');

        // ── ছুটির আবেদন — এ মাস, ধরন × সপ্তাহ; অবস্থা ধরে গোনা নিচের লাইনে (নিচের পুরনো চার্টের সবটা) ──────────
        if (auth()->user()?->can('hr.leave.view')) {
            $cards[] = self::leaveHeat();
        }

        // ── কর্মী চলাচল — ছয় মাস, যোগদান বনাম বিদায়; এ বছরের মোট নিচের লাইনে ───────────────────
        $start = Carbon::today()->startOfMonth()->subMonths(5);
        $byMonth = fn (string $column) => self::inView(Employee::query(), 'hr_employees.branch_id')
            ->where($column, '>=', $start->toDateString())->where($column, '<=', $today)
            ->selectRaw("DATE_FORMAT({$column}, '%Y-%m') as ym, COUNT(*) as n")
            ->groupByRaw("DATE_FORMAT({$column}, '%Y-%m')")->toBase()->pluck('n', 'ym');
        $joined = $byMonth('joining_date');
        $left = $byMonth('leaving_date');
        $a = [];
        $b = [];
        $l = [];
        for ($month = $start->copy(); $month->lessThanOrEqualTo(Carbon::today()); $month->addMonth()) {
            $a[] = (int) ($joined[$month->format('Y-m')] ?? 0);
            $b[] = (int) ($left[$month->format('Y-m')] ?? 0);
            $l[] = $month->translatedFormat('M');
        }
        $year = self::yearMovement();
        $cards[] = Card::make(__('hr::dashboard.viz_movement'), 'pairs', ['a' => $a, 'b' => $b, 'l' => $l],
            sub: __('hr::dashboard.viz_movement_sub', ['year' => Carbon::today()->year, 'joined' => $year['joined'], 'left' => $year['left']]),
            legend: [[__('hr::dashboard.joined'), Svg::STATUS[0]], [__('hr::dashboard.left'), Svg::STATUS[3]]],
            href: route('hr.employee.index'), colour: '#14b8a6');

        // ── ভাতা ও কর্তন — দেখানো মাসের বেতনশিটের খাত, আয়ের খাত → মোট আয়, কর্তনের খাত → কর্তন ─────────────
        if ($pay) {
            $cards[] = self::headsFlow();
        }

        return $cards;
    }

    /**
     * আজকের হাজিরার গোনা — নিচের "আজকের হাজিরা" ভাগ আর বোর্ডের কাঁটা-মিটারের একই সংখ্যা।
     *
     * @return array{headcount: int, present: int, late: int, leave: int, absent: int, off: int, unwritten: int}
     */
    private static function rollCounts(string $today): array
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

        return [
            'headcount' => $headcount, 'present' => $present, 'late' => $late, 'leave' => $leave, 'absent' => $absent, 'off' => $off,
            'unwritten' => max(0, $headcount - $present - $late - $leave - $absent - $off),
        ];
    }

    /**
     * চালু কর্মী, বিভাগ ধরে, বড় থেকে ছোট — প্রথম ছয়টা, বাকি "অন্যান্য"; নিচের পুরনো চার্ট আর বোর্ডের একই ভাগ।
     *
     * @return list<array{label: string, n: int}>
     */
    private static function departmentCounts(): array
    {
        $rows = self::inView(Employee::query()->whereNull('leaving_date'), 'hr_employees.branch_id')
            ->selectRaw('department_id, COUNT(*) as n')
            ->groupBy('department_id')
            ->toBase()->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $names = Department::query()
            ->whereIn('id', $rows->pluck('department_id')->filter()->all())
            ->get()->mapWithKeys(fn ($d) => [$d->id => $d->name()]);

        $parts = $rows->map(fn ($r) => [
            'label' => $r->department_id === null ? __('hr::dashboard.no_department') : (string) ($names[$r->department_id] ?? '—'),
            'n' => (int) $r->n,
        ])->sortByDesc('n')->values();

        $shown = $parts->take(6)->all();
        $rest = (int) $parts->slice(6)->sum('n');

        if ($rest > 0) {
            $shown[] = ['label' => __('hr::dashboard.other_departments'), 'n' => $rest];
        }

        return array_values($shown);
    }

    /**
     * বিভাগ ধরে মাসের বেতন খরচ (মোট আয়) — দেখানো মাসের নিশ্চিত বেতনশিট; নিচের পুরনো চার্ট আর বোর্ডের একই অঙ্ক।
     * ⛔ ডাকার আগে [[showsPay()]]।
     *
     * @return array{rows: list<array{label: string, cost: string}>, total: string, month: string, from: Carbon}|null
     */
    private static function departmentCost(): ?array
    {
        $month = self::payrollMonth();

        if ($month === null) {
            return null;
        }

        $rows = Payslip::query()
            ->join('hr_employees', 'hr_employees.id', '=', 'hr_payslips.employee_id')
            ->whereIn('hr_payslips.payroll_run_id', self::payrollRuns()->where('hr_payroll_runs.month', $month->toDateString())->select('hr_payroll_runs.id'))
            ->selectRaw('hr_employees.department_id as k, COALESCE(SUM(hr_payslips.gross), 0) as cost')
            ->groupBy('hr_employees.department_id')
            ->toBase()->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $names = Department::query()
            ->whereIn('id', $rows->pluck('k')->filter()->all())
            ->get()->mapWithKeys(fn ($d) => [$d->id => $d->name()]);

        $parts = $rows->map(fn ($r) => [
            'label' => $r->k === null ? __('hr::dashboard.no_department') : (string) ($names[$r->k] ?? '—'),
            'cost' => (string) $r->cost,
        ])->sort(fn ($a, $b) => bccomp($b['cost'], $a['cost'], 4))->values()->all();

        return [
            'rows' => $parts,
            'total' => array_reduce($parts, fn (string $carry, array $p) => bcadd($carry, $p['cost'], 4), '0'),
            'month' => $month->translatedFormat('M Y'),
            'from' => $month,
        ];
    }

    /**
     * বেতন খরচ, মাস ধরে, গত ছয় মাস — নিশ্চিত রানের মোট আয় আর হাতে পাওয়া (লেখা, bcmath)। ⛔ ডাকার আগে [[showsPay()]]।
     *
     * @return list<array{label: string, gross: string, net: string, basic: string}>
     */
    private static function salaryMonths(): array
    {
        $start = Carbon::today()->startOfMonth()->subMonths(5);
        $expr = "DATE_FORMAT(month, '%Y-%m')";

        $rows = self::inView(PayrollRun::query(), 'hr_payroll_runs.branch_id')
            ->whereIn('status', DocumentStatus::POSTED)
            ->where('month', '>=', $start->toDateString())
            ->selectRaw("{$expr} as ym, COALESCE(SUM(gross_total), 0) as gross, COALESCE(SUM(net_total), 0) as net")
            ->groupByRaw($expr)
            ->toBase()->get()->keyBy('ym');

        // ⓘ মূল বেতন — একই রানগুলোর বেতনশিটের "মূল" আয়ের খাত ([[SalaryHead]] `is_basic`), মাস ধরে
        $basic = PayslipLine::query()
            ->join('hr_payslips', 'hr_payslips.id', '=', 'hr_payslip_lines.payslip_id')
            ->join('hr_payroll_runs', 'hr_payroll_runs.id', '=', 'hr_payslips.payroll_run_id')
            ->whereNull('hr_payslips.deleted_at')
            ->whereIn('hr_payslips.payroll_run_id', self::payrollRuns()->where('hr_payroll_runs.month', '>=', $start->toDateString())->select('hr_payroll_runs.id'))
            ->where('hr_payslip_lines.kind', SalaryHead::EARNING)
            ->whereIn('hr_payslip_lines.salary_head_id', SalaryHead::withTrashed()->where('is_basic', true)->select('id'))
            ->selectRaw("DATE_FORMAT(hr_payroll_runs.month, '%Y-%m') as ym, COALESCE(SUM(hr_payslip_lines.amount), 0) as total")
            ->groupByRaw("DATE_FORMAT(hr_payroll_runs.month, '%Y-%m')")
            ->toBase()->pluck('total', 'ym');

        $months = [];

        for ($month = $start->copy(); $month->lessThanOrEqualTo(Carbon::today()); $month->addMonth()) {
            $row = $rows[$month->format('Y-m')] ?? null;
            $months[] = [
                'label' => $month->translatedFormat('M'),
                'gross' => (string) ($row->gross ?? '0'),
                'net' => (string) ($row->net ?? '0'),
                'basic' => (string) ($basic[$month->format('Y-m')] ?? '0'),
            ];
        }

        return $months;
    }

    /**
     * এ বছরের যোগদান আর বিদায় — নিচের পুরনো "কর্মী চলাচল"-এর একই গোনা।
     *
     * @return array{joined: int, left: int}
     */
    private static function yearMovement(): array
    {
        $from = Carbon::today()->startOfYear()->toDateString();
        $to = Carbon::today()->endOfYear()->toDateString();
        $staff = fn () => self::inView(Employee::query(), 'hr_employees.branch_id');

        return [
            'joined' => $staff()->whereBetween('joining_date', [$from, $to])->count(),
            'left' => $staff()->whereBetween('leaving_date', [$from, $to])->count(),
        ];
    }

    /**
     * ছুটির আবেদন, এ মাসে শুরু — ধরন × মাসের সপ্তাহ; অবস্থা ধরে গোনা কার্ডের নিচের লাইনে, তাই নিচের পুরনো চার্টের কিছু হারায় না।
     *
     * @return array<string, mixed>
     */
    private static function leaveHeat(): array
    {
        $start = Carbon::today()->startOfMonth();
        $rows = self::leaveInView()
            ->whereBetween('from_date', [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()])
            ->get(['leave_type_id', 'from_date', 'status']);

        $types = LeaveType::query()->whereIn('id', $rows->pluck('leave_type_id')->filter()->unique()->all())->get()
            ->mapWithKeys(fn (LeaveType $t) => [$t->id => $t->name()]);
        $weeks = ['1–7', '8–14', '15–21', '22–28', '29–31'];
        $grid = [];
        foreach ($rows as $r) {
            $type = $r->leave_type_id === null ? '—' : (string) ($types[$r->leave_type_id] ?? '—');
            $week = min(4, intdiv(Carbon::parse($r->from_date)->day - 1, 7));
            $grid[$type] ??= array_fill(0, 5, 0);
            $grid[$type][$week]++;
        }
        uasort($grid, fn (array $x, array $y) => array_sum($y) <=> array_sum($x));
        if (count($grid) > 5) {
            $rest = array_fill(0, 5, 0);
            foreach (array_slice($grid, 4, null, true) as $cells) {
                foreach ($cells as $k => $n) {
                    $rest[$k] += $n;
                }
            }
            $grid = array_slice($grid, 0, 4, true) + [__('hr::dashboard.viz_other_types') => $rest];
        }

        $status = $rows->countBy('status');

        return Card::make(__('hr::dashboard.leave_this_month'), 'heat', $grid === [] ? [] : [
            'rows' => array_map(fn ($t) => Str::limit((string) $t, 10), array_keys($grid)),
            'cols' => $weeks, 'v' => array_values($grid), 'color' => '#f59e0b',
        ], sub: __('hr::dashboard.viz_leave_sub', [
            'pending' => (int) ($status[LeaveApplication::PENDING] ?? 0), 'approved' => (int) ($status[LeaveApplication::APPROVED] ?? 0),
            'rejected' => (int) ($status[LeaveApplication::REJECTED] ?? 0), 'cancelled' => (int) ($status[LeaveApplication::CANCELLED] ?? 0),
        ]), href: route('hr.leave.index'), colour: '#f59e0b');
    }

    /**
     * ভাতা ও কর্তনের প্রবাহ — বাঁয়ে খাত, ডানে মোট আয় আর কর্তন; বড় চারটা আয়ের খাত আর তিনটা কর্তন, বাকি "অন্যান্য"।
     * ⛔ ডাকার আগে [[showsPay()]]।
     *
     * @return array<string, mixed>
     */
    private static function headsFlow(): array
    {
        $heads = self::salaryHeads();
        $earn = __('hr::dashboard.viz_gross');
        $ded = __('hr::dashboard.viz_deductions');
        $left = [];
        $totals = [$earn => '0', $ded => '0'];

        foreach ([false => 4, true => 3] as $isDeduction => $keep) {
            $group = array_values(array_filter($heads['rows'] ?? [], fn (array $h) => $h['deduction'] === (bool) $isDeduction));
            usort($group, fn (array $x, array $y) => bccomp($y['total'], $x['total'], 4));
            $to = $isDeduction ? $ded : $earn;
            foreach (array_slice($group, 0, $keep) as $h) {
                $left[] = [Str::limit($h['name'], 14), $h['total'], $to];
            }
            $rest = array_reduce(array_slice($group, $keep), fn (string $c, array $h) => bcadd($c, $h['total'], 4), '0');
            if (bccomp($rest, '0', 4) > 0) {
                $left[] = [__('hr::dashboard.viz_other_heads'), $rest, $to];
            }
            $totals[$to] = array_reduce($group, fn (string $c, array $h) => bcadd($c, $h['total'], 4), '0');
        }

        $net = bcsub($totals[$earn], $totals[$ded], 4);

        return Card::make(__('hr::dashboard.viz_heads'), 'sankey', $heads === null ? [] : [
            'left' => $left, 'right' => [[$earn, $totals[$earn]], [$ded, $totals[$ded]]],
        ], value: $heads === null ? '' : self::short($net), sub: $heads === null ? __('hr::dashboard.viz_heads_none')
            : __('hr::dashboard.viz_heads_sub', ['month' => $heads['month'], 'net' => self::short($net)]),
            href: route('hr.payroll.index'), colour: '#7c3aed');
    }

    /**
     * দেখানো মাসের বেতনশিটের খাত — আয় আর কর্তন আলাদা, শূন্য বাদ; নিচের পুরনো "ভাতা ও কর্তন" আর বোর্ডের প্রবাহের একই সারি।
     * ⛔ ডাকার আগে [[showsPay()]]।
     *
     * @return array{rows: list<array{name: string, total: string, deduction: bool}>, month: string, from: Carbon}|null
     */
    private static function salaryHeads(): ?array
    {
        $month = self::payrollMonth();

        if ($month === null) {
            return null;
        }

        $rows = PayslipLine::query()
            ->join('hr_payslips', 'hr_payslips.id', '=', 'hr_payslip_lines.payslip_id')
            ->whereNull('hr_payslips.deleted_at')
            ->whereIn('hr_payslips.payroll_run_id', self::payrollRuns()->where('hr_payroll_runs.month', $month->toDateString())->select('hr_payroll_runs.id'))
            ->selectRaw('hr_payslip_lines.kind, hr_payslip_lines.head_code, MAX(hr_payslip_lines.head_name_en) as name_en, '
                .'MAX(hr_payslip_lines.head_name_bn) as name_bn, MIN(hr_payslip_lines.sort_order) as sort, SUM(hr_payslip_lines.amount) as total')
            ->groupBy('hr_payslip_lines.kind', 'hr_payslip_lines.head_code')
            ->toBase()->get()
            ->filter(fn ($r) => bccomp((string) $r->total, '0', 4) !== 0)
            ->sortBy(fn ($r) => [$r->kind === SalaryHead::DEDUCTION ? 1 : 0, (int) $r->sort, (string) $r->head_code])
            ->values();

        if ($rows->isEmpty()) {
            return null;
        }

        $bn = app()->getLocale() === 'bn';

        return [
            'rows' => $rows->map(fn ($r) => [
                'name' => $bn && filled($r->name_bn) ? (string) $r->name_bn : (string) $r->name_en,
                'total' => (string) $r->total,
                'deduction' => $r->kind === SalaryHead::DEDUCTION,
            ])->all(),
            'month' => $month->translatedFormat('M Y'),
            'from' => $month,
        ];
    }

    /** ⓘ নমুনার রূপ: কোটির ওপরে "৳X.XX কো", লাখের ওপরে "৳X.X লা", নিচে হাজারে "৳Xk" — ইংরেজি অঙ্ক, bcmath */
    private static function short(string $amount): string
    {
        $amount = $amount === '' ? '0' : $amount;
        $a = ltrim($amount, '-');
        $sign = bccomp($amount, '0', 4) < 0 ? '−' : '';

        return match (true) {
            bccomp($a, '10000000', 4) >= 0 => $sign.'৳'.bcdiv($a, '10000000', 2).' '.__('hr::dashboard.crore'),
            bccomp($a, '100000', 4) >= 0 => $sign.'৳'.bcdiv($a, '100000', 1).' '.__('hr::dashboard.lakh'),
            bccomp($a, '1000', 4) >= 0 => $sign.'৳'.bcdiv($a, '1000', 0).'k',
            default => $sign.'৳'.bcadd($a, '0', 0),
        };
    }

    /**
     * ⛔ ছুটির আবেদন দেখার শাখার কর্মীদের — পুনঃনিরীক্ষা, ৯ অক্টোবর ২০২৬।
     *
     * ⚠️ `LeaveApplication`-এ শাখার স্কোপ নেই, তাই এক শাখার মানুষ গোটা কোম্পানির
     * অপেক্ষমাণ ছুটি গুনতেন আর নাম দেখতেন। ⓘ "আজ উপস্থিত" ঘরের হুবহু নিয়ম:
     * কর্মীর শাখা ধরে।
     */
    private static function leaveInView(): Builder
    {
        return LeaveApplication::query()
            ->whereIn('employee_id', self::inView(Employee::query(), 'hr_employees.branch_id')->select('id'));
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
        ['headcount' => $headcount, 'present' => $present, 'late' => $late, 'leave' => $leave, 'absent' => $absent,
            'unwritten' => $unwritten] = self::rollCounts($today);

        // ⭐ প্রতিটা ভাগের অবস্থার রং — উপস্থিত ঠিক আছে, দেরিতে আজই, ছুটি ধূসর, অনুপস্থিত আর লেখা হয়নি জরুরি (মালিক, ১৮ নভেম্বর ২০২৬)
        return new Breakdown(
            label: __('hr::dashboard.todays_roll'),
            parts: [
                ['label' => __('hr::kind.present'), 'value' => (string) $present, 'tone' => 'ok'],
                ['label' => __('hr::dashboard.late'), 'value' => (string) $late, 'tone' => 'today'],
                ['label' => __('hr::kind.leave'), 'value' => (string) $leave, 'tone' => 'off'],
                ['label' => __('hr::kind.absent'), 'value' => (string) $absent, 'tone' => 'urgent'],
                ['label' => __('hr::dashboard.not_written'), 'value' => (string) $unwritten, 'tone' => 'urgent'],
            ],
            hint: __('hr::dashboard.todays_roll_hint', ['count' => $headcount]),
            // ⭐ কোন দিনের (মালিক, ৫ অক্টোবর ২০২৬)
            range: DateRange::label(Carbon::today(), Carbon::today()),
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

        $parts = self::departmentCounts();

        if ($parts === []) {
            return [];
        }

        return [new Breakdown(
            label: __('hr::dashboard.by_department'),
            parts: array_map(fn (array $p) => ['label' => $p['label'], 'value' => (string) $p['n']], $parts),
            hint: __('hr::dashboard.by_department_hint', ['count' => array_sum(array_column($parts, 'n'))]),
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
            range: DateRange::label($start, Carbon::today()),
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

        $year = self::yearMovement();

        return [new Breakdown(
            label: __('hr::dashboard.coming_and_going', ['year' => Carbon::today()->year]),
            parts: [
                ['label' => __('hr::dashboard.joined'), 'value' => (string) $year['joined']],
                ['label' => __('hr::dashboard.left'), 'value' => (string) $year['left']],
            ],
            hint: __('hr::dashboard.coming_and_going_hint'),
        )];
    }

    /**
     * বেতনের অঙ্ক দেখানো যায় কি না — বেতনের চাবি চাই, আর ফোনের উত্তরে কখনো নয়।
     *
     * ⛔ মালিক, ১৭ অক্টোবর ২০২৬: HR-এর ড্যাশবোর্ড ফোনে, বেতনের অঙ্ক সার্ভারেই বাদ ([[PhoneAnswer]], চুক্তি §৪) — চাবি থাকলেও।
     */
    private static function showsPay(): bool
    {
        return (bool) auth()->user()?->can('hr.payroll.view') && ! PhoneAnswer::active();
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
        if (! config('abos.dashboards_v2') || ! self::showsPay()) {
            return [];
        }

        $start = Carbon::today()->startOfMonth()->subMonths(5);

        // ⓘ গ্রাফের বোর্ড মোট আয়কে মূল বেতন আর ভাতায় ভাঙে (মাথায় মোট আয়) — এখানে তার বাকি দুই ভাগ: হাতে পাওয়া আর কর্তন, মাস ধরে
        $points = array_map(fn (array $m) => [
            'label' => $m['label'],
            'first' => $m['net'],
            'second' => bcsub($m['gross'], $m['net'], 4),
            'firstTitle' => Money::format($m['net']),
            'secondTitle' => Money::format(bcsub($m['gross'], $m['net'], 4)),
        ], self::salaryMonths());

        return [new Series(
            label: __('hr::dashboard.salary_cost'),
            points: $points,
            firstLabel: __('hr::field.net'),
            secondLabel: __('hr::dashboard.viz_deductions'),
            // ⓘ টাকা — মাউস/কিবোর্ডে পুরো অঙ্কের পরে "টাকা" ([[Series::$money]], ২০ অক্টোবর ২০২৬)
            money: true,
            // ⓘ কবে থেকে কবে — মালিক, ৫ অক্টোবর ২০২৬: প্রতিটা চার্টে তারিখ
            range: DateRange::label($start, Carbon::today()),
        )];
    }

    /**
     * ⭐ শাখা অনুযায়ী চালু কর্মী — মালিকের ড্যাশবোর্ড নকশা (৬ অক্টোবর ২০২৬)।
     *
     * ⓘ "বিভাগ অনুযায়ী"-র একই ভিত (দেখার শাখা, `leaving_date` নেই), তাই দুই চার্টের যোগফল এক। শাখাহীনরা আলাদা ভাগে।
     * ⓘ হেডারে একটা শাখা বাছা থাকলে একটাই দণ্ড — সেটাই ঠিক, অন্য শাখার মানুষ এখানে দেখানোর কথা নয়।
     * ⓘ নতুন ড্যাশবোর্ডের অংশ (config abos.dashboards_v2)।
     *
     * @return list<Breakdown>
     */
    private static function byBranch(): array
    {
        return self::workforceBy(
            'branch_id',
            fn (array $ids) => Branch::query()->whereIn('id', $ids)->get()->mapWithKeys(fn ($b) => [$b->id => $b->name()]),
            label: __('hr::dashboard.by_branch'),
            none: __('hr::dashboard.no_branch'),
            others: __('hr::dashboard.other_branches'),
            top: 6,
            chart: 'hbars',
        );
    }

    /**
     * ⭐ পদবি অনুযায়ী চালু কর্মী — মালিকের ড্যাশবোর্ড নকশা (৬ অক্টোবর ২০২৬)।
     *
     * ⓘ একই ভিত; বড় থেকে ছোট প্রথম পাঁচটা, বাকি "অন্যান্য" — ছয় টুকরোর বেশি ডোনাটে চোখে আলাদা হয় না।
     * ⓘ নতুন ড্যাশবোর্ডের অংশ (config abos.dashboards_v2)।
     *
     * @return list<Breakdown>
     */
    private static function byDesignation(): array
    {
        return self::workforceBy(
            'designation_id',
            fn (array $ids) => Designation::query()->whereIn('id', $ids)->get()->mapWithKeys(fn ($d) => [$d->id => $d->name()]),
            label: __('hr::dashboard.by_designation'),
            none: __('hr::dashboard.no_designation'),
            others: __('hr::dashboard.other_designations'),
            top: 5,
            chart: 'donut',
        );
    }

    /**
     * চালু কর্মী এক ঘর ধরে ভাগ — শাখা আর পদবির চার্টের একটাই নিয়ম (৬ অক্টোবর ২০২৬)।
     *
     * @param  callable(list<int>): Collection<int, string>  $names
     * @return list<Breakdown>
     */
    private static function workforceBy(string $column, callable $names, string $label, string $none, string $others, int $top, string $chart): array
    {
        if (! config('abos.dashboards_v2')) {
            return [];
        }

        $rows = self::inView(Employee::query()->whereNull('leaving_date'), 'hr_employees.branch_id')
            ->selectRaw("hr_employees.{$column} as k, COUNT(*) as n")
            ->groupBy("hr_employees.{$column}")
            ->toBase()->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $known = $names($rows->pluck('k')->filter()->map(fn ($id) => (int) $id)->values()->all());

        $parts = $rows->map(fn ($r) => [
            'label' => $r->k === null ? $none : ($known[$r->k] ?? '—'),
            'n' => (int) $r->n,
        ])->sortByDesc('n')->values();

        $shown = $parts->take($top);
        $rest = $parts->slice($top)->sum('n');

        if ($rest > 0) {
            $shown->push(['label' => $others, 'n' => $rest]);
        }

        return [new Breakdown(
            label: $label,
            parts: $shown->map(fn ($p) => ['label' => $p['label'], 'value' => (string) $p['n']])->all(),
            hint: __('hr::dashboard.workforce_hint', ['count' => $parts->sum('n')]),
            chart: $chart,
        )];
    }

    /**
     * কোন মাসের বেতন দেখানো হবে — এ মাসের নিশ্চিত বেতনশিট থাকলে এ মাস, নইলে তার আগের সর্বশেষ নিশ্চিত মাস (৬ অক্টোবর ২০২৬)।
     *
     * ⓘ বেতন সাধারণত মাসের শেষে হয়; কেবল "এ মাস" ধরলে মাসের প্রায় পুরোটা জুড়ে চার্ট ফাঁকা থাকত। কোন মাস, তা চার্টের নিচে লেখা থাকে।
     * ⓘ "বেতন খরচ — গত ছয় মাস"-এর একই ছাঁকনি: নিশ্চিত রান, রানের শাখা দেখার শাখায়।
     */
    private static function payrollMonth(): ?Carbon
    {
        $month = self::payrollRuns()
            ->where('month', '<=', Carbon::today()->startOfMonth()->toDateString())
            ->max('month');

        return $month !== null ? Carbon::parse((string) $month)->startOfMonth() : null;
    }

    /** নিশ্চিত বেতন-রান, দেখার শাখায় — বেতনের সব চার্টের একই ভিত। */
    private static function payrollRuns(): \Illuminate\Database\Eloquent\Builder
    {
        return self::inView(PayrollRun::query(), 'hr_payroll_runs.branch_id')
            ->whereIn('hr_payroll_runs.status', DocumentStatus::POSTED);
    }

    /**
     * ⭐ ভাতা বনাম কর্তন — বেতনশিটের সারি, খাত ধরে (মালিকের ড্যাশবোর্ড নকশা, ৬ অক্টোবর ২০২৬)।
     *
     * ⓘ বেতনশিটের সারির (`hr_payslip_lines`) কপি করা খাত-নাম আর ধরন — খাতের নাম পরে বদলালেও সেদিনের নামই থাকে।
     * আগে আয়ের খাতগুলো (মূল বেতনসহ), তারপর কর্তনের খাত সামনে "−" চিহ্ন নিয়ে; দুই দলের মোট ব্যাখ্যায়।
     * ⓘ আয়ের মোট = বেতনশিটের `gross`, কর্তনের মোট = `deductions` — বেতনশিটের পর্দার সাথে এক।
     * ⛔ টাকার অঙ্ক — কেবল `hr.payroll.view`। ⓘ নতুন ড্যাশবোর্ডের অংশ (config abos.dashboards_v2)।
     *
     * @return list<Breakdown>
     */
    private static function allowancesAndDeductions(): array
    {
        if (! config('abos.dashboards_v2') || ! self::showsPay()) {
            return [];
        }

        $heads = self::salaryHeads();

        if ($heads === null) {
            return [];
        }

        $month = $heads['from'];
        $earning = '0';
        $deduction = '0';
        $parts = [];

        foreach ($heads['rows'] as $r) {
            if ($r['deduction']) {
                $deduction = bcadd($deduction, $r['total'], 4);
            } else {
                $earning = bcadd($earning, $r['total'], 4);
            }

            $parts[] = [
                'label' => $r['deduction'] ? __('hr::dashboard.deduction_head', ['name' => $r['name']]) : $r['name'],
                'value' => Money::format($r['total']),
            ];
        }

        return [new Breakdown(
            label: __('hr::dashboard.allowance_deduction'),
            parts: $parts,
            hint: __('hr::dashboard.allowance_deduction_hint', ['earning' => Money::format($earning), 'deduction' => Money::format($deduction)]),
            chart: 'columns',
            range: DateRange::label($month, $month->copy()->endOfMonth()),
        )];
    }

    /**
     * ⭐ বিভাগ অনুযায়ী বেতন খরচ — মালিকের ড্যাশবোর্ড নকশা (৬ অক্টোবর ২০২৬)।
     *
     * ⓘ খরচ = বেতনশিটের মোট আয় (`gross`) — কর্তন কর্মীর পকেট থেকে যায়, প্রতিষ্ঠানের খরচ কমায় না। বিভাগ কর্মীর
     * আজকের বিভাগ (`hr_employees.department_id`); বিভাগহীনরা আলাদা ভাগে, যাতে যোগফল মাসের মোট বেতনের সমান থাকে।
     * ⓘ ভাতা-কর্তনের চার্টের একই মাস আর একই রান; বড় থেকে ছোট প্রথম ছয়টা, বাকি "অন্যান্য"।
     * ⛔ টাকার অঙ্ক — কেবল `hr.payroll.view`। ⓘ নতুন ড্যাশবোর্ডের অংশ (config abos.dashboards_v2)।
     *
     * @return list<Breakdown>
     */
    private static function costByDepartment(): array
    {
        if (! config('abos.dashboards_v2') || ! self::showsPay()) {
            return [];
        }

        $cost = self::departmentCost();

        if ($cost === null) {
            return [];
        }

        $month = $cost['from'];
        $parts = collect($cost['rows']);

        $sum = fn ($list) => $list->reduce(fn (string $carry, $p) => bcadd($carry, $p['cost'], 4), '0');

        $shown = $parts->take(6);
        $rest = $sum($parts->slice(6));

        if (bccomp($rest, '0', 4) !== 0) {
            $shown->push(['label' => __('hr::dashboard.other_departments'), 'cost' => $rest]);
        }

        return [new Breakdown(
            label: __('hr::dashboard.cost_by_department'),
            parts: $shown->map(fn ($p) => ['label' => $p['label'], 'value' => Money::format($p['cost'])])->all(),
            hint: __('hr::dashboard.cost_by_department_hint', ['total' => Money::format($sum($parts))]),
            chart: 'hbars',
            range: DateRange::label($month, $month->copy()->endOfMonth()),
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
