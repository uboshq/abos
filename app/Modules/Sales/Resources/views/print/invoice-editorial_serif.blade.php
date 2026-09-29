{{--
    ১৭ · সম্পাদকীয় সেরিফ — পত্রিকার মতো: মাঝখানে বড় সেরিফ নাম, হালকা ঘিয়ে জমিন, সরু দাগ, মোটের নিচে
    দুই দাগ। নমুনা: Design canvas, ১৭। ⓘ সুইচ আর `data-*` চিহ্ন ভাগের partial-এ ([[InvoicePaperView]])।
    ⓘ সেরিফ অক্ষর mPDF-এর নিজের `dejavuserif` — বাইরের হরফ লাগে না।
--}}
@php($v = new \App\Modules\Sales\Support\InvoicePaperView($doc, $facts, $company, $profile))

<style @nonce>
    body { font-family: hindsiliguri, sans-serif; font-size: 9pt; color: #2b2622; background: #fcfaf6; }
    table { border-collapse: collapse; }
    .serif { font-family: dejavuserif, serif; }
    .masthead { text-align: left; border-bottom: 0.3mm solid #2b2622; padding-bottom: 4mm; }
    .co-name { font-family: dejavuserif, serif; font-size: 24pt; font-weight: bold; }
    .co-meta { font-size: 7.5pt; letter-spacing: 0.4mm; color: #7a6f63; }
    table.titleline { width: 100%; margin-top: 5mm; }
    table.titleline td { vertical-align: bottom; }
    .title { font-family: dejavuserif, serif; font-size: 16pt; font-style: italic; }
    .when { text-align: right; font-size: 8.5pt; color: #7a6f63; }
    .dup { text-align: right; font-size: 7.5pt; font-weight: bold; color: #7a6f63; }
    .notice { text-align: center; font-weight: bold; border: 0.4mm solid #9b2c2c; color: #9b2c2c; padding: 2mm; margin-top: 3mm; font-size: 11pt; }
    table.two { width: 100%; margin-top: 5mm; }
    table.two td { width: 50%; vertical-align: top; font-size: 9pt; line-height: 1.6; padding-right: 6mm; }
    .cap { font-family: dejavuserif, serif; font-style: italic; color: #7a6f63; font-size: 10pt; }
    .party { font-weight: bold; }
    .sub { font-size: 7.5pt; color: #7a6f63; font-weight: normal; }
    table.items { width: 100%; margin-top: 6mm; }
    table.items th { font-size: 7pt; letter-spacing: 0.5mm; color: #7a6f63; font-weight: normal; padding: 0 1.5mm 1.8mm 0; text-align: left; border-bottom: 0.3mm solid #2b2622; }
    table.items th.num { text-align: right; padding-right: 0; }
    table.items td { padding: 2.6mm 1.5mm 2.6mm 0; border-bottom: 0.2mm solid #e7e1d6; font-size: 9.5pt; vertical-align: top; }
    table.items td.item-name { font-family: dejavuserif, serif; font-size: 10pt; }
    table.items tr.grand td { font-weight: bold; border-bottom: 0; }
    .num { text-align: right; white-space: nowrap; }
    td.num { font-family: dejavusans; }
    table.bottom { width: 100%; margin-top: 5mm; }
    table.bottom td.side { vertical-align: top; font-size: 8.5pt; }
    .pay-head { font-family: dejavuserif, serif; font-style: italic; color: #7a6f63; font-size: 9.5pt; }
    table.pay { width: 100%; margin-top: 1mm; }
    table.pay th { font-size: 7pt; color: #7a6f63; font-weight: normal; text-align: left; padding: 0.8mm 1mm 0.8mm 0; }
    table.pay td { font-size: 8.5pt; padding: 0.8mm 1mm 0.8mm 0; }
    table.sums { width: 100%; }
    table.sums td { padding: 1.2mm 0; font-size: 9.5pt; }
    table.sums tr.net td { font-weight: bold; }
    table.sums tr.owed td { font-family: dejavuserif, serif; border-top: 0.3mm solid #2b2622; border-bottom: 1mm double #2b2622; padding: 2.5mm 0; font-size: 13pt; font-weight: bold; }
    .words { margin-top: 5mm; text-align: center; font-family: dejavuserif, serif; font-style: italic; color: #7a6f63; font-size: 10pt; }
    .footnote { margin-top: 3mm; text-align: center; font-size: 9pt; font-weight: bold; color: #9b2c2c; }
    table.signatures { width: 100%; margin-top: 15mm; }
    table.signatures td { text-align: center; padding: 0 6mm; font-size: 9pt; }
    .sig-line { border-top: 0.3mm solid #2b2622; padding-top: 1.2mm; }
    .printed { margin-top: 4mm; text-align: center; font-size: 7pt; color: #7a6f63; }
</style>

<div class="masthead">
    {{-- ⭐ লোগো বাঁয়ে, নাম তার পাশে — মালিক, ৩০ সেপ্টেম্বর ২০২৬ (মাঝখানে বসালে লোগো বাঁয়ে যেত না) --}}
    <table><tr>
        @if ($v->logo)<td style="padding-right: 4mm; vertical-align: middle"><img src="{{ $v->logo }}" style="height: 14mm;" alt=""></td>@endif
        <td style="vertical-align: middle">
            <div class="co-name">{{ $v->head['name'] }}</div>
            {{-- ⭐ ঠিকানা · মোবাইল · BIN এক লাইনে, নামের ঠিক নিচে — মালিক, ৩০ সেপ্টেম্বর ২০২৬ --}}
            <div class="co-meta">
                @if ($v->head['address'] !== '')<span data-head-address>{{ $v->head['address'] }}</span>@endif
                @if ($v->head['phone'] !== '') · {{ $v->head['phone'] }}@endif
                @if ($v->taxIds !== '') · <span data-tax-ids>{{ $v->taxIds }}</span>@endif
            </div>
        </td>
    </tr></table>
</div>

<table class="titleline">
    <tr>
        <td><span class="title">{{ ucfirst(mb_strtolower($v->en('heading'))) }} No. {{ $facts['bill']['bill_no'] }}</span></td>
        <td>
            <div class="when">{{ $facts['bill']['bill_date'] }}@if ($v->shows('order_no')) · <span data-order-no>{{ $facts['bill']['order_no'] }}</span>@endif @if ($v->shows('invoice_type')) · <span data-invoice-type>{{ $facts['bill']['type'] }}</span>@endif</div>
            @if ($v->duplicate)<div class="dup" data-duplicate>{{ $v->en('duplicate') }}</div>@endif
        </td>
    </tr>
</table>

@if ($v->notices !== [])<div class="notice">{{ implode(' · ', $v->notices) }}</div>@endif

<table class="two">
    <tr>
        <td>@include('sales::print.partials.invoice-bill-to', ['v' => $v, 'facts' => $facts])</td>
        <td>@include('sales::print.partials.invoice-transport', ['v' => $v, 'facts' => $facts])</td>
    </tr>
</table>

@include('sales::print.partials.invoice-items', ['v' => $v, 'facts' => $facts, 'paper' => $paper])

<table class="bottom">
    <tr>
        <td class="side" style="width: 52%; padding-right: 8mm">
            @include('sales::print.partials.invoice-payments', ['v' => $v, 'doc' => $doc])
            <div style="margin-top: 4mm; text-align: left">@include('sales::print.partials.invoice-qr', ['v' => $v])</div>
        </td>
        <td class="side">@include('sales::print.partials.invoice-sums', ['v' => $v, 'paper' => $paper])</td>
    </tr>
</table>

@if ($v->shows('amount_words'))<div class="words" data-words>{{ $facts['words'] }}</div>@endif
<div class="footnote">{{ $v->footnote }}</div>
@include('sales::print.partials.invoice-signatures', ['v' => $v])
<div class="printed">{{ $v->printedAt() }}</div>
