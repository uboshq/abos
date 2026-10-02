{{-- ⓘ A5 সংস্করণ — A4-এর bangla_heritage থেকে মাপ ছোট করে (mm ×০.৭২, অক্ষর ×০.৮৫, সীমা ৬.৫pt), চেহারা একই। মালিক, ৩০ সেপ্টেম্বর ২০২৬: "A5 er jonno 21 desine koro" --}}
{{--
    ৫ · পুরো বাংলা — প্রতিটা ঘরের নাম বাংলায় (`sales::print.classic.*`-এর `bn`), মেরুন ফ্রেম, মাঝখানে
    "বিক্রয় বিল"। নমুনা: Design canvas, ৫। ⓘ সুইচ আর `data-*` চিহ্ন ভাগের partial-এ ([[InvoicePaperView]])।

    ⚠️ সংখ্যা ইংরেজি অঙ্কেই — বিলের নম্বর, তারিখ আর টাকা সফটওয়্যারের বাকি সব জায়গার সাথে মেলাতে হয়;
    এক কাগজে দুই রকম অঙ্ক ([[OnePaperShouldNotCarryTwoKindsOfDigitsTest]]) নয়।
--}}
@php
    $v = new \App\Modules\Sales\Support\InvoicePaperView($doc, $facts, $company, $profile);
    $accent = '#7a1f2b';
    $bn = fn (string $key) => $v->label($key, 'bn');
@endphp

<style @nonce>
    body { font-family: hindsiliguri, sans-serif; font-size: 8.1pt; color: #2a1a17; }
    table { border-collapse: collapse; }
    .frame { border: 1.6mm solid {{ $accent }}; padding: 2.6mm 3mm; }
    .co-name { text-align: center; font-size: 17pt; font-weight: bold; color: {{ $accent }}; }
    .co-meta { text-align: center; font-size: 7.2pt; }
    .title-wrap { text-align: center; margin-top: 0.8mm; padding-bottom: 1.3mm; border-bottom: 0.6mm double {{ $accent }}; }
    .title { display: inline; font-size: 11pt; font-weight: bold; border: 0.2mm solid {{ $accent }}; padding: 0.4mm 2.6mm; }
    .dup { text-align: center; font-size: 6.8pt; font-weight: bold; margin-top: 0.4mm; }
    .notice { text-align: center; font-weight: bold; border: 0.3mm solid #b42318; color: #b42318; padding: 0.8mm; margin-top: 1.3mm; font-size: 9.3pt; }
    table.two { width: 100%; margin-top: 1.7mm; }
    table.two td { width: 50%; vertical-align: top; font-size: 7.6pt; line-height: 1.6; padding-right: 2.9mm; }
    .cap { font-weight: bold; color: {{ $accent }}; font-size: 7.6pt; }
    .party { font-weight: bold; }
    .sub { font-size: 6.8pt; color: #6b4f4a; font-weight: normal; }
    table.items { width: 100%; margin-top: 1.7mm; border: 0.2mm solid {{ $accent }}; }
    table.items th { background: {{ $accent }}; color: #fffdf8; font-size: 7.6pt; font-weight: bold; padding: 0.8mm; text-align: left; }
    table.items th.num { text-align: right; }
    table.items td { padding: 0.8mm; border-top: 0.2mm solid #e6d3cf; font-size: 8.1pt; vertical-align: top; }
    table.items tr.grand td { background: #f6ebe8; font-weight: bold; border-top: 0.3mm solid {{ $accent }}; }
    .num { text-align: right; white-space: nowrap; }
    td.num { font-family: dejavusans; }
    table.bottom { width: 100%; margin-top: 1.7mm; }
    table.bottom td.side { vertical-align: top; font-size: 7.6pt; }
    .pay-head { font-weight: bold; }
    table.pay { width: 100%; margin-top: 0.4mm; }
    table.pay th { font-size: 6.8pt; text-align: left; padding: 0.4mm 0.4mm; border-bottom: 0.2mm solid #e6d3cf; }
    table.pay td { font-size: 7.2pt; padding: 0.4mm 0.4mm; }
    table.sums { width: 100%; }
    table.sums td { padding: 0.4mm 0.7mm; font-size: 8.1pt; }
    table.sums tr.net td { font-weight: bold; border-top: 0.2mm solid {{ $accent }}; }
    table.sums tr.owed td { background: #f6ebe8; color: {{ $accent }}; font-weight: bold; }
    .words { margin-top: 1.3mm; font-size: 7.6pt; }
    .footnote { margin-top: 1.3mm; text-align: center; font-size: 8.1pt; font-weight: bold; color: #b42318; }
    table.signatures { width: 100%; margin-top: 6.1mm; }
    table.signatures td { text-align: center; padding: 0 2.2mm; font-size: 8.1pt; }
    .sig-line { border-top: 0.2mm dashed #2a1a17; padding-top: 0.4mm; }
    .printed { margin-top: 1.3mm; font-size: 6.5pt; text-align: right; }
</style>

<div class="frame">
    {{-- ⭐ লোগো নামের পাশে, উপরে নয় — মালিক, ৩০ সেপ্টেম্বর ২০২৬ --}}
    <table style="margin: 0 auto"><tr>
        @if ($v->logo)<td style="padding-right: 2.2mm; vertical-align: middle"><img src="{{ $v->logo }}" style="height: 9.5mm;" alt=""></td>@endif
        <td style="vertical-align: middle"><div class="co-name">{{ $company->name('bn') }}</div></td>
    </tr></table>
    @include('sales::print.partials.invoice-company', ['v' => $v])
    <div class="title-wrap"><span class="title">{{ $bn('heading') }}</span></div>
    @if ($v->duplicate)<div class="dup" data-duplicate>{{ $bn('duplicate') }}</div>@endif
    @if ($v->notices !== [])<div class="notice">{{ implode(' · ', $v->notices) }}</div>@endif

    <table class="two">
        <tr>
            <td>
                <div class="cap">{{ $bn('bill_to') }}</div>
                <div class="party">{{ $facts['bill_to']['name'] }}</div>
                @if (filled($facts['bill_to']['point']))<div>{{ $bn('point') }}: {{ $facts['bill_to']['point'] }}</div>@endif
                @if (filled($facts['bill_to']['address']))<div>{{ $facts['bill_to']['address'] }}</div>@endif
                @if (filled($facts['bill_to']['phone']))<div>{{ $bn('phone') }}: {{ $facts['bill_to']['phone'] }}</div>@endif
            </td>
            <td>
                <div class="cap">{{ __('sales::invoice_design.details', [], 'bn') }}</div>
                <div>{{ $bn('bill_no') }}: {{ $facts['bill']['bill_no'] }} · {{ $bn('bill_date') }}: {{ $facts['bill']['bill_date'] }}</div>
                @if ($v->shows('order_no'))<div data-order-no>{{ $bn('order_no') }}: {{ $facts['bill']['order_no'] }}</div>@endif
                @if ($v->shows('invoice_type'))<div data-invoice-type>{{ $bn('type') }}: {{ $facts['bill']['type'] }}</div>@endif
                @if ($v->shows('transport'))
                    <div data-transport>{{ $bn('carrier') }}: {{ $facts['transport']['carrier'] }} · {{ $facts['transport']['vehicle'] }}</div>
                @endif
            </td>
        </tr>
    </table>

    @include('sales::print.partials.invoice-items', ['v' => $v, 'facts' => $facts, 'paper' => $paper, 'narrow' => true, 'upper' => false, 'lang' => 'bn'])

    <table class="bottom">
        <tr>
            <td class="side" style="width: 55%; padding-right: 3.6mm">
                @include('sales::print.partials.invoice-payments', ['v' => $v, 'doc' => $doc, 'lang' => 'bn'])
                <div style="margin-top: 1.3mm; text-align: left">@include('sales::print.partials.invoice-qr', ['v' => $v, 'width' => '16mm'])</div>
            </td>
            <td class="side">@include('sales::print.partials.invoice-sums', ['v' => $v, 'paper' => $paper, 'lang' => 'bn'])</td>
        </tr>
    </table>

    @if ($v->shows('amount_words'))<div class="words" data-words><strong>{{ $bn('in_words') }}</strong> {{ $facts['words'] }}</div>@endif
    <div class="footnote">{!! nl2br(e($v->footnote)) !!}</div>
    @include('sales::print.partials.invoice-signatures', ['v' => $v])
    <div class="printed">{{ $v->printedAt() }}</div>
</div>
