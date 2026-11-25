{{--
    ⭐ কন্ট্রোল প্যানেল — বিষয় ধরে (মালিক, ২৪ নভেম্বর ২০২৬, নমুনা system-plan-v4 ট্যাব ৩; [[ControlPanelController::subjects()]])।
    ⓘ বাঁয়ে বিষয় আর গোনা, ডানে মডিউল ধরে বাক্স; উপরে পুরো তালিকার বার। ফর্মে কেবল আঁকা ঘরগুলো — ছাঁকা পাতা সংরক্ষণ করলে বাকি সেটিং নড়ে না।
--}}
@php
    $title = __('system_admin::menu.control_panel');
    $columns = [
        ['key' => 'setting', 'label' => __('system_admin::control.col_setting'), 'wrap' => true,
            'render' => fn ($r) => view('system_admin::control-panel.partials.setting-name', ['setting' => $r])],
        ['key' => 'subject', 'label' => __('system_admin::control.col_subject'), 'width' => '10rem',
            'render' => fn ($r) => __('system_admin::control.subject.'.$r['subject'])],
        ['key' => 'value', 'label' => __('system_admin::control.col_value'), 'width' => '15rem',
            'render' => fn ($r) => view('system_admin::control-panel.partials.control', ['setting' => $r, 'settings' => $settings])],
        ['key' => 'changed', 'label' => __('system_admin::control.col_changed'), 'width' => '9rem',
            'render' => fn ($r) => $r['changed'] !== null ? \App\Core\Support\DateFormat::spoken($r['changed']) : '—'],
    ];
    // ⓘ এক বিষয়ের পাতায় বিষয়ের কলাম একই কথা বারবার বলত — কেবল সব বিষয় জুড়ে খোঁজায়
    if (! $everywhere) {
        $columns = array_values(array_filter($columns, fn ($c) => $c['key'] !== 'subject'));
    }
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $title }}</x-slot:title>

    @if (session('saved'))
        <div role="status" class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm text-(--color-badge-success-ink)">{{ session('saved') }}</div>
    @endif
    <x-ui.errors />
    {{-- ⭐ জরুরি সেটিংয়ের বদল — অপেক্ষায়, আটকানো, অনুমোদন (দফা ৭) --}}
    @include('system_admin::settings.partials.changes')

    <form id="board-form" method="POST" action="{{ route('system_admin.control-panel.update') }}">
        @csrf
        @method('PUT')
    </form>

    <x-ui.filter-layout :filters="$facets" form="list-form">
        <div data-boxed class="mb-4 overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
            <form id="list-form" method="GET" action="{{ route('system_admin.control-panel') }}" class="contents" data-no-swap>
                <input type="hidden" name="tab" value="{{ $tab }}">
                <x-ui.toolbar :title="$title" :count="trans_choice('core.count.records', $total, ['count' => $total])"
                              :columns="$columns" :facets="$facets" :quiet="['tab', 'find']"
                              :sort="['subject' => __('system_admin::control.col_subject'), 'name' => __('core.table.name'), 'changed' => __('system_admin::control.col_changed')]"
                              :search-placeholder="__('system_admin::control.find')">
                    <x-slot:actions>
                        <x-ui.button tone="secondary" href="#control-history">{{ __('system_admin::module_board.history') }}</x-ui.button>
                        <x-ui.button type="submit" form="board-form" tone="primary">{{ __('core.action.save') }}</x-ui.button>
                    </x-slot:actions>
                </x-ui.toolbar>
            </form>
        </div>

        @component('system_admin::control-panel.partials.side', ['tab' => $everywhere ? '' : $tab, 'ownSearch' => false])
            <div class="space-y-4" data-control-boxes>
                @forelse ($boxes as $code => $rows)
                    <section data-boxed data-control-box="{{ $code }}" class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
                        <h2 class="border-b border-(--color-border) bg-(--color-section-head) px-4 py-2 text-sm font-semibold">{{ $moduleLabels[$code] ?? $code }}
                            <span class="num ms-1 text-2xs font-normal text-(--color-ink-muted)">{{ $rows->count() }}</span></h2>
                        <x-ui.table :rows="$rows" :columns="$columns" :compact="true" :empty="request()->except(['page', 'per_page']) !== [] ? __('core.empty.no_results') : __('core.empty.nothing_here')" />
                    </section>
                @empty
                    <x-ui.empty-state :message="__('core.empty.no_results')" />
                @endforelse
            </div>
            <div id="control-history">
                @include('system_admin::control-panel.partials.history')
            </div>
        @endcomponent
    </x-ui.filter-layout>
</x-layouts.app>
