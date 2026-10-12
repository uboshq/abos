{{--
    A4 · ভাউচার · নকশা 1 — টাকা প্রাপ্তির রসিদ, এক বা দুই কপি (কন্ট্রোল প্যানেলের সেটিং) — মালিকের দেওয়া ছবির ধাঁচ (১২ অক্টোবর ২০২৬); বিবরণ আর বিল সমন্বয় "কথায়"-এর ওপরে।
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
@php $copies = $p->copies() === 2 ? ['office_copy', 'customer_copy'] : ['customer_copy']; @endphp
@foreach ($copies as $copy)
    {{-- ⓘ প্রতিটা কপি A4-এর অর্ধেক — মাঝখান থেকে কাটলে দুটো A5 রসিদ --}}
    <div style="height: {{ count($copies) === 2 ? '128mm' : 'auto' }}; overflow: hidden">
        @include('print.partials.voucher-money-receipt-body', ['p' => $p, 'v' => $v, 'h' => $h, 'copy' => $copy, 'first' => $loop->first])
    </div>
    @if (! $loop->last)
        <div style="text-align: center; color: #666; font-size: 8pt; margin: 3mm 0">- - - - - - - - - - - - - - - - - - - - - - - - {{ $p->t('cut_here') }} - - - - - - - - - - - - - - - - - - - - - - - -</div>
    @endif
@endforeach
