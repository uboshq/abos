{{--
    বেতনের খাতের তালিকা।

    আয় ও কর্তন এক তালিকায়, কারণ বেতনশিটে দুইটাই পাশাপাশি বসে — আলাদা
    দুইটা পর্দা হলে "মোট কর্তন কেন এত" প্রশ্নে দুই জায়গায় যেতে হত।
--}}
@php
    $columns = [
        ['key' => 'code', 'label' => __('hr::field.code'), 'width' => '9rem',
         'render' => fn ($h) => view('hr::salary_head.partials.code', ['head' => $h])],
        ['key' => 'name_en', 'label' => __('hr::field.name'),
         'render' => fn ($h) => $h->name()],
        ['key' => 'kind', 'label' => __('hr::field.kind'), 'width' => '8rem',
         'render' => fn ($h) => __('hr::kind.' . $h->kind)],
        ['key' => 'calculation', 'label' => __('hr::field.calculation'), 'width' => '12rem',
         'render' => fn ($h) => __('hr::kind.' . $h->calculation)],
        ['key' => 'account', 'label' => __('hr::field.account'), 'width' => '14rem',
         'render' => fn ($h) => $h->account?->label() ?? '—'],
        ['key' => 'actions', 'label' => __('core.table.action_menu'), 'width' => '8rem',
         'render' => fn ($h) => view('hr::salary_head.partials.actions', ['head' => $h])],
    ];

    // ⭐ ছাঁকনি ডান পাশের প্যানেলে — আগের ঘরগুলো, একই চাবি (মালিক, ২৩ নভেম্বর ২০২৬; [[Facets]])
    $facetPanel = \App\Core\ListFilter\Facets::panel([
        \App\Core\ListFilter\Facets::flag('inactive', __('hr::action.show_inactive')),
    ]);
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('hr::menu.salary_heads') }}</x-slot:title>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    @if ($errors->any())
        <div role="alert"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm
                    text-(--color-badge-danger-ink)">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @php $abosHead = \App\Core\Support\AbosHead::on(); @endphp
    @if ($canInstallDefaults && ! $abosHead)
        @include('hr::salary_head.partials.install')
    @endif

    <x-ui.filter-layout :filters="$facetPanel" form="list-form">
    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form id="list-form" method="GET" class="contents">
            <x-ui.toolbar :title="__('hr::menu.salary_heads')"
                :sort="$sortOptions"
                :columns="$columns" :search="false" :facets="$facetPanel">
        <x-slot:actions>
            <x-ui.button tone="primary" icon="plus" :href="route('hr.salary_head.create')">
                    {{ __('hr::action.new_head') }}
                </x-ui.button>
        </x-slot:actions>
            </x-ui.toolbar>
        </form>

        @if ($canInstallDefaults && $abosHead)
            <div class="px-3 pt-3">
                @include('hr::salary_head.partials.install')
            </div>
        @endif

        <x-ui.table
            :empty="__('hr::message.no_heads')"
            :rows="$heads"
            :columns="$columns" />

        <x-ui.pager :rows="$heads" />
        <x-ui.list-totals :rows="$heads" />
    </div>
    </x-ui.filter-layout>
</x-layouts.app>
