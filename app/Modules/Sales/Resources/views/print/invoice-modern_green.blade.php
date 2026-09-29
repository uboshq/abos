{{--
    ১ · আধুনিক সবুজ — মালিকের নির্দেশ, ৩০ সেপ্টেম্বর ২০২৬ (*"eta print control e add koro"*)।

    ⓘ উপরে রঙের দাগ, মাথায় লোগো-নাম আর বড় INVOICE, তথ্যের সারি, দুই কার্ড (কাকে · কোন গাড়িতে),
    গাঢ় মাথার পণ্যের ছক, ডানে টাকার ব্লক (নিট প্রদেয় রঙিন পটিতে, মোট বকেয়া হলুদাভ পটিতে)।
    নমুনা: Design canvas "আধুনিক বিলের নমুনা", ১।

    ⓘ কী আঁকা হবে তা [[InvoicePaperView]] বলে (সব সুইচ [[InvoicePrintLook]]-এর); লেখা আর অঙ্ক `$facts`
    থেকে। mPDF-এর জন্য কেবল table, টাকার ঘর DejaVu। `data-*` চিহ্ন ক্লাসিকের নামেই।
--}}
@php
    $v = new \App\Modules\Sales\Support\InvoicePaperView($doc, $facts, $company, $profile);
    $accent = '#0f5c4d';
    $credit = ($facts['bill']['type'] ?? '') === $v->en('credit');

    $meta = [[$v->label('bill_date'), $facts['bill']['bill_date'] ?? '', null]];
    if (filled($facts['bill']['due_date'] ?? '')) {
        $meta[] = [__('sales::invoice_design.due_date', [], 'en'), $facts['bill']['due_date'], null];
    }
    if ($v->shows('order_no')) {
        $meta[] = [$v->label('order_no'), $facts['bill']['order_no'] ?? '', 'data-order-no'];
    }
    if (filled($facts['bill']['sales_officer'] ?? '')) {
        $meta[] = [__('sales::invoice_design.sales_officer', [], 'en'), $facts['bill']['sales_officer'], null];
    }
    $meta[] = [$v->label('created_by'), $facts['bill']['created_by'] ?? '', null];
    $metaWidth = round(100 / count($meta), 2);
@endphp

<style @nonce>
    body { font-family: hindsiliguri, sans-serif; font-size: 9pt; color: #1c1f1e; }
    table { border-collapse: collapse; }
    .rule { height: 1.6mm; background: {{ $accent }}; }
    table.head { width: 100%; margin-top: 5mm; }
    table.head td { vertical-align: middle; }
    .mark { width: 14mm; height: 14mm; background: {{ $accent }}; color: #fff; text-align: center; font-weight: bold; font-size: 13pt; }
    .co-name { font-size: 16pt; font-weight: bold; }
    .co-meta { font-size: 8pt; color: #5b625f; }
    .title { text-align: right; font-size: 22pt; font-weight: bold; letter-spacing: 1.5mm; color: {{ $accent }}; }
    .no { text-align: right; font-size: 11pt; font-weight: bold; }
    .pill { font-size: 7.5pt; font-weight: bold; padding: 0.6mm 2.4mm; background: #fdf0e1; color: #8a4a0c; }
    .pill-cash { background: #e7f1ee; color: {{ $accent }}; }
    .dup { text-align: right; font-size: 7.5pt; font-weight: bold; color: #5b625f; }
    .notice { text-align: center; font-weight: bold; border: 0.4mm solid #b42318; color: #b42318; padding: 2mm; margin-top: 3mm; font-size: 11pt; }
    table.meta { width: 100%; margin-top: 5mm; border: 0.25mm solid #dfe3e1; }
    table.meta td { padding: 2mm 3mm; border-right: 0.25mm solid #dfe3e1; vertical-align: top; font-size: 9pt; }
    .cap { font-size: 6.8pt; font-weight: bold; color: #5b625f; }
    .cap-accent { color: {{ $accent }}; }
    table.cards { width: 100%; margin-top: 4mm; }
    table.cards td.card { width: 49%; background: #f5f7f6; padding: 3mm 4mm; vertical-align: top; font-size: 8.5pt; color: #3a403e; }
    .card-name { font-size: 10.5pt; font-weight: bold; color: #1c1f1e; }
    table.items { width: 100%; margin-top: 5mm; }
    table.items th { background: #1c1f1e; color: #fff; font-size: 7.5pt; font-weight: bold; padding: 2.2mm 2mm; text-align: left; }
    table.items th.num { text-align: right; }
    table.items td { padding: 2mm; border-bottom: 0.25mm solid #e6e9e8; font-size: 9pt; vertical-align: top; }
    table.items tr.alt td { background: #fafbfa; }
    .sub { font-size: 7.5pt; color: #5b625f; font-weight: normal; }
    .free { color: {{ $accent }}; font-weight: bold; }
    table.items tr.grand td { background: #eef4f2; font-weight: bold; border-bottom: 0; }
    .num { text-align: right; white-space: nowrap; }
    td.num { font-family: dejavusans; }
    .words { margin-top: 3mm; font-size: 8.5pt; color: #3a403e; }
    table.bottom { width: 100%; margin-top: 4mm; }
    table.bottom td.side { vertical-align: top; }
    table.pay { width: 100%; margin-top: 1.5mm; border: 0.25mm solid #dfe3e1; }
    table.pay th { background: #f5f7f6; font-size: 7pt; color: #5b625f; padding: 1.6mm 2mm; text-align: left; }
    table.pay td { font-size: 8pt; padding: 1.8mm 2mm; border-top: 0.25mm solid #e6e9e8; }
    table.sums { width: 100%; border: 0.25mm solid #dfe3e1; }
    table.sums td { padding: 1.5mm 3.5mm; font-size: 9pt; }
    table.sums tr.net td { background: {{ $accent }}; color: #fff; padding: 2.6mm 3.5mm; font-weight: bold; }
    table.sums tr.owed td { background: #fdf0e1; color: #8a4a0c; padding: 2.6mm 3.5mm; font-weight: bold; }
    .footnote { margin-top: 3mm; font-size: 9pt; font-weight: bold; color: #b42318; }
    table.signatures { width: 100%; margin-top: 14mm; }
    table.signatures td { text-align: center; padding: 0 5mm; vertical-align: top; font-size: 9pt; }
    .sig-line { border-top: 0.25mm solid #1c1f1e; padding-top: 1mm; font-weight: bold; }
    table.foot { width: 100%; margin-top: 5mm; border-top: 0.25mm solid #e6e9e8; }
    table.foot td { font-size: 7pt; color: #6b7270; padding-top: 2mm; }
</style>

<div class="rule"></div>

<table class="head">
    <tr>
        <td style="width: 62%">
            <table>
                <tr>
                    <td style="padding-right: 3.5mm">
                        @if ($v->logo)
                            <img src="{{ $v->logo }}" style="height: 14mm;" alt="">
                        @else
                            <div class="mark">{{ mb_strtoupper(mb_substr($v->head['name'], 0, 2)) }}</div>
                        @endif
                    </td>
                    <td>
                        <div class="co-name">{{ $v->head['name'] }}</div>
                        @if ($v->head['address'] !== '')<div class="co-meta" data-head-address>{{ $v->head['address'] }}</div>@endif
                        <div class="co-meta">{{ implode(' · ', array_filter([$v->head['phone'], $v->head['email'], $v->head['website']])) }}</div>
                        @if ($v->taxIds !== '')<div class="co-meta" data-tax-ids>{{ $v->taxIds }}</div>@endif
                    </td>
                </tr>
            </table>
        </td>
        <td>
            <div class="title">{{ $v->en('heading') }}</div>
            <div class="no">
                @if ($v->shows('invoice_type') && filled($facts['bill']['type'] ?? ''))
                    <span data-invoice-type @class(['pill', 'pill-cash' => ! $credit])>{{ $facts['bill']['type'] }}</span>&nbsp;
                @endif
                {{ $facts['bill']['bill_no'] }}
            </div>
            @if ($v->duplicate)<div class="dup" data-duplicate>{{ $v->en('duplicate') }}</div>@endif
        </td>
    </tr>
</table>

@if ($v->notices !== [])<div class="notice">{{ implode(' · ', $v->notices) }}</div>@endif

<table class="meta">
    <tr>
        @foreach ($meta as [$label, $value, $marker])
            <td style="width: {{ $metaWidth }}%" @if ($marker) {{ $marker }} @endif>
                <div class="cap">{{ mb_strtoupper($label) }}</div>
                <div>{{ $value }}</div>
            </td>
        @endforeach
    </tr>
</table>

<table class="cards">
    <tr>
        <td class="card">
            <div class="cap cap-accent">{{ mb_strtoupper($v->label('bill_to')) }}</div>
            <div class="card-name">{{ $v->en('ms') }} {{ $facts['bill_to']['name'] }}
                @if (filled($facts['bill_to']['code'] ?? ''))<span class="sub">· {{ $facts['bill_to']['code'] }}</span>@endif
            </div>
            @if (filled($facts['bill_to']['point']))<div>{{ $v->en('point') }} {{ $facts['bill_to']['point'] }}</div>@endif
            @if (filled($facts['bill_to']['address']))<div>{{ $facts['bill_to']['address'] }}</div>@endif
            @if (filled($facts['bill_to']['phone']))<div>{{ $v->en('phone') }} {{ $facts['bill_to']['phone'] }}</div>@endif
        </td>
        <td style="width: 2%"></td>
        <td class="card">
            @if ($v->shows('transport'))
                <div data-transport>
                    <div class="cap cap-accent">{{ mb_strtoupper($v->label('transport')) }}</div>
                    <div class="card-name">{{ filled($facts['transport']['carrier']) ? $facts['transport']['carrier'] : '—' }}</div>
                    @if (filled($facts['transport']['vehicle']))<div>{{ $v->en('vehicle') }} {{ $facts['transport']['vehicle'] }}</div>@endif
                    @if (filled($facts['transport']['driver_phone']))<div>{{ $v->en('driver_phone') }} {{ $facts['transport']['driver_phone'] }}</div>@endif
                    @if (filled($facts['transport']['delivery_date']))<div>{{ $v->en('delivery_date') }} {{ $facts['transport']['delivery_date'] }}</div>@endif
                </div>
            @endif
        </td>
    </tr>
</table>

<table class="items">
    <thead>
        <tr>
            <th style="width: 8mm">{{ $v->label('sl') }}</th>
            <th>{{ mb_strtoupper($v->label('product')) }}</th>
            <th class="num" style="width: 24mm">{{ mb_strtoupper($v->label('rate')) }}</th>
            <th class="num" style="width: 18mm">{{ mb_strtoupper($v->label('qty')) }}</th>
            @if ($v->free)<th class="num" style="width: 15mm" data-col-free>{{ mb_strtoupper($v->label('free')) }}</th>@endif
            @if ($v->totalQty)<th class="num" style="width: 20mm" data-col-total-qty>{{ mb_strtoupper($v->label('total_qty')) }}</th>@endif
            <th class="num" style="width: 29mm">{{ mb_strtoupper($v->label('amount')) }}</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($facts['items']['rows'] as $index => $item)
            <tr @class(['alt' => $index % 2 === 1])>
                <td>{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</td>
                <td style="font-weight: bold">
                    {{ $item['name'] }}
                    @php($under = implode(' · ', array_filter([$item['code'] ?? '', $item['lot'] ?? ''])))
                    @if ($under !== '')<div class="sub">{{ $under }}</div>@endif
                </td>
                <td class="num">{{ $paper->money($item['rate']) }}</td>
                <td class="num">{{ $item['qty'] }}</td>
                @if ($v->free)<td @class(['num', 'free' => filled($item['free'])])>{{ filled($item['free']) ? $item['free'] : '—' }}</td>@endif
                @if ($v->totalQty)<td class="num">{{ $item['total_qty'] }}</td>@endif
                <td class="num"><strong>{{ $paper->money($item['amount']) }}</strong></td>
            </tr>
        @endforeach
        @if ($v->shows('grand_total_row'))
            <tr class="grand" data-grand-row>
                <td></td>
                <td>{{ $v->en('grand_total') }} <span class="sub">· {{ $v->en('total_items') }} {{ $facts['total_items'] }}</span></td>
                <td></td>
                <td class="num">{{ $facts['items']['totals']['qty'] }}</td>
                @if ($v->free)<td class="num">{{ $facts['items']['totals']['free'] }}</td>@endif
                @if ($v->totalQty)<td class="num">{{ $facts['items']['totals']['total_qty'] }}</td>@endif
                <td class="num">{{ $paper->money($facts['items']['totals']['amount']) }}</td>
            </tr>
        @endif
    </tbody>
</table>

@if ($v->shows('amount_words'))<div class="words" data-words><strong>{{ $v->en('in_words') }}</strong> {{ $facts['words'] }}</div>@endif

<table class="bottom">
    <tr>
        <td class="side" style="width: 58%; padding-right: 5mm">
            @if ($v->shows('deposits'))
                <div class="cap cap-accent" data-deposits>{{ mb_strtoupper($v->en('payments_title')) }}</div>
                <table class="pay">
                    <tr><th>{{ $v->en('txn_id') }}</th><th>{{ $v->en('txn_date') }}</th><th>{{ $v->en('method') }}</th><th class="num">{{ $v->en('amount') }}</th></tr>
                    @foreach ($doc->payments as $row)
                        <tr><td>{{ $row['ref'] }}</td><td>{{ $row['date'] }}</td><td data-method>{{ $row['method'] }}</td><td class="num">{{ $row['amount'] }}</td></tr>
                    @endforeach
                </table>
            @endif
            <div style="margin-top: 4mm">@include('sales::print.partials.invoice-qr', ['v' => $v])</div>
        </td>
        <td class="side">
            <table class="sums">
                <tr><td>{{ $v->en('grand_total') }}</td><td class="num">{{ $paper->money($v->sums['grand_total']) }}</td></tr>
                <tr><td>{{ $v->en('discount') }}</td><td class="num">{{ $paper->money($v->sums['discount']) }}</td></tr>
                @if ($v->showVat)<tr><td>{{ $v->en('vat') }}</td><td class="num">{{ $paper->money($v->sums['vat']) }}</td></tr>@endif
                <tr><td>{{ $v->en('rounding') }}</td><td class="num">{{ $paper->money($v->sums['rounding']) }}</td></tr>
                <tr class="net"><td>{{ mb_strtoupper($v->en('net_payable')) }}</td><td class="num">{{ $paper->money($v->sums['net_payable']) }}</td></tr>
                <tr><td>{{ $v->en('paid') }}</td><td class="num">{{ $paper->money($v->sums['paid']) }}</td></tr>
                <tr><td>{{ $v->en('invoice_due') }}</td><td class="num">{{ $paper->money($v->sums['invoice_due']) }}</td></tr>
                @if ($v->shows('previous_due'))
                    <tr data-previous-due><td>{{ $v->en('previous_due') }}</td><td class="num">{{ $paper->money($v->sums['previous_due']) }}</td></tr>
                    <tr class="owed"><td>{{ mb_strtoupper($v->en('total_due')) }}</td><td class="num">{{ $paper->money($v->sums['outstanding']) }}</td></tr>
                @endif
            </table>
        </td>
    </tr>
</table>

<div class="footnote">{{ $v->footnote }}</div>

<table class="signatures">
    <tr>
        @foreach ($v->signatures as $label)
            <td style="width: {{ round(100 / max(1, count($v->signatures)), 1) }}%"><table style="width: 100%"><tr><td class="sig-line" style="text-align: center" data-signature>{{ $label }}</td></tr></table></td>
        @endforeach
    </tr>
</table>

<table class="foot"><tr><td>{{ $v->printedAt() }}</td><td style="text-align: right">{{ __('sales::invoice_design.thanks', [], 'en') }}</td></tr></table>
