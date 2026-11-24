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
    // ⭐ এক সারির মাথা কেবল তিন ABOS রূপে ([[AbosHead]]); বাকি রূপ আগের মতো
    $abosHead = \App\Core\Support\AbosHead::on();
    $facetPanel = ! $abosHead ? null : \App\Core\ListFilter\Facets::panel([
        \App\Core\ListFilter\Facets::pick('kind', __('messaging::audit.kind'), ['direct' => __('messaging::audit.kind_direct'), 'group' => __('messaging::audit.kind_group')]),
    ]);
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('messaging::audit.title') }}</x-slot:title>

    <x-ui.filter-layout :filters="$facetPanel ?? \App\Core\ListFilter\Facets::panel([])" form="list-form">
    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form id="list-form" method="GET" action="{{ route('messaging.audit.index') }}" class="contents">
            <x-ui.toolbar :title="__('messaging::audit.title')"
                          :search-placeholder="__('messaging::audit.search')" :columns="$abosHead ? $columns : []" :facets="$facetPanel"
                          :sort="$abosHead ? ['newest' => __('core.notify.sort.newest'), 'oldest' => __('core.notify.sort.oldest')] : []"
                          :export="false" :share="false" />
        </form>

        <x-ui.table :rows="$threads" :columns="$columns" :empty="__('messaging::audit.empty')" />

        <x-ui.pager :rows="$threads" />
        <x-ui.list-totals :rows="$threads" />
    </div>
    </x-ui.filter-layout>
</x-layouts.app>
