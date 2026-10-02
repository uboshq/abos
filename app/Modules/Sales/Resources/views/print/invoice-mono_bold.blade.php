{{--
    ২২ · মোনো সাহসী ২০২৬ — সাদা-কালোয় এখনকার সবচেয়ে চালু বিলের ধাঁচ: উপরে পুরো চওড়া সারাংশের পট্টি (কত বকেয়া ·
    কবে · কোন বিল), কালো পট্টির পণ্য-মাথা, নিচে কালো ব্লকে মোট বকেয়া। মালিক, ৩০ সেপ্টেম্বর ২০২৬: *"BLACK &
    WHAITE WORALD LATEST JONOPRIO EKTA INVOICE FORMET DAW ZA 2026 ER SOBCHEYE JONO PRIO . BUT AGER TEMPLATE E DAWNI EMO"*।

    ⓘ আগের সাদা-কালোগুলো থেকে তফাত: minimal_mono হালকা আর ফাঁকা, bw_ledger খাতার ঘর, bw_typewriter টাইপরাইটার —
    এটা ভারী কালো ব্লক আর "প্রথমেই কত টাকা" সারাংশের পট্টি। ⓘ রঙিন কালি লাগে না, সাদা-কালো প্রিন্টারে পুরোটা আসে।
    ⓘ সুইচ আর `data-*` চিহ্ন ভাগের partial-এ ([[InvoicePaperView]])।
--}}
@php
    $v = new \App\Modules\Sales\Support\InvoicePaperView($doc, $facts, $company, $profile);
    $s = $v->sums;
    $dueLabel = $v->shows('previous_due') ? $v->label('total_due') : $v->label('invoice_due');
    $due = $v->shows('previous_due') ? $s['outstanding'] : $s['invoice_due'];
@endphp

<style @nonce>
    body { font-family: hindsiliguri, sans-serif; font-size: 9pt; color: #111; }
    table { border-collapse: collapse; }
    table.head { width: 100%; }
    table.head td { vertical-align: middle; }
    .co-name { font-size: 15pt; font-weight: bold; color: #000; }
    .co-meta { font-size: 7.5pt; color: #555; }
    .title { text-align: right; font-size: 30pt; font-weight: bold; letter-spacing: 1.5mm; color: #000; line-height: 1; }
    .dup { text-align: right; font-size: 7.5pt; font-weight: bold; margin-top: 1mm; }
    .notice { text-align: center; font-weight: bold; border: 0.5mm solid #000; padding: 2mm; margin-top: 3mm; font-size: 11pt; }
    table.strip { width: 100%; margin-top: 5mm; border-top: 1.4mm solid #000; border-bottom: 0.3mm solid #000; }
    table.strip td { padding: 3mm 3mm 3mm 0; vertical-align: top; }
    .cap { font-size: 6.8pt; font-weight: bold; letter-spacing: 0.5mm; color: #555; }
    .big { font-size: 20pt; font-weight: bold; font-family: dejavusans; color: #000; }
    .mid { font-size: 11pt; font-weight: bold; color: #000; }
    table.three { width: 100%; margin-top: 5mm; }
    table.three td { width: 33%; vertical-align: top; font-size: 8.5pt; line-height: 1.5; padding-right: 5mm; }
    .party { font-weight: bold; font-size: 10pt; }
    .sub { font-size: 7.5pt; color: #555; font-weight: normal; }
    table.items { width: 100%; margin-top: 6mm; }
    table.items th { background: #000; color: #fff; font-size: 7.5pt; font-weight: bold; letter-spacing: 0.3mm; padding: 2mm; text-align: left; }
    table.items th.num { text-align: right; }
    table.items td { padding: 2.2mm 2mm; border-bottom: 0.2mm solid #c8c8c8; font-size: 9pt; vertical-align: top; }
    table.items tr.grand td { font-weight: bold; border-top: 0.6mm solid #000; border-bottom: 0.6mm solid #000; }
    .free { font-weight: bold; }
    .num { text-align: right; white-space: nowrap; }
    td.num { font-family: dejavusans; }
    table.bottom { width: 100%; margin-top: 5mm; }
    table.bottom td.side { vertical-align: top; font-size: 8.5pt; }
    .pay-head { font-size: 7pt; font-weight: bold; letter-spacing: 0.5mm; color: #555; }
    table.pay { width: 100%; margin-top: 1mm; }
    table.pay th { font-size: 7pt; color: #555; text-align: left; padding: 1mm; border-bottom: 0.3mm solid #000; }
    table.pay td { font-size: 8.5pt; padding: 1mm; border-bottom: 0.2mm solid #c8c8c8; }
    table.sums { width: 100%; }
    table.sums td { padding: 1.1mm 2mm; font-size: 9pt; }
    table.sums tr.net td { font-weight: bold; border-top: 0.3mm solid #000; }
    table.sums tr.owed td { background: #000; color: #fff; font-weight: bold; font-size: 11pt; padding: 2.5mm 2mm; }
    .words { margin-top: 2mm; font-size: 8.5pt; }
    .footnote { margin-top: 4mm; font-size: 9pt; font-weight: bold; text-align: center; }
    table.signatures { width: 100%; margin-top: 14mm; }
    table.signatures td { text-align: center; padding: 0 5mm; font-size: 8.5pt; }
    .sig-line { border-top: 0.3mm solid #000; padding-top: 1mm; }
    table.foot { width: 100%; margin-top: 5mm; border-top: 0.3mm solid #000; }
    table.foot td { padding-top: 1.5mm; font-size: 7pt; color: #555; }
</style>

<table class="head">
    <tr>
        <td>
            <table><tr>
                @if ($v->logo)<td style="padding-right: 3mm; vertical-align: middle"><img src="{{ $v->logo }}" style="height: 14mm;" alt=""></td>@endif
                <td style="vertical-align: middle">
                    <div class="co-name">{{ $v->head['name'] }}</div>
                    @include('sales::print.partials.invoice-company', ['v' => $v])
                </td>
            </tr></table>
        </td>
        <td style="width: 70mm">
            <div class="title">{{ mb_strtoupper($v->en('heading')) }}</div>
            @if ($v->duplicate)<div class="dup" data-duplicate>{{ $v->en('duplicate') }}</div>@endif
        </td>
    </tr>
</table>

@if ($v->notices !== [])<div class="notice">{{ implode(' · ', $v->notices) }}</div>@endif

{{-- ⭐ সারাংশের পট্টি — কাগজ হাতে নিয়ে প্রথম প্রশ্ন "কত, কবে, কোন বিল" — এক নজরে --}}
<table class="strip">
    <tr>
        <td style="width: 40%">
            <div class="cap">{{ mb_strtoupper($dueLabel) }}</div>
            <div class="big">{{ $paper->money($due) }}</div>
        </td>
        <td style="width: 20%">
            <div class="cap">{{ mb_strtoupper($v->label('bill_date')) }}</div>
            <div class="mid">{{ $facts['bill']['bill_date'] }}</div>
        </td>
        <td style="width: 20%">
            <div class="cap">{{ mb_strtoupper($v->label('bill_no')) }}</div>
            <div class="mid" style="font-family: dejavusans">{{ $facts['bill']['bill_no'] }}</div>
        </td>
        <td style="text-align: right; padding-right: 0">
            @if ($v->shows('invoice_type'))<div class="cap">{{ mb_strtoupper($v->label('type')) }}</div><div class="mid" data-invoice-type>{{ $facts['bill']['type'] }}</div>@endif
        </td>
    </tr>
</table>

<table class="three">
    <tr>
        <td>@include('sales::print.partials.invoice-bill-to', ['v' => $v, 'facts' => $facts])</td>
        <td>@include('sales::print.partials.invoice-transport', ['v' => $v, 'facts' => $facts])</td>
        <td style="padding-right: 0">
            {{-- ⓘ নম্বর, তারিখ আর ধরন উপরের পট্টিতে — এখানে কেবল বাকিটা, একই কথা দুইবার নয় --}}
            <div class="cap">{{ mb_strtoupper(__('sales::invoice_design.details', [], 'en')) }}</div>
            @if ($v->shows('order_no'))<div data-order-no>{{ $v->label('order_no') }}: <strong>{{ $facts['bill']['order_no'] }}</strong></div>@endif
            <div>{{ $v->label('created_by') }}: {{ $facts['bill']['created_by'] }}</div>
        </td>
    </tr>
</table>

@include('sales::print.partials.invoice-items', ['v' => $v, 'facts' => $facts, 'paper' => $paper])

<table class="bottom">
    <tr>
        <td class="side" style="width: 52%; padding-right: 8mm">
            @include('sales::print.partials.invoice-payments', ['v' => $v, 'doc' => $doc])
            @if ($v->shows('amount_words'))<div class="words" data-words><strong>{{ $v->en('in_words') }}</strong> {{ $facts['words'] }}</div>@endif
            <div style="margin-top: 4mm">@include('sales::print.partials.invoice-qr', ['v' => $v, 'width' => '20mm'])</div>
        </td>
        <td class="side">@include('sales::print.partials.invoice-sums', ['v' => $v, 'paper' => $paper])</td>
    </tr>
</table>

<div class="footnote">{!! nl2br(e($v->footnote)) !!}</div>
@include('sales::print.partials.invoice-signatures', ['v' => $v])

<table class="foot">
    <tr>
        <td>{{ $v->head['name'] }}@if ($v->head['phone'] !== '') · {{ $v->head['phone'] }}@endif</td>
        <td style="text-align: right">{{ $v->printedAt() }}</td>
    </tr>
</table>
