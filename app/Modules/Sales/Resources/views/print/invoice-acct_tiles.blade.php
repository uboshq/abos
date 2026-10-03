{{--
    Figure Tiles — বিল + হিসাবের বিবরণী, মাথার নিচে পাঁচটা অঙ্কের টালি। মালিক, ৩ অক্টোবর ২০২৬ (নকশার ক্যানভাস "Style 6",
    মালিকের অনুমোদিত)।

    ⭐ গড়ন: মাথায় বাঁয়ে কোম্পানি, ডানে "INVOICE & ACCOUNT STATEMENT" আর বিল নম্বর · তারিখ, তারপর QR। তার নিচে এক সারিতে
    পাঁচটা টালি — আগের বকেয়া (`data-previous-due`), এই বিল, আজ জমা, বেগুনি ভরা টালিতে শেষ জের Due / Advance / No Due
    ([[InvoicePaperView::balanceWord()]]), আর ফ্রেমের টালিতে টার্গেটের বাকি (`data-target`, লক্ষ্য না থাকলে টালিটাই নেই —
    [[target()]])। তারপর উপরে-নিচে দাগের ফিতায় ক্রেতা | ড্রাইভার (`data-transport`); মোটা বেগুনি দাগের মাথায় পণ্য (একটা
    বাদে একটা হালকা সারি, লট নামের পাশে); ডানে-সাঁটা এক লাইনের টাকার সারি (`data-invoice-summary`, শেষ ঘর Invoice Due /
    Extra Paid — [[billLeftWord()]]); কথায় টাকা; আর পুরো চওড়ায় বিলের মাসের চলাচল ([[InvoicePaperView::movement()]])।
    ⓘ "আগের বকেয়া" সুইচ বন্ধ হলে প্রথম টালি আর চলাচল যায়, ভরা টালি এই বিলের বাকি দেখায়।
    ⓘ সুইচ আর বাকি `data-*` চিহ্ন ভাগের partial-এ।
--}}
@php
    $v = new \App\Modules\Sales\Support\InvoicePaperView($doc, $facts, $company, $profile);
    $accent = '#5b3a8c';
    $soft = '#f1edf7';
    $t = fn (string $key) => __('sales::invoice_design.'.$key, [], 'en');
    $s = $v->sums;
    $target = $v->target();
    $transport = $facts['transport'] ?? [];
@endphp
<style @nonce>
    body { font-family: hindsiliguri, sans-serif; font-size: 8.5pt; color: #1d1530; }
    table { border-collapse: collapse; }
    table.head { width: 100%; }
    table.head td { vertical-align: middle; line-height: 1.15; }
    .co-name { font-size: 14pt; font-weight: bold; color: {{ $accent }}; }
    .co-meta { font-size: 7.5pt; color: #5f5770; }
    .title { font-size: 10.5pt; font-weight: bold; text-align: right; white-space: nowrap; }
    .no { font-family: dejavusans; font-weight: bold; text-align: right; }
    .right { text-align: right; }
    .muted { color: #5f5770; }
    .dup { font-size: 7.5pt; font-weight: bold; color: #5f5770; text-align: right; }
    .notice { text-align: center; font-weight: bold; border: 0.4mm solid #b42318; color: #b42318; padding: 1mm; margin-top: 2mm; font-size: 10pt; }
    table.tiles { width: 100%; margin-top: 1.5mm; }
    table.tiles td { line-height: 1.15; }
    table.tiles td.tile { background: {{ $soft }}; padding: 1.1mm 2.2mm; vertical-align: top; }
    table.tiles td.tile-dark { background: {{ $accent }}; color: #ffffff; padding: 1.1mm 2.2mm; vertical-align: top; }
    table.tiles td.tile-frame { border: 0.5mm solid {{ $accent }}; padding: 0.8mm 2mm; vertical-align: top; }
    table.tiles td.gap { width: 1.6mm; }
    .tile-cap { color: #5f5770; font-size: 7.5pt; }
    .tile-cap-dark { color: #e2d8f2; font-size: 7.5pt; }
    .fig { font-family: dejavusans; font-size: 10.5pt; font-weight: bold; white-space: nowrap; }
    .tile-note { color: #5f5770; font-size: 7pt; }
    table.parties { width: 100%; margin-top: 1.5mm; border-top: 0.25mm solid #d9d1e6; border-bottom: 0.25mm solid #d9d1e6; }
    table.parties td { width: 50%; vertical-align: top; padding: 0.8mm 0; font-size: 8pt; line-height: 1.15; }
    .cap { font-weight: bold; }
    .party { font-weight: bold; }
    table.items { width: 100%; margin-top: 1.5mm; }
    table.items th { font-size: 7.5pt; font-weight: bold; color: {{ $accent }}; padding: 0.6mm 1.5mm; text-align: left; border-top: 1mm solid {{ $accent }}; border-bottom: 0.25mm solid {{ $accent }}; }
    table.items th.num { text-align: right; }
    table.items td { padding: 0.5mm 1.5mm; font-size: 8pt; line-height: 1.1; vertical-align: top; }
    table.items tr.alt td { background: #f8f6fb; }
    table.items tr.grand td { font-weight: bold; border-top: 0.25mm solid {{ $accent }}; }
    .sub { font-size: 7pt; color: #5f5770; }
    .free { font-weight: bold; }
    .num { text-align: right; white-space: nowrap; }
    td.num { font-family: dejavusans; }
    table.totals { margin-top: 1mm; }
    table.totals td { padding: 0.5mm 0 0.5mm 5mm; font-size: 8pt; line-height: 1.15; text-align: right; vertical-align: top; border-top: 0.25mm solid {{ $accent }}; }
    table.totals td .val { font-family: dejavusans; white-space: nowrap; }
    table.totals td .lbl { font-size: 7pt; color: #5f5770; }
    table.totals td.strong { font-weight: bold; color: {{ $accent }}; }
    .words { margin-top: 1.5mm; color: #5f5770; }
    .words strong { color: #1d1530; }
    .section { margin-top: 1.5mm; font-weight: bold; color: {{ $accent }}; }
    table.ledger { width: 100%; margin-top: 0.5mm; }
    table.ledger th { font-size: 7.5pt; font-weight: bold; color: #5f5770; text-align: left; white-space: nowrap; padding: 0.4mm 1.5mm; border-bottom: 0.25mm solid {{ $accent }}; }
    table.ledger th.num { text-align: right; }
    table.ledger td { padding: 0.35mm 1.5mm; font-size: 8pt; line-height: 1.1; }
    table.ledger tr.close td { background: {{ $soft }}; font-weight: bold; }
    table.pay { width: 100%; margin-top: 1mm; }
    table.pay th { font-size: 7pt; font-weight: bold; color: #5f5770; text-align: left; padding: 0.5mm; border-bottom: 0.3mm solid #1d1530; }
    table.pay td { font-size: 8pt; padding: 0.5mm; border-bottom: 0.2mm solid #ece8f2; }
    .pay-head { font-weight: bold; color: {{ $accent }}; margin-top: 1.5mm; }
    .footnote { margin-top: 1.5mm; font-size: 9pt; font-weight: bold; color: #b42318; }
    table.signatures { width: 100%; margin-top: 7mm; }
    table.signatures td { text-align: center; padding: 0 4mm; font-size: 8.5pt; }
    .sig-line { border-top: 0.25mm solid #1d1530; padding-top: 1mm; }
    table.foot { width: 100%; margin-top: 1.5mm; }
    table.foot td { font-size: 7pt; color: #5f5770; }
</style>

<table class="head">
    <tr>
        @if ($v->logo)<td style="width: 22mm; padding-right: 3mm"><img src="{{ $v->logo }}" style="max-height: 16mm; max-width: 22mm;" alt=""></td>@endif
        <td>
            <div class="co-name">{{ $v->head['name'] }}</div>
            @include('sales::print.partials.invoice-company', ['v' => $v])
        </td>
        <td style="width: 66mm; padding-right: 3mm">
            <div class="title">INVOICE &amp; ACCOUNT STATEMENT</div>
            <div class="no">{{ $facts['bill']['bill_no'] }} · {{ $facts['bill']['bill_date'] }}</div>
            @if ($v->shows('order_no'))<div class="right muted" data-order-no>{{ $v->en('order_no') }} {{ $facts['bill']['order_no'] }}</div>@endif
            @if ($v->shows('invoice_type'))<div class="right muted" data-invoice-type>{{ $v->en('type') }} {{ $facts['bill']['type'] }}</div>@endif
            @if ($v->duplicate)<div class="dup" data-duplicate>{{ $v->en('duplicate') }}</div>@endif
        </td>
        <td style="width: 20mm">@include('sales::print.partials.invoice-qr', ['v' => $v, 'width' => '19mm'])</td>
    </tr>
</table>

@if ($v->notices !== [])<div class="notice">{{ implode(' · ', $v->notices) }}</div>@endif

<table class="tiles">
    <tr>
        @if ($v->shows('previous_due'))
            <td class="tile" data-previous-due>
                <div class="tile-cap">Previous balance</div>
                <div class="fig">{{ $v->previousBeforeBill() }}</div>
            </td>
            <td class="gap"></td>
        @endif
        <td class="tile">
            <div class="tile-cap">This invoice</div>
            <div class="fig">{{ $paper->money($s['net_payable']) }}</div>
        </td>
        <td class="gap"></td>
        <td class="tile">
            <div class="tile-cap">Received today</div>
            <div class="fig">{{ $paper->money($s['paid']) }}</div>
        </td>
        <td class="gap"></td>
        <td class="tile-dark">
            @if ($v->shows('previous_due'))
                <div class="tile-cap-dark">Closing balance</div>
                <div class="fig">{{ $v->balanceAmount() }} <span data-balance-word>{{ $v->balanceWord() }}</span></div>
            @else
                <div class="tile-cap-dark">This invoice</div>
                <div class="fig">{{ $v->billLeftAmount() }} {{ $v->billLeftWord() }}</div>
            @endif
        </td>
        @if ($target !== null)
            <td class="gap"></td>
            <td class="tile-frame" data-target>
                <div class="tile-cap">Target left · {{ $target['month'] }}</div>
                <div class="fig">{{ $target['remaining'] }}</div>
                <div class="tile-note">of {{ $target['target'] }} · achieved {{ $target['achieved'] }}</div>
                <div class="tile-note">Closes {{ $target['closes_on'] }} · {{ $target['bank_days'] }} bank days</div>
            </td>
        @endif
    </tr>
</table>

<table class="parties">
    <tr>
        <td style="padding-right: 4mm">@include('sales::print.partials.invoice-bill-to', ['v' => $v, 'facts' => $facts, 'cap' => 'cap'])</td>
        <td>
            @if ($v->shows('transport'))
                <div data-transport>
                    <div class="cap">Driver</div>
                    <div class="party">{{ ($transport['driver_name'] ?? '') !== '' ? $transport['driver_name'] : ($transport['carrier'] ?? '') }}@if (($transport['driver_phone'] ?? '') !== '') · {{ $transport['driver_phone'] }}@endif</div>
                    @if (($transport['vehicle'] ?? '') !== '')<div>{{ $v->en('vehicle') }} {{ $transport['vehicle'] }}</div>@endif
                    @if (($transport['delivery_date'] ?? '') !== '')<div class="muted">{{ $v->en('delivery_date') }} {{ $transport['delivery_date'] }}</div>@endif
                </div>
            @endif
        </td>
    </tr>
</table>

@include('sales::print.partials.invoice-items', ['v' => $v, 'facts' => $facts, 'paper' => $paper, 'upper' => false, 'zebra' => true, 'inlineLot' => true])

<table style="width: 100%"><tr><td style="text-align: right">
    <table class="totals" align="right" data-invoice-summary>
        <tr>
            <td><div class="lbl">{{ $v->label('grand_total') }}</div><div class="val">{{ $paper->money($s['grand_total']) }}</div></td>
            <td><div class="lbl">{{ $v->label('discount') }}</div><div class="val">{{ $paper->money($s['discount']) }}</div></td>
            @if ($v->showVat)<td><div class="lbl">{{ $v->label('vat') }}</div><div class="val">{{ $paper->money($s['vat']) }}</div></td>@endif
            <td><div class="lbl">{{ $v->label('rounding') }}</div><div class="val">{{ $paper->money($s['rounding']) }}</div></td>
            <td class="strong"><div class="lbl">{{ $v->label('net_payable') }}</div><div class="val">{{ $paper->money($s['net_payable']) }}</div></td>
            <td class="strong"><div class="lbl" data-bill-left>{{ $v->billLeftWord() }}</div><div class="val">{{ $v->billLeftAmount() }}</div></td>
        </tr>
    </table>
</td></tr></table>

@if ($v->shows('amount_words'))<div class="words" data-words><strong>{{ $v->en('in_words') }}</strong> {{ $facts['words'] }}</div>@endif

@if ($v->shows('previous_due'))
    <div class="section">{{ $t('statement') }}</div>
    <table class="ledger" data-movement>
        <tr>
            <th style="width: 18mm">{{ $v->label('txn_date') }}</th>
            <th>{{ $t('particulars') }}</th>
            <th class="num" style="width: 28mm">{{ $t('debit') }}</th>
            <th class="num" style="width: 28mm">{{ $t('credit') }}</th>
            <th class="num" style="width: 34mm">{{ $t('balance') }}</th>
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

@include('sales::print.partials.invoice-payments', ['v' => $v, 'doc' => $doc])

<div class="footnote"><div style="text-align: left; font-size: 50%; line-height: 1.35">{!! nl2br(e($v->footnote)) !!}</div></div>
@include('sales::print.partials.invoice-signatures', ['v' => $v])
<table class="foot">
    <tr>
        <td>{{ $v->printedAt() }}</td>
        <td style="text-align: right">Figure Tiles</td>
    </tr>
</table>
