{{--
    ⓘ A5 রূপ — A4-এর থেকে তৈরি (mm ×০.৭২, অক্ষর ×০.৮৫, সীমা ৬.৫pt; make_a5_new)। A4-এ বদলালে এটাও নতুন করে বানাতে হয়।
    বিশ্ব-মানক বিল — সারা বিশ্বে সবচেয়ে বেশি ব্যবহৃত গড়ন (Word/Zoho/Invoice Simple-এর চেনা "পেশাদার" ছাঁচ):
    উপরে বাঁয়ে লোগো-কোম্পানি, ডানে "INVOICE" আর ঘেরা ঘরে নম্বর-তারিখ; "বিল যার নামে" আর "পাঠানো হবে" পাশাপাশি;
    হালকা ধূসর মাথার ছক; ডানে নিচে মোটের বাক্স; নিচে শর্ত আর ধন্যবাদ।
    মালিক, ৩০ সেপ্টেম্বর ২০২৬: *"ro ekta daw sobcheye jonoprio sara bisshe bangla & eng duti vartion e dibe"*।

    চাই: $doc, $facts, $company, $paper, $profile, আর $lang ('en' | 'bn') — দুই ছাঁচ এটাকেই দুই ভাষায় ডাকে।
    ⓘ সুইচ আর `data-*` চিহ্ন ভাগের partial-এ ([[InvoicePaperView]]); ঘরের নাম `$v->label($key, $lang)` থেকে।
    ⚠️ রং কেবল কালো-ধূসর — রঙিন আর সাদা-কালো দুই প্রিন্টারেই একই চেহারা।
--}}
@php
    $v = new \App\Modules\Sales\Support\InvoicePaperView($doc, $facts, $company, $profile);
    $lang = $lang ?? 'en';
    $L = fn (string $key) => $v->label($key, $lang);
    $D = fn (string $key) => (string) __('sales::invoice_design.'.$key, [], $lang);
    $b = $facts['bill_to'];
    $tr = $facts['transport'];
@endphp

<style @nonce>
    body { font-family: hindsiliguri, sans-serif; font-size: 7.6pt; color: #222; }
    table { border-collapse: collapse; }
    .co-name { font-size: 13.6pt; font-weight: bold; color: #111; }
    .co-meta { font-size: 6.8pt; color: #555; line-height: 1.4; }
    .title { text-align: right; font-size: 22.1pt; font-weight: bold; color: #333; letter-spacing: 0.43mm; }
    .dup { text-align: right; font-size: 6.5pt; font-weight: bold; color: #555; }
    .notice { text-align: center; font-weight: bold; border: 0.29mm solid #b42318; color: #b42318; padding: 1.44mm; margin-top: 2.16mm; font-size: 9.3pt; }
    table.meta-box { border: 0.22mm solid #999; margin-left: auto; margin-top: 1.44mm; }
    table.meta-box td { padding: 1.01mm 2.16mm; font-size: 7.2pt; border-bottom: 0.14mm solid #ddd; }
    table.meta-box td.k { background: #eeeeee; font-weight: bold; color: #333; }
    table.meta-box td.v { text-align: right; min-width: 23.04mm; }
    table.parties { width: 100%; margin-top: 5.04mm; }
    table.parties td { width: 50%; vertical-align: top; font-size: 7.5pt; line-height: 1.5; }
    .band { background: #e6e6e6; font-weight: bold; font-size: 6.8pt; letter-spacing: 0.22mm; padding: 1.01mm 1.8mm; color: #222; }
    .party { font-weight: bold; font-size: 8.9pt; margin-top: 1.08mm; }
    .sub { font-size: 6.5pt; color: #555; font-weight: normal; }
    table.items { width: 100%; margin-top: 4.32mm; border: 0.22mm solid #999; }
    table.items th { background: #e6e6e6; font-size: 6.8pt; font-weight: bold; padding: 1.44mm; text-align: left; color: #222; border-bottom: 0.22mm solid #999; }
    table.items th.num { text-align: right; }
    table.items td { padding: 1.44mm; border-bottom: 0.14mm solid #dddddd; font-size: 7.6pt; vertical-align: top; }
    table.items tr.alt td { background: #f7f7f7; }
    table.items tr.grand td { font-weight: bold; border-top: 0.22mm solid #999; background: #f0f0f0; }
    .free { font-weight: bold; }
    .num { text-align: right; white-space: nowrap; }
    td.num { font-family: dejavusans; }
    table.bottom { width: 100%; margin-top: 3.6mm; }
    table.bottom td.side { vertical-align: top; font-size: 7.2pt; }
    .pay-head { font-size: 6.8pt; font-weight: bold; color: #333; }
    table.pay { width: 100%; margin-top: 0.72mm; }
    table.pay th { font-size: 6.5pt; text-align: left; padding: 0.72mm; background: #eeeeee; }
    table.pay td { font-size: 7.2pt; padding: 0.72mm; border-bottom: 0.14mm solid #ddd; }
    table.sums { width: 100%; border: 0.22mm solid #999; }
    table.sums td { padding: 1.01mm 1.8mm; font-size: 7.6pt; }
    table.sums tr.net td { font-weight: bold; border-top: 0.22mm solid #999; }
    table.sums tr.owed td { background: #333; color: #fff; font-weight: bold; font-size: 8.9pt; padding: 1.58mm 1.8mm; }
    .words { margin-top: 1.44mm; font-size: 7.2pt; }
    .terms { margin-top: 3.6mm; font-size: 6.8pt; color: #444; border-top: 0.22mm solid #999; padding-top: 1.44mm; }
    .thanks { text-align: center; font-size: 9.3pt; font-weight: bold; color: #333; margin-top: 3.6mm; }
    table.signatures { width: 100%; margin-top: 8.64mm; }
    table.signatures td { text-align: center; padding: 0 3.6mm; font-size: 7.2pt; }
    .sig-line { border-top: 0.22mm solid #333; padding-top: 0.72mm; }
    .printed { margin-top: 2.16mm; font-size: 6.5pt; color: #777; text-align: center; }
</style>

<table style="width: 100%">
    <tr>
        <td style="vertical-align: top">
            @if ($v->logo)<img src="{{ $v->logo }}" style="height: 10.8mm;" alt=""><br>@endif
            <div class="co-name">{{ $v->head['name'] }}</div>
            @include('sales::print.partials.invoice-company', ['v' => $v])
        </td>
        <td style="width: 56.16mm; vertical-align: top">
            <div class="title">{{ $lang === 'bn' ? $L('heading') : mb_strtoupper($L('heading')) }}</div>
            @if ($v->duplicate)<div class="dup" data-duplicate>{{ $lang === 'bn' ? $v->bn('duplicate') : $v->en('duplicate') }}</div>@endif
            <table class="meta-box">
                <tr><td class="k">{{ $L('bill_no') }}</td><td class="v"><strong>{{ $facts['bill']['bill_no'] }}</strong></td></tr>
                <tr><td class="k">{{ $L('bill_date') }}</td><td class="v">{{ $facts['bill']['bill_date'] }}</td></tr>
                @if ($v->shows('order_no'))<tr data-order-no><td class="k">{{ $L('order_no') }}</td><td class="v">{{ $facts['bill']['order_no'] }}</td></tr>@endif
                @if ($v->shows('invoice_type'))<tr data-invoice-type><td class="k">{{ $L('type') }}</td><td class="v"><strong>{{ $facts['bill']['type'] }}</strong></td></tr>@endif
            </table>
        </td>
    </tr>
</table>

@if ($v->notices !== [])<div class="notice">{{ implode(' · ', $v->notices) }}</div>@endif

<table class="parties">
    <tr>
        <td style="padding-right: 3.6mm">
            <div class="band">{{ mb_strtoupper($L('bill_to')) }}</div>
            <div class="party">{{ $lang === 'bn' ? '' : $v->en('ms').' ' }}{{ $b['name'] }}</div>
            @if (filled($b['point']))<div>{{ $L('point') }}: {{ $b['point'] }}</div>@endif
            @if (filled($b['address']))<div>{{ $b['address'] }}</div>@endif
            @if (filled($b['phone']))<div>{{ $L('phone') }}: {{ $b['phone'] }}</div>@endif
        </td>
        <td style="padding-left: 3.6mm">
            <div class="band">{{ mb_strtoupper($L('transport')) }}</div>
            @if ($v->shows('transport'))
                <div data-transport>
                    @if (filled($tr['carrier']))<div class="party">{{ $tr['carrier'] }}</div>@endif
                    @if (filled($tr['vehicle']))<div>{{ $L('vehicle') }}: {{ $tr['vehicle'] }}</div>@endif
                    @if (filled($tr['driver_phone']))<div>{{ $L('driver_phone') }}: {{ $tr['driver_phone'] }}</div>@endif
                    @if (filled($tr['delivery_date']))<div>{{ $L('delivery_date') }}: {{ $tr['delivery_date'] }}</div>@endif
                </div>
            @endif
            <div style="margin-top: 0.72mm">{{ $L('created_by') }}: {{ $facts['bill']['created_by'] }}</div>
        </td>
    </tr>
</table>

@include('sales::print.partials.invoice-items', ['v' => $v, 'facts' => $facts, 'paper' => $paper, 'upper' => $lang !== 'bn', 'zebra' => true, 'lang' => $lang, 'narrow' => true])

<table class="bottom">
    <tr>
        <td class="side" style="width: 54%; padding-right: 5.76mm">
            @include('sales::print.partials.invoice-payments', ['v' => $v, 'doc' => $doc, 'lang' => $lang])
            @if ($v->shows('amount_words'))<div class="words" data-words><strong>{{ $L('in_words') }}</strong> {{ $lang === 'bn' ? \App\Core\Support\AmountInWords::of(str_replace(',', '', (string) $v->sums['net_payable']), 'bn') : $facts['words'] }}</div>@endif
            <div style="margin-top: 2.16mm">@include('sales::print.partials.invoice-qr', ['v' => $v, 'width' => '16mm'])</div>
        </td>
        <td class="side">@include('sales::print.partials.invoice-sums', ['v' => $v, 'paper' => $paper, 'lang' => $lang])</td>
    </tr>
</table>

<div class="terms"><strong>{{ __('sales::paper_design.terms', [], $lang) }}:</strong> <span style="font-family: hindsiliguri">{{ $v->footnote }}</span></div>
@include('sales::print.partials.invoice-signatures', ['v' => $v])
<div class="thanks">{{ $D('thanks') }}</div>
<div class="printed">{{ $v->printedAt() }}</div>
