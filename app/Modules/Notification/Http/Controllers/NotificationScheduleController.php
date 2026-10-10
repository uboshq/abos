<?php

declare(strict_types=1);

namespace App\Modules\Notification\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Core\Services\NotificationAudit;
use App\Core\Support\Actor;
use App\Core\Support\CompanyContext;
use App\Core\Support\NotificationKinds;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\NotificationPreference;
use App\Models\NotificationSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * ⭐ সূচিমতো খবর — কোন টেমপ্লেট, কোন দলকে, কখন (নিজের সময় অঞ্চলে), কতবার (মালিকের স্পেক §৪ "Schedule"; ধাপ ৩)।
 * ⓘ পাঠায় `abos:notifications-schedule` ([[ScheduleRunner]])। সময় পর্দায় সূচির নিজের অঞ্চলে, ভিতরে অ্যাপের অঞ্চলে।
 */
class NotificationScheduleController extends Controller
{
    private const PER_PAGE = 50;

    public function __construct(private readonly MenuBuilder $menu) {}

    public function index(Request $request): View
    {
        $rows = NotificationSchedule::query()->with(['template', 'group'])->orderByDesc('is_active')->orderBy('next_run_at')
            ->paginate(self::PER_PAGE)->withQueryString();

        return view('notification::schedules.index', [
            'menu' => $this->menu->forUser($request->user()),
            'rows' => $rows,
        ]);
    }

    public function create(Request $request): View
    {
        return $this->form($request, new NotificationSchedule([
            'priority' => 'normal', 'recurrence' => 'none', 'is_active' => true, 'timezone' => $this->zone(),
        ]));
    }

    public function store(Request $request): RedirectResponse
    {
        $schedule = NotificationSchedule::query()->create($this->validated($request) + ['created_by' => Actor::userId()]);
        app(NotificationAudit::class)->record('schedule_save', $schedule, 'done', ['schedule_id' => $schedule->id]);

        return redirect()->route('notification.schedules.edit', $schedule)->with('saved', __('notification::schedule.saved'));
    }

    public function edit(Request $request, NotificationSchedule $schedule): View
    {
        return $this->form($request, $schedule);
    }

    public function update(Request $request, NotificationSchedule $schedule): RedirectResponse
    {
        $schedule->fill($this->validated($request))->save();
        app(NotificationAudit::class)->record('schedule_save', $schedule, 'done', ['schedule_id' => $schedule->id]);

        return redirect()->route('notification.schedules.edit', $schedule)->with('saved', __('notification::schedule.saved'));
    }

    private function form(Request $request, NotificationSchedule $schedule): View
    {
        $choices = RecipientChoices::all();

        return view('notification::schedules.form', [
            'menu' => $this->menu->forUser($request->user()),
            'schedule' => $schedule,
            'templates' => $choices['templates'],
            'groups' => $choices['groups'],
            'zones' => NotificationPreference::ZONES,
            'local' => $schedule->next_run_at?->copy()->setTimezone($schedule->timezone ?: $this->zone())->format('Y-m-d\TH:i'),
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $choices = RecipientChoices::ids();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'template_id' => ['required', Rule::in($choices['templates'])],
            'group_id' => ['required', Rule::in($choices['groups'])],
            'priority' => ['required', Rule::in(NotificationKinds::PRIORITIES)],
            'timezone' => ['required', Rule::in(NotificationPreference::ZONES)],
            'recurrence' => ['required', Rule::in(NotificationSchedule::RECURRENCES)],
            'run_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        return [
            'name' => $data['name'],
            'template_id' => (int) $data['template_id'],
            'group_id' => (int) $data['group_id'],
            'priority' => $data['priority'],
            'timezone' => $data['timezone'],
            'recurrence' => $data['recurrence'],
            // ⓘ পর্দার সময়টা সূচির সময় অঞ্চলে — ভিতরে অ্যাপের সময় অঞ্চলে (ডেটাবেসের সব সময় যেভাবে থাকে)
            'next_run_at' => CarbonImmutable::createFromFormat('Y-m-d\TH:i', $data['run_at'], $data['timezone'])->setTimezone(config('app.timezone')),
            'is_active' => (bool) ($data['is_active'] ?? false),
        ];
    }

    private function zone(): string
    {
        return (string) (Company::query()->whereKey(CompanyContext::id())->value('timezone') ?: 'Asia/Dhaka');
    }
}
