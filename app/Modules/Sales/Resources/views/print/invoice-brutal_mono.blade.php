{{--
    ২২ · নিও-ব্রুটাল সাদা-কালো — ২০২৫–২৬-এর সবচেয়ে আলোচিত নকশা-ধারা: পুরো কাগজ মোটা কালো দাগের বাক্সে ভাগ,
    টাইপরাইটার-ধাঁচের মোনো অক্ষর, বড় হাতের ছোট লেবেল, উল্টো (কালো) মোটের ঘর, সই-সিলের বড় বাক্স।
    মালিক, ৩০ সেপ্টেম্বর ২০২৬: *"BLACK & WHAITE WORALD LATEST JONOPRIO ... AGER TEMPLATE E DAWNI EMO EKTA"* —
    প্রথম চেষ্টা (সারাংশের পট্টি) ১০ নম্বর "আগে হিসাব"-এর মতো লেগেছিল; এটা আগের কুড়িটার কোনোটার গড়ন নয়।

    ⓘ কেবল কালো আর সাদা — ধূসর ভরাট নেই, তাই ফটোকপিতেও ঝাপসা হয় না। ⓘ সুইচ আর `data-*` চিহ্ন ভাগের
    partial-এ ([[InvoicePaperView]]); ⚠️ মোনো অক্ষরে বাংলা নেই — বাংলা আসতে পারে এমন ঘর বাংলা অক্ষরে।
--}}
@php
    $v = new \App\Modules\Sales\Support\InvoicePaperView($doc, $facts, $company, $profile);
    $B = '0.8mm solid #000';
@endphp

<style @nonce>
    body { font-family: hindsiliguri, sans-serif; font-size: 8.8pt; color: #000; }
    table { border-collapse: collapse; }
    .m { font-family: dejavusansmono, hindsiliguri; }
    .bn { font-family: hindsiliguri, sans-serif; }
    table.grid { width: 100%; }
    table.grid > tr > td, table.grid td.box { border: {{ $B }}; padding: 3mm; vertical-align: top; }
    .lab { font-family: dejavusansmono; font-size: 6.8pt; font-weight: bold; letter-spacing: 0.4mm; margin-bottom: 1mm; }
    .co-name { font-family: dejavusansmono; font-size: 15pt; font-weight: bold; }
    .co-meta { font-size: 7.5pt; }
    .title { font-family: dejavusansmono; font-size: 30pt; font-weight: bold; letter-spacing: 1mm; line-height: 1; }
    .dup { font-family: dejavusansmono; font-size: 7.5pt; font-weight: bold; margin-top: 1.5mm; }
    .notice { text-align: center; font-weight: bold; border: {{ $B }}; padding: 2mm; margin-top: 2.5mm; font-size: 11pt; }
    .party { font-weight: bold; font-size: 10pt; }
    .sub { font-size: 7.5pt; font-weight: normal; }
    .cap { font-family: dejavusansmono; font-size: 6.8pt; font-weight: bold; letter-spacing: 0.4mm; }
    table.items { width: 100%; margin-top: 2.5mm; border: {{ $B }}; }
    table.items th { font-family: dejavusansmono; font-size: 7pt; font-weight: bold; padding: 2mm 1.8mm; text-align: left; border-bottom: {{ $B }}; border-right: 0.3mm solid #000; }
    table.items th.num { text-align: right; }
    table.items td { padding: 2mm 1.8mm; font-size: 9pt; border-bottom: 0.3mm solid #000; border-right: 0.3mm solid #000; vertical-align: top; }
    table.items tr.grand td { font-weight: bold; border-top: {{ $B }}; }
    .free { font-weight: bold; }
    .num { text-align: right; white-space: nowrap; }
    td.num { font-family: dejavusansmono; }
    .pay-head { font-family: dejavusansmono; font-size: 6.8pt; font-weight: bold; letter-spacing: 0.4mm; }
    table.pay { width: 100%; margin-top: 1mm; }
    table.pay th { font-size: 6.8pt; text-align: left; padding: 0.8mm; border-bottom: 0.3mm solid #000; }
    table.pay td { font-size: 8pt; padding: 0.8mm; }
    table.sums { width: 100%; }
    table.sums td { padding: 1mm 0; font-size: 9pt; }
    table.sums tr.net td { font-weight: bold; border-top: 0.3mm solid #000; }
    table.sums tr.owed td { background: #000; color: #fff; font-weight: bold; font-size: 12pt; padding: 2.5mm 2mm; }
    .words { margin-top: 2mm; font-size: 8.5pt; }
    .footnote { border: {{ $B }}; border-top: 0; padding: 2mm; text-align: center; font-weight: bold; font-size: 9pt; }
    table.signatures { width: 100%; margin-top: 2.5mm; }
    table.signatures td { border: {{ $B }}; height: 22mm; vertical-align: bottom; text-align: center; font-size: 8pt; padding: 1.5mm; }
    .sig-line { border-top: 0; }
    .printed { font-family: dejavusansmono; font-size: 6.8pt; margin-top: 1.5mm; }
</style>

{{-- ── মাথা: কোম্পানি | শিরোনাম ─────────────────────────────────────── --}}
<table class="grid">
    <tr>
        <td class="box">
            <table><tr>
                @if ($v->logo)<td style="padding-right: 3mm; vertical-align: middle"><img src="{{ $v->logo }}" style="height: 14mm;" alt=""></td>@endif
                <td style="vertical-align: middle">
                    <div class="co-name">{{ $v->head['name'] }}</div>
                    @include('sales::print.partials.invoice-company', ['v' => $v])
                </td>
            </tr></table>
        </td>
        <td class="box" style="width: 72mm; vertical-align: middle">
            <div class="title">{{ mb_strtoupper($v->en('heading')) }}</div>
            @if ($v->duplicate)<div class="dup" data-duplicate>{{ $v->en('duplicate') }}</div>@endif
        </td>
    </tr>
</table>

@if ($v->notices !== [])<div class="notice">{{ implode(' · ', $v->notices) }}</div>@endif

{{-- ── কাকে | কোন গাড়িতে | বিলের ঘর ─────────────────────────────────── --}}
<table class="grid" style="margin-top: 2.5mm">
    <tr>
        <td class="box" style="width: 36%">@include('sales::print.partials.invoice-bill-to', ['v' => $v, 'facts' => $facts])</td>
        <td class="box" style="width: 34%">@include('sales::print.partials.invoice-transport', ['v' => $v, 'facts' => $facts])</td>
        <td class="box">
            @foreach ($v->billFacts() as [$label, $value, $marker])
                <div @if ($marker) {{ $marker }} @endif><span class="cap">{{ mb_strtoupper($label) }}</span><br><strong class="m">{{ $value }}</strong></div>
            @endforeach
        </td>
    </tr>
</table>

@include('sales::print.partials.invoice-items', ['v' => $v, 'facts' => $facts, 'paper' => $paper])

{{-- ── জমা-কথায় | টাকার সারি ─────────────────────────────────────────── --}}
<table class="grid" style="margin-top: 2.5mm">
    <tr>
        <td class="box" style="width: 54%">
            @include('sales::print.partials.invoice-payments', ['v' => $v, 'doc' => $doc])
            @if ($v->shows('amount_words'))<div class="words" data-words><span class="cap">{{ mb_strtoupper($v->en('in_words')) }}</span><br>{{ $facts['words'] }}</div>@endif
            <div style="margin-top: 3mm">@include('sales::print.partials.invoice-qr', ['v' => $v, 'width' => '20mm'])</div>
        </td>
        <td class="box">@include('sales::print.partials.invoice-sums', ['v' => $v, 'paper' => $paper])</td>
    </tr>
</table>
<div class="footnote bn"><div style="text-align: left; font-size: 80%; line-height: 1.35">{!! nl2br(e($v->footnote)) !!}</div></div>

{{-- ── সই-সিলের বাক্স ────────────────────────────────────────────────── --}}
<table class="signatures">
    <tr>
        @foreach ($v->signatures as $label)
            <td class="bn" style="width: {{ round(100 / max(1, count($v->signatures)), 1) }}%" data-signature>{{ $label }}</td>
        @endforeach
    </tr>
</table>
<div class="printed">{{ $v->printedAt() }}</div>
