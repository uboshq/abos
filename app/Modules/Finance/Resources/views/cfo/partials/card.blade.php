{{-- CFO ড্যাশবোর্ডের এক কার্ড — নাম, সংখ্যা, আর চাইলে ছোট ব্যাখ্যা --}}
<p class="text-sm text-(--color-ink-muted)">{{ $card['label'] }}</p>
<p class="num mt-1 text-xl font-semibold {{ $card['tone'] ?? '' }}">{{ $card['value'] }}</p>
@if (! empty($card['hint']))
    <p class="mt-1 text-xs text-(--color-ink-muted)">{{ $card['hint'] }}</p>
@endif
