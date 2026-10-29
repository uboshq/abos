{{-- ⭐ কাগজের নম্বর, পাশে পক্ষ এক লাইনে (মালিক, ১৯ অক্টোবর ২০২৬) — নাম আনে কন্ট্রোলার, পাতার একবারে ([[DocumentParties]]) --}}
<x-ui.party-cell :party="$party ?? null"><span class="num">{{ $no ?: '—' }}</span></x-ui.party-cell>
