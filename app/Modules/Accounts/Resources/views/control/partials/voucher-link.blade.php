{{-- ⭐ পাশে পক্ষ, থাকলে (মালিক, ১৯ অক্টোবর ২০২৬) — নাম আনে কন্ট্রোলার, পাতার একবারে --}}
<x-ui.party-cell :party="$party ?? null">
    <a href="{{ route('accounts.voucher.show', $voucher) }}" class="text-(--color-link) hover:underline">{{ $voucher->document_no ?? '—' }}</a>
</x-ui.party-cell>
