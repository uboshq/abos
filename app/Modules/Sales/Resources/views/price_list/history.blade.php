{{-- একটা পণ্যের দামের ইতিহাস — অডিট-খাতা থেকে, নতুন আগে ([[SalePriceBook::history()]])। --}}
@php
    use App\Core\Support\Money;

    $money = fn (?string $v) => $v === null || $v === '' ? '—' : Money::format($v);

    $columns = [
        ['key' => 'when', 'label' => __('sales::price_list.when'), 'width' => '12rem',
            'render' => fn ($r) => $r->when ? \Illuminate\Support\Carbon::parse($r->when)->format('d-m-Y H:i') : '—'],
        ['key' => 'who', 'label' => __('sales::price_list.who'), 'render' => fn ($r) => $r->user ?? '—'],
        ['key' => 'old', 'label' => __('sales::price_list.old'), 'numeric' => true, 'render' => fn ($r) => $money($r->old)],
        ['key' => 'new', 'label' => __('sales::price_list.new'), 'numeric' => true, 'render' => fn ($r) => $money($r->new)],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('sales::price_list.history_title', ['product' => $product->name()]) }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('sales::price_list.history_title', ['product' => $product->name()])"
                          :subtitle="__('sales::price_list.history_subtitle')">
            <x-slot:actions>
                <x-ui.button :href="route('sales.price_list.index')" tone="secondary">{{ __('sales::price_list.back') }}</x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                bg-(--color-surface-card)">
        <x-ui.table :rows="$rows" :columns="$columns" :empty="__('sales::price_list.no_history')" />
    </div>
</x-layouts.app>
