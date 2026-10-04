{{--
    ৭ · কালি বাঁচানো — কোনো রঙের ভরাট নেই, কেবল সরু ধূসর দাগ; যেকোনো লেজার বা ডট-ম্যাট্রিক্সে কম কালিতে।
    নমুনা: Design canvas, ৭। ⓘ সুইচ আর `data-*` চিহ্ন ভাগের partial-এ ([[InvoicePaperView]])।
--}}
@php($v = new \App\Modules\Sales\Support\InvoicePaperView($doc, $facts, $company, $profile))

<style @nonce>
    body { font-family: hindsiliguri, sans-serif; font-size: 9pt; color: #222; }
    table { border-collapse: collapse; }
    table.head { width: 100%; border: 0.3mm solid #222; }
    table.head td { vertical-align: middle; padding: 3mm 4mm; }
    .co-name { font-size: 15pt; font-weight: bold; }
    .co-meta { font-size: 8pt; }
    .title { text-align: right; font-size: 15pt; font-weight: bold; letter-spacing: 1mm; }
    .no { text-align: right; font-size: 10pt; }
    .dup { text-align: right; font-size: 7.5pt; font-weight: bold; }
    .notice { text-align: center; font-weight: bold; border: 0.4mm solid #222; padding: 2mm; margin-top: 3mm; font-size: 11pt; }
    table.three { width: 100%; margin-top: 3mm; border: 0.2mm solid #bdbdbd; }
    table.three td { width: 33.3%; vertical-align: top; padding: 2.5mm 3mm; font-size: 8.5pt; border-right: 0.2mm solid #bdbdbd; }
    .cap { font-size: 7pt; font-weight: bold; }
    .party { font-weight: bold; }
    .sub { font-size: 7.5pt; font-weight: normal; }
    table.items { width: 100%; margin-top: 4mm; border: 0.2mm solid #bdbdbd; }
    table.items th { font-size: 7.5pt; font-weight: bold; padding: 1.8mm; text-align: left; border: 0.2mm solid #bdbdbd; }
    table.items th.num { text-align: right; }
    table.items td { padding: 1.8mm; border: 0.2mm solid #bdbdbd; font-size: 9pt; vertical-align: top; }
    table.items tr.grand td { font-weight: bold; }
    .num { text-align: right; white-space: nowrap; }
    td.num { font-family: dejavusans; }
    table.bottom { width: 100%; margin-top: 4mm; }
    table.bottom td.side { vertical-align: top; font-size: 8.5pt; }
    .pay-head { font-weight: bold; font-size: 7.5pt; }
    table.pay { width: 100%; margin-top: 1mm; border: 0.2mm solid #bdbdbd; }
    table.pay th, table.pay td { font-size: 8pt; padding: 1mm 1.5mm; border: 0.2mm solid #bdbdbd; text-align: left; }
    table.sums { width: 100%; border: 0.2mm solid #bdbdbd; }
    table.sums td { padding: 1.3mm 2.5mm; font-size: 9pt; border-bottom: 0.2mm solid #bdbdbd; }
    table.sums tr.net td { font-weight: bold; }
    table.sums tr.owed td { font-weight: bold; font-size: 10.5pt; border-top: 0.5mm solid #222; }
    .words { margin-top: 3mm; font-size: 8.5pt; }
    .footnote { margin-top: 3mm; font-size: 9pt; font-weight: bold; }
    table.signatures { width: 100%; margin-top: 14mm; }
    table.signatures td { text-align: center; padding: 0 5mm; font-size: 9pt; }
    .sig-line { border-top: 0.25mm solid #222; padding-top: 1mm; }
    .printed { margin-top: 4mm; font-size: 7pt; color: #555; }
</style>

<table class="head">
    <tr>
        <td>
{{-- ⭐ লোগো নামের বাঁয়ে — মালিক, ৩০ সেপ্টেম্বর ২০২৬ (নমুনা PDF দেখে) --}}
<table><tr>
    @if ($v->logo)<td style="padding-right: 3.5mm; vertical-align: middle"><img src="{{ $v->logo }}" style="height: 12mm;" alt=""></td>@endif
    <td style="vertical-align: middle">
                <div class="co-name">{{ $v->head['name'] }}</div>
                @include('sales::print.partials.invoice-company', ['v' => $v])
    </td>
</tr></table>
        </td>
        <td style="width: 60mm">
            <div class="title">{{ $v->en('heading') }}</div>
            <div class="no">{{ $facts['bill']['bill_no'] }}@if ($v->shows('invoice_type')) · <span data-invoice-type>{{ $facts['bill']['type'] }}</span>@endif</div>
            @if ($v->duplicate)<div class="dup" data-duplicate>{{ $v->en('duplicate') }}</div>@endif
        </td>
    </tr>
</table>

@if ($v->notices !== [])<div class="notice">{{ implode(' · ', $v->notices) }}</div>@endif

<table class="three">
    <tr>
        <td>@include('sales::print.partials.invoice-bill-to', ['v' => $v, 'facts' => $facts])</td>
        <td>@include('sales::print.partials.invoice-transport', ['v' => $v, 'facts' => $facts])</td>
        <td><div class="cap">{{ mb_strtoupper(__('sales::invoice_design.details', [], 'en')) }}</div>{{ $v->en('bill_date') }} {{ $facts['bill']['bill_date'] }}@if ($v->shows('order_no'))<div data-order-no>{{ $v->en('order_no') }} {{ $facts['bill']['order_no'] }}</div>@endif<div>{{ $v->en('created_by') }} {{ $facts['bill']['created_by'] }}</div></td>
    </tr>
</table>

@include('sales::print.partials.invoice-items', ['v' => $v, 'facts' => $facts, 'paper' => $paper])

<table class="bottom">
    <tr>
        <td class="side" style="width: 57%; padding-right: 5mm">
            @if ($v->shows('amount_words'))<div data-words><strong>{{ $v->en('in_words') }}</strong> {{ $facts['words'] }}</div>@endif
            <div style="margin-top: 2mm">@include('sales::print.partials.invoice-payments', ['v' => $v, 'doc' => $doc])</div>
            <div style="margin-top: 3mm; text-align: left">@include('sales::print.partials.invoice-qr', ['v' => $v])</div>
        </td>
        <td class="side">@include('sales::print.partials.invoice-sums', ['v' => $v, 'paper' => $paper])</td>
    </tr>
</table>

<div class="footnote"><div style="text-align: left; font-size: 80%; line-height: 1.35">{!! nl2br(e($v->footnote)) !!}</div></div>
@include('sales::print.partials.invoice-signatures', ['v' => $v])
<div class="printed">{{ $v->printedAt() }}</div>
