{{-- ⓘ A5 সংস্করণ — A4-এর statement_ledger থেকে মাপ ছোট করে (mm ×০.৭২, অক্ষর ×০.৮৫, সীমা ৬.৫pt), চেহারা একই। মালিক, ৩০ সেপ্টেম্বর ২০২৬: "A5 er jonno 21 desine koro" --}}
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
    body { font-family: hindsiliguri, sans-serif; font-size: 7.6pt; color: #1f2933; }
    table { border-collapse: collapse; }
    table.head { width: 100%; }
    table.head td { vertical-align: top; }
    .co-name { font-size: 12.8pt; font-weight: bold; }
    .co-meta { font-size: 6.5pt; color: #7b8794; }
    .kicker { text-align: right; font-size: 6.5pt; letter-spacing: 0.6mm; color: {{ $accent }}; font-weight: bold; }
    .no { text-align: right; font-size: 17pt; font-weight: bold; font-family: dejavusans; }
    .when { text-align: right; font-size: 7.2pt; color: #7b8794; }
    .dup { text-align: right; font-size: 6.5pt; font-weight: bold; color: #7b8794; }
    .notice { text-align: center; font-weight: bold; border: 0.3mm solid #b42318; color: #b42318; padding: 0.8mm; margin-top: 1.3mm; font-size: 9.3pt; }
    table.tiles { width: 100%; margin-top: 2.2mm; }
    table.tiles td.tile { background: #f3f6f8; padding: 1.5mm 1.5mm; vertical-align: top; }
    table.tiles td.tile-on { background: {{ $accent }}; }
    .tile-cap { font-size: 6.5pt; color: #7b8794; }
        .tile-val { font-size: 10.6pt; font-weight: bold; font-family: dejavusans; margin-top: 0.4mm; }
        table.two { width: 100%; margin-top: 2.2mm; }
    table.two td { width: 50%; vertical-align: top; font-size: 7.2pt; line-height: 1.55; padding-right: 4.3mm; }
    .cap { font-size: 6.5pt; letter-spacing: 0.3mm; color: #7b8794; }
    .party { font-weight: bold; font-size: 8.5pt; }
    .sub { font-size: 6.5pt; color: #7b8794; font-weight: normal; }
    table.items { width: 100%; margin-top: 2.2mm; }
    table.items th { font-size: 6.5pt; color: #7b8794; font-weight: normal; padding: 0 0.7mm 0.8mm 0; text-align: left; border-bottom: 0.2mm solid #cbd2d9; }
    table.items th.num { text-align: right; padding-right: 0; }
    table.items td { padding: 0.8mm 0.7mm 0.8mm 0; border-bottom: 0.1mm solid #eef1f4; font-size: 7.6pt; vertical-align: top; }
    table.items td.num { padding-right: 0; }
    table.items tr.grand td { font-weight: bold; border-bottom: 0; }
    .free { color: {{ $accent }}; font-weight: bold; }
    .num { text-align: right; white-space: nowrap; }
    td.num { font-family: dejavusans; }
    table.bottom { width: 100%; margin-top: 1.7mm; }
    table.bottom td.side { vertical-align: top; font-size: 7.2pt; }
    .pay-head { font-size: 6.5pt; color: #7b8794; }
    table.pay { width: 100%; margin-top: 0.4mm; }
    table.pay th { font-size: 6.5pt; color: #7b8794; font-weight: normal; text-align: left; padding: 0.4mm 0.4mm 0.4mm 0; }
    table.pay td { font-size: 7.2pt; padding: 0.4mm 0.4mm 0.4mm 0; }
    table.sums { width: 100%; }
    table.sums td { padding: 0.5mm 0; font-size: 7.6pt; color: #52606d; }
    table.sums td.num { color: #1f2933; }
    table.sums tr.net td { font-weight: bold; color: #1f2933; }
    table.sums tr.owed td { font-weight: bold; color: {{ $accent }}; border-top: 0.2mm solid #cbd2d9; padding-top: 0.8mm; font-size: 8.5pt; }
    .words { margin-top: 1.3mm; font-size: 7.2pt; color: #52606d; }
    .section { margin-top: 2.2mm; font-size: 6.5pt; letter-spacing: 0.4mm; color: {{ $accent }}; font-weight: bold; }
    table.ledger { width: 100%; margin-top: 0.8mm; }
    table.ledger th { font-size: 6.5pt; color: #7b8794; font-weight: normal; padding: 0 0.7mm 0.8mm 0; text-align: left; border-bottom: 0.2mm solid #cbd2d9; }
    table.ledger th.num { text-align: right; padding-right: 0; }
    table.ledger td { padding: 0.7mm 0.7mm 0.7mm 0; border-bottom: 0.1mm solid #eef1f4; font-size: 7.6pt; }
    table.ledger td.num { padding-right: 0; }
    table.ledger tr.close td { font-weight: bold; color: {{ $accent }}; border-bottom: 0; }
    .footnote { margin-top: 2.2mm; font-size: 7.6pt; font-weight: bold; color: #b42318; }
    table.signatures { width: 100%; margin-top: 4.3mm; }
    table.signatures td { padding-right: 5.8mm; font-size: 7.6pt; }
    .sig-line { border-top: 0.2mm solid #1f2933; padding-top: 0.5mm; }
    .printed { margin-top: 1.7mm; font-size: 6.5pt; color: #7b8794; }
</style>

<table class="head">
    <tr>
        <td>
{{-- ⭐ লোগো নামের বাঁয়ে — মালিক, ৩০ সেপ্টেম্বর ২০২৬ (নমুনা PDF দেখে) --}}
<table><tr>
    @if ($v->logo)<td style="padding-right: 2.5mm; vertical-align: middle"><img src="{{ $v->logo }}" style="height: 9.5mm;" alt=""></td>@endif
    <td style="vertical-align: middle">
                <div class="co-name">{{ $v->head['name'] }}</div>
                @include('sales::print.partials.invoice-company', ['v' => $v])
    </td>
</tr></table>
        </td>
        <td style="width: 56.2mm">
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

@include('sales::print.partials.invoice-items', ['v' => $v, 'facts' => $facts, 'paper' => $paper, 'narrow' => true, 'upper' => false])

<table class="bottom">
    <tr>
        <td class="side" style="width: 52%; padding-right: 5.8mm">
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
            <th style="width: 17.3mm">{{ $v->label('txn_date') }}</th>
            <th>{{ $t('particulars') }}</th>
            <th class="num" style="width: 19.4mm">{{ $t('debit') }}</th>
            <th class="num" style="width: 19.4mm">{{ $t('credit') }}</th>
            <th class="num" style="width: 21.6mm">{{ $t('balance') }}</th>
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

<table style="width: 100%; margin-top: 2.2mm">
    <tr>
        <td style="vertical-align: top"><div class="footnote" style="margin-top: 0"><div style="text-align: left; font-size: 50%; line-height: 1.35">{!! nl2br(e($v->footnote)) !!}</div></div></td>
        <td style="width: 18.7mm; text-align: right; vertical-align: top">@include('sales::print.partials.invoice-qr', ['v' => $v, 'width' => '14.4mm'])</td>
    </tr>
</table>

@include('sales::print.partials.invoice-signatures', ['v' => $v])
<div class="printed">{{ $v->printedAt() }}</div>
