{{--
    ⓘ A5 রূপ — A4-এর এই partial থেকে তৈরি (mm ×০.৭২, অক্ষর ×০.৮৫, সীমা ৬.৫pt; make_look_a5)। A4-এ বদলালে এটাও নতুন করে বানাতে হয়।
    বিক্রয় আদেশের নকশা-কাঠামো — ২০ নকশা এটাকেই আলাদা সাজে ডাকে, চালান-ভাউচারের সেই একই সাজে ([[PaperLook]])।
    মালিক, ৩০ সেপ্টেম্বর ২০২৬: *"বিক্রয় আদেশ, আদায়ের রসিদ egulo doro"*।

    চাই: $doc (পণ্যের সারি, টাকার সারি, সই — [[SalesPrintController::order()]]), $facts
    ([[OrderPaperFacts::order()]]), $company, $paper, $profile, $settings, আর $look।
    ⓘ QR নেই — আদেশে মাল নড়ে না; স্ক্যান চালান আর বিলের কাজ। ⚠️ নিচে "এটা বিল নয়" লেখা — আদেশের কাগজ
    দেখিয়ে কেউ টাকা চাইলে কাগজটাই না বলে।
--}}
@php
    $look = new \App\Core\Engines\Print\PaperLook($look);
    $lang = $look->lang();
    $t = fn (string $key) => (string) __($key, [], $lang);
    $up = fn (string $key) => mb_strtoupper($t($key));
    $head = \App\Core\Engines\Print\PaperLook::head($company, $profile->shows('logo'));
    $ac = $look->accent();
    $money = $doc->showMoney;
    $card = $look->card();
    $to = $facts['to'];
    $totals = $doc->totals;
    $grandKey = array_key_last($totals);
@endphp

@include('print.partials.look-head-a5', [
    'L' => $look->look, 'head' => $head, 'title' => $up('sales::doc.order'),
    'no' => $facts['no'], 'date' => $facts['date'],
    'labels' => ['no' => $t('core.print.document_no'), 'date' => $t('core.print.date')],
    'qr' => '', 'qrHint' => '',
    'notices' => $doc->notices(),
])

{{-- ── কার · কবে · কোথা থেকে ───────────────────────────────────────── --}}
<table style="width: 100%; margin-top: 3.6mm">
    <tr>
        <td style="width: 44%; vertical-align: top; {{ $card }}" data-order-for>
            <div class="cap" style="color: {{ $look->capColor() }}">{{ $up('sales::paper_design.order_for') }}</div>
            <div style="font-size: 9.3pt; font-weight: bold; margin-top: 0.58mm">{{ $to['name'] }}</div>
            @if ($to['point'] !== '')<div style="font-size: 7.2pt">{{ $t('sales::print.classic.point') }} {{ $to['point'] }}</div>@endif
            @if ($to['address'] !== '')<div style="font-size: 7.2pt" class="muted">{{ $to['address'] }}</div>@endif
            @if ($to['phone'] !== '')<div style="font-size: 7.2pt" class="muted">{{ $to['phone'] }}</div>@endif
        </td>
        <td style="width: 2%"></td>
        <td style="width: 26%; vertical-align: top; {{ $card }}" data-delivery>
            <div class="cap" style="color: {{ $look->capColor() }}">{{ $up('sales::field.deliver_on') }}</div>
            <div style="font-size: 9.3pt; font-weight: bold; margin-top: 0.58mm">{{ $facts['deliver_on'] !== '' ? $facts['deliver_on'] : '—' }}</div>
            @if ($facts['warehouse'] !== '')<div class="cap" style="color: {{ $look->capColor() }}; margin-top: 1.08mm">{{ $up('sales::field.warehouse') }}</div><div style="font-size: 7.6pt">{{ $facts['warehouse'] }}</div>@endif
        </td>
        <td style="width: 2%"></td>
        <td style="vertical-align: top; {{ $card }}">
            <div class="cap" style="color: {{ $look->capColor() }}">{{ $up('sales::paper_design.order_details') }}</div>
            @if ($facts['quotation_no'] !== '')<div style="font-size: 7.2pt; margin-top: 0.58mm">{{ $t('sales::paper_design.quotation_no') }}: <strong>{{ $facts['quotation_no'] }}</strong></div>@endif
            <div style="font-size: 7.2pt; margin-top: 0.58mm">{{ $t('sales::print.classic.created_by') }} {{ $facts['created_by'] }}</div>
            <div style="font-size: 7.2pt">{{ $t('sales::print.classic.total_items') }} {{ $facts['items'] }}</div>
        </td>
    </tr>
</table>

{{-- ── পণ্য ─────────────────────────────────────────────────────────── --}}
<table class="lines">
    <tr>
        <th style="{{ $look->th() }} width: 5.76mm">#</th>
        <th style="{{ $look->th() }}">{{ $up('sales::print.classic.product') }}</th>
        <th class="num" style="{{ $look->th() }} width: 17.28mm">{{ $up('sales::print.classic.qty') }}</th>
        <th class="num" style="{{ $look->th() }} width: 11.52mm">{{ $up('sales::print.classic.free') }}</th>
        @if ($money)
            <th class="num" style="{{ $look->th() }} width: 15.84mm">{{ $up('sales::print.classic.rate') }}</th>
            <th class="num" style="{{ $look->th() }} width: 20.16mm">{{ $up('core.print.amount') }}</th>
        @endif
    </tr>
    @foreach ($doc->lines as $i => $line)
        <tr>
            <td style="{{ $look->td($i) }}" class="muted">{{ $i + 1 }}</td>
            <td style="{{ $look->td($i) }}">
                <div style="font-weight: bold">{{ $line['name'] }}</div>
                @php $under = implode(' · ', array_filter([$line['code'] ?? '', $line['note'] ?? ''])); @endphp
                @if ($under !== '')<div style="font-size: 6.5pt" class="muted">{{ $under }}</div>@endif
            </td>
            <td class="num" style="{{ $look->td($i) }}">{{ $line['qty'] }} <span class="bn">{{ $line['unit'] ?? '' }}</span></td>
            <td class="num" style="{{ $look->td($i) }}">{{ filled($line['free'] ?? '') ? $line['free'] : '—' }}</td>
            @if ($money)
                <td class="num" style="{{ $look->td($i) }}">{{ $line['rate'] }}</td>
                <td class="num" style="{{ $look->td($i) }}">{{ $line['amount'] }}</td>
            @endif
        </tr>
    @endforeach
    <tr class="total">
        <td colspan="2" style="{{ $look->totalRow() }}">{{ $up('core.print.total') }} ({{ $facts['items'] }})</td>
        <td class="num bn" style="{{ $look->totalRow() }} font-family: hindsiliguri">{{ $facts['total_qty'] }}</td>
        <td style="{{ $look->totalRow() }}"></td>
        @if ($money)
            <td style="{{ $look->totalRow() }}"></td>
            <td class="num" style="{{ $look->totalRow() }}">{{ $totals['core.print.subtotal'] ?? '' }}</td>
        @endif
    </tr>
</table>

@if ($money)
    <table style="width: 100%; margin-top: 2.88mm">
        <tr>
            <td style="vertical-align: top; padding-right: 4.32mm; font-size: 7.2pt">
                <span class="cap">{{ $up('core.print.in_words') }}</span><br>{{ $lang === 'bn' ? $facts['words_bn'] : $facts['words'] }}
                @if (filled($doc->narration))<div style="margin-top: 1.44mm"><span class="cap">{{ $up('core.table.narration') }}</span><br>{{ $doc->narration }}</div>@endif
            </td>
            <td style="width: 51.84mm; vertical-align: top">
                {{-- ⓘ টাকার সারি কন্ট্রোলারের ([[SalesPrintController::totals()]]) — শূন্য ছাড়-ভ্যাটের সারি সেখানেই বাদ --}}
                <table style="width: 100%; margin-bottom: 1.44mm">
                    @foreach ($totals as $key => $value)
                        @continue($key === $grandKey)
                        <tr><td style="font-size: 7.6pt; padding: 0.58mm 0">{{ $t($key) }}</td><td class="num" style="font-size: 7.6pt; padding: 0.58mm 0">{{ $value }}</td></tr>
                    @endforeach
                </table>
                {!! $look->amountBox($up($grandKey), (string) $totals[$grandKey]) !!}
            </td>
        </tr>
    </table>
@elseif (filled($doc->narration))
    <div style="margin-top: 2.88mm; font-size: 7.2pt"><span class="cap">{{ $up('core.table.narration') }}</span><br>{{ $doc->narration }}</div>
@endif

<div style="margin-top: 2.88mm; text-align: center; font-size: 6.8pt" class="muted" data-not-a-bill>{{ $t('sales::paper_design.not_a_bill') }}</div>

@php $roles = array_map(fn (string $k) => $t($k), $doc->signatures); @endphp
@if (($look->look['seal'] ?? false) === true)
    <table style="width: 100%; margin-top: 5.76mm" data-signatures>
        <tr>
            @foreach ($roles as $role)
                <td style="width: {{ (int) round(100 / max(count($roles), 1)) }}%; border: 0.22mm solid {{ $look->ink() }}; height: 18.72mm; vertical-align: bottom; text-align: center; font-size: 6.8pt; padding: 1.08mm; font-family: hindsiliguri">{{ $role }}</td>
            @endforeach
        </tr>
    </table>
@else
    <table class="signatures" data-signatures>
        <tr>
            @foreach ($roles as $role)
                <td style="width: {{ (int) round(100 / max(count($roles), 1)) }}%"><table style="width: 100%"><tr><td class="sig-line" style="text-align: center">{{ $role }}</td></tr></table></td>
            @endforeach
        </tr>
    </table>
@endif
