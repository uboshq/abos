{{-- ⓘ A5 সংস্করণ — A4-এর seal_boxes থেকে মাপ ছোট করে (mm ×০.৭২, অক্ষর ×০.৮৫, সীমা ৬.৫pt), চেহারা একই। মালিক, ৩০ সেপ্টেম্বর ২০২৬: "A5 er jonno 21 desine koro" --}}
{{--
    ১৯ · সিলমোহরের ঘর — বাঁয়ে "কার জন্য", ডানে বিল ও কোম্পানি; মাঝে মোট বকেয়া মোটা বাক্সে; নিচে ডান কোণে
    সিল-সইয়ের চৌকো ঘর (নাম উপরে, সিলের জায়গা নিচে) — নিখুঁত শৃঙ্খলার ধাঁচ। নমুনা: Design canvas, ১৯।
    ⓘ সুইচ আর `data-*` চিহ্ন ভাগের partial-এ ([[InvoicePaperView]]); সইয়ের ঘরের নাম আর সংখ্যা সেই একই
    [[InvoicePrintLook::signatures()]] থেকে — কেবল আঁকার ধরন চৌকো।
--}}
@php($v = new \App\Modules\Sales\Support\InvoicePaperView($doc, $facts, $company, $profile))

<style @nonce>
    body { font-family: hindsiliguri, sans-serif; font-size: 7.6pt; color: #222; }
    table { border-collapse: collapse; }
    table.head { width: 100%; }
    table.head td { vertical-align: top; }
    .title { font-size: 17pt; font-weight: bold; letter-spacing: 1.4mm; }
    .title-rule { width: 41.8mm; height: 0.6mm; background: #222; margin-top: 0.7mm; }
    .to { font-size: 10.2pt; font-weight: bold; margin-top: 2.2mm; }
    .co-name { text-align: right; font-size: 10.2pt; font-weight: bold; margin-top: 1.4mm; }
    .co-meta { text-align: right; font-size: 6.5pt; color: #555; }
    .right { text-align: right; font-size: 7.2pt; }
    .dup { text-align: right; font-size: 6.5pt; font-weight: bold; }
    .notice { text-align: center; font-weight: bold; border: 0.4mm solid #222; padding: 1.4mm; margin-top: 2.2mm; font-size: 9.3pt; }
    table.total { width: 100%; margin-top: 3.6mm; border: 0.4mm solid #222; }
    table.total td { padding: 2.2mm 3.6mm; vertical-align: middle; }
    .total-cap { font-weight: bold; font-size: 8.1pt; }
    .total-val { text-align: right; font-size: 15.3pt; font-weight: bold; font-family: dejavusans; }
    .cap { font-size: 6.5pt; font-weight: bold; }
    .party { font-weight: bold; }
    .sub { font-size: 6.5pt; color: #555; font-weight: normal; }
    table.items { width: 100%; margin-top: 3.6mm; border-top: 0.4mm solid #222; }
    table.items th { background: #f1f1f1; font-size: 6.5pt; font-weight: bold; padding: 1.3mm 1.1mm; text-align: left; border-bottom: 0.2mm solid #c9c9c9; }
    table.items th.num { text-align: right; }
    table.items td { padding: 1.3mm 1.1mm; border-bottom: 0.2mm solid #c9c9c9; font-size: 7.6pt; vertical-align: top; }
    table.items tr.grand td { font-weight: bold; border-bottom: 0.4mm solid #222; }
    .num { text-align: right; white-space: nowrap; }
    td.num { font-family: dejavusans; }
    table.bottom { width: 100%; margin-top: 2.9mm; }
    table.bottom td.side { vertical-align: top; font-size: 7.2pt; }
    .pay-head { font-weight: bold; font-size: 6.8pt; }
    table.pay { width: 100%; margin-top: 0.7mm; }
    table.pay th { font-size: 6.5pt; color: #555; text-align: left; padding: 0.6mm 0.7mm; }
    table.pay td { font-size: 7.2pt; padding: 0.6mm 0.7mm; }
    table.sums { width: 100%; border: 0.2mm solid #c9c9c9; }
    table.sums td { padding: 1mm 2.2mm; font-size: 7.6pt; border-bottom: 0.2mm solid #c9c9c9; }
    table.sums tr.net td { font-weight: bold; }
    table.sums tr.owed td { font-weight: bold; }
    .words { margin-top: 1.4mm; font-size: 7.2pt; }
    .footnote { margin-top: 2.2mm; font-size: 7.6pt; font-weight: bold; color: #b42318; }
    table.seals { margin-top: 5.8mm; margin-left: auto; }
    table.seals td { width: 18mm; height: 20.2mm; border: 0.2mm solid #222; vertical-align: top; padding: 0; }
    .seal-name { border-bottom: 0.2mm solid #222; text-align: center; font-size: 6.8pt; padding: 0.7mm 0; }
    .printed { margin-top: 2.2mm; font-size: 6.5pt; color: #555; }
</style>

<table class="head">
    <tr>
        <td style="width: 55%">
            <div class="title">{{ $v->en('heading') }}</div>
            {{-- ⓘ দাগটা আলাদা ঘর — mPDF-এ বড় অক্ষরের নিচের border পরের লাইনের উপর দিয়ে কাটত --}}
            <div class="title-rule"></div>
            <div class="to">{{ $v->en('ms') }} {{ $facts['bill_to']['name'] }}</div>
            <div style="font-size: 7.2pt; color: #555">{{ implode(' · ', array_filter([$facts['bill_to']['point'], $facts['bill_to']['address'], $facts['bill_to']['phone']])) }}</div>
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

@include('sales::print.partials.invoice-items', ['v' => $v, 'facts' => $facts, 'paper' => $paper, 'narrow' => true, 'upper' => false])

<table class="bottom">
    <tr>
        <td class="side" style="width: 55%; padding-right: 4.3mm">
            @include('sales::print.partials.invoice-transport', ['v' => $v, 'facts' => $facts])
            <div style="margin-top: 1.4mm">@include('sales::print.partials.invoice-payments', ['v' => $v, 'doc' => $doc])</div>
            @if ($v->shows('amount_words'))<div class="words" data-words>{{ $facts['words'] }}</div>@endif
        </td>
        <td class="side">@include('sales::print.partials.invoice-sums', ['v' => $v, 'paper' => $paper])</td>
    </tr>
</table>

<div class="footnote">{{ $v->footnote }}</div>

<table style="width: 100%">
    <tr>
        <td style="vertical-align: bottom">@include('sales::print.partials.invoice-qr', ['v' => $v, 'width' => '16mm'])</td>
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
