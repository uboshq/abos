@props(['url' => null])

{{--
    ⭐ ছাপা তৈরি — নতুন ট্যাবে (মালিক, ২৩ নভেম্বর ২০২৬: কাউন্টারে নিশ্চিতের পরে ছাপা একই ট্যাবে খুলত, কাউন্টার হারিয়ে যেত)।

    ⓘ সার্ভার কাজের পাতায় ফেরে, সাথে `print_url`। `data-print-tab-url` দেখে [[print-tab.js]] ঠিকানাটা জমার মুহূর্তে খোলা ট্যাবে
      বসায়; ব্রাউজার পপ-আপ আটকালেও লিংকটা থাকে — চাপলেই নতুন ট্যাব।
--}}
@if (is_string($url) && $url !== '')
    <a href="{{ $url }}" target="_blank" rel="noopener" data-print-tab-url="{{ $url }}"
       {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 font-semibold underline underline-offset-2']) }}>
        <x-ui.icon name="printer" :size="15" />
        {{ __('core.print.open_print') }}
    </a>
@endif
