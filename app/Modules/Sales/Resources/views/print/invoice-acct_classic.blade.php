{{--
    Invoice & Account Statement — ক্লাসিক ব্যাংক বিবরণীর ধাঁচে বিল। মালিক, ৩ অক্টোবর ২০২৬ (নকশার ক্যানভাস "১ · ক্লাসিক ব্যাংক বিবরণী")।

    ⓘ গড়ন উপর থেকে নিচে, পুরো পাতা জুড়ে (ফিতা নেই): মাথায় বাঁয়ে কোম্পানি, মাঝে QR, ডানে "INVOICE & ACCOUNT STATEMENT"
    আর বিল নম্বর — নিচে মোটা নীল দাগ। তারপর তিন ঘরের সারি: ক্রেতা (+ ড্রাইভার), হিসাবের সারাংশ (আগের জের → এই বিল →
    জমা → শেষ লাইন Due / Advance / No Due), টার্গেট রিমাইন্ডার। তারপর পণ্যের ছক, আর তার নিচে দুই ঘর: বাঁয়ে বিলের
    মাসের হিসাবের চলাচল ([[InvoicePaperView::movement()]]) শেষ জেরের সারিসহ, ডানে টাকার যোগফল (শেষে Invoice Due /
    Extra Paid — [[InvoicePaperView::billLeftWord()]])। তারপর কথায়, জমার ছক, সই।
    ⓘ সুইচ আর `data-*` চিহ্ন "Special for DB"-র মতোই, একই শর্তে; লক্ষ্য না থাকলে বাক্সটাই নেই ([[InvoicePaperView::target()]])।
    ⓘ লট আলাদা কলাম নয় — নামের পাশে, এক লাইনে (`inlineLot`), যাতে A4-এর এক পাতায় ২৫ সারি ধরে।
--}}
@php
    $v = new \App\Modules\Sales\Support\InvoicePaperView($doc, $facts, $company, $profile);
    $accent = '#0b5394';
    $tint = '#eef4fa';
    $t = fn (string $key) => __('sales::invoice_design.'.$key, [], 'en');
    $s = $v->sums;
    $target = $v->target();
    $transport = $facts['transport'] ?? [];
    $officer = (string) ($facts['bill']['sales_officer'] ?? '');
@endphp
<style @nonce>
    body { font-family: hindsiliguri, sans-serif; font-size: 8.5pt; color: #15202b; }
    table { border-collapse: collapse; }
    table.head { width: 100%; border-bottom: 1.1mm solid {{ $accent }}; }
    table.head td { vertical-align: middle; padding-bottom: 1.5mm; line-height: 1.15; }
    .co-name { font-size: 14pt; font-weight: bold; color: {{ $accent }}; line-height: 1.1; }
    .co-meta { font-size: 7.5pt; color: #4a5560; }
    .title { font-size: 10pt; font-weight: bold; letter-spacing: 0.3mm; text-align: right; white-space: nowrap; }
    .no { font-size: 11.5pt; font-weight: bold; font-family: dejavusans; text-align: right; }
    .right { text-align: right; }
    .muted { color: #4a5560; }
    .dup { font-size: 7.5pt; font-weight: bold; color: #4a5560; text-align: right; }
    .notice { text-align: center; font-weight: bold; border: 0.4mm solid #b42318; color: #b42318; padding: 1mm; margin-top: 2mm; font-size: 10pt; }
    table.trio { width: 100%; margin-top: 1.5mm; }
    table.trio td { line-height: 1.15; }
    table.trio td.cell { width: 33.33%; vertical-align: top; padding: 0 2mm; }
    table.trio td.first { padding-left: 0; }
    table.trio td.last { padding-right: 0; }
    .cap { font-size: 7.5pt; font-weight: bold; letter-spacing: 0.3mm; color: #4a5560; }
    .cap-accent { font-size: 7.5pt; font-weight: bold; letter-spacing: 0.3mm; color: {{ $accent }}; }
    .party { font-size: 10.5pt; font-weight: bold; }
    .driver { margin-top: 1mm; padding-top: 0.8mm; border-top: 0.25mm dashed #9aa5ae; }
    .tintbox { background: {{ $tint }}; padding: 1mm 2.5mm; margin-top: 0.8mm; }
    table.kv { width: 100%; }
    table.kv td { padding: 0.15mm 0; font-size: 8pt; line-height: 1.15; }
    table.kv td.num { text-align: right; font-family: dejavusans; white-space: nowrap; }
    table.kv tr.final td { font-weight: bold; font-size: 9.5pt; color: {{ $accent }}; border-top: 0.25mm solid {{ $accent }}; padding-top: 0.8mm; }
    .section { margin-top: 1.5mm; font-size: 7.5pt; font-weight: bold; letter-spacing: 0.3mm; color: {{ $accent }}; }
    table.items { width: 100%; margin-top: 0.8mm; }
    table.items th { font-size: 7.5pt; font-weight: bold; color: #4a5560; padding: 0.6mm 1.5mm; text-align: left; border-bottom: 0.5mm solid #15202b; }
    table.items th.num { text-align: right; }
    table.items td { padding: 0.5mm 1.5mm; border-bottom: 0.2mm solid #d4dbe1; font-size: 8pt; line-height: 1.1; vertical-align: top; }
    table.items tr.grand td { font-weight: bold; border-top: 0.5mm solid #15202b; border-bottom: 0.5mm solid #15202b; }
    table.items tr.grand td.grand-amount { color: {{ $accent }}; }
    .sub { font-size: 7pt; color: #4a5560; }
    .free { font-weight: bold; }
    .num { text-align: right; white-space: nowrap; }
    td.num { font-family: dejavusans; }
    table.lower { width: 100%; margin-top: 1.5mm; }
    table.lower td.stmt { vertical-align: top; padding-right: 4mm; }
    table.lower td.totals { width: 55mm; vertical-align: top; padding-top: 3.5mm; }
    table.ledger { width: 100%; margin-top: 0.8mm; }
    table.ledger th { font-size: 7.5pt; color: #4a5560; font-weight: bold; text-align: left; white-space: nowrap; padding: 0.5mm 1.2mm; border-bottom: 0.5mm solid #15202b; }
    table.ledger th.num { text-align: right; }
    table.ledger td { padding: 0.4mm 1.2mm; border-bottom: 0.2mm solid #d4dbe1; font-size: 8pt; line-height: 1.1; }
    table.ledger tr.closing td { font-weight: bold; border-top: 0.5mm solid #15202b; border-bottom: none; }
    table.ledger tr.closing td.num { color: {{ $accent }}; }
    table.sums { width: 100%; }
    table.sums td { padding: 0.25mm 0; font-size: 8.5pt; line-height: 1.1; }
    table.sums td.num { text-align: right; font-family: dejavusans; white-space: nowrap; }
    table.sums tr.net td { font-weight: bold; font-size: 9.5pt; border-top: 0.5mm solid #15202b; padding-top: 0.8mm; }
    table.sums tr.left td { font-weight: bold; color: {{ $accent }}; background: {{ $tint }}; padding: 0.8mm 1mm; }
    .words { margin-top: 1.5mm; font-size: 8.5pt; }
    table.pay { width: 100%; margin-top: 1mm; }
    table.pay th { font-size: 7pt; font-weight: bold; color: #4a5560; text-align: left; padding: 0.6mm; border-bottom: 0.3mm solid #15202b; }
    table.pay td { font-size: 8pt; padding: 0.6mm; border-bottom: 0.2mm solid #d4dbe1; }
    .pay-head { font-size: 7.5pt; font-weight: bold; color: {{ $accent }}; margin-top: 1.5mm; }
    .footnote { margin-top: 1.5mm; font-size: 9pt; font-weight: bold; color: #b42318; }
    table.signatures { width: 100%; margin-top: 7mm; }
    table.signatures td { text-align: center; padding: 0 4mm; font-size: 8.5pt; }
    .sig-line { border-top: 0.25mm solid #15202b; padding-top: 1mm; }
    table.foot { width: 100%; margin-top: 1.5mm; }
    table.foot td { font-size: 7pt; color: #4a5560; }
</style>

<table class="head">
    <tr>
        @if ($v->logo)<td style="width: 22mm"><img src="{{ $v->logo }}" style="max-height: 18mm; max-width: 20mm;" alt=""></td>@endif
        <td>
            <div class="co-name">{{ $v->head['name'] }}</div>
            @include('sales::print.partials.invoice-company', ['v' => $v])
        </td>
        <td style="width: 22mm; text-align: center">@include('sales::print.partials.invoice-qr', ['v' => $v, 'width' => '18mm'])</td>
        <td style="width: 68mm; text-align: right">
            <div class="title">INVOICE &amp; ACCOUNT STATEMENT</div>
            <div class="no">{{ $facts['bill']['bill_no'] }}</div>
            <div class="right muted">{{ $v->label('bill_date') }} {{ $facts['bill']['bill_date'] }}</div>
            @if ($v->shows('order_no'))<div class="right muted" data-order-no>{{ $v->en('order_no') }} {{ $facts['bill']['order_no'] }}</div>@endif
            @if ($v->shows('invoice_type'))<div class="right muted" data-invoice-type>{{ $v->en('type') }} {{ $facts['bill']['type'] }}</div>@endif
            @if ($v->duplicate)<div class="dup" data-duplicate>{{ $v->en('duplicate') }}</div>@endif
        </td>
    </tr>
</table>

@if ($v->notices !== [])<div class="notice">{{ implode(' · ', $v->notices) }}</div>@endif

<table class="trio">
    <tr>
        <td class="cell first">
            @include('sales::print.partials.invoice-bill-to', ['v' => $v, 'facts' => $facts, 'cap' => 'cap'])
            @if ($officer !== '')<div class="muted">{{ $t('sales_officer') }} {{ $officer }}</div>@endif
            @if ($v->shows('transport'))
                <div class="driver" data-transport>
                    <div>Driver {{ ($transport['driver_name'] ?? '') !== '' ? $transport['driver_name'] : ($transport['carrier'] ?? '') }}@if (($transport['driver_phone'] ?? '') !== '') · {{ $transport['driver_phone'] }}@endif</div>
                    @if (($transport['vehicle'] ?? '') !== '')<div>{{ $v->en('vehicle') }} {{ $transport['vehicle'] }}</div>@endif
                    @if (($transport['delivery_date'] ?? '') !== '')<div class="muted">{{ $v->en('delivery_date') }} {{ $transport['delivery_date'] }}</div>@endif
                </div>
            @endif
        </td>
        <td class="cell">
            <div class="cap-accent">{{ mb_strtoupper($t('account_summary')) }}</div>
            <div class="tintbox">
                <table class="kv">
                    @if ($v->shows('previous_due'))
                        <tr data-previous-due><td>{{ $v->label('previous_due') }}</td><td class="num">{{ $v->previousBeforeBill() }}</td></tr>
                    @endif
                    <tr><td>+ {{ __('sales::print.this_invoice', [], 'en') }}</td><td class="num">{{ $paper->money($s['net_payable']) }}</td></tr>
                    <tr><td>− {{ $v->label('paid') }}</td><td class="num">{{ $paper->money($s['paid']) }}</td></tr>
                    @if ($v->shows('previous_due'))
                        <tr class="final"><td data-balance-word>{{ $v->balanceWord() }}</td><td class="num">{{ $v->balanceAmount() }}</td></tr>
                    @else
                        <tr class="final"><td>{{ $v->billLeftWord() }}</td><td class="num">{{ $v->billLeftAmount() }}</td></tr>
                    @endif
                </table>
            </div>
        </td>
        <td class="cell last">
            @if ($target !== null)
                <div class="cap-accent">TARGET REMINDER</div>
                <div class="tintbox">
                    <table class="kv" data-target>
                        <tr><td>{{ $target['month'] }} target</td><td class="num">{{ $target['target'] }}</td></tr>
                        <tr><td>Inflow achieved</td><td class="num">{{ $target['achieved'] }}</td></tr>
                        <tr class="final"><td>Remaining</td><td class="num">{{ $target['remaining'] }}</td></tr>
                        <tr><td>Closing date</td><td class="num">{{ $target['closes_on'] }}</td></tr>
                        <tr><td class="muted">Remaining bank days</td><td class="num muted">{{ $target['bank_days'] }}</td></tr>
                    </table>
                </div>
            @endif
        </td>
    </tr>
</table>

<div class="section">{{ mb_strtoupper($t('goods')) }} · {{ $facts['total_items'] ?? count($facts['items']['rows']) }} ITEMS</div>
@include('sales::print.partials.invoice-items', ['v' => $v, 'facts' => $facts, 'paper' => $paper, 'upper' => false, 'inlineLot' => true])

<table class="lower">
    <tr>
        <td class="stmt">
            @if ($v->shows('previous_due'))
                <div class="section" style="margin-top: 0">{{ mb_strtoupper($t('statement')) }}</div>
                <table class="ledger" data-movement>
                    <tr>
                        <th style="width: 17mm">{{ $v->label('txn_date') }}</th>
                        <th>{{ $t('particulars') }}</th>
                        <th class="num" style="width: 21mm">{{ $t('debit') }}</th>
                        <th class="num" style="width: 21mm">{{ $t('credit') }}</th>
                        <th class="num" style="width: 28mm">{{ $t('balance') }}</th>
                    </tr>
                    @foreach ($v->movement($doc->payments) as $row)
                        <tr>
                            <td>{{ $row['date'] }}</td>
                            <td>{{ $row['text'] }}</td>
                            <td class="num">{{ $row['debit'] }}</td>
                            <td class="num">{{ $row['credit'] }}</td>
                            <td class="num">{{ $row['balance'] }}</td>
                        </tr>
                    @endforeach
                    <tr class="closing">
                        <td colspan="4">{{ __('sales::print.closing_balance', [], 'en') }} · {{ $facts['bill']['bill_date'] }}</td>
                        <td class="num">{{ $v->balanceAmount() }} {{ $v->balanceWord() }}</td>
                    </tr>
                </table>
            @endif
        </td>
        <td class="totals">
            <table class="sums" data-invoice-summary>
                <tr><td>{{ $v->label('grand_total') }}</td><td class="num">{{ $paper->money($s['grand_total']) }}</td></tr>
                <tr><td>{{ $v->label('discount') }}</td><td class="num">− {{ $paper->money($s['discount']) }}</td></tr>
                @if ($v->showVat)<tr><td>{{ $v->label('vat') }}</td><td class="num">{{ $paper->money($s['vat']) }}</td></tr>@endif
                <tr><td>{{ $v->label('rounding') }}</td><td class="num">{{ $paper->money($s['rounding']) }}</td></tr>
                <tr class="net"><td>{{ $v->label('net_payable') }}</td><td class="num">{{ $paper->money($s['net_payable']) }}</td></tr>
                <tr><td>{{ $v->label('paid') }}</td><td class="num">{{ $paper->money($s['paid']) }}</td></tr>
                <tr class="left"><td data-bill-left>{{ $v->billLeftWord() }}</td><td class="num">{{ $v->billLeftAmount() }}</td></tr>
            </table>
        </td>
    </tr>
</table>

@if ($v->shows('amount_words'))<div class="words" data-words><strong>{{ $v->en('in_words') }}</strong> {{ $facts['words'] }}</div>@endif

@include('sales::print.partials.invoice-payments', ['v' => $v, 'doc' => $doc])

<div class="footnote"><div style="text-align: left; font-size: 80%; line-height: 1.35">{!! nl2br(e($v->footnote)) !!}</div></div>
@include('sales::print.partials.invoice-signatures', ['v' => $v])
<table class="foot">
    <tr>
        <td>{{ $v->printedAt() }}</td>
        <td style="text-align: right">Invoice &amp; Account Statement</td>
    </tr>
</table>
