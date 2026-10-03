{{--
    ৯ · বিল + কেটে রাখার অংশ — উপরে বিল; ড্যাশের দাগের নিচে ডিপোর কপি (ডেলিভারির প্রমাণ): গ্রাহক, মাল,
    প্রদেয়, মোট বকেয়া, আর গ্রহণকারী-চালক-গেটের সই। নমুনা: Design canvas, ৯।
    ⓘ সুইচ আর `data-*` চিহ্ন ভাগের partial-এ ([[InvoicePaperView]])। ⚠️ কাটার অংশে সইয়ের ঘর নিজের — ঐ তিনটা
    ঘর ডেলিভারির প্রমাণ, বিলের সইয়ের সুইচের নয়।
--}}
@php
    $v = new \App\Modules\Sales\Support\InvoicePaperView($doc, $facts, $company, $profile);
    $accent = '#0e6e8c';
@endphp

<style @nonce>
    body { font-family: hindsiliguri, sans-serif; font-size: 8.5pt; color: #1d2021; }
    table { border-collapse: collapse; }
    table.head { width: 100%; }
    table.head td { vertical-align: middle; }
    .co-name { font-size: 14pt; font-weight: bold; }
    .co-meta { font-size: 7pt; color: #5a6062; }
    .title { text-align: right; font-size: 16pt; font-weight: bold; color: {{ $accent }}; }
    .no { text-align: right; font-size: 9pt; }
    .dup { text-align: right; font-size: 7pt; font-weight: bold; }
    .notice { text-align: center; font-weight: bold; border: 0.4mm solid #b42318; color: #b42318; padding: 1.5mm; margin-top: 2mm; font-size: 10pt; }
    table.two { width: 100%; margin-top: 3mm; }
    table.two td.box { width: 49%; background: #eef6f9; padding: 2.5mm 3mm; vertical-align: top; font-size: 8pt; }
    .cap { font-size: 6.5pt; font-weight: bold; color: {{ $accent }}; }
    .party { font-weight: bold; }
    .sub { font-size: 7pt; color: #5a6062; font-weight: normal; }
    table.items { width: 100%; margin-top: 3mm; }
    table.items th { background: {{ $accent }}; color: #fff; font-size: 7pt; font-weight: bold; padding: 1.5mm; text-align: left; }
    table.items th.num { text-align: right; }
    table.items td { padding: 1.4mm 1.5mm; border-bottom: 0.2mm solid #dde6e9; font-size: 8.5pt; vertical-align: top; }
    table.items tr.grand td { font-weight: bold; border-bottom: 0; }
    .num { text-align: right; white-space: nowrap; }
    td.num { font-family: dejavusans; font-size: 8.5pt; }
    table.bottom { width: 100%; margin-top: 3mm; }
    table.bottom td.side { vertical-align: top; font-size: 8pt; }
    .pay-head { font-weight: bold; font-size: 7pt; }
    table.pay { width: 100%; margin-top: 0.8mm; }
    table.pay th { font-size: 6.5pt; color: #5a6062; text-align: left; padding: 0.6mm 1mm; }
    table.pay td { font-size: 7.5pt; padding: 0.6mm 1mm; }
    table.sums { width: 100%; }
    table.sums td { padding: 0.8mm 1.5mm; font-size: 8.5pt; }
    table.sums tr.net td { font-weight: bold; }
    table.sums tr.owed td { background: {{ $accent }}; color: #fff; font-weight: bold; }
    .footnote { margin-top: 2mm; font-size: 8pt; font-weight: bold; color: #b42318; }
    table.signatures { width: 100%; margin-top: 10mm; }
    table.signatures td { text-align: center; padding: 0 4mm; font-size: 8pt; }
    .sig-line { border-top: 0.25mm solid #1d2021; padding-top: 0.8mm; }
    .cut { margin-top: 6mm; border-top: 0.5mm dashed #8a9396; text-align: center; font-size: 7pt; color: #5a6062; padding-top: 1mm; }
    table.stub { width: 100%; margin-top: 3mm; }
    table.stub td { vertical-align: top; font-size: 8.5pt; padding-right: 3mm; }
    .stub-title { font-size: 10pt; font-weight: bold; color: {{ $accent }}; }
    table.stub-signs { width: 100%; margin-top: 12mm; }
    table.stub-signs td { width: 33.3%; text-align: center; padding: 0 4mm; font-size: 8pt; }
    .printed { margin-top: 2mm; font-size: 6.5pt; color: #5a6062; }
</style>

<table class="head">
    <tr>
        <td>
            <div class="co-name">{{ $v->head['name'] }}</div>
            @include('sales::print.partials.invoice-company', ['v' => $v])
        </td>
        <td>
            <div class="title">{{ $v->en('heading') }}</div>
            <div class="no">{{ $facts['bill']['bill_no'] }} · {{ $facts['bill']['bill_date'] }}@if ($v->shows('invoice_type')) · <span data-invoice-type>{{ $facts['bill']['type'] }}</span>@endif</div>
            @if ($v->duplicate)<div class="dup" data-duplicate>{{ $v->en('duplicate') }}</div>@endif
        </td>
    </tr>
</table>

@if ($v->notices !== [])<div class="notice">{{ implode(' · ', $v->notices) }}</div>@endif

<table class="two">
    <tr>
        <td class="box">@include('sales::print.partials.invoice-bill-to', ['v' => $v, 'facts' => $facts])</td>
        <td style="width: 2%"></td>
        <td class="box">
            @include('sales::print.partials.invoice-transport', ['v' => $v, 'facts' => $facts])
            @if ($v->shows('order_no'))<div data-order-no>{{ $v->en('order_no') }} {{ $facts['bill']['order_no'] }}</div>@endif
        </td>
    </tr>
</table>

@include('sales::print.partials.invoice-items', ['v' => $v, 'facts' => $facts, 'paper' => $paper])

<table class="bottom">
    <tr>
        <td class="side" style="width: 58%; padding-right: 5mm">
            @if ($v->shows('amount_words'))<div data-words><strong>{{ $v->en('in_words') }}</strong> {{ $facts['words'] }}</div>@endif
            <div style="margin-top: 1.5mm">@include('sales::print.partials.invoice-payments', ['v' => $v, 'doc' => $doc])</div>
            <div class="footnote"><div style="text-align: left; font-size: 50%; line-height: 1.35">{!! nl2br(e($v->footnote)) !!}</div></div>
        </td>
        <td class="side">@include('sales::print.partials.invoice-sums', ['v' => $v, 'paper' => $paper])</td>
    </tr>
</table>

@include('sales::print.partials.invoice-signatures', ['v' => $v])

<div class="cut">{{ __('sales::invoice_design.cut_here', [], 'bn') }}</div>

<table class="stub">
    <tr>
        <td colspan="3"><span class="stub-title">{{ mb_strtoupper($v->en('heading')) }} {{ $facts['bill']['bill_no'] }}</span> · {{ $facts['bill_to']['name'] }}</td>
        <td rowspan="2" style="width: 24mm; text-align: right">@include('sales::print.partials.invoice-qr', ['v' => $v, 'width' => '20mm'])</td>
    </tr>
    <tr>
        <td><div class="cap">{{ mb_strtoupper($v->label('total_delivery')) }}</div>{{ $facts['items']['totals']['total_qty'] ?: $facts['items']['totals']['qty'] }}</td>
        <td><div class="cap">{{ mb_strtoupper($v->label('net_payable')) }}</div>{{ $paper->money($v->sums['net_payable']) }}</td>
        <td><div class="cap">{{ mb_strtoupper($v->label('total_due')) }}</div>{{ $paper->money($v->sums['outstanding']) }}</td>
    </tr>
</table>

<table class="stub-signs">
    <tr>
        <td><div class="sig-line">{{ $v->bn('received_by') }}</div></td>
        <td><div class="sig-line">{{ __('core.print.driver', [], 'bn') }}</div></td>
        <td><div class="sig-line">{{ __('core.print.gate_officer', [], 'bn') }}</div></td>
    </tr>
</table>

<div class="printed">{{ $v->printedAt() }}</div>
