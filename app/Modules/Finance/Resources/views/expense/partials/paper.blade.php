{{-- কাগজের নম্বরটাই লিংক — খরচের সারি থেকে আসল ভাউচারে যাওয়ার পথ।
     ⭐ পাশে পক্ষ, থাকলে — কাকে দেওয়া (মালিক, ১৯ অক্টোবর ২০২৬); নাম আনে কন্ট্রোলার, পাতার একবারে --}}
<x-ui.party-cell :party="$party ?? null">
    <a href="{{ route('accounts.voucher.show', $voucher) }}"
       class="num text-(--color-link) hover:underline">{{ $voucher->document_no }}</a>
</x-ui.party-cell>
