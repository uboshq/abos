{{-- ⓘ A5 সংস্করণ — A4-এর minimal_mono থেকে মাপ ছোট করে (mm ×০.৭২, অক্ষর ×০.৮৫, সীমা ৬.৫pt), চেহারা একই। মালিক, ৩০ সেপ্টেম্বর ২০২৬: "A5 er jonno 21 desine koro" --}}
{{--
    ৩ · মিনিমাল সাদা-কালো — প্রচুর ফাঁকা জায়গা, সরু দাগ, বড় "Invoice", শেষে বড় করে মোট বকেয়া।
    নমুনা: Design canvas, ৩। ⓘ সুইচ আর `data-*` চিহ্ন ভাগের partial-এ ([[InvoicePaperView]])।
--}}
@php($v = new \App\Modules\Sales\Support\InvoicePaperView($doc, $facts, $company, $profile))

<style @nonce>
    body { font-family: hindsiliguri, sans-serif; font-size: 7.6pt; color: #111; }
    table { border-collapse: collapse; }
    table.head { width: 100%; margin-top: 1.7mm; }
    table.head td { vertical-align: bottom; }
    .co-name { font-size: 11.9pt; font-weight: bold; }
    .co-meta { font-size: 6.5pt; color: #777; }
    .title { text-align: right; font-size: 25.5pt; }
    .dup { text-align: right; font-size: 6.5pt; color: #777; }
    .notice { text-align: center; font-weight: bold; border: 0.2mm solid #111; padding: 0.8mm; margin-top: 1.3mm; font-size: 9.3pt; }
    table.four { width: 100%; margin-top: 3.9mm; }
    table.four td { width: 25%; vertical-align: top; font-size: 7.2pt; line-height: 1.5; padding-right: 2.2mm; }
    .cap { font-size: 6.5pt; color: #777; }
    .party { font-weight: bold; }
    .sub { font-size: 6.5pt; color: #777; font-weight: normal; }
    table.items { width: 100%; margin-top: 3.9mm; }
    table.items th { font-size: 6.5pt; color: #777; font-weight: normal; padding: 0 0 0.8mm 0; text-align: left; border-bottom: 0.2mm solid #111; }
    table.items th.num { text-align: right; }
    table.items td { padding: 1.3mm 0; border-bottom: 0.1mm solid #e5e5e5; font-size: 8.1pt; vertical-align: top; }
    table.items tr.grand td { font-weight: bold; border-bottom: 0; border-top: 0.2mm solid #111; }
    .num { text-align: right; white-space: nowrap; }
    td.num { font-family: dejavusans; }
    table.bottom { width: 100%; margin-top: 3mm; }
    table.bottom td.side { vertical-align: top; }
    .pay-head { font-size: 6.5pt; color: #777; }
    table.pay { width: 100%; margin-top: 0.4mm; }
    table.pay th { display: none; font-size: 6.5pt; color: #777; font-weight: normal; text-align: left; padding: 0.4mm 0; }
    table.pay td { font-size: 7.2pt; padding: 0.4mm 0; }
    table.sums { width: 100%; }
    table.sums td { padding: 0.5mm 0; font-size: 7.6pt; color: #777; }
    table.sums td.num { color: #111; }
    table.sums tr.net td { color: #111; font-weight: bold; }
    table.sums tr.owed td { color: #111; border-top: 0.2mm solid #111; padding-top: 1.3mm; font-size: 11.9pt; font-weight: bold; }
    .words { margin-top: 2.6mm; font-size: 7.2pt; color: #555; }
    .footnote { margin-top: 1.3mm; font-size: 7.2pt; }
    table.signatures { width: 100%; margin-top: 6.9mm; }
    table.signatures td { padding-right: 5.8mm; font-size: 7.2pt; }
    .sig-line { border-top: 0.2mm solid #111; padding-top: 0.7mm; }
    .printed { margin-top: 2.6mm; font-size: 6.5pt; color: #777; }
</style>

<table class="head">
    <tr>
        <td>
            @if ($v->logo)<img src="{{ $v->logo }}" style="height: 9.5mm; margin-bottom: 0.4mm;" alt="">@endif
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

@include('sales::print.partials.invoice-items', ['v' => $v, 'facts' => $facts, 'paper' => $paper, 'narrow' => true, 'upper' => false])

<table class="bottom">
    <tr>
        <td class="side" style="width: 52%; padding-right: 5.8mm">
            @include('sales::print.partials.invoice-payments', ['v' => $v, 'doc' => $doc])
            <div style="margin-top: 1.7mm; text-align: left">@include('sales::print.partials.invoice-qr', ['v' => $v, 'width' => '14.4mm'])</div>
        </td>
        <td class="side">@include('sales::print.partials.invoice-sums', ['v' => $v, 'paper' => $paper])</td>
    </tr>
</table>

@if ($v->shows('amount_words'))<div class="words" data-words>{{ $facts['words'] }}</div>@endif
<div class="footnote">{!! nl2br(e($v->footnote)) !!}</div>
@include('sales::print.partials.invoice-signatures', ['v' => $v])
<div class="printed">@include('sales::print.partials.invoice-company', ['v' => $v, 'width' => '16mm']) {{ $v->printedAt() }}</div>
