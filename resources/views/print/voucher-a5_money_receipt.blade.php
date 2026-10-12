{{--
    ভাউচার · নকশা 1 — টাকা প্রাপ্তির রসিদ — মালিকের দেওয়া ছবির ধাঁচ (১২ অক্টোবর ২০২৬); বিবরণ আর বিল সমন্বয় "কথায়"-এর ওপরে।
    মালিক, ১২ অক্টোবর ২০২৬: *"ei gulo control panel e bosaw sobgulo"*; ভাগের কাজ [[VoucherPaper]]-এ।
--}}
@php
    $p = \App\Modules\Accounts\Support\VoucherPaper::from(get_defined_vars());
    $v = $p->v;
    $h = $p->head();
@endphp
<style @nonce>
    body { font-family: hindsiliguri, sans-serif; font-size: 9pt; color: #111; }
    table { border-collapse: collapse; }
    .num { text-align: right; white-space: nowrap; font-family: {{ $p->numFont() }}; }
</style>
@include('print.partials.voucher-money-receipt-body', ['p' => $p, 'v' => $v, 'h' => $h, 'copy' => 'customer_copy', 'first' => true])
