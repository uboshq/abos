{{--
    ⓘ A5 রূপ — A4-এর থেকে তৈরি (mm ×০.৭২, অক্ষর ×০.৮৫, সীমা ৬.৫pt; make_a5_new)। A4-এ বদলালে এটাও নতুন করে বানাতে হয়।
    ২৭ · মোনো ক্লাসিক হালকা — দুই ভাষার একটাই কাঠামো ($lang 'en' | 'bn')।
    মালিক, ৩০ সেপ্টেম্বর ২০২৬: *"mark kora kalo bracground bad diye ekta dio"* → *"ok etar bangla ekta eng ekta koro"*।
    ⓘ কালো ভরাট একটাও নেই — পণ্যের ছকের মাথা আর মোট বকেয়া মোটা দাগে আলাদা; কালি সবচেয়ে কম, ফটোকপিতে ঝাপসা হয় না।

    চাই: $doc, $facts, $company, $paper, $profile, $lang; ঐচ্ছিক $titleFont (ইংরেজি "INVOICE"-এর হরফ)।
    ⚠️ বাংলার শিরোনাম সবসময় হিন্দ শিলিগুড়ি — প্লেফেয়ার-জাতীয় লাতিন হরফে বাংলা অক্ষর নেই, দিলে বাক্স ছাপা হত।
    ⓘ সুইচ আর `data-*` চিহ্ন: বিল-যার-নামে, পরিবহন আর বিলের ঘর এখানে (দুই ভাষায়); ছক, টাকা, জমা, QR, সই ভাগের partial-এ।
--}}
@php
    $v = new \App\Modules\Sales\Support\InvoicePaperView($doc, $facts, $company, $profile);
    $lang = $lang ?? 'en';
    $bn = $lang === 'bn';
    $L = fn (string $key) => $v->label($key, $lang);
    $b = $facts['bill_to'];
    $tr = $facts['transport'];
    $bill = $facts['bill'];
    $titleCss = $bn ? 'font-family: hindsiliguri; font-size: 28.9pt; letter-spacing: 0;' : 'font-family: '.($titleFont ?? 'playfair').', freeserif; font-size: 32.3pt; letter-spacing: 1.44mm;';
@endphp

<style @nonce>
    body { font-family: hindsiliguri, sans-serif; font-size: 7.6pt; color: #111; }
    table { border-collapse: collapse; }
    .co-name { font-size: 12.8pt; font-weight: bold; color: #000; }
    .co-meta { font-size: 6.5pt; color: #555; }
    .title { text-align: right; font-weight: bold; color: #000; line-height: 1; }
    .dup { text-align: right; font-size: 6.5pt; font-weight: bold; margin-top: 0.72mm; }
    .notice { text-align: center; font-weight: bold; border: 0.36mm solid #000; padding: 1.44mm; margin-top: 2.16mm; font-size: 9.3pt; }
    .cap { font-size: {{ $bn ? '6.5pt' : '6.5pt' }}; font-weight: bold; letter-spacing: {{ $bn ? '0' : '0.36mm' }}; color: #555; }
    table.three { width: 100%; margin-top: 4.32mm; border-top: 1.01mm solid #000; }
    table.three td { width: 33%; vertical-align: top; font-size: 7.2pt; line-height: 1.5; padding: 2.16mm 3.6mm 0 0; }
    .party { font-weight: bold; font-size: 8.5pt; }
    .sub { font-size: 6.5pt; color: #555; font-weight: normal; }
    table.items { width: 100%; margin-top: 4.32mm; }
    table.items th { color: #000; font-size: {{ $bn ? '6.8pt' : '6.5pt' }}; font-weight: bold; letter-spacing: {{ $bn ? '0' : '0.22mm' }}; padding: 1.44mm; text-align: left; border-top: 0.72mm solid #000; border-bottom: 0.29mm solid #000; }
    table.items th.num { text-align: right; }
    table.items td { padding: 1.58mm 1.44mm; border-bottom: 0.14mm solid #c8c8c8; font-size: 7.6pt; vertical-align: top; }
    table.items tr.grand td { font-weight: bold; border-top: 0.43mm solid #000; border-bottom: 0.43mm solid #000; }
    .free { font-weight: bold; }
    .num { text-align: right; white-space: nowrap; }
    td.num { font-family: dejavusans; }
    table.bottom { width: 100%; margin-top: 3.6mm; }
    table.bottom td.side { vertical-align: top; font-size: 7.2pt; }
    .pay-head { font-size: 6.5pt; font-weight: bold; color: #555; }
    table.pay { width: 100%; margin-top: 0.72mm; }
    table.pay th { font-size: 6.5pt; color: #555; text-align: left; padding: 0.72mm; border-bottom: 0.22mm solid #000; }
    table.pay td { font-size: 7.2pt; padding: 0.72mm; border-bottom: 0.14mm solid #c8c8c8; }
    table.sums { width: 100%; }
    table.sums td { padding: 0.79mm 1.44mm; font-size: 7.6pt; }
    table.sums tr.net td { font-weight: bold; border-top: 0.22mm solid #000; }
    table.sums tr.owed td { font-weight: bold; font-size: 10.6pt; padding: 1.8mm 1.44mm; border-top: 0.72mm solid #000; border-bottom: 0.72mm solid #000; }
    .words { margin-top: 1.44mm; font-size: 7.2pt; }
    .footnote { margin-top: 2.88mm; font-size: 7.6pt; font-weight: bold; text-align: center; }
    table.signatures { width: 100%; margin-top: 10.08mm; }
    table.signatures td { text-align: center; padding: 0 3.6mm; font-size: 7.2pt; }
    .sig-line { border-top: 0.22mm solid #000; padding-top: 0.72mm; }
    table.foot { width: 100%; margin-top: 3.6mm; border-top: 0.22mm solid #000; }
    table.foot td { padding-top: 1.08mm; font-size: 6.5pt; color: #555; }
</style>

<table style="width: 100%">
    <tr>
        <td style="vertical-align: middle">
            <table><tr>
                @if ($v->logo)<td style="padding-right: 2.16mm; vertical-align: middle"><img src="{{ $v->logo }}" style="height: 10.08mm;" alt=""></td>@endif
                <td style="vertical-align: middle">
                    <div class="co-name">{{ $v->head['name'] }}</div>
                    @include('sales::print.partials.invoice-company', ['v' => $v])
                </td>
            </tr></table>
        </td>
        <td style="width: 51.84mm; vertical-align: middle">
            <div class="title" style="{{ $titleCss }}">{{ $bn ? $L('heading') : mb_strtoupper($L('heading')) }}</div>
            @if ($v->duplicate)<div class="dup" data-duplicate>{{ $bn ? $v->bn('duplicate') : $v->en('duplicate') }}</div>@endif
        </td>
    </tr>
</table>

@if ($v->notices !== [])<div class="notice">{{ implode(' · ', $v->notices) }}</div>@endif

<table class="three">
    <tr>
        <td>
            <div class="cap">{{ mb_strtoupper($L('bill_to')) }}</div>
            <div class="party">{{ $bn ? '' : $v->en('ms').' ' }}{{ $b['name'] }}</div>
            @if (filled($b['point']))<div>{{ $L('point') }}: {{ $b['point'] }}</div>@endif
            @if (filled($b['address']))<div>{{ $b['address'] }}</div>@endif
            @if (filled($b['phone']))<div>{{ $L('phone') }}: {{ $b['phone'] }}</div>@endif
        </td>
        <td>
            <div class="cap">{{ mb_strtoupper($L('transport')) }}</div>
            @if ($v->shows('transport'))
                <div data-transport>
                    @if (filled($tr['carrier']))<div class="party">{{ $tr['carrier'] }}</div>@endif
                    @if (filled($tr['vehicle']))<div>{{ $L('vehicle') }}: {{ $tr['vehicle'] }}</div>@endif
                    @if (filled($tr['driver_phone']))<div>{{ $L('driver_phone') }}: {{ $tr['driver_phone'] }}</div>@endif
                    @if (filled($tr['delivery_date']))<div>{{ $L('delivery_date') }}: {{ $tr['delivery_date'] }}</div>@endif
                </div>
            @endif
        </td>
        <td style="padding-right: 0">
            {{-- ⭐ বিলের ঘর সব এখানে — নম্বর, তারিখ, অর্ডার, ধরন, তৈরি --}}
            <div class="cap">{{ mb_strtoupper((string) __('sales::invoice_design.details', [], $lang)) }}</div>
            <div>{{ $L('bill_no') }}: <strong>{{ $bill['bill_no'] }}</strong></div>
            <div>{{ $L('bill_date') }}: {{ $bill['bill_date'] }}</div>
            @if ($v->shows('order_no'))<div data-order-no>{{ $L('order_no') }}: {{ $bill['order_no'] }}</div>@endif
            @if ($v->shows('invoice_type'))<div data-invoice-type>{{ $L('type') }}: <strong>{{ $bill['type'] }}</strong></div>@endif
            <div>{{ $L('created_by') }}: {{ $bill['created_by'] }}</div>
        </td>
    </tr>
</table>

@include('sales::print.partials.invoice-items', ['v' => $v, 'facts' => $facts, 'paper' => $paper, 'upper' => ! $bn, 'lang' => $lang, 'narrow' => true])

<table class="bottom">
    <tr>
        <td class="side" style="width: 52%; padding-right: 5.76mm">
            @include('sales::print.partials.invoice-payments', ['v' => $v, 'doc' => $doc, 'lang' => $lang])
            @if ($v->shows('amount_words'))
                <div class="words" data-words><strong>{{ $L('in_words') }}</strong>
                    {{ $bn ? \App\Core\Support\AmountInWords::of(str_replace(',', '', (string) $v->sums['net_payable']), 'bn') : $facts['words'] }}</div>
            @endif
            <div style="margin-top: 2.88mm">@include('sales::print.partials.invoice-qr', ['v' => $v, 'width' => '16mm'])</div>
        </td>
        <td class="side">@include('sales::print.partials.invoice-sums', ['v' => $v, 'paper' => $paper, 'lang' => $lang])</td>
    </tr>
</table>

<div class="footnote">{!! nl2br(e($v->footnote)) !!}</div>
@include('sales::print.partials.invoice-signatures', ['v' => $v])

<table class="foot">
    <tr>
        <td>{{ $v->head['name'] }}@if ($v->head['phone'] !== '') · {{ $v->head['phone'] }}@endif</td>
        <td style="text-align: right">{{ $v->printedAt() }}</td>
    </tr>
</table>
