{{--
    ক্রয় বিল — নতুন কাগজ (মালিকের অনুমোদন, ১ অক্টোবর ২০২৬: "ok template diye daw")।
    সাদা-কালো, কম কালি, ফটোকপিতে ঝাপসা হয় না; A4-এ ২০–২২ সারি এক পাতায়।
    ⓘ মালিক: উপরের ডানের "বিলের তথ্য" বাক্স তিন-ঘরের সারিতে, "পরিশোধ" ঘরের জায়গায় —
    মোট/পরিশোধিত/বাকি নিচের হিসাবে আছেই, তাই জায়গা বাঁচে।

    চাই: $doc (PrintableDocument — নোটিশ আর দাম দেখানোর চাবি), $bill (তথ্য, [[PurchasePrintController::billFacts()]]),
    $paper, $company, $profile (PrintEngine দেয়)।
--}}
@php
    $bn = app()->getLocale() === 'bn';
    $L = fn (string $k, array $r = []) => __('purchase::bill_paper.'.$k, $r);
    $up = fn (string $s) => $bn ? $s : mb_strtoupper($s);
    $money = $doc->showMoney;
@endphp
<style @nonce>
    body { font-family: hindsiliguri, sans-serif; font-size: 8.6pt; color: #111; }
    table { border-collapse: collapse; }
    .num { text-align: right; white-space: nowrap; }
    td.num, .fig { font-family: dejavusans; }
    .muted { color: #666; }
    .cap { font-size: {{ $bn ? '7.4pt' : '6.6pt' }}; font-weight: bold; letter-spacing: {{ $bn ? '0' : '0.45mm' }}; color: #666; }
    .co { font-size: 15pt; font-weight: bold; color: #000; line-height: 1.15; }
    .branch { font-size: 8.4pt; font-weight: bold; margin-top: 0.6mm; }
    .co-meta { font-size: 7.4pt; color: #555; line-height: 1.45; margin-top: 0.8mm; }
    .title { font-size: {{ $bn ? '25pt' : '23pt' }}; font-weight: bold; text-align: right; color: #000; line-height: 1; letter-spacing: {{ $bn ? '0' : '0.8mm' }}; }
    .title-sub { text-align: right; font-size: 7.6pt; color: #555; margin-top: 1mm; }
    .notice { text-align: center; font-weight: bold; border: 0.5mm solid #000; padding: 2mm; margin-top: 3mm; font-size: 11pt; }
    table.three { width: 100%; margin-top: 4mm; border-top: 1.2mm solid #000; }
    table.three td { vertical-align: top; padding: 2.6mm 4mm 0 0; font-size: 8.3pt; line-height: 1.5; }
    .party { font-weight: bold; font-size: 10pt; }
    table.facts { width: 100%; }
    table.facts td { font-size: 8.2pt; padding: 0.5mm 0; }
    table.facts td.v { text-align: right; font-weight: bold; }
    .status { font-family: hindsiliguri; font-size: 7.2pt; font-weight: bold; border: 0.3mm solid #000; padding: 0.3mm 1.6mm; }
    table.items { width: 100%; margin-top: 3.5mm; }
    table.items th { font-size: {{ $bn ? '7.8pt' : '7pt' }}; font-weight: bold; letter-spacing: {{ $bn ? '0' : '0.25mm' }}; padding: 1.8mm 1.6mm; text-align: left; border-top: 0.9mm solid #000; border-bottom: 0.4mm solid #000; }
    table.items th.num { text-align: right; }
    table.items td { padding: 1.05mm 1.6mm; border-bottom: 0.18mm solid #cfcfcf; font-size: 8.3pt; vertical-align: top; }
    table.items tr.alt td { background: #f4f4f4; }
    .code { font-size: 7pt; color: #666; }
    .lot { font-size: 7.6pt; }
    .freeq { font-weight: bold; }
    table.items tr.tot td { font-weight: bold; border-top: 0.5mm solid #000; border-bottom: 0.5mm solid #000; background: #fff; }
    table.bottom { width: 100%; margin-top: 3mm; }
    table.bottom td.side { vertical-align: top; }
    .words { margin-top: 2.5mm; font-size: 8.3pt; line-height: 1.45; }
    .note { margin-top: 2.5mm; font-size: 8pt; border-left: 0.8mm solid #000; padding: 0.5mm 0 0.5mm 2.5mm; }
    table.sums { width: 100%; }
    table.sums td { padding: 1mm 2mm; font-size: 8.8pt; }
    table.sums tr.grand td { font-size: 12pt; font-weight: bold; padding: 1.6mm 2mm; border-top: 1mm solid #000; border-bottom: 1mm solid #000; }
    table.sums tr.due td { font-weight: bold; font-size: 10pt; }
    table.sign { width: 100%; margin-top: 11mm; }
    table.sign td { width: 23%; text-align: center; font-size: 8pt; vertical-align: bottom; }
    table.sign td.gap { width: 6mm; padding: 0; }
    .sig-name { font-size: 7.6pt; height: 5mm; }
    table.sign td.sl { border-top: 0.3mm solid #000; padding-top: 1.2mm; font-weight: bold; }
    table.foot { width: 100%; margin-top: 4mm; border-top: 0.3mm solid #000; }
    table.foot td { padding-top: 1.4mm; font-size: 6.9pt; color: #666; }
</style>

<table style="width: 100%">
    <tr>
        <td style="vertical-align: top; padding-right: 8mm">
            <div class="co">{{ $company->name() }}</div>
            @if ($bill['branch']['name'] !== '')<div class="branch">{{ $bill['branch']['name'] }}</div>@endif
            <div class="co-meta">
                @if ($bill['branch']['address'] !== ''){{ $bill['branch']['address'] }}<br>@endif
                @if ($bill['branch']['phone'] !== ''){{ $L('phone') }}: {{ $bill['branch']['phone'] }}@endif
                @if (filled($company->bin)) · BIN {{ $company->bin }}@endif
            </div>
        </td>
        <td style="width: 70mm; vertical-align: top">
            <div class="title">{{ $up($L('title')) }}</div>
            <div class="title-sub">{{ $L('subtitle') }}</div>
        </td>
    </tr>
</table>

@foreach ($doc->notices() as $notice)
    <div class="notice" data-notice>{{ $notice }}</div>
@endforeach

<table class="three">
    <tr>
        <td style="width: 38%">
            <div class="cap">{{ $up($L('supplier')) }}</div>
            <div class="party">{{ $bill['supplier']['name'] }}</div>
            @if ($bill['supplier']['address'] !== '')<div>{{ $bill['supplier']['address'] }}</div>@endif
            @if ($bill['supplier']['phone'] !== '')<div>{{ $L('phone') }}: {{ $bill['supplier']['phone'] }}</div>@endif
        </td>
        <td style="width: 27%">
            <div class="cap">{{ $up($L('received_at')) }}</div>
            <div class="party">{{ $bill['warehouse'] }}</div>
            <div>{{ $L('received_on') }}: {{ $bill['received'] }}</div>
            @if ($bill['vehicle'] !== '')<div>{{ $L('vehicle') }}: {{ $bill['vehicle'] }}</div>@endif
        </td>
        <td style="width: 35%; padding-right: 0">
            <div class="cap">{{ $up($L('details')) }}</div>
            <table class="facts">
                <tr><td>{{ $L('bill_no') }}</td><td class="v fig">{{ $bill['no'] }}</td></tr>
                <tr><td>{{ $L('supplier_no') }}</td><td class="v fig" data-supplier-bill-no>{{ $bill['supplier_no'] !== '' ? $bill['supplier_no'] : '—' }}</td></tr>
                <tr><td>{{ $L('bill_date') }}</td><td class="v">{{ $bill['date'] }}</td></tr>
                <tr><td>{{ $L('due_on') }}</td><td class="v">{{ $bill['due'] !== '' ? $bill['due'] : '—' }}</td></tr>
                <tr><td>{{ $L('status') }}</td><td class="v"><span class="status">{{ $bill['status'] }}</span></td></tr>
            </table>
        </td>
    </tr>
</table>

<table class="items">
    <thead>
        <tr>
            <th style="width: 6mm">#</th>
            <th>{{ $up($L('product')) }}</th>
            <th style="width: 26mm">{{ $up($L('lot')) }}</th>
            <th class="num" style="width: 16mm">{{ $up($L('qty')) }}</th>
            <th class="num" style="width: 11mm">{{ $up($L('free')) }}</th>
            <th class="num" style="width: 15mm">{{ $up($L('total_qty')) }}</th>
            @if ($money)
                <th class="num" style="width: 20mm">{{ $up($L('rate')) }}</th>
                <th class="num" style="width: 19mm">{{ $up($L('discount')) }}</th>
                <th class="num" style="width: 25mm">{{ $up($L('amount')) }}</th>
            @endif
        </tr>
    </thead>
    <tbody>
        @foreach ($bill['lines'] as $i => $l)
            <tr class="{{ $i % 2 === 1 ? 'alt' : '' }}">
                <td class="muted fig">{{ $i + 1 }}</td>
                <td><span class="code">{{ $l['code'] }}</span> {{ $l['name'] }}</td>
                <td class="lot">{{ $l['lot'] }}</td>
                <td class="num">{{ $l['qty'] }} <span class="muted" style="font-family: hindsiliguri; font-size: 7pt">{{ $l['unit'] }}</span></td>
                <td class="num {{ $l['free'] !== '' ? 'freeq' : 'muted' }}">{{ $l['free'] !== '' ? $l['free'] : '—' }}</td>
                <td class="num" style="font-weight: bold">{{ $l['total_qty'] }}</td>
                @if ($money)
                    <td class="num">{{ $l['rate'] }}</td>
                    <td class="num">{{ $l['discount'] !== '' ? $l['discount'] : '—' }}</td>
                    <td class="num">{{ $l['amount'] }}</td>
                @endif
            </tr>
        @endforeach
        <tr class="tot">
            <td></td>
            <td>{{ $L('total_row', ['count' => count($bill['lines'])]) }}</td>
            <td></td>
            <td class="num">{{ $bill['qty_total'] }}</td>
            <td class="num">{{ $bill['free_total'] !== '' ? $bill['free_total'] : '—' }}</td>
            <td class="num">{{ $bill['all_total'] }}</td>
            @if ($money)
                <td></td>
                <td class="num">{{ $bill['sums']['line_discount'] }}</td>
                <td class="num">{{ $bill['sums']['lines'] }}</td>
            @endif
        </tr>
    </tbody>
</table>

<table class="bottom">
    <tr>
        <td class="side" style="width: 55%; padding-right: 9mm">
            @if ($money && $bill['words'] !== '')<div class="words"><strong>{{ $L('in_words') }}</strong> {{ $bill['words'] }}</div>@endif
            @if ($bill['note'] !== '')<div class="note">{{ $bill['note'] }}</div>@endif
        </td>
        <td class="side">
            @if ($money)
                <table class="sums">
                    <tr><td>{{ $L('gross') }}</td><td class="num fig">{{ $bill['sums']['gross'] }}</td></tr>
                    <tr><td>{{ $L('item_discount') }}</td><td class="num fig">{{ $bill['sums']['line_discount'] }}</td></tr>
                    @if ($bill['sums']['bill_discount'] !== '')<tr><td>{{ $L('bill_discount') }}</td><td class="num fig">{{ $bill['sums']['bill_discount'] }}</td></tr>@endif
                    @if ($bill['sums']['tax'] !== '')<tr><td>{{ $L('vat') }}</td><td class="num fig">{{ $bill['sums']['tax'] }}</td></tr>@endif
                    @if ($bill['sums']['transport'] !== '')<tr><td>{{ $L('transport') }}</td><td class="num fig">{{ $bill['sums']['transport'] }}</td></tr>@endif
                    <tr class="grand"><td>{{ $L('grand') }}</td><td class="num fig">{{ $bill['sums']['total'] }}</td></tr>
                    <tr><td>{{ $L('paid') }}</td><td class="num fig">{{ $bill['sums']['paid'] }}</td></tr>
                    <tr class="due"><td>{{ $L('due') }}</td><td class="num fig">{{ $bill['sums']['due'] }}</td></tr>
                </table>
            @endif
        </td>
    </tr>
</table>

<table class="sign">
    <tr>
        <td class="sig-name">{{ $bill['prepared_by'] }}</td><td class="gap"></td>
        <td class="sig-name"></td><td class="gap"></td>
        <td class="sig-name"></td><td class="gap"></td>
        <td class="sig-name"></td>
    </tr>
    <tr>
        <td class="sl">{{ $L('prepared_by') }}</td><td class="gap"></td>
        <td class="sl">{{ $L('received_by') }}</td><td class="gap"></td>
        <td class="sl">{{ $L('checked_by') }}</td><td class="gap"></td>
        <td class="sl">{{ $L('approved_by') }}</td>
    </tr>
</table>

<table class="foot">
    <tr>
        <td>{{ $company->name() }}@if ($bill['branch']['name'] !== '') · {{ $bill['branch']['name'] }}@endif</td>
        <td style="text-align: right">{{ $L('printed') }} {{ \App\Core\Support\DateFormat::formatWithTime(now()) }} · {{ $L('page') }} {PAGENO}/{nbpg}</td>
    </tr>
</table>
