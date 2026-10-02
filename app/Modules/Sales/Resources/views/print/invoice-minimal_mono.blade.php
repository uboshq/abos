{{--
    ৩ · মিনিমাল সাদা-কালো — প্রচুর ফাঁকা জায়গা, সরু দাগ, বড় "Invoice", শেষে বড় করে মোট বকেয়া।
    নমুনা: Design canvas, ৩। ⓘ সুইচ আর `data-*` চিহ্ন ভাগের partial-এ ([[InvoicePaperView]])।
--}}
@php($v = new \App\Modules\Sales\Support\InvoicePaperView($doc, $facts, $company, $profile))

<style @nonce>
    body { font-family: hindsiliguri, sans-serif; font-size: 9pt; color: #111; }
    table { border-collapse: collapse; }
    table.head { width: 100%; margin-top: 4mm; }
    table.head td { vertical-align: bottom; }
    .co-name { font-size: 14pt; font-weight: bold; }
    .co-meta { font-size: 7.5pt; color: #777; }
    .title { text-align: right; font-size: 30pt; }
    .dup { text-align: right; font-size: 7.5pt; color: #777; }
    .notice { text-align: center; font-weight: bold; border: 0.3mm solid #111; padding: 2mm; margin-top: 3mm; font-size: 11pt; }
    table.four { width: 100%; margin-top: 9mm; }
    table.four td { width: 25%; vertical-align: top; font-size: 8.5pt; line-height: 1.5; padding-right: 3mm; }
    .cap { font-size: 7.5pt; color: #777; }
    .party { font-weight: bold; }
    .sub { font-size: 7.5pt; color: #777; font-weight: normal; }
    table.items { width: 100%; margin-top: 9mm; }
    table.items th { font-size: 7.5pt; color: #777; font-weight: normal; padding: 0 0 2mm 0; text-align: left; border-bottom: 0.3mm solid #111; }
    table.items th.num { text-align: right; }
    table.items td { padding: 3mm 0; border-bottom: 0.2mm solid #e5e5e5; font-size: 9.5pt; vertical-align: top; }
    table.items tr.grand td { font-weight: bold; border-bottom: 0; border-top: 0.3mm solid #111; }
    .num { text-align: right; white-space: nowrap; }
    td.num { font-family: dejavusans; }
    table.bottom { width: 100%; margin-top: 7mm; }
    table.bottom td.side { vertical-align: top; }
    .pay-head { font-size: 7.5pt; color: #777; }
    table.pay { width: 100%; margin-top: 1mm; }
    table.pay th { display: none; font-size: 7pt; color: #777; font-weight: normal; text-align: left; padding: 0.8mm 0; }
    table.pay td { font-size: 8.5pt; padding: 0.8mm 0; }
    table.sums { width: 100%; }
    table.sums td { padding: 1.3mm 0; font-size: 9pt; color: #777; }
    table.sums td.num { color: #111; }
    table.sums tr.net td { color: #111; font-weight: bold; }
    table.sums tr.owed td { color: #111; border-top: 0.3mm solid #111; padding-top: 3mm; font-size: 14pt; font-weight: bold; }
    .words { margin-top: 6mm; font-size: 8.5pt; color: #555; }
    .footnote { margin-top: 3mm; font-size: 8.5pt; }
    table.signatures { width: 100%; margin-top: 16mm; }
    table.signatures td { padding-right: 8mm; font-size: 8.5pt; }
    .sig-line { border-top: 0.3mm solid #111; padding-top: 1.5mm; }
    .printed { margin-top: 6mm; font-size: 7pt; color: #777; }
</style>

<table class="head">
    <tr>
        <td>
            @if ($v->logo)<img src="{{ $v->logo }}" style="height: 10mm; margin-bottom: 1mm;" alt="">@endif
            <div class="co-name">{{ $v->head['name'] }}</div>
        </td>
        <td>
            <div class="title">{{ ucfirst(mb_strtolower($v->en('heading'))) }}</div>
            @if ($v->duplicate)<div class="dup" data-duplicate>{{ $v->en('duplicate') }}</div>@endif
        </td>
    </tr>
</table>

@if ($v->notices !== [])<div class="notice">{{ implode(' · ', $v->notices) }}</div>@endif

<table class="four">
    <tr>
        <td>@include('sales::print.partials.invoice-bill-to', ['v' => $v, 'facts' => $facts])</td>
        <td>@include('sales::print.partials.invoice-transport', ['v' => $v, 'facts' => $facts])</td>
        <td colspan="2"><div class="cap">{{ mb_strtoupper(__('sales::invoice_design.details', [], 'en')) }}</div>@include('sales::print.partials.invoice-bill-facts', ['v' => $v, 'layout' => 'rows'])</td>
    </tr>
</table>

@include('sales::print.partials.invoice-items', ['v' => $v, 'facts' => $facts, 'paper' => $paper, 'upper' => false])

<table class="bottom">
    <tr>
        <td class="side" style="width: 52%; padding-right: 8mm">
            @include('sales::print.partials.invoice-payments', ['v' => $v, 'doc' => $doc])
            <div style="margin-top: 4mm; text-align: left">@include('sales::print.partials.invoice-qr', ['v' => $v, 'width' => '20mm'])</div>
        </td>
        <td class="side">@include('sales::print.partials.invoice-sums', ['v' => $v, 'paper' => $paper])</td>
    </tr>
</table>

@if ($v->shows('amount_words'))<div class="words" data-words>{{ $facts['words'] }}</div>@endif
<div class="footnote">{!! nl2br(e($v->footnote)) !!}</div>
@include('sales::print.partials.invoice-signatures', ['v' => $v])
<div class="printed">@include('sales::print.partials.invoice-company', ['v' => $v]) {{ $v->printedAt() }}</div>
