{{--
    Visual — বিল + হিসাবের বিবরণী, ছবির মতো করে পড়ার। মালিক, ৩ অক্টোবর ২০২৬ (নকশার ক্যানভাস, Style 10)।

    ⓘ উপরে কোম্পানি (নীল নাম), ডানে "INVOICE & ACCOUNT STATEMENT", বিল নম্বর আর QR, নিচে নীল মোটা দাগ। তারপর তিন ঘর
    পাশাপাশি — ক্রেতা আর ড্রাইভার; হালকা নীল ঘরে লক্ষ্যের অগ্রগতি (বড় শতাংশ আর একটা ভরাট দণ্ড); গাঢ় নীল ঘরে শেষ জের
    (ডিলারের ভাষায় Due / Advance / No Due, [[InvoicePaperView::balanceWord()]]) আর আগের + বিল − জমা।
    পণ্যের ছকের মাথা গাঢ় নীল, সারি একটা বাদে একটা হালকা।
    ⭐ মূল ভাবনা: নিচে বাঁয়ে "How it was paid" — জমার মাধ্যম ধরে (Bank / Cash / bKash …) একটা ভাগ-করা দণ্ড, প্রতিটা ভাগ
    তার টাকার অনুপাতে চওড়া, রঙের চিহ্নসহ; তার নিচে বিবরণী (+/− অঙ্ক আর চলমান জের, [[InvoicePaperView::movement()]])।
    ডানে বিলের যোগ-বিয়োগ আর কথায় অঙ্ক।
    ⛔ ক্যানভাসের গোল SVG নয় — mPDF-এ দণ্ডগুলো ছকের ঘর, চওড়া শতাংশে; অনুপাত কেবল bcmath-এ, ছাপার অঙ্কের কমা তুলে।
    ⓘ সুইচ আর `data-*` চিহ্ন ভাগের partial-এ আর "Special for DB"-এর মতোই; লক্ষ্য না থাকলে লক্ষ্যের ঘরটাই নেই।
--}}
@php
    $v = new \App\Modules\Sales\Support\InvoicePaperView($doc, $facts, $company, $profile);
    $accent = '#3949ab';
    $t = fn (string $key) => __('sales::invoice_design.'.$key, [], 'en');
    $s = $v->sums;
    $target = $v->target();
    $transport = $facts['transport'] ?? [];

    /* ⓘ ছাপার অঙ্ক ("12,500.00") থেকে bcmath-এর সংখ্যা */
    $plain = function (?string $text): string {
        $n = str_replace([',', ' '], '', trim((string) $text));

        return is_numeric($n) ? bcadd($n, '0', 4) : '0.0000';
    };

    /* ⭐ লক্ষ্যের শতাংশ — অর্জন ÷ লক্ষ্য; দণ্ড ১০০%-এ থামে, লেখা নয় */
    $pct = '0';
    $bar = '0';
    if ($target !== null && bccomp($plain($target['target']), '0', 4) > 0) {
        $pct = bcdiv(bcmul($plain($target['achieved']), '100', 4), $plain($target['target']), 0);
        $bar = bccomp($pct, '100', 0) > 0 ? '100' : (bccomp($pct, '0', 0) < 0 ? '0' : $pct);
    }

    /* ⭐ জমার মাধ্যম ধরে ভাগ — দণ্ডের প্রতিটা ভাগ তার টাকার অনুপাতে; শেষ ভাগ বাকিটা নেয়, তাই যোগ ঠিক ১০০% */
    $colors = ['#3949ab', '#f4a51c', '#c2185b', '#00897b', '#6d4c41', '#5c6185'];
    $byMethod = [];
    $received = '0';
    foreach ($doc->payments as $row) {
        $amount = $plain((string) ($row['amount'] ?? '0'));
        if (bccomp($amount, '0', 4) <= 0) {
            continue;
        }
        $method = (string) ($row['method'] ?? '');
        $byMethod[$method] = bcadd($byMethod[$method] ?? '0', $amount, 4);
        $received = bcadd($received, $amount, 4);
    }
    $parts = [];
    $used = '0';
    $i = 0;
    foreach ($byMethod as $method => $amount) {
        $width = $i === count($byMethod) - 1 ? bcsub('100', $used, 2) : bcdiv(bcmul($amount, '100', 4), $received, 2);
        $used = bcadd($used, $width, 2);
        $parts[] = ['method' => $method, 'amount' => \App\Core\Support\Money::format($amount), 'width' => $width, 'color' => $colors[$i % count($colors)]];
        $i++;
    }

    $rows = $v->shows('previous_due') ? $v->movement($doc->payments) : [];
    $period = $rows === [] ? '' : implode(' – ', array_values(array_unique(array_filter([(string) $rows[0]['date'], (string) $rows[count($rows) - 1]['date']]))));
    $billNo = (string) ($facts['bill']['bill_no'] ?? '');
@endphp
<style @nonce>
    body { font-family: hindsiliguri, sans-serif; font-size: 8.5pt; color: #1c2140; }
    table { border-collapse: collapse; }
    .muted { color: #5c6185; }
    table.head { width: 100%; border-bottom: 0.6mm solid {{ $accent }}; }
    table.head td { vertical-align: middle; padding-bottom: 1mm; }
    .co-name { font-size: 13pt; font-weight: bold; color: {{ $accent }}; line-height: 1.1; }
    .co-meta { font-size: 7.5pt; color: #5c6185; }
    .doc-title { font-size: 10.5pt; font-weight: bold; white-space: nowrap; }
    .doc-no { font-family: dejavusansmono, monospace; font-weight: bold; font-size: 9pt; }
    .dup { font-size: 7.5pt; font-weight: bold; color: #5c6185; }
    .notice { text-align: center; font-weight: bold; border: 0.4mm solid #b42318; color: #b42318; padding: 0.8mm; margin-top: 1.5mm; font-size: 9.5pt; }
    table.trio { width: 100%; margin-top: 1.5mm; }
    table.trio td.cell { vertical-align: top; }
    .cap { font-weight: bold; color: {{ $accent }}; font-size: 8.5pt; }
    .party { font-size: 10.5pt; font-weight: bold; }
    .sub { font-size: 7pt; color: #5c6185; font-weight: normal; }
    td.goal { background: #f1f2fb; padding: 1.2mm 2.5mm; }
    .pct { font-size: 16pt; font-weight: bold; color: {{ $accent }}; font-family: dejavusans; line-height: 1; }
    table.bar { width: 100%; margin-top: 1mm; }
    table.bar td { height: 2.8mm; font-size: 1pt; line-height: 1; padding: 0; }
    td.balance { background: {{ $accent }}; color: #ffffff; padding: 1.5mm 2.5mm; }
    td.balance .lbl { color: #d8dbf0; font-size: 8pt; }
    td.balance .big { font-family: dejavusans; font-size: 13pt; font-weight: bold; color: #ffffff; }
    table.kv { width: 100%; }
    table.kv td { padding: 0.3mm 0; font-size: 8pt; }
    table.kv td.num { text-align: right; font-family: dejavusans; white-space: nowrap; }
    table.kv.light td { color: #d8dbf0; }
    table.items { width: 100%; margin-top: 1.5mm; }
    table.items th { font-size: 7.5pt; font-weight: bold; color: #ffffff; background: {{ $accent }}; padding: 0.7mm 1.4mm; text-align: left; }
    table.items th.num { text-align: right; }
    table.items td { padding: 0.6mm 1.4mm; font-size: 8pt; line-height: 1.15; vertical-align: top; }
    table.items tr.alt td { background: #f4f5fc; }
    table.items tr.grand td { font-weight: bold; border-top: 0.5mm solid {{ $accent }}; }
    .free { font-weight: bold; }
    .num { text-align: right; white-space: nowrap; }
    td.num { font-family: dejavusans; }
    table.lower { width: 100%; margin-top: 1.5mm; }
    table.lower td.cell { vertical-align: top; }
    .section { font-weight: bold; color: {{ $accent }}; font-size: 8.5pt; }
    table.legend { margin-top: 0.8mm; }
    table.legend td { font-size: 7.5pt; padding: 0 1mm 0 0; vertical-align: middle; }
    table.legend td.swatch { width: 2.6mm; height: 2.6mm; font-size: 1pt; padding: 0; }
    table.ledger { width: 100%; margin-top: 0.5mm; }
    table.ledger td { padding: 0.3mm 1mm; font-size: 8pt; border-bottom: 0.2mm solid #eceef8; vertical-align: top; }
    table.ledger td.date { color: #5c6185; }
    table.ledger tr.b td { font-weight: bold; }
    table.sums { width: 100%; }
    table.sums td { padding: 0.3mm 0; font-size: 8.5pt; }
    table.sums td.num { text-align: right; font-family: dejavusans; white-space: nowrap; }
    table.sums tr.net td { font-weight: bold; font-size: 10pt; border-top: 0.6mm solid {{ $accent }}; padding-top: 0.8mm; }
    table.sums tr.left td { font-weight: bold; }
    .words { margin-top: 1.5mm; font-size: 8pt; color: #5c6185; }
    .words strong { color: #1c2140; }
    table.pay { width: 100%; margin-top: 1mm; }
    table.pay th { font-size: 7pt; font-weight: bold; color: #5c6185; text-align: left; padding: 0.6mm; border-bottom: 0.3mm solid {{ $accent }}; }
    table.pay td { font-size: 8pt; padding: 0.6mm; border-bottom: 0.2mm solid #eceef8; }
    .pay-head { font-size: 7.5pt; font-weight: bold; color: {{ $accent }}; margin-top: 1.5mm; }
    .footnote { margin-top: 1.5mm; font-size: 9pt; font-weight: bold; color: #b42318; }
    table.signatures { width: 100%; margin-top: 7mm; }
    table.signatures td { text-align: center; padding: 0 5mm; font-size: 8.5pt; }
    .sig-line { border-top: 0.25mm solid #1c2140; padding-top: 1mm; }
    table.printed { width: 100%; margin-top: 1mm; }
    table.printed td { font-size: 7pt; color: #5c6185; }
</style>

<table class="head">
    <tr>
        @if ($v->logo)<td style="width: 22mm"><img src="{{ $v->logo }}" style="max-height: 16mm; max-width: 20mm;" alt=""></td>@endif
        <td>
            <div class="co-name">{{ $v->head['name'] }}</div>
            @include('sales::print.partials.invoice-company', ['v' => $v])
        </td>
        <td style="text-align: right">
            <div class="doc-title">INVOICE &amp; ACCOUNT STATEMENT</div>
            <div class="doc-no">{{ $facts['bill']['bill_no'] }} · {{ $facts['bill']['bill_date'] }}</div>
            @if ($v->shows('order_no'))<div class="muted" data-order-no>{{ $v->en('order_no') }} {{ $facts['bill']['order_no'] }}</div>@endif
            @if ($v->shows('invoice_type'))<div class="muted" data-invoice-type>{{ $v->en('type') }} {{ $facts['bill']['type'] }}</div>@endif
            @if ($v->duplicate)<div class="dup" data-duplicate>{{ $v->en('duplicate') }}</div>@endif
        </td>
        @if ($v->qr !== '')<td style="width: 22mm; text-align: right">@include('sales::print.partials.invoice-qr', ['v' => $v, 'width' => '19mm'])</td>@endif
    </tr>
</table>

@if ($v->notices !== [])<div class="notice">{{ implode(' · ', $v->notices) }}</div>@endif

<table class="trio">
    <tr>
        <td class="cell" style="padding-right: 3mm">
            @include('sales::print.partials.invoice-bill-to', ['v' => $v, 'facts' => $facts])
            @if ($v->shows('transport'))
                <div data-transport>
                    <div class="cap" style="margin-top: 1mm">DRIVER</div>
                    <div>{{ collect([($transport['driver_name'] ?? '') !== '' ? $transport['driver_name'] : ($transport['carrier'] ?? ''), $transport['driver_phone'] ?? '', $transport['vehicle'] ?? ''])->filter(fn ($x) => $x !== '' && $x !== null)->implode(' · ') }}</div>
                    @if (($transport['delivery_date'] ?? '') !== '')<div class="muted">{{ $v->en('delivery_date') }} {{ $transport['delivery_date'] }}</div>@endif
                </div>
            @endif
        </td>
        @if ($target !== null)
            <td class="cell goal" style="width: 56mm" data-target>
                <table style="width: 100%">
                    <tr>
                        <td style="vertical-align: top"><span style="font-weight: bold">{{ $target['month'] }} target</span><br><span style="font-family: dejavusans">{{ $target['target'] }}</span></td>
                        <td class="pct" style="text-align: right; vertical-align: top">{{ $pct }}%</td>
                    </tr>
                </table>
                <table class="bar">
                    <tr>
                        @if (bccomp($bar, '0', 0) > 0)<td style="width: {{ $bar }}%; background: {{ $accent }}">&nbsp;</td>@endif
                        @if (bccomp($bar, '100', 0) < 0)<td style="width: {{ bcsub('100', $bar, 0) }}%; background: #d8dbf0">&nbsp;</td>@endif
                    </tr>
                </table>
                <table class="kv" style="margin-top: 0.8mm">
                    <tr><td>Done</td><td class="num">{{ $target['achieved'] }}</td></tr>
                    <tr><td style="font-weight: bold; color: {{ $accent }}">Left</td><td class="num" style="font-weight: bold; color: {{ $accent }}">{{ $target['remaining'] }}</td></tr>
                    <tr><td colspan="2" class="muted">by {{ $target['closes_on'] }} · {{ $target['bank_days'] }} bank days</td></tr>
                </table>
            </td>
        @endif
        @if ($v->shows('previous_due'))
            <td class="cell balance" style="width: 54mm">
                <div class="lbl">Closing balance · <span data-balance-word>{{ $v->balanceWord() }}</span></div>
                <div class="big">{{ $v->balanceAmount() }}</div>
                <table class="kv light" style="margin-top: 1mm">
                    <tr data-previous-due><td>Previous</td><td class="num" style="color: #d8dbf0">{{ $v->previousBeforeBill() }}</td></tr>
                    <tr><td>+ Invoice</td><td class="num" style="color: #d8dbf0">{{ $paper->money($s['net_payable']) }}</td></tr>
                    <tr><td>− Paid</td><td class="num" style="color: #d8dbf0">{{ $paper->money($s['paid']) }}</td></tr>
                </table>
            </td>
        @endif
    </tr>
</table>

@include('sales::print.partials.invoice-items', ['v' => $v, 'facts' => $facts, 'paper' => $paper, 'upper' => false, 'zebra' => true, 'inlineLot' => true])

<table class="lower">
    <tr>
        <td class="cell" style="padding-right: 4mm">
            @if ($v->shows('deposits') && $parts !== [])
                <div class="section">How it was paid · <span style="font-family: dejavusans">{{ $paper->money(\App\Core\Support\Money::format($received)) }}</span></div>
                <table class="bar" style="margin-top: 0.8mm">
                    <tr>
                        @foreach ($parts as $part)
                            <td style="width: {{ $part['width'] }}%; background: {{ $part['color'] }}">&nbsp;</td>
                        @endforeach
                    </tr>
                </table>
                <table class="legend">
                    <tr>
                        @foreach ($parts as $part)
                            <td class="swatch" style="background: {{ $part['color'] }}">&nbsp;</td>
                            <td style="padding-right: 3mm">{{ $part['method'] }} <span style="font-family: dejavusans">{{ $paper->money($part['amount']) }}</span></td>
                        @endforeach
                    </tr>
                </table>
            @endif

            @if ($v->shows('previous_due'))
                <div class="section" style="margin-top: 1.5mm">Statement @if ($period !== '')· {{ $period }}@endif</div>
                <table class="ledger" data-movement>
                    @foreach ($rows as $row)
                        @php($amount = $row['debit'] !== '' ? '+ '.$paper->money($row['debit']) : ($row['credit'] !== '' ? '− '.$paper->money($row['credit']) : ''))
                        <tr @class(['b' => $loop->last || ($billNo !== '' && str_contains((string) $row['text'], $billNo))])>
                            <td class="date" style="width: 17mm">{{ $row['date'] }}</td>
                            <td>{{ $row['text'] }}</td>
                            <td class="num" style="width: 25mm">{{ $amount }}</td>
                            <td class="num" style="width: 30mm">{{ $row['balance'] }}</td>
                        </tr>
                    @endforeach
                </table>
            @endif
        </td>
        <td class="cell" style="width: 60mm">
            <table class="sums" data-invoice-summary>
                <tr><td>{{ $v->label('grand_total') }}</td><td class="num">{{ $paper->money($s['grand_total']) }}</td></tr>
                <tr><td>{{ $v->label('discount') }}</td><td class="num">− {{ $paper->money($s['discount']) }}</td></tr>
                @if ($v->showVat)<tr><td>{{ $v->label('vat') }}</td><td class="num">{{ $paper->money($s['vat']) }}</td></tr>@endif
                <tr><td>{{ $v->label('rounding') }}</td><td class="num">{{ $paper->money($s['rounding']) }}</td></tr>
                <tr class="net"><td>{{ $v->label('net_payable') }}</td><td class="num">{{ $paper->money($s['net_payable']) }}</td></tr>
                <tr><td>{{ $v->label('paid') }}</td><td class="num">{{ $paper->money($s['paid']) }}</td></tr>
                <tr class="left"><td data-bill-left>{{ $v->billLeftWord() }}</td><td class="num">{{ $v->billLeftAmount() }}</td></tr>
            </table>
            @if ($v->shows('amount_words'))<div class="words" data-words><strong>{{ $v->en('in_words') }}</strong> {{ $facts['words'] }}</div>@endif
        </td>
    </tr>
</table>

@include('sales::print.partials.invoice-payments', ['v' => $v, 'doc' => $doc])

<div class="footnote"><div style="text-align: left; font-size: 50%; line-height: 1.35">{!! nl2br(e($v->footnote)) !!}</div></div>
@include('sales::print.partials.invoice-signatures', ['v' => $v])
<table class="printed">
    <tr>
        <td>{{ $v->printedAt() }}</td>
        <td style="text-align: right">Visual</td>
    </tr>
</table>
