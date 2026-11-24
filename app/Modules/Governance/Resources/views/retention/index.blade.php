{{--
    কাগজ সংরক্ষণ নীতি — কোনটা কতদিন থাকে।

    ── কেন পর্দাটা নিয়ম বসায় না ────────────────────────────────────────
    এখানে "৬ বছর" লিখে রাখা যেত। ⛔ কিন্তু লেখা এক আর ঘটা আরেক হলে নীতিটাই
    সবচেয়ে বিপজ্জনক কাগজ — নিরীক্ষকের সামনে ওটা দেখিয়ে বলা হত "আমাদের নীতি
    আছে", অথচ সারিগুলো কবে মুছেছে কেউ জানত না।

    ⭐ তাই প্রতিটা সারিতে তিনটা কথা: কী, কতদিন, আর **কে সেটা মানায়**।
--}}
@php
    use App\Modules\Governance\Services\WhatIsKeptHowLong;

    $abosHead = \App\Core\Support\AbosHead::on();
    $kind = fn ($row) => (string) __('governance::retention.kind.'.$row['key']);
    $columns = [
        ['key' => 'what', 'label' => __('governance::retention.what'), 'wrap' => true, 'render' => fn ($row) => view('governance::retention.partials.what', ['row' => $row])],
        ['key' => 'kept', 'label' => __('governance::retention.how_long'), 'width' => '12rem', 'render' => fn ($row) => view('governance::retention.partials.kept', ['row' => $row])],
        ['key' => 'by', 'label' => __('governance::retention.enforced_by'), 'wrap' => true, 'render' => fn ($row) => __('governance::retention.by_'.$row['by'])],
        ['key' => 'rows', 'label' => __('governance::retention.rows_now'), 'numeric' => true, 'width' => '9rem', 'render' => fn ($row) => (string) ($row['rows'] ?? '—')],
    ];
    $facetPanel = \App\Core\ListFilter\Facets::panel($abosHead ? [\App\Core\ListFilter\Facets::pick('kept', __('governance::retention.how_long'),
        ['forever' => __('governance::retention.forever'), 'limited' => __('governance::retention.limited')])] : []);
    // ⓘ সারি কোডে বাঁধা, তাই খোঁজা, ছাঁকনি আর সাজানো এখানেই — কেবল ABOS রূপে
    $find = mb_strtolower(request()->string('q')->trim()->value());
    $shown = collect($rows)
        ->filter(fn ($row) => $find === '' || str_contains(mb_strtolower($kind($row)), $find))
        ->filter(fn ($row) => match (request('kept')) {
            'forever' => $row['kept'] === WhatIsKeptHowLong::FOREVER,
            'limited' => $row['kept'] !== WhatIsKeptHowLong::FOREVER,
            default => true,
        });
    $shown = (request('sort') === 'rows' ? $shown->sortByDesc(fn ($row) => (int) ($row['rows'] ?? 0)) : $shown)->values();
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('governance::retention.title') }}</x-slot:title>

    @unless ($abosHead)
    <x-slot:header>
        <x-ui.page-header :title="__('governance::retention.title')"
                          :subtitle="__('governance::retention.note', ['years' => $lawYears])"
                          :info="$abosHead ? __('governance::retention.footer') : null" />
    </x-slot:header>
    @endunless

    {{-- ⚠️ সবচেয়ে দুর্বল জায়গাটা উপরে, কারণ দুইটা জিনিস এক মনে হয়:
         খাতা চিরকাল থাকে, কিন্তু ব্যাকআপ ত্রিশ দিনের। ডাটাবেস হারালে
         ছয় বছরের খাতা নয়, ত্রিশ দিনের পিছন পর্যন্তই ফেরানো যায়। --}}
    @unless ($abosHead)
        @include('governance::retention.partials.gap')
    @endunless

    @if ($abosHead)
        <x-ui.filter-layout :filters="$facetPanel" form="list-form">
        <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
            <form id="list-form" method="GET" class="contents">
                <x-ui.toolbar :title="__('governance::retention.title')" :info="__('governance::retention.note', ['years' => $lawYears]).' '.__('governance::retention.footer')"
                              :search-placeholder="__('governance::retention.what')" :share="false" :columns="$columns" :facets="$facetPanel"
                              :sort="['plan' => __('governance::retention.what'), 'rows' => __('governance::retention.rows_now')]" />
            </form>
            {{-- ⭐ আগে মাথা, বাক্স তার নিচে (মালিকের নিয়ম; তিন ABOS রূপে) --}}
            <div class="px-3 pt-3">@include('governance::retention.partials.gap')</div>
            <x-ui.table :rows="$shown" :columns="$columns" :row-attributes="fn ($row) => ['data-kept' => $row['key']]" :empty="__('core.empty.no_results')" />
        </div>
        </x-ui.filter-layout>
    @else
    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card)">
        <table class="ui-list table-cards w-full border-collapse">
            <thead>
                <tr>
                    <th class="text-start">{{ __('governance::retention.what') }}</th>
                    <th class="text-start">{{ __('governance::retention.how_long') }}</th>
                    <th class="text-start">{{ __('governance::retention.enforced_by') }}</th>
                    <th class="text-end">{{ __('governance::retention.rows_now') }}</th>
                </tr>
            </thead>

            <tbody>
                @foreach ($rows as $row)
                    <tr data-kept="{{ $row['key'] }}">
                        <td>
                            @if ($row['route'])
                                <a href="{{ \App\Modules\Finance\Support\FinancePlan::urlFor($row['route']) }}"
                                   class="text-(--color-link) hover:underline">{{ __('governance::retention.kind.'.$row['key']) }}</a>
                            @else
                                {{ __('governance::retention.kind.'.$row['key']) }}
                            @endif
                        </td>

                        <td>
                            @if ($row['kept'] === WhatIsKeptHowLong::FOREVER)
                                <span class="rounded-(--radius-pill) bg-(--color-badge-success-bg) px-2 py-0.5
                                             text-2xs text-(--color-badge-success-ink)">
                                    {{ __('governance::retention.forever') }}
                                </span>
                            @else
                                <span class="rounded-(--radius-pill) bg-(--color-badge-pending-bg) px-2 py-0.5
                                             text-2xs text-(--color-badge-pending-ink)">
                                    {{ trans_choice('governance::retention.days', $row['days'], ['count' => $row['days']]) }}
                                </span>
                            @endif
                        </td>

                        <td class="text-2xs text-(--color-ink-muted)">
                            {{ __('governance::retention.by_'.$row['by']) }}
                        </td>

                        <td class="num text-end">{{ $row['rows'] === null ? '—' : $row['rows'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif

    @unless ($abosHead)
        <p class="mt-3 text-2xs text-(--color-ink-muted)">{{ __('governance::retention.footer') }}</p>
    @endunless
</x-layouts.app>
