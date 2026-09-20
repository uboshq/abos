{{-- হাতে যা থাকবে — শূন্যের নিচে গেলে লাল: ঐ দিনের আগেই টাকার ব্যবস্থা লাগবে --}}
<span @class(['num font-semibold', 'text-(--color-badge-danger-ink)' => bccomp((string) $amount, '0', 4) < 0])>
    {{ \App\Core\Support\Money::format($amount) }}
</span>
