@php
    $columns = [
        ['key' => 'document_no', 'label' => __('inventory::field.document_no'), 'width' => '10rem',
         'render' => fn ($p) => view('restaurant::partials.production-no', ['production' => $p])],

        ['key' => 'trx_date', 'label' => __('inventory::field.date'), 'width' => '9rem',
         'render' => fn ($p) => \App\Core\Support\DateFormat::format($p->trx_date)],

        ['key' => 'product_id', 'label' => __('inventory::field.dish'), 'width' => '16rem',
         'render' => fn ($p) => $p->product?->name()],

        ['key' => 'qty', 'label' => __('inventory::field.made'), 'width' => '7rem', 'align' => 'end',
         'render' => fn ($p) => rtrim(rtrim((string) $p->qty, '0'), '.')],

        /* এক প্লেটে কত টাকার মাল — খাদ্য-খরচের মূল সংখ্যা। */
        ['key' => 'cost_total', 'label' => __('inventory::field.cost_per_unit'), 'width' => '9rem',
         'align' => 'end',
         'render' => fn ($p) => $p->status === \App\Core\Support\DocumentStatus::CONFIRMED
             ? \App\Core\Support\Money::format($p->unitCost())
             : ''],

        ['key' => 'status', 'label' => __('inventory::field.state'), 'width' => '8rem',
         'render' => fn ($p) => view('restaurant::partials.production-state', ['production' => $p])],
    ];

    // ⭐ ছাঁকনি ডান পাশের প্যানেলে — আগের তারিখের ঘর, একই চাবি (মালিক, ২৩ নভেম্বর ২০২৬; [[Facets]])
    $facetPanel = \App\Core\ListFilter\Facets::panel([
        \App\Core\ListFilter\Facets::dates(__('core.list_filter.date')),
    ]);
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('restaurant::menu.cooking') }}</x-slot:title>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    <x-ui.filter-layout :filters="$facetPanel" form="list-form">
    <div data-boxed
         class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form id="list-form" method="GET" class="contents">
            <x-ui.toolbar :title="__('restaurant::menu.cooking')"
                          :count="trans_choice('inventory::message.production_count', $productions->total(), ['count' => $productions->total()])"
                          :columns="$columns"
                          :search-placeholder="__('inventory::field.production_search')"
                          :sort="$sortOptions" :facets="$facetPanel">
                <x-slot:actions>
                    @can('create', \App\Modules\Restaurant\Models\Production::class)
                        <x-ui.button tone="primary" icon="plus" :href="route('restaurant.production.create')">
                            {{ __('inventory::action.new_production') }}
                        </x-ui.button>
                    @endcan
                </x-slot:actions>
            </x-ui.toolbar>
        </form>

        <x-ui.table
            :empty="$q ? __('core.empty.no_results') : __('inventory::message.no_productions')"
            :rows="$productions"
            :compact="\App\Core\Support\Ui::compact()"
            :columns="$columns" />

        <x-ui.pager :rows="$productions" />
        <x-ui.list-totals :rows="$productions" />
    </div>
    </x-ui.filter-layout>
</x-layouts.app>
