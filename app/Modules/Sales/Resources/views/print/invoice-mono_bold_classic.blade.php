{{--
    ২৬ · মোনো সাহসী ক্লাসিক — ২২ (মোনো সাহসী ২০২৬)-এর সবকিছু, তিন বদলে। মালিক, ৩০ সেপ্টেম্বর ২০২৬:
    সারাংশের পট্টি বাদ; বিলের তারিখ-নম্বর-ধরন DETAILS ঘরে; উপরে ডানের "INVOICE" আরও সুন্দর অক্ষরে।
    ⓘ অক্ষর `$titleFont` — mPDF-এর নিজের হরফ (freeserif · dejavuserif · dejavuserifcondensed), বাইরের হরফ লাগে না।
    ⓘ সুইচ আর `data-*` চিহ্ন ভাগের partial-এ ([[InvoicePaperView]])।
--}}
@php
    $v = new \App\Modules\Sales\Support\InvoicePaperView($doc, $facts, $company, $profile);
    $s = $v->sums;
@endphp

<style @nonce>
    body { font-family: hindsiliguri, sans-serif; font-size: 9pt; color: #111; }
    table { border-collapse: collapse; }
    table.head { width: 100%; }
    table.head td { vertical-align: middle; }
    .co-name { font-size: 15pt; font-weight: bold; color: #000; }
    .co-meta { font-size: 7.5pt; color: #555; }
    .title { text-align: right; font-family: {{ $titleFont ?? 'playfair' }}, freeserif; font-size: 38pt; font-weight: bold; letter-spacing: 2mm; color: #000; line-height: 1; }
    .dup { text-align: right; font-size: 7.5pt; font-weight: bold; margin-top: 1mm; }
    .notice { text-align: center; font-weight: bold; border: 0.5mm solid #000; padding: 2mm; margin-top: 3mm; font-size: 11pt; }
    .cap { font-size: 6.8pt; font-weight: bold; letter-spacing: 0.5mm; color: #555; }
    table.three { width: 100%; margin-top: 6mm; border-top: 1.4mm solid #000; padding-top: 3mm; }
    table.three td { width: 33%; vertical-align: top; font-size: 8.5pt; line-height: 1.5; padding-right: 5mm; }
    .party { font-weight: bold; font-size: 10pt; }
    .sub { font-size: 7.5pt; color: #555; font-weight: normal; }
    table.items { width: 100%; margin-top: 6mm; }
    table.items th { background: #000; color: #fff; font-size: 7.5pt; font-weight: bold; letter-spacing: 0.3mm; padding: 2mm; text-align: left; }
    table.items th.num { text-align: right; }
    table.items td { padding: 2.2mm 2mm; border-bottom: 0.2mm solid #c8c8c8; font-size: 9pt; vertical-align: top; }
    table.items tr.grand td { font-weight: bold; border-top: 0.6mm solid #000; border-bottom: 0.6mm solid #000; }
    .free { font-weight: bold; }
    .num { text-align: right; white-space: nowrap; }
    td.num { font-family: dejavusans; }
    table.bottom { width: 100%; margin-top: 5mm; }
    table.bottom td.side { vertical-align: top; font-size: 8.5pt; }
    .pay-head { font-size: 7pt; font-weight: bold; letter-spacing: 0.5mm; color: #555; }
    table.pay { width: 100%; margin-top: 1mm; }
    table.pay th { font-size: 7pt; color: #555; text-align: left; padding: 1mm; border-bottom: 0.3mm solid #000; }
    table.pay td { font-size: 8.5pt; padding: 1mm; border-bottom: 0.2mm solid #c8c8c8; }
    table.sums { width: 100%; }
    table.sums td { padding: 1.1mm 2mm; font-size: 9pt; }
    table.sums tr.net td { font-weight: bold; border-top: 0.3mm solid #000; }
    table.sums tr.owed td { background: #000; color: #fff; font-weight: bold; font-size: 11pt; padding: 2.5mm 2mm; }
    .words { margin-top: 2mm; font-size: 8.5pt; }
    .footnote { margin-top: 4mm; font-size: 9pt; font-weight: bold; text-align: center; }
    table.signatures { width: 100%; margin-top: 14mm; }
    table.signatures td { text-align: center; padding: 0 5mm; font-size: 8.5pt; }
    .sig-line { border-top: 0.3mm solid #000; padding-top: 1mm; }
    table.foot { width: 100%; margin-top: 5mm; border-top: 0.3mm solid #000; }
    table.foot td { padding-top: 1.5mm; font-size: 7pt; color: #555; }
</style>

<table class="head">
    <tr>
        <td>
            <table><tr>
                @if ($v->logo)<td style="padding-right: 3mm; vertical-align: middle"><img src="{{ $v->logo }}" style="height: 14mm;" alt=""></td>@endif
                <td style="vertical-align: middle">
                    <div class="co-name">{{ $v->head['name'] }}</div>
                    @include('sales::print.partials.invoice-company', ['v' => $v])
                </td>
            </tr></table>
        </td>
        <td style="width: 70mm">
            <div class="title">{{ mb_strtoupper($v->en('heading')) }}</div>
            @if ($v->duplicate)<div class="dup" data-duplicate>{{ $v->en('duplicate') }}</div>@endif
        </td>
    </tr>
</table>

@if ($v->notices !== [])<div class="notice">{{ implode(' · ', $v->notices) }}</div>@endif

<table class="three">
    <tr>
        <td>@include('sales::print.partials.invoice-bill-to', ['v' => $v, 'facts' => $facts])</td>
        <td>@include('sales::print.partials.invoice-transport', ['v' => $v, 'facts' => $facts])</td>
        <td style="padding-right: 0">
            {{-- ⭐ বিলের তারিখ, নম্বর, অর্ডার, ধরন, তৈরি — সব এখানে (উপরের পট্টি নেই) --}}
            <div class="cap">{{ mb_strtoupper(__('sales::invoice_design.details', [], 'en')) }}</div>
            @include('sales::print.partials.invoice-bill-facts', ['v' => $v, 'layout' => 'rows'])
        </td>
    </tr>
</table>

@include('sales::print.partials.invoice-items', ['v' => $v, 'facts' => $facts, 'paper' => $paper])

<table class="bottom">
    <tr>
        <td class="side" style="width: 52%; padding-right: 8mm">
            @include('sales::print.partials.invoice-payments', ['v' => $v, 'doc' => $doc])
            @if ($v->shows('amount_words'))<div class="words" data-words><strong>{{ $v->en('in_words') }}</strong> {{ $facts['words'] }}</div>@endif
            <div style="margin-top: 4mm">@include('sales::print.partials.invoice-qr', ['v' => $v, 'width' => '20mm'])</div>
        </td>
        <td class="side">@include('sales::print.partials.invoice-sums', ['v' => $v, 'paper' => $paper])</td>
    </tr>
</table>

<div class="footnote"><div style="text-align: left; font-size: 50%; line-height: 1.35">{!! nl2br(e($v->footnote)) !!}</div></div>
@include('sales::print.partials.invoice-signatures', ['v' => $v])

<table class="foot">
    <tr>
        <td>{{ $v->head['name'] }}@if ($v->head['phone'] !== '') · {{ $v->head['phone'] }}@endif</td>
        <td style="text-align: right">{{ $v->printedAt() }}</td>
    </tr>
</table>
