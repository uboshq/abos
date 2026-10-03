{{--
    ৮ · প্রিমিয়াম সোনালি — গাঢ় মাথা আর সোনালি রেখা, মোটের ব্লক গাঢ় ঘরে সোনালি অঙ্কে।
    নমুনা: Design canvas, ৮। ⓘ সুইচ আর `data-*` চিহ্ন ভাগের partial-এ ([[InvoicePaperView]])।
--}}
@php
    $v = new \App\Modules\Sales\Support\InvoicePaperView($doc, $facts, $company, $profile);
    $gold = '#b8913a';
    $dark = '#232120';
@endphp

<style @nonce>
    body { font-family: hindsiliguri, sans-serif; font-size: 9pt; color: #222120; }
    table { border-collapse: collapse; }
    table.band { width: 100%; background: {{ $dark }}; border-bottom: 1.2mm solid {{ $gold }}; }
    table.band td { padding: 6mm 7mm; vertical-align: bottom; color: #f3ead8; }
    .co-name { font-size: 19pt; font-weight: bold; }
    .co-meta { font-size: 7.5pt; color: #cfc3a8; }
    .title { text-align: right; font-size: 10pt; letter-spacing: 1.2mm; color: {{ $gold }}; }
    .no { text-align: right; font-size: 16pt; font-family: dejavusans; }
    .dup { text-align: right; font-size: 7.5pt; color: {{ $gold }}; font-weight: bold; }
    .notice { text-align: center; font-weight: bold; border: 0.4mm solid #b42318; color: #b42318; padding: 2mm; margin-top: 3mm; font-size: 11pt; }
    table.three { width: 100%; margin-top: 6mm; }
    table.three td { width: 33.3%; vertical-align: top; font-size: 8.5pt; line-height: 1.5; padding-right: 4mm; }
    .cap { font-size: 7pt; letter-spacing: 0.5mm; color: #9a7a2e; }
    .party { font-weight: bold; font-size: 11pt; }
    .sub { font-size: 7.5pt; color: #7a6f63; font-weight: normal; }
    table.items { width: 100%; margin-top: 6mm; }
    table.items th { font-size: 7pt; letter-spacing: 0.4mm; color: #9a7a2e; font-weight: normal; padding: 0 1.5mm 1.8mm 1.5mm; text-align: left; border-bottom: 0.3mm solid {{ $gold }}; }
    table.items th.num { text-align: right; }
    table.items td { padding: 2.5mm 1.5mm; border-bottom: 0.2mm solid #ece6d9; font-size: 9pt; vertical-align: top; }
    table.items tr.grand td { font-weight: bold; border-bottom: 0; }
    .free { color: #9a7a2e; font-weight: bold; }
    .num { text-align: right; white-space: nowrap; }
    td.num { font-family: dejavusans; }
    table.bottom { width: 100%; margin-top: 5mm; }
    table.bottom td.side { vertical-align: top; font-size: 8.5pt; }
    .pay-head { font-size: 7pt; letter-spacing: 0.5mm; color: #9a7a2e; }
    table.pay { width: 100%; margin-top: 1mm; }
    table.pay th { font-size: 7pt; color: #7a6f63; font-weight: normal; text-align: left; padding: 0.8mm 1mm; }
    table.pay td { font-size: 8pt; padding: 0.8mm 1mm; border-top: 0.2mm solid #ece6d9; }
    table.sums { width: 100%; background: {{ $dark }}; }
    table.sums td { padding: 1.4mm 4mm; font-size: 9pt; color: #f3ead8; }
    table.sums tr.net td { font-weight: bold; }
    table.sums tr.owed td { color: #e2c47a; font-weight: bold; font-size: 11pt; border-top: 0.3mm solid {{ $gold }}; padding-top: 2.5mm; padding-bottom: 3mm; }
    .words { margin-top: 3mm; font-size: 9pt; font-style: italic; }
    .footnote { margin-top: 3mm; font-size: 9pt; font-weight: bold; color: #b42318; }
    table.signatures { width: 100%; margin-top: 14mm; }
    table.signatures td { text-align: center; padding: 0 5mm; font-size: 9pt; }
    .sig-line { border-top: 0.3mm solid {{ $gold }}; padding-top: 1mm; }
    .printed { margin-top: 4mm; font-size: 7pt; color: #7a6f63; }
    .foot-rule { height: 1.2mm; background: {{ $gold }}; margin-top: 3mm; }
</style>

<table class="band">
    <tr>
        <td>
{{-- ⭐ লোগো নামের বাঁয়ে — মালিক, ৩০ সেপ্টেম্বর ২০২৬ (নমুনা PDF দেখে) --}}
<table><tr>
    @if ($v->logo)<td style="padding-right: 4mm; vertical-align: middle"><img src="{{ $v->logo }}" style="height: 14mm;" alt=""></td>@endif
    <td style="vertical-align: middle">
                <div class="co-name">{{ $v->head['name'] }}</div>
                @include('sales::print.partials.invoice-company', ['v' => $v])
    </td>
</tr></table>
        </td>
        <td style="width: 58mm">
            <div class="title">{{ mb_strtoupper($v->en('heading')) }}</div>
            <div class="no">{{ $facts['bill']['bill_no'] }}</div>
            @if ($v->duplicate)<div class="dup" data-duplicate>{{ $v->en('duplicate') }}</div>@endif
        </td>
    </tr>
</table>

@if ($v->notices !== [])<div class="notice">{{ implode(' · ', $v->notices) }}</div>@endif

<table class="three">
    <tr>
        <td>@include('sales::print.partials.invoice-bill-to', ['v' => $v, 'facts' => $facts])</td>
        <td>@include('sales::print.partials.invoice-transport', ['v' => $v, 'facts' => $facts])</td>
        <td><div class="cap">{{ mb_strtoupper(__('sales::invoice_design.details', [], 'en')) }}</div>@include('sales::print.partials.invoice-bill-facts', ['v' => $v, 'layout' => 'rows'])</td>
    </tr>
</table>

@include('sales::print.partials.invoice-items', ['v' => $v, 'facts' => $facts, 'paper' => $paper])

<table class="bottom">
    <tr>
        <td class="side" style="width: 55%; padding-right: 6mm">
            @if ($v->shows('amount_words'))<div class="words" data-words>{{ $facts['words'] }}</div>@endif
            <div style="margin-top: 3mm">@include('sales::print.partials.invoice-payments', ['v' => $v, 'doc' => $doc])</div>
            <div style="margin-top: 3mm; text-align: left">@include('sales::print.partials.invoice-qr', ['v' => $v])</div>
        </td>
        <td class="side">@include('sales::print.partials.invoice-sums', ['v' => $v, 'paper' => $paper])</td>
    </tr>
</table>

<div class="footnote"><div style="text-align: left; font-size: 50%; line-height: 1.35">{!! nl2br(e($v->footnote)) !!}</div></div>
@include('sales::print.partials.invoice-signatures', ['v' => $v])
<div class="printed">{{ $v->printedAt() }}</div>
<div class="foot-rule"></div>
