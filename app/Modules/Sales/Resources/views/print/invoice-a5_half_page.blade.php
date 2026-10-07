{{--
    A5 · আধা পাতা — শুরু থেকেই A5-এর জন্য আঁকা নকশা (Design canvas, ১৫): সবুজ দাগের নিচে এক সারির মাথা,
    দুই ছোট ঘরে কাকে আর কোন গাড়িতে, গাঢ় মাথার ছোট ছক, ডানে টাকার সারি, নিচে সই।
    মালিক, ৩০ সেপ্টেম্বর ২০২৬: *"A5 er jonno 21 desine koro"* — বাকি বিশটা A4-এর নকশার ছোট রূপ, এটা নিজের।
    ⓘ সুইচ আর `data-*` চিহ্ন ভাগের partial-এ ([[InvoicePaperView]])।
--}}
@php
    $v = new \App\Modules\Sales\Support\InvoicePaperView($doc, $facts, $company, $profile);
    $accent = '#0f5c4d';
@endphp

<style @nonce>
    body { font-family: hindsiliguri, sans-serif; font-size: 7.5pt; color: #1c1f1e; }
    table { border-collapse: collapse; }
    table.head { width: 100%; border-bottom: 0.5mm solid {{ $accent }}; }
    table.head td { vertical-align: middle; padding-bottom: 1.5mm; }
    .co-name { font-size: 11pt; font-weight: bold; }
    .co-meta { font-size: 6.5pt; color: #5b625f; }
    .title { text-align: right; font-size: 11pt; font-weight: bold; color: {{ $accent }}; letter-spacing: 0.6mm; }
    .no { text-align: right; font-size: 7.5pt; }
    .dup { text-align: right; font-size: 6.5pt; font-weight: bold; color: #5b625f; }
    .notice { text-align: center; font-weight: bold; border: 0.3mm solid #b42318; color: #b42318; padding: 1.2mm; margin-top: 1.5mm; font-size: 8.5pt; }
    table.two { width: 100%; margin-top: 2mm; }
    table.two td.box { width: 49%; background: #f5f7f6; padding: 1.8mm 2.2mm; vertical-align: top; font-size: 7pt; line-height: 1.35; }
    .cap { font-size: 6.5pt; font-weight: bold; color: {{ $accent }}; }
    .party { font-weight: bold; font-size: 7.5pt; }
    .sub { font-size: 6.5pt; color: #5b625f; font-weight: normal; }
    table.items { width: 100%; margin-top: 2mm; }
    table.items th { background: #1c1f1e; color: #fff; font-size: 6.5pt; font-weight: bold; padding: 1.1mm 1mm; text-align: left; }
    table.items th.num { text-align: right; }
    table.items td { padding: 1mm; border-bottom: 0.2mm solid #e3e6e5; font-size: 7.5pt; vertical-align: top; }
    table.items tr.grand td { background: #eef4f2; font-weight: bold; border-bottom: 0; }
    .free { color: {{ $accent }}; font-weight: bold; }
    .num { text-align: right; white-space: nowrap; }
    td.num { font-family: dejavusans; font-size: 7.5pt; }
    table.bottom { width: 100%; margin-top: 2mm; }
    table.bottom td.side { vertical-align: top; font-size: 7pt; }
    .pay-head { font-size: 6.5pt; font-weight: bold; color: {{ $accent }}; }
    table.pay { width: 100%; margin-top: 0.5mm; }
    table.pay th { font-size: 6pt; color: #5b625f; text-align: left; padding: 0.4mm 0.6mm; }
    table.pay td { font-size: 6.5pt; padding: 0.4mm 0.6mm; }
    table.sums { width: 100%; }
    table.sums td { padding: 0.5mm 1mm; font-size: 7.5pt; }
    table.sums tr.net td { font-weight: bold; border-top: 0.2mm solid #1c1f1e; }
    table.sums tr.owed td { background: #fdf0e1; color: #8a4a0c; font-weight: bold; }
    .words { margin-top: 1mm; font-size: 6.5pt; }
    .footnote { margin-top: 1.5mm; font-size: 7pt; font-weight: bold; color: #b42318; }
    table.signatures { width: 100%; margin-top: 7mm; }
    table.signatures td { text-align: center; padding: 0 2mm; font-size: 7pt; }
    .sig-line { border-top: 0.2mm solid #1c1f1e; padding-top: 0.6mm; }
    .printed { margin-top: 1.5mm; font-size: 6pt; color: #5b625f; }
</style>

<table class="head">
    <tr>
        <td>
            <table><tr>
                @if ($v->logo)<td style="padding-right: 2mm; vertical-align: middle"><img src="{{ $v->logo }}" style="height: 9mm;" alt=""></td>@endif
                <td style="vertical-align: middle">
                    <div class="co-name">{{ $v->head['name'] }}</div>
                    <div class="co-meta">
                        @if ($v->head['address'] !== '')<span data-head-address>{{ $v->head['address'] }}</span>@endif
                        @if ($v->head['phone'] !== '') · {{ $v->head['phone'] }}@endif
                    </div>
                    @if ($v->taxIds !== '')<div class="co-meta" data-tax-ids>{{ $v->taxIds }}</div>@endif
                </td>
            </tr></table>
        </td>
        <td style="width: 16mm; text-align: center">@include('sales::print.partials.invoice-qr', ['v' => $v, 'width' => '13mm'])</td>
        <td style="width: 38mm">
            <div class="title">{{ $v->en('heading') }}</div>
            <div class="no">{{ $facts['bill']['bill_no'] }} · {{ $facts['bill']['bill_date'] }}</div>
            @if ($v->shows('invoice_type'))<div class="no" data-invoice-type>{{ $facts['bill']['type'] }}</div>@endif
            @if ($v->duplicate)<div class="dup" data-duplicate>{{ $v->en('duplicate') }}</div>@endif
        </td>
    </tr>
</table>

@if ($v->notices !== [])<div class="notice">{{ implode(' · ', $v->notices) }}</div>@endif

<table class="two">
    <tr>
        <td class="box">@include('sales::print.partials.invoice-bill-to', ['v' => $v, 'facts' => $facts])</td>
        <td style="width: 2%"></td>
        <td class="box">
            @include('sales::print.partials.invoice-transport', ['v' => $v, 'facts' => $facts])
            @if ($v->shows('order_no'))<div data-order-no>{{ $v->en('order_no') }} {{ $facts['bill']['order_no'] }}</div>@endif
        </td>
    </tr>
</table>

@include('sales::print.partials.invoice-items', ['v' => $v, 'facts' => $facts, 'paper' => $paper, 'narrow' => true])

<table class="bottom">
    <tr>
        <td class="side" style="width: 54%; padding-right: 3mm">
            @include('sales::print.partials.invoice-payments', ['v' => $v, 'doc' => $doc])
            @if ($v->shows('amount_words'))<div class="words" data-words><strong>{{ $v->en('in_words') }}</strong> {{ $facts['words'] }}</div>@endif
        </td>
        <td class="side">@include('sales::print.partials.invoice-sums', ['v' => $v, 'paper' => $paper])</td>
    </tr>
</table>

<div class="footnote"><div style="text-align: left; font-size: 80%; line-height: 1.35">{!! nl2br(e($v->footnote)) !!}</div></div>
@include('sales::print.partials.invoice-signatures', ['v' => $v])
<div class="printed">{{ $v->printedAt() }}</div>
