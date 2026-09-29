{{--
    বিলের পণ্যের ছক — সব নকশার ভাগের ([[InvoicePaperView]])। চাই: $v, $facts, $paper;
    ঐচ্ছিক $upper (মাথার লেখা বড় হাতে), $zebra (একটা বাদে একটা সারিতে `alt`), $lang (`en`/`bn`), $narrow (সরু কলাম)।

    ⓘ সুইচ (Free, Total QTY, Grand Total-এর সারি) আর `data-*` চিহ্ন এখানেই, একবার — নকশা কেবল
    `table.items`, `tr.alt`, `tr.grand`, `.sub`, `.free`, `.num` সাজায়।
--}}
@php
    $upper = $upper ?? true;
    $lang = $lang ?? 'en';
    /* ⓘ সরু জায়গার নকশায় (পাশের পট্টি, আধা পাতা) সংখ্যার কলাম সরু — নইলে পণ্যের নাম ভাঙে */
    $w = ($narrow ?? false) ? ['rate' => 17, 'qty' => 15, 'free' => 11, 'total' => 16, 'amount' => 22] : ['rate' => 24, 'qty' => 18, 'free' => 15, 'total' => 20, 'amount' => 29];
    $zebra = $zebra ?? false;
    $h = fn (string $key) => $upper ? mb_strtoupper($v->label($key, $lang)) : $v->label($key, $lang);
@endphp
<table class="items">
    <thead>
        <tr>
            <th style="width: 8mm">{{ $v->label('sl', $lang) }}</th>
            <th>{{ $h('product') }}</th>
            <th class="num" style="width: {{ $w['rate'] }}mm">{{ $h('rate') }}</th>
            <th class="num" style="width: {{ $w['qty'] }}mm">{{ $h('qty') }}</th>
            @if ($v->free)<th class="num" style="width: {{ $w['free'] }}mm" data-col-free>{{ $h('free') }}</th>@endif
            @if ($v->totalQty)<th class="num" style="width: {{ $w['total'] }}mm" data-col-total-qty>{{ $h('total_qty') }}</th>@endif
            <th class="num" style="width: {{ $w['amount'] }}mm">{{ $h('amount') }}</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($facts['items']['rows'] as $index => $item)
            <tr @class(['alt' => $zebra && $index % 2 === 1])>
                <td>{{ $index + 1 }}</td>
                <td class="item-name">
                    {{ $item['name'] }}
                    @php($under = implode(' · ', array_filter([$item['code'] ?? '', $item['lot'] ?? ''])))
                    @if ($under !== '')<div class="sub">{{ $under }}</div>@endif
                </td>
                <td class="num">{{ $paper->money($item['rate']) }}</td>
                <td class="num">{{ $item['qty'] }}</td>
                @if ($v->free)<td @class(['num', 'free' => filled($item['free'])])>{{ filled($item['free']) ? $item['free'] : '—' }}</td>@endif
                @if ($v->totalQty)<td class="num">{{ $item['total_qty'] }}</td>@endif
                <td class="num">{{ $paper->money($item['amount']) }}</td>
            </tr>
        @endforeach

        @if ($v->shows('grand_total_row'))
            <tr class="grand" data-grand-row>
                <td></td>
                <td>{{ $h('grand_total') }}</td>
                <td></td>
                <td class="num">{{ $facts['items']['totals']['qty'] }}</td>
                @if ($v->free)<td class="num">{{ $facts['items']['totals']['free'] }}</td>@endif
                @if ($v->totalQty)<td class="num">{{ $facts['items']['totals']['total_qty'] }}</td>@endif
                <td class="num">{{ $paper->money($facts['items']['totals']['amount']) }}</td>
            </tr>
        @endif
    </tbody>
</table>
