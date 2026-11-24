{{-- ⭐ মডিউলের পর্দা — বাঁয়ে মডিউল, ডানে তার পর্দার সুইচ; লেখে কন্ট্রোল প্যানেলের একই দরজায় ([[ModuleScreenController]]) --}}
@php
    use Illuminate\Support\HtmlString;
    $title = __('system_admin::module_board.screens');
    $keep = array_filter(request()->only(['q', 'state', 'sort']), fn ($v) => filled($v));
    $columns = [
        ['key' => 'label', 'label' => __('system_admin::module_board.module'),
            'render' => fn ($m) => new HtmlString('<a href="'.e(route('system_admin.module.screens', [...$keep, 'module' => $m['code']])).'" class="'
                .(($open['code'] ?? null) === $m['code'] ? 'font-semibold ' : '').'text-(--color-link) hover:underline">'.e($m['label']).'</a>')],
        ['key' => 'count', 'label' => __('system_admin::module_board.why_screen'), 'numeric' => true, 'render' => fn ($m) => (string) $m['count']],
        ['key' => 'off', 'label' => __('system_admin::module_board.off'), 'numeric' => true, 'render' => fn ($m) => (string) $m['off']],
    ];
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $title }}</x-slot:title>

    @if (session('saved'))
        <div role="status" class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm text-(--color-badge-success-ink)">{{ session('saved') }}</div>
    @endif
    <x-ui.errors />

    <x-ui.filter-layout :filters="$facets" form="list-form">
        <div data-boxed class="mb-4 overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
            <form id="list-form" method="GET" class="contents" data-no-swap>
                <x-ui.toolbar :title="$title" :count="trans_choice('core.count.records', $rows->total(), ['count' => $rows->total()])"
                              :columns="$columns" :facets="$facets" :quiet="['module', 'company']"
                              :sort="['label' => __('core.table.name'), 'count' => __('system_admin::module_board.why_screen'), 'off' => __('system_admin::module_board.off')]"
                              :search-placeholder="__('system_admin::module_board.module')">
                    <x-slot:picker>
                        @include('system_admin::module-board.partials.company', ['companies' => [$company], 'chosen' => $company->id])
                    </x-slot:picker>
                    <x-slot:actions>
                        <x-ui.button type="submit" form="board-form" tone="primary">{{ __('core.action.save') }}</x-ui.button>
                    </x-slot:actions>
                </x-ui.toolbar>
            </form>
        </div>
        <div class="grid items-start gap-4 xl:grid-cols-3">
            <div data-boxed class="min-w-0 overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
                <x-ui.table :rows="$rows" :columns="$columns" :compact="true" :empty="__('core.empty.no_results')" />
                <x-ui.pager :rows="$rows" />
                <x-ui.list-totals :rows="$rows" />
            </div>
            <div class="min-w-0 xl:col-span-2">
                @if ($open === null)
                    <x-ui.empty-state :message="__('core.empty.no_results')" />
                @else
                    <form id="board-form" method="POST" action="{{ route('system_admin.control-panel.update') }}" class="space-y-4">
                        @csrf
                        @method('PUT')
                        @foreach ($open['groups'] as $group)
                            <section data-boxed class= + CARD + r>
                                <div class="flex items-center gap-3 border-b border-(--color-border) px-4 py-2">
                                    <h2 class="flex-1 text-sm font-semibold">{{ __('core.menu.'.$group['name']) }}</h2>
                                    <input type="checkbox" role="switch" name="settings[{{ $group['key'] }}]" value="1" class="size-4" aria-label="{{ __('core.menu.'.$group['name']) }}"
                                           @checked($settings->get($group['key'], true))>
                                    <input type="hidden" name="scope[]" value="{{ $group['key'] }}">
                                </div>
                                <div class="grid gap-x-4 px-4 py-2 sm:grid-cols-2">
                                    @foreach ($group['items'] as $item)
                                        <div class="flex items-center gap-3 py-2 text-sm" data-screen="{{ $item['key'] }}">
                                            <span class="min-w-0 flex-1">{{ __($item['label']) }}
                                                @unless ($mine($item['key']))
                                                    <a href="{{ route('system_admin.control-panel', ['tab' => $open['code']]) }}" class="block text-2xs text-(--color-link) hover:underline">{{ __('system_admin::module_board.control_panel') }}</a>
                                                @endunless
                                            </span>
                                            <input type="checkbox" role="switch" name="settings[{{ $item['key'] }}]" value="1" class="size-4" aria-label="{{ __($item['label']) }}"
                                                   @checked($settings->get($item['key'], true)) @disabled(! $mine($item['key']))>
                                            @if ($mine($item['key']))
                                                <input type="hidden" name="scope[]" value="{{ $item['key'] }}">
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            </section>
                        @endforeach
                    </form>
                @endif
            </div>
        </div>
    </x-ui.filter-layout>
</x-layouts.app>
