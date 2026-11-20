{{-- ⭐ নম্বরের পাশে পক্ষ, থাকলে (মালিক, ১৯ অক্টোবর ২০২৬) — নাম আনে ডাকনেওয়ালা, পাতার একবারে ([[VoucherParties]]) --}}
<x-ui.party-cell :party="$party ?? null">
    <a href="{{ route('accounts.voucher.show', $voucher) }}"
       class="num text-(--color-brand-500) underline-offset-2 hover:underline">{{ $voucher->document_no }}</a>
    {{-- ⭐ কাউন্টারের রসিদে "কাউন্টার" দাগ — মালিক, ২০ নভেম্বর ২০২৬ (কাউন্টারের টাকাও রসিদ; তালিকায় চেনা যায়) --}}
    @if ($voucher->type === \App\Modules\Accounts\Models\Voucher::RECEIPT && $voucher->origin === \App\Modules\Accounts\Models\Voucher::ORIGIN_COUNTER)
        <x-ui.badge tone="info" class="ms-1" data-counter-receipt title="{{ __('accounts::voucher.counter_badge_hint') }}">{{ __('accounts::voucher.counter_badge') }}</x-ui.badge>
    @endif
</x-ui.party-cell>
