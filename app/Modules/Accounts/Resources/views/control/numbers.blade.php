{{--
    নম্বর সিরিজ মেলানো — ফিন্যান্স মানচিত্রের §৩৩।

    ⓘ আগে কেবল `abos:catch-up-numbers` কমান্ড ছিল, চালাতে ssh লাগত। পর্দা
    আর কমান্ড দুইটাই [[NumberSeriesCatchUp]] পড়ে। বোতাম চাপলে কেবল এই
    কোম্পানির সিরিজ সামনে আসে, অন্য কোম্পানিরটা নয়।
--}}
@php
    $columns = [
        ['key' => 'series', 'label' => __('accounts::control.series'),
         'render' => fn ($r) => $r['series']->doc_type.' · '.$r['series']->prefix],
        ['key' => 'next', 'label' => __('accounts::control.next_number'), 'numeric' => true, 'width' => '10rem',
         'render' => fn ($r) => $r['series']->next_number],
        ['key' => 'highest', 'label' => __('accounts::control.highest_used'), 'numeric' => true, 'width' => '10rem'],
        ['key' => 'after', 'label' => __('accounts::control.will_become'), 'numeric' => true, 'width' => '10rem',
         'render' => fn ($r) => $r['highest'] + 1],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('accounts::control.numbers_title') }}</x-slot:title>

    @if (session('saved'))
        <p role="alert" class="mb-3 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2
                               text-sm text-(--color-badge-success-ink)">{{ session('saved') }}</p>
    @endif

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <x-ui.toolbar :title="__('accounts::control.numbers_title')"
                      :subtitle="__('accounts::control.numbers_note')"
                      :search="false" :filter="false" :density="false"
                      :export="false" :share="false">
            @if ($behind !== [])
                <x-slot:actions>
                    <form method="POST" action="{{ route('accounts.control.catch_up') }}">
                        @csrf
                        <x-ui.button type="submit" tone="primary">{{ __('accounts::control.catch_up_now') }}</x-ui.button>
                    </form>
                </x-slot:actions>
            @endif
        </x-ui.toolbar>

        <x-ui.table :rows="$behind" :columns="$columns" :empty="__('accounts::control.all_caught_up')" />
    </div>
</x-layouts.app>
