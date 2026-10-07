{{-- কোন দিক — তারা দেবে, না আমরা দেব (১৯ সেপ্টেম্বর ২০২৬, মালিকের চাওয়া কলাম)।

     ⓘ চিহ্ন থেকেই, [[partials/balance]]-এর মতো — একই হিসাব দুই জায়গায়
     দুই রকম হলে কেউ বিশ্বাস করত না কোনটা ঠিক। --}}
@php($sign = bccomp((string) $row['balance'], '0', 4))

<span @class(['text-sm', 'text-(--color-badge-danger-ink)' => $sign < 0])>
    @if ($sign > 0)
        {{ __('finance::message.hand_loan_they_owe') }}
    @elseif ($sign < 0)
        {{ __('finance::message.hand_loan_we_owe') }}
    @else
        {{ __('finance::message.hand_loan_clear') }}
    @endif
</span>
