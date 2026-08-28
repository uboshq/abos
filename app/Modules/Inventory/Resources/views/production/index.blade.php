@php
    $columns = [
        ['key' => 'document_no', 'label' => __('inventory::field.document_no'), 'width' => '10rem',
         'render' => fn ($p) => view('inventory::partials.production-no', ['production' => $p])],

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
         'render' => fn ($p) => view('inventory::partials.production-state', ['production' => $p])],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('inventory::menu.production') }}</x-slot:title>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    <div data-boxed
         class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form method="GET" class="contents">
            <x-ui.toolbar :title="__('inventory::menu.production')"
                          :count="trans_choice('inventory::message.production_count', $productions->total(), ['count' => $productions->total()])"
                          :columns="$columns"
                          :search-placeholder="__('inventory::field.production_search')"
                          :sort="$sortOptions">
                <x-slot:actions>
                    @can('create', \App\Modules\Inventory\Models\Production::class)
                        <x-ui.button tone="primary" icon="plus" :href="route('inventory.production.create')">
                            {{ __('inventory::action.new_production') }}
                        </x-ui.button>
                    @endcan
                </x-slot:actions>

                <x-ui.date-range :dates="$dates" />
            </x-ui.toolbar>
        </form>

        <x-ui.table
            :empty="$q ? __('core.empty.no_results') : __('inventory::message.no_productions')"
            :rows="$productions"
            :compact="request()->boolean('compact')"
            :columns="$columns" />

        <x-ui.pager :rows="$productions" />
    </div>
</x-layouts.app>
