{{-- ⭐ "নাম · পয়েন্ট · শাখা" — মালিক, ১৯ অক্টোবর ২০২৬ (স্থায়ী নিয়ম); এক লাইনে, পুরোটা title-এ --}}
<span class="block max-w-64 truncate" title="{{ $claim->customer?->nameWithPoint() }}">{{ $claim->customer?->nameWithPoint() }}</span>
<span class="block text-2xs text-(--color-ink-muted)">{{ $claim->customer?->code }}</span>
