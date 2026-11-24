{{--
    ⭐ কোম্পানির মডিউল আর শাখার মডিউল — এক পর্দা, দুই ডাক (মালিক, ২৪ নভেম্বর ২০২৬, নমুনা system-plan-v4)।
    ⓘ বাঁয়ে তালিকা (সার্ভারে খোঁজা, ছাঁকা, সাজানো, পাতা ধরে), ডানে বাছাগুলোর মডিউল; সংরক্ষণের আগে যা বদলাবে তার তালিকা।
    ⓘ জাভাস্ক্রিপ্ট ছাড়াই চলে — বাছাই ঠিকানায় (`pick[]`), তাই বুকমার্কও হয়।
--}}
@php
    use Illuminate\Support\HtmlString;

    $isBranch = $kind === 'branch';
    $here = $isBranch ? 'system_admin.module.branches' : 'system_admin.module.companies';
    $title = __('system_admin::module_board.'.($isBranch ? 'branches' : 'companies'));
    $keep = $isBranch ? ['company' => $company->id] : [];
    $filters = array_filter(request()->only(['q', 'state', 'sort']), fn ($v) => filled($v));
    $pageIds = collect($rows->items())->pluck('id')->map(fn ($id) => (int) $id)->all();

    $columns = array_values(array_filter([
        ['key' => 'pick', 'label' => __('system_admin::module_board.pick'), 'width' => '3rem',
            'render' => fn ($r) => new HtmlString('<input type="checkbox" name="pick[]" value="'.(int) $r->id.'" form="list-form" class="size-4" data-pick'
                .(in_array((int) $r->id, $ids, true) ? ' checked' : '').' aria-label="'.e($r->name()).'">')],
        ['key' => 'name', 'label' => __('core.table.name'),
            'render' => fn ($r) => new HtmlString('<a href="'.e(route($here, [...$keep, ...$filters, 'pick' => [(int) $r->id]])).'" class="'
                .(in_array((int) $r->id, $ids, true) ? 'font-semibold ' : '').'text-(--color-link) hover:underline">'.e($r->name()).'</a>')],
        $isBranch ? ['key' => 'package', 'label' => __('system_admin::module_board.package'),
            'render' => fn ($r) => isset($packages[(int) $r->id])
                ? ($packages[(int) $r->id]['custom'] ? __('system_admin::module_board.package_custom', ['name' => $packages[(int) $r->id]['name']]) : $packages[(int) $r->id]['name']) : '—'] : null,
        ['key' => 'off', 'label' => __('system_admin::module_board.off'), 'numeric' => true, 'width' => '4rem',
            'render' => fn ($r) => (string) count($off[(int) $r->id] ?? [])],
    ]));
    $who = count($ids) === 1 ? ($names[$ids[0]] ?? '') : __('system_admin::module_board.picked_many', ['n' => count($ids)]);
    $wasState = collect($was)->flatMap(fn ($g) => $g['rows'])->pluck('state', 'code');
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $title }}</x-slot:title>

    @if (session('saved'))
        <div role="status" class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm text-(--color-badge-success-ink)">{{ session('saved') }}</div>
    @endif
    <x-ui.errors />

    @if ($copied)
        <p role="status" class="mb-4 rounded-(--radius-field) bg-(--color-badge-pending-bg) px-3 py-2 text-sm text-(--color-badge-pending-ink)">{{ __('system_admin::module_board.copied', ['name' => $copied]) }}</p>
    @endif

    <x-ui.filter-layout :filters="$facets" form="list-form" :report="true">
        <div data-boxed class="mb-4 overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
            <form id="list-form" method="GET" action="{{ route($here) }}" class="contents" data-report>
                @if ($isBranch)
                    <input type="hidden" name="company" value="{{ $company->id }}">
                @endif
                @foreach (array_diff($ids, $pageIds) as $elsewhere)
                    <input type="hidden" name="pick[]" value="{{ $elsewhere }}">
                @endforeach
                <x-ui.toolbar :title="$title" :count="trans_choice('core.count.records', $rows->total(), ['count' => $rows->total()])"
                              :columns="$columns" :facets="$facets" :report="true" :quiet="['pick', 'company', 'copy_from']"
                              :sort="['name' => __('core.table.name'), 'off' => __('system_admin::module_board.off')]"
                              :search-placeholder="$isBranch ? __('core.company.branch') : __('core.table.name')">
                    <x-slot:actions>
                        <x-ui.button type="submit" form="board-form" tone="primary">{{ __('core.action.save') }}</x-ui.button>
                    </x-slot:actions>
                </x-ui.toolbar>
            </form>
            @if ($isBranch && count($companies) > 1)
                <nav class="flex flex-wrap gap-2 border-t border-(--color-border) px-3 py-2 text-sm" data-company-picker aria-label="{{ __('system_admin::module_board.companies') }}">
                    @foreach ($companies as $one)
                        <a href="{{ route($here, ['company' => $one->id]) }}" @if ($one->id === $company->id) aria-current="page" @endif
                           class="{{ $one->id === $company->id ? 'font-semibold text-(--color-ink)' : 'text-(--color-link) hover:underline' }}">{{ $one->name() }}</a>
                    @endforeach
                </nav>
            @endif
        </div>

        <div class="grid items-start gap-4 xl:grid-cols-3">
            <div data-boxed class="min-w-0 overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
                <div class="flex flex-wrap items-center gap-3 border-b border-(--color-border) px-3 py-2 text-sm">
                    <button type="submit" form="list-form" class="font-semibold text-(--color-link) hover:underline">{{ __('system_admin::module_board.show_picked') }}</button>
                    <a href="{{ route($here, [...$keep, ...$filters, 'pick' => 'all']) }}" data-pick-all class="text-(--color-link) hover:underline">{{ __('system_admin::module_board.pick_all') }}</a>
                    @if ($ids !== [])
                        <a href="{{ route($here, [...$keep, ...$filters, 'pick' => [$ids[0]]]) }}" data-pick-one class="text-(--color-link) hover:underline">{{ __('system_admin::module_board.pick_one') }}</a>
                    @endif
                </div>
                <x-ui.table :rows="$rows" :columns="$columns" :compact="true" :empty="__('core.empty.no_results')" />
                <x-ui.pager :rows="$rows" />
                <x-ui.list-totals :rows="$rows" />
            </div>

            <div class="min-w-0 xl:col-span-2">
                @if ($ids === [])
                    <x-ui.empty-state :message="__('system_admin::branch_module.no_branches')" />
                @else
                    <div class="mb-3 flex flex-wrap items-center gap-3 px-3 py-2 text-sm rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)" data-who>
                        <b>{{ $who }}</b>
                        <span class="flex-1"></span>
                        <form method="GET" action="{{ route($here) }}" class="flex items-center gap-2" data-copy-form>
                            @foreach ([...$keep, ...$filters] as $k => $v)
                                <input type="hidden" name="{{ $k }}" value="{{ is_scalar($v) ? $v : '' }}">
                            @endforeach
                            @foreach ($ids as $id)
                                <input type="hidden" name="pick[]" value="{{ $id }}">
                            @endforeach
                            <select name="copy_from" aria-label="{{ __('system_admin::module_board.copy_from') }}"
                                    class="h-(--spacing-field-compact) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
                                @foreach ($copyable as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </select>
                            <x-ui.button type="submit" tone="secondary">{{ __('system_admin::module_board.copy_from') }}</x-ui.button>
                        </form>
                        @if ($isBranch)
                            <form method="POST" action="{{ route('system_admin.module.branches.preview') }}" data-reset-form>
                                @csrf
                                <input type="hidden" name="company" value="{{ $company->id }}">
                                <input type="hidden" name="reset" value="1">
                                @foreach ($ids as $id)
                                    <input type="hidden" name="pick[]" value="{{ $id }}">
                                @endforeach
                                <x-ui.button type="submit" tone="secondary">{{ __('system_admin::module_board.reset') }}</x-ui.button>
                            </form>
                        @endif
                    </div>

                    <form id="board-form" method="POST" action="{{ route($here.'.preview') }}" class="space-y-4">
                        @csrf
                        @if ($isBranch)
                            <input type="hidden" name="company" value="{{ $company->id }}">
                        @endif
                        @foreach ($ids as $id)
                            <input type="hidden" name="pick[]" value="{{ $id }}">
                        @endforeach

                        @foreach ($groups as $group)
                            <section data-boxed class= + CARD + r data-module-group="{{ $group['key'] }}">
                                <h2 class="border-b border-(--color-border) px-4 py-2 text-sm font-semibold">{{ $group['label'] }}</h2>
                                <div class="grid gap-x-4 px-4 py-2 sm:grid-cols-2">
                                    @foreach ($group['rows'] as $row)
                                        @include('system_admin::module-board.partials.row', ['row' => $row, 'was' => $wasState[$row['code']] ?? $row['state'], 'copied' => $copied])
                                    @endforeach
                                </div>
                            </section>
                        @endforeach
                    </form>

                    <details data-module-history class="mt-4 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
                        <summary class="cursor-pointer px-4 py-3 text-sm font-semibold">{{ __('system_admin::module_board.history') }}</summary>
                        <ul class="divide-y divide-(--color-border) border-t border-(--color-border)">
                            @forelse ($history as $h)
                                <li class="flex flex-wrap gap-3 px-4 py-2 text-sm">
                                    <span class="tabular text-(--color-ink-muted)">{{ $h['when'] ? \App\Core\Support\DateFormat::spoken($h['when']) : '—' }}</span>
                                    <span>{{ $h['who'] ?? '—' }}</span>
                                    <span>{{ $h['where'] }} · {{ $h['module'] }}</span>
                                    <b>{{ __('system_admin::module_board.'.($h['on'] ? 'on' : 'off')) }}</b>
                                </li>
                            @empty
                                <li class="px-4 py-2 text-sm text-(--color-ink-muted)">{{ __('system_admin::module_board.no_history') }}</li>
                            @endforelse
                        </ul>
                    </details>
                @endif
            </div>
        </div>
    </x-ui.filter-layout>
</x-layouts.app>
