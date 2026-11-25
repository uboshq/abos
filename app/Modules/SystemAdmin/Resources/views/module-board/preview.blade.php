{{-- ⭐ লেখার আগে যা বদলাবে — প্রতিটা জায়গা, মডিউল আর কোন দিকে; নিচে কী আটকাবে (সতর্কতা, শিকল নয়) --}}
@php
    $title = __('system_admin::module_board.preview');
    $columns = [
        ['key' => 'name', 'label' => __('system_admin::module_board.where'), 'render' => fn ($c) => $c['name']],
        ['key' => 'module', 'label' => __('system_admin::module_board.module'), 'render' => fn ($c) => $board->label($c['module'])],
        ['key' => 'change', 'label' => __('system_admin::module_board.change'), 'render' => fn ($c) => __('system_admin::module_board.'.($c['on'] ? 'change_on' : 'change_off'))],
    ];
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $title }}</x-slot:title>
    <x-slot:header>
        <x-ui.page-header :title="$title" :subtitle="__('system_admin::module_board.changes_n', ['n' => count($plan['changes'])])" />
    </x-slot:header>

    @if ($stale)
        <p role="alert" class="mb-4 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm text-(--color-badge-danger-ink)">{{ __('system_admin::module_board.stale') }}</p>
    @endif
    @foreach ($plan['warnings'] as $w)
        <p role="alert" data-module-warning="{{ $w['module'] }}" class="mb-3 rounded-(--radius-field) bg-(--color-badge-pending-bg) px-3 py-2 text-sm text-(--color-badge-pending-ink)">{{ __('system_admin::module_board.warning', ['module' => $board->label($w['module']), 'n' => $w['count'], 'needs' => implode(', ', array_map($board->label(...), $w['needs']))]) }}</p>
    @endforeach
    @if ($plan['blocked'] !== [])
        <p role="status" class="mb-3 rounded-(--radius-field) bg-(--color-badge-draft-bg) px-3 py-2 text-sm text-(--color-badge-draft-ink)">{{ __('system_admin::module_board.blocked', ['names' => implode(', ', array_map($board->label(...), $plan['blocked']))]) }}</p>
    @endif

    <form method="POST" action="{{ $apply }}" data-module-confirm>
        @csrf
        <input type="hidden" name="stamp" value="{{ $stamp }}">
        @foreach ($payload as $key => $value)
            @if (is_array($value))
                @foreach ($value as $k => $v)
                    @if (is_scalar($v))
                        <input type="hidden" name="{{ $key }}[{{ $k }}]" value="{{ $v }}">
                    @endif
                @endforeach
            @elseif (is_scalar($value))
                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
            @endif
        @endforeach
        <div data-boxed class="mb-4 overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
            <x-ui.table :rows="$plan['changes']" :columns="$columns" :compact="true" :empty="request()->except(['page', 'per_page']) !== [] ? __('core.empty.no_results') : __('core.empty.nothing_here')" />
        </div>
        <div class="flex flex-wrap gap-3">
            <x-ui.button type="submit" tone="primary">{{ __('system_admin::module_board.confirm') }}</x-ui.button>
            <x-ui.button :href="$back" tone="secondary">{{ __('core.action.cancel') }}</x-ui.button>
        </div>
    </form>
</x-layouts.app>
