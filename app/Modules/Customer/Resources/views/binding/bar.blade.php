{{--
    ⭐ কোন কর্মী কোন ডিলারের — তিন ABOS রূপে: পুরো বার এক সারিতে, দুই ট্যাব, চার কাজ পপআপে (মালিকের "ok", ২৫ নভেম্বর ২০২৬;
    নমুনা X57NWrioqvAT1qcaZG8LHd)। ⓘ বাকি রূপে আগের পাতা ([[customer::binding.index]]); ফর্মের ঠিকানা আর ঘরের নাম একই।
--}}
@php
    $date = fn ($value) => \App\Core\Support\DateFormat::format($value);
    $customerName = fn ($c) => $c === null ? '—' : $c->code.' · '.$c->nameWithPoint();
    $areaName = fn ($l) => app()->getLocale() === 'bn' && filled($l->name_bn) ? $l->name_bn : $l->name_en;
    $pop = $errors->any() ? (string) old('_pop') : '';
    $switchLine = $switchOn ? __('customer::binding.switch_on') : __('customer::binding.switch_off');
    $keep = array_filter(['user_id' => $filterUser]);

    $tabs = [
        ['key' => 'bind', 'label' => __('customer::binding.tab_bind'), 'count' => $rows->total(), 'url' => route('customer.binding.index', $keep), 'active' => $tab === 'bind'],
        ['key' => 'who', 'label' => __('customer::binding.tab_who'), 'count' => $staff->count(), 'url' => route('customer.binding.index', $keep + ['tab' => 'who']), 'active' => $tab === 'who'],
    ];

    $facetPanel = \App\Core\ListFilter\Facets::panel([
        \App\Core\ListFilter\Facets::pick('user_id', __('customer::binding.staff'),
            $staff->mapWithKeys(fn ($person) => [(string) $person->id => $person->name])->all(), __('customer::binding.everyone')),
        $tab === 'bind' ? \App\Core\ListFilter\Facets::flag('ended', __('customer::binding.with_ended')) : null,
    ]);

    $ended = fn ($row) => $row->ends_on !== null;
    $columns = $tab === 'bind' ? [
        ['key' => 'staff', 'label' => __('customer::binding.staff'), 'width' => '14rem', 'render' => fn ($row) => (string) $row->user?->name],
        ['key' => 'dealer', 'label' => __('customer::binding.dealer'), 'wrap' => true, 'render' => fn ($row) => $customerName($row->customer)],
        ['key' => 'starts', 'label' => __('customer::binding.starts'), 'width' => '9rem', 'render' => fn ($row) => $date($row->starts_on)],
        ['key' => 'ends', 'label' => __('customer::binding.ends'), 'width' => '9rem', 'render' => fn ($row) => $ended($row) ? $date($row->ends_on) : '—'],
        ['key' => 'state', 'label' => __('customer::binding.state'), 'width' => '10rem', 'render' => fn ($row) => view('customer::binding.partials.state', ['ended' => $ended($row)])],
        ['key' => 'actions', 'label' => __('core.table.action_menu'), 'width' => '7rem', 'render' => fn ($row) => view('customer::binding.partials.row-menu', ['row' => $row])],
    ] : [
        ['key' => 'staff', 'label' => __('customer::binding.staff'), 'width' => '18rem', 'render' => fn ($row) => $row['user']->name],
        ['key' => 'sees', 'label' => __('customer::binding.sees'), 'render' => fn ($row) => $row['walled'] ? __('customer::binding.sees_count', ['count' => (int) $row['dealers']]) : __('customer::binding.sees_all')],
        ['key' => 'above', 'label' => __('customer::binding.above'), 'render' => fn ($row) => $row['supervisor']?->name ?? '—'],
    ];
    $blind = fn ($row) => $row['walled'] && (int) $row['dealers'] === 0;
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('customer::binding.title') }}</x-slot:title>

    @if (session('saved'))
        <div role="status" class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm text-(--color-badge-success-ink)">{{ session('saved') }}</div>
    @endif
    @if ($errors->any() && $pop === '')
        <div role="alert" class="mb-4 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm text-(--color-badge-danger-ink)">{{ $errors->first() }}</div>
    @endif

    <x-ui.list-tabs :tabs="$tabs" :label="__('customer::binding.title')" />

    <x-ui.filter-layout :filters="$facetPanel" form="list-form">
    <div data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form id="list-form" method="GET" class="contents">
            @if ($tab === 'who')
                <input type="hidden" name="tab" value="who">
            @endif
            <x-ui.toolbar :title="__('customer::binding.title')"
                          :info="$tab === 'who' ? __('customer::binding.preview').' — '.__('customer::binding.preview_hint') : __('customer::binding.subtitle')"
                          :search-placeholder="__('customer::binding.find')" :share="false" :columns="$columns" :facets="$facetPanel" :quiet="['tab']"
                          :sort="$tab === 'bind' ? ['staff' => __('customer::binding.staff'), 'newest' => __('customer::binding.sort_newest'), 'oldest' => __('customer::binding.sort_oldest')] : ['staff' => __('customer::binding.staff')]">
                <x-slot:picker>
                    <span data-switch="{{ $switchOn ? 'on' : 'off' }}" title="{{ $switchLine }}"
                          @class(['rounded-full px-2.5 py-0.5 text-xs font-semibold',
                              'bg-(--color-badge-success-bg) text-(--color-badge-success-ink)' => $switchOn,
                              'bg-(--color-badge-warning-bg) text-(--color-badge-warning-ink)' => ! $switchOn])>{{ __('customer::binding.state_'.($switchOn ? 'on' : 'off')) }}</span>
                </x-slot:picker>
                <x-slot:actions>
                    <span x-data="{ open: false }" class="relative inline-block">
                        <button type="button" x-on:click="open = ! open" x-on:click.outside="open = false" x-bind:aria-expanded="open.toString()"
                                class="inline-flex min-h-(--spacing-touch) items-center gap-1 rounded-(--radius-field) px-2 text-sm hover:bg-(--color-surface-hover)">{{ __('customer::binding.more') }} <span aria-hidden="true">▾</span></button>
                        <span x-show="open" x-cloak class="absolute end-0 z-30 mt-1 block w-52 overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) shadow-lg">
                            <button type="button" popovertarget="pop-handover" class="block w-full px-3 py-2 text-start text-sm hover:bg-(--color-surface-hover)">{{ __('customer::binding.handover') }}</button>
                            <button type="button" popovertarget="pop-supervisor" class="block w-full px-3 py-2 text-start text-sm hover:bg-(--color-surface-hover)">{{ __('customer::binding.supervisor') }}</button>
                            <a href="{{ route('customer.binding.tree') }}" class="block px-3 py-2 text-sm hover:bg-(--color-surface-hover)">{{ __('customer::binding.tree_link') }}</a>
                        </span>
                    </span>
                    <x-ui.button type="button" tone="primary" icon="plus" popovertarget="pop-bind">{{ __('customer::binding.bind') }}</x-ui.button>
                </x-slot:actions>
            </x-ui.toolbar>
        </form>

        @if ($tab === 'bind')
            <x-ui.table :rows="$rows" :columns="$columns" :compact="\App\Core\Support\Ui::compact()" :empty="__('customer::binding.none')" :row-attributes="fn ($row) => ['data-binding' => $row->id]" />
            <x-ui.pager :rows="$rows" />
        @else
            <div data-preview>
                <x-ui.table :rows="$preview" :columns="$columns" :compact="\App\Core\Support\Ui::compact()" :empty="request()->except(['page', 'per_page']) !== [] ? __('core.empty.no_results') : __('core.empty.nothing_here')"
                            :row-attributes="fn ($row) => ['data-staff' => $row['user']->id, 'data-blind' => $blind($row) ? '1' : '0', 'class' => $blind($row) ? 'bg-(--color-badge-danger-bg) text-(--color-badge-danger-ink)' : '']" />
            </div>
        @endif
        <x-ui.list-totals :rows="$tab === 'bind' ? $rows : $preview" />
    </div>
    </x-ui.filter-layout>

    @include('customer::binding.partials.pop', ['id' => 'pop-bind', 'title' => __('customer::binding.bind'), 'action' => route('customer.binding.store'), 'button' => __('customer::binding.bind_button'), 'open' => $pop === 'pop-bind', 'body' => 'customer::binding.partials.form-bind'])
    @include('customer::binding.partials.pop', ['id' => 'pop-handover', 'title' => __('customer::binding.handover'), 'action' => route('customer.binding.handover'), 'button' => __('customer::binding.handover_button'), 'open' => $pop === 'pop-handover', 'body' => 'customer::binding.partials.form-handover'])
    @include('customer::binding.partials.pop', ['id' => 'pop-supervisor', 'title' => __('customer::binding.supervisor'), 'action' => route('customer.binding.supervisor'), 'button' => __('core.action.save'), 'open' => $pop === 'pop-supervisor', 'body' => 'customer::binding.partials.form-supervisor'])
    @if ($tab === 'bind')
        @foreach ($rows as $row)
            @if ($row->ends_on === null)
                @include('customer::binding.partials.pop', ['id' => 'pop-end-'.$row->id, 'title' => __('customer::binding.end_button').' — '.$customerName($row->customer), 'action' => route('customer.binding.end', $row), 'button' => __('customer::binding.end_button'), 'open' => $pop === 'pop-end-'.$row->id, 'body' => 'customer::binding.partials.form-end'])
            @endif
        @endforeach
    @endif
</x-layouts.app>
