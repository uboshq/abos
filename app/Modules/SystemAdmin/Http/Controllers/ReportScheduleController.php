<?php

declare(strict_types=1);

namespace App\Modules\SystemAdmin\Http\Controllers;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Models\ReportRun;
use App\Models\ReportSchedule;
use App\Models\User;
use App\Modules\SystemAdmin\Services\ScheduleService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * নির্ধারিত রিপোর্টের সূচি — বানানো, বদলানো, থামানো।
 *
 * ফাইল তৈরি ও অনুমতির আসল কাজ ScheduleService ও ScheduledReportRunner-এ;
 * এই কন্ট্রোলার কেবল পর্দা আর ফর্ম।
 */
final class ReportScheduleController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly ScheduleService $schedules,
        private readonly ReportEngine $reports,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:system_admin.reports.schedule')];
    }

    public function index(): View
    {
        return view('system_admin::reports.index', [
            'menu' => $this->menu->forUser(request()->user()),
            'schedules' => ReportSchedule::query()->latest()->get(),
            'runs' => ReportRun::query()->latest('ran_at')->limit(20)->get(),
            'reportTitles' => $this->reportTitles(),
        ]);
    }

    public function create(): View
    {
        return $this->form(new ReportSchedule([
            'format' => 'xlsx',
            'frequency' => 'daily',
            'at_time' => '08:00',
            'timezone' => config('app.timezone'),
        ]));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->schedules->create($this->validated($request));

        return redirect()
            ->route('system_admin.reports.schedule.index')
            ->with('saved', __('system_admin::schedule.saved'));
    }

    public function edit(ReportSchedule $schedule): View
    {
        return $this->form($schedule);
    }

    public function update(Request $request, ReportSchedule $schedule): RedirectResponse
    {
        $this->schedules->update($schedule, $this->validated($request));

        return redirect()
            ->route('system_admin.reports.schedule.index')
            ->with('saved', __('system_admin::schedule.saved'));
    }

    /** চালু/বন্ধ — মোছা নয়। */
    public function toggle(ReportSchedule $schedule): RedirectResponse
    {
        $schedule->is_active
            ? $this->schedules->deactivate($schedule)
            : $this->schedules->activate($schedule);

        return back()->with('saved', __('system_admin::schedule.saved'));
    }

    private function form(ReportSchedule $schedule): View
    {
        return view('system_admin::reports.form', [
            'menu' => $this->menu->forUser(request()->user()),
            'schedule' => $schedule,
            'reportTitles' => $this->reportTitles(),
            'users' => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'report_key' => ['required', 'string'],
            'format' => ['required', 'string', 'in:csv,xlsx,json,pdf'],
            'frequency' => ['required', 'string', 'in:daily,weekly,monthly'],
            'at_time' => ['required', 'string'],
            'day_of_week' => ['nullable', 'integer', 'between:0,6'],
            'day_of_month' => ['nullable', 'integer', 'between:1,28'],
            'on_month_end' => ['nullable', 'boolean'],
            'timezone' => ['nullable', 'string', 'timezone'],
            'recipients' => ['nullable', 'array'],
            'recipients.*' => ['integer'],
        ]);
    }

    /**
     * রিপোর্টের key => শিরোনাম — ড্রপডাউন ও তালিকার নামের জন্য।
     *
     * @return array<string, string>
     */
    private function reportTitles(): array
    {
        $titles = [];

        foreach ($this->reports->keys() as $key) {
            $titles[$key] = __($this->reports->get($key)->title);
        }

        asort($titles);

        return $titles;
    }
}
