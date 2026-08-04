{{--
    হাতে কত — আর দুইটা জিনিস যা চোখে পড়া দরকার।

    সীমা ছাড়ানো: আটকানো হয় না, কারণ বিকেলে আদায় বেশি হওয়া কারও দোষ নয়।
    কিন্তু দেখা না গেলে টাকাটা রাতেও ওই হাতেই থেকে যেত, আর সেটাই ঝুঁকি।

    ঋণাত্মক: হাতে ঋণাত্মক টাকা বলে কিছু নেই। এটা দেখা মানে হয় কোনো আদায়
    লেখা হয়নি, নয় তারিখ ভুল, নয় ভুল কাউন্টার থেকে খরচ লেখা হয়েছে —
    তিনটাই ভুল, আর দেরিতে ধরা পড়লে মিলানো কঠিন হয়ে যায়।
--}}
@php
    $negative = bccomp((string) $balance, '0', 4) < 0;

    $over = ! $negative
        && bccomp((string) $till->limit_amount, '0', 4) > 0
        && bccomp((string) $balance, (string) $till->limit_amount, 4) > 0;
@endphp

<span class="block">
    <span @class(['block', 'font-semibold' => $negative || $over])>
        {{-- অঙ্কটাই লিংক — কাউন্টারের পাতায় লেনদেনগুলো আছে (নিয়ম ১) --}}
        <x-ui.amount :value="$balance"
                     :href="route('accounts.till.show', $till)"
                     :tone="$negative || $over ? 'danger' : null" />
    </span>

    @if ($negative)
        <span class="block text-2xs text-(--color-danger)">
            {{ __('accounts::message.negative_cash') }}
        </span>
    @elseif ($over)
        <span class="num block text-2xs text-(--color-ink-muted)">
            {{ __('accounts::field.limit') }} {{ number_format((float) $till->limit_amount, 2) }}
        </span>
    @endif
</span>
