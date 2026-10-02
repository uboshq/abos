{{-- ⓘ A5 সংস্করণ — A4-এর statement থেকে মাপ ছোট করে (mm ×০.৭২, অক্ষর ×০.৮৫, সীমা ৬.৫pt), চেহারা একই। মালিক, ৩০ সেপ্টেম্বর ২০২৬: "A5 er jonno 21 desine koro" --}}
{{--
    ২০ · বিল + হিসাবের বিবরণী — বিলের পাশে গ্রাহকের হিসাবের সারাংশ (খোলা জের · এই বিল · জমা · শেষ জের)।
    ব্যাংক বিবরণীর ধাঁচ। নমুনা: Design canvas, ২০।
    ⓘ সুইচ আর `data-*` চিহ্ন ভাগের partial-এ ([[InvoicePaperView]])। ⚠️ সারাংশের অঙ্ক `$facts['sums']`-এর
    সেই একই অঙ্ক (আগের বকেয়া · নিট প্রদেয় · জমা · মোট বকেয়া) — এখানে আলাদা করে গোনা হয় না; "আগের বকেয়া"
    বন্ধ হলে সারাংশটাই যায়।
--}}
@php
    $v = new \App\Modules\Sales\Support\InvoicePaperView($doc, $facts, $company, $profile);
    $accent = '#0b5394';
@endphp

<style @nonce>
    body { font-family: hindsiliguri, sans-serif; font-size: 7.6pt; color: #15202b; }
    table { border-collapse: collapse; }
    table.head { width: 100%; border-bottom: 0.6mm solid {{ $accent }}; }
    table.head td { vertical-align: middle; padding-bottom: 2.2mm; }
    .co-name { font-size: 12.8pt; font-weight: bold; color: {{ $accent }}; }
    .co-meta { font-size: 6.5pt; color: #5b6770; }
    .title { text-align: right; font-size: 7.6pt; font-weight: bold; letter-spacing: 0.4mm; }
    .no { text-align: right; font-size: 8.5pt; font-family: dejavusans; }
    .dup { text-align: right; font-size: 6.5pt; font-weight: bold; color: #5b6770; }
    .notice { text-align: center; font-weight: bold; border: 0.3mm solid #b42318; color: #b42318; padding: 1.4mm; margin-top: 2.2mm; font-size: 9.3pt; }
    table.top { width: 100%; margin-top: 2.9mm; }
    table.top td { vertical-align: top; font-size: 7.2pt; line-height: 1.5; }
    .cap { font-size: 6.5pt; font-weight: bold; color: #5b6770; }
    .party { font-weight: bold; font-size: 8.9pt; }
    .sub { font-size: 6.5pt; color: #5b6770; font-weight: normal; }
    table.summary { width: 100%; background: #eef4fa; }
    table.summary td { padding: 0.9mm 2.2mm; font-size: 7.6pt; }
    table.summary tr.close td { border-top: 0.2mm solid {{ $accent }}; font-weight: bold; color: {{ $accent }}; font-size: 8.5pt; padding-top: 1.4mm; padding-bottom: 1.8mm; }
    .section { margin-top: 3.6mm; font-size: 6.5pt; font-weight: bold; color: {{ $accent }}; }
    table.items { width: 100%; margin-top: 1.1mm; }
    table.items th { font-size: 6.5pt; font-weight: bold; color: #5b6770; padding: 0.9mm 1.1mm; text-align: left; border-bottom: 0.4mm solid #15202b; }
    table.items th.num { text-align: right; }
    table.items td { padding: 1.3mm 1.1mm; border-bottom: 0.2mm solid #dde3e8; font-size: 7.6pt; vertical-align: top; }
    table.items tr.grand td { font-weight: bold; border-bottom: 0; }
    .num { text-align: right; white-space: nowrap; }
    td.num { font-family: dejavusans; }
    table.bottom { width: 100%; margin-top: 2.2mm; }
    table.bottom td.side { vertical-align: top; font-size: 7.2pt; }
    .pay-head { font-size: 6.5pt; font-weight: bold; color: {{ $accent }}; }
    table.pay { width: 100%; margin-top: 0.7mm; }
    table.pay th { font-size: 6.5pt; font-weight: bold; color: #5b6770; text-align: left; padding: 0.7mm; border-bottom: 0.3mm solid #15202b; }
    table.pay td { font-size: 7.2pt; padding: 0.7mm; border-bottom: 0.1mm solid #dde3e8; }
    table.sums { width: 100%; }
    table.sums td { padding: 0.8mm 1.1mm; font-size: 7.6pt; }
    table.sums tr.net td { font-weight: bold; }
    table.sums tr.owed td { font-weight: bold; color: {{ $accent }}; }
    .words { margin-top: 1.4mm; font-size: 7.2pt; }
    .footnote { margin-top: 2.2mm; font-size: 7.6pt; font-weight: bold; color: #b42318; }
    table.signatures { width: 100%; margin-top: 9.4mm; }
    table.signatures td { text-align: center; padding: 0 3.6mm; font-size: 7.6pt; }
    .sig-line { border-top: 0.2mm solid #15202b; padding-top: 0.7mm; }
    .printed { margin-top: 2.2mm; font-size: 6.5pt; color: #5b6770; }
</style>

<table class="head">
    <tr>
        <td>
            <div class="co-name">{{ $v->head['name'] }}</div>
            @include('sales::print.partials.invoice-company', ['v' => $v])
        </td>
        {{-- ⭐ QR উপরে মাঝখানে — মালিক, ৩০ সেপ্টেম্বর ২০২৬; QR না থাকলে ঘরটা ফাঁকা --}}
        <td style="width: 20.2mm; text-align: center; vertical-align: middle">@include('sales::print.partials.invoice-qr', ['v' => $v, 'width' => '14.4mm'])</td>
        <td style="width: 50.4mm">
            <div class="title">{{ mb_strtoupper($v->en('heading')) }} · {{ mb_strtoupper(__('sales::invoice_design.statement', [], 'en')) }}</div>
            <div class="no">{{ $facts['bill']['bill_no'] }} · {{ $facts['bill']['bill_date'] }}</div>
            @if ($v->duplicate)<div class="dup" data-duplicate>{{ $v->en('duplicate') }}</div>@endif
        </td>
    </tr>
</table>

@if ($v->notices !== [])<div class="notice">{{ implode(' · ', $v->notices) }}</div>@endif

<table class="top">
    <tr>
        <td style="width: 52%; padding-right: 4.3mm">
            @include('sales::print.partials.invoice-bill-to', ['v' => $v, 'facts' => $facts])
            @if ($v->shows('order_no'))<div data-order-no>{{ $v->en('order_no') }} {{ $facts['bill']['order_no'] }}</div>@endif
            @if ($v->shows('invoice_type'))<div data-invoice-type>{{ $v->en('type') }} {{ $facts['bill']['type'] }}</div>@endif
            <div style="margin-top: 1.4mm">@include('sales::print.partials.invoice-transport', ['v' => $v, 'facts' => $facts])</div>
        </td>
        <td>
            @if ($v->shows('previous_due'))
                <div class="cap" style="color: {{ $accent }}">{{ mb_strtoupper(__('sales::invoice_design.account_summary', [], 'en')) }}</div>
                <table class="summary">
                    <tr><td>{{ $v->label('previous_due') }}</td><td class="num">{{ $paper->money($v->sums['previous_due']) }}</td></tr>
                    <tr><td>+ {{ $v->label('net_payable') }}</td><td class="num">{{ $paper->money($v->sums['net_payable']) }}</td></tr>
                    <tr><td>− {{ $v->label('paid') }}</td><td class="num">{{ $paper->money($v->sums['paid']) }}</td></tr>
                    <tr class="close"><td>{{ $v->label('total_due') }}</td><td class="num">{{ $paper->money($v->sums['outstanding']) }}</td></tr>
                </table>
            @endif
        </td>
    </tr>
</table>

<div class="section">{{ mb_strtoupper(__('sales::invoice_design.goods', [], 'en')) }}</div>
@include('sales::print.partials.invoice-items', ['v' => $v, 'facts' => $facts, 'paper' => $paper, 'narrow' => true])

<table class="bottom">
    <tr>
        <td class="side" style="width: 55%; padding-right: 4.3mm">
            @include('sales::print.partials.invoice-payments', ['v' => $v, 'doc' => $doc])
            @if ($v->shows('amount_words'))<div class="words" data-words><strong>{{ $v->en('in_words') }}</strong> {{ $facts['words'] }}</div>@endif
        </td>
        <td class="side">@include('sales::print.partials.invoice-sums', ['v' => $v, 'paper' => $paper])</td>
    </tr>
</table>

<div class="footnote">{!! nl2br(e($v->footnote)) !!}</div>
@include('sales::print.partials.invoice-signatures', ['v' => $v])
<div class="printed">{{ $v->printedAt() }}</div>
