{{--
    Formal Form — বিল + হিসাবের বিবরণী, সরকারি ফর্মের ধাঁচে: সব কিছু দাগটানা ছকের ঘরে। মালিক, ৩ অক্টোবর ২০২৬
    (নকশার ক্যানভাস "৩ · সরকারি ফর্ম")।

    ⓘ গড়ন উপর থেকে নিচে, পুরো পাতা জুড়ে, কেবল কালো কালি (মাথার সারিতে হালকা ধূসর): মাঝখানে কোম্পানির নাম আর
    ঠিকানা; তারপর দুই পাশে দুই দাগের মাঝে ফ্রেমে "INVOICE & ACCOUNT STATEMENT"; তারপর তিন ঘরের ছক — বিলের ঘর
    (নম্বর, তারিখ, অর্ডার, ধরন) | ক্রেতা আর ড্রাইভার | QR। তারপর পুরো দাগের পণ্যের ছক, ঠিক নিচে যোগফলের এক সারি
    (Grand Total · Discount · VAT · Rounding · Net Payable) আর কথায়। তারপর দুই ভাগ: বাঁয়ে বিলের মাসের হিসাবের চলাচল
    ([[InvoicePaperView::movement()]]) আর জমার ছক, ডানে "Account Summary" ছক (শেষে Invoice Due / Extra Paid, তারপর
    Due / Advance / No Due — [[InvoicePaperView::billLeftWord()]], [[balanceWord()]]) আর টার্গেটের ছক। শেষে সইয়ের ঘর বাক্সে।
    ⓘ সুইচ আর `data-*` চিহ্ন "Special for DB"-র মতোই, একই শর্তে; লক্ষ্য না থাকলে ছকটাই নেই ([[InvoicePaperView::target()]])।
    ⓘ লট আলাদা কলাম নয় — নামের পাশে, এক লাইনে (`inlineLot`)।
    ⓘ সইয়ের বাক্স: দাগ ভেতরের ঘরে (`td.sig-line`), কারণ ভাগের partial বাইরের ঘরে class দেয় না।
--}}
@php
    $v = new \App\Modules\Sales\Support\InvoicePaperView($doc, $facts, $company, $profile);
    $ink = '#111111';
    $grid = '#8a8a8a';
    $shade = '#e9e9e9';
    $t = fn (string $key) => __('sales::invoice_design.'.$key, [], 'en');
    $s = $v->sums;
    $target = $v->target();
    $transport = $facts['transport'] ?? [];
    $officer = (string) ($facts['bill']['sales_officer'] ?? '');
@endphp
<style @nonce>
    body { font-family: hindsiliguri, sans-serif; font-size: 8pt; line-height: 1.15; color: {{ $ink }}; }
    table { border-collapse: collapse; }
    .center { text-align: center; }
    .co-name { font-size: 13pt; font-weight: bold; letter-spacing: 0.3mm; text-align: center; }
    .co-meta { font-size: 7.5pt; line-height: 1.1; text-align: center; }
    table.titlebar { width: 100%; margin-top: 1mm; }
    table.titlebar td.rule { border-top: 0.9mm double {{ $ink }}; }
    {{-- ⓘ বাঁধা mm চওড়া: mPDF `width: 1%` ঘরে `nowrap` মানে না — লেখা এক অক্ষর এক লাইনে খাড়া হয়ে পাতা খেত (৬৫%-এ আঁটত) --}}
    table.titlebar td.label { width: 80mm; text-align: center; white-space: nowrap; border: 0.4mm solid {{ $ink }}; padding: 0.4mm 4mm; font-size: 9.5pt; font-weight: bold; letter-spacing: 0.3mm; }
    table.form { width: 100%; margin-top: 1.2mm; border: 0.4mm solid {{ $ink }}; }
    table.form td { border: 0.25mm solid {{ $ink }}; padding: 0.5mm 1.6mm; vertical-align: top; }
    .strong { font-weight: bold; }
    .mono { font-family: dejavusans; }
    .cap { font-weight: bold; }
    .party { font-weight: bold; }
    .sub { font-size: 7pt; color: #444444; }
    .dup { font-size: 7.5pt; font-weight: bold; }
    .notice { text-align: center; font-weight: bold; border: 0.4mm solid #b42318; color: #b42318; padding: 1mm; margin-top: 2mm; font-size: 10pt; }
    table.items { width: 100%; margin-top: 1.2mm; border: 0.4mm solid {{ $ink }}; }
    table.items th { background: {{ $shade }}; font-size: 7.5pt; font-weight: bold; padding: 0.5mm 1.3mm; text-align: left; border: 0.25mm solid {{ $ink }}; }
    table.items th.num { text-align: right; }
    table.items td { padding: 0.3mm 1.3mm; border: 0.25mm solid {{ $grid }}; font-size: 8pt; line-height: 1.1; vertical-align: top; }
    table.items tr.grand td { font-weight: bold; border: 0.25mm solid {{ $ink }}; }
    .free { font-weight: bold; }
    .num { text-align: right; white-space: nowrap; }
    td.num { font-family: dejavusans; }
    table.strip { width: 100%; border: 0.4mm solid {{ $ink }}; border-top: none; }
    table.strip td { border: 0.25mm solid {{ $ink }}; padding: 0.5mm 1.3mm; font-size: 8pt; }
    table.lower { width: 100%; margin-top: 1.2mm; }
    table.lower td.left { vertical-align: top; padding-right: 2mm; }
    table.lower td.right { width: 52mm; vertical-align: top; }
    table.box { width: 100%; border: 0.4mm solid {{ $ink }}; }
    table.box th { background: {{ $shade }}; font-weight: bold; font-size: 7.5pt; text-align: left; padding: 0.4mm 1.3mm; border: 0.25mm solid {{ $ink }}; }
    table.box th.num { text-align: right; }
    table.box td { border: 0.25mm solid {{ $grid }}; padding: 0.3mm 1.3mm; font-size: 7.5pt; }
    table.box tr.strong td { font-weight: bold; border: 0.25mm solid {{ $ink }}; }
    table.box tr.close td { font-weight: bold; }
    table.pay { width: 100%; margin-top: 1mm; border: 0.4mm solid {{ $ink }}; }
    table.pay th { background: {{ $shade }}; font-size: 7pt; font-weight: bold; text-align: left; padding: 0.4mm 1.3mm; border: 0.25mm solid {{ $ink }}; }
    table.pay td { font-size: 7.5pt; padding: 0.3mm 1.3mm; border: 0.25mm solid {{ $grid }}; }
    .pay-head { font-size: 7.5pt; font-weight: bold; margin-top: 1mm; }
    .footnote { margin-top: 1mm; font-size: 9pt; font-weight: bold; color: #b42318; }
    table.signatures { width: 100%; margin-top: 2mm; }
    table.signatures td { text-align: center; padding: 0 0.8mm; font-size: 8pt; }
    .sig-line { border: 0.3mm solid {{ $ink }}; height: 9mm; vertical-align: bottom; padding: 0.6mm; }
    table.foot { width: 100%; margin-top: 1mm; }
    table.foot td { font-size: 7pt; color: #444444; }
</style>

@if ($v->logo)<div class="center"><img src="{{ $v->logo }}" style="max-height: 10mm; max-width: 26mm;" alt=""></div>@endif
<div class="co-name">{{ $v->head['name'] }}</div>
@include('sales::print.partials.invoice-company', ['v' => $v])

<table class="titlebar">
    <tr>
        <td class="rule">&nbsp;</td>
        <td class="label">INVOICE &amp; ACCOUNT STATEMENT</td>
        <td class="rule">&nbsp;</td>
    </tr>
</table>

@if ($v->notices !== [])<div class="notice">{{ implode(' · ', $v->notices) }}</div>@endif

<table class="form">
    <tr>
        <td style="width: 36%">
            <div class="strong">{{ $v->label('bill_no') }}: <span class="mono">{{ $facts['bill']['bill_no'] }}</span></div>
            <div>{{ $v->label('bill_date') }}: {{ $facts['bill']['bill_date'] }}</div>
            @if ($v->shows('order_no'))<div data-order-no>{{ $v->en('order_no') }} {{ $facts['bill']['order_no'] }}</div>@endif
            @if ($v->shows('invoice_type'))<div data-invoice-type>{{ $v->en('type') }} {{ $facts['bill']['type'] }}</div>@endif
            @if ($officer !== '')<div>{{ $t('sales_officer') }}: {{ $officer }}</div>@endif
            @if ($v->duplicate)<div class="dup" data-duplicate>{{ $v->en('duplicate') }}</div>@endif
        </td>
        <td>
            @include('sales::print.partials.invoice-bill-to', ['v' => $v, 'facts' => $facts, 'cap' => 'cap'])
            @if ($v->shows('transport'))
                <div data-transport>Driver: {{ ($transport['driver_name'] ?? '') !== '' ? $transport['driver_name'] : ($transport['carrier'] ?? '') }}@if (($transport['driver_phone'] ?? '') !== '') · {{ $transport['driver_phone'] }}@endif @if (($transport['vehicle'] ?? '') !== '') · {{ $transport['vehicle'] }}@endif @if (($transport['delivery_date'] ?? '') !== '') · {{ $v->en('delivery_date') }} {{ $transport['delivery_date'] }}@endif</div>
            @endif
        </td>
        <td style="width: 22mm; text-align: center; vertical-align: middle">@include('sales::print.partials.invoice-qr', ['v' => $v, 'width' => '18mm'])</td>
    </tr>
</table>

@include('sales::print.partials.invoice-items', ['v' => $v, 'facts' => $facts, 'paper' => $paper, 'upper' => false, 'inlineLot' => true])

<table class="strip">
    <tr>
        <td>
            {{ $v->label('grand_total') }} <span class="mono">{{ $paper->money($s['grand_total']) }}</span>
            · {{ $v->label('discount') }} − <span class="mono">{{ $paper->money($s['discount']) }}</span>
            @if ($v->showVat)· {{ $v->label('vat') }} <span class="mono">{{ $paper->money($s['vat']) }}</span>@endif
            · {{ $v->label('rounding') }} <span class="mono">{{ $paper->money($s['rounding']) }}</span>
            · <strong>{{ $v->label('net_payable') }}</strong>
        </td>
        <td class="num strong" style="width: 30mm">{{ $paper->money($s['net_payable']) }}</td>
    </tr>
    @if ($v->shows('amount_words'))
        <tr><td colspan="2" data-words><strong>{{ $v->en('in_words') }}</strong> {{ $facts['words'] }}</td></tr>
    @endif
</table>

<table class="lower">
    <tr>
        <td class="left">
            @if ($v->shows('previous_due'))
                <table class="box" data-movement>
                    <tr><th colspan="5">{{ $t('statement') }}</th></tr>
                    <tr>
                        <th style="width: 16mm">{{ $v->label('txn_date') }}</th>
                        <th>{{ $t('particulars') }}</th>
                        <th class="num" style="width: 20mm">{{ $t('debit') }}</th>
                        <th class="num" style="width: 20mm">{{ $t('credit') }}</th>
                        <th class="num" style="width: 27mm">{{ $t('balance') }}</th>
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
        </td>
        <td class="right">
            <table class="box" data-invoice-summary>
                <tr><th colspan="2">{{ $t('account_summary') }}</th></tr>
                @if ($v->shows('previous_due'))
                    <tr data-previous-due><td>{{ $v->label('previous_due') }}</td><td class="num">{{ $v->previousBeforeBill() }}</td></tr>
                @endif
                <tr><td>This invoice</td><td class="num">{{ $paper->money($s['net_payable']) }}</td></tr>
                <tr><td>{{ $v->label('paid') }}</td><td class="num">{{ $paper->money($s['paid']) }}</td></tr>
                <tr class="strong"><td data-bill-left>{{ $v->billLeftWord() }}</td><td class="num">{{ $v->billLeftAmount() }}</td></tr>
                @if ($v->shows('previous_due'))
                    <tr class="strong"><td data-balance-word>{{ $v->balanceWord() }}</td><td class="num">{{ $v->balanceAmount() }}</td></tr>
                @endif
            </table>

            @if ($target !== null)
                <table class="box" style="margin-top: 2mm" data-target>
                    <tr><th colspan="2">Target · {{ $target['month'] }}</th></tr>
                    <tr><td>Target</td><td class="num">{{ $target['target'] }}</td></tr>
                    <tr><td>Inflow achieved</td><td class="num">{{ $target['achieved'] }}</td></tr>
                    <tr class="strong"><td>Remaining</td><td class="num">{{ $target['remaining'] }}</td></tr>
                    <tr><td colspan="2">Closes {{ $target['closes_on'] }} · {{ $target['bank_days'] }} bank days</td></tr>
                </table>
            @endif
        </td>
    </tr>
</table>

<div class="footnote"><div style="text-align: left; font-size: 80%; line-height: 1.35">{!! nl2br(e($v->footnote)) !!}</div></div>
@include('sales::print.partials.invoice-signatures', ['v' => $v])
<table class="foot">
    <tr>
        <td>{{ $v->printedAt() }}</td>
        <td style="text-align: right">Formal Form</td>
    </tr>
</table>
