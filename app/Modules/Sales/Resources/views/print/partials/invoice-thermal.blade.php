{{--
    থার্মাল (৮০মিমি রোল) বিলের একটাই কাঠামো — ২১টা নকশা এটাকেই আলাদা সাজে ডাকে।
    মালিক, ৩০ সেপ্টেম্বর ২০২৬: *"ok 21 ti kore nomuna sob kotir hobe"*।

    ── ⚠️ কেন একটা কাঠামো, ২১টা আলাদা ছাঁচ নয় ──────────────────────────
    রোলে লেখার জায়গা ~৭৪মিমি, আর থার্মাল কেবল কালো ছাপে — রঙ নেই, ধূসর ভরাট ঝাপসা হয়। তাই
    নকশাগুলোর তফাত মাথার ধরন, দাগ, অক্ষর আর মোটের ঘরের সাজে; সারি, টাকা আর সুইচ একই। ⛔ ২১টা
    আলাদা ছাঁচে একই সারি ২১ বার লেখা থাকত, আর একটা সুইচ একদিন কোনো একটায় কাজ করত না।

    চাই: $doc, $facts, $company, $paper, $profile, আর $style (নকশার সাজ):
      font      hindsiliguri | dejavusansmono | dejavuserif
      lang      en | bn | both
      header    center | left | band | boxed | bar | big_no | stacked
      rule      solid | dashed | double
      items     lines | grid | dense
      total     band | box | big | plain | double
      summary_first · account · movement · stub · seal   (bool)
      logo      top | left | none

    ⓘ সুইচ সব [[InvoicePaperView]]-এর; `data-*` চিহ্ন অন্য নকশার নামেই।
--}}
@php
    $v = new \App\Modules\Sales\Support\InvoicePaperView($doc, $facts, $company, $profile);
    $st = array_merge([
        'font' => 'hindsiliguri', 'lang' => 'en', 'header' => 'center', 'rule' => 'dashed', 'items' => 'lines',
        'total' => 'box', 'summary_first' => false, 'account' => false, 'movement' => false, 'stub' => false,
        'seal' => false, 'logo' => 'top', 'size' => 8, 'hero' => false, 'qr_top' => false, 'upper' => false,
    ], $style ?? []);

    $L = function (string $key) use ($v, $st) {
        return match ($st['lang']) {
            'bn' => $v->label($key, 'bn'),
            'both' => $v->label($key).' / '.$v->label($key, 'bn'),
            default => $v->label($key),
        };
    };
    $ruleCss = match ($st['rule']) {
        'solid' => '0.3mm solid #000',
        'double' => '0.9mm double #000',
        default => '0.3mm dashed #000',
    };
    $fs = (float) $st['size'];
    $mono = $st['font'] === 'dejavusansmono';
    $name = $v->head['name'];
    $s = $v->sums;
@endphp

<style @nonce>
    body { font-family: {{ $st['font'] }}, sans-serif; font-size: {{ $fs }}pt; color: #000; }
    table { border-collapse: collapse; }
    .bn { font-family: hindsiliguri, sans-serif; }
    .c { text-align: center; }
    .r { text-align: right; }
    .num { text-align: right; white-space: nowrap; }
    td.num { font-family: {{ $mono ? 'dejavusansmono' : 'dejavusans' }}; }
    .rule { border-top: {{ $ruleCss }}; margin: 1.5mm 0; height: 0; }
    .co-name { font-size: {{ $fs + 4 }}pt; font-weight: bold; }
    .co-meta { font-size: {{ $fs - 1 }}pt; }
    .title { font-size: {{ $fs + 2 }}pt; font-weight: bold; letter-spacing: 0.6mm; }
    .inv { background: #000; color: #fff; padding: 1.5mm 2mm; }
    .boxed { border: 0.4mm solid #000; padding: 1.5mm 2mm; }
    .bar { border-left: 1.8mm solid #000; padding-left: 2mm; }
    .big-no { font-size: {{ $fs + 12 }}pt; font-weight: bold; line-height: 1; font-family: dejavusans; }
    .small { font-size: {{ $fs - 1 }}pt; }
    .notice { border: 0.5mm solid #000; text-align: center; font-weight: bold; padding: 1.2mm; margin-top: 1.5mm; font-size: {{ $fs + 1 }}pt; }
    table.kv { width: 100%; }
    table.kv td { padding: 0.3mm 0; font-size: {{ $fs }}pt; vertical-align: top; }
    table.it { width: 100%; }
    table.it th { font-size: {{ $fs - 0.5 }}pt; text-align: left; padding: 0.6mm 0; border-bottom: {{ $ruleCss }}; }
    table.it th.num { text-align: right; }
    table.it td { font-size: {{ $fs }}pt; padding: 0.5mm 0; vertical-align: top; }
    table.it tr.sub td { font-size: {{ $fs - 1 }}pt; padding-top: 0; padding-bottom: 1mm; }
    table.it.grid td, table.it.grid th { border: 0.25mm solid #000; padding: 0.6mm 0.8mm; }
    table.it tr.grand td { font-weight: bold; border-top: {{ $ruleCss }}; padding-top: 1mm; }
    table.sums { width: 100%; }
    table.sums td { padding: 0.4mm 0; font-size: {{ $fs }}pt; }
    table.sums tr.net td { font-weight: bold; }
    table.sums tr.owed td { font-weight: bold; }
    .total-band { background: #000; color: #fff; padding: 1.8mm 2mm; margin-top: 1.5mm; }
    .total-box { border: 0.5mm solid #000; padding: 1.8mm 2mm; margin-top: 1.5mm; }
    .total-double { border-top: 0.9mm double #000; border-bottom: 0.9mm double #000; padding: 1.5mm 0; margin-top: 1.5mm; }
    .total-val { font-size: {{ $fs + 5 }}pt; font-weight: bold; font-family: dejavusans; }
    .pay-head { font-weight: bold; font-size: {{ $fs - 0.5 }}pt; }
    table.pay { width: 100%; }
    table.pay th { display: none; }
    table.pay td { font-size: {{ $fs - 1 }}pt; padding: 0.3mm 0; }
    .footnote { text-align: center; font-weight: bold; font-size: {{ $fs - 0.5 }}pt; margin-top: 1.5mm; }
    table.signatures { width: 100%; margin-top: 9mm; }
    table.signatures td { text-align: center; font-size: {{ $fs - 1.5 }}pt; padding: 0 1mm; }
    .sig-line { border-top: 0.25mm solid #000; padding-top: 0.5mm; }
    table.seals td { border: 0.3mm solid #000; height: 14mm; vertical-align: top; text-align: center; font-size: {{ $fs - 1.5 }}pt; }
    .printed { text-align: center; font-size: {{ $fs - 1.5 }}pt; margin-top: 1.5mm; }
    /* ⚠️ মোনো আর সেরিফ অক্ষরে বাংলা নেই — বাংলা আসতে পারে এমন ঘর বাংলা অক্ষরে, নইলে বাক্স ছাপা হয় */
    table.pay td, table.signatures td, table.seals td, .footnote { font-family: hindsiliguri, sans-serif; }
    .hero { text-align: center; margin: 2mm 0 1mm; }
    .hero-val { font-size: {{ $fs + 14 }}pt; font-weight: bold; font-family: dejavusans; line-height: 1.1; }
    @if ($st['upper'])
    table.kv td, table.it th, table.sums td, .title, .pay-head { text-transform: uppercase; }
    @endif
</style>
@php $headline = $v->shows('previous_due') ? $v->sums['outstanding'] : $v->sums['invoice_due']; @endphp
@php $headlineLabel = $v->shows('previous_due') ? 'total_due' : 'invoice_due'; @endphp
@if ($st['qr_top'])
    <div class="c" style="margin-bottom: 1.5mm">@include('sales::print.partials.invoice-qr', ['v' => $v, 'width' => '24mm'])</div>
@endif

{{-- ── মাথা ───────────────────────────────────────────────────────── --}}
@php
    $logoImg = ($v->logo && $st['logo'] !== 'none') ? '<img src="'.e($v->logo).'" style="height: 11mm;" alt="">' : '';
@endphp
@if ($st['header'] === 'band')
    <div class="inv c">
        @if ($logoImg !== '')<div style="background: #fff; padding: 1mm; display: inline;">{!! $logoImg !!}</div>@endif
        <div class="co-name">{{ $name }}</div>
    </div>
    <div class="c">@include('sales::print.partials.invoice-company', ['v' => $v])</div>
@elseif ($st['header'] === 'left' || $st['logo'] === 'left')
    <table style="width: 100%"><tr>
        @if ($logoImg !== '')<td style="width: 14mm; vertical-align: middle">{!! $logoImg !!}</td>@endif
        <td style="vertical-align: middle"><div class="co-name">{{ $name }}</div>@include('sales::print.partials.invoice-company', ['v' => $v])</td>
    </tr></table>
@elseif ($st['header'] === 'boxed')
    <div class="boxed c">
        @if ($logoImg !== '')<div>{!! $logoImg !!}</div>@endif
        <div class="co-name">{{ $name }}</div>
        @include('sales::print.partials.invoice-company', ['v' => $v])
    </div>
@elseif ($st['header'] === 'bar')
    <div class="bar">
        @if ($logoImg !== ''){!! $logoImg !!}@endif
        <div class="co-name">{{ $name }}</div>
        @include('sales::print.partials.invoice-company', ['v' => $v])
    </div>
@else
    <div class="c">
        @if ($logoImg !== '')<div>{!! $logoImg !!}</div>@endif
        <div class="co-name">{{ $name }}</div>
        @include('sales::print.partials.invoice-company', ['v' => $v])
    </div>
@endif

<div class="rule"></div>

@if ($st['header'] === 'big_no')
    <div class="small">{{ mb_strtoupper($L('heading')) }}</div>
    <div class="big-no">{{ $facts['bill']['bill_no'] }}</div>
@else
    <div class="c title">{{ $st['lang'] === 'bn' ? $L('heading') : mb_strtoupper($v->en('heading')) }}@if ($st['lang'] === 'both') <span class="bn">/ {{ $v->label('heading', 'bn') }}</span>@endif</div>
@endif
@if ($v->duplicate)<div class="c small" data-duplicate><strong>{{ $st['lang'] === 'bn' ? $v->label('duplicate', 'bn') : $v->en('duplicate') }}</strong></div>@endif
@if ($v->notices !== [])<div class="notice">{{ implode(' · ', $v->notices) }}</div>@endif

@if ($st['hero'])
    {{-- ⓘ বড় অঙ্কে আগে "কত দিতে হবে" — এখনকার ডিজিটাল রসিদের ধাঁচ --}}
    <div class="hero">
        <div class="small">{{ $L($headlineLabel) }}</div>
        <div class="hero-val">{{ $paper->money($headline) }}</div>
    </div>
@endif

{{-- ── বিলের ঘর ─────────────────────────────────────────────────────── --}}
<table class="kv" style="margin-top: 1.5mm">
    @if ($st['header'] !== 'big_no')<tr><td>{{ $L('bill_no') }}</td><td class="r"><strong>{{ $facts['bill']['bill_no'] }}</strong></td></tr>@endif
    <tr><td>{{ $L('bill_date') }}</td><td class="r">{{ $facts['bill']['bill_date'] }}</td></tr>
    @if ($v->shows('order_no'))<tr data-order-no><td>{{ $L('order_no') }}</td><td class="r">{{ $facts['bill']['order_no'] }}</td></tr>@endif
    @if ($v->shows('invoice_type'))<tr data-invoice-type><td>{{ $L('type') }}</td><td class="r"><strong>{{ $facts['bill']['type'] }}</strong></td></tr>@endif
    <tr><td>{{ $L('created_by') }}</td><td class="r">{{ $facts['bill']['created_by'] }}</td></tr>
</table>

<div class="rule"></div>
<div><strong>{{ $st['lang'] === 'bn' ? '' : $v->en('ms').' ' }}{{ $facts['bill_to']['name'] }}</strong></div>
@if (filled($facts['bill_to']['point']))<div class="small">{{ $L('point') }}: {{ $facts['bill_to']['point'] }}</div>@endif
@if (filled($facts['bill_to']['address']))<div class="small">{{ $facts['bill_to']['address'] }}</div>@endif
@if (filled($facts['bill_to']['phone']))<div class="small">{{ $facts['bill_to']['phone'] }}</div>@endif
@if ($v->shows('transport'))
    <div class="small" data-transport>{{ $L('transport') }}: {{ implode(' · ', array_filter([$facts['transport']['carrier'], $facts['transport']['vehicle'], $facts['transport']['driver_phone']])) }}</div>
@endif

{{-- ── আগে হিসাব (নকশা চাইলে) ────────────────────────────────────────── --}}
@if ($st['summary_first'] && $v->shows('previous_due'))
    <div class="total-box">
        <table class="kv">
            <tr><td>{{ $L('net_payable') }}</td><td class="num">{{ $paper->money($s['net_payable']) }}</td></tr>
            <tr><td>{{ $L('paid') }}</td><td class="num">{{ $paper->money($s['paid']) }}</td></tr>
            <tr><td><strong>{{ $L('total_due') }}</strong></td><td class="num"><strong>{{ $paper->money($s['outstanding']) }}</strong></td></tr>
        </table>
    </div>
@endif

{{-- ── পণ্য ─────────────────────────────────────────────────────────── --}}
<div class="rule"></div>
@if ($st['items'] === 'pos')
    {{--
        ⭐ পিওএস ধাঁচ — বিশ্বের দোকানের চেনা রসিদ: নাম পুরো এক লাইনে, নিচের লাইনে "পরিমাণ × দর … টাকা"।
        ⓘ মোনো অক্ষর চওড়া; তিন কলামের ছকে নাম ২৭মিমিতে ঠেসে দুই-তিন লাইনে ভাঙত (মালিকের ডিফল্ট নকশা, তাই এখানেই)।
    --}}
    <table class="it" data-items-pos>
        <tr>
            <th>{{ $L('product') }}</th>
            <th class="num">{{ $L('amount') }}</th>
        </tr>
        @foreach ($facts['items']['rows'] as $index => $item)
            <tr><td colspan="2" style="padding-bottom: 0"><strong>{{ $index + 1 }}. {{ $item['name'] }}</strong></td></tr>
            <tr>
                <td style="padding-left: 3mm; padding-top: 0">
                    {{ $item['qty'] }} × {{ $paper->money($item['rate']) }}
                    @if ($v->free)<span data-col-free> · {{ $L('free') }} {{ filled($item['free']) ? $item['free'] : '—' }}</span>@endif
                    @if ($v->totalQty && filled($item['free']))<span data-col-total-qty> · {{ $L('total_qty') }} {{ $item['total_qty'] }}</span>@endif
                </td>
                <td class="num" style="padding-top: 0">{{ $paper->money($item['amount']) }}</td>
            </tr>
        @endforeach
        @if ($v->shows('grand_total_row'))
            <tr class="grand" data-grand-row>
                <td>{{ $L('grand_total') }} ({{ $facts['total_items'] }}) · {{ $facts['items']['totals']['qty'] }}@if ($v->free && filled($facts['items']['totals']['free'])) · {{ $L('free') }} {{ $facts['items']['totals']['free'] }}@endif</td>
                <td class="num">{{ $paper->money($facts['items']['totals']['amount']) }}</td>
            </tr>
        @endif
    </table>
@else
<table @class(['it', 'grid' => $st['items'] === 'grid'])>
    <tr>
        <th>{{ $L('product') }}</th>
        <th class="num" style="width: 15mm">{{ $L('qty') }}</th>
        {{-- ⭐ ফ্রি নিজের ঘরে, না থাকলে "—" — মালিক, ৩০ সেপ্টেম্বর ২০২৬: *"Tharmal print e free ullek nai"*।
             ⓘ আগে কেবল নামের নিচে, আর কেবল ফ্রি থাকলে — ফলে বেশির ভাগ রসিদে ফ্রির কথাই থাকত না। --}}
        @if ($v->free)<th class="num" style="width: 11mm" data-col-free>{{ $L('free') }}</th>@endif
        <th class="num" style="width: 19mm">{{ $L('amount') }}</th>
    </tr>
    @foreach ($facts['items']['rows'] as $index => $item)
        <tr>
            <td>{{ $index + 1 }}. {{ $item['name'] }}</td>
            <td class="num">{{ $item['qty'] }}</td>
            @if ($v->free)<td class="num">{{ filled($item['free']) ? $item['free'] : '—' }}</td>@endif
            <td class="num">{{ $paper->money($item['amount']) }}</td>
        </tr>
        @if ($st['items'] !== 'dense' || $v->totalQty)
            <tr class="sub">
                <td colspan="{{ $v->free ? 4 : 3 }}">
                    @if ($st['items'] !== 'dense')@ {{ $paper->money($item['rate']) }}@endif
                    @if ($v->totalQty && filled($item['free']))<span data-col-total-qty> · {{ $L('total_qty') }} {{ $item['total_qty'] }}</span>@endif
                    @php $under = implode(' · ', array_filter([$item['code'] ?? '', $item['lot'] ?? ''])); @endphp
                    @if ($under !== '') · {{ $under }}@endif
                </td>
            </tr>
        @endif
    @endforeach
    @if ($v->shows('grand_total_row'))
        <tr class="grand" data-grand-row>
            <td>{{ $L('grand_total') }} ({{ $facts['total_items'] }})</td>
            <td class="num">{{ $facts['items']['totals']['qty'] }}</td>
            @if ($v->free)<td class="num">{{ filled($facts['items']['totals']['free']) ? $facts['items']['totals']['free'] : '—' }}</td>@endif
            <td class="num">{{ $paper->money($facts['items']['totals']['amount']) }}</td>
        </tr>
    @endif
</table>
@endif

{{-- ── টাকা ─────────────────────────────────────────────────────────── --}}
<div class="rule"></div>
{{--
    ⭐ টাকার সারি এখানেই, ভাগের partial নয় — যে অঙ্ক নিচের মোটের ঘরে (বা উপরের বড় অঙ্কে) বড় করে বসে, সেটা
    এই তালিকায় আর আসে না। ⓘ ধরা পড়েছে, ৩০ সেপ্টেম্বর ২০২৬: পিওএস মানকে "Outstanding Amount" দুইবার ছাপা হত —
    একবার সারিতে, একবার দুই দাগের মাঝে; রোলে এক লাইনও দামি, আর একই কথা দুইবার পড়লে মনে হয় দুইটা আলাদা অঙ্ক।
    ⚠️ সারির ক্রম আর `data-previous-due` চিহ্ন [[invoice-sums]]-এর সেই একই।
--}}
@php
    $shownBig = $st['total'] !== 'plain' || $st['hero'];
    $sl = $st['lang'] === 'bn' ? 'bn' : 'en';
    $sumRow = fn (string $key) => $v->t($key, $sl);
@endphp
<table class="sums">
    <tr><td>{{ $sumRow('grand_total') }}</td><td class="num">{{ $paper->money($s['grand_total']) }}</td></tr>
    <tr><td>{{ $sumRow('discount') }}</td><td class="num">{{ $paper->money($s['discount']) }}</td></tr>
    @if ($v->showVat)<tr><td>{{ $sumRow('vat') }}</td><td class="num">{{ $paper->money($s['vat']) }}</td></tr>@endif
    <tr><td>{{ $sumRow('rounding') }}</td><td class="num">{{ $paper->money($s['rounding']) }}</td></tr>
    <tr class="net"><td>{{ $sumRow('net_payable') }}</td><td class="num">{{ $paper->money($s['net_payable']) }}</td></tr>
    <tr><td>{{ $sumRow('paid') }}</td><td class="num">{{ $paper->money($s['paid']) }}</td></tr>
    @if (! ($shownBig && $headlineLabel === 'invoice_due'))
        <tr><td>{{ $sumRow('invoice_due') }}</td><td class="num">{{ $paper->money($s['invoice_due']) }}</td></tr>
    @endif
    @if ($v->shows('previous_due'))
        <tr data-previous-due><td>{{ $sumRow('previous_due') }}</td><td class="num">{{ $paper->money($s['previous_due']) }}</td></tr>
        @unless ($shownBig)
            <tr class="owed"><td>{{ $sumRow('total_due') }}</td><td class="num">{{ $paper->money($s['outstanding']) }}</td></tr>
        @endunless
    @endif
</table>

@if ($st['total'] !== 'plain')
    <div @class(['total-band' => $st['total'] === 'band', 'total-box' => $st['total'] === 'box' || $st['total'] === 'big', 'total-double' => $st['total'] === 'double'])>
        <table class="kv"><tr>
            <td style="vertical-align: middle; @if ($st['total'] === 'band') color: #fff; @endif"><strong>{{ $v->shows('previous_due') ? $L('total_due') : $L('invoice_due') }}</strong></td>
            <td class="num total-val" style="@if ($st['total'] === 'band') color: #fff; @endif @if ($st['total'] === 'big') font-size: {{ $fs + 9 }}pt; @endif">{{ $paper->money($headline) }}</td>
        </tr></table>
    </div>
@endif

@if ($v->shows('amount_words'))<div class="small" style="margin-top: 1.2mm" data-words>{{ $facts['words'] }}</div>@endif

{{-- ── হিসাবের সারাংশ আর চলাচল (বিবরণী-ধাঁচ) ─────────────────────────── --}}
@if ($st['account'] && $v->shows('previous_due'))
    <div class="rule"></div>
    <div class="pay-head">{{ mb_strtoupper(__('sales::invoice_design.account_summary', [], $st['lang'] === 'bn' ? 'bn' : 'en')) }}</div>
    <table class="kv">
        <tr><td>{{ $L('previous_due') }}</td><td class="num">{{ $paper->money($s['previous_due']) }}</td></tr>
        <tr><td>+ {{ $L('net_payable') }}</td><td class="num">{{ $paper->money($s['net_payable']) }}</td></tr>
        <tr><td>− {{ $L('paid') }}</td><td class="num">{{ $paper->money($s['paid']) }}</td></tr>
        <tr><td><strong>= {{ $L('total_due') }}</strong></td><td class="num"><strong>{{ $paper->money($s['outstanding']) }}</strong></td></tr>
    </table>
@endif
@if ($st['movement'] && $v->shows('previous_due'))
    <div class="rule"></div>
    <div class="pay-head">{{ mb_strtoupper(__('sales::invoice_design.movement', [], 'en')) }}</div>
    <table class="kv" data-movement>
        @foreach ($v->movement($doc->payments) as $row)
            <tr><td class="small">{{ $row['date'] }} {{ $row['text'] }}</td><td class="num small">{{ $row['balance'] }}</td></tr>
        @endforeach
    </table>
@endif

{{-- ── জমা ──────────────────────────────────────────────────────────── --}}
@if ($v->shows('deposits') && $doc->payments !== [])<div class="rule"></div>@endif
{{--
    ⭐ জমা — প্রতিটা এক লাইনে: নম্বর · তারিখ · পথ … টাকা। ⓘ ভাগের চার-কলামের ছক ([[invoice-payments]]) রোলে
    মাথাগুলো দুই-তিন লাইনে ভাঙত ("Transaction / Date")। সুইচ আর `data-deposits`/`data-method` চিহ্ন সেই একই।
--}}
@if ($v->shows('deposits'))
    <div class="pay-head" data-deposits>{{ $v->t('payments_title', $st['lang'] === 'bn' ? 'bn' : 'en') }}</div>
    <table class="kv">
        @foreach ($doc->payments as $row)
            <tr>
                <td class="small">{{ $row['ref'] }} · {{ $row['date'] }} · <span class="bn" data-method>{{ $row['method'] }}</span></td>
                <td class="num small">{{ $row['amount'] }}</td>
            </tr>
        @endforeach
    </table>
@endif

<div class="rule"></div>
<div class="footnote bn">{!! nl2br(e($v->footnoteThermal)) !!}</div>

@if ($st['seal'])
    <table class="seals" style="width: 100%; margin-top: 3mm"><tr>
        @foreach ($v->signatures as $label)
            <td style="width: {{ round(100 / max(1, count($v->signatures)), 1) }}%"><div class="bn" data-signature>{{ $label }}</div></td>
        @endforeach
    </tr></table>
@else
    @include('sales::print.partials.invoice-signatures', ['v' => $v])
@endif

@if (! $st['qr_top'])
    <div class="c" style="margin-top: 2mm">@include('sales::print.partials.invoice-qr', ['v' => $v, 'width' => '22mm'])</div>
@endif

@if ($st['stub'])
    <div class="c small" style="margin-top: 2mm; border-top: 0.4mm dashed #000; padding-top: 1mm">{{ __('sales::invoice_design.cut_here', [], 'bn') }}</div>
    <table class="kv">
        <tr><td><strong>{{ $facts['bill']['bill_no'] }}</strong> · {{ $facts['bill_to']['name'] }}</td></tr>
        <tr><td class="small">{{ $L('total_delivery') }} {{ $facts['items']['totals']['total_qty'] ?: $facts['items']['totals']['qty'] }} · {{ $L('net_payable') }} {{ $paper->money($s['net_payable']) }}</td></tr>
    </table>
    <table class="signatures" style="margin-top: 8mm"><tr>
        <td><div class="sig-line bn">{{ $v->bn('received_by') }}</div></td>
    </tr></table>
@endif

<div class="printed">{{ $v->printedAt() }}</div>
