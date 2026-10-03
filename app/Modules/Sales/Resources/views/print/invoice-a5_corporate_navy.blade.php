{{-- ⓘ A5 সংস্করণ — A4-এর corporate_navy থেকে মাপ ছোট করে (mm ×০.৭২, অক্ষর ×০.৮৫, সীমা ৬.৫pt), চেহারা একই। মালিক, ৩০ সেপ্টেম্বর ২০২৬: "A5 er jonno 21 desine koro" --}}
{{--
    ২ · কর্পোরেট নীল — মাথায় পুরো চওড়া নীল পট্টি, নিচে তিন কলাম, মোট বকেয়া নীল ঘরে; পায়ে নীল দাগ।
    নমুনা: Design canvas "আধুনিক বিলের নমুনা", ২।

    ⓘ কী আঁকা হবে তা [[InvoicePaperView]] আর ভাগের partial-গুলো বলে (সুইচ আর `data-*` চিহ্ন সেখানেই);
    এখানে কেবল সাজ আর ঘরের জায়গা। mPDF-এর জন্য কেবল table, টাকার ঘর DejaVu।
--}}
@php($v = new \App\Modules\Sales\Support\InvoicePaperView($doc, $facts, $company, $profile))

<style @nonce>
    body { font-family: hindsiliguri, sans-serif; font-size: 7.6pt; color: #1b2333; }
    table { border-collapse: collapse; }
    table.band { width: 100%; background: #1d3f8f; color: #fff; }
    table.band td { padding: 3.6mm 4.3mm; vertical-align: middle; }
    .co-name { font-size: 14.4pt; font-weight: bold; }
    .co-meta { font-size: 6.8pt; color: #c9d5f2; }
    .title { text-align: right; font-size: 7.6pt; letter-spacing: 0.9mm; color: #c9d5f2; }
    .no { text-align: right; font-size: 13.6pt; font-weight: bold; }
    .dup { text-align: right; font-size: 6.5pt; font-weight: bold; color: #c9d5f2; }
    .notice { text-align: center; font-weight: bold; border: 0.3mm solid #b42318; color: #b42318; padding: 1.4mm; margin-top: 2.2mm; font-size: 9.3pt; }
    table.three { width: 100%; margin-top: 3.6mm; }
    table.three td { width: 33.3%; vertical-align: top; font-size: 7.2pt; padding-right: 2.9mm; }
    .cap { font-size: 6.5pt; font-weight: bold; color: #1d3f8f; }
    .party { font-weight: bold; font-size: 8.5pt; }
    .sub { font-size: 6.5pt; color: #5b6477; font-weight: normal; }
    table.items { width: 100%; margin-top: 3.6mm; border: 0.2mm solid #c9d5f2; }
    table.items th { background: #e8eefb; color: #1d3f8f; font-size: 6.5pt; font-weight: bold; padding: 1.4mm; text-align: left; }
    table.items th.num { text-align: right; }
    table.items td { padding: 1.4mm; border-top: 0.2mm solid #e3e8f4; font-size: 7.6pt; vertical-align: top; }
    table.items tr.grand td { background: #1d3f8f; color: #fff; font-weight: bold; }
    .free { color: #1d3f8f; font-weight: bold; }
    .num { text-align: right; white-space: nowrap; }
    td.num { font-family: dejavusans; }
    .words { margin-top: 2.2mm; font-size: 7.2pt; }
    table.bottom { width: 100%; margin-top: 2.9mm; }
    table.bottom td.side { vertical-align: top; }
    .pay-head { font-size: 6.5pt; font-weight: bold; color: #1d3f8f; }
    table.pay { width: 100%; margin-top: 0.7mm; }
    table.pay th { font-size: 6.5pt; color: #5b6477; padding: 0.9mm 1.1mm; text-align: left; border-bottom: 0.2mm solid #c9d5f2; }
    table.pay td { font-size: 6.8pt; padding: 1mm 1.1mm; border-bottom: 0.2mm solid #e3e8f4; }
    table.sums { width: 100%; }
    table.sums td { padding: 0.9mm 1.4mm; font-size: 7.6pt; }
    table.sums tr.net td { border-top: 0.4mm solid #1d3f8f; font-weight: bold; font-size: 8.5pt; }
    table.sums tr.owed td { background: #e8eefb; color: #1d3f8f; font-weight: bold; padding: 1.6mm 1.4mm; }
    .footnote { margin-top: 2.9mm; font-size: 7.6pt; font-weight: bold; color: #b42318; }
    table.signatures { width: 100%; margin-top: 10.1mm; }
    table.signatures td { text-align: center; padding: 0 3.6mm; font-size: 7.6pt; }
    .sig-line { border-top: 0.2mm solid #1b2333; padding-top: 0.7mm; }
    table.foot { width: 100%; margin-top: 3.6mm; background: #1d3f8f; }
    table.foot td { font-size: 6.5pt; color: #c9d5f2; padding: 1.1mm 2.9mm; }
</style>

<table class="band">
    <tr>
        <td>
            @if ($v->logo)<img src="{{ $v->logo }}" style="height: 7.9mm; margin-bottom: 0.7mm;" alt="">@endif
            <div class="co-name">{{ $v->head['name'] }}</div>
            @include('sales::print.partials.invoice-company', ['v' => $v])
        </td>
        <td style="width: 43.2mm">
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

@if ($v->shows('amount_words'))<div class="words" data-words><strong>{{ $v->en('in_words') }}</strong> {{ $facts['words'] }}</div>@endif

<table class="bottom">
    <tr>
        <td class="side" style="width: 57%; padding-right: 4.3mm">
            @include('sales::print.partials.invoice-payments', ['v' => $v, 'doc' => $doc])
            <div style="margin-top: 2.9mm; text-align: left">@include('sales::print.partials.invoice-qr', ['v' => $v, 'width' => '16mm'])</div>
        </td>
        <td class="side">@include('sales::print.partials.invoice-sums', ['v' => $v, 'paper' => $paper])</td>
    </tr>
</table>

<div class="footnote"><div style="text-align: left; font-size: 50%; line-height: 1.35">{!! nl2br(e($v->footnote)) !!}</div></div>
@include('sales::print.partials.invoice-signatures', ['v' => $v])
<table class="foot"><tr><td>{{ $v->printedAt() }}</td></tr></table>
