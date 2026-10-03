{{--
    বিলের পণ্যের ছক — সব নকশার ভাগের ([[InvoicePaperView]])। চাই: $v, $facts, $paper;
    ঐচ্ছিক $upper (মাথার লেখা বড় হাতে), $zebra (একটা বাদে একটা সারিতে `alt`), $lang (`en`/`bn`), $narrow (সরু কলাম),
    $inlineLot (কোড · লট নামের পাশে, একই লাইনে — এক সারি এক লাইন; মোনো হালকা A4-এ ২৫ সারি এক পাতায়, মালিক ৩ অক্টোবর ২০২৬)।

    ⓘ সুইচ (Free, Total QTY, Grand Total-এর সারি) আর `data-*` চিহ্ন এখানেই, একবার — নকশা কেবল
    `table.items`, `tr.alt`, `tr.grand`, `.sub`, `.free`, `.num` সাজায়।
--}}
@php
    $upper = $upper ?? true;
    $lang = $lang ?? 'en';
    /*
     * ⛔→⭐ সংখ্যার কলামে বাঁধা চওড়া নেই — মালিক, ৩ অক্টোবর ২০২৬: *"faka jayga komale ordhek jayga kome emnitei"*।
     * ⓘ আগে দর ২৪, পরিমাণ ১৮, ফ্রি ১৫, মোট পরিমাণ ২০, টাকা ২৯mm বাঁধা (সরু নকশায় কম): ডানে-সাঁটা "10 Ctn" ১৮mm ঘরে
     * বসে বাঁয়ে ৮–১০mm ফাঁকা রাখত — কাগজে দর আর পরিমাণের মাঝে, ফ্রি আর মোট পরিমাণের মাঝে দুইটা "খালি কলাম"। ⭐ এখন
     * সংখ্যার ঘর লেখার মাপে (mPDF নিজে মাপে, `nowrap` তাই সংখ্যা ভাঙে না), বাকি সব জায়গা পণ্যের নামের — নাম কম
     * ভাঙে, সারি খাটো। ⓘ `$narrow` আর লাগে না; যে নকশা দেয়, তার ক্ষতি নেই।
     */
    $zebra = $zebra ?? false;
    $h = fn (string $key) => $upper ? mb_strtoupper($v->label($key, $lang)) : $v->label($key, $lang);
@endphp
<table class="items">
    <thead>
        <tr>
            <th style="width: 8mm">{{ $v->label('sl', $lang) }}</th>
            <th>{{ $h('product') }}</th>
            <th class="num">{{ $h('rate') }}</th>
            <th class="num">{{ $h('qty') }}</th>
            @if ($v->free)<th class="num" data-col-free>{{ $h('free') }}</th>@endif
            @if ($v->totalQty)<th class="num" data-col-total-qty>{{ $h('total_qty') }}</th>@endif
            <th class="num">{{ $h('amount') }}</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($facts['items']['rows'] as $index => $item)
            <tr @class(['alt' => $zebra && $index % 2 === 1])>
                <td>{{ $index + 1 }}</td>
                <td class="item-name">
                    {{ $item['name'] }}
                    @if ($inlineLot ?? false)
                        <span class="sub">@include('sales::print.partials.item-code-lot', ['item' => $item, 'inline' => true])</span>
                    @else
                        @include('sales::print.partials.item-code-lot', ['item' => $item, 'class' => 'sub'])
                    @endif
                </td>
                <td class="num">{{ $paper->money($item['rate']) }}</td>
                <td class="num">{{ $item['qty'] }}</td>
                @if ($v->free)<td @class(['num', 'free' => filled($item['free'])])>{{ filled($item['free']) ? $item['free'] : '—' }}</td>@endif
                @if ($v->totalQty)<td class="num">{{ $item['total_qty'] }}</td>@endif
                <td class="num">{{ $paper->money($item['amount']) }}</td>
            </tr>
        @endforeach

        @if ($v->shows('grand_total_row'))
            {{--
                ⭐ মোট পরিমাণের প্রতিটা একক নিজের লাইনে — মালিকের ছবি, ৩ অক্টোবর ২০২৬ (S-0001, Special for DB): পণ্যের নাম
                ২–৪ লাইনে ভাঙছিল, অথচ QTY আর Total QTY কলামে বড় ফাঁকা। ⓘ কারণ এই সারিটা: "168 Ctn, 7 Mbag" `nowrap`-এ এক
                লাইনে, তাই mPDF গোটা কলামটা ওই মাপে চওড়া করত — প্রতিটা সারিতে "24 Ctn"-এর বাঁয়ে খালি জায়গা। এখন
                "168 Ctn" / "7 Mbag" দুই লাইনে, কলাম সাধারণ সারির মাপে, বাকি জায়গা নামের।
            --}}
            @php($stack = fn (string $units) => implode('<br>', array_map('e', array_map('trim', explode(',', $units)))))
            <tr class="grand" data-grand-row>
                <td></td>
                <td>{{ $h('grand_total') }}</td>
                <td></td>
                <td class="num">{!! $stack((string) $facts['items']['totals']['qty']) !!}</td>
                @if ($v->free)<td class="num">{!! $stack((string) $facts['items']['totals']['free']) !!}</td>@endif
                @if ($v->totalQty)<td class="num">{!! $stack((string) $facts['items']['totals']['total_qty']) !!}</td>@endif
                {{-- ⓘ `grand-amount` — নকশা চাইলে কেবল টাকার ঘরটা আলাদা করে ("Special for DB", ৩ অক্টোবর ২০২৬) --}}
                <td class="num grand-amount">{{ $paper->money($facts['items']['totals']['amount']) }}</td>
            </tr>
        @endif
    </tbody>
</table>
