{{--
    ২১ · আধুনিক বিবরণী — মালিকের নির্দেশ, ৩০ সেপ্টেম্বর ২০২৬: *"বিল + হিসাবের বিবরণী moto ro ekta koro"*,
    তারপর *"20 tmplater moto but ro adunik soccho poriskar"* (১৫ নম্বর A5-এ যাওয়ায় A4-এ বিশটা পূর্ণ করতে)।

    ⓘ ২০ নম্বরের কথাই, আরও হালকা করে: উপরে চারটা নরম ঘরে হিসাবের সারাংশ (আগের জের · এই বিল · জমা ·
    শেষ জের), প্রচুর ফাঁকা জায়গা, সরু দাগ, আর নিচে ব্যাংকের খাতার মতো হিসাবের চলাচল — প্রতিটা সারির পরে
    চলমান জের ([[InvoicePaperView::movement()]])।

    ⓘ সুইচ আর `data-*` চিহ্ন ভাগের partial-এ। "আগের বকেয়া" বন্ধ হলে সারাংশ আর চলাচল দুইটাই যায় (দুইটার শুরুই
    ওটা)। ⚠️ জমার সুইচ বন্ধ হলেও চলাচলে জমা থাকে — নইলে শেষ জের মোট বকেয়ার সাথে মিলত না, কাগজ মিথ্যা বলত।
--}}
@php
    $v = new \App\Modules\Sales\Support\InvoicePaperView($doc, $facts, $company, $profile);
    $accent = '#0f766e';
    $t = fn (string $key) => __('sales::invoice_design.'.$key, [], 'en');
    $tiles = [
        [$v->label('previous_due'), $v->sums['previous_due'], false],
        ['+ '.$v->label('net_payable'), $v->sums['net_payable'], false],
        ['− '.$v->label('paid'), $v->sums['paid'], false],
        [$v->label('total_due'), $v->sums['outstanding'], true],
    ];
@endphp

<style @nonce>
    body { font-family: hindsiliguri, sans-serif; font-size: 9pt; color: #1f2933; }
    table { border-collapse: collapse; }
    table.head { width: 100%; }
    table.head td { vertical-align: top; }
    .co-name { font-size: 15pt; font-weight: bold; }
    .co-meta { font-size: 7.5pt; color: #7b8794; }
    .kicker { text-align: right; font-size: 7.5pt; letter-spacing: 0.8mm; color: {{ $accent }}; font-weight: bold; }
    .no { text-align: right; font-size: 20pt; font-weight: bold; font-family: dejavusans; }
    .when { text-align: right; font-size: 8.5pt; color: #7b8794; }
    .dup { text-align: right; font-size: 7.5pt; font-weight: bold; color: #7b8794; }
    .notice { text-align: center; font-weight: bold; border: 0.4mm solid #b42318; color: #b42318; padding: 2mm; margin-top: 3mm; font-size: 11pt; }
    table.tiles { width: 100%; margin-top: 5mm; }
    table.tiles td.tile { background: #f3f6f8; padding: 3.5mm 3.5mm; vertical-align: top; }
    table.tiles td.tile-on { background: {{ $accent }}; }
    .tile-cap { font-size: 7pt; color: #7b8794; }
        .tile-val { font-size: 12.5pt; font-weight: bold; font-family: dejavusans; margin-top: 1mm; }
        table.two { width: 100%; margin-top: 5mm; }
    table.two td { width: 50%; vertical-align: top; font-size: 8.5pt; line-height: 1.55; padding-right: 6mm; }
    .cap { font-size: 7pt; letter-spacing: 0.4mm; color: #7b8794; }
    .party { font-weight: bold; font-size: 10pt; }
    .sub { font-size: 7.5pt; color: #7b8794; font-weight: normal; }
    table.items { width: 100%; margin-top: 5mm; }
    table.items th { font-size: 7pt; color: #7b8794; font-weight: normal; padding: 0 1.5mm 2mm 0; text-align: left; border-bottom: 0.25mm solid #cbd2d9; }
    table.items th.num { text-align: right; padding-right: 0; }
    table.items td { padding: 2mm 1.5mm 2mm 0; border-bottom: 0.2mm solid #eef1f4; font-size: 9pt; vertical-align: top; }
    table.items td.num { padding-right: 0; }
    table.items tr.grand td { font-weight: bold; border-bottom: 0; }
    .free { color: {{ $accent }}; font-weight: bold; }
    .num { text-align: right; white-space: nowrap; }
    td.num { font-family: dejavusans; }
    table.bottom { width: 100%; margin-top: 4mm; }
    table.bottom td.side { vertical-align: top; font-size: 8.5pt; }
    .pay-head { font-size: 7pt; color: #7b8794; }
    table.pay { width: 100%; margin-top: 1mm; }
    table.pay th { font-size: 7pt; color: #7b8794; font-weight: normal; text-align: left; padding: 0.8mm 1mm 0.8mm 0; }
    table.pay td { font-size: 8.5pt; padding: 0.8mm 1mm 0.8mm 0; }
    table.sums { width: 100%; }
    table.sums td { padding: 1.1mm 0; font-size: 9pt; color: #52606d; }
    table.sums td.num { color: #1f2933; }
    table.sums tr.net td { font-weight: bold; color: #1f2933; }
    table.sums tr.owed td { font-weight: bold; color: {{ $accent }}; border-top: 0.25mm solid #cbd2d9; padding-top: 2mm; font-size: 10pt; }
    .words { margin-top: 3mm; font-size: 8.5pt; color: #52606d; }
    .section { margin-top: 5mm; font-size: 7pt; letter-spacing: 0.6mm; color: {{ $accent }}; font-weight: bold; }
    table.ledger { width: 100%; margin-top: 2mm; }
    table.ledger th { font-size: 7pt; color: #7b8794; font-weight: normal; padding: 0 1.5mm 1.8mm 0; text-align: left; border-bottom: 0.25mm solid #cbd2d9; }
    table.ledger th.num { text-align: right; padding-right: 0; }
    table.ledger td { padding: 1.6mm 1.5mm 1.6mm 0; border-bottom: 0.2mm solid #eef1f4; font-size: 9pt; }
    table.ledger td.num { padding-right: 0; }
    table.ledger tr.close td { font-weight: bold; color: {{ $accent }}; border-bottom: 0; }
    .footnote { margin-top: 5mm; font-size: 9pt; font-weight: bold; color: #b42318; }
    table.signatures { width: 100%; margin-top: 10mm; }
    table.signatures td { padding-right: 8mm; font-size: 9pt; }
    .sig-line { border-top: 0.25mm solid #1f2933; padding-top: 1.2mm; }
    .printed { margin-top: 4mm; font-size: 7pt; color: #7b8794; }
</style>

<table class="head">
    <tr>
        <td>
{{-- ⭐ লোগো নামের বাঁয়ে — মালিক, ৩০ সেপ্টেম্বর ২০২৬ (নমুনা PDF দেখে) --}}
<table><tr>
    @if ($v->logo)<td style="padding-right: 3.5mm; vertical-align: middle"><img src="{{ $v->logo }}" style="height: 13mm;" alt=""></td>@endif
    <td style="vertical-align: middle">
                <div class="co-name">{{ $v->head['name'] }}</div>
                @include('sales::print.partials.invoice-company', ['v' => $v])
    </td>
</tr></table>
        </td>
        <td style="width: 78mm">
            <div class="kicker">{{ mb_strtoupper($v->en('heading')) }} &amp; {{ mb_strtoupper($t('statement')) }}</div>
            <div class="no">{{ $facts['bill']['bill_no'] }}</div>
            <div class="when">{{ $facts['bill']['bill_date'] }}@if ($v->shows('order_no')) · <span data-order-no>{{ $facts['bill']['order_no'] }}</span>@endif @if ($v->shows('invoice_type')) · <span data-invoice-type>{{ $facts['bill']['type'] }}</span>@endif</div>
            @if ($v->duplicate)<div class="dup" data-duplicate>{{ $v->en('duplicate') }}</div>@endif
        </td>
    </tr>
</table>

@if ($v->notices !== [])<div class="notice">{{ implode(' · ', $v->notices) }}</div>@endif

@if ($v->shows('previous_due'))
    <table class="tiles" data-account-summary>
        <tr>
            @foreach ($tiles as [$label, $amount, $on])
                <td @class(['tile', 'tile-on' => $on]) style="width: 24.4%">
                    {{-- ⓘ রং সরাসরি — mPDF `td.x .y` ধাঁচের বংশধর-নিয়ম মানে না --}}
                    <div class="tile-cap" style="color: {{ $on ? '#cdeee9' : '#7b8794' }}">{{ $label }}</div>
                    <div class="tile-val" style="color: {{ $on ? '#ffffff' : '#1f2933' }}">{{ $paper->money($amount) }}</div>
                </td>
                @if (! $loop->last)<td style="width: 0.8%; padding: 0"></td>@endif
            @endforeach
        </tr>
    </table>
@endif

<table class="two">
    <tr>
        <td>@include('sales::print.partials.invoice-bill-to', ['v' => $v, 'facts' => $facts])</td>
        <td>@include('sales::print.partials.invoice-transport', ['v' => $v, 'facts' => $facts])</td>
    </tr>
</table>

@include('sales::print.partials.invoice-items', ['v' => $v, 'facts' => $facts, 'paper' => $paper, 'upper' => false])

<table class="bottom">
    <tr>
        <td class="side" style="width: 52%; padding-right: 8mm">
            @include('sales::print.partials.invoice-payments', ['v' => $v, 'doc' => $doc])
            @if ($v->shows('amount_words'))<div class="words" data-words>{{ $facts['words'] }}</div>@endif
        </td>
        <td class="side">@include('sales::print.partials.invoice-sums', ['v' => $v, 'paper' => $paper])</td>
    </tr>
</table>

@if ($v->shows('previous_due'))
    <div class="section">{{ mb_strtoupper($t('movement')) }}</div>
    <table class="ledger" data-movement>
        <tr>
            <th style="width: 24mm">{{ $v->label('txn_date') }}</th>
            <th>{{ $t('particulars') }}</th>
            <th class="num" style="width: 27mm">{{ $t('debit') }}</th>
            <th class="num" style="width: 27mm">{{ $t('credit') }}</th>
            <th class="num" style="width: 30mm">{{ $t('balance') }}</th>
        </tr>
        @foreach ($v->movement($doc->payments) as $row)
            <tr @class(['close' => $loop->last])>
                <td>{{ $row['date'] }}</td>
                <td>{{ $row['text'] }}</td>
                <td class="num">{{ $row['debit'] }}</td>
                <td class="num">{{ $row['credit'] }}</td>
                <td class="num">{{ $row['balance'] }}</td>
            </tr>
        @endforeach
    </table>
@endif

<table style="width: 100%; margin-top: 5mm">
    <tr>
        <td style="vertical-align: top"><div class="footnote" style="margin-top: 0">{{ $v->footnote }}</div></td>
        <td style="width: 26mm; text-align: right; vertical-align: top">@include('sales::print.partials.invoice-qr', ['v' => $v, 'width' => '20mm'])</td>
    </tr>
</table>

@include('sales::print.partials.invoice-signatures', ['v' => $v])
<div class="printed">{{ $v->printedAt() }}</div>
