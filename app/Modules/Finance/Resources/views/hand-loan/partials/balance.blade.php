{{-- চিহ্নটাই বলে দেয় কে কার কাছে পায়।

     ── কেন সংখ্যার পাশে কথাটাও লেখা ──────────────────────────────────
     ঋণাত্মক সংখ্যা পড়ে "এটা কি আমি পাব না দেব" ভাবতে হয়, আর ভুল
     ভাবলে ফোন করে ভুল কথা বলা হয়। তিনটা শব্দ ওই সন্দেহটা তুলে দেয়। --}}
@php
    $balance = $row['balance'];
    $sign = bccomp($balance, '0', 4);
@endphp

<span class="inline-flex flex-col items-end">
    <span @class(['tabular-nums font-medium',
        'text-(--color-badge-danger-ink)' => $sign < 0])>
        {{ \App\Core\Support\Money::format($sign < 0 ? bcmul($balance, '-1', 4) : $balance) }}
    </span>

    <span class="text-2xs text-(--color-ink-muted)">
        @if ($sign > 0)
            {{ __('finance::message.hand_loan_they_owe') }}
        @elseif ($sign < 0)
            {{ __('finance::message.hand_loan_we_owe') }}
        @else
            {{ __('finance::message.hand_loan_clear') }}
        @endif
    </span>
</span>
