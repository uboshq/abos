{{-- গুদামের তালিকা। --}}
@php
    $columns = [
        ['key' => 'code', 'label' => __('inventory::field.code'), 'width' => '9rem'],
        /* নামটা গুদামের নিজের পাতায় নিয়ে যায় — ২০ সেপ্টেম্বর ২০২৬ */
        ['key' => 'name_en', 'label' => __('inventory::field.name'), 'width' => '18rem',
         'render' => fn ($w) => view('inventory::warehouse.partials.name-link', ['warehouse' => $w])],
        ['key' => 'branch_id', 'label' => __('inventory::field.branch'),
         'render' => fn ($w) => $w->branch?->name()],
        ['key' => 'is_default', 'label' => __('inventory::field.is_default'), 'width' => '9rem',
         'render' => fn ($w) => $w->is_default ? __('core.yes') : ''],
        ['key' => 'is_active', 'label' => __('inventory::field.state'), 'width' => '7rem',
         'render' => fn ($w) => view('inventory::partials.state-badge', ['record' => $w])],
        ['key' => 'actions', 'label' => __('core.table.action_menu'), 'width' => '8rem',
         'render' => fn ($w) => view('inventory::partials.warehouse-actions', ['warehouse' => $w])],
    ];

    // ⭐ ছাঁকনি ডান পাশের প্যানেলে — আগের ঘরগুলো, একই চাবি (মালিক, ২৩ নভেম্বর ২০২৬; [[Facets]])
    $facetPanel = \App\Core\ListFilter\Facets::panel([
        \App\Core\ListFilter\Facets::flag('inactive', __('inventory::action.show_inactive')),
    ]);
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('inventory::menu.warehouses') }}</x-slot:title>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    <x-ui.filter-layout :filters="$facetPanel" form="list-form">
    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form id="list-form" method="GET" class="contents">
            <x-ui.toolbar :title="__('inventory::menu.warehouses')" :count="trans_choice('inventory::message.warehouse_count', $warehouses->total(), ['count' => $warehouses->total()])"
                :columns="$columns" :search-placeholder="__('inventory::message.warehouse_search')"
                          :sort="$sortOptions" :facets="$facetPanel">
        <x-slot:actions>
            @can('create', \App\Modules\Inventory\Models\Warehouse::class)
                    <x-ui.button tone="primary" icon="plus" :href="route('inventory.warehouse.create')">
                        {{ __('inventory::action.new_warehouse') }}
                    </x-ui.button>
                @endcan
        </x-slot:actions>
            </x-ui.toolbar>
        </form>

        <x-ui.table
            :empty="$q ? __('core.empty.no_results') : __('inventory::message.no_warehouses')"
            :rows="$warehouses"
            :compact="\App\Core\Support\Ui::compact()"
            :columns="$columns" />

        <x-ui.pager :rows="$warehouses" />
        <x-ui.list-totals :rows="$warehouses" />
    </div>
    </x-ui.filter-layout>
</x-layouts.app>
