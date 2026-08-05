<?php

declare(strict_types=1);

namespace App\Modules\Hr\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Core\Services\SettingsService;
use App\Http\Controllers\Controller;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Services\AttendanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * হাজিরা — একটা দিনের পর্দা, আর মাসের সারসংক্ষেপ।
 */
class AttendanceController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly MenuBuilder $menu,
        private readonly SettingsService $settings,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:hr.attendance.view', only: ['index', 'sheet']),
            new Middleware('can:hr.attendance.manage', only: ['store']),
        ];
    }

    /**
     * আজকের (বা বাছা দিনের) পর্দা — সবার সারি একসাথে।
     */
    public function index(Request $request): View
    {
        $date = $this->chosenDate($request);

        $employees = Employee::query()
            ->onPayrollFor($date)
            ->with(['department', 'designation'])
            ->orderBy('code')
            ->get();

        /*
         * ওই দিনের যা আগে বসানো আছে।
         *
         * ফর্মে আগের মানটাই বসে থাকে, তাই একটা সারি শুধরাতে গিয়ে বাকি
         * উনিশটা আবার বাছতে হয় না — আর ভুল করে খালি রেখে সংরক্ষণ করলেও
         * আগেরগুলো মুছে যায় না।
         */
        $existing = Attendance::query()
            ->whereDate('work_date', $date->toDateString())
            ->get()
            ->keyBy('employee_id');

        return view('hr::attendance.index', [
            'menu' => $this->menu->forUser($request->user()),
            'employees' => $employees,
            'existing' => $existing,
            'date' => $date,
            'statuses' => Attendance::STATUSES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'work_date' => ['required', 'date'],
            'rows' => ['required', 'array'],
            'rows.*.status' => ['nullable', Rule::in(Attendance::STATUSES)],
            'rows.*.is_late' => ['nullable', 'boolean'],
            'rows.*.remarks' => ['nullable', 'string', 'max:191'],
        ]);

        $marked = $this->attendance->markDay($data['work_date'], $data['rows']);

        return redirect()
            ->route('hr.attendance.index', ['date' => $data['work_date']])
            ->with('saved', __('hr::message.attendance_saved', ['count' => $marked]));
    }

    /**
     * মাসের সারসংক্ষেপ — কে কতদিন এসেছে, কতদিন কাটা যাবে।
     */
    public function sheet(Request $request): View
    {
        $month = $this->chosenMonth($request);

        $employees = Employee::query()
            ->onPayrollFor($month->copy()->endOfMonth())
            ->orderBy('code')
            ->get();

        $rows = $employees->map(fn (Employee $employee) => [
            'employee' => $employee,
            'summary' => $this->attendance->monthlySummary($employee, $month),
        ]);

        return view('hr::attendance.sheet', [
            'menu' => $this->menu->forUser($request->user()),
            'rows' => $rows,
            'month' => $month,

            /*
             * সুইচটা কন্ট্রোলার থেকে যায়, ভিউ থেকে সেবা ডাকা হয় না।
             *
             * ভিউয়ে app(...) লিখলে পর্দাটা নিজেই কাজ খুঁজতে যেত, আর
             * পরীক্ষায় সেটা বদলে দেওয়ার কোনো পথ থাকত না।
             */
            'affectsSalary' => (bool) $this->settings->get('hr.attendance_affects_salary'),
        ]);
    }

    private function chosenDate(Request $request): Carbon
    {
        $date = $request->query('date');

        return filled($date) ? Carbon::parse((string) $date) : now();
    }

    private function chosenMonth(Request $request): Carbon
    {
        $month = $request->query('month');

        return filled($month)
            ? Carbon::parse((string) $month.'-01')
            : now()->startOfMonth();
    }
}
