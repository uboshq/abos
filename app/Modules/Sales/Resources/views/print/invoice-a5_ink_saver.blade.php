{{-- ⓘ A5 সংস্করণ — A4-এর ink_saver থেকে মাপ ছোট করে (mm ×০.৭২, অক্ষর ×০.৮৫, সীমা ৬.৫pt), চেহারা একই। মালিক, ৩০ সেপ্টেম্বর ২০২৬: "A5 er jonno 21 desine koro" --}}
{{--
    ৭ · কালি বাঁচানো — কোনো রঙের ভরাট নেই, কেবল সরু ধূসর দাগ; যেকোনো লেজার বা ডট-ম্যাট্রিক্সে কম কালিতে।
    নমুনা: Design canvas, ৭। ⓘ সুইচ আর `data-*` চিহ্ন ভাগের partial-এ ([[InvoicePaperView]])।
--}}
@php($v = new \App\Modules\Sales\Support\InvoicePaperView($doc, $facts, $company, $profile))

<style @nonce>
    body { font-family: hindsiliguri, sans-serif; font-size: 7.6pt; color: #222; }
    table { border-collapse: collapse; }
    table.head { width: 100%; border: 0.2mm solid #222; }
    table.head td { vertical-align: middle; padding: 2.2mm 2.9mm; }
    .co-name { font-size: 12.8pt; font-weight: bold; }
    .co-meta { font-size: 6.8pt; }
    .title { text-align: right; font-size: 12.8pt; font-weight: bold; letter-spacing: 0.7mm; }
    .no { text-align: right; font-size: 8.5pt; }
    .dup { text-align: right; font-size: 6.5pt; font-weight: bold; }
    .notice { text-align: center; font-weight: bold; border: 0.3mm solid #222; padding: 1.4mm; margin-top: 2.2mm; font-size: 9.3pt; }
    table.three { width: 100%; margin-top: 2.2mm; border: 0.1mm solid #bdbdbd; }
    table.three td { width: 33.3%; vertical-align: top; padding: 1.8mm 2.2mm; font-size: 7.2pt; border-right: 0.1mm solid #bdbdbd; }
    .cap { font-size: 6.5pt; font-weight: bold; }
    .party { font-weight: bold; }
    .sub { font-size: 6.5pt; font-weight: normal; }
    table.items { width: 100%; margin-top: 2.9mm; border: 0.1mm solid #bdbdbd; }
    table.items th { font-size: 6.5pt; font-weight: bold; padding: 1.3mm; text-align: left; border: 0.1mm solid #bdbdbd; }
    table.items th.num { text-align: right; }
    table.items td { padding: 1.3mm; border: 0.1mm solid #bdbdbd; font-size: 7.6pt; vertical-align: top; }
    table.items tr.grand td { font-weight: bold; }
    .num { text-align: right; white-space: nowrap; }
    td.num { font-family: dejavusans; }
    table.bottom { width: 100%; margin-top: 2.9mm; }
    table.bottom td.side { vertical-align: top; font-size: 7.2pt; }
    .pay-head { font-weight: bold; font-size: 6.5pt; }
    table.pay { width: 100%; margin-top: 0.7mm; border: 0.1mm solid #bdbdbd; }
    table.pay th, table.pay td { font-size: 6.8pt; padding: 0.7mm 1.1mm; border: 0.1mm solid #bdbdbd; text-align: left; }
    table.sums { width: 100%; border: 0.1mm solid #bdbdbd; }
    table.sums td { padding: 0.9mm 1.8mm; font-size: 7.6pt; border-bottom: 0.1mm solid #bdbdbd; }
    table.sums tr.net td { font-weight: bold; }
    table.sums tr.owed td { font-weight: bold; font-size: 8.9pt; border-top: 0.4mm solid #222; }
    .words { margin-top: 2.2mm; font-size: 7.2pt; }
    .footnote { margin-top: 2.2mm; font-size: 7.6pt; font-weight: bold; }
    table.signatures { width: 100%; margin-top: 10.1mm; }
    table.signatures td { text-align: center; padding: 0 3.6mm; font-size: 7.6pt; }
    .sig-line { border-top: 0.2mm solid #222; padding-top: 0.7mm; }
    .printed { margin-top: 2.9mm; font-size: 6.5pt; color: #555; }
</style>

<table class="head">
    <tr>
        <td>
{{-- ⭐ লোগো নামের বাঁয়ে — মালিক, ৩০ সেপ্টেম্বর ২০২৬ (নমুনা PDF দেখে) --}}
<table><tr>
    @if ($v->logo)<td style="padding-right: 2.5mm; vertical-align: middle"><img src="{{ $v->logo }}" style="height: 8.6mm;" alt=""></td>@endif
    <td style="vertical-align: middle">
                <div class="co-name">{{ $v->head['name'] }}</div>
                @include('sales::print.partials.invoice-company', ['v' => $v])
    </td>
</tr></table>
        </td>
        <td style="width: 43.2mm">
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

@include('sales::print.partials.invoice-items', ['v' => $v, 'facts' => $facts, 'paper' => $paper, 'narrow' => true])

<table class="bottom">
    <tr>
        <td class="side" style="width: 57%; padding-right: 3.6mm">
            @if ($v->shows('amount_words'))<div data-words><strong>{{ $v->en('in_words') }}</strong> {{ $facts['words'] }}</div>@endif
            <div style="margin-top: 1.4mm">@include('sales::print.partials.invoice-payments', ['v' => $v, 'doc' => $doc])</div>
            <div style="margin-top: 2.2mm; text-align: left">@include('sales::print.partials.invoice-qr', ['v' => $v, 'width' => '16mm'])</div>
        </td>
        <td class="side">@include('sales::print.partials.invoice-sums', ['v' => $v, 'paper' => $paper])</td>
    </tr>
</table>

<div class="footnote">{{ $v->footnote }}</div>
@include('sales::print.partials.invoice-signatures', ['v' => $v])
<div class="printed">{{ $v->printedAt() }}</div>
