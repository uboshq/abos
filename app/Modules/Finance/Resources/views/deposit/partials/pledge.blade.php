{{--
    কোন ঋণের জামানতে — আর সেই ঋণের পাতায় নামে।

    ── ⓘ কেন লিংক, ২০ সেপ্টেম্বর ২০২৬ ────────────────────────────────
    ⛔ ঋণের নম্বরটা লেখা থাকলেও প্রশ্নটা থামত না: *কত বাকি, কবে শোধ*।
    ⚠️ উত্তরটা ঋণের পাতায়, আর নম্বর দেখে ওটা খুঁজতে হলে মানুষ হিসাব
    মডিউলে গিয়ে তালিকা ছাঁকতেন।

    ⓘ বন্ধক না থাকলে "খালি" — ওটা লিংক নয়, কারণ যাওয়ার জায়গা নেই।
--}}
@if ($deposit->pledged_to_loan_id === null)
    {{ __('finance::field.dep_free') }}
@elseif ($deposit->pledgedToLoan === null)
    {{-- ⚠️ ঋণটা আর নেই — নম্বরও নেই, তাই চুপ করে ড্যাশ --}}
    —
@else
    <a href="{{ route('accounts.loan.show', ['loan' => $deposit->pledged_to_loan_id]) }}"
       class="text-(--color-brand-500) underline-offset-2 hover:underline">
        {{ $deposit->pledgedToLoan->document_no }}
    </a>
@endif
