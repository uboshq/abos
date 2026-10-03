{{--
    Typewriter — বিল + হিসাবের বিবরণী, টাইপরাইটারের চেহারায়। মালিক, ৩ অক্টোবর ২০২৬ (নকশার ক্যানভাস, Style 9)।

    ⓘ সব কাঠামো সমান-চওড়া অক্ষরে (DejaVu Sans Mono), কেবল কালো, দাগ সব ড্যাশের: মাঝখানে কোম্পানি আর
    "*** INVOICE & ACCOUNT STATEMENT ***", তারপর তিন ঘর — বাঁয়ে বিলের ঘরগুলো "INVOICE ...... S-0154" ধাঁচে আর ক্রেতা,
    মাঝে ড্যাশের ফ্রেমে ACCOUNT (আগের + বিল − জমা = জের, ডিলারের ভাষায় Due / Advance) আর TARGET, ডানে QR।
    ⭐ মূল ভাবনা: নিচে দুই ঘর পাশাপাশি — বাঁয়ে বিবরণী এক কলামের খাতা (তারিখ, বিবরণ, +/− অঙ্ক, চলমান জের;
    [[InvoicePaperView::movement()]]), ডানে বিলের যোগ-বিয়োগ, দুই দাগের নিচে বাকির লাইন ([[billLeftWord()]])।
    ⓘ বাংলা লেখা (পণ্যের নাম, ক্রেতা, বিবরণ, সই, ফুটনোট) monospace-এ নেই — ওগুলো hindsiliguri-তে।
    ⓘ সুইচ আর `data-*` চিহ্ন ভাগের partial-এ আর "Special for DB"-এর মতোই; লক্ষ্য না থাকলে TARGET-এর ঘরটাই নেই।
--}}
@php
    $v = new \App\Modules\Sales\Support\InvoicePaperView($doc, $facts, $company, $profile);
    $t = fn (string $key) => __('sales::invoice_design.'.$key, [], 'en');
    $s = $v->sums;
    $target = $v->target();
    $transport = $facts['transport'] ?? [];
    /* ⓘ "INVOICE ...... " — সমান-চওড়া অক্ষরে বিন্দুর সারি, তাই মান এক খাড়া রেখায় */
    $dots = fn (string $label) => str_pad(mb_strtoupper($label).' ', 15, '.').' ';
    $rows = $v->shows('previous_due') ? $v->movement($doc->payments) : [];
    $period = $rows === [] ? '' : implode(' - ', array_values(array_unique(array_filter([(string) $rows[0]['date'], (string) $rows[count($rows) - 1]['date']]))));
    $billNo = (string) ($facts['bill']['bill_no'] ?? '');
@endphp
<style @nonce>
    body { font-family: dejavusansmono, monospace; font-size: 8pt; color: #000000; }
    table { border-collapse: collapse; }
    .bn, .party, td.item-name, .co-name, .co-meta { font-family: hindsiliguri, sans-serif; }
    .center { text-align: center; }
    .co-name { font-size: 15pt; font-weight: bold; letter-spacing: 0.8mm; }
    .co-meta { font-size: 8pt; }
    .banner { margin-top: 1mm; font-weight: bold; letter-spacing: 0.4mm; font-size: 9pt; }
    .rule { border-top: 0.4mm dashed #000000; margin-top: 1.5mm; height: 0; }
    .dup { font-weight: bold; }
    .notice { text-align: center; font-weight: bold; border: 0.4mm dashed #000000; padding: 1mm; margin-top: 2mm; font-size: 10pt; }
    table.top { width: 100%; margin-top: 1.5mm; }
    table.top td { vertical-align: top; }
    .facts div { line-height: 1.35; }
    .cap { font-weight: bold; margin-top: 1mm; font-family: dejavusansmono, monospace; }
    .party { font-weight: bold; font-size: 9pt; }
    .sub { font-size: 7pt; }
    td.frame { border: 0.4mm dashed #000000; padding: 1.2mm 2mm; }
    table.kv { width: 100%; }
    table.kv td { padding: 0.2mm 0; font-size: 8pt; }
    table.kv td.num { text-align: right; white-space: nowrap; }
    table.kv tr.strong td { font-weight: bold; }
    table.kv tr.head td { font-weight: bold; padding-top: 0.8mm; }
    table.kv tr.dash td { border-top: 0.3mm dashed #000000; padding-top: 0.6mm; }
    table.kv tr.net td { font-weight: bold; font-size: 9pt; border-top: 0.3mm dashed #000000; border-bottom: 1mm double #000000; padding: 0.6mm 0; }
    table.items { width: 100%; margin-top: 1mm; }
    table.items th { font-size: 7.5pt; font-weight: bold; padding: 0.6mm 1mm; text-align: left; border-bottom: 0.3mm dashed #000000; }
    table.items th.num { text-align: right; }
    table.items td { padding: 0.45mm 1mm; font-size: 8pt; line-height: 1.2; vertical-align: top; }
    table.items tr.grand td { font-weight: bold; border-top: 0.3mm dashed #000000; }
    .free { font-weight: bold; }
    .num { text-align: right; white-space: nowrap; }
    table.bottom { width: 100%; margin-top: 1.5mm; }
    table.bottom td.cell { vertical-align: top; }
    table.ledger { width: 100%; }
    table.ledger td { padding: 0.25mm 0.8mm; font-size: 7.5pt; vertical-align: top; }
    table.ledger td.what { font-family: hindsiliguri, sans-serif; }
    table.ledger tr.b td { font-weight: bold; }
    .words { margin-top: 1.5mm; font-size: 8pt; }
    .pay-head { font-weight: bold; margin-top: 2mm; }
    table.pay { width: 100%; margin-top: 0.5mm; }
    table.pay th { font-size: 7pt; font-weight: bold; text-align: left; padding: 0.4mm 0.8mm; border-bottom: 0.3mm dashed #000000; }
    table.pay td { font-size: 7.5pt; padding: 0.4mm 0.8mm; }
    .footnote { margin-top: 2mm; font-family: hindsiliguri, sans-serif; font-size: 9pt; font-weight: bold; }
    table.signatures { width: 100%; margin-top: 10mm; }
    table.signatures td { text-align: center; padding: 0 5mm; font-size: 8pt; font-family: hindsiliguri, sans-serif; }
    .sig-line { border-top: 0.3mm dashed #000000; padding-top: 1mm; }
    table.printed { width: 100%; margin-top: 1.5mm; }
    table.printed td { font-size: 7pt; }
</style>

<table style="width: 100%">
    <tr>
        <td style="width: 24mm; vertical-align: middle">@if ($v->logo)<img src="{{ $v->logo }}" style="max-height: 14mm; max-width: 22mm;" alt="">@endif</td>
        <td class="center" style="vertical-align: middle">
            <div class="co-name">{{ mb_strtoupper($v->head['name']) }}</div>
            @include('sales::print.partials.invoice-company', ['v' => $v])
            <div class="banner">*** INVOICE &amp; ACCOUNT STATEMENT ***</div>
            @if ($v->duplicate)<div class="dup" data-duplicate>-- {{ $v->en('duplicate') }} --</div>@endif
        </td>
        <td style="width: 24mm"></td>
    </tr>
</table>
<div class="rule"></div>

@if ($v->notices !== [])<div class="notice">{{ implode(' * ', $v->notices) }}</div>@endif

<table class="top">
    <tr>
        <td class="facts" style="padding-right: 3mm">
            <div>{{ $dots('Invoice') }}{{ $facts['bill']['bill_no'] }}</div>
            <div>{{ $dots('Date') }}{{ $facts['bill']['bill_date'] }}</div>
            @if ($v->shows('order_no'))<div data-order-no>{{ $dots($v->label('order_no')) }}{{ $facts['bill']['order_no'] }}</div>@endif
            @if ($v->shows('invoice_type'))<div data-invoice-type>{{ $dots($v->label('type')) }}{{ $facts['bill']['type'] }}</div>@endif
            @if ($v->shows('transport'))
                <div data-transport>{{ $dots('Driver') }}<span class="bn">{{ collect([($transport['driver_name'] ?? '') !== '' ? $transport['driver_name'] : ($transport['carrier'] ?? ''), $transport['driver_phone'] ?? '', $transport['vehicle'] ?? ''])->filter(fn ($x) => $x !== '' && $x !== null)->implode(' / ') }}</span>@if (($transport['delivery_date'] ?? '') !== '')<br>{{ $dots($v->label('delivery_date')) }}{{ $transport['delivery_date'] }}@endif</div>
            @endif
            <div class="bn">@include('sales::print.partials.invoice-bill-to', ['v' => $v, 'facts' => $facts])</div>
        </td>
        @if ($v->shows('previous_due') || $target !== null)
            <td class="frame" style="width: 64mm">
                @if ($v->shows('previous_due'))
                <table class="kv">
                        <tr class="head"><td colspan="2">ACCOUNT</td></tr>
                        <tr data-previous-due><td>PREVIOUS</td><td class="num">{{ $v->previousBeforeBill() }}</td></tr>
                        <tr><td>+ INVOICE</td><td class="num">{{ $paper->money($s['net_payable']) }}</td></tr>
                        <tr><td>- RECEIVED</td><td class="num">{{ $paper->money($s['paid']) }}</td></tr>
                        <tr class="strong dash"><td>= <span data-balance-word>{{ $v->balanceWord() }}</span></td><td class="num">{{ $v->balanceAmount() }}</td></tr>
                </table>
                @endif
                @if ($target !== null)
                    <table class="kv" data-target>
                        <tr class="head"><td colspan="2">TARGET {{ mb_strtoupper($target['month']) }}</td></tr>
                        <tr><td>TARGET</td><td class="num">{{ $target['target'] }}</td></tr>
                        <tr><td>DONE</td><td class="num">{{ $target['achieved'] }}</td></tr>
                        <tr class="strong"><td>REMAINING</td><td class="num">{{ $target['remaining'] }}</td></tr>
                        <tr><td colspan="2">BY {{ $target['closes_on'] }} ({{ $target['bank_days'] }} BANK DAYS)</td></tr>
                    </table>
                @endif
            </td>
        @endif
        @if ($v->qr !== '')
            <td style="width: 26mm; padding-left: 3mm; text-align: right">
                <table style="width: 100%"><tr><td class="frame" style="text-align: center">@include('sales::print.partials.invoice-qr', ['v' => $v, 'width' => '19mm'])</td></tr></table>
            </td>
        @endif
    </tr>
</table>
<div class="rule"></div>

@include('sales::print.partials.invoice-items', ['v' => $v, 'facts' => $facts, 'paper' => $paper, 'upper' => true, 'inlineLot' => true])
<div class="rule"></div>

<table class="bottom">
    <tr>
        <td class="cell" style="padding-right: 4mm">
            @if ($v->shows('previous_due'))
                <div style="font-weight: bold">STATEMENT {{ $period }}</div>
                <table class="ledger" data-movement>
                    @foreach ($rows as $row)
                        @php($amount = $row['debit'] !== '' ? '+'.$paper->money($row['debit']) : ($row['credit'] !== '' ? '-'.$paper->money($row['credit']) : ''))
                        <tr @class(['b' => $loop->last || ($billNo !== '' && str_contains((string) $row['text'], $billNo))])>
                            <td style="width: 17mm">{{ $row['date'] }}</td>
                            <td class="what">{{ $row['text'] }}</td>
                            <td class="num" style="width: 24mm">{{ $amount }}</td>
                            <td class="num" style="width: 30mm">{{ $row['balance'] }}</td>
                        </tr>
                    @endforeach
                </table>
            @endif
        </td>
        <td class="cell" style="width: 62mm">
            <table class="kv" data-invoice-summary>
                <tr><td>{{ mb_strtoupper($v->label('grand_total')) }}</td><td class="num">{{ $paper->money($s['grand_total']) }}</td></tr>
                <tr><td>{{ mb_strtoupper($v->label('discount')) }}</td><td class="num">-{{ $paper->money($s['discount']) }}</td></tr>
                @if ($v->showVat)<tr><td>{{ mb_strtoupper($v->label('vat')) }}</td><td class="num">{{ $paper->money($s['vat']) }}</td></tr>@endif
                <tr><td>{{ mb_strtoupper($v->label('rounding')) }}</td><td class="num">{{ $paper->money($s['rounding']) }}</td></tr>
                <tr class="net"><td>NET PAYABLE</td><td class="num">{{ $paper->money($s['net_payable']) }}</td></tr>
                <tr><td>{{ mb_strtoupper($v->label('paid')) }}</td><td class="num">{{ $paper->money($s['paid']) }}</td></tr>
                <tr class="strong"><td data-bill-left>{{ $v->billLeftWord() }}</td><td class="num">{{ $v->billLeftAmount() }}</td></tr>
            </table>
        </td>
    </tr>
</table>

@if ($v->shows('amount_words'))<div class="words" data-words>IN WORDS: <span class="bn">{{ $facts['words'] }}</span></div>@endif

@include('sales::print.partials.invoice-payments', ['v' => $v, 'doc' => $doc])

<div class="footnote"><div style="text-align: left; font-size: 50%; line-height: 1.35">{!! nl2br(e($v->footnote)) !!}</div></div>
@include('sales::print.partials.invoice-signatures', ['v' => $v])
<table class="printed">
    <tr>
        <td class="bn">{{ $v->printedAt() }}</td>
        <td style="text-align: right">TYPEWRITER</td>
    </tr>
</table>
