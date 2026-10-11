{{--
    একটা পিস এখন কোথায়।

    ⓘ তিনটাই, আর তিনটাই আলাদা প্রশ্নের উত্তর: গুদামে (বেচা যায়),
    গ্রাহকের কাছে (ওয়ারেন্টির ঘড়ি চলছে), বা ফেরত এসেছে (সিদ্ধান্ত বাকি)।
--}}
@php
    [$tone, $label] = match ($serial->status) {
        \App\Modules\Inventory\Models\SerialNumber::SOLD
            => ['success', __('inventory::status.serial_sold')],
        \App\Modules\Inventory\Models\SerialNumber::RETURNED
            => ['pending', __('inventory::status.serial_returned')],
        default => ['draft', __('inventory::status.serial_in_stock')],
    };
@endphp

<x-ui.badge :tone="$tone">{{ $label }}</x-ui.badge>

{{-- ⭐ ফেরত পিস — দেখে গুদামে ফেরত নিন (মজুদ ছ৩); কেবল মজুদ-সমন্বয়ের চাবিতে --}}
@if ($serial->status === \App\Modules\Inventory\Models\SerialNumber::RETURNED)
    @can('inventory.stock.adjust')
        <form method="POST" action="{{ route('inventory.serial.back_to_stock', $serial) }}" class="inline" data-back-to-stock>
            @csrf
            <x-ui.button type="submit" size="sm" tone="secondary">{{ __('inventory::action.serial_back_to_stock') }}</x-ui.button>
        </form>
    @endcan
@endif
