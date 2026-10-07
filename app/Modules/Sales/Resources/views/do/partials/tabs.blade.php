{{--
    ডেলিভারি অর্ডারের ট্যাব-সারি — DO পাতা আর কাউন্টারের খসড়ার পাতা, দুইটাই এটা দেখায়
    ([[DeliveryOrderTabs]])। ⓘ `$active` — কোন ট্যাবটা এখন খোলা।
--}}
@php
    $doTabs = app(\App\Modules\Sales\Services\DeliveryOrderTabs::class);
    $doCounts = $doTabs->counts();
    $canSell = auth()->user()?->can('sales.challan.create') ?? false;
@endphp
<nav class="mb-3 flex flex-wrap gap-2" aria-label="{{ __('sales::menu.delivery_orders') }}">
    @foreach (\App\Modules\Sales\Services\DeliveryOrderTabs::TABS as $key => $label)
        {{-- ⓘ চাবি ছাড়া যে পাতা খোলে না, তার ট্যাবও নয় — চাপলে ৪০৩ দেখানোর চেয়ে না দেখানো ভালো --}}
        @continue (in_array($key, ['new', 'drafts', 'approval'], true) && ! $canSell)
        @continue ($key === 'tracking' && ! (auth()->user()?->can('sales.delivery.view') ?? false))
        <a href="{{ $doTabs->href($key) }}"
           @if ($active === $key) aria-current="page" @endif
           @class([
               'inline-flex items-center gap-2 rounded-(--radius-field) border px-3 py-1.5 text-sm font-semibold',
               'border-(--color-brand-600) bg-(--color-brand-600) text-white' => $active === $key,
               'border-(--color-border) text-(--color-ink) hover:bg-(--color-surface-hover)' => $active !== $key,
           ])>
            {{ __($label) }}
            @if (in_array($key, \App\Modules\Sales\Services\DeliveryOrderTabs::COUNTED, true))
                <span class="num rounded-full bg-black/10 px-1.5 text-xs">{{ $doCounts[$key] }}</span>
            @endif
        </a>
    @endforeach
</nav>
