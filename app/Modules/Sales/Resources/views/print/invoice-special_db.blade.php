{{--
    Special for DB — বিল + হিসাবের বিবরণী, বাঁয়ে সাদা ফিতা। মালিক, ৩ অক্টোবর ২০২৬ (নকশার ক্যানভাস "২গ", নাম মালিকের)।

    ⓘ ফিতায় উপর থেকে নিচে: INVOICE / With Accounts Statement, বিল নম্বর · তারিখ, ক্রেতা, ড্রাইভার, টার্গেট রিমাইন্ডার,
    বড় QR, আর তলায় INVOICE SUMMARY — শেষ লাইন ডিলারের ভাষায় Due / Advance / No Due, আর বেশি দিলে "Extra Paid"
    ([[InvoicePaperView::balanceWord()]], [[billLeftWord()]])। ডানে মাথায় কোম্পানি আর লোগো, তারপর পণ্য (Grand Total-এর
    টাকার ঘর ফ্রেমে), তারপর বিলের মাসের হিসাবের চলাচল ([[InvoicePaperView::movement()]], জের "… Due / … Advance")।
    ⛔ কোনো ভরাট রং নেই — মালিক: "kono solid color hobena ei template"; আলাদা করে কেবল নীল দাগ, ফ্রেম আর মোটা অক্ষর।
    ⓘ সুইচ আর `data-*` চিহ্ন ভাগের partial-এ; ফিতার ড্রাইভার বাক্স (`data-transport`) আর সারাংশের আগের বকেয়া
    (`data-previous-due`) এখানে, একই সুইচে। লক্ষ্য না থাকলে বাক্সটাই নেই ([[InvoicePaperView::target()]])।
--}}
@php
    $v = new \App\Modules\Sales\Support\InvoicePaperView($doc, $facts, $company, $profile);
    $accent = '#123b5e';
    $t = fn (string $key) => __('sales::invoice_design.'.$key, [], 'en');
    $s = $v->sums;
    $target = $v->target();
    $transport = $facts['transport'] ?? [];
@endphp
<style @nonce>
    body { font-family: hindsiliguri, sans-serif; font-size: 8.5pt; color: #15202b; }
    table { border-collapse: collapse; }
    table.page { width: 100%; }
    td.side { width: 52mm; vertical-align: top; padding: 0 4mm 0 0; border-right: 0.8mm solid {{ $accent }}; }
    td.main { vertical-align: top; padding: 0 0 0 5mm; }
    .title { font-size: 24pt; font-weight: bold; color: {{ $accent }}; letter-spacing: 0.6mm; line-height: 1; }
    .subtitle { font-size: 9pt; font-weight: bold; color: {{ $accent }}; }
    .no { font-size: 11pt; font-weight: bold; font-family: dejavusans; margin-top: 2mm; }
    .muted { color: #4a5560; }
    .cap { font-size: 7.5pt; font-weight: bold; letter-spacing: 0.4mm; color: {{ $accent }}; margin-top: 3.5mm; }
    .box { border: 0.25mm solid #9db4ca; padding: 1.5mm 2mm; margin-top: 1mm; }
    table.kv { width: 100%; }
    table.kv td { padding: 0.4mm 0; font-size: 8pt; }
    table.kv td.num { text-align: right; font-family: dejavusans; white-space: nowrap; }
    table.kv tr.strong td { font-weight: bold; }
    table.kv tr.rule td { border-top: 0.25mm solid #9db4ca; padding-top: 0.8mm; }
    table.kv tr.final td { font-weight: bold; font-size: 10pt; color: {{ $accent }}; border-top: 0.6mm solid {{ $accent }}; border-bottom: 1.2mm double {{ $accent }}; padding: 1mm 0; }
    .qr { text-align: center; margin-top: 4mm; }
    table.head { width: 100%; border-bottom: 0.6mm solid {{ $accent }}; }
    table.head td { vertical-align: middle; padding-bottom: 2mm; }
    .co-name { font-size: 14pt; font-weight: bold; color: {{ $accent }}; }
    .co-meta { font-size: 7.5pt; color: #4a5560; }
    .dup { font-size: 7.5pt; font-weight: bold; color: #4a5560; }
    .notice { text-align: center; font-weight: bold; border: 0.4mm solid #b42318; color: #b42318; padding: 1mm; margin-top: 2mm; font-size: 10pt; }
    table.items { width: 100%; margin-top: 2.5mm; }
    table.items th { font-size: 7.5pt; font-weight: bold; color: {{ $accent }}; padding: 0.8mm 1.2mm; text-align: left; border-bottom: 0.5mm solid {{ $accent }}; }
    table.items th.num { text-align: right; }
    table.items td { padding: 0.7mm 1.2mm; border-bottom: 0.2mm solid #dde5ec; font-size: 8.5pt; line-height: 1.2; vertical-align: top; }
    table.items tr.grand td { font-weight: bold; border-top: 0.5mm solid {{ $accent }}; border-bottom: 0.5mm solid {{ $accent }}; }
    table.items tr.grand td.grand-amount { color: {{ $accent }}; font-size: 9.5pt; border: 0.5mm solid {{ $accent }}; border-bottom: 1.2mm double {{ $accent }}; }
    .sub { font-size: 7pt; color: #4a5560; }
    .free { font-weight: bold; }
    .num { text-align: right; white-space: nowrap; }
    td.num { font-family: dejavusans; }
    .words { margin-top: 1.5mm; font-size: 8pt; color: #4a5560; }
    .section { margin-top: 3.5mm; font-size: 7.5pt; font-weight: bold; letter-spacing: 0.4mm; color: {{ $accent }}; }
    table.ledger { width: 100%; margin-top: 1mm; }
    table.ledger th { font-size: 7.5pt; color: #4a5560; font-weight: bold; text-align: left; padding: 0.6mm 1.2mm; border-bottom: 0.4mm solid {{ $accent }}; }
    table.ledger th.num { text-align: right; }
    table.ledger td { padding: 0.6mm 1.2mm; border-bottom: 0.2mm solid #dde5ec; font-size: 8pt; }
    table.ledger tr.close td { font-weight: bold; border-bottom: 0.5mm solid {{ $accent }}; }
    table.pay { width: 100%; margin-top: 1mm; }
    table.pay th { font-size: 7pt; font-weight: bold; color: #4a5560; text-align: left; padding: 0.6mm; border-bottom: 0.3mm solid #15202b; }
    table.pay td { font-size: 8pt; padding: 0.6mm; border-bottom: 0.2mm solid #dde5ec; }
    .pay-head { font-size: 7.5pt; font-weight: bold; color: {{ $accent }}; margin-top: 2mm; }
    .footnote { margin-top: 2mm; font-size: 9pt; font-weight: bold; color: #b42318; }
    table.signatures { width: 100%; margin-top: 10mm; }
    table.signatures td { text-align: center; padding: 0 4mm; font-size: 8.5pt; }
    .sig-line { border-top: 0.25mm solid #15202b; padding-top: 1mm; }
    .printed { margin-top: 2mm; font-size: 7pt; color: #4a5560; }
</style>

<table class="page">
    <tr>
        <td class="side">
            <div class="title">INVOICE</div>
            <div class="subtitle">With Accounts Statement</div>
            <div class="no">{{ $facts['bill']['bill_no'] }}</div>
            <div>{{ $facts['bill']['bill_date'] }}</div>
            @if ($v->shows('order_no'))<div class="muted" data-order-no>{{ $v->en('order_no') }} {{ $facts['bill']['order_no'] }}</div>@endif
            @if ($v->shows('invoice_type'))<div class="muted" data-invoice-type>{{ $v->en('type') }} {{ $facts['bill']['type'] }}</div>@endif
            @if ($v->duplicate)<div class="dup" data-duplicate>{{ $v->en('duplicate') }}</div>@endif

            <div class="cap">BILL TO</div>
            @include('sales::print.partials.invoice-bill-to', ['v' => $v, 'facts' => $facts])

            @if ($v->shows('transport'))
                <div class="cap">DRIVER</div>
                <div class="box" data-transport>
                    <div>{{ ($transport['driver_name'] ?? '') !== '' ? $transport['driver_name'] : ($transport['carrier'] ?? '') }}</div>
                    @if (($transport['driver_phone'] ?? '') !== '')<div>{{ $transport['driver_phone'] }}</div>@endif
                    @if (($transport['vehicle'] ?? '') !== '')<div class="muted">{{ $transport['vehicle'] }}</div>@endif
                    @if (($transport['delivery_date'] ?? '') !== '')<div class="muted">{{ $v->en('delivery_date') }} {{ $transport['delivery_date'] }}</div>@endif
                </div>
            @endif

            @if ($target !== null)
                <div class="cap">TARGET REMINDER</div>
                <table class="kv" data-target>
                    <tr><td>{{ $target['month'] }} target</td><td class="num">{{ $target['target'] }}</td></tr>
                    <tr><td>Inflow achieved</td><td class="num">{{ $target['achieved'] }}</td></tr>
                    <tr class="strong"><td>Remaining</td><td class="num">{{ $target['remaining'] }}</td></tr>
                    <tr><td class="muted">Closes {{ $target['closes_on'] }}</td><td class="num muted">{{ $target['bank_days'] }} bank days</td></tr>
                </table>
            @endif

            <div class="qr">@include('sales::print.partials.invoice-qr', ['v' => $v, 'width' => '34mm'])</div>

            <div class="cap">INVOICE SUMMARY</div>
            <div class="box">
                <table class="kv" data-invoice-summary>
                    <tr><td>{{ $v->label('grand_total') }}</td><td class="num">{{ $paper->money($s['grand_total']) }}</td></tr>
                    <tr><td>{{ $v->label('discount') }}</td><td class="num">{{ $paper->money($s['discount']) }}</td></tr>
                    @if ($v->showVat)<tr><td>{{ $v->label('vat') }}</td><td class="num">{{ $paper->money($s['vat']) }}</td></tr>@endif
                    <tr><td>{{ $v->label('rounding') }}</td><td class="num">{{ $paper->money($s['rounding']) }}</td></tr>
                    <tr class="strong rule"><td>{{ $v->label('net_payable') }}</td><td class="num">{{ $paper->money($s['net_payable']) }}</td></tr>
                    <tr><td>{{ $v->label('paid') }}</td><td class="num">{{ $paper->money($s['paid']) }}</td></tr>
                    <tr class="strong"><td data-bill-left>{{ $v->billLeftWord() }}</td><td class="num">{{ $v->billLeftAmount() }}</td></tr>
                    @if ($v->shows('previous_due'))
                        <tr data-previous-due><td>(+) {{ $v->label('previous_due') }}</td><td class="num">{{ $paper->money($s['previous_due']) }}</td></tr>
                        <tr class="final"><td data-balance-word>{{ $v->balanceWord() }}</td><td class="num">{{ $v->balanceAmount() }}</td></tr>
                    @endif
                </table>
            </div>
        </td>

        <td class="main">
            <table class="head">
                <tr>
                    <td>
                        <div class="co-name">{{ $v->head['name'] }}</div>
                        @include('sales::print.partials.invoice-company', ['v' => $v])
                    </td>
                    <td style="width: 26mm; text-align: right">@if ($v->logo)<img src="{{ $v->logo }}" style="max-height: 20mm; max-width: 26mm;" alt="">@endif</td>
                </tr>
            </table>

            @if ($v->notices !== [])<div class="notice">{{ implode(' · ', $v->notices) }}</div>@endif

            @include('sales::print.partials.invoice-items', ['v' => $v, 'facts' => $facts, 'paper' => $paper, 'upper' => false, 'inlineLot' => true])

            @if ($v->shows('amount_words'))<div class="words" data-words><strong>{{ $v->en('in_words') }}</strong> {{ $facts['words'] }}</div>@endif

            @if ($v->shows('previous_due'))
                <div class="section">{{ mb_strtoupper($t('movement')) }}</div>
                <table class="ledger" data-movement>
                    <tr>
                        <th style="width: 18mm">{{ $v->label('txn_date') }}</th>
                        <th>{{ $t('particulars') }}</th>
                        <th class="num" style="width: 24mm">{{ $t('debit') }}</th>
                        <th class="num" style="width: 24mm">{{ $t('credit') }}</th>
                        <th class="num" style="width: 32mm">{{ $t('balance') }}</th>
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
            <div class="printed">{{ $v->printedAt() }} · Special for DB</div>
        </td>
    </tr>
</table>
