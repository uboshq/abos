{{-- ⓘ A5 সংস্করণ — A4-এর swiss_grid থেকে মাপ ছোট করে (mm ×০.৭২, অক্ষর ×০.৮৫, সীমা ৬.৫pt), চেহারা একই। মালিক, ৩০ সেপ্টেম্বর ২০২৬: "A5 er jonno 21 desine koro" --}}
{{--
    ১৬ · সুইস আন্তর্জাতিক ধাঁচ — কড়া গ্রিড, খুব বড় বিল নম্বর, মোটা কালো দাগ, একটামাত্র লাল চিহ্ন।
    নমুনা: Design canvas, ১৬। ⓘ সুইচ আর `data-*` চিহ্ন ভাগের partial-এ ([[InvoicePaperView]])।
--}}
@php
    $v = new \App\Modules\Sales\Support\InvoicePaperView($doc, $facts, $company, $profile);
    $red = '#e2231a';
@endphp

<style @nonce>
    body { font-family: hindsiliguri, sans-serif; font-size: 7.6pt; color: #111; }
    table { border-collapse: collapse; }
    table.head { width: 100%; }
    table.head td { vertical-align: top; }
    .square { width: 4.3mm; height: 4.3mm; background: {{ $red }}; }
    .co-name { font-size: 11pt; font-weight: bold; margin-top: 1.4mm; }
    .co-meta { font-size: 6.5pt; color: #555; }
    .title { text-align: right; font-size: 7.6pt; font-weight: bold; color: {{ $red }}; }
    .no { text-align: right; font-size: 28.9pt; font-weight: bold; line-height: 1; font-family: dejavusans; letter-spacing: -0.4mm; }
    .dup { text-align: right; font-size: 6.5pt; font-weight: bold; }
    .notice { text-align: center; font-weight: bold; border: 0.4mm solid {{ $red }}; color: {{ $red }}; padding: 1.4mm; margin-top: 2.2mm; font-size: 9.3pt; }
    table.grid { width: 100%; margin-top: 5mm; border-top: 0.5mm solid #111; }
    table.grid td { width: 25%; vertical-align: top; font-size: 7.2pt; line-height: 1.5; padding: 2.2mm 2.2mm 0 0; }
    .cap { font-weight: bold; font-size: 6.8pt; }
    .party { font-weight: normal; }
    .sub { font-size: 6.5pt; color: #555; }
    table.items { width: 100%; margin-top: 5mm; }
    table.items th { font-size: 6.5pt; font-weight: bold; padding: 0 1.1mm 1.3mm 0; text-align: left; border-bottom: 0.5mm solid #111; }
    table.items th.num { text-align: right; padding-right: 0; }
    table.items td { padding: 1.8mm 1.1mm 1.8mm 0; border-bottom: 0.1mm solid #d4d4d4; font-size: 8.1pt; vertical-align: top; }
    table.items td.num { padding-right: 0; }
    table.items tr.grand td { font-weight: bold; border-bottom: 0; }
    .free { color: {{ $red }}; }
    .num { text-align: right; white-space: nowrap; }
    td.num { font-family: dejavusans; }
    table.bottom { width: 100%; margin-top: 4.3mm; }
    table.bottom td.side { vertical-align: top; font-size: 7.2pt; }
    .pay-head { font-weight: bold; font-size: 6.8pt; }
    table.pay { width: 100%; margin-top: 0.7mm; }
    table.pay th { font-size: 6.5pt; color: #555; font-weight: normal; text-align: left; padding: 0.6mm 0.7mm 0.6mm 0; }
    table.pay td { font-size: 7.2pt; padding: 0.6mm 0.7mm 0.6mm 0; }
    table.sums { width: 100%; }
    table.sums td { padding: 0.9mm 0; font-size: 8.1pt; }
    table.sums tr.net td { font-weight: bold; }
    table.sums tr.owed td { border-top: 0.5mm solid #111; padding-top: 2.2mm; font-size: 14.4pt; font-weight: bold; }
    .words { margin-top: 2.2mm; font-size: 7.2pt; }
    .footnote { margin-top: 3.6mm; font-size: 7.6pt; font-weight: bold; color: {{ $red }}; }
    table.signatures { width: 100%; margin-top: 11.5mm; }
    table.signatures td { padding-right: 5.8mm; font-size: 7.6pt; }
    .sig-line { border-top: 0.2mm solid #111; padding-top: 1.1mm; }
    .printed { margin-top: 3.6mm; font-size: 6.5pt; color: #555; }
</style>

<table class="head">
    <tr>
        <td style="width: 45%">
{{-- ⭐ লোগো নামের বাঁয়ে — মালিক, ৩০ সেপ্টেম্বর ২০২৬ (নমুনা PDF দেখে) --}}
            <table><tr>
                <td style="padding-right: 2.2mm; vertical-align: middle">@if ($v->logo)<img src="{{ $v->logo }}" style="height: 8.6mm;" alt="">@else<div class="square"></div>@endif</td>
                <td style="vertical-align: middle">
                    <div class="co-name" style="margin-top: 0">{{ $v->head['name'] }}</div>
                    @include('sales::print.partials.invoice-company', ['v' => $v])
                </td>
            </tr></table>
        </td>
        <td>
            <div class="title">{{ ucfirst(mb_strtolower($v->en('heading'))) }}</div>
            <div class="no">{{ $facts['bill']['bill_no'] }}</div>
            @if ($v->duplicate)<div class="dup" data-duplicate>{{ $v->en('duplicate') }}</div>@endif
        </td>
    </tr>
</table>

@if ($v->notices !== [])<div class="notice">{{ implode(' · ', $v->notices) }}</div>@endif

<table class="grid">
    <tr>
        <td>@include('sales::print.partials.invoice-bill-to', ['v' => $v, 'facts' => $facts])</td>
        <td>@include('sales::print.partials.invoice-transport', ['v' => $v, 'facts' => $facts])</td>
        <td colspan="2"><div class="cap">{{ __('sales::invoice_design.details', [], 'en') }}</div>@include('sales::print.partials.invoice-bill-facts', ['v' => $v, 'layout' => 'rows'])</td>
    </tr>
</table>

@include('sales::print.partials.invoice-items', ['v' => $v, 'facts' => $facts, 'paper' => $paper, 'narrow' => true, 'upper' => false])

<table class="bottom">
    <tr>
        <td class="side" style="width: 50%; padding-right: 5.8mm">
            @include('sales::print.partials.invoice-payments', ['v' => $v, 'doc' => $doc])
            @if ($v->shows('amount_words'))<div class="words" data-words><strong>{{ $v->en('in_words') }}</strong> {{ $facts['words'] }}</div>@endif
            <div style="margin-top: 2.9mm; text-align: left">@include('sales::print.partials.invoice-qr', ['v' => $v, 'width' => '16mm'])</div>
        </td>
        <td class="side">@include('sales::print.partials.invoice-sums', ['v' => $v, 'paper' => $paper])</td>
    </tr>
</table>

<div class="footnote">{!! nl2br(e($v->footnote)) !!}</div>
@include('sales::print.partials.invoice-signatures', ['v' => $v])
<div class="printed">{{ $v->printedAt() }}</div>
