{{--
    আদায়ের রসিদের থার্মাল (৮০মিমি) রূপ — A4-এর [[receipt-look]]-এর সেই একই ২০ সাজ, সাদা-কালোয় ([[PaperLook]])।
    মালিক, ৩০ সেপ্টেম্বর ২০২৬: *"A4 A5 tharmal tintiroi"*। ⓘ কাউন্টারে টাকা নিয়ে হাতে হাতে দেওয়ার কাগজ — বড় অঙ্ক আগে।
--}}
@php
    $look = new \App\Core\Engines\Print\PaperLook($look);
    $lang = $look->lang();
    $t = fn (string $key) => (string) __($key, [], $lang);
    $from = $facts['from'];
@endphp

@include('print.partials.look-head-thermal', [
    'L' => $look->look, 'head' => app(\App\Modules\Sales\Support\InvoicePrintLook::class)->paperHead($company, $profile->shows('logo')),
    'title' => mb_strtoupper($t('sales::paper_design.money_receipt')), 'no' => $facts['no'], 'date' => $facts['date'],
    'labels' => ['no' => $t('core.print.document_no'), 'date' => $t('core.print.date')],
    'notices' => $doc->notices(),
])

<div class="rule"></div>
<div class="cap">{{ mb_strtoupper($t('sales::paper_design.received_from')) }}</div>
<div data-received-from><strong>{{ $from['name'] }}</strong></div>
@if ($from['point'] !== '')<div class="small">{{ $t('sales::print.classic.point') }} {{ $from['point'] }}</div>@endif
@if ($from['phone'] !== '')<div class="small">{{ $from['phone'] }}</div>@endif

<div style="margin-top: 1.5mm">{!! $look->thermalAmount($t('sales::paper_design.amount_received'), $facts['total'], $lang === 'bn' ? $facts['words_bn'] : $facts['words']) !!}</div>

<table class="kv" style="margin-top: 1.5mm" data-payment-details>
    @if ($facts['instrument'] !== '')<tr><td>{{ $t('sales::field.instrument') }}</td><td class="num">{{ ucfirst($facts['instrument']) }}</td></tr>@endif
    @if ($facts['account'] !== '')<tr><td>{{ $t('sales::field.account') }}</td><td class="num" style="font-family: hindsiliguri">{{ $facts['account'] }}</td></tr>@endif
    @if ($facts['instrument_no'] !== '')<tr><td>{{ $t('sales::field.instrument_no') }}</td><td class="num">{{ $facts['instrument_no'] }}</td></tr>@endif
    @if ($facts['instrument_date'] !== '')<tr><td>{{ $t('sales::field.instrument_date') }}</td><td class="num">{{ $facts['instrument_date'] }}</td></tr>@endif
</table>

@if ($facts['bills'] !== [])
    <div class="rule"></div>
    <table class="it" data-bills>
        <tr>
            <th>{{ $t('sales::paper_design.bills_settled') }}</th>
            <th class="num" style="width: 20mm">{{ $t('sales::paper_design.bill_total') }}</th>
            <th class="num" style="width: 20mm">{{ $t('sales::paper_design.applied') }}</th>
        </tr>
        @foreach ($facts['bills'] as $bill)
            <tr><td><strong>{{ $bill['no'] }}</strong> <span class="small">{{ $bill['date'] }}</span></td><td class="num">{{ $bill['bill_total'] }}</td><td class="num">{{ $bill['amount'] }}</td></tr>
        @endforeach
        <tr class="tot"><td colspan="2">{{ $t('core.print.total') }}</td><td class="num">{{ $facts['total'] }}</td></tr>
    </table>
@endif
@if ($facts['narration'] !== '')<div class="small" style="margin-top: 1mm">{{ $facts['narration'] }}</div>@endif

<table class="signatures" data-signatures><tr>
    <td style="width: 30%"></td>
    <td><table style="width: 100%"><tr><td class="sig-line" style="text-align: center">{{ $t($doc->signatures[0] ?? 'core.print.received_by') }}</td></tr></table></td>
    <td style="width: 30%"></td>
</tr></table>
