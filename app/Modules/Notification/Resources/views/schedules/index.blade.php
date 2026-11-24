{{-- ⭐ সূচিমতো খবরের তালিকা — কোন টেমপ্লেট, কোন দল, পরের পাঠানো (মালিকের স্পেক §৪ "Notification Schedule"; ধাপ ৩) --}}
@php
    // ⓘ ব্যাখ্যা নামের পাশে ⓘ-এ, "নতুন" মাথার সারিতে — কেবল তিন ABOS রূপে ([[AbosHead]]; মালিক, ২৪ নভেম্বর ২০২৬)
    $abosHead = \App\Core\Support\AbosHead::on();
    $columns = [
        ['key' => 'name', 'label' => __('notification::schedule.name'), 'render' => fn ($r) => new \Illuminate\Support\HtmlString('<a href="'.e(route('notification.schedules.edit', $r)).'" class="hover:underline">'.e($r->name).'</a>')],
        ['key' => 'template', 'label' => __('notification::schedule.template'), 'render' => fn ($r) => $r->template?->name ?? '—'],
        ['key' => 'group', 'label' => __('notification::schedule.group'), 'render' => fn ($r) => $r->group?->name ?? '—'],
        ['key' => 'recurrence', 'label' => __('notification::schedule.recurrence'), 'render' => fn ($r) => __('notification::schedule.recurrences.'.$r->recurrence)],
        ['key' => 'next', 'label' => __('notification::schedule.next'), 'render' => fn ($r) => $r->next_run_at ? $r->next_run_at->copy()->setTimezone($r->timezone)->format('d/m/Y H:i').' '.$r->timezone : '—'],
        ['key' => 'runs', 'label' => __('notification::schedule.runs'), 'numeric' => true, 'render' => fn ($r) => (string) $r->runs],
        ['key' => 'state', 'label' => __('notification::rule.state'), 'render' => fn ($r) => __('notification::rule.states.'.($r->is_active ? 'active' : 'inactive'))],
    ];

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
    <div @class(['space-y-4' => ! $abosHead, 'overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)' => $abosHead]) @if ($abosHead) data-boxed @endif>
        <form id="list-form" method="GET" class="contents">
        <x-ui.toolbar :title="__('notification::schedule.title')" :info="__('notification::schedule.note')" :search-placeholder="__('notification::schedule.name')" :export="$abosHead" :share="false" :sort="$abosHead ? ['next' => __('notification::schedule.next'), 'name' => __('notification::schedule.name')] : []" :columns="$abosHead ? $columns : []" :facets="$facetPanel">
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

        @if ($abosHead && (session('saved') || session('failed') || $errors->any()))
            <div class="px-3 pt-2">@include('notification::partials.flash')</div>
        @elseif (! $abosHead)
            @include('notification::partials.flash')
        @endif

        <div>
        @if ($rows->isEmpty())
            @if ($abosHead)
                <x-ui.empty-state :message="__('notification::schedule.empty')" />
            @else
                <p class="text-sm text-(--color-ink-muted)">{{ __('notification::schedule.empty') }}</p>
            @endif
        @else
            @if ($abosHead)
            <x-ui.table :rows="$rows" :columns="$columns" :row-attributes="fn ($r) => ['data-schedule' => $r->id]" />
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
        @endif
        </div>

        <x-ui.pager :rows="$rows" />
        <x-ui.list-totals :rows="$rows" />
    </div>
    </x-ui.filter-layout>
</x-layouts.app>
