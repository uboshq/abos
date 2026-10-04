{{--
    ⓘ A5 রূপ — A4-এর থেকে তৈরি (mm ×০.৭২, অক্ষর ×০.৮৫, সীমা ৬.৫pt; make_a5_new)। A4-এ বদলালে এটাও নতুন করে বানাতে হয়।
    ২২ · নিও-ব্রুটাল সাদা-কালো — ২০২৫–২৬-এর সবচেয়ে আলোচিত নকশা-ধারা: পুরো কাগজ মোটা কালো দাগের বাক্সে ভাগ,
    টাইপরাইটার-ধাঁচের মোনো অক্ষর, বড় হাতের ছোট লেবেল, উল্টো (কালো) মোটের ঘর, সই-সিলের বড় বাক্স।
    মালিক, ৩০ সেপ্টেম্বর ২০২৬: *"BLACK & WHAITE WORALD LATEST JONOPRIO ... AGER TEMPLATE E DAWNI EMO EKTA"* —
    প্রথম চেষ্টা (সারাংশের পট্টি) ১০ নম্বর "আগে হিসাব"-এর মতো লেগেছিল; এটা আগের কুড়িটার কোনোটার গড়ন নয়।

    ⓘ কেবল কালো আর সাদা — ধূসর ভরাট নেই, তাই ফটোকপিতেও ঝাপসা হয় না। ⓘ সুইচ আর `data-*` চিহ্ন ভাগের
    partial-এ ([[InvoicePaperView]]); ⚠️ মোনো অক্ষরে বাংলা নেই — বাংলা আসতে পারে এমন ঘর বাংলা অক্ষরে।
--}}
@php
    $v = new \App\Modules\Sales\Support\InvoicePaperView($doc, $facts, $company, $profile);
    $B = '0.58mm solid #000';
@endphp

<style @nonce>
    body { font-family: hindsiliguri, sans-serif; font-size: 7.5pt; color: #000; }
    table { border-collapse: collapse; }
    .m { font-family: dejavusansmono, hindsiliguri; }
    .bn { font-family: hindsiliguri, sans-serif; }
    table.grid { width: 100%; }
    table.grid > tr > td, table.grid td.box { border: {{ $B }}; padding: 2.16mm; vertical-align: top; }
    .lab { font-family: dejavusansmono; font-size: 6.5pt; font-weight: bold; letter-spacing: 0.29mm; margin-bottom: 0.72mm; }
    .co-name { font-family: dejavusansmono; font-size: 12.8pt; font-weight: bold; }
    .co-meta { font-size: 6.5pt; }
    .title { font-family: dejavusansmono; font-size: 25.5pt; font-weight: bold; letter-spacing: 0.72mm; line-height: 1; }
    .dup { font-family: dejavusansmono; font-size: 6.5pt; font-weight: bold; margin-top: 1.08mm; }
    .notice { text-align: center; font-weight: bold; border: {{ $B }}; padding: 1.44mm; margin-top: 1.8mm; font-size: 9.3pt; }
    .party { font-weight: bold; font-size: 8.5pt; }
    .sub { font-size: 6.5pt; font-weight: normal; }
    .cap { font-family: dejavusansmono; font-size: 6.5pt; font-weight: bold; letter-spacing: 0.29mm; }
    table.items { width: 100%; margin-top: 1.8mm; border: {{ $B }}; }
    table.items th { font-family: dejavusansmono; font-size: 6.5pt; font-weight: bold; padding: 1.44mm 1.3mm; text-align: left; border-bottom: {{ $B }}; border-right: 0.22mm solid #000; }
    table.items th.num { text-align: right; }
    table.items td { padding: 1.44mm 1.3mm; font-size: 7.6pt; border-bottom: 0.22mm solid #000; border-right: 0.22mm solid #000; vertical-align: top; }
    table.items tr.grand td { font-weight: bold; border-top: {{ $B }}; }
    .free { font-weight: bold; }
    .num { text-align: right; white-space: nowrap; }
    td.num { font-family: dejavusansmono; }
    .pay-head { font-family: dejavusansmono; font-size: 6.5pt; font-weight: bold; letter-spacing: 0.29mm; }
    table.pay { width: 100%; margin-top: 0.72mm; }
    table.pay th { font-size: 6.5pt; text-align: left; padding: 0.58mm; border-bottom: 0.22mm solid #000; }
    table.pay td { font-size: 6.8pt; padding: 0.58mm; }
    table.sums { width: 100%; }
    table.sums td { padding: 0.72mm 0; font-size: 7.6pt; }
    table.sums tr.net td { font-weight: bold; border-top: 0.22mm solid #000; }
    table.sums tr.owed td { background: #000; color: #fff; font-weight: bold; font-size: 10.2pt; padding: 1.8mm 1.44mm; }
    .words { margin-top: 1.44mm; font-size: 7.2pt; }
    .footnote { border: {{ $B }}; border-top: 0; padding: 1.44mm; text-align: center; font-weight: bold; font-size: 7.6pt; }
    table.signatures { width: 100%; margin-top: 1.8mm; }
    table.signatures td { border: {{ $B }}; height: 15.84mm; vertical-align: bottom; text-align: center; font-size: 6.8pt; padding: 1.08mm; }
    .sig-line { border-top: 0; }
    .printed { font-family: dejavusansmono; font-size: 6.5pt; margin-top: 1.08mm; }
</style>

{{-- ── মাথা: কোম্পানি | শিরোনাম ─────────────────────────────────────── --}}
<table class="grid">
    <tr>
        <td class="box">
            <table><tr>
                @if ($v->logo)<td style="padding-right: 2.16mm; vertical-align: middle"><img src="{{ $v->logo }}" style="height: 10.08mm;" alt=""></td>@endif
                <td style="vertical-align: middle">
                    <div class="co-name">{{ $v->head['name'] }}</div>
                    @include('sales::print.partials.invoice-company', ['v' => $v])
                </td>
            </tr></table>
        </td>
        <td class="box" style="width: 51.84mm; vertical-align: middle">
            <div class="title">{{ mb_strtoupper($v->en('heading')) }}</div>
            @if ($v->duplicate)<div class="dup" data-duplicate>{{ $v->en('duplicate') }}</div>@endif
        </td>
    </tr>
</table>

@if ($v->notices !== [])<div class="notice">{{ implode(' · ', $v->notices) }}</div>@endif

{{-- ── কাকে | কোন গাড়িতে | বিলের ঘর ─────────────────────────────────── --}}
<table class="grid" style="margin-top: 1.8mm">
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

@include('sales::print.partials.invoice-items', ['v' => $v, 'facts' => $facts, 'paper' => $paper, 'narrow' => true])

{{-- ── জমা-কথায় | টাকার সারি ─────────────────────────────────────────── --}}
<table class="grid" style="margin-top: 1.8mm">
    <tr>
        <td class="box" style="width: 54%">
            @include('sales::print.partials.invoice-payments', ['v' => $v, 'doc' => $doc])
            @if ($v->shows('amount_words'))<div class="words" data-words><span class="cap">{{ mb_strtoupper($v->en('in_words')) }}</span><br>{{ $facts['words'] }}</div>@endif
            <div style="margin-top: 2.16mm">@include('sales::print.partials.invoice-qr', ['v' => $v, 'width' => '16mm'])</div>
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
