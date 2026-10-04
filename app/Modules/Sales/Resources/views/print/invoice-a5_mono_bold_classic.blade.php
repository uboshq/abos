{{--
    ⓘ A5 রূপ — A4-এর থেকে তৈরি (mm ×০.৭২, অক্ষর ×০.৮৫, সীমা ৬.৫pt; make_a5_new)। A4-এ বদলালে এটাও নতুন করে বানাতে হয়।
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
    body { font-family: hindsiliguri, sans-serif; font-size: 7.6pt; color: #111; }
    table { border-collapse: collapse; }
    table.head { width: 100%; }
    table.head td { vertical-align: middle; }
    .co-name { font-size: 12.8pt; font-weight: bold; color: #000; }
    .co-meta { font-size: 6.5pt; color: #555; }
    .title { text-align: right; font-family: {{ $titleFont ?? 'playfair' }}, freeserif; font-size: 32.3pt; font-weight: bold; letter-spacing: 1.44mm; color: #000; line-height: 1; }
    .dup { text-align: right; font-size: 6.5pt; font-weight: bold; margin-top: 0.72mm; }
    .notice { text-align: center; font-weight: bold; border: 0.36mm solid #000; padding: 1.44mm; margin-top: 2.16mm; font-size: 9.3pt; }
    .cap { font-size: 6.5pt; font-weight: bold; letter-spacing: 0.36mm; color: #555; }
    table.three { width: 100%; margin-top: 4.32mm; border-top: 1.01mm solid #000; padding-top: 2.16mm; }
    table.three td { width: 33%; vertical-align: top; font-size: 7.2pt; line-height: 1.5; padding-right: 3.6mm; }
    .party { font-weight: bold; font-size: 8.5pt; }
    .sub { font-size: 6.5pt; color: #555; font-weight: normal; }
    table.items { width: 100%; margin-top: 4.32mm; }
    table.items th { color: #000; border-top: 0.6mm solid #000; border-bottom: 0.3mm solid #000; font-size: 6.5pt; font-weight: bold; letter-spacing: 0.22mm; padding: 1.44mm; text-align: left; }
    table.items th.num { text-align: right; }
    table.items td { padding: 1.58mm 1.44mm; border-bottom: 0.14mm solid #c8c8c8; font-size: 7.6pt; vertical-align: top; }
    table.items tr.grand td { font-weight: bold; border-top: 0.43mm solid #000; border-bottom: 0.43mm solid #000; }
    .free { font-weight: bold; }
    .num { text-align: right; white-space: nowrap; }
    td.num { font-family: dejavusans; }
    table.bottom { width: 100%; margin-top: 3.6mm; }
    table.bottom td.side { vertical-align: top; font-size: 7.2pt; }
    .pay-head { font-size: 6.5pt; font-weight: bold; letter-spacing: 0.36mm; color: #555; }
    table.pay { width: 100%; margin-top: 0.72mm; }
    table.pay th { font-size: 6.5pt; color: #555; text-align: left; padding: 0.72mm; border-bottom: 0.22mm solid #000; }
    table.pay td { font-size: 7.2pt; padding: 0.72mm; border-bottom: 0.14mm solid #c8c8c8; }
    table.sums { width: 100%; }
    table.sums td { padding: 0.79mm 1.44mm; font-size: 7.6pt; }
    table.sums tr.net td { font-weight: bold; border-top: 0.22mm solid #000; }
    table.sums tr.owed td { border-top: 0.6mm solid #000; border-bottom: 0.6mm solid #000; font-weight: bold; font-size: 9.3pt; padding: 1.8mm 1.44mm; }
    .words { margin-top: 1.44mm; font-size: 7.2pt; }
    .footnote { margin-top: 2.88mm; font-size: 7.6pt; font-weight: bold; text-align: center; }
    table.signatures { width: 100%; margin-top: 10.08mm; }
    table.signatures td { text-align: center; padding: 0 3.6mm; font-size: 7.2pt; }
    .sig-line { border-top: 0.22mm solid #000; padding-top: 0.72mm; }
    table.foot { width: 100%; margin-top: 3.6mm; border-top: 0.22mm solid #000; }
    table.foot td { padding-top: 1.08mm; font-size: 6.5pt; color: #555; }
</style>

<table class="head">
    <tr>
        <td>
            <table><tr>
                @if ($v->logo)<td style="padding-right: 2.16mm; vertical-align: middle"><img src="{{ $v->logo }}" style="height: 10.08mm;" alt=""></td>@endif
                <td style="vertical-align: middle">
                    <div class="co-name">{{ $v->head['name'] }}</div>
                    @include('sales::print.partials.invoice-company', ['v' => $v])
                </td>
            </tr></table>
        </td>
        <td style="width: 50.4mm">
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

@include('sales::print.partials.invoice-items', ['v' => $v, 'facts' => $facts, 'paper' => $paper, 'narrow' => true])

<table class="bottom">
    <tr>
        <td class="side" style="width: 52%; padding-right: 5.76mm">
            @include('sales::print.partials.invoice-payments', ['v' => $v, 'doc' => $doc])
            @if ($v->shows('amount_words'))<div class="words" data-words><strong>{{ $v->en('in_words') }}</strong> {{ $facts['words'] }}</div>@endif
        </td>
        <td class="side">@include('sales::print.partials.invoice-sums', ['v' => $v, 'paper' => $paper])</td>
    </tr>
</table>

<div class="footnote"><div style="text-align: left; font-size: 80%; line-height: 1.35">{!! nl2br(e($v->footnote)) !!}</div></div>
{{-- ⭐ QR সইয়ের সারির ডানে, "অনুমোদনকারী"-র পাশে — মালিক, ৪ অক্টোবর ২০২৬ (আগে নিচে বাঁয়ে) --}}
<table style="width: 100%"><tr>
    <td style="vertical-align: bottom">@include('sales::print.partials.invoice-signatures', ['v' => $v])</td>
    @if ($v->qr !== '')<td style="width: 19mm; vertical-align: bottom; padding-left: 2mm">@include('sales::print.partials.invoice-qr', ['v' => $v, 'width' => '16mm'])</td>@endif
</tr></table>

<table class="foot">
    <tr>
        <td>{{ $v->head['name'] }}@if ($v->head['phone'] !== '') · {{ $v->head['phone'] }}@endif</td>
        <td style="text-align: right">{{ $v->printedAt() }}</td>
    </tr>
</table>
