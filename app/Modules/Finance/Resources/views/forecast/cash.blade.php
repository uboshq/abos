{{--
    নগদের পূর্বাভাস — মানচিত্র §৮। চারটা সারি: এখনই (মেয়াদোত্তীর্ণ সহ),
    ৩০, ৬০, ৯০ দিন। প্রতিটায় কত আসবে, কত যাবে, আর শেষে হাতে কত।

    ⓘ কোথা থেকে কী ধরা হয় আর কী হয় না — [[CashForecast]]-এর মাথায় লেখা,
    আর পাতাতেও ছোট করে বলা, যাতে কেউ সংখ্যাটাকে প্রতিশ্রুতি ভাবেন না।
--}}
@php
    $money = fn ($v) => \App\Core\Support\Money::format($v);
    $columns = [
        ['key' => 'bucket', 'label' => __('finance::forecast.when'),
         'render' => fn ($r) => __('finance::forecast.bucket_'.$r['bucket'])],
        ['key' => 'receivables', 'label' => __('finance::forecast.receivables'), 'numeric' => true,
         'render' => fn ($r) => $money($r['receivables'])],
        ['key' => 'loans_in', 'label' => __('finance::forecast.loans_in'), 'numeric' => true,
         'render' => fn ($r) => $money($r['loans_in'])],
        ['key' => 'payables', 'label' => __('finance::forecast.payables'), 'numeric' => true,
         'render' => fn ($r) => $money($r['payables'])],
        ['key' => 'loans_out', 'label' => __('finance::forecast.loans_out'), 'numeric' => true,
         'render' => fn ($r) => $money($r['loans_out'])],
        ['key' => 'net', 'label' => __('finance::forecast.net'), 'numeric' => true,
         'render' => fn ($r) => $money($r['net'])],
        ['key' => 'closing', 'label' => __('finance::forecast.closing'), 'numeric' => true,
         'render' => fn ($r) => view('finance::forecast.partials.closing', ['amount' => $r['closing']])],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('finance::forecast.title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('finance::forecast.title')" :subtitle="__('finance::forecast.note')" />
    </x-slot:header>

    <section data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card)">
        <h2 class="flex flex-wrap items-baseline justify-between gap-2 border-b border-(--color-border)
                   bg-(--color-section-head) px-4 py-3 font-semibold">
            <span>{{ __('finance::forecast.opening') }}</span>
            <span class="num">{{ $money($forecast['opening']) }}</span>
        </h2>

        <x-ui.table :compact="true" :empty="''" :rows="$forecast['rows']" :columns="$columns" />

        <p class="border-t border-(--color-border) px-4 py-3 text-xs text-(--color-ink-muted)">
            {{ __('finance::forecast.caveat') }}
        </p>
    </section>
</x-layouts.app>
