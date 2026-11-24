{{-- ⭐ সূচিমতো খবরের তালিকা — কোন টেমপ্লেট, কোন দল, পরের পাঠানো (মালিকের স্পেক §৪ "Notification Schedule"; ধাপ ৩) --}}
@php
    // ⓘ ব্যাখ্যা নামের পাশে ⓘ-এ, "নতুন" মাথার সারিতে — কেবল তিন ABOS রূপে ([[AbosHead]]; মালিক, ২৪ নভেম্বর ২০২৬)
    $abosHead = \App\Core\Support\AbosHead::on();

    // ⭐ ছাঁকনি ডান পাশের প্যানেলে ([[Facets]]; মালিক, ২৪ নভেম্বর ২০২৬)
    $facetPanel = \App\Core\ListFilter\Facets::panel([
        \App\Core\ListFilter\Facets::pick('recurrence', __('notification::schedule.recurrence'),
            collect(\App\Models\NotificationSchedule::RECURRENCES)->mapWithKeys(fn ($r) => [$r => __('notification::schedule.recurrences.'.$r)])->all(), __('core.notify.any')),
        \App\Core\ListFilter\Facets::pick('state', __('notification::rule.state'),
            collect(['active', 'inactive'])->mapWithKeys(fn ($s) => [$s => __('notification::rule.states.'.$s)])->all(), __('core.notify.any')),
    ]);
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('notification::schedule.title') }}</x-slot:title>

    <x-ui.filter-layout :filters="$facetPanel" form="list-form">
    <div class="space-y-4">
        <form id="list-form" method="GET" class="contents">
        <x-ui.toolbar :title="__('notification::schedule.title')" :info="__('notification::schedule.note')" :search="false" :export="false" :share="false" :facets="$facetPanel">
            @if ($abosHead)
                <x-slot:actions>
                    <x-ui.button tone="primary" icon="plus" :href="route('notification.schedules.create')">{{ __('notification::schedule.new') }}</x-ui.button>
                </x-slot:actions>
            @endif
        </x-ui.toolbar>
        </form>

        @unless ($abosHead)
            <div class="flex items-center justify-between gap-2">
                <p class="text-sm text-(--color-ink-muted)">{{ __('notification::schedule.note') }}</p>
                <a href="{{ route('notification.schedules.create') }}" class="shrink-0 rounded-(--radius-field) bg-(--color-brand-500) px-3 py-1.5 text-sm text-white">{{ __('notification::schedule.new') }}</a>
            </div>
        @endunless

        @include('notification::partials.flash')

        {{-- ⓘ ABOS রূপে তালিকার সাদা কার্ড, বাকি তালিকার মতো --}}
        <div @class(['overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)' => $abosHead, 'p-3' => $abosHead && $rows->isEmpty()]) @if ($abosHead) data-boxed @endif>
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
        </div>

        <x-ui.pager :rows="$rows" />
        <x-ui.list-totals :rows="$rows" />
    </div>
    </x-ui.filter-layout>
</x-layouts.app>
