{{-- ⓘ A5 সংস্করণ — A4-এর modern_card থেকে মাপ ছোট করে (mm ×০.৭২, অক্ষর ×০.৮৫, সীমা ৬.৫pt), চেহারা একই। মালিক, ৩০ সেপ্টেম্বর ২০২৬: "A5 er jonno 21 desine koro" --}}
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
    body { font-family: hindsiliguri, sans-serif; font-size: 7.6pt; color: #1a1c23; }
    table { border-collapse: collapse; }
    .card { border: 0.2mm solid #e4e6ee; padding: 2.2mm 2.6mm; margin-bottom: 1.3mm; }
    table.head { width: 100%; }
    table.head td { vertical-align: middle; }
    .mark { width: 6.5mm; height: 3.9mm; background: {{ $accent }}; color: #fff; text-align: center; font-weight: bold; font-size: 8.5pt; }
    .co-name { font-size: 10.2pt; font-weight: bold; }
    .co-meta { font-size: 6.5pt; color: #6b7080; }
    .ref { text-align: right; font-size: 7.2pt; color: #6b7080; }
    .dup { text-align: right; font-size: 6.5pt; font-weight: bold; color: #6b7080; }
    .big-cap { margin-top: 2.2mm; font-size: 7.6pt; color: #6b7080; }
    .big { font-size: 23.8pt; font-weight: bold; font-family: dejavusans; letter-spacing: -0.4mm; }
    .big-sub { font-size: 6.8pt; color: #6b7080; }
    .notice { text-align: center; font-weight: bold; border: 0.3mm solid #b42318; color: #b42318; padding: 0.8mm; margin-top: 1.3mm; font-size: 9.3pt; }
    table.three { width: 100%; margin-top: 2.2mm; }
    table.three td { width: 33.3%; vertical-align: top; font-size: 7.2pt; line-height: 1.45; padding-right: 2.2mm; }
    .cap { font-size: 6.5pt; color: #6b7080; }
    .party { font-weight: bold; }
    .sub { font-size: 6.5pt; color: #6b7080; font-weight: normal; }
    table.items { width: 100%; }
    table.items th { font-size: 6.5pt; color: #6b7080; font-weight: normal; padding: 0 0.7mm 0.8mm 0.7mm; text-align: left; border-bottom: 0.2mm solid #eceef3; }
    table.items th.num { text-align: right; }
    table.items td { padding: 1.1mm 0.7mm; border-bottom: 0.2mm solid #eceef3; font-size: 7.6pt; vertical-align: top; }
    table.items tr.grand td { font-weight: bold; border-bottom: 0; }
    .free { color: {{ $accent }}; font-weight: bold; }
    .num { text-align: right; white-space: nowrap; }
    td.num { font-family: dejavusans; }
    table.bottom { width: 100%; margin-top: 1.3mm; }
    table.bottom td.side { vertical-align: top; font-size: 7.2pt; }
    .pay-head { font-size: 6.5pt; color: #6b7080; }
    table.pay { width: 100%; margin-top: 0.4mm; }
    table.pay th { font-size: 6.5pt; color: #6b7080; font-weight: normal; text-align: left; padding: 0.4mm 0.4mm; }
    table.pay td { font-size: 7.2pt; padding: 0.4mm 0.4mm; }
    table.sums { width: 100%; }
    table.sums td { padding: 0.5mm 0.4mm; font-size: 7.6pt; }
    table.sums tr.net td { font-weight: bold; }
    table.sums tr.owed td { font-weight: bold; font-size: 8.9pt; border-top: 0.2mm solid #eceef3; padding-top: 1mm; color: {{ $accent }}; }
    .words { margin-top: 0.8mm; font-size: 7.2pt; color: #6b7080; }
    .footnote { font-size: 7.6pt; font-weight: bold; color: #b42318; }
    table.signatures { width: 100%; margin-top: 5.2mm; }
    table.signatures td { text-align: center; padding: 0 2.2mm; font-size: 7.6pt; }
    .sig-line { border-top: 0.2mm solid #1a1c23; padding-top: 0.4mm; }
    .printed { margin-top: 0.8mm; text-align: center; font-size: 6.5pt; color: #6b7080; }
</style>

<div class="card">
    <table class="head">
        <tr>
            <td>
                <table><tr>
                    <td style="padding-right: 1.8mm">@if ($v->logo)<img src="{{ $v->logo }}" style="height: 9.5mm;" alt="">@else<div class="mark">{{ mb_strtoupper(mb_substr($v->head['name'], 0, 2)) }}</div>@endif</td>
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
    @include('sales::print.partials.invoice-items', ['v' => $v, 'facts' => $facts, 'paper' => $paper, 'narrow' => true, 'upper' => false])

    <table class="bottom">
        <tr>
            <td class="side" style="width: 52%; padding-right: 4.3mm">
                @include('sales::print.partials.invoice-payments', ['v' => $v, 'doc' => $doc])
                @if ($v->shows('amount_words'))<div class="words" data-words>{{ $facts['words'] }}</div>@endif
                <div style="margin-top: 1.3mm; text-align: left">@include('sales::print.partials.invoice-qr', ['v' => $v, 'width' => '16mm'])</div>
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
