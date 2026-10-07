{{--
    Statement First — আগে ডিলারের হিসাব, পরে এই বিল। মালিক, ৩ অক্টোবর ২০২৬ (নকশার ক্যানভাস "Style 7", মালিকের অনুমোদিত)।

    ⭐ গড়ন: মাথায় বাঁয়ে মরচে রঙের খাড়া দাগ, তারপর "INVOICE & ACCOUNT STATEMENT", কোম্পানি; ডানে বিল নম্বর, তারিখ, ক্রেতার নাম
    আর QR। তারপর অংশ "1 · YOUR ACCOUNT": বাঁয়ে বিলের মাসের চলাচল ([[InvoicePaperView::movement()]]), ডানে ভরা ঘরে শেষ জের
    Due / Advance / No Due ([[balanceWord()]]) আর আগের বকেয়া (`data-previous-due`), তার নিচে টার্গেটের ফ্রেম (`data-target`,
    লক্ষ্য না থাকলে নেই — [[target()]])। তারপর মোটা দাগের নিচে অংশ "2 · THIS INVOICE": ক্রেতা | ড্রাইভার (`data-transport`),
    পণ্য (লট নামের পাশে), আর তলায় বাঁয়ে কথায় টাকা, ডানে টাকার সারি (`data-invoice-summary`) — Net Payable ভরা ঘরে, শেষ লাইন
    Invoice Due / Extra Paid ([[billLeftWord()]])।
    ⓘ "আগের বকেয়া" সুইচ বন্ধ আর লক্ষ্যও না থাকলে প্রথম অংশটাই নেই, আর অংশের নম্বরও ছাপা হয় না।
    ⓘ সুইচ আর বাকি `data-*` চিহ্ন ভাগের partial-এ।
--}}
@php
    $v = new \App\Modules\Sales\Support\InvoicePaperView($doc, $facts, $company, $profile);
    $accent = '#9a3412';
    $soft = '#fbeee6';
    $t = fn (string $key) => __('sales::invoice_design.'.$key, [], 'en');
    $s = $v->sums;
    $target = $v->target();
    $transport = $facts['transport'] ?? [];
    $account = $v->shows('previous_due') || $target !== null;
@endphp
<style @nonce>
    body { font-family: hindsiliguri, sans-serif; font-size: 8.5pt; color: #231a14; }
    table { border-collapse: collapse; }
    table.head { width: 100%; }
    table.head td { vertical-align: middle; }
    td.bar { width: 1.6mm; background: {{ $accent }}; }
    .kicker { font-size: 10.5pt; font-weight: bold; color: {{ $accent }}; white-space: nowrap; }
    .co-name { font-size: 12pt; font-weight: bold; }
    .co-meta { font-size: 7.5pt; color: #6b5a4e; }
    .right { text-align: right; }
    .no { font-family: dejavusans; font-size: 10.5pt; font-weight: bold; text-align: right; }
    .muted { color: #6b5a4e; }
    .dup { font-size: 7.5pt; font-weight: bold; color: #6b5a4e; text-align: right; }
    .notice { text-align: center; font-weight: bold; border: 0.4mm solid #b42318; color: #b42318; padding: 0.8mm; margin-top: 1.5mm; font-size: 9.5pt; }
    .section { font-weight: bold; color: {{ $accent }}; }
    table.account { width: 100%; margin-top: 1.5mm; }
    table.account td.main { vertical-align: top; padding-right: 3mm; }
    table.account td.side { width: 54mm; vertical-align: top; }
    table.ledger { width: 100%; margin-top: 0.5mm; }
    table.ledger th { background: {{ $soft }}; font-size: 7.5pt; font-weight: bold; text-align: left; padding: 0.6mm 1.2mm; }
    table.ledger th.num { text-align: right; }
    table.ledger td { padding: 0.4mm 1.2mm; border-bottom: 0.2mm solid #efe2d8; font-size: 8pt; }
    table.ledger tr.close td { font-weight: bold; }
    .closing { background: {{ $accent }}; color: #ffffff; padding: 1.5mm 2.5mm; }
    .closing-cap { color: #f6d8c8; }
    .closing-fig { font-family: dejavusans; font-size: 12pt; font-weight: bold; }
    .target { border: 0.25mm solid {{ $accent }}; padding: 1mm 2.5mm; margin-top: 1mm; }
    table.kv { width: 100%; }
    table.kv td { padding: 0.3mm 0; font-size: 8pt; }
    table.kv td.num { text-align: right; font-family: dejavusans; white-space: nowrap; }
    table.kv tr.strong td { font-weight: bold; }
    table.kv tr.net td { background: {{ $accent }}; color: #ffffff; font-weight: bold; font-size: 9.5pt; padding: 0.8mm 1.5mm; }
    table.kv tr.left td { font-weight: bold; color: {{ $accent }}; }
    .invoice-part { margin-top: 2mm; border-top: 0.6mm solid {{ $accent }}; padding-top: 1mm; }
    table.parties { width: 100%; margin-top: 0.8mm; }
    table.parties td { width: 50%; vertical-align: top; font-size: 8pt; }
    .cap { font-weight: bold; color: #6b5a4e; }
    .party { font-weight: bold; }
    table.items { width: 100%; margin-top: 1.5mm; }
    table.items th { background: {{ $soft }}; font-size: 7.5pt; font-weight: bold; padding: 0.6mm 1.5mm; text-align: left; }
    table.items th.num { text-align: right; }
    table.items td { padding: 0.5mm 1.5mm; border-bottom: 0.2mm solid #efe2d8; font-size: 8pt; line-height: 1.15; vertical-align: top; }
    table.items tr.grand td { font-weight: bold; border-top: 0.4mm solid {{ $accent }}; border-bottom: 0; }
    .sub { font-size: 7pt; color: #6b5a4e; }
    .free { font-weight: bold; }
    .num { text-align: right; white-space: nowrap; }
    td.num { font-family: dejavusans; }
    table.bottom { width: 100%; margin-top: 1.5mm; }
    table.bottom td { vertical-align: top; }
    table.pay { width: 100%; margin-top: 1mm; }
    table.pay th { font-size: 7pt; font-weight: bold; color: #6b5a4e; text-align: left; padding: 0.5mm; border-bottom: 0.3mm solid #231a14; }
    table.pay td { font-size: 8pt; padding: 0.5mm; border-bottom: 0.2mm solid #efe2d8; }
    .pay-head { font-weight: bold; color: {{ $accent }}; margin-top: 1.5mm; }
    .footnote { margin-top: 1.5mm; font-size: 9pt; font-weight: bold; color: #b42318; }
    table.signatures { width: 100%; margin-top: 7mm; }
    table.signatures td { text-align: center; padding: 0 4mm; font-size: 8.5pt; }
    .sig-line { border-top: 0.25mm solid #231a14; padding-top: 1mm; }
    table.foot { width: 100%; margin-top: 1.5mm; }
    table.foot td { font-size: 7pt; color: #6b5a4e; }
</style>

<table class="head">
    <tr>
        <td class="bar">&nbsp;</td>
        @if ($v->logo)<td style="width: 22mm; padding-left: 3mm"><img src="{{ $v->logo }}" style="max-height: 16mm; max-width: 22mm;" alt=""></td>@endif
        <td style="padding-left: 3mm">
            <div class="kicker">INVOICE &amp; ACCOUNT STATEMENT</div>
            <div class="co-name">{{ $v->head['name'] }}</div>
            @include('sales::print.partials.invoice-company', ['v' => $v])
        </td>
        <td style="width: 58mm; padding-right: 3mm">
            <div class="no">{{ $facts['bill']['bill_no'] }}</div>
            <div class="right">{{ $facts['bill']['bill_date'] }}</div>
            <div class="right muted">{{ $facts['bill_to']['name'] }}@if (filled($facts['bill_to']['code'] ?? '')) · {{ $facts['bill_to']['code'] }}@endif</div>
            @if ($v->shows('order_no'))<div class="right muted" data-order-no>{{ $v->en('order_no') }} {{ $facts['bill']['order_no'] }}</div>@endif
            @if ($v->shows('invoice_type'))<div class="right muted" data-invoice-type>{{ $v->en('type') }} {{ $facts['bill']['type'] }}</div>@endif
            @if ($v->duplicate)<div class="dup" data-duplicate>{{ $v->en('duplicate') }}</div>@endif
        </td>
        <td style="width: 21mm">@include('sales::print.partials.invoice-qr', ['v' => $v, 'width' => '20mm'])</td>
    </tr>
</table>

@if ($v->notices !== [])<div class="notice">{{ implode(' · ', $v->notices) }}</div>@endif

@if ($account)
    <table class="account">
        <tr>
            <td class="main">
                @if ($v->shows('previous_due'))
                    <div class="section">1 · {{ mb_strtoupper($t('statement')) }}</div>
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
                @else
                    <div class="section">1 · {{ mb_strtoupper($t('account_summary')) }}</div>
                @endif
            </td>
            <td class="side">
                @if ($v->shows('previous_due'))
                    <div class="closing">
                        <div class="closing-cap">Closing balance</div>
                        <div class="closing-fig">{{ $v->balanceAmount() }} <span data-balance-word>{{ $v->balanceWord() }}</span></div>
                        <div class="closing-cap" data-previous-due>Previous {{ $v->previousBeforeBill() }}</div>
                    </div>
                @endif
                @if ($target !== null)
                    <div class="target">
                        <div class="section">Target · {{ $target['month'] }}</div>
                        <table class="kv" data-target>
                            <tr><td>Target</td><td class="num">{{ $target['target'] }}</td></tr>
                            <tr><td>Inflow</td><td class="num">{{ $target['achieved'] }}</td></tr>
                            <tr class="strong"><td>Remaining</td><td class="num">{{ $target['remaining'] }}</td></tr>
                            <tr><td colspan="2" class="muted">Closes {{ $target['closes_on'] }} · {{ $target['bank_days'] }} bank days</td></tr>
                        </table>
                    </div>
                @endif
            </td>
        </tr>
    </table>
@endif

<div class="invoice-part">
    <div class="section">@if ($account)2 · @endif THIS INVOICE</div>
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
</div>

@include('sales::print.partials.invoice-items', ['v' => $v, 'facts' => $facts, 'paper' => $paper, 'upper' => false, 'inlineLot' => true])

<table class="bottom">
    <tr>
        <td style="padding-right: 5mm">
            @if ($v->shows('amount_words'))<div data-words><strong>{{ $v->en('in_words') }}</strong> {{ $facts['words'] }}</div>@endif
            @include('sales::print.partials.invoice-payments', ['v' => $v, 'doc' => $doc])
        </td>
        <td style="width: 64mm">
            <table class="kv" data-invoice-summary>
                <tr><td>{{ $v->label('grand_total') }}</td><td class="num">{{ $paper->money($s['grand_total']) }}</td></tr>
                <tr><td>{{ $v->label('discount') }}</td><td class="num">{{ $paper->money($s['discount']) }}</td></tr>
                @if ($v->showVat)<tr><td>{{ $v->label('vat') }}</td><td class="num">{{ $paper->money($s['vat']) }}</td></tr>@endif
                <tr><td>{{ $v->label('rounding') }}</td><td class="num">{{ $paper->money($s['rounding']) }}</td></tr>
                <tr class="net"><td>{{ $v->label('net_payable') }}</td><td class="num">{{ $paper->money($s['net_payable']) }}</td></tr>
                <tr><td>{{ $v->label('paid') }}</td><td class="num">{{ $paper->money($s['paid']) }}</td></tr>
                <tr class="left"><td data-bill-left>{{ $v->billLeftWord() }}</td><td class="num">{{ $v->billLeftAmount() }}</td></tr>
            </table>
        </td>
    </tr>
</table>

<div class="footnote"><div style="text-align: left; font-size: 80%; line-height: 1.35">{!! nl2br(e($v->footnote)) !!}</div></div>
@include('sales::print.partials.invoice-signatures', ['v' => $v])
<table class="foot">
    <tr>
        <td>{{ $v->printedAt() }}</td>
        <td style="text-align: right">Statement First</td>
    </tr>
</table>
