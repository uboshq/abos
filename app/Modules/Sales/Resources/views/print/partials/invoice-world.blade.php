{{--
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
    body { font-family: hindsiliguri, sans-serif; font-size: 9pt; color: #222; }
    table { border-collapse: collapse; }
    .co-name { font-size: 16pt; font-weight: bold; color: #111; }
    .co-meta { font-size: 8pt; color: #555; line-height: 1.4; }
    .title { text-align: right; font-size: 26pt; font-weight: bold; color: #333; letter-spacing: 0.6mm; }
    .dup { text-align: right; font-size: 7.5pt; font-weight: bold; color: #555; }
    .notice { text-align: center; font-weight: bold; border: 0.4mm solid #b42318; color: #b42318; padding: 2mm; margin-top: 3mm; font-size: 11pt; }
    table.meta-box { border: 0.3mm solid #999; margin-left: auto; margin-top: 2mm; }
    table.meta-box td { padding: 1.4mm 3mm; font-size: 8.5pt; border-bottom: 0.2mm solid #ddd; }
    table.meta-box td.k { background: #eeeeee; font-weight: bold; color: #333; }
    table.meta-box td.v { text-align: right; min-width: 32mm; }
    table.parties { width: 100%; margin-top: 7mm; }
    table.parties td { width: 50%; vertical-align: top; font-size: 8.8pt; line-height: 1.5; }
    .band { background: #e6e6e6; font-weight: bold; font-size: 8pt; letter-spacing: 0.3mm; padding: 1.4mm 2.5mm; color: #222; }
    .party { font-weight: bold; font-size: 10.5pt; margin-top: 1.5mm; }
    .sub { font-size: 7.5pt; color: #555; font-weight: normal; }
    table.items { width: 100%; margin-top: 6mm; border: 0.3mm solid #999; }
    table.items th { background: #e6e6e6; font-size: 8pt; font-weight: bold; padding: 2mm; text-align: left; color: #222; border-bottom: 0.3mm solid #999; }
    table.items th.num { text-align: right; }
    table.items td { padding: 2mm; border-bottom: 0.2mm solid #dddddd; font-size: 9pt; vertical-align: top; }
    table.items tr.alt td { background: #f7f7f7; }
    table.items tr.grand td { font-weight: bold; border-top: 0.3mm solid #999; background: #f0f0f0; }
    .free { font-weight: bold; }
    .num { text-align: right; white-space: nowrap; }
    td.num { font-family: dejavusans; }
    table.bottom { width: 100%; margin-top: 5mm; }
    table.bottom td.side { vertical-align: top; font-size: 8.5pt; }
    .pay-head { font-size: 8pt; font-weight: bold; color: #333; }
    table.pay { width: 100%; margin-top: 1mm; }
    table.pay th { font-size: 7.5pt; text-align: left; padding: 1mm; background: #eeeeee; }
    table.pay td { font-size: 8.5pt; padding: 1mm; border-bottom: 0.2mm solid #ddd; }
    table.sums { width: 100%; border: 0.3mm solid #999; }
    table.sums td { padding: 1.4mm 2.5mm; font-size: 9pt; }
    table.sums tr.net td { font-weight: bold; border-top: 0.3mm solid #999; }
    table.sums tr.owed td { background: #333; color: #fff; font-weight: bold; font-size: 10.5pt; padding: 2.2mm 2.5mm; }
    .words { margin-top: 2mm; font-size: 8.5pt; }
    .terms { margin-top: 5mm; font-size: 8pt; color: #444; border-top: 0.3mm solid #999; padding-top: 2mm; }
    .thanks { text-align: center; font-size: 11pt; font-weight: bold; color: #333; margin-top: 5mm; }
    table.signatures { width: 100%; margin-top: 12mm; }
    table.signatures td { text-align: center; padding: 0 5mm; font-size: 8.5pt; }
    .sig-line { border-top: 0.3mm solid #333; padding-top: 1mm; }
    .printed { margin-top: 3mm; font-size: 7pt; color: #777; text-align: center; }
</style>

<table style="width: 100%">
    <tr>
        <td style="vertical-align: top">
            @if ($v->logo)<img src="{{ $v->logo }}" style="height: 15mm;" alt=""><br>@endif
            <div class="co-name">{{ $v->head['name'] }}</div>
            @include('sales::print.partials.invoice-company', ['v' => $v])
        </td>
        <td style="width: 78mm; vertical-align: top">
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
        <td style="padding-right: 5mm">
            <div class="band">{{ mb_strtoupper($L('bill_to')) }}</div>
            <div class="party">{{ $lang === 'bn' ? '' : $v->en('ms').' ' }}{{ $b['name'] }}</div>
            @if (filled($b['point']))<div>{{ $L('point') }}: {{ $b['point'] }}</div>@endif
            @if (filled($b['address']))<div>{{ $b['address'] }}</div>@endif
            @if (filled($b['phone']))<div>{{ $L('phone') }}: {{ $b['phone'] }}</div>@endif
        </td>
        <td style="padding-left: 5mm">
            <div class="band">{{ mb_strtoupper($L('transport')) }}</div>
            @if ($v->shows('transport'))
                <div data-transport>
                    @if (filled($tr['carrier']))<div class="party">{{ $tr['carrier'] }}</div>@endif
                    @if (filled($tr['vehicle']))<div>{{ $L('vehicle') }}: {{ $tr['vehicle'] }}</div>@endif
                    @if (filled($tr['driver_phone']))<div>{{ $L('driver_phone') }}: {{ $tr['driver_phone'] }}</div>@endif
                    @if (filled($tr['delivery_date']))<div>{{ $L('delivery_date') }}: {{ $tr['delivery_date'] }}</div>@endif
                </div>
            @endif
            <div style="margin-top: 1mm">{{ $L('created_by') }}: {{ $facts['bill']['created_by'] }}</div>
        </td>
    </tr>
</table>

@include('sales::print.partials.invoice-items', ['v' => $v, 'facts' => $facts, 'paper' => $paper, 'upper' => $lang !== 'bn', 'zebra' => true, 'lang' => $lang])

<table class="bottom">
    <tr>
        <td class="side" style="width: 54%; padding-right: 8mm">
            @include('sales::print.partials.invoice-payments', ['v' => $v, 'doc' => $doc, 'lang' => $lang])
            @if ($v->shows('amount_words'))<div class="words" data-words><strong>{{ $L('in_words') }}</strong> {{ $lang === 'bn' ? \App\Core\Support\AmountInWords::of(str_replace(',', '', (string) $v->sums['net_payable']), 'bn') : $facts['words'] }}</div>@endif
            <div style="margin-top: 3mm">@include('sales::print.partials.invoice-qr', ['v' => $v, 'width' => '20mm'])</div>
        </td>
        <td class="side">@include('sales::print.partials.invoice-sums', ['v' => $v, 'paper' => $paper, 'lang' => $lang])</td>
    </tr>
</table>

<div class="terms"><strong>{{ __('sales::paper_design.terms', [], $lang) }}:</strong> <span style="font-family: hindsiliguri">{{ $v->footnote }}</span></div>
@include('sales::print.partials.invoice-signatures', ['v' => $v])
<div class="thanks">{{ $D('thanks') }}</div>
<div class="printed">{{ $v->printedAt() }}</div>
