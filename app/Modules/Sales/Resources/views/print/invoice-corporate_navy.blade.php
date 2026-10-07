{{--
    ২ · কর্পোরেট নীল — মাথায় পুরো চওড়া নীল পট্টি, নিচে তিন কলাম, মোট বকেয়া নীল ঘরে; পায়ে নীল দাগ।
    নমুনা: Design canvas "আধুনিক বিলের নমুনা", ২।

    ⓘ কী আঁকা হবে তা [[InvoicePaperView]] আর ভাগের partial-গুলো বলে (সুইচ আর `data-*` চিহ্ন সেখানেই);
    এখানে কেবল সাজ আর ঘরের জায়গা। mPDF-এর জন্য কেবল table, টাকার ঘর DejaVu।
--}}
@php($v = new \App\Modules\Sales\Support\InvoicePaperView($doc, $facts, $company, $profile))

<style @nonce>
    body { font-family: hindsiliguri, sans-serif; font-size: 9pt; color: #1b2333; }
    table { border-collapse: collapse; }
    table.band { width: 100%; background: #1d3f8f; color: #fff; }
    table.band td { padding: 5mm 6mm; vertical-align: middle; }
    .co-name { font-size: 17pt; font-weight: bold; }
    .co-meta { font-size: 8pt; color: #c9d5f2; }
    .title { text-align: right; font-size: 9pt; letter-spacing: 1.2mm; color: #c9d5f2; }
    .no { text-align: right; font-size: 16pt; font-weight: bold; }
    .dup { text-align: right; font-size: 7.5pt; font-weight: bold; color: #c9d5f2; }
    .notice { text-align: center; font-weight: bold; border: 0.4mm solid #b42318; color: #b42318; padding: 2mm; margin-top: 3mm; font-size: 11pt; }
    table.three { width: 100%; margin-top: 5mm; }
    table.three td { width: 33.3%; vertical-align: top; font-size: 8.5pt; padding-right: 4mm; }
    .cap { font-size: 7pt; font-weight: bold; color: #1d3f8f; }
    .party { font-weight: bold; font-size: 10pt; }
    .sub { font-size: 7.5pt; color: #5b6477; font-weight: normal; }
    table.items { width: 100%; margin-top: 5mm; border: 0.3mm solid #c9d5f2; }
    table.items th { background: #e8eefb; color: #1d3f8f; font-size: 7.5pt; font-weight: bold; padding: 2mm; text-align: left; }
    table.items th.num { text-align: right; }
    table.items td { padding: 2mm; border-top: 0.25mm solid #e3e8f4; font-size: 9pt; vertical-align: top; }
    table.items tr.grand td { background: #1d3f8f; color: #fff; font-weight: bold; }
    .free { color: #1d3f8f; font-weight: bold; }
    .num { text-align: right; white-space: nowrap; }
    td.num { font-family: dejavusans; }
    .words { margin-top: 3mm; font-size: 8.5pt; }
    table.bottom { width: 100%; margin-top: 4mm; }
    table.bottom td.side { vertical-align: top; }
    .pay-head { font-size: 7pt; font-weight: bold; color: #1d3f8f; }
    table.pay { width: 100%; margin-top: 1mm; }
    table.pay th { font-size: 7pt; color: #5b6477; padding: 1.2mm 1.5mm; text-align: left; border-bottom: 0.25mm solid #c9d5f2; }
    table.pay td { font-size: 8pt; padding: 1.4mm 1.5mm; border-bottom: 0.25mm solid #e3e8f4; }
    table.sums { width: 100%; }
    table.sums td { padding: 1.3mm 2mm; font-size: 9pt; }
    table.sums tr.net td { border-top: 0.6mm solid #1d3f8f; font-weight: bold; font-size: 10pt; }
    table.sums tr.owed td { background: #e8eefb; color: #1d3f8f; font-weight: bold; padding: 2.2mm 2mm; }
    .footnote { margin-top: 4mm; font-size: 9pt; font-weight: bold; color: #b42318; }
    table.signatures { width: 100%; margin-top: 14mm; }
    table.signatures td { text-align: center; padding: 0 5mm; font-size: 9pt; }
    .sig-line { border-top: 0.25mm solid #1b2333; padding-top: 1mm; }
    table.foot { width: 100%; margin-top: 5mm; background: #1d3f8f; }
    table.foot td { font-size: 7pt; color: #c9d5f2; padding: 1.5mm 4mm; }
</style>

<table class="band">
    <tr>
        <td>
            @if ($v->logo)<img src="{{ $v->logo }}" style="height: 11mm; margin-bottom: 1mm;" alt="">@endif
            <div class="co-name">{{ $v->head['name'] }}</div>
            @include('sales::print.partials.invoice-company', ['v' => $v])
        </td>
        <td style="width: 60mm">
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

@if ($v->shows('amount_words'))<div class="words" data-words><strong>{{ $v->en('in_words') }}</strong> {{ $facts['words'] }}</div>@endif

<table class="bottom">
    <tr>
        <td class="side" style="width: 57%; padding-right: 6mm">
            @include('sales::print.partials.invoice-payments', ['v' => $v, 'doc' => $doc])
            <div style="margin-top: 4mm; text-align: left">@include('sales::print.partials.invoice-qr', ['v' => $v])</div>
        </td>
        <td class="side">@include('sales::print.partials.invoice-sums', ['v' => $v, 'paper' => $paper])</td>
    </tr>
</table>

<div class="footnote"><div style="text-align: left; font-size: 80%; line-height: 1.35">{!! nl2br(e($v->footnote)) !!}</div></div>
@include('sales::print.partials.invoice-signatures', ['v' => $v])
<table class="foot"><tr><td>{{ $v->printedAt() }}</td></tr></table>
