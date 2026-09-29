{{--
    আদায়ের রসিদের নকশা-কাঠামো — ২০ নকশা এটাকেই আলাদা সাজে ডাকে, চালান-ভাউচারের সেই একই সাজে ([[PaperLook]])।
    মালিক, ৩০ সেপ্টেম্বর ২০২৬: *"বিক্রয় আদেশ, আদায়ের রসিদ egulo doro"*।

    চাই: $doc (সই, নোটিশ — [[SalesPrintController::receipt()]]), $facts ([[OrderPaperFacts::receipt()]]), $company,
    $paper, $profile, $settings, আর $look।
    ⓘ প্রথম প্রশ্ন "কত টাকা পেলেন" — তাই বড় অঙ্ক উপরে, কথায় লেখা নিচে; তারপর কীভাবে এল আর কোন বিলে বসল।
--}}
@php
    $look = new \App\Core\Engines\Print\PaperLook($look);
    $lang = $look->lang();
    $t = fn (string $key) => (string) __($key, [], $lang);
    $up = fn (string $key) => mb_strtoupper($t($key));
    $head = \App\Core\Engines\Print\PaperLook::head($company, $profile->shows('logo'));
    $ac = $look->accent();
    $card = $look->card();
    $from = $facts['from'];
@endphp

@include('print.partials.look-head', [
    'L' => $look->look, 'head' => $head, 'title' => $up('sales::paper_design.money_receipt'),
    'no' => $facts['no'], 'date' => $facts['date'],
    'labels' => ['no' => $t('core.print.document_no'), 'date' => $t('core.print.date')],
    'qr' => '', 'qrHint' => '',
    'notices' => $doc->notices(),
])

{{-- ── কার কাছ থেকে · কত ──────────────────────────────────────────────── --}}
<table style="width: 100%; margin-top: 6mm">
    <tr>
        <td style="vertical-align: top; {{ $card }}" data-received-from>
            <div class="cap" style="color: {{ $look->capColor() }}">{{ $up('sales::paper_design.received_from') }}</div>
            <div style="font-size: 12pt; font-weight: bold; margin-top: 0.8mm">{{ $from['name'] }}</div>
            @if ($from['point'] !== '')<div style="font-size: 8.5pt">{{ $t('sales::print.classic.point') }} {{ $from['point'] }}</div>@endif
            @if ($from['address'] !== '')<div style="font-size: 8.5pt" class="muted">{{ $from['address'] }}</div>@endif
            @if ($from['phone'] !== '')<div style="font-size: 8.5pt" class="muted">{{ $from['phone'] }}</div>@endif
        </td>
        <td style="width: 4%"></td>
        <td style="width: 52%; vertical-align: top">
            {!! $look->amountBox($up('sales::paper_design.amount_received'), $facts['total'], $lang === 'bn' ? $facts['words_bn'] : $facts['words']) !!}
        </td>
    </tr>
</table>

{{-- ── কীভাবে এল ─────────────────────────────────────────────────────── --}}
<table style="width: 100%; margin-top: 4mm" data-payment-details>
    <tr>
        <td style="vertical-align: top; {{ $card }}">
            <div class="cap" style="color: {{ $look->capColor() }}">{{ $up('sales::paper_design.payment_details') }}</div>
            <table style="width: 100%; margin-top: 1mm">
                <tr>
                    @if ($facts['instrument'] !== '')<td style="font-size: 9pt; padding-right: 5mm"><span class="muted">{{ $t('sales::field.instrument') }}</span><br><strong>{{ ucfirst($facts['instrument']) }}</strong></td>@endif
                    @if ($facts['account'] !== '')<td style="font-size: 9pt; padding-right: 5mm"><span class="muted">{{ $t('sales::field.account') }}</span><br><strong>{{ $facts['account'] }}</strong></td>@endif
                    @if ($facts['instrument_no'] !== '')<td style="font-size: 9pt; padding-right: 5mm"><span class="muted">{{ $t('sales::field.instrument_no') }}</span><br><strong>{{ $facts['instrument_no'] }}</strong></td>@endif
                    @if ($facts['instrument_date'] !== '')<td style="font-size: 9pt"><span class="muted">{{ $t('sales::field.instrument_date') }}</span><br><strong>{{ $facts['instrument_date'] }}</strong></td>@endif
                </tr>
            </table>
        </td>
    </tr>
</table>

{{-- ── কোন বিলে বসল ──────────────────────────────────────────────────── --}}
@if ($facts['bills'] !== [])
    <table class="lines" data-bills>
        <tr>
            <th style="{{ $look->th() }} width: 8mm">#</th>
            <th style="{{ $look->th() }}">{{ $up('sales::paper_design.bills_settled') }}</th>
            <th style="{{ $look->th() }} width: 28mm">{{ $up('core.print.date') }}</th>
            <th class="num" style="{{ $look->th() }} width: 32mm">{{ $up('sales::paper_design.bill_total') }}</th>
            <th class="num" style="{{ $look->th() }} width: 32mm">{{ $up('sales::paper_design.applied') }}</th>
        </tr>
        @foreach ($facts['bills'] as $i => $bill)
            <tr>
                <td style="{{ $look->td($i) }}" class="muted">{{ $i + 1 }}</td>
                <td style="{{ $look->td($i) }} font-weight: bold">{{ $bill['no'] }}</td>
                <td style="{{ $look->td($i) }}">{{ $bill['date'] }}</td>
                <td class="num" style="{{ $look->td($i) }}">{{ $bill['bill_total'] }}</td>
                <td class="num" style="{{ $look->td($i) }}">{{ $bill['amount'] }}</td>
            </tr>
        @endforeach
        <tr class="total">
            <td colspan="4" style="{{ $look->totalRow() }}">{{ $up('core.print.total') }}</td>
            <td class="num" style="{{ $look->totalRow() }}">{{ $facts['total'] }}</td>
        </tr>
    </table>
@endif

@if ($facts['narration'] !== '')<div style="margin-top: 4mm; font-size: 8.5pt"><span class="cap">{{ $up('core.table.narration') }}</span><br>{{ $facts['narration'] }}</div>@endif

@php $roles = array_map(fn (string $k) => $t($k), $doc->signatures); @endphp
@if (($look->look['seal'] ?? false) === true)
    <table style="width: 100%; margin-top: 8mm" data-signatures>
        <tr>
            @foreach ($roles as $role)
                <td style="width: {{ (int) round(100 / max(count($roles), 1)) }}%; border: 0.3mm solid {{ $look->ink() }}; height: 26mm; vertical-align: bottom; text-align: center; font-size: 8pt; padding: 1.5mm; font-family: hindsiliguri">{{ $role }}</td>
            @endforeach
        </tr>
    </table>
@else
    <table class="signatures" data-signatures>
        <tr>
            <td style="width: 50%"></td>
            @foreach ($roles as $role)
                <td style="width: {{ (int) round(50 / max(count($roles), 1)) }}%"><table style="width: 100%"><tr><td class="sig-line" style="text-align: center">{{ $role }}</td></tr></table></td>
            @endforeach
        </tr>
    </table>
@endif
