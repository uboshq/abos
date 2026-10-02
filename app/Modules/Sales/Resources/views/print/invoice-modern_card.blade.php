{{--
    ১৮ · আধুনিক কার্ড — সবার উপরে বড় করে "কত দিতে হবে": মোট বকেয়া; তার নিচে কাকে-কোথা থেকে-কোন গাড়িতে,
    তারপর পণ্য আর হিসাব আলাদা ঘরে। আজকের নামী অনলাইন বিলের ধাঁচ। নমুনা: Design canvas, ১৮।
    ⓘ সুইচ আর `data-*` চিহ্ন ভাগের partial-এ ([[InvoicePaperView]])। ⚠️ "আগের বকেয়া" বন্ধ হলে উপরে দেখায় এই বিলের বকেয়া।
--}}
@php
    $v = new \App\Modules\Sales\Support\InvoicePaperView($doc, $facts, $company, $profile);
    $accent = '#4338ca';
    $headline = $v->shows('previous_due') ? $v->sums['outstanding'] : $v->sums['invoice_due'];
    $headlineLabel = $v->shows('previous_due') ? $v->label('total_due') : $v->label('invoice_due');
@endphp

<style @nonce>
    body { font-family: hindsiliguri, sans-serif; font-size: 9pt; color: #1a1c23; }
    table { border-collapse: collapse; }
    .card { border: 0.3mm solid #e4e6ee; padding: 5mm 6mm; margin-bottom: 3mm; }
    table.head { width: 100%; }
    table.head td { vertical-align: middle; }
    .mark { width: 9mm; height: 9mm; background: {{ $accent }}; color: #fff; text-align: center; font-weight: bold; font-size: 10pt; }
    .co-name { font-size: 12pt; font-weight: bold; }
    .co-meta { font-size: 7.5pt; color: #6b7080; }
    .ref { text-align: right; font-size: 8.5pt; color: #6b7080; }
    .dup { text-align: right; font-size: 7.5pt; font-weight: bold; color: #6b7080; }
    .big-cap { margin-top: 5mm; font-size: 9pt; color: #6b7080; }
    .big { font-size: 28pt; font-weight: bold; font-family: dejavusans; letter-spacing: -0.5mm; }
    .big-sub { font-size: 8pt; color: #6b7080; }
    .notice { text-align: center; font-weight: bold; border: 0.4mm solid #b42318; color: #b42318; padding: 2mm; margin-top: 3mm; font-size: 11pt; }
    table.three { width: 100%; margin-top: 5mm; }
    table.three td { width: 33.3%; vertical-align: top; font-size: 8.5pt; line-height: 1.45; padding-right: 3mm; }
    .cap { font-size: 7.5pt; color: #6b7080; }
    .party { font-weight: bold; }
    .sub { font-size: 7.5pt; color: #6b7080; font-weight: normal; }
    table.items { width: 100%; }
    table.items th { font-size: 7.5pt; color: #6b7080; font-weight: normal; padding: 0 1.5mm 2mm 1.5mm; text-align: left; border-bottom: 0.25mm solid #eceef3; }
    table.items th.num { text-align: right; }
    table.items td { padding: 2.6mm 1.5mm; border-bottom: 0.25mm solid #eceef3; font-size: 9pt; vertical-align: top; }
    table.items tr.grand td { font-weight: bold; border-bottom: 0; }
    .free { color: {{ $accent }}; font-weight: bold; }
    .num { text-align: right; white-space: nowrap; }
    td.num { font-family: dejavusans; }
    table.bottom { width: 100%; margin-top: 3mm; }
    table.bottom td.side { vertical-align: top; font-size: 8.5pt; }
    .pay-head { font-size: 7.5pt; color: #6b7080; }
    table.pay { width: 100%; margin-top: 1mm; }
    table.pay th { font-size: 7pt; color: #6b7080; font-weight: normal; text-align: left; padding: 0.8mm 1mm; }
    table.pay td { font-size: 8.5pt; padding: 0.8mm 1mm; }
    table.sums { width: 100%; }
    table.sums td { padding: 1.2mm 1mm; font-size: 9pt; }
    table.sums tr.net td { font-weight: bold; }
    table.sums tr.owed td { font-weight: bold; font-size: 10.5pt; border-top: 0.25mm solid #eceef3; padding-top: 2.2mm; color: {{ $accent }}; }
    .words { margin-top: 2mm; font-size: 8.5pt; color: #6b7080; }
    .footnote { font-size: 9pt; font-weight: bold; color: #b42318; }
    table.signatures { width: 100%; margin-top: 12mm; }
    table.signatures td { text-align: center; padding: 0 5mm; font-size: 9pt; }
    .sig-line { border-top: 0.25mm solid #1a1c23; padding-top: 1mm; }
    .printed { margin-top: 2mm; text-align: center; font-size: 7pt; color: #6b7080; }
</style>

<div class="card">
    <table class="head">
        <tr>
            <td>
                <table><tr>
                    <td style="padding-right: 2.5mm">@if ($v->logo)<img src="{{ $v->logo }}" style="height: 9mm;" alt="">@else<div class="mark">{{ mb_strtoupper(mb_substr($v->head['name'], 0, 2)) }}</div>@endif</td>
                    <td><div class="co-name">{{ $v->head['name'] }}</div></td>
                </tr></table>
            </td>
            <td>
                <div class="ref">{{ ucfirst(mb_strtolower($v->en('heading'))) }} {{ $facts['bill']['bill_no'] }} · {{ $facts['bill']['bill_date'] }}</div>
                @if ($v->duplicate)<div class="dup" data-duplicate>{{ $v->en('duplicate') }}</div>@endif
            </td>
        </tr>
    </table>

    <div class="big-cap">{{ $headlineLabel }}</div>
    <div class="big"><span style="font-family: hindsiliguri">৳</span> {{ $paper->money($headline) }}</div>
    <div class="big-sub">{{ $v->label('invoice_due') }} {{ $paper->money($v->sums['invoice_due']) }}@if ($v->shows('previous_due')) · {{ $v->label('previous_due') }} {{ $paper->money($v->sums['previous_due']) }}@endif</div>

    @if ($v->notices !== [])<div class="notice">{{ implode(' · ', $v->notices) }}</div>@endif

    <table class="three">
        <tr>
            <td>@include('sales::print.partials.invoice-bill-to', ['v' => $v, 'facts' => $facts])</td>
            <td><div class="cap">{{ mb_strtoupper(__('sales::invoice_design.details', [], 'en')) }}</div>@include('sales::print.partials.invoice-company', ['v' => $v])@if ($v->shows('order_no'))<div data-order-no>{{ $v->en('order_no') }} {{ $facts['bill']['order_no'] }}</div>@endif @if ($v->shows('invoice_type'))<div data-invoice-type>{{ $v->en('type') }} {{ $facts['bill']['type'] }}</div>@endif</td>
            <td>@include('sales::print.partials.invoice-transport', ['v' => $v, 'facts' => $facts])</td>
        </tr>
    </table>
</div>

<div class="card">
    @include('sales::print.partials.invoice-items', ['v' => $v, 'facts' => $facts, 'paper' => $paper, 'upper' => false])

    <table class="bottom">
        <tr>
            <td class="side" style="width: 52%; padding-right: 6mm">
                @include('sales::print.partials.invoice-payments', ['v' => $v, 'doc' => $doc])
                @if ($v->shows('amount_words'))<div class="words" data-words>{{ $facts['words'] }}</div>@endif
                <div style="margin-top: 3mm; text-align: left">@include('sales::print.partials.invoice-qr', ['v' => $v])</div>
            </td>
            <td class="side">@include('sales::print.partials.invoice-sums', ['v' => $v, 'paper' => $paper])</td>
        </tr>
    </table>
</div>

<div class="card">
    <div class="footnote">{!! nl2br(e($v->footnote)) !!}</div>
    @include('sales::print.partials.invoice-signatures', ['v' => $v])
</div>
<div class="printed">{{ $v->printedAt() }}</div>
