{{--
    ১০ · আগে হিসাব — মাথার নিচেই চারটা বড় ঘর: নিট প্রদেয় · জমা · আগের বকেয়া · মোট বকেয়া; তারপর বাকি সব।
    নমুনা: Design canvas, ১০। ⓘ সুইচ আর `data-*` চিহ্ন ভাগের partial-এ ([[InvoicePaperView]])।
    ⚠️ "আগের বকেয়া" সুইচ বন্ধ হলে উপরের শেষ দুই ঘরও যায় — নিচের সারির সাথে এক নিয়ম।
--}}
@php
    $v = new \App\Modules\Sales\Support\InvoicePaperView($doc, $facts, $company, $profile);
    $accent = '#4b3fa8';
    $cards = [
        [$v->label('net_payable'), $v->sums['net_payable'], '#f1effb', $accent, '#1a1c23'],
        [$v->label('paid'), $v->sums['paid'], '#eef7f1', '#1f6b3a', '#1a1c23'],
    ];
    if ($v->shows('previous_due')) {
        $cards[] = [$v->label('previous_due'), $v->sums['previous_due'], '#f4f5f7', '#5d6773', '#1a1c23'];
        $cards[] = [$v->label('total_due'), $v->sums['outstanding'], $accent, '#d9d4f7', '#ffffff'];
    }
    $cardWidth = round(100 / count($cards), 2);
@endphp

<style @nonce>
    body { font-family: hindsiliguri, sans-serif; font-size: 9pt; color: #18212b; }
    table { border-collapse: collapse; }
    table.head { width: 100%; }
    table.head td { vertical-align: middle; }
    .mark { width: 12mm; height: 12mm; background: {{ $accent }}; color: #fff; text-align: center; font-weight: bold; font-size: 12pt; }
    .co-name { font-size: 15pt; font-weight: bold; }
    .co-meta { font-size: 7.5pt; color: #5d6773; }
    .title { text-align: right; font-size: 9pt; font-weight: bold; letter-spacing: 1mm; color: {{ $accent }}; }
    .no { text-align: right; font-size: 15pt; font-weight: bold; font-family: dejavusans; }
    .dup { text-align: right; font-size: 7.5pt; font-weight: bold; color: #5d6773; }
    .notice { text-align: center; font-weight: bold; border: 0.4mm solid #b42318; color: #b42318; padding: 2mm; margin-top: 3mm; font-size: 11pt; }
    table.cards { width: 100%; margin-top: 5mm; }
    table.cards td { padding: 3mm; vertical-align: top; }
    .card-cap { font-size: 7pt; font-weight: bold; }
    .card-val { font-size: 13pt; font-weight: bold; font-family: dejavusans; margin-top: 1mm; }
    table.two { width: 100%; margin-top: 4mm; }
    table.two td.box { width: 49%; border: 0.25mm solid #e3e6ea; padding: 2.5mm 3mm; vertical-align: top; font-size: 8.5pt; }
    .cap { font-size: 7pt; font-weight: bold; color: {{ $accent }}; }
    .party { font-weight: bold; }
    .sub { font-size: 7.5pt; color: #5d6773; font-weight: normal; }
    table.items { width: 100%; margin-top: 4mm; }
    table.items th { font-size: 7pt; font-weight: bold; color: #5d6773; padding: 0 1.5mm 1.5mm 1.5mm; text-align: left; border-bottom: 0.6mm solid #18212b; }
    table.items th.num { text-align: right; }
    table.items td { padding: 2mm 1.5mm; border-bottom: 0.25mm solid #e3e6ea; font-size: 9pt; vertical-align: top; }
    table.items tr.grand td { font-weight: bold; border-bottom: 0; }
    .free { color: {{ $accent }}; font-weight: bold; }
    .num { text-align: right; white-space: nowrap; }
    td.num { font-family: dejavusans; }
    table.bottom { width: 100%; margin-top: 4mm; }
    table.bottom td.side { vertical-align: top; font-size: 8.5pt; }
    .pay-head { font-size: 7pt; font-weight: bold; color: {{ $accent }}; }
    table.pay { width: 100%; margin-top: 1mm; }
    table.pay th { font-size: 7pt; color: #5d6773; text-align: left; padding: 0.8mm 1mm; }
    table.pay td { font-size: 8pt; padding: 0.8mm 1mm; border-top: 0.2mm solid #e3e6ea; }
    table.sums { width: 100%; }
    table.sums td { padding: 1.1mm 1.5mm; font-size: 9pt; }
    table.sums tr.net td { font-weight: bold; border-top: 0.3mm solid #18212b; }
    table.sums tr.owed td { font-weight: bold; color: {{ $accent }}; }
    .words { margin-top: 3mm; font-size: 8.5pt; }
    .footnote { margin-top: 3mm; font-size: 9pt; font-weight: bold; color: #b42318; }
    table.signatures { width: 100%; margin-top: 14mm; }
    table.signatures td { text-align: center; padding: 0 5mm; font-size: 9pt; }
    .sig-line { border-top: 0.25mm solid #18212b; padding-top: 1mm; }
    .printed { margin-top: 4mm; font-size: 7pt; color: #5d6773; }
</style>

<table class="head">
    <tr>
        <td>
            <table>
                <tr>
                    <td style="padding-right: 3mm">
                        @if ($v->logo)
                            <img src="{{ $v->logo }}" style="height: 12mm;" alt="">
                        @else
                            <div class="mark">{{ mb_strtoupper(mb_substr($v->head['name'], 0, 2)) }}</div>
                        @endif
                    </td>
                    <td>
                        <div class="co-name">{{ $v->head['name'] }}</div>
                        @include('sales::print.partials.invoice-company', ['v' => $v])
                    </td>
                </tr>
            </table>
        </td>
        <td style="width: 60mm">
            <div class="title">{{ mb_strtoupper($v->en('heading')) }}</div>
            <div class="no">{{ $facts['bill']['bill_no'] }}</div>
            <div class="dup">{{ $facts['bill']['bill_date'] }}@if ($v->shows('order_no')) · <span data-order-no>{{ $facts['bill']['order_no'] }}</span>@endif @if ($v->shows('invoice_type')) · <span data-invoice-type>{{ $facts['bill']['type'] }}</span>@endif</div>
            @if ($v->duplicate)<div class="dup" data-duplicate>{{ $v->en('duplicate') }}</div>@endif
        </td>
    </tr>
</table>

@if ($v->notices !== [])<div class="notice">{{ implode(' · ', $v->notices) }}</div>@endif

<table class="cards">
    <tr>
        @foreach ($cards as [$label, $amount, $bg, $capColor, $valColor])
            <td style="width: {{ $cardWidth }}%; background: {{ $bg }};">
                <div class="card-cap" style="color: {{ $capColor }}">{{ mb_strtoupper($label) }}</div>
                <div class="card-val" style="color: {{ $valColor }}">{{ $paper->money($amount) }}</div>
            </td>
            @if (! $loop->last)<td style="width: 1.5mm; padding: 0"></td>@endif
        @endforeach
    </tr>
</table>

<table class="two">
    <tr>
        <td class="box">@include('sales::print.partials.invoice-bill-to', ['v' => $v, 'facts' => $facts])</td>
        <td style="width: 2%"></td>
        <td class="box">@include('sales::print.partials.invoice-transport', ['v' => $v, 'facts' => $facts])</td>
    </tr>
</table>

@include('sales::print.partials.invoice-items', ['v' => $v, 'facts' => $facts, 'paper' => $paper])

<table class="bottom">
    <tr>
        <td class="side" style="width: 57%; padding-right: 6mm">
            @if ($v->shows('amount_words'))<div data-words><strong>{{ $v->en('in_words') }}</strong> {{ $facts['words'] }}</div>@endif
            <div style="margin-top: 2mm">@include('sales::print.partials.invoice-payments', ['v' => $v, 'doc' => $doc])</div>
            <div style="margin-top: 3mm; text-align: left">@include('sales::print.partials.invoice-qr', ['v' => $v])</div>
        </td>
        <td class="side">@include('sales::print.partials.invoice-sums', ['v' => $v, 'paper' => $paper])</td>
    </tr>
</table>

<div class="footnote"><div style="text-align: left; font-size: 50%; line-height: 1.35">{!! nl2br(e($v->footnote)) !!}</div></div>
@include('sales::print.partials.invoice-signatures', ['v' => $v])
<div class="printed">{{ $v->printedAt() }}</div>
