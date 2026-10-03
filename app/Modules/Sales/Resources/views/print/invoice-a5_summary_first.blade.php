{{-- ⓘ A5 সংস্করণ — A4-এর summary_first থেকে মাপ ছোট করে (mm ×০.৭২, অক্ষর ×০.৮৫, সীমা ৬.৫pt), চেহারা একই। মালিক, ৩০ সেপ্টেম্বর ২০২৬: "A5 er jonno 21 desine koro" --}}
{{--
    ১০ · আগে হিসাব — মাথার নিচেই চারটা বড় ঘর: নিট প্রদেয় · জমা · আগের বকেয়া · মোট বকেয়া; তারপর বাকি সব।
    নমুনা: Design canvas, ১০। ⓘ সুইচ আর `data-*` চিহ্ন ভাগের partial-এ ([[InvoicePaperView]])।
    ⚠️ "আগের বকেয়া" সুইচ বন্ধ হলে উপরের শেষ দুই ঘরও যায় — নিচের সারির সাথে এক নিয়ম।
--}}
@php
    $v = new \App\Modules\Sales\Support\InvoicePaperView($doc, $facts, $company, $profile);
    $accent = '#4b3fa8';
    $cards = [
        [$v->label('net_payable'), $v->sums['net_payable'], '#f1effb', $accent, '#1a1c23'],
        [$v->label('paid'), $v->sums['paid'], '#eef7f1', '#1f6b3a', '#1a1c23'],
    ];
    if ($v->shows('previous_due')) {
        $cards[] = [$v->label('previous_due'), $v->sums['previous_due'], '#f4f5f7', '#5d6773', '#1a1c23'];
        $cards[] = [$v->label('total_due'), $v->sums['outstanding'], $accent, '#d9d4f7', '#ffffff'];
    }
    $cardWidth = round(100 / count($cards), 2);
@endphp

<style @nonce>
    body { font-family: hindsiliguri, sans-serif; font-size: 7.6pt; color: #18212b; }
    table { border-collapse: collapse; }
    table.head { width: 100%; }
    table.head td { vertical-align: middle; }
    .mark { width: 8.6mm; height: 8.6mm; background: {{ $accent }}; color: #fff; text-align: center; font-weight: bold; font-size: 10.2pt; }
    .co-name { font-size: 12.8pt; font-weight: bold; }
    .co-meta { font-size: 6.5pt; color: #5d6773; }
    .title { text-align: right; font-size: 7.6pt; font-weight: bold; letter-spacing: 0.7mm; color: {{ $accent }}; }
    .no { text-align: right; font-size: 12.8pt; font-weight: bold; font-family: dejavusans; }
    .dup { text-align: right; font-size: 6.5pt; font-weight: bold; color: #5d6773; }
    .notice { text-align: center; font-weight: bold; border: 0.3mm solid #b42318; color: #b42318; padding: 1.4mm; margin-top: 2.2mm; font-size: 9.3pt; }
    table.cards { width: 100%; margin-top: 3.6mm; }
    table.cards td { padding: 2.2mm; vertical-align: top; }
    .card-cap { font-size: 6.5pt; font-weight: bold; }
    .card-val { font-size: 11pt; font-weight: bold; font-family: dejavusans; margin-top: 0.7mm; }
    table.two { width: 100%; margin-top: 2.9mm; }
    table.two td.box { width: 49%; border: 0.2mm solid #e3e6ea; padding: 1.8mm 2.2mm; vertical-align: top; font-size: 7.2pt; }
    .cap { font-size: 6.5pt; font-weight: bold; color: {{ $accent }}; }
    .party { font-weight: bold; }
    .sub { font-size: 6.5pt; color: #5d6773; font-weight: normal; }
    table.items { width: 100%; margin-top: 2.9mm; }
    table.items th { font-size: 6.5pt; font-weight: bold; color: #5d6773; padding: 0 1.1mm 1.1mm 1.1mm; text-align: left; border-bottom: 0.4mm solid #18212b; }
    table.items th.num { text-align: right; }
    table.items td { padding: 1.4mm 1.1mm; border-bottom: 0.2mm solid #e3e6ea; font-size: 7.6pt; vertical-align: top; }
    table.items tr.grand td { font-weight: bold; border-bottom: 0; }
    .free { color: {{ $accent }}; font-weight: bold; }
    .num { text-align: right; white-space: nowrap; }
    td.num { font-family: dejavusans; }
    table.bottom { width: 100%; margin-top: 2.9mm; }
    table.bottom td.side { vertical-align: top; font-size: 7.2pt; }
    .pay-head { font-size: 6.5pt; font-weight: bold; color: {{ $accent }}; }
    table.pay { width: 100%; margin-top: 0.7mm; }
    table.pay th { font-size: 6.5pt; color: #5d6773; text-align: left; padding: 0.6mm 0.7mm; }
    table.pay td { font-size: 6.8pt; padding: 0.6mm 0.7mm; border-top: 0.1mm solid #e3e6ea; }
    table.sums { width: 100%; }
    table.sums td { padding: 0.8mm 1.1mm; font-size: 7.6pt; }
    table.sums tr.net td { font-weight: bold; border-top: 0.2mm solid #18212b; }
    table.sums tr.owed td { font-weight: bold; color: {{ $accent }}; }
    .words { margin-top: 2.2mm; font-size: 7.2pt; }
    .footnote { margin-top: 2.2mm; font-size: 7.6pt; font-weight: bold; color: #b42318; }
    table.signatures { width: 100%; margin-top: 10.1mm; }
    table.signatures td { text-align: center; padding: 0 3.6mm; font-size: 7.6pt; }
    .sig-line { border-top: 0.2mm solid #18212b; padding-top: 0.7mm; }
    .printed { margin-top: 2.9mm; font-size: 6.5pt; color: #5d6773; }
</style>

<table class="head">
    <tr>
        <td>
            <table>
                <tr>
                    <td style="padding-right: 2.2mm">
                        @if ($v->logo)
                            <img src="{{ $v->logo }}" style="height: 8.6mm;" alt="">
                        @else
                            <div class="mark">{{ mb_strtoupper(mb_substr($v->head['name'], 0, 2)) }}</div>
                        @endif
                    </td>
                    <td>
                        <div class="co-name">{{ $v->head['name'] }}</div>
                        @include('sales::print.partials.invoice-company', ['v' => $v])
                    </td>
                </tr>
            </table>
        </td>
        <td style="width: 43.2mm">
            <div class="title">{{ mb_strtoupper($v->en('heading')) }}</div>
            <div class="no">{{ $facts['bill']['bill_no'] }}</div>
            <div class="dup">{{ $facts['bill']['bill_date'] }}@if ($v->shows('order_no')) · <span data-order-no>{{ $facts['bill']['order_no'] }}</span>@endif @if ($v->shows('invoice_type')) · <span data-invoice-type>{{ $facts['bill']['type'] }}</span>@endif</div>
            @if ($v->duplicate)<div class="dup" data-duplicate>{{ $v->en('duplicate') }}</div>@endif
        </td>
    </tr>
</table>

@if ($v->notices !== [])<div class="notice">{{ implode(' · ', $v->notices) }}</div>@endif

<table class="cards">
    <tr>
        @foreach ($cards as [$label, $amount, $bg, $capColor, $valColor])
            <td style="width: {{ $cardWidth }}%; background: {{ $bg }};">
                <div class="card-cap" style="color: {{ $capColor }}">{{ mb_strtoupper($label) }}</div>
                <div class="card-val" style="color: {{ $valColor }}">{{ $paper->money($amount) }}</div>
            </td>
            @if (! $loop->last)<td style="width: 1.1mm; padding: 0"></td>@endif
        @endforeach
    </tr>
</table>

<table class="two">
    <tr>
        <td class="box">@include('sales::print.partials.invoice-bill-to', ['v' => $v, 'facts' => $facts])</td>
        <td style="width: 2%"></td>
        <td class="box">@include('sales::print.partials.invoice-transport', ['v' => $v, 'facts' => $facts])</td>
    </tr>
</table>

@include('sales::print.partials.invoice-items', ['v' => $v, 'facts' => $facts, 'paper' => $paper, 'narrow' => true])

<table class="bottom">
    <tr>
        <td class="side" style="width: 57%; padding-right: 4.3mm">
            @if ($v->shows('amount_words'))<div data-words><strong>{{ $v->en('in_words') }}</strong> {{ $facts['words'] }}</div>@endif
            <div style="margin-top: 1.4mm">@include('sales::print.partials.invoice-payments', ['v' => $v, 'doc' => $doc])</div>
            <div style="margin-top: 2.2mm; text-align: left">@include('sales::print.partials.invoice-qr', ['v' => $v, 'width' => '16mm'])</div>
        </td>
        <td class="side">@include('sales::print.partials.invoice-sums', ['v' => $v, 'paper' => $paper])</td>
    </tr>
</table>

<div class="footnote"><div style="text-align: left; font-size: 50%; line-height: 1.35">{!! nl2br(e($v->footnote)) !!}</div></div>
@include('sales::print.partials.invoice-signatures', ['v' => $v])
<div class="printed">{{ $v->printedAt() }}</div>
