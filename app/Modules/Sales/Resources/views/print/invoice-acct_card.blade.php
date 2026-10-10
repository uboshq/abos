{{--
    Modern Card — বিল + হিসাবের বিবরণী, কার্ডে ভাগ করা। মালিক, ৩ অক্টোবর ২০২৬ (নকশার ক্যানভাস "Style 4", মালিকের অনুমোদিত)।

    ⭐ গড়ন: মাথায় বাঁয়ে বড় "Invoice / & Account Statement", মাঝে টিল রঙের বড় কার্ডে শেষ জের — Due / Advance / No Due
    ([[InvoicePaperView::balanceWord()]]), নিচে আগের বকেয়া আর "+ Bill − Paid"; ডানে QR-এর ঘর। তারপর পাশাপাশি তিনটা কার্ড:
    ক্রেতা, ড্রাইভার (`data-transport`), আর টার্গেট — অগ্রগতির দাগসহ (`data-target`, লক্ষ্য না থাকলে কার্ডটাই নেই,
    [[InvoicePaperView::target()]])। তারপর ফ্রেমে পণ্য (লট নামের পাশে, আলাদা কলাম নয়), আর তলায় বাঁয়ে বিলের সারাংশের কার্ড
    (`data-invoice-summary`, শেষ লাইন Invoice Due / Extra Paid — [[billLeftWord()]]), ডানে বিলের মাসের চলাচল এক টাকার কলামে
    "+ / −" দিয়ে ([[InvoicePaperView::movement()]])।
    ⓘ "আগের বকেয়া" সুইচ বন্ধ হলে বড় কার্ড এই বিলের বাকি দেখায় আর চলাচলের কার্ড থাকে না।
    ⓘ সুইচ আর বাকি `data-*` চিহ্ন ভাগের partial-এ; এখানে কেবল আঁকা।
--}}
@php
    $v = new \App\Modules\Sales\Support\InvoicePaperView($doc, $facts, $company, $profile);
    $accent = '#0f766e';
    $t = fn (string $key) => __('sales::invoice_design.'.$key, [], 'en');
    $s = $v->sums;
    $target = $v->target();
    $transport = $facts['transport'] ?? [];
    /* ⓘ টার্গেটের দাগ — কতটা আদায় হলো, শতাংশে; bcmath-এ, ছাপার অঙ্ক থেকে কমা বাদ দিয়ে */
    $pct = 0;
    if ($target !== null) {
        $plain = fn ($x) => is_numeric($c = str_replace([',', ' '], '', (string) $x)) ? $c : '0';
        $pct = bccomp($plain($target['target']), '0', 4) > 0
            ? (int) bcdiv(bcmul($plain($target['achieved']), '100', 4), $plain($target['target']), 0)
            : 0;
        $pct = max(0, min(100, $pct));
    }
@endphp
<style @nonce>
    body { font-family: hindsiliguri, sans-serif; font-size: 8.5pt; color: #1f2933; }
    table { border-collapse: collapse; }
    table.head { width: 100%; }
    table.head td { vertical-align: middle; line-height: 1.15; }
    .co-line { font-size: 9pt; font-weight: bold; color: {{ $accent }}; }
    .co-meta { font-size: 7.5pt; color: #52606d; }
    .title { font-size: 20pt; font-weight: bold; line-height: 1.0; margin-top: 0.5mm; }
    .subtitle { font-size: 10pt; font-weight: bold; color: #52606d; }
    .facts { margin-top: 0.5mm; color: #52606d; }
    .no { font-family: dejavusans; font-weight: bold; color: #1f2933; }
    .dup { font-size: 7.5pt; font-weight: bold; color: #52606d; }
    td.hero { width: 60mm; background: {{ $accent }}; color: #ffffff; padding: 2mm 4mm; vertical-align: middle; }
    .hero-cap { font-weight: bold; color: #ccebe7; }
    .hero-amount { font-family: dejavusans; font-size: 15pt; font-weight: bold; }
    .hero-word { font-size: 10pt; font-weight: bold; }
    .hero-sub { color: #ccebe7; font-size: 8pt; }
    td.qrcell { width: 24mm; border: 0.25mm solid #cbd2d9; text-align: center; vertical-align: middle; padding: 1mm; }
    .notice { text-align: center; font-weight: bold; border: 0.4mm solid #b42318; color: #b42318; padding: 1mm; margin-top: 2mm; font-size: 10pt; }
    table.cards { width: 100%; margin-top: 2mm; }
    table.cards td.card { border: 0.25mm solid #e4e7eb; padding: 1.2mm 2.5mm; vertical-align: top; line-height: 1.15; }
    table.cards td.gap { width: 3mm; }
    .cap { font-weight: bold; color: #52606d; }
    .party { font-size: 10pt; font-weight: bold; }
    .muted { color: #52606d; }
    table.kv { width: 100%; }
    table.kv td { padding: 0.2mm 0; font-size: 8pt; line-height: 1.15; }
    table.kv td.num { text-align: right; font-family: dejavusans; white-space: nowrap; }
    table.kv tr.strong td { font-weight: bold; }
    table.kv tr.accent td { font-weight: bold; color: {{ $accent }}; }
    table.kv tr.rule td { border-top: 0.25mm solid #e4e7eb; padding-top: 0.8mm; }
    table.kv tr.big td { font-size: 10pt; }
    table.bar { width: 100%; margin: 0.8mm 0; }
    table.bar td { height: 2.2mm; font-size: 1pt; line-height: 1; }
    table.frame { width: 100%; margin-top: 2mm; border: 0.25mm solid #e4e7eb; }
    table.items { width: 100%; }
    table.items th { background: #f5f7fa; font-size: 7.5pt; font-weight: bold; color: #52606d; padding: 0.7mm 2mm; text-align: left; }
    table.items th.num { text-align: right; }
    table.items td { padding: 0.5mm 2mm; border-top: 0.2mm solid #eef1f4; font-size: 8pt; line-height: 1.1; vertical-align: top; }
    table.items tr.grand td { font-weight: bold; border-top: 0.4mm solid {{ $accent }}; }
    table.items tr.grand td.grand-amount { color: {{ $accent }}; }
    .sub { font-size: 7pt; color: #7b8794; }
    .free { font-weight: bold; color: {{ $accent }}; }
    .num { text-align: right; white-space: nowrap; }
    td.num { font-family: dejavusans; }
    table.bottom { width: 100%; margin-top: 2mm; }
    table.bottom td.card { border: 0.25mm solid #e4e7eb; padding: 1.2mm 2.5mm; vertical-align: top; }
    table.bottom td.gap { width: 3mm; }
    table.ledger { width: 100%; margin-top: 0.8mm; }
    table.ledger td { padding: 0.25mm 0.8mm; font-size: 8pt; line-height: 1.15; }
    table.ledger td.date { width: 15mm; color: #7b8794; }
    table.ledger tr.close td { font-weight: bold; border-top: 0.25mm solid #e4e7eb; }
    .words { margin-top: 1.5mm; color: #52606d; }
    .words strong { color: #1f2933; }
    table.pay { width: 100%; margin-top: 1mm; }
    table.pay th { font-size: 7pt; font-weight: bold; color: #52606d; text-align: left; padding: 0.6mm; border-bottom: 0.3mm solid #1f2933; }
    table.pay td { font-size: 8pt; padding: 0.6mm; border-bottom: 0.2mm solid #eef1f4; }
    .pay-head { font-weight: bold; color: #52606d; margin-top: 1.5mm; }
    .footnote { margin-top: 1.5mm; font-size: 9pt; font-weight: bold; color: #b42318; }
    table.signatures { width: 100%; margin-top: 7mm; }
    table.signatures td { text-align: center; padding: 0 4mm; font-size: 8.5pt; }
    .sig-line { border-top: 0.25mm solid #1f2933; padding-top: 1mm; }
    table.foot { width: 100%; margin-top: 1.5mm; }
    table.foot td { font-size: 7pt; color: #7b8794; }
</style>

<table class="head">
    <tr>
        @if ($v->logo)<td style="width: 22mm; padding-right: 3mm"><img src="{{ $v->logo }}" style="max-height: 18mm; max-width: 22mm;" alt=""></td>@endif
        <td style="padding-right: 4mm">
            <div class="co-line">{{ $v->head['name'] }}</div>
            @include('sales::print.partials.invoice-company', ['v' => $v])
            <div class="title">Invoice</div>
            <div class="subtitle">&amp; Account Statement</div>
            <div class="facts">
                <span class="no">{{ $facts['bill']['bill_no'] }}</span> &nbsp; {{ $facts['bill']['bill_date'] }}
                @if ($v->shows('order_no')) &nbsp; <span data-order-no>{{ $v->en('order_no') }} {{ $facts['bill']['order_no'] }}</span>@endif
                @if ($v->shows('invoice_type')) &nbsp; <span data-invoice-type>{{ $v->en('type') }} {{ $facts['bill']['type'] }}</span>@endif
            </div>
            @if ($v->duplicate)<div class="dup" data-duplicate>{{ $v->en('duplicate') }}</div>@endif
        </td>
        <td class="hero">
            @if ($v->shows('previous_due'))
                <div class="hero-cap">{{ __('sales::print.closing_balance') }}</div>
                <div class="hero-amount">{{ $v->balanceAmount() }} <span class="hero-word" data-balance-word>{{ $v->balanceWord() }}</span></div>
                <div class="hero-sub" data-previous-due>{{ __('sales::print.previous_short') }} {{ $v->previousBeforeBill() }}</div>
                <div class="hero-sub">+ {{ __('sales::print.bill_short') }} {{ $paper->money($s['net_payable']) }} - {{ __('sales::print.paid') }} {{ $paper->money($s['paid']) }}</div>
            @else
                <div class="hero-cap">{{ __('sales::print.this_invoice') }}</div>
                <div class="hero-amount">{{ $v->billLeftAmount() }} <span class="hero-word">{{ $v->billLeftWord() }}</span></div>
                <div class="hero-sub">{{ __('sales::print.bill_short') }} {{ $paper->money($s['net_payable']) }} - {{ __('sales::print.paid') }} {{ $paper->money($s['paid']) }}</div>
            @endif
        </td>
        <td style="width: 3mm"></td>
        <td class="qrcell">@include('sales::print.partials.invoice-qr', ['v' => $v, 'width' => '22mm'])</td>
    </tr>
</table>

@if ($v->notices !== [])<div class="notice">{{ implode(' · ', $v->notices) }}</div>@endif

<table class="cards">
    <tr>
        <td class="card">
            @include('sales::print.partials.invoice-bill-to', ['v' => $v, 'facts' => $facts, 'cap' => 'cap'])
        </td>
        @if ($v->shows('transport'))
            <td class="gap"></td>
            <td class="card" data-transport>
                <div class="cap">Driver</div>
                <div class="party">{{ ($transport['driver_name'] ?? '') !== '' ? $transport['driver_name'] : ($transport['carrier'] ?? '') }}</div>
                @if (($transport['driver_phone'] ?? '') !== '')<div>{{ $transport['driver_phone'] }}</div>@endif
                @if (($transport['vehicle'] ?? '') !== '')<div>{{ $transport['vehicle'] }}</div>@endif
                @if (($transport['delivery_date'] ?? '') !== '')<div class="muted">{{ $v->en('delivery_date') }} {{ $transport['delivery_date'] }}</div>@endif
            </td>
        @endif
        @if ($target !== null)
            <td class="gap"></td>
            <td class="card">
                <table class="kv" data-target>
                    <tr class="strong"><td class="muted">{{ $target['month'] }} target</td><td class="num">{{ $target['target'] }}</td></tr>
                    <tr><td colspan="2">
                        <table class="bar"><tr>
                            @if ($pct > 0)<td style="width: {{ $pct }}%; background: {{ $accent }}">&nbsp;</td>@endif
                            @if ($pct < 100)<td style="background: #e4e7eb">&nbsp;</td>@endif
                        </tr></table>
                    </td></tr>
                    <tr><td>Achieved {{ $pct }}%</td><td class="num">{{ $target['achieved'] }}</td></tr>
                    <tr class="accent"><td>Remaining</td><td class="num">{{ $target['remaining'] }}</td></tr>
                    <tr><td colspan="2" class="muted">Closes {{ $target['closes_on'] }} · {{ $target['bank_days'] }} bank days</td></tr>
                </table>
            </td>
        @endif
    </tr>
</table>

<table class="frame"><tr><td>
    @include('sales::print.partials.invoice-items', ['v' => $v, 'facts' => $facts, 'paper' => $paper, 'upper' => false, 'inlineLot' => true])
</td></tr></table>

<table class="bottom">
    <tr>
        <td class="card" style="width: 62mm">
            <table class="kv" data-invoice-summary>
                <tr><td>{{ $v->label('grand_total') }}</td><td class="num">{{ $paper->money($s['grand_total']) }}</td></tr>
                <tr><td>{{ $v->label('discount') }}</td><td class="num">{{ $paper->money($s['discount']) }}</td></tr>
                @if ($v->showVat)<tr><td>{{ $v->label('vat') }}</td><td class="num">{{ $paper->money($s['vat']) }}</td></tr>@endif
                <tr><td>{{ $v->label('rounding') }}</td><td class="num">{{ $paper->money($s['rounding']) }}</td></tr>
                <tr class="strong rule big"><td>{{ $v->label('net_payable') }}</td><td class="num">{{ $paper->money($s['net_payable']) }}</td></tr>
                <tr><td class="muted">{{ $v->label('paid') }}</td><td class="num">{{ $paper->money($s['paid']) }}</td></tr>
                <tr class="accent"><td data-bill-left>{{ $v->billLeftWord() }}</td><td class="num">{{ $v->billLeftAmount() }}</td></tr>
            </table>
        </td>
        @if ($v->shows('previous_due'))
            <td class="gap"></td>
            <td class="card">
                <div class="cap">{{ $t('statement') }}</div>
                <table class="ledger" data-movement>
                    @foreach ($v->movement($doc->payments) as $row)
                        <tr @class(['close' => $loop->last])>
                            <td class="date">{{ $row['date'] }}</td>
                            <td>{{ $row['text'] }}</td>
                            <td class="num">{{ $row['debit'] !== '' ? '+ '.$row['debit'] : ($row['credit'] !== '' ? '− '.$row['credit'] : '') }}</td>
                            <td class="num">{{ $row['balance'] }}</td>
                        </tr>
                    @endforeach
                </table>
            </td>
        @endif
    </tr>
</table>

@if ($v->shows('amount_words'))<div class="words" data-words><strong>{{ $v->en('in_words') }}</strong> {{ $facts['words'] }}</div>@endif

@include('sales::print.partials.invoice-payments', ['v' => $v, 'doc' => $doc])

<div class="footnote"><div style="text-align: left; font-size: 80%; line-height: 1.35">{!! nl2br(e($v->footnote)) !!}</div></div>
@include('sales::print.partials.invoice-signatures', ['v' => $v])
<table class="foot">
    <tr>
        <td>{{ $v->printedAt() }}</td>
        <td style="text-align: right">Modern Card</td>
    </tr>
</table>
