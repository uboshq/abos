{{-- ⭐ মডিউল প্যাকেজের তালিকা ([[ModulePackageController]]) --}}
@php
    $title = __('system_admin::module_board.packages');
    $columns = [
        ['key' => 'name', 'label' => __('system_admin::module_board.package'),
            'render' => fn ($p) => new \Illuminate\Support\HtmlString('<a href="'.e(route('system_admin.module.packages.edit', $p)).'" class="font-semibold text-(--color-link) hover:underline">'.e($p->name()).'</a>')],
        ['key' => 'modules', 'label' => __('system_admin::module_board.module'), 'wrap' => true,
            'render' => fn ($p) => implode(' · ', array_map($board->label(...), (array) $p->modules))],
        ['key' => 'branches_count', 'label' => __('system_admin::module_board.package_branches'), 'numeric' => true],
        ['key' => 'custom_count', 'label' => __('system_admin::module_board.package_custom_count'), 'numeric' => true],
    ];
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $title }}</x-slot:title>

    @if (session('saved'))
        <div role="status" class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm text-(--color-badge-success-ink)">{{ session('saved') }}</div>
    @endif
    <x-ui.errors />

    <x-ui.filter-layout :filters="$facets" form="list-form">
        <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
            <form id="list-form" method="GET" class="contents">
                <x-ui.toolbar :title="$title" :count="trans_choice('core.count.records', $rows->total(), ['count' => $rows->total()])"
                              :columns="$columns" :facets="$facets" :search-placeholder="__('system_admin::module_board.package')"
                              :sort="['name' => __('core.table.name'), 'branches' => __('system_admin::module_board.package_branches')]">
                    <x-slot:actions>
                        <x-ui.button tone="primary" icon="plus" :href="route('system_admin.module.packages.create')">{{ __('system_admin::module_board.package_new') }}</x-ui.button>
                    </x-slot:actions>
                </x-ui.toolbar>
            </form>
            <x-ui.table :rows="$rows" :columns="$columns" :empty="filled(request('q')) || filled(request('state')) ? __('core.empty.no_results') : __('system_admin::module_board.no_packages')" />
            <x-ui.pager :rows="$rows" />
            <x-ui.list-totals :rows="$rows" />
        </div>
    </x-ui.filter-layout>
</x-layouts.app>
