{{-- এক তালিকায় এক পণ্যের দরের ইতিহাস — অডিট-খাতা থেকে, নতুন আগে ([[PriceBookService::history()]])। --}}
@php
    use App\Core\Support\Money;

    $value = fn ($r, ?string $v) => $v === null || $v === '' ? '—'
        : ($r->field === 'price' ? Money::format($v) : \Illuminate\Support\Carbon::parse($v)->format('d-m-Y'));

    $columns = [
        ['key' => 'when', 'label' => __('sales::price_book.when'), 'width' => '12rem',
            'render' => fn ($r) => $r->when ? \Illuminate\Support\Carbon::parse($r->when)->format('d-m-Y H:i') : '—'],
        ['key' => 'who', 'label' => __('sales::price_book.who'), 'render' => fn ($r) => $r->user ?? '—'],
        ['key' => 'what', 'label' => __('sales::price_book.what'),
            'render' => fn ($r) => __('sales::price_book.field_'.$r->field).($r->from ? ' · '.__('sales::price_book.valid_from').' '.$r->from : '')],
        ['key' => 'old', 'label' => __('sales::price_book.old'), 'numeric' => true, 'render' => fn ($r) => $value($r, $r->old)],
        ['key' => 'new', 'label' => __('sales::price_book.new_value'), 'numeric' => true, 'render' => fn ($r) => $value($r, $r->new)],
    ];

    $title = __('sales::price_book.history_title', ['list' => $list->name(), 'product' => $product->name()]);
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $title }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$title" :subtitle="__('sales::price_book.history_subtitle')">
            <x-slot:actions>
                <x-ui.button :href="route('sales.price_book.show', $list)" tone="secondary">{{ __('sales::price_book.back') }}</x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <x-ui.table :rows="$rows" :columns="$columns" :empty="__('sales::price_book.no_history')" />
    </div>
</x-layouts.app>
