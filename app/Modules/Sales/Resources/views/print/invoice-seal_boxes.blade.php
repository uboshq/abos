{{--
    ১৯ · সিলমোহরের ঘর — বাঁয়ে "কার জন্য", ডানে বিল ও কোম্পানি; মাঝে মোট বকেয়া মোটা বাক্সে; নিচে ডান কোণে
    সিল-সইয়ের চৌকো ঘর (নাম উপরে, সিলের জায়গা নিচে) — নিখুঁত শৃঙ্খলার ধাঁচ। নমুনা: Design canvas, ১৯।
    ⓘ সুইচ আর `data-*` চিহ্ন ভাগের partial-এ ([[InvoicePaperView]]); সইয়ের ঘরের নাম আর সংখ্যা সেই একই
    [[InvoicePrintLook::signatures()]] থেকে — কেবল আঁকার ধরন চৌকো।
--}}
@php($v = new \App\Modules\Sales\Support\InvoicePaperView($doc, $facts, $company, $profile))

<style @nonce>
    body { font-family: hindsiliguri, sans-serif; font-size: 9pt; color: #222; }
    table { border-collapse: collapse; }
    table.head { width: 100%; }
    table.head td { vertical-align: top; }
    .title { font-size: 20pt; font-weight: bold; letter-spacing: 2mm; }
    .title-rule { width: 58mm; height: 0.8mm; background: #222; margin-top: 1mm; }
    .to { font-size: 12pt; font-weight: bold; margin-top: 3mm; }
    .co-name { text-align: right; font-size: 12pt; font-weight: bold; margin-top: 2mm; }
    .co-meta { text-align: right; font-size: 7.5pt; color: #555; }
    .right { text-align: right; font-size: 8.5pt; }
    .dup { text-align: right; font-size: 7.5pt; font-weight: bold; }
    .notice { text-align: center; font-weight: bold; border: 0.5mm solid #222; padding: 2mm; margin-top: 3mm; font-size: 11pt; }
    table.total { width: 100%; margin-top: 5mm; border: 0.6mm solid #222; }
    table.total td { padding: 3mm 5mm; vertical-align: middle; }
    .total-cap { font-weight: bold; font-size: 9.5pt; }
    .total-val { text-align: right; font-size: 18pt; font-weight: bold; font-family: dejavusans; }
    .cap { font-size: 7.5pt; font-weight: bold; }
    .party { font-weight: bold; }
    .sub { font-size: 7.5pt; color: #555; font-weight: normal; }
    table.items { width: 100%; margin-top: 5mm; border-top: 0.6mm solid #222; }
    table.items th { background: #f1f1f1; font-size: 7.5pt; font-weight: bold; padding: 1.8mm 1.5mm; text-align: left; border-bottom: 0.25mm solid #c9c9c9; }
    table.items th.num { text-align: right; }
    table.items td { padding: 1.8mm 1.5mm; border-bottom: 0.25mm solid #c9c9c9; font-size: 9pt; vertical-align: top; }
    table.items tr.grand td { font-weight: bold; border-bottom: 0.6mm solid #222; }
    .num { text-align: right; white-space: nowrap; }
    td.num { font-family: dejavusans; }
    table.bottom { width: 100%; margin-top: 4mm; }
    table.bottom td.side { vertical-align: top; font-size: 8.5pt; }
    .pay-head { font-weight: bold; font-size: 8pt; }
    table.pay { width: 100%; margin-top: 1mm; }
    table.pay th { font-size: 7pt; color: #555; text-align: left; padding: 0.8mm 1mm; }
    table.pay td { font-size: 8.5pt; padding: 0.8mm 1mm; }
    table.sums { width: 100%; border: 0.25mm solid #c9c9c9; }
    table.sums td { padding: 1.4mm 3mm; font-size: 9pt; border-bottom: 0.25mm solid #c9c9c9; }
    table.sums tr.net td { font-weight: bold; }
    table.sums tr.owed td { font-weight: bold; }
    .words { margin-top: 2mm; font-size: 8.5pt; }
    .footnote { margin-top: 3mm; font-size: 9pt; font-weight: bold; color: #b42318; }
    table.seals { margin-top: 8mm; margin-left: auto; }
    table.seals td { width: 25mm; height: 28mm; border: 0.3mm solid #222; vertical-align: top; padding: 0; }
    .seal-name { border-bottom: 0.3mm solid #222; text-align: center; font-size: 8pt; padding: 1mm 0; }
    .printed { margin-top: 3mm; font-size: 7pt; color: #555; }
</style>

<table class="head">
    <tr>
        <td style="width: 55%">
            <div class="title">{{ $v->en('heading') }}</div>
            {{-- ⓘ দাগটা আলাদা ঘর — mPDF-এ বড় অক্ষরের নিচের border পরের লাইনের উপর দিয়ে কাটত --}}
            <div class="title-rule"></div>
            <div class="to">{{ $v->en('ms') }} {{ $facts['bill_to']['name'] }}</div>
            <div style="font-size: 8.5pt; color: #555">{{ implode(' · ', array_filter([$facts['bill_to']['point'], $facts['bill_to']['address'], $facts['bill_to']['phone']])) }}</div>
        </td>
        <td>
            <div class="right">{{ $v->label('bill_no') }} <strong>{{ $facts['bill']['bill_no'] }}</strong></div>
            <div class="right">{{ $v->label('bill_date') }} {{ $facts['bill']['bill_date'] }}</div>
            @if ($v->shows('order_no'))<div class="right" data-order-no>{{ $v->en('order_no') }} {{ $facts['bill']['order_no'] }}</div>@endif
            @if ($v->shows('invoice_type'))<div class="right" data-invoice-type>{{ $v->en('type') }} {{ $facts['bill']['type'] }}</div>@endif
            @if ($v->duplicate)<div class="dup" data-duplicate>{{ $v->en('duplicate') }}</div>@endif
            <div class="co-name">{{ $v->head['name'] }}</div>
            @include('sales::print.partials.invoice-company', ['v' => $v])
        </td>
    </tr>
</table>

@if ($v->notices !== [])<div class="notice">{{ implode(' · ', $v->notices) }}</div>@endif

<table class="total">
    <tr>
        <td class="total-cap">{{ $v->shows('previous_due') ? $v->label('total_due') : $v->label('net_payable') }}</td>
        <td class="total-val"><span style="font-family: hindsiliguri">৳</span> {{ $paper->money($v->shows('previous_due') ? $v->sums['outstanding'] : $v->sums['net_payable']) }}</td>
    </tr>
</table>

@include('sales::print.partials.invoice-items', ['v' => $v, 'facts' => $facts, 'paper' => $paper, 'upper' => false])

<table class="bottom">
    <tr>
        <td class="side" style="width: 55%; padding-right: 6mm">
            @include('sales::print.partials.invoice-transport', ['v' => $v, 'facts' => $facts])
            <div style="margin-top: 2mm">@include('sales::print.partials.invoice-payments', ['v' => $v, 'doc' => $doc])</div>
            @if ($v->shows('amount_words'))<div class="words" data-words>{{ $facts['words'] }}</div>@endif
        </td>
        <td class="side">@include('sales::print.partials.invoice-sums', ['v' => $v, 'paper' => $paper])</td>
    </tr>
</table>

<div class="footnote">{!! nl2br(e($v->footnote)) !!}</div>

<table style="width: 100%">
    <tr>
        <td style="vertical-align: bottom">@include('sales::print.partials.invoice-qr', ['v' => $v])</td>
        <td style="vertical-align: bottom">
            <table class="seals">
                <tr>
                    @foreach (array_reverse($v->signatures) as $label)
                        <td><div class="seal-name" data-signature>{{ $label }}</div></td>
                    @endforeach
                </tr>
            </table>
        </td>
    </tr>
</table>
<div class="printed">{{ $v->printedAt() }}</div>
