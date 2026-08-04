{{--
    হাতে কত, আর সীমা ছাড়িয়েছে কি না।

    সীমা ছাড়ানোটা লাল করে দেখানো হয়, আটকানো হয় না — বিকেলে আদায় বেশি
    হলে সেটা কারও দোষ নয়। কিন্তু দেখা না গেলে টাকাটা রাতেও ওই হাতেই
    থেকে যেত, আর সেটাই আসল ঝুঁকি।
--}}
@php $over = bccomp((string) $till->limit_amount, '0', 4) > 0
    && bccomp((string) $balance, (string) $till->limit_amount, 4) > 0 @endphp

<span class="block">
    <span @class(['num block', 'font-semibold text-(--color-danger)' => $over])>
        {{ number_format((float) $balance, 2) }}
    </span>

    @if ($over)
        <span class="num block text-2xs text-(--color-ink-muted)">
            {{ __('accounts::field.limit') }} {{ number_format((float) $till->limit_amount, 2) }}
        </span>
    @endif
</span>
