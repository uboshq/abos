{{-- ⭐ অ্যাপের মডিউল — কোন মডিউল ফোনে; লেখে কন্ট্রোল প্যানেলের একই দরজায় ([[ModuleScreenController]]) --}}
@php
    use Illuminate\Support\HtmlString;
    $title = __('system_admin::module_board.app');
    $columns = [
        ['key' => 'label', 'label' => __('system_admin::module_board.module'), 'render' => fn ($m) => $m['label']],
        ['key' => 'group', 'label' => __('core.table.type'), 'render' => fn ($m) => $m['group']],
        ['key' => 'on', 'label' => __('core.table.status'), 'render' => fn ($m) => __('system_admin::module_board.'.($m['on'] ? 'on' : 'off'))],
        ['key' => 'pick', 'label' => __('system_admin::module_board.on'), 'width' => '5rem',
            'render' => fn ($m) => new HtmlString('<input type="hidden" form="board-form" name="scope[]" value="'.e($m['key']).'">'
                .view('system_admin::partials.switch', ['name' => 'settings['.$m['key'].']', 'form' => 'board-form', 'label' => $m['label'], 'checked' => $m['on']])->render())],
    ];
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $title }}</x-slot:title>

    @if (session('saved'))
        <div role="status" class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm text-(--color-badge-success-ink)">{{ session('saved') }}</div>
    @endif
    <x-ui.errors />

    <form id="board-form" method="POST" action="{{ route('system_admin.control-panel.update') }}">
        @csrf
        @method('PUT')
    </form>
    <x-ui.filter-layout :filters="$facets" form="list-form">
        <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
            <form id="list-form" method="GET" class="contents" data-no-swap>
                <x-ui.toolbar :title="$title" :count="trans_choice('core.count.records', $rows->total(), ['count' => $rows->total()])"
                              :columns="$columns" :facets="$facets" :quiet="['company']"
                              :sort="['label' => __('core.table.name'), 'on' => __('core.table.status')]"
                              :search-placeholder="__('system_admin::module_board.module')">
                    <x-slot:picker>
                        @include('system_admin::module-board.partials.company', ['companies' => [$company], 'chosen' => $company->id])
                    </x-slot:picker>
                    <x-slot:actions>
                        <x-ui.button type="submit" form="board-form" tone="primary">{{ __('core.action.save') }}</x-ui.button>
                    </x-slot:actions>
                </x-ui.toolbar>
            </form>
            <x-ui.table :rows="$rows" :columns="$columns" :empty="__('core.empty.no_results')" />
            <x-ui.pager :rows="$rows" />
            <x-ui.list-totals :rows="$rows" />
        </div>
    </x-ui.filter-layout>
</x-layouts.app>
