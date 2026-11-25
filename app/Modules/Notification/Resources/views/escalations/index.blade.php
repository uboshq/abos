{{--
    ⭐ ওপরে পাঠানো — অপেক্ষমাণ অনুমোদন: শেষ সময়, স্তর, দায়িত্বে কে, কাকে পাঠানো হলো (মালিকের স্পেক §৪, §১৪; ধাপ ৪)।
    ⓘ মনে করানো আর ওপরে পাঠানো করে অনুমোদন ইঞ্জিন নিজে (`abos:approvals-due`); এখানে কেবল দেখা। প্রতিটা সারি অনুরোধে খোলে।
--}}
@php
    // ⭐ ছাঁকনি ডান পাশের প্যানেলে — আগের ঘর, একই চাবি (মালিক, ২৩ নভেম্বর ২০২৬; [[Facets]])
    $facetPanel = \App\Core\ListFilter\Facets::panel([
        \App\Core\ListFilter\Facets::pick('state', __('notification::escalation.state'),
            collect(['waiting', 'late', 'escalated'])->mapWithKeys(fn ($s) => [$s => __('notification::escalation.filter.'.$s)])->all(), __('core.notify.any')),
    ]);
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('notification::escalation.title') }}</x-slot:title>

    <x-ui.filter-layout :filters="$facetPanel" form="list-form">
    <div class="space-y-4">
        <form id="list-form" method="GET" class="contents">
        <x-ui.toolbar :title="__('notification::escalation.title')" :info="__('notification::escalation.note')" :search="false" :export="false" :share="false" :facets="$facetPanel">
        </x-ui.toolbar>
        </form>

        <p data-page-subtitle class="text-sm text-(--color-ink-muted)">{{ __('notification::escalation.note') }}</p>

        @if ($holes->isNotEmpty())
            <section data-escalation-holes class="space-y-1 rounded-(--radius-card) border border-(--color-border) bg-(--color-badge-warning-bg) p-3 text-sm text-(--color-badge-warning-ink)">
                <p class="font-medium">{{ __('notification::escalation.holes', ['count' => $holes->count()]) }}</p>
                <ul class="text-2xs">
                    @foreach ($holes as $hole)
                        <li>{{ $hole->module }} · {{ $hole->action }} — {{ __('notification::escalation.level') }} {{ $hole->level }}{{ $hole->step_name ? ' ('.$hole->step_name.')' : '' }}</li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($rows->isEmpty())
            @if (\App\Core\Support\AbosHead::on())
                <x-ui.empty-state :message="__('notification::escalation.empty')" />
            @else
                <p class="text-sm text-(--color-ink-muted)">{{ __('notification::escalation.empty') }}</p>
            @endif
        @else
            <div class="overflow-x-auto">
                <table class="ui-grid">
                    <thead class="bg-(--color-surface-sunken) text-2xs text-(--color-ink-muted)">
                        <tr>
                            <th class="text-start">{{ __('notification::escalation.paper') }}</th>
                            <th class="text-end">{{ __('notification::escalation.level') }}</th>
                            <th class="text-start">{{ __('notification::escalation.responsible') }}</th>
                            <th class="text-start">{{ __('notification::escalation.deadline') }}</th>
                            <th class="text-start">{{ __('notification::escalation.state') }}</th>
                            <th class="text-start">{{ __('notification::escalation.reminded') }}</th>
                            <th class="text-start">{{ __('notification::escalation.escalated_to') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $approval)
                            @php $state = $states[$approval->id] ?? 'none'; @endphp
                            <tr class="border-t border-(--color-border)" data-escalation="{{ $approval->id }}" data-state="{{ $state }}">
                                <td class="text-2xs"><a href="{{ route('approval.inbox.show', $approval) }}" class="hover:underline">{{ $approval->drillLabel() }}</a></td>
                                <td class="text-end text-2xs tabular-nums">{{ $approval->current_level }}</td>
                                <td class="text-2xs">{{ $approval->assignee?->name ?? '—' }}</td>
                                <td class="whitespace-nowrap text-2xs">{{ \App\Core\Support\DateFormat::formatWithTime($approval->due_at) }}</td>
                                <td class="text-2xs">
                                    <span @class([
                                        'rounded-full px-1.5 py-0.5',
                                        'bg-(--color-danger) text-white' => $state === 'late',
                                        'bg-(--color-badge-warning-bg) text-(--color-badge-warning-ink)' => in_array($state, ['near', 'escalated'], true),
                                        'text-(--color-ink-muted)' => in_array($state, ['fine', 'none'], true),
                                    ])>{{ __('notification::escalation.states.'.$state) }}</span>
                                </td>
                                <td class="whitespace-nowrap text-2xs">{{ (\App\Core\Support\DateFormat::formatWithTime($approval->reminded_at) ?: '—') }}</td>
                                <td class="text-2xs">{{ $approval->escalated_to ? ($escalatedTo[$approval->escalated_to] ?? '—') : '—' }}
                                    @if ($approval->escalated_at)<span class="block text-(--color-ink-muted)">{{ \App\Core\Support\DateFormat::formatWithTime($approval->escalated_at) }}</span>@endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <x-ui.pager :rows="$rows" />
        <x-ui.list-totals :rows="$rows" />
    </div>
    </x-ui.filter-layout>
</x-layouts.app>
