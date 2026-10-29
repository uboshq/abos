{{-- ⭐ নম্বরের পাশে পক্ষ, থাকলে (মালিক, ১৯ অক্টোবর ২০২৬) — নাম আনে ডাকনেওয়ালা, পাতার একবারে ([[VoucherParties]]) --}}
<x-ui.party-cell :party="$party ?? null">
    <a href="{{ route('accounts.voucher.show', $voucher) }}"
       class="num text-(--color-brand-500) underline-offset-2 hover:underline">{{ $voucher->document_no }}</a>
</x-ui.party-cell>
