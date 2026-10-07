{{--
    Tear-off Slip — উপরে বিল, ড্যাশের দাগের নিচে কেটে রাখার হিসাবের অংশ। মালিক, ৩ অক্টোবর ২০২৬ (নকশার ক্যানভাস "Style 5",
    মালিকের অনুমোদিত)।

    ⭐ গড়ন: মাথায় বাঁয়ে QR, মাঝে কোম্পানি, ডানে "INVOICE & ACCOUNT STATEMENT" আর বিল নম্বর-তারিখ; নিচে দুই দাগ।
    তারপর এক সারিতে ক্রেতা আর হালকা ঘরে ড্রাইভার (`data-transport`); বাদামি মাথার পণ্যের ছক (লট নামের পাশে); তার নিচে
    এক লাইনের টাকার ফিতা (`data-invoice-summary`, শেষ ঘর Invoice Due / Extra Paid — [[InvoicePaperView::billLeftWord()]]),
    কথায় টাকা, জমা, সই। ✂ তারপর কাটার দাগ, আর ফ্রেমের ভেতর কেটে রাখার অংশ: বাঁয়ে হিসাবের সারাংশ (আগের বকেয়া + এই বিল −
    জমা = Due / Advance / No Due — [[balanceWord()]]) আর টার্গেট (`data-target`, না থাকলে নেই — [[target()]]), ডানে বিলের
    মাসের চলাচল ([[InvoicePaperView::movement()]]) আর ডিপোর কপির সইয়ের লাইন।
    ⓘ "আগের বকেয়া" সুইচ বন্ধ হলে সারাংশ আর চলাচল দুটোই যায়; সুইচ আর বাকি `data-*` চিহ্ন ভাগের partial-এ।
--}}
@php
    $v = new \App\Modules\Sales\Support\InvoicePaperView($doc, $facts, $company, $profile);
    $accent = '#6b4f1d';
    $soft = '#f7f1e6';
    $t = fn (string $key) => __('sales::invoice_design.'.$key, [], 'en');
    $s = $v->sums;
    $target = $v->target();
    $transport = $facts['transport'] ?? [];
@endphp
<style @nonce>
    body { font-family: hindsiliguri, sans-serif; font-size: 8pt; line-height: 1.15; color: #2b2116; }
    table { border-collapse: collapse; }
    table.head { width: 100%; border-bottom: 1.2mm double {{ $accent }}; }
    table.head td { vertical-align: middle; padding-bottom: 1mm; }
    .co-name { font-size: 13pt; font-weight: bold; color: {{ $accent }}; }
    .co-meta { font-size: 7.5pt; line-height: 1.1; color: #5c5040; }
    .title { font-size: 10pt; font-weight: bold; text-align: right; white-space: nowrap; }
    .right { text-align: right; }
    .no { font-family: dejavusans; font-weight: bold; }
    .dup { font-size: 7.5pt; font-weight: bold; color: #5c5040; }
    .notice { text-align: center; font-weight: bold; border: 0.4mm solid #b42318; color: #b42318; padding: 1mm; margin-top: 2mm; font-size: 10pt; }
    table.parties { width: 100%; margin-top: 1.2mm; }
    table.parties td { vertical-align: top; font-size: 8pt; }
    td.driver { width: 66mm; background: {{ $soft }}; padding: 0.6mm 2.5mm; }
    .cap { font-weight: bold; }
    .party { font-weight: bold; }
    .muted { color: #5c5040; }
    table.items { width: 100%; margin-top: 1.2mm; }
    table.items th { background: {{ $accent }}; color: #ffffff; font-size: 7.5pt; font-weight: bold; padding: 0.5mm 1.5mm; text-align: left; }
    table.items th.num { text-align: right; }
    table.items td { padding: 0.3mm 1.5mm; border-bottom: 0.2mm solid #e4d8c2; font-size: 8pt; line-height: 1.1; vertical-align: top; }
    table.items tr.alt td { background: #fdfbf6; }
    table.items tr.grand td { font-weight: bold; border-top: 0.4mm solid {{ $accent }}; }
    .sub { font-size: 7pt; color: #5c5040; }
    .free { font-weight: bold; }
    .num { text-align: right; white-space: nowrap; }
    td.num { font-family: dejavusans; }
    table.strip { width: 100%; background: {{ $soft }}; margin-top: 1mm; }
    table.strip td { padding: 0.6mm 2mm; font-size: 8pt; font-weight: bold; vertical-align: top; }
    table.strip td .lbl { font-size: 7pt; font-weight: normal; color: #5c5040; }
    table.strip td .val { font-family: dejavusans; white-space: nowrap; }
    table.strip td.net { color: {{ $accent }}; }
    .words { margin-top: 0.8mm; font-size: 8pt; }
    table.pay { width: 100%; margin-top: 1mm; }
    table.pay th { font-size: 7pt; font-weight: bold; color: #5c5040; text-align: left; padding: 0.3mm 0.5mm; border-bottom: 0.3mm solid #2b2116; }
    table.pay td { font-size: 7.5pt; padding: 0.3mm 0.5mm; border-bottom: 0.2mm solid #e4d8c2; }
    .pay-head { font-size: 7.5pt; font-weight: bold; color: {{ $accent }}; margin-top: 0.8mm; }
    .footnote { margin-top: 0.8mm; font-size: 9pt; font-weight: bold; color: #b42318; }
    table.signatures { width: 100%; margin-top: 6mm; }
    table.signatures td { text-align: center; padding: 0 4mm; font-size: 8pt; }
    .sig-line { border-top: 0.25mm solid #2b2116; padding-top: 0.8mm; }
    table.cut { width: 100%; margin-top: 2mm; }
    table.cut td { vertical-align: middle; color: {{ $accent }}; font-size: 8pt; font-weight: bold; }
    table.cut td.cut-label { width: 92mm; text-align: center; white-space: nowrap; padding: 0 2mm; }
    table.cut td.dash { border-top: 0.4mm dashed {{ $accent }}; }
    .scissors { font-family: dejavusans; font-size: 11pt; }
    table.slip { width: 100%; margin-top: 1mm; border: 0.4mm solid {{ $accent }}; }
    table.slip td.slip-side { width: 58mm; vertical-align: top; padding: 1.2mm 3mm 1.2mm 2.5mm; }
    table.slip td.slip-main { vertical-align: top; padding: 1.2mm 2.5mm 1.2mm 0; }
    .slip-cap { font-weight: bold; color: {{ $accent }}; }
    table.kv { width: 100%; }
    table.kv td { padding: 0.15mm 0; font-size: 7.5pt; }
    table.kv td.num { text-align: right; font-family: dejavusans; white-space: nowrap; }
    table.kv tr.strong td { font-weight: bold; }
    table.kv tr.final td { font-weight: bold; color: {{ $accent }}; border-top: 0.3mm solid {{ $accent }}; padding-top: 0.4mm; }
    .target { background: {{ $soft }}; padding: 0.8mm 2mm; margin-top: 1.2mm; }
    table.ledger { width: 100%; margin-top: 0.5mm; }
    table.ledger th { font-size: 7pt; font-weight: bold; text-align: left; padding: 0.3mm 1mm; border-bottom: 0.4mm solid {{ $accent }}; }
    table.ledger th.num { text-align: right; }
    table.ledger td { padding: 0.3mm 1mm; border-bottom: 0.2mm solid #e4d8c2; font-size: 7.5pt; }
    table.ledger tr.close td { font-weight: bold; }
    .depot { margin-top: 1mm; font-size: 7.5pt; }
    table.foot { width: 100%; margin-top: 1mm; }
    table.foot td { font-size: 7pt; color: #5c5040; }
</style>

<table class="head">
    <tr>
        <td style="width: 22mm">@include('sales::print.partials.invoice-qr', ['v' => $v, 'width' => '19mm'])</td>
        @if ($v->logo)<td style="width: 22mm; padding-right: 2mm"><img src="{{ $v->logo }}" style="max-height: 16mm; max-width: 22mm;" alt=""></td>@endif
        <td style="padding-left: 2mm">
            <div class="co-name">{{ $v->head['name'] }}</div>
            @include('sales::print.partials.invoice-company', ['v' => $v])
        </td>
        <td class="right" style="width: 62mm">
            <div class="title">INVOICE &amp; ACCOUNT STATEMENT</div>
            <div class="no">{{ $facts['bill']['bill_no'] }}</div>
            <div>{{ $facts['bill']['bill_date'] }}</div>
            @if ($v->shows('order_no'))<div class="muted" data-order-no>{{ $v->en('order_no') }} {{ $facts['bill']['order_no'] }}</div>@endif
            @if ($v->shows('invoice_type'))<div class="muted" data-invoice-type>{{ $v->en('type') }} {{ $facts['bill']['type'] }}</div>@endif
            @if ($v->duplicate)<div class="dup" data-duplicate>{{ $v->en('duplicate') }}</div>@endif
        </td>
    </tr>
</table>

@if ($v->notices !== [])<div class="notice">{{ implode(' · ', $v->notices) }}</div>@endif

<table class="parties">
    <tr>
        <td style="padding-right: 4mm">@include('sales::print.partials.invoice-bill-to', ['v' => $v, 'facts' => $facts, 'cap' => 'cap'])</td>
        @if ($v->shows('transport'))
            <td class="driver" data-transport>
                <span class="cap">Driver:</span>
                {{ ($transport['driver_name'] ?? '') !== '' ? $transport['driver_name'] : ($transport['carrier'] ?? '') }}
                @if (($transport['driver_phone'] ?? '') !== '') · {{ $transport['driver_phone'] }}@endif
                @if (($transport['vehicle'] ?? '') !== '') · {{ $v->en('vehicle') }} {{ $transport['vehicle'] }}@endif
                @if (($transport['delivery_date'] ?? '') !== '')<div class="muted">{{ $v->en('delivery_date') }} {{ $transport['delivery_date'] }}</div>@endif
            </td>
        @endif
    </tr>
</table>

@include('sales::print.partials.invoice-items', ['v' => $v, 'facts' => $facts, 'paper' => $paper, 'upper' => false, 'zebra' => true, 'inlineLot' => true])

<table class="strip" data-invoice-summary>
    <tr>
        <td><div class="lbl">{{ $v->label('grand_total') }}</div><div class="val">{{ $paper->money($s['grand_total']) }}</div></td>
        <td><div class="lbl">{{ $v->label('discount') }}</div><div class="val">{{ $paper->money($s['discount']) }}</div></td>
        @if ($v->showVat)<td><div class="lbl">{{ $v->label('vat') }}</div><div class="val">{{ $paper->money($s['vat']) }}</div></td>@endif
        <td><div class="lbl">{{ $v->label('rounding') }}</div><div class="val">{{ $paper->money($s['rounding']) }}</div></td>
        <td class="net"><div class="lbl">{{ $v->label('net_payable') }}</div><div class="val">{{ $paper->money($s['net_payable']) }}</div></td>
        <td><div class="lbl">{{ $v->label('paid') }}</div><div class="val">{{ $paper->money($s['paid']) }}</div></td>
        <td class="net"><div class="lbl" data-bill-left>{{ $v->billLeftWord() }}</div><div class="val">{{ $v->billLeftAmount() }}</div></td>
    </tr>
</table>

@if ($v->shows('amount_words'))<div class="words" data-words><strong>{{ $v->en('in_words') }}</strong> {{ $facts['words'] }}</div>@endif

@include('sales::print.partials.invoice-payments', ['v' => $v, 'doc' => $doc])

<div class="footnote"><div style="text-align: left; font-size: 80%; line-height: 1.35">{!! nl2br(e($v->footnote)) !!}</div></div>
@include('sales::print.partials.invoice-signatures', ['v' => $v])

<table class="cut">
    <tr>
        <td style="width: 6mm"><span class="scissors">&#9986;</span></td>
        <td class="dash">&nbsp;</td>
        {{-- ⓘ বাঁধা mm চওড়া: mPDF `width: 1%` ঘরে `nowrap` মানে না — লেখা এক অক্ষর এক লাইনে খাড়া হয়ে পাতা খেত (৬০%-এ আঁটত) --}}
        <td class="cut-label">{{ $t('cut_here') }}</td>
        <td class="dash">&nbsp;</td>
    </tr>
</table>

<table class="slip">
    <tr>
        <td class="slip-side">
            @if ($v->shows('previous_due'))
                <div class="slip-cap">{{ $t('account_summary') }}</div>
                <table class="kv">
                    <tr data-previous-due><td>{{ $v->label('previous_due') }}</td><td class="num">{{ $v->previousBeforeBill() }}</td></tr>
                    <tr><td>+ This invoice</td><td class="num">{{ $paper->money($s['net_payable']) }}</td></tr>
                    <tr><td>- {{ $v->label('paid') }}</td><td class="num">{{ $paper->money($s['paid']) }}</td></tr>
                    <tr class="final"><td data-balance-word>{{ $v->balanceWord() }}</td><td class="num">{{ $v->balanceAmount() }}</td></tr>
                </table>
            @endif
            @if ($target !== null)
                <div class="target">
                    <div class="slip-cap">Target · {{ $target['month'] }}</div>
                    <table class="kv" data-target>
                        <tr><td>Target</td><td class="num">{{ $target['target'] }}</td></tr>
                        <tr><td>Inflow achieved</td><td class="num">{{ $target['achieved'] }}</td></tr>
                        <tr class="strong"><td>Remaining</td><td class="num">{{ $target['remaining'] }}</td></tr>
                        <tr><td colspan="2">Closes {{ $target['closes_on'] }} · {{ $target['bank_days'] }} bank days</td></tr>
                    </table>
                </div>
            @endif
        </td>
        <td class="slip-main">
            @if ($v->shows('previous_due'))
                <div class="slip-cap">{{ $t('statement') }}</div>
                <table class="ledger" data-movement>
                    <tr>
                        <th style="width: 16mm">{{ $v->label('txn_date') }}</th>
                        <th>{{ $t('particulars') }}</th>
                        <th class="num">{{ $t('debit') }}</th>
                        <th class="num">{{ $t('credit') }}</th>
                        <th class="num">{{ $t('balance') }}</th>
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
            <div class="depot">Depot copy · {{ $facts['bill']['bill_no'] }} · {{ $facts['bill_to']['name'] }} · Customer signature ..............................</div>
        </td>
    </tr>
</table>

<table class="foot">
    <tr>
        <td>{{ $v->printedAt() }}</td>
        <td style="text-align: right">Tear-off Slip</td>
    </tr>
</table>
