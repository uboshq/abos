{{-- ⭐ "নাম · পয়েন্ট · শাখা" — মালিক, ১৯ অক্টোবর ২০২৬ (স্থায়ী নিয়ম); এক লাইনে, পুরোটা title-এ --}}
<span class="block max-w-64 truncate" title="{{ $claim->customer?->nameWithPoint() }}">{{ $claim->customer?->nameWithPoint() }}</span>
<span class="block text-2xs text-(--color-ink-muted)">{{ $claim->customer?->code }}@if ($claim->depositor) · {{ __('sales::slip.depositor') }}: {{ $claim->depositor }}@endif</span>
{{-- ⚠️ একই চেহারার স্লিপ আগে এসেছে — হিসাবরক্ষক ব্যাংক স্টেটমেন্টে মিলিয়ে দেখবেন (সতর্কতা, আটকানো নয়; ১৬ নভেম্বর ২০২৬) --}}
@if ($claim->lookalike_claim_id)
    {{-- ⓘ চিহ্ন আইকনের সেট থেকে, টাইপ করা ইমোজি নয় ([[IconSetTest]]) --}}
    {{-- ⭐ ছাপটা লিংক — আগের জমার খবর তালিকায় একা খোলে (২০ নভেম্বর ২০২৬) --}}
    <a href="{{ route('sales.claim.index', ['status' => 'all', 'claim' => $claim->lookalike_claim_id]) }}"
       class="mt-0.5 inline-flex items-center gap-1 rounded-(--radius-field) bg-(--color-badge-warning-bg) px-1.5 text-2xs font-semibold text-(--color-badge-warning-ink) hover:underline"
       data-slip-lookalike><x-ui.icon name="alert-triangle" :size="12" />{{ __('sales::slip.lookalike_short', ['no' => $claim->lookalike_claim_id]) }}</a>
@endif
