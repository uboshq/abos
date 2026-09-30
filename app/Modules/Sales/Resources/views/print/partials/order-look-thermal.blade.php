{{--
    বিক্রয় আদেশের থার্মাল (৮০মিমি) রূপ — A4-এর [[order-look]]-এর সেই একই ২০ সাজ, সাদা-কালোয় ([[PaperLook]])।
    মালিক, ৩০ সেপ্টেম্বর ২০২৬: *"A4 A5 tharmal tintiroi"*। ⚠️ নিচে "এটা বিল নয়" — আদেশ দেখিয়ে টাকা চাওয়া যায় না।
--}}
@php
    $look = new \App\Core\Engines\Print\PaperLook($look);
    $lang = $look->lang();
    $t = fn (string $key) => (string) __($key, [], $lang);
    $money = $doc->showMoney && $profile->shows('prices'); /* ⛔ নিয়ম আর মালিকের দামের সুইচ দুইটাই — সাধারণ কাগজের মতো ([[document-body]]); ৩০ সেপ্টেম্বর ২০২৬ */
    $to = $facts['to'];
    $totals = $doc->totals;
    $grandKey = array_key_last($totals);
@endphp

@include('print.partials.look-head-thermal', [
    'L' => $look->look, 'head' => \App\Core\Engines\Print\PaperLook::head($company, $profile->shows('logo')),
    'title' => mb_strtoupper($t('sales::doc.order')), 'no' => $facts['no'], 'date' => $facts['date'],
    'labels' => ['no' => $t('core.print.document_no'), 'date' => $t('core.print.date')],
    'notices' => $doc->notices(),
])
<table class="kv">
    <tr><td>{{ $t('sales::field.deliver_on') }}</td><td class="num">{{ $facts['deliver_on'] !== '' ? $facts['deliver_on'] : '—' }}</td></tr>
    @if ($facts['warehouse'] !== '')<tr><td>{{ $t('sales::field.warehouse') }}</td><td class="num" style="font-family: hindsiliguri">{{ $facts['warehouse'] }}</td></tr>@endif
    @if ($facts['quotation_no'] !== '')<tr><td>{{ $t('sales::paper_design.quotation_no') }}</td><td class="num">{{ $facts['quotation_no'] }}</td></tr>@endif
    <tr><td>{{ $t('sales::print.classic.created_by') }}</td><td class="num">{{ $facts['created_by'] }}</td></tr>
</table>

<div class="rule"></div>
<div class="cap">{{ mb_strtoupper($t('sales::paper_design.order_for')) }}</div>
<div data-order-for><strong>{{ $to['name'] }}</strong></div>
@if ($to['point'] !== '')<div class="small">{{ $t('sales::print.classic.point') }} {{ $to['point'] }}</div>@endif
@if ($to['address'] !== '')<div class="small">{{ $to['address'] }}</div>@endif
@if ($to['phone'] !== '')<div class="small">{{ $to['phone'] }}</div>@endif

<div class="rule"></div>
<table class="it">
    <tr>
        <th>{{ $t('sales::print.classic.product') }}</th>
        <th class="num" style="width: 15mm">{{ $t('sales::print.classic.qty') }}</th>
        <th class="num" style="width: 11mm">{{ $t('sales::print.classic.free') }}</th>
        @if ($money)<th class="num" style="width: 19mm">{{ $t('core.print.amount') }}</th>@endif
    </tr>
    @foreach ($doc->lines as $i => $line)
        <tr>
            <td>{{ $i + 1 }}. {{ $line['name'] }}</td>
            <td class="num">{{ $line['qty'] }} <span class="bn">{{ $line['unit'] ?? '' }}</span></td>
            <td class="num">{{ filled($line['free'] ?? '') ? $line['free'] : '—' }}</td>
            @if ($money)<td class="num">{{ $line['amount'] }}</td>@endif
        </tr>
        @php $under = implode(' · ', array_filter([$money ? '@ '.$line['rate'] : '', $line['code'] ?? ''])); @endphp
        @if ($under !== '')<tr class="sub"><td colspan="{{ $money ? 4 : 3 }}">{{ $under }}</td></tr>@endif
    @endforeach
    <tr class="tot">
        <td>{{ $t('core.print.total') }} ({{ $facts['items'] }})</td>
        <td class="num" style="font-family: hindsiliguri">{{ $facts['total_qty'] }}</td>
        <td></td>
        @if ($money)<td class="num">{{ $totals['core.print.subtotal'] ?? '' }}</td>@endif
    </tr>
</table>

@if ($money)
    <table class="kv" style="margin-top: 1mm">
        @foreach ($totals as $key => $value)
            @continue($key === $grandKey)
            <tr><td>{{ $t($key) }}</td><td class="num">{{ $value }}</td></tr>
        @endforeach
    </table>
    <div style="margin-top: 1mm">{!! $look->thermalAmount($t($grandKey), (string) $totals[$grandKey], $lang === 'bn' ? $facts['words_bn'] : $facts['words']) !!}</div>
@endif
@if (filled($doc->narration))<div class="small" style="margin-top: 1mm">{{ $doc->narration }}</div>@endif
<div class="c small" style="margin-top: 1.5mm" data-not-a-bill>{{ $t('sales::paper_design.not_a_bill') }}</div>

<table class="signatures" data-signatures><tr>
    @foreach ($doc->signatures as $k)
        <td style="width: {{ (int) round(100 / max(count($doc->signatures), 1)) }}%"><table style="width: 100%"><tr><td class="sig-line" style="text-align: center">{{ $t($k) }}</td></tr></table></td>
    @endforeach
</tr></table>
