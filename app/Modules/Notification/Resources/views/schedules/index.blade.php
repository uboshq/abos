{{-- ⭐ সূচিমতো খবরের তালিকা — কোন টেমপ্লেট, কোন দল, পরের পাঠানো (মালিকের স্পেক §৪ "Notification Schedule"; ধাপ ৩) --}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('notification::schedule.title') }}</x-slot:title>

    <div class="space-y-4">
        <x-ui.toolbar :title="__('notification::schedule.title')" :search="false" :export="false" :share="false" />

        <div class="flex items-center justify-between gap-2">
            <p class="text-sm text-(--color-ink-muted)">{{ __('notification::schedule.note') }}</p>
            <a href="{{ route('notification.schedules.create') }}" class="shrink-0 rounded-(--radius-field) bg-(--color-brand-500) px-3 py-1.5 text-sm text-white">{{ __('notification::schedule.new') }}</a>
        </div>

        @include('notification::partials.flash')

        @if ($rows->isEmpty())
            <p class="text-sm text-(--color-ink-muted)">{{ __('notification::schedule.empty') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="ui-grid">
                    <thead class="bg-(--color-surface-sunken) text-2xs text-(--color-ink-muted)">
                        <tr>
                            <th class="text-start">{{ __('notification::schedule.name') }}</th>
                            <th class="text-start">{{ __('notification::schedule.template') }}</th>
                            <th class="text-start">{{ __('notification::schedule.group') }}</th>
                            <th class="text-start">{{ __('notification::schedule.recurrence') }}</th>
                            <th class="text-start">{{ __('notification::schedule.next') }}</th>
                            <th class="text-end">{{ __('notification::schedule.runs') }}</th>
                            <th class="text-start">{{ __('notification::rule.state') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $schedule)
                            <tr class="border-t border-(--color-border)" data-schedule="{{ $schedule->id }}">
                                <td><a href="{{ route('notification.schedules.edit', $schedule) }}" class="hover:underline">{{ $schedule->name }}</a></td>
                                <td class="text-2xs">{{ $schedule->template?->name ?? '—' }}</td>
                                <td class="text-2xs">{{ $schedule->group?->name ?? '—' }}</td>
                                <td class="text-2xs">{{ __('notification::schedule.recurrences.'.$schedule->recurrence) }}</td>
                                <td class="whitespace-nowrap text-2xs">{{ $schedule->next_run_at?->copy()->setTimezone($schedule->timezone)->format('d/m/Y H:i') ?? '—' }} <span class="text-(--color-ink-muted)">{{ $schedule->timezone }}</span></td>
                                <td class="text-end text-2xs tabular-nums">{{ $schedule->runs }}</td>
                                <td class="text-2xs">{{ __('notification::rule.states.'.($schedule->is_active ? 'active' : 'inactive')) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <x-ui.pager :rows="$rows" />
        <x-ui.list-totals :rows="$rows" />
    </div>
</x-layouts.app>
