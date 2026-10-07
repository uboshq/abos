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
    body { font-family: hindsiliguri, sans-serif; font-size: 9.5pt; color: #2a1a17; }
    table { border-collapse: collapse; }
    .frame { border: 2.2mm solid {{ $accent }}; padding: 6mm 7mm; }
    .co-name { text-align: center; font-size: 20pt; font-weight: bold; color: {{ $accent }}; }
    .co-meta { text-align: center; font-size: 8.5pt; }
    .title-wrap { text-align: center; margin-top: 2mm; padding-bottom: 3mm; border-bottom: 0.8mm double {{ $accent }}; }
    .title { display: inline; font-size: 13pt; font-weight: bold; border: 0.3mm solid {{ $accent }}; padding: 1mm 6mm; }
    .dup { text-align: center; font-size: 8pt; font-weight: bold; margin-top: 1mm; }
    .notice { text-align: center; font-weight: bold; border: 0.4mm solid #b42318; color: #b42318; padding: 2mm; margin-top: 3mm; font-size: 11pt; }
    table.two { width: 100%; margin-top: 4mm; }
    table.two td { width: 50%; vertical-align: top; font-size: 9pt; line-height: 1.6; padding-right: 4mm; }
    .cap { font-weight: bold; color: {{ $accent }}; font-size: 9pt; }
    .party { font-weight: bold; }
    .sub { font-size: 8pt; color: #6b4f4a; font-weight: normal; }
    table.items { width: 100%; margin-top: 4mm; border: 0.3mm solid {{ $accent }}; }
    table.items th { background: {{ $accent }}; color: #fffdf8; font-size: 9pt; font-weight: bold; padding: 1.8mm; text-align: left; }
    table.items th.num { text-align: right; }
    table.items td { padding: 1.8mm; border-top: 0.25mm solid #e6d3cf; font-size: 9.5pt; vertical-align: top; }
    table.items tr.grand td { background: #f6ebe8; font-weight: bold; border-top: 0.4mm solid {{ $accent }}; }
    .num { text-align: right; white-space: nowrap; }
    td.num { font-family: dejavusans; }
    table.bottom { width: 100%; margin-top: 4mm; }
    table.bottom td.side { vertical-align: top; font-size: 9pt; }
    .pay-head { font-weight: bold; }
    table.pay { width: 100%; margin-top: 1mm; }
    table.pay th { font-size: 8pt; text-align: left; padding: 0.8mm 1mm; border-bottom: 0.25mm solid #e6d3cf; }
    table.pay td { font-size: 8.5pt; padding: 0.8mm 1mm; }
    table.sums { width: 100%; }
    table.sums td { padding: 1mm 1.5mm; font-size: 9.5pt; }
    table.sums tr.net td { font-weight: bold; border-top: 0.3mm solid {{ $accent }}; }
    table.sums tr.owed td { background: #f6ebe8; color: {{ $accent }}; font-weight: bold; }
    .words { margin-top: 3mm; font-size: 9pt; }
    .footnote { margin-top: 3mm; text-align: center; font-size: 9.5pt; font-weight: bold; color: #b42318; }
    table.signatures { width: 100%; margin-top: 14mm; }
    table.signatures td { text-align: center; padding: 0 5mm; font-size: 9.5pt; }
    .sig-line { border-top: 0.3mm dashed #2a1a17; padding-top: 1mm; }
    .printed { margin-top: 3mm; font-size: 7.5pt; text-align: right; }
</style>

<div class="frame">
    {{-- ⭐ লোগো নামের পাশে, উপরে নয় — মালিক, ৩০ সেপ্টেম্বর ২০২৬ --}}
    <table style="margin: 0 auto"><tr>
        @if ($v->logo)<td style="padding-right: 3mm; vertical-align: middle"><img src="{{ $v->logo }}" style="height: 13mm;" alt=""></td>@endif
        <td style="vertical-align: middle"><div class="co-name" data-head-name>{{ trim((string) app(\App\Core\Services\BranchSettings::class)->get('sales.print.header.name')) ?: $company->name('bn') }}</div></td>
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

    @include('sales::print.partials.invoice-items', ['v' => $v, 'facts' => $facts, 'paper' => $paper, 'upper' => false, 'lang' => 'bn'])

    <table class="bottom">
        <tr>
            <td class="side" style="width: 55%; padding-right: 5mm">
                @include('sales::print.partials.invoice-payments', ['v' => $v, 'doc' => $doc, 'lang' => 'bn'])
                <div style="margin-top: 3mm; text-align: left">@include('sales::print.partials.invoice-qr', ['v' => $v])</div>
            </td>
            <td class="side">@include('sales::print.partials.invoice-sums', ['v' => $v, 'paper' => $paper, 'lang' => 'bn'])</td>
        </tr>
    </table>

    @if ($v->shows('amount_words'))<div class="words" data-words><strong>{{ $bn('in_words') }}</strong> {{ $facts['words'] }}</div>@endif
    <div class="footnote"><div style="text-align: left; font-size: 80%; line-height: 1.35">{!! nl2br(e($v->footnote)) !!}</div></div>
    @include('sales::print.partials.invoice-signatures', ['v' => $v])
    <div class="printed">{{ $v->printedAt() }}</div>
</div>
