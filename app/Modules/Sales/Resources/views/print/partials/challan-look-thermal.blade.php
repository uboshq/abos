{{--
    ডেলিভারি চালানের থার্মাল (৮০মিমি) রূপ — A4-এর [[challan-look]]-এর সেই একই ২০ সাজ, সাদা-কালোয় ([[PaperLook]])।
    মালিক, ৩০ সেপ্টেম্বর ২০২৬: *"A4 A5 tharmal tintiroi"*। ⓘ QR নিচে, সইয়ের পরে — স্ক্যান করে কর্মী ধাপ দেন,
    ডিলার মাল পাওয়া নিশ্চিত করেন ([[DeliveryScanController]])। ⚠️ দাম বন্ধ হলে দর-টাকা যায়।
--}}
@php
    $look = new \App\Core\Engines\Print\PaperLook($look);
    $lang = $look->lang();
    $t = fn (string $key) => (string) __($key, [], $lang);
    $money = $doc->pricesChosen ?? ($doc->showMoney && $profile->shows('prices')); /* ⭐ ছাপার বোতামে বাছা থাকলে সেটাই — টাকাসহ/টাকা ছাড়া (২ অক্টোবর ২০২৬) */
    $sh = fn (string $what) => (bool) ($facts['shows'][$what] ?? true); /* ⭐ চালানের সুইচ (৩ অক্টোবর ২০২৬) */ /* ⛔ নিয়ম আর মালিকের দামের সুইচ দুইটাই — সাধারণ কাগজের মতো ([[document-body]]); ৩০ সেপ্টেম্বর ২০২৬ */
    $look_ = app(\App\Modules\Sales\Support\InvoicePrintLook::class);
    $qrUrl = $look_->shows('qr') && $sh('qr') ? $facts['scan_url'] : '';
    $cols = 2 + ($sh('free') ? 1 : 0) + ($sh('total_qty') ? 1 : 0) + ($money ? 1 : 0);
    $to = $facts['to'];
    $tr = $facts['transport'];
@endphp

@include('print.partials.look-head-thermal', [
    'L' => $look->look, 'head' => \App\Core\Engines\Print\PaperLook::head($company, $profile->shows('logo')),
    'title' => mb_strtoupper($t('sales::doc.challan')), 'no' => $facts['no'], 'date' => $facts['date'],
    'labels' => ['no' => $t('core.print.document_no'), 'date' => $t('core.print.date')],
    'notices' => $doc->notices(),
])
<table class="kv">
    @if ($facts['order_no'] !== '' && $sh('order_no'))<tr><td>{{ $t('sales::print.classic.order_no') }}</td><td class="num">{{ $facts['order_no'] }}</td></tr>@endif
    @if ($facts['warehouse'] !== '')<tr><td>{{ $t('sales::field.warehouse') }}</td><td class="num" style="font-family: hindsiliguri">{{ $facts['warehouse'] }}</td></tr>@endif
</table>

<div class="rule"></div>
<div class="cap">{{ mb_strtoupper($t('sales::field.ship_to')) }}</div>
<div data-ship-to><strong>{{ $to['name'] }}</strong></div>
@if ($to['point'] !== '')<div class="small">{{ $t('sales::print.classic.point') }} {{ $to['point'] }}</div>@endif
@if ($to['address'] !== '')<div class="small">{{ $to['address'] }}</div>@endif
@if ($to['phone'] !== '')<div class="small">{{ $to['phone'] }}</div>@endif
@if ($sh('transport') && ($tr['carrier'] !== '' || $tr['vehicle'] !== ''))
    <div class="small" data-transport>{{ implode(' · ', array_filter([$tr['carrier'], $tr['vehicle'], trim($tr['driver'].' '.$tr['driver_phone'])])) }}</div>
@endif

<div class="rule"></div>
<table class="it">
    <tr>
        <th>{{ $t('sales::print.classic.product') }}</th>
        <th class="num" style="width: 15mm">{{ $t('sales::print.classic.qty') }}</th>
        @if ($sh('free'))<th class="num" style="width: 10mm">{{ $t('sales::print.classic.free') }}</th>@endif
        @if ($sh('total_qty'))<th class="num" style="width: 14mm" data-total-qty>{{ $t('sales::print.classic.total_qty') }}</th>@endif
        @if ($money)<th class="num" style="width: 19mm">{{ $t('core.print.amount') }}</th>@endif
    </tr>
    @foreach ($doc->lines as $i => $line)
        <tr>
            <td>{{ $i + 1 }}. {{ $line['name'] }}</td>
            <td class="num">{{ $line['qty'] }} <span class="bn">{{ $line['unit'] ?? '' }}</span></td>
            @if ($sh('free'))<td class="num">{{ filled($line['free'] ?? '') ? $line['free'] : '—' }}</td>@endif
            @if ($sh('total_qty'))<td class="num">{{ $line['total_qty'] ?? '' }}</td>@endif
            @if ($money)<td class="num">{{ $line['amount'] }}</td>@endif
        </tr>
        @php $under = implode(' · ', array_filter([$money ? '@ '.$line['rate'] : '', $line['code'] ?? '', $line['note'] ?? ''])); @endphp
        @if ($under !== '')<tr class="sub"><td colspan="{{ $cols }}">{{ $under }}</td></tr>@endif
    @endforeach
    <tr class="tot">
        <td>{{ $t('core.print.total') }} ({{ $facts['items'] }})</td>
        <td class="num" style="font-family: hindsiliguri">{{ $facts['total_qty'] }}</td>
        @if ($sh('free'))<td></td>@endif
        @if ($sh('total_qty'))<td class="num" style="font-family: hindsiliguri" data-total-qty-sum>{{ $facts['total_qty_with_free'] ?? '' }}</td>@endif
        @if ($money)<td class="num">{{ $facts['lines_total'] ?? $facts['total'] }}</td>@endif
    </tr>
</table>
@if ($money && ! empty($facts['money_rows']))
    <table style="width: 100%; margin-top: 2mm" data-money-rows>
        @foreach ($facts['money_rows'] as $label => $value)
            <tr><td style="text-align: right; font-size: 7.5pt; padding: 0.3mm 0;">{{ $t($label) }}</td><td class="num" style="width: 34mm; font-size: 7.5pt; padding: 0.3mm 0;">{{ $value }}</td></tr>
        @endforeach
    </table>
@endif

@if ($money)
    <div style="margin-top: 1.5mm">{!! $look->thermalAmount($t('core.print.total'), $facts['total'], $lang === 'bn' ? $facts['words_bn'] : $facts['words']) !!}</div>
@endif
@if (filled($doc->narration))<div class="small" style="margin-top: 1mm">{{ $doc->narration }}</div>@endif
@if ($look_->footnote() !== '')<div class="c bn" style="font-weight: bold; font-size: 7.5pt; margin-top: 1.5mm">{{ $look_->footnote() }}</div>@endif

<table class="signatures" data-signatures><tr>
    @foreach ($doc->signatures as $k)
        <td style="width: {{ (int) round(100 / max(count($doc->signatures), 1)) }}%"><table style="width: 100%"><tr><td class="sig-line" style="text-align: center">{{ $t($k) }}</td></tr></table></td>
    @endforeach
</tr></table>

@if ($qrUrl !== '')
    <div class="c" style="margin-top: 2mm" data-scan-qr>
        <img src="data:image/svg+xml;base64,{{ base64_encode(\App\Core\Support\QrCode::svg($qrUrl, scale: 4, quiet: 2)) }}" style="width: 22mm; height: 22mm;" alt="">
        <div class="small bn">{{ $t('sales::print.classic.scan_hint') }}</div>
    </div>
@endif
