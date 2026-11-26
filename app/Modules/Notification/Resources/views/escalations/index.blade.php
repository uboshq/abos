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
    $columns = [
        ['key' => 'paper', 'label' => __('notification::escalation.paper'),
         'render' => fn ($approval) => new \Illuminate\Support\HtmlString('<a href="'.e(route('approval.inbox.show', $approval)).'" class="hover:underline">'.e($approval->drillLabel()).'</a>')],
        ['key' => 'level', 'label' => __('notification::escalation.level'), 'numeric' => true, 'width' => '5rem', 'render' => fn ($approval) => $approval->current_level],
        ['key' => 'responsible', 'label' => __('notification::escalation.responsible'), 'render' => fn ($approval) => $approval->assignee?->name ?? '—'],
        ['key' => 'deadline', 'label' => __('notification::escalation.deadline'), 'width' => '10rem',
         'render' => fn ($approval) => \App\Core\Support\DateFormat::formatWithTime($approval->due_at)],
        ['key' => 'state', 'label' => __('notification::escalation.state'), 'render' => function ($approval) use ($states) {
            $state = $states[$approval->id] ?? 'none';
            $tone = match (true) {
                $state === 'late' => 'bg-(--color-danger) text-white',
                in_array($state, ['near', 'escalated'], true) => 'bg-(--color-badge-warning-bg) text-(--color-badge-warning-ink)',
                default => 'text-(--color-ink-muted)',
            };

            return new \Illuminate\Support\HtmlString('<span class="rounded-full px-1.5 py-0.5 '.$tone.'">'.e(__('notification::escalation.states.'.$state)).'</span>');
        }],
        ['key' => 'reminded', 'label' => __('notification::escalation.reminded'), 'width' => '10rem',
         'render' => fn ($approval) => \App\Core\Support\DateFormat::formatWithTime($approval->reminded_at) ?: '—'],
        ['key' => 'escalated_to', 'label' => __('notification::escalation.escalated_to'), 'render' => function ($approval) use ($escalatedTo) {
            $who = e($approval->escalated_to ? ($escalatedTo[$approval->escalated_to] ?? '—') : '—');
            $at = $approval->escalated_at ? '<span class="block text-(--color-ink-muted)">'.e(\App\Core\Support\DateFormat::formatWithTime($approval->escalated_at)).'</span>' : '';

            return new \Illuminate\Support\HtmlString($who.$at);
        }],
    ];
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('notification::escalation.title') }}</x-slot:title>

    <x-ui.filter-layout :filters="$facetPanel" form="list-form">
    <div class="space-y-4">
        <form id="list-form" method="GET" class="contents">
        <x-ui.toolbar :title="__('notification::escalation.title')" :info="__('notification::escalation.note')" :search="false" :export="false" :share="false" :facets="$facetPanel" :columns="$columns"
                      :sort="\App\Core\ListFilter\ListSort::labels(['due_soon', 'newest', 'oldest'])">
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

        {{-- ⭐ পুরো বার — সাধারণ টেবিল, কলাম বাছাই আর সাজানো (মালিক, ২৪ নভেম্বর ২০২৬; ২৭ নভেম্বর); সারির চেহারা আগের মতোই --}}
        <x-ui.table :rows="$rows" :columns="$columns" :empty="__('notification::escalation.empty')"
                    :row-attributes="fn ($approval) => ['data-escalation' => $approval->id, 'data-state' => $states[$approval->id] ?? 'none']" />

        <x-ui.pager :rows="$rows" />
        <x-ui.list-totals :rows="$rows" />
    </div>
    </x-ui.filter-layout>
</x-layouts.app>
