{{-- ⓘ A5 সংস্করণ — A4-এর premium_gold থেকে মাপ ছোট করে (mm ×০.৭২, অক্ষর ×০.৮৫, সীমা ৬.৫pt), চেহারা একই। মালিক, ৩০ সেপ্টেম্বর ২০২৬: "A5 er jonno 21 desine koro" --}}
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
    body { font-family: hindsiliguri, sans-serif; font-size: 7.6pt; color: #222120; }
    table { border-collapse: collapse; }
    table.band { width: 100%; background: {{ $dark }}; border-bottom: 0.9mm solid {{ $gold }}; }
    table.band td { padding: 4.3mm 5mm; vertical-align: bottom; color: #f3ead8; }
    .co-name { font-size: 16.1pt; font-weight: bold; }
    .co-meta { font-size: 6.5pt; color: #cfc3a8; }
    .title { text-align: right; font-size: 8.5pt; letter-spacing: 0.9mm; color: {{ $gold }}; }
    .no { text-align: right; font-size: 13.6pt; font-family: dejavusans; }
    .dup { text-align: right; font-size: 6.5pt; color: {{ $gold }}; font-weight: bold; }
    .notice { text-align: center; font-weight: bold; border: 0.3mm solid #b42318; color: #b42318; padding: 1.4mm; margin-top: 2.2mm; font-size: 9.3pt; }
    table.three { width: 100%; margin-top: 4.3mm; }
    table.three td { width: 33.3%; vertical-align: top; font-size: 7.2pt; line-height: 1.5; padding-right: 2.9mm; }
    .cap { font-size: 6.5pt; letter-spacing: 0.4mm; color: #9a7a2e; }
    .party { font-weight: bold; font-size: 9.3pt; }
    .sub { font-size: 6.5pt; color: #7a6f63; font-weight: normal; }
    table.items { width: 100%; margin-top: 4.3mm; }
    table.items th { font-size: 6.5pt; letter-spacing: 0.3mm; color: #9a7a2e; font-weight: normal; padding: 0 1.1mm 1.3mm 1.1mm; text-align: left; border-bottom: 0.2mm solid {{ $gold }}; }
    table.items th.num { text-align: right; }
    table.items td { padding: 1.8mm 1.1mm; border-bottom: 0.1mm solid #ece6d9; font-size: 7.6pt; vertical-align: top; }
    table.items tr.grand td { font-weight: bold; border-bottom: 0; }
    .free { color: #9a7a2e; font-weight: bold; }
    .num { text-align: right; white-space: nowrap; }
    td.num { font-family: dejavusans; }
    table.bottom { width: 100%; margin-top: 3.6mm; }
    table.bottom td.side { vertical-align: top; font-size: 7.2pt; }
    .pay-head { font-size: 6.5pt; letter-spacing: 0.4mm; color: #9a7a2e; }
    table.pay { width: 100%; margin-top: 0.7mm; }
    table.pay th { font-size: 6.5pt; color: #7a6f63; font-weight: normal; text-align: left; padding: 0.6mm 0.7mm; }
    table.pay td { font-size: 6.8pt; padding: 0.6mm 0.7mm; border-top: 0.1mm solid #ece6d9; }
    table.sums { width: 100%; background: {{ $dark }}; }
    table.sums td { padding: 1mm 2.9mm; font-size: 7.6pt; color: #f3ead8; }
    table.sums tr.net td { font-weight: bold; }
    table.sums tr.owed td { color: #e2c47a; font-weight: bold; font-size: 9.3pt; border-top: 0.2mm solid {{ $gold }}; padding-top: 1.8mm; padding-bottom: 2.2mm; }
    .words { margin-top: 2.2mm; font-size: 7.6pt; font-style: italic; }
    .footnote { margin-top: 2.2mm; font-size: 7.6pt; font-weight: bold; color: #b42318; }
    table.signatures { width: 100%; margin-top: 10.1mm; }
    table.signatures td { text-align: center; padding: 0 3.6mm; font-size: 7.6pt; }
    .sig-line { border-top: 0.2mm solid {{ $gold }}; padding-top: 0.7mm; }
    .printed { margin-top: 2.9mm; font-size: 6.5pt; color: #7a6f63; }
    .foot-rule { height: 0.9mm; background: {{ $gold }}; margin-top: 2.2mm; }
</style>

<table class="band">
    <tr>
        <td>
{{-- ⭐ লোগো নামের বাঁয়ে — মালিক, ৩০ সেপ্টেম্বর ২০২৬ (নমুনা PDF দেখে) --}}
<table><tr>
    @if ($v->logo)<td style="padding-right: 2.9mm; vertical-align: middle"><img src="{{ $v->logo }}" style="height: 10.1mm;" alt=""></td>@endif
    <td style="vertical-align: middle">
                <div class="co-name">{{ $v->head['name'] }}</div>
                @include('sales::print.partials.invoice-company', ['v' => $v])
    </td>
</tr></table>
        </td>
        <td style="width: 41.8mm">
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

@include('sales::print.partials.invoice-items', ['v' => $v, 'facts' => $facts, 'paper' => $paper, 'narrow' => true])

<table class="bottom">
    <tr>
        <td class="side" style="width: 55%; padding-right: 4.3mm">
            @if ($v->shows('amount_words'))<div class="words" data-words>{{ $facts['words'] }}</div>@endif
            <div style="margin-top: 2.2mm">@include('sales::print.partials.invoice-payments', ['v' => $v, 'doc' => $doc])</div>
            <div style="margin-top: 2.2mm; text-align: left">@include('sales::print.partials.invoice-qr', ['v' => $v, 'width' => '16mm'])</div>
        </td>
        <td class="side">@include('sales::print.partials.invoice-sums', ['v' => $v, 'paper' => $paper])</td>
    </tr>
</table>

<div class="footnote">{!! nl2br(e($v->footnote)) !!}</div>
@include('sales::print.partials.invoice-signatures', ['v' => $v])
<div class="printed">{{ $v->printedAt() }}</div>
<div class="foot-rule"></div>
