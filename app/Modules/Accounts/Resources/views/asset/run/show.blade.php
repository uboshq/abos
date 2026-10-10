{{--
    ⭐ এক শাখার এক মাসের অবচয়ের কাগজ — খাতায় একটা দাখিলা, ভেতরে সম্পদ ধরে সারি (স্থায়ী সম্পদ ধাপ ২)।
    ⓘ খাতার "DEP-…" সারি থেকে এখানে আসা যায় ([[DepreciationRun::drillRoute()]]); প্রতিটা সম্পদ নিজের পাতায় খোলে।
--}}
@php
    $columns = [
        ['key' => 'asset', 'label' => __('accounts::asset.name'),
            'render' => fn ($e) => view('accounts::asset.partials.name', ['asset' => $e->asset])],
        ['key' => 'category', 'label' => __('accounts::asset.category'), 'width' => '12rem',
            'render' => fn ($e) => $e->asset?->category?->name() ?? '—'],
        ['key' => 'amount', 'label' => __('accounts::asset.amount'), 'numeric' => true, 'width' => '10rem', 'total' => 'money',
            'render' => fn ($e) => view('accounts::asset.partials.amount', ['value' => $e->amount])],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $run->document_no }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$run->document_no"
                          :subtitle="__('accounts::asset.run_label', ['month' => $run->period_end?->translatedFormat('F Y')]).' · '.($run->branch?->name() ?? __('accounts::asset.no_branch'))" />
    </x-slot:header>

    <div class="mb-4 flex justify-end">
        <p class="num text-xl font-semibold">{{ \App\Core\Support\Money::format((string) $run->total) }}</p>
    </div>

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <x-ui.table :rows="$run->entries" :columns="$columns" :empty="__('accounts::asset.empty_entries')" />
    </div>
</x-layouts.app>
