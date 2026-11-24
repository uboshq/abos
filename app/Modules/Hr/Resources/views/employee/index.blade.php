{{--
    কর্মীর তালিকা।

    ছেড়ে যাওয়া কর্মীরা ডিফল্টে নেই, কিন্তু মোছাও হয়নি — চেকবক্সে
    দেখা যায়। পুরনো বেতনশিটে নামটা লাগে, তাই মোছার কোনো পথ নেই।
--}}
@php
    $columns = [
        ['key' => 'code', 'label' => __('hr::field.code'), 'width' => '8rem',
         'render' => fn ($e) => view('hr::employee.partials.code', ['employee' => $e])],
        ['key' => 'name_en', 'label' => __('hr::field.name'),
         'render' => fn ($e) => view('hr::employee.partials.name', ['employee' => $e])],
        ['key' => 'department', 'label' => __('hr::field.department'), 'width' => '12rem',
         'render' => fn ($e) => $e->department?->name() ?? '—'],
        ['key' => 'designation', 'label' => __('hr::field.designation'), 'width' => '12rem',
         'render' => fn ($e) => $e->designation?->name() ?? '—'],
        ['key' => 'mobile', 'label' => __('hr::field.mobile'), 'width' => '10rem',
         'render' => fn ($e) => $e->mobile ?? '—'],
        ['key' => 'joining_date', 'label' => __('hr::field.joining_date'), 'width' => '10rem',
         'render' => fn ($e) => $e->joining_date?->format('d M Y') ?? '—'],
    ];

    // ⭐ ছাঁকনি ডান পাশের প্যানেলে — আগের ঘরগুলো, একই চাবি (মালিক, ২৩ নভেম্বর ২০২৬; [[Facets]])
    $facetPanel = \App\Core\ListFilter\Facets::panel([
        \App\Core\ListFilter\Facets::flag('left', __('hr::action.show_left')),
    ]);
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('hr::menu.employees') }}</x-slot:title>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    <x-ui.filter-layout :filters="$facetPanel" form="list-form">
    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form id="list-form" method="GET" class="contents">
            <x-ui.toolbar :title="__('hr::menu.employees')" :count="trans_choice('core.count.records', $employees->total(), ['count' => $employees->total()])"
                :columns="$columns" :sort="$sortOptions"
                          :search-placeholder="__('hr::field.name')" :facets="$facetPanel">
        <x-slot:actions>
            @can('hr.employee.manage')
                    <x-ui.button tone="primary" icon="plus" :href="route('hr.employee.create')">
                        {{ __('hr::action.new_employee') }}
                    </x-ui.button>
                @endcan
        </x-slot:actions>
            </x-ui.toolbar>
        </form>

        <x-ui.table
            :empty="$q ? __('core.empty.no_results') : __('hr::message.no_employees')"
            :rows="$employees"
            :columns="$columns" />
    </div>

    <div class="mt-4">{{ $employees->links() }}</div>
    <x-ui.list-totals :rows="$employees" />
    </x-ui.filter-layout>
</x-layouts.app>
