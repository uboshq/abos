{{-- গুদামের তালিকা। --}}
@php
    $columns = [
        ['key' => 'code', 'label' => __('inventory::field.code'), 'width' => '9rem'],
        ['key' => 'name_en', 'label' => __('inventory::field.name'), 'width' => '18rem',
         'render' => fn ($w) => $w->name()],
        ['key' => 'branch_id', 'label' => __('inventory::field.branch'),
         'render' => fn ($w) => $w->branch?->name()],
        ['key' => 'is_default', 'label' => __('inventory::field.is_default'), 'width' => '9rem',
         'render' => fn ($w) => $w->is_default ? __('core.yes') : ''],
        ['key' => 'is_active', 'label' => __('inventory::field.state'), 'width' => '7rem',
         'render' => fn ($w) => view('inventory::partials.state-badge', ['record' => $w])],
        ['key' => 'actions', 'label' => __('core.action.edit'), 'width' => '6rem',
         'render' => fn ($w) => view('inventory::partials.warehouse-actions', ['warehouse' => $w])],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('inventory::menu.warehouses') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="__('inventory::menu.warehouses')"
            :subtitle="trans_choice('inventory::message.warehouse_count', $warehouses->total(), ['count' => $warehouses->total()])">
            <x-slot:actions>
                @can('create', \App\Modules\Inventory\Models\Warehouse::class)
                    <x-ui.button tone="primary" icon="plus" :href="route('inventory.warehouse.create')">
                        {{ __('inventory::action.new_warehouse') }}
                    </x-ui.button>
                @endcan
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    <div class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form method="GET" class="contents">
            <x-ui.toolbar
                :columns="$columns" :search-placeholder="__('inventory::message.warehouse_search')"
                          :sort="$sortOptions">
                <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm">
                    <input type="checkbox" name="inactive" value="1" @checked($showInactive) class="size-4">
                    {{ __('inventory::action.show_inactive') }}
                </label>
            </x-ui.toolbar>
        </form>

        <x-ui.table
            :empty="$q ? __('core.empty.no_results') : __('inventory::message.no_warehouses')"
            :rows="$warehouses"
            :compact="request()->boolean('compact')"
            :columns="$columns" />

        @if ($warehouses->hasPages())
            <div class="border-t border-(--color-border) px-3 py-2">{{ $warehouses->links() }}</div>
        @endif
    </div>
</x-layouts.app>
