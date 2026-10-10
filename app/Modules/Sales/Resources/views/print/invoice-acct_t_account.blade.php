{{--
    T-Account Ledger — বিল + হিসাবের বিবরণী, হিসাবের খাতার "T" ছকে। মালিক, ৩ অক্টোবর ২০২৬ (নকশার ক্যানভাস, Style 8)।

    ⓘ উপরে বাঁয়ে কোম্পানি, ডানে "INVOICE & ACCOUNT STATEMENT", বিল নম্বর আর QR — নিচে একটা লাল দাগ। তারপর দুই ভাগ:
    ক্রেতা (Bill To) আর ডেলিভারি (ড্রাইভার + টার্গেটের এক লাইন)। পণ্যের ছক কালো দুই দাগের মাঝে, তার নিচে বিলের হিসাব
    এক সারির ঘরে ঘরে (`data-invoice-summary`)। ⭐ মূল ভাবনা: বিলের মাসের হিসাবের চলাচল ([[InvoicePaperView::movement()]])
    খাতার মতো দুই পাশে — বাঁয়ে Dr (ডিলারের দেনা: আগের জের, বিল), ডানে Cr (ডিলারের জমা), মাঝে মোটা খাড়া দাগ; জের
    "Balance c/d" হয়ে হালকা পাশে বসে, তাই দুই পাশের যোগফল সমান, দুই দাগের নিচে। শেষে এক লাইনে
    আগের + এই বিল − জমা = জের, ডিলারের ভাষায় Due / Advance / No Due ([[InvoicePaperView::balanceWord()]])।
    ⓘ সুইচ আর `data-*` চিহ্ন ভাগের partial-এ আর "Special for DB"-এর মতোই; লক্ষ্য না থাকলে টার্গেটের লাইনটাই নেই।
    ⓘ Dr/Cr-এর যোগ কেবল bcmath-এ, `movement()`-এর ছাপার অঙ্ক থেকে — নতুন কোনো হিসাব নয়।
--}}
@php
    $v = new \App\Modules\Sales\Support\InvoicePaperView($doc, $facts, $company, $profile);
    $accent = '#a61b1b';
    $t = fn (string $key) => __('sales::invoice_design.'.$key, [], 'en');
    $s = $v->sums;
    $target = $v->target();
    $transport = $facts['transport'] ?? [];

    /* ⓘ ছাপার অঙ্ক ("12,500.00", "250.79 Due", "1,000.00 Advance") থেকে চিহ্নসহ সংখ্যা — Advance মানে ঋণাত্মক */
    $plain = function (string $text): string {
        $n = preg_replace('/[^0-9.\-]/', '', $text);

        return is_numeric($n) ? bcadd($n, '0', 4) : '0.0000';
    };
    $signed = fn (string $text) => str_contains($text, 'Advance') ? bcmul($plain($text), '-1', 4) : $plain($text);
    $fmt = fn (string $n) => \App\Core\Support\Money::format($n);

    $dr = [];
    $cr = [];
    $drSum = '0';
    $crSum = '0';
    $period = '';

    if ($v->shows('previous_due')) {
        $rows = $v->movement($doc->payments);
        $first = $rows[0] ?? ['date' => '', 'text' => '', 'balance' => '0'];
        $last = $rows[count($rows) - 1] ?? $first;
        $open = $signed((string) $first['balance']);
        $close = $signed((string) $last['balance']);
        $billNo = (string) ($facts['bill']['bill_no'] ?? '');

        if (bccomp($open, '0', 4) > 0) {
            $dr[] = ['date' => $first['date'], 'text' => $first['text'].' b/d', 'amount' => $open, 'bold' => false];
        } elseif (bccomp($open, '0', 4) < 0) {
            $cr[] = ['date' => $first['date'], 'text' => $first['text'].' b/d', 'amount' => bcmul($open, '-1', 4), 'bold' => false];
        }

        foreach (array_slice($rows, 1) as $row) {
            $mine = $billNo !== '' && str_contains((string) $row['text'], $billNo);
            if ($row['debit'] !== '') {
                $dr[] = ['date' => $row['date'], 'text' => $row['text'], 'amount' => $plain((string) $row['debit']), 'bold' => $mine];
            }
            if ($row['credit'] !== '') {
                $cr[] = ['date' => $row['date'], 'text' => $row['text'], 'amount' => $plain((string) $row['credit']), 'bold' => $mine];
            }
        }

        /* ⭐ জের হালকা পাশে বসে — বকেয়া হলে Cr-এ, অগ্রিম হলে Dr-এ — তাই দুই পাশের যোগ সমান */
        if (bccomp($close, '0', 4) > 0) {
            $cr[] = ['date' => $last['date'], 'text' => 'Balance c/d (Due)', 'amount' => $close, 'bold' => true];
        } elseif (bccomp($close, '0', 4) < 0) {
            $dr[] = ['date' => $last['date'], 'text' => 'Balance c/d (Advance)', 'amount' => bcmul($close, '-1', 4), 'bold' => true];
        }

        foreach ($dr as $line) {
            $drSum = bcadd($drSum, $line['amount'], 4);
        }
        foreach ($cr as $line) {
            $crSum = bcadd($crSum, $line['amount'], 4);
        }

        $period = implode(' to ', array_values(array_unique(array_filter([(string) $first['date'], (string) $last['date']]))));
    }

    $lines = max(count($dr), count($cr));
@endphp
<style @nonce>
    body { font-family: hindsiliguri, sans-serif; font-size: 8.5pt; color: #1a1a1a; }
    table { border-collapse: collapse; }
    .mono { font-family: dejavusansmono, monospace; }
    .muted { color: #555555; }
    table.head { width: 100%; border-bottom: 0.3mm solid {{ $accent }}; }
    table.head td { vertical-align: top; padding-bottom: 1.2mm; line-height: 1.15; }
    .co-name { font-size: 14pt; font-weight: bold; line-height: 1.1; }
    .co-meta { font-size: 7.5pt; color: #555555; }
    .doc-title { font-size: 10.5pt; font-weight: bold; color: {{ $accent }}; white-space: nowrap; }
    .doc-no { font-family: dejavusansmono, monospace; font-weight: bold; font-size: 9pt; }
    .dup { font-size: 7.5pt; font-weight: bold; color: #555555; }
    .notice { text-align: center; font-weight: bold; border: 0.4mm solid #b42318; color: #b42318; padding: 1mm; margin-top: 2mm; font-size: 10pt; }
    table.parties { width: 100%; margin-top: 1.5mm; }
    table.parties td { vertical-align: top; width: 50%; font-size: 8.5pt; line-height: 1.15; }
    .cap { font-weight: bold; color: {{ $accent }}; font-size: 8.5pt; }
    .party { font-size: 10pt; font-weight: bold; }
    .sub { font-size: 7pt; color: #555555; font-weight: normal; }
    table.items { width: 100%; margin-top: 1.5mm; }
    table.items th { font-size: 7.5pt; font-weight: bold; padding: 0.6mm 1.4mm; text-align: left; border-top: 0.5mm solid #1a1a1a; border-bottom: 0.5mm solid #1a1a1a; }
    table.items th.num { text-align: right; }
    table.items td { padding: 0.5mm 1.4mm; border-bottom: 0.2mm dotted #b5b5b5; font-size: 8pt; line-height: 1.1; vertical-align: top; }
    table.items tr.grand td { font-weight: bold; border-top: 0.5mm solid #1a1a1a; border-bottom: 0.5mm solid #1a1a1a; }
    .free { font-weight: bold; }
    .num { text-align: right; white-space: nowrap; }
    td.num { font-family: dejavusans; }
    table.sumline { width: 100%; margin-top: 1mm; border-bottom: 0.5mm solid #1a1a1a; }
    table.sumline td { padding: 0.3mm 1.4mm; line-height: 1.15; text-align: right; vertical-align: bottom; }
    table.sumline tr.lbl td { font-size: 7pt; color: #555555; }
    table.sumline tr.val td { font-family: dejavusans; font-size: 8.5pt; white-space: nowrap; }
    table.sumline td.net { font-weight: bold; border-left: 0.3mm solid #1a1a1a; border-right: 0.3mm solid #1a1a1a; }
    table.sumline tr.lbl td.net { color: {{ $accent }}; }
    table.sumline td.left { font-weight: bold; }
    .words { margin-top: 1mm; font-size: 8pt; color: #555555; }
    .t-title { text-align: center; font-weight: bold; font-size: 9pt; margin-top: 2mm; }
    table.taccount { width: 100%; margin-top: 1mm; border-top: 0.5mm solid #1a1a1a; }
    table.taccount td { padding: 0.35mm 1.2mm; font-size: 8pt; line-height: 1.1; vertical-align: top; }
    table.taccount td.mid { border-right: 0.5mm solid #1a1a1a; }
    table.taccount tr.side td { font-weight: bold; color: {{ $accent }}; padding-top: 0.6mm; }
    table.taccount td.b { font-weight: bold; }
    table.taccount td.cr { padding-left: 2.5mm; }
    table.taccount tr.total td.amt { font-weight: bold; border-top: 0.3mm solid #1a1a1a; border-bottom: 1.2mm double #1a1a1a; }
    .strip-wrap { width: 100%; margin-top: 1mm; }
    .strip-wrap td { background: #f7eaea; padding: 0.7mm 2mm; font-weight: bold; font-size: 8.5pt; }
    .strip-wrap td.closing { text-align: right; color: {{ $accent }}; white-space: nowrap; }
    table.pay { width: 100%; margin-top: 1mm; }
    table.pay th { font-size: 7pt; font-weight: bold; color: #555555; text-align: left; padding: 0.6mm; border-bottom: 0.3mm solid #1a1a1a; }
    table.pay td { font-size: 8pt; padding: 0.6mm; border-bottom: 0.2mm dotted #b5b5b5; }
    .pay-head { font-size: 7.5pt; font-weight: bold; color: {{ $accent }}; margin-top: 1.5mm; }
    .footnote { margin-top: 1.5mm; font-size: 9pt; font-weight: bold; color: #b42318; }
    table.signatures { width: 100%; margin-top: 7mm; }
    table.signatures td { text-align: center; padding: 0 5mm; font-size: 8.5pt; }
    .sig-line { border-top: 0.25mm solid #1a1a1a; padding-top: 1mm; }
    table.printed { width: 100%; margin-top: 1.5mm; }
    table.printed td { font-size: 7pt; color: #555555; }
</style>

<table class="head">
    <tr>
        @if ($v->logo)<td style="width: 22mm; vertical-align: middle"><img src="{{ $v->logo }}" style="max-height: 16mm; max-width: 20mm;" alt=""></td>@endif
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
        @if ($v->qr !== '')<td style="width: 22mm; text-align: right; vertical-align: middle">@include('sales::print.partials.invoice-qr', ['v' => $v, 'width' => '19mm'])</td>@endif
    </tr>
</table>

@if ($v->notices !== [])<div class="notice">{{ implode(' · ', $v->notices) }}</div>@endif

<table class="parties">
    <tr>
        <td style="padding-right: 4mm">
            @include('sales::print.partials.invoice-bill-to', ['v' => $v, 'facts' => $facts])
        </td>
        <td style="padding-left: 4mm">
            @if ($v->shows('transport'))
                <div data-transport>
                    <div class="cap">DELIVERY</div>
                    <div>Driver {{ ($transport['driver_name'] ?? '') !== '' ? $transport['driver_name'] : ($transport['carrier'] ?? '') }}@if (($transport['driver_phone'] ?? '') !== '') · {{ $transport['driver_phone'] }}@endif</div>
                    @if (($transport['vehicle'] ?? '') !== '')<div>{{ $v->en('vehicle') }} {{ $transport['vehicle'] }}</div>@endif
                    @if (($transport['delivery_date'] ?? '') !== '')<div class="muted">{{ $v->en('delivery_date') }} {{ $transport['delivery_date'] }}</div>@endif
                </div>
            @endif
            @if ($target !== null)
                <div class="muted" data-target>Target {{ $target['month'] }}: {{ $target['achieved'] }} of {{ $target['target'] }} · {{ $target['remaining'] }} left by {{ $target['closes_on'] }} ({{ $target['bank_days'] }} bank days)</div>
            @endif
        </td>
    </tr>
</table>

@include('sales::print.partials.invoice-items', ['v' => $v, 'facts' => $facts, 'paper' => $paper, 'upper' => false, 'inlineLot' => true])

<table class="sumline" data-invoice-summary>
    <tr class="lbl">
        <td>{{ $v->label('grand_total') }}</td>
        <td>{{ $v->label('discount') }}</td>
        @if ($v->showVat)<td>{{ $v->label('vat') }}</td>@endif
        <td>{{ $v->label('rounding') }}</td>
        <td class="net">{{ $v->label('net_payable') }}</td>
        <td>{{ $v->label('paid') }}</td>
        <td class="left" data-bill-left>{{ $v->billLeftWord() }}</td>
    </tr>
    <tr class="val">
        <td>{{ $paper->money($s['grand_total']) }}</td>
        <td>{{ $paper->money($s['discount']) }}</td>
        @if ($v->showVat)<td>{{ $paper->money($s['vat']) }}</td>@endif
        <td>{{ $paper->money($s['rounding']) }}</td>
        <td class="net">{{ $paper->money($s['net_payable']) }}</td>
        <td>{{ $paper->money($s['paid']) }}</td>
        <td class="left">{{ $v->billLeftAmount() }}</td>
    </tr>
</table>

@if ($v->shows('amount_words'))<div class="words" data-words><strong>{{ $v->en('in_words') }}</strong> {{ $facts['words'] }}</div>@endif

@if ($v->shows('previous_due'))
    <div class="t-title">{{ $facts['bill_to']['name'] }} — Account @if ($period !== '')· {{ $period }}@endif</div>
    <table class="taccount" data-movement>
        <tr class="side">
            <td style="width: 15mm">Dr</td>
            <td>{{ __('sales::print.owed_by_customer') }}</td>
            <td class="num mid" style="width: 26mm">Taka</td>
            <td style="width: 15mm; padding-left: 2.5mm">Cr</td>
            <td>{{ __('sales::print.paid_by_customer') }}</td>
            <td class="num" style="width: 26mm">Taka</td>
        </tr>
        @for ($i = 0; $i < $lines; $i++)
            @php($l = $dr[$i] ?? null)
            @php($r = $cr[$i] ?? null)
            @php($lb = $l['bold'] ?? false)
            @php($rb = $r['bold'] ?? false)
            <tr>
                <td @class(['b' => $lb])>{{ $l['date'] ?? '' }}</td>
                <td @class(['b' => $lb])>{{ $l['text'] ?? '' }}</td>
                <td @class(['num', 'mid', 'b' => $lb])>{{ $l !== null ? $paper->money($fmt($l['amount'])) : '' }}</td>
                <td @class(['cr', 'b' => $rb])>{{ $r['date'] ?? '' }}</td>
                <td @class(['b' => $rb])>{{ $r['text'] ?? '' }}</td>
                <td @class(['num', 'b' => $rb])>{{ $r !== null ? $paper->money($fmt($r['amount'])) : '' }}</td>
            </tr>
        @endfor
        <tr class="total">
            <td></td>
            <td></td>
            <td class="num mid amt">{{ $paper->money($fmt($drSum)) }}</td>
            <td style="padding-left: 2.5mm"></td>
            <td></td>
            <td class="num amt">{{ $paper->money($fmt($crSum)) }}</td>
        </tr>
    </table>
    <table class="strip-wrap">
        <tr>
            <td data-previous-due>{{ __('sales::print.previous_short') }} {{ $v->previousBeforeBill() }} + {{ __('sales::print.this_invoice') }} {{ $paper->money($s['net_payable']) }} − {{ __('sales::print.received_short') }} {{ $paper->money($s['paid']) }}</td>
            <td class="closing">{{ __('sales::print.closing_short') }} <span data-balance-word>{{ $v->balanceWord() }}</span> <span style="font-family: dejavusans">{{ $v->balanceAmount() }}</span></td>
        </tr>
    </table>
@endif

@include('sales::print.partials.invoice-payments', ['v' => $v, 'doc' => $doc])

<div class="footnote"><div style="text-align: left; font-size: 80%; line-height: 1.35">{!! nl2br(e($v->footnote)) !!}</div></div>
@include('sales::print.partials.invoice-signatures', ['v' => $v])
<table class="printed">
    <tr>
        <td>{{ $v->printedAt() }}</td>
        <td style="text-align: right">T-Account Ledger</td>
    </tr>
</table>
