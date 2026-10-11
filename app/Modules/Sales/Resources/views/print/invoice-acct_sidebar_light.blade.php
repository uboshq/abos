{{--
    Sidebar Light — বিল + হিসাবের বিবরণী, বাঁয়ে হালকা নীলচে ফিতা, ডান পাশে গাঢ় নীল দাগ। মালিক, ৩ অক্টোবর ২০২৬
    (নকশার ক্যানভাস "২খ · পাশের ফিতা, হালকা")।

    ⓘ গড়ন "Sidebar"-এর (`invoice-acct_sidebar`) মতোই — ফিতায় কোম্পানি আর লোগো, ক্রেতা, ড্রাইভারের বাক্স, হিসাবের
    সারাংশ (শেষ লাইন Due / Advance / No Due — [[InvoicePaperView::balanceWord()]]), টার্গেট রিমাইন্ডার, তলায় টাকার যোগফল
    (শেষে Invoice Due / Extra Paid — [[billLeftWord()]]); ডানে মাথা, বিল নম্বর আর QR, পণ্য, "Net payable · কথায়", বিলের
    মাসের হিসাবের চলাচল ([[InvoicePaperView::movement()]]), সই। তফাত কালিতে: ফিতা ভরাট গাঢ় নয়, প্রায় সাদা; লেখা কালো,
    শিরোনাম নীল; ড্রাইভারের বাক্স সাদা, পাতলা নীল ফ্রেমে — কম কালি, সাধারণ প্রিন্টারেও পরিষ্কার।
    ⓘ সুইচ আর `data-*` চিহ্ন "Special for DB"-র মতোই, একই শর্তে; লক্ষ্য না থাকলে বাক্সটাই নেই ([[InvoicePaperView::target()]])।
    ⓘ লট আলাদা কলাম নয় — নামের পাশে, এক লাইনে (`inlineLot`)।
    ⚠️ mPDF ছকের ঘরের ভেতরের div-এর রং/দাগ ঠিক আঁকে না — তাই বাক্সগুলো ছোট ছকের ঘর (`td.boxcell`)।
--}}
@php
    $v = new \App\Modules\Sales\Support\InvoicePaperView($doc, $facts, $company, $profile);
    $accent = '#123b5e';
    $band = '#f4f8fc';
    $bandInk = '#15202b';
    $bandCap = '#123b5e';
    $bandMuted = '#4a5560';
    $bandFrame = '#b9cde0';
    $t = fn (string $key) => __('sales::invoice_design.'.$key, [], 'en');
    $s = $v->sums;
    $target = $v->target();
    $transport = $facts['transport'] ?? [];
    $officer = (string) ($facts['bill']['sales_officer'] ?? '');
@endphp
<style @nonce>
    body { font-family: hindsiliguri, sans-serif; font-size: 8.5pt; color: #15202b; }
    table { border-collapse: collapse; }
    table.page { width: 100%; }
    td.side { width: 55mm; vertical-align: top; padding: 5mm 4.5mm; background: {{ $band }}; color: {{ $bandInk }}; border-right: 0.8mm solid {{ $accent }}; }
    td.main { vertical-align: top; padding: 0 0 0 6mm; }
    .co-name { font-size: 13.5pt; font-weight: bold; color: {{ $accent }}; line-height: 1.15; }
    .co-meta { font-size: 7.5pt; color: {{ $bandMuted }}; }
    .scap { font-size: 7.5pt; font-weight: bold; letter-spacing: 0.3mm; color: {{ $bandCap }}; margin-top: 4mm; }
    .party { font-size: 10pt; font-weight: bold; color: {{ $bandInk }}; }
    td.side .sub { color: {{ $bandMuted }}; }
    .side-muted { color: {{ $bandMuted }}; }
    table.boxed { width: 100%; margin-top: 4mm; }
    td.boxcell { background: #ffffff; border: 0.25mm solid {{ $bandFrame }}; padding: 2mm 2.5mm; color: {{ $bandInk }}; }
    table.kv { width: 100%; margin-top: 0.8mm; }
    table.kv td { padding: 0.4mm 0; font-size: 8pt; color: {{ $bandInk }}; }
    table.kv td.num { text-align: right; font-family: dejavusans; white-space: nowrap; }
    table.kv tr.strong td { font-weight: bold; }
    table.kv tr.final td { font-weight: bold; font-size: 9.5pt; border-top: 0.25mm solid {{ $bandCap }}; padding-top: 0.8mm; }
    table.kv tr.big td { font-weight: bold; font-size: 10pt; padding-top: 0.8mm; }
    table.kv td.dim { color: {{ $bandMuted }}; }
    table.totals { width: 100%; margin-top: 6mm; border-top: 0.25mm solid {{ $bandCap }}; }
    table.totals td { padding: 0.4mm 0; font-size: 8pt; color: {{ $bandInk }}; }
    table.totals tr.first td { padding-top: 2.5mm; }
    table.totals td.num { text-align: right; font-family: dejavusans; white-space: nowrap; }
    table.totals tr.net td { font-weight: bold; font-size: 10pt; padding-top: 0.8mm; }
    table.totals tr.strong td { font-weight: bold; }
    table.head { width: 100%; border-bottom: 0.6mm solid {{ $accent }}; }
    table.head td { vertical-align: bottom; padding-bottom: 2mm; }
    .title { font-size: 12pt; font-weight: bold; color: {{ $accent }}; white-space: nowrap; }
    .no { font-size: 12pt; font-weight: bold; font-family: dejavusans; text-align: right; white-space: nowrap; }
    .muted { color: #4a5560; }
    .dup { font-size: 7.5pt; font-weight: bold; color: #4a5560; }
    .notice { text-align: center; font-weight: bold; border: 0.4mm solid #b42318; color: #b42318; padding: 1mm; margin-top: 2mm; font-size: 10pt; }
    table.items { width: 100%; margin-top: 2.5mm; }
    table.items th { font-size: 7.5pt; font-weight: bold; color: {{ $accent }}; padding: 0.8mm 1.1mm; text-align: left; border-bottom: 0.5mm solid {{ $accent }}; }
    table.items th.num { text-align: right; }
    table.items td { padding: 0.6mm 1.1mm; font-size: 8.5pt; line-height: 1.2; vertical-align: top; }
    table.items tr.alt td { background: #f2f6fa; }
    table.items tr.grand td { font-weight: bold; border-top: 0.3mm solid {{ $accent }}; }
    .sub { font-size: 7pt; color: #4a5560; }
    .free { font-weight: bold; }
    .num { text-align: right; white-space: nowrap; }
    td.num { font-family: dejavusans; }
    table.netline { width: 100%; border-top: 0.5mm solid {{ $accent }}; }
    table.netline td { padding: 0.8mm 1.1mm; font-weight: bold; font-size: 8.5pt; vertical-align: top; }
    table.netline td.words { font-weight: normal; }
    .section { margin-top: 3.5mm; font-size: 7.5pt; font-weight: bold; letter-spacing: 0.3mm; color: {{ $accent }}; }
    table.ledger { width: 100%; margin-top: 1mm; }
    table.ledger th { font-size: 7.5pt; color: #4a5560; font-weight: bold; text-align: left; padding: 0.5mm 1.1mm; border-bottom: 0.3mm solid {{ $accent }}; }
    table.ledger th.num { text-align: right; }
    table.ledger td { padding: 0.5mm 1.1mm; border-bottom: 0.2mm solid #e1e7ec; font-size: 8pt; }
    table.ledger tr.close td { font-weight: bold; }
    table.pay { width: 100%; margin-top: 1mm; }
    table.pay th { font-size: 7pt; font-weight: bold; color: #4a5560; text-align: left; padding: 0.6mm; border-bottom: 0.3mm solid #15202b; }
    table.pay td { font-size: 8pt; padding: 0.6mm; border-bottom: 0.2mm solid #e1e7ec; }
    .pay-head { font-size: 7.5pt; font-weight: bold; color: {{ $accent }}; margin-top: 2mm; }
    .footnote { margin-top: 2mm; font-size: 9pt; font-weight: bold; color: #b42318; }
    table.signatures { width: 100%; margin-top: 10mm; }
    table.signatures td { text-align: center; padding: 0 3mm; font-size: 8.5pt; }
    .sig-line { border-top: 0.25mm solid #15202b; padding-top: 1mm; }
    table.foot { width: 100%; margin-top: 2mm; }
    table.foot td { font-size: 7pt; color: #4a5560; }
</style>

<table class="page">
    <tr>
        <td class="side">
            @if ($v->logo)<div style="margin-bottom: 2mm"><img src="{{ $v->logo }}" style="max-height: 16mm; max-width: 30mm;" alt=""></div>@endif
            <div class="co-name">{{ $v->head['name'] }}</div>
            @include('sales::print.partials.invoice-company', ['v' => $v])

            @include('sales::print.partials.invoice-bill-to', ['v' => $v, 'facts' => $facts, 'cap' => 'scap'])
            @if ($officer !== '')<div class="side-muted">{{ $t('sales_officer') }} {{ $officer }}</div>@endif

            @if ($v->shows('transport'))
                <table class="boxed" data-transport>
                    <tr><td class="boxcell">
                        <div class="scap" style="margin-top: 0">DRIVER</div>
                        <div>{{ ($transport['driver_name'] ?? '') !== '' ? $transport['driver_name'] : ($transport['carrier'] ?? '') }}</div>
                        @if (($transport['driver_phone'] ?? '') !== '')<div>{{ $transport['driver_phone'] }}</div>@endif
                        @if (($transport['vehicle'] ?? '') !== '')<div>{{ $transport['vehicle'] }}</div>@endif
                        @if (($transport['delivery_date'] ?? '') !== '')<div class="side-muted">{{ $v->en('delivery_date') }} {{ $transport['delivery_date'] }}</div>@endif
                    </td></tr>
                </table>
            @endif

            <div class="scap">{{ mb_strtoupper($t('account_summary')) }}</div>
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

            @if ($target !== null)
                <div class="scap">TARGET REMINDER</div>
                <table class="kv" data-target>
                    <tr><td>{{ $target['month'] }} target</td><td class="num">{{ $target['target'] }}</td></tr>
                    <tr><td>Inflow achieved</td><td class="num">{{ $target['achieved'] }}</td></tr>
                    <tr class="strong"><td>Remaining</td><td class="num">{{ $target['remaining'] }}</td></tr>
                    <tr><td class="dim">Closes {{ $target['closes_on'] }}</td><td class="num dim">{{ $target['bank_days'] }} bank days</td></tr>
                </table>
            @endif

            <table class="totals" data-invoice-summary>
                <tr class="first"><td>{{ $v->label('grand_total') }}</td><td class="num">{{ $paper->money($s['grand_total']) }}</td></tr>
                <tr><td>{{ $v->label('discount') }}</td><td class="num">− {{ $paper->money($s['discount']) }}</td></tr>
                @if ($v->showVat)<tr><td>{{ $v->label('vat') }}</td><td class="num">{{ $paper->money($s['vat']) }}</td></tr>@endif
                <tr><td>{{ $v->label('rounding') }}</td><td class="num">{{ $paper->money($s['rounding']) }}</td></tr>
                <tr class="net"><td>{{ $v->label('net_payable') }}</td><td class="num">{{ $paper->money($s['net_payable']) }}</td></tr>
                <tr><td>{{ $v->label('paid') }}</td><td class="num">{{ $paper->money($s['paid']) }}</td></tr>
                <tr class="strong"><td data-bill-left>{{ $v->billLeftWord() }}</td><td class="num">{{ $v->billLeftAmount() }}</td></tr>
            </table>
        </td>

        <td class="main">
            <table class="head">
                <tr>
                    <td>
                        <div class="title">INVOICE &amp; ACCOUNT STATEMENT</div>
                        <div class="muted">{{ $facts['bill']['bill_date'] }}</div>
                        @if ($v->shows('order_no'))<div class="muted" data-order-no>{{ $v->en('order_no') }} {{ $facts['bill']['order_no'] }}</div>@endif
                        @if ($v->shows('invoice_type'))<div class="muted" data-invoice-type>{{ $v->en('type') }} {{ $facts['bill']['type'] }}</div>@endif
                        @if ($v->duplicate)<div class="dup" data-duplicate>{{ $v->en('duplicate') }}</div>@endif
                    </td>
                    <td style="text-align: right"><div class="no">{{ $facts['bill']['bill_no'] }}</div></td>
                    <td style="width: 17mm; text-align: right">@include('sales::print.partials.invoice-qr', ['v' => $v, 'width' => '15mm'])</td>
                </tr>
            </table>

            @if ($v->notices !== [])<div class="notice">{{ implode(' · ', $v->notices) }}</div>@endif

            @include('sales::print.partials.invoice-items', ['v' => $v, 'facts' => $facts, 'paper' => $paper, 'upper' => false, 'zebra' => true, 'inlineLot' => true])

            <table class="netline">
                <tr>
                    <td>{{ $v->label('net_payable') }}@if ($v->shows('amount_words')) · <span class="words" data-words>{{ $facts['words'] }}</span>@endif</td>
                    <td class="num" style="width: 30mm">{{ $paper->money($s['net_payable']) }}</td>
                </tr>
            </table>

            @if ($v->shows('previous_due'))
                <div class="section">{{ mb_strtoupper($t('statement')) }}</div>
                <table class="ledger" data-movement>
                    <tr>
                        <th style="width: 16mm">{{ $v->label('txn_date') }}</th>
                        <th>{{ $t('particulars') }}</th>
                        <th class="num" style="width: 21mm">{{ $t('debit') }}</th>
                        <th class="num" style="width: 21mm">{{ $t('credit') }}</th>
                        <th class="num" style="width: 28mm">{{ $t('balance') }}</th>
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

            <div class="footnote"><div style="text-align: left; font-size: 80%; line-height: 1.35">{!! nl2br(e($v->footnote)) !!}</div></div>
            @include('sales::print.partials.invoice-signatures', ['v' => $v])
            <table class="foot">
                <tr>
                    <td>{{ $v->printedAt() }}</td>
                    <td style="text-align: right">Sidebar Light</td>
                </tr>
            </table>
        </td>
    </tr>
</table>
