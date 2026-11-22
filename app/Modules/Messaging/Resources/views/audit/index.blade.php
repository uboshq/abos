{{--
    ⛔ Messenger audit — who talked with whom, and when; never a word of what was said until a reason is written.
    ⓘ Each row opens only through the reason form ([[MessengerAuditController::open()]]), recorded in the audit trail.
    ⓘ Built on the house list parts (toolbar, ui-list table, pager, totals bar) so every look and theme dresses it.
--}}
@php
    $columns = [
        ['key' => 'who', 'label' => __('messaging::audit.who'), 'wrap' => true,
            'render' => fn ($t) => ($t->isGroup() ? $t->title.' · ' : '').$t->members->map(fn ($m) => $m->user?->name)->filter()->implode(', ')],
        ['key' => 'kind', 'label' => __('messaging::audit.kind'), 'width' => '8rem',
            'render' => fn ($t) => __('messaging::audit.kind_'.$t->kind)],
        ['key' => 'last_at', 'label' => __('messaging::audit.last_at'), 'width' => '10rem',
            'render' => fn ($t) => $t->last_at?->format('d-m-Y H:i')],
        ['key' => 'reason', 'label' => __('messaging::audit.reason'),
            'render' => fn ($t) => view('messaging::audit.partials.open', ['thread' => $t])],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('messaging::audit.title') }}</x-slot:title>

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form method="GET" action="{{ route('messaging.audit.index') }}" class="contents">
            <x-ui.toolbar :title="__('messaging::audit.title')"
                          :search-placeholder="__('messaging::audit.search')"
                          :export="false" :share="false" />
        </form>

        <x-ui.table :rows="$threads" :columns="$columns" :empty="__('messaging::audit.empty')" />

        <x-ui.pager :rows="$threads" />
        <x-ui.list-totals :rows="$threads" />
    </div>
</x-layouts.app>
