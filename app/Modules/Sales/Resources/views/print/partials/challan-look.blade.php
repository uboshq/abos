{{--
    ডেলিভারি চালানের নকশা-কাঠামো — ২০ নকশা এটাকেই আলাদা সাজে ডাকে, ভাউচারের সেই একই সাজে ([[PaperLook]])।
    মালিক, ৩০ সেপ্টেম্বর ২০২৬: *"ডেলিভারি চালান EI GULO EKHONI KORO"*।

    চাই: $doc (পণ্যের সারি, সই, দাম দেখানো কি না — [[SalesPrintController::challan()]]), $facts
    ([[ChallanPaperFacts]]), $company, $paper, $profile, $settings, আর $look।
    ⓘ QR: কর্মী স্ক্যান করে ডেলিভারির ধাপ দেন, ডিলার মাল পাওয়া নিশ্চিত করেন — বিলের সেই একই ঠিকানা
    ([[DeliveryScanController]])। ⚠️ দাম বন্ধ (`showMoney` false) হলে দর-টাকার ঘর আর কথায় টাকা যায়।
--}}
@php
    $look = new \App\Core\Engines\Print\PaperLook($look);
    $lang = $look->lang();
    $t = fn (string $key) => (string) __($key, [], $lang);
    $up = fn (string $key) => mb_strtoupper($t($key));
    $head = app(\App\Modules\Sales\Support\InvoicePrintLook::class)->paperHead($company, $profile->shows('logo'));
    $ac = $look->accent();
    $money = $doc->pricesChosen ?? ($doc->showMoney && $profile->shows('prices')); /* ⭐ ছাপার বোতামে বাছা থাকলে সেটাই — টাকাসহ/টাকা ছাড়া (২ অক্টোবর ২০২৬) */
    $sh = fn (string $what) => (bool) ($facts['shows'][$what] ?? true); /* ⭐ চালানের সুইচ (৩ অক্টোবর ২০২৬) */ /* ⛔ নিয়ম আর মালিকের দামের সুইচ দুইটাই — সাধারণ কাগজের মতো ([[document-body]]); ৩০ সেপ্টেম্বর ২০২৬ */
    $cards = $look->look['cards'] ?? 'tint';
    $r = ($look->look['radius'] ?? 0).'mm';
    $cardCss = match ($cards) {
        'line' => 'border-top: 0.8mm solid '.$ac.'; padding: 2.5mm 0;',
        'box' => 'border: 0.3mm solid '.$look->ink().'; padding: 2.5mm 3mm;',
        'brutal' => 'border: 0.7mm solid #000; padding: 2.5mm 3mm;',
        'plain' => 'padding: 1mm 0;',
        default => 'background: '.$look->tint().'; padding: 3mm 3.5mm; border-radius: '.$r.';',
    };
    $look_ = app(\App\Modules\Sales\Support\InvoicePrintLook::class);
    $footnote = $look_->footnote();
    $to = $facts['to'];
    $tr = $facts['transport'];
@endphp

@include('print.partials.look-head', [
    'L' => $look->look, 'head' => $head, 'title' => $up('sales::doc.challan'),
    'no' => $facts['no'], 'date' => $facts['date'],
    'labels' => ['no' => $t('core.print.document_no'), 'date' => $t('core.print.date')],
    'qr' => $look_->shows('qr') && $sh('qr') ? $facts['scan_url'] : '',
    'qrHint' => $t('sales::print.classic.scan_hint'),
    'notices' => $doc->notices(),
])

{{-- ── কাকে · কোন গাড়িতে · কোন আদেশে ────────────────────────────────── --}}
<table style="width: 100%; margin-top: 5mm">
    <tr>
        <td style="width: 38%; vertical-align: top; {{ $cardCss }}" data-ship-to>
            <div class="cap" style="color: {{ $look->capColor() }}">{{ $up('sales::field.ship_to') }}</div>
            <div style="font-size: 11pt; font-weight: bold; margin-top: 0.8mm">{{ $to['name'] }}</div>
            @if ($to['point'] !== '')<div style="font-size: 8.5pt">{{ $t('sales::print.classic.point') }} {{ $to['point'] }}</div>@endif
            @if ($to['address'] !== '')<div style="font-size: 8.5pt" class="muted">{{ $to['address'] }}</div>@endif
            @if ($to['phone'] !== '')<div style="font-size: 8.5pt" class="muted">{{ $to['phone'] }}</div>@endif
        </td>
        <td style="width: 2%"></td>
        <td style="width: 32%; vertical-align: top; {{ $cardCss }}" data-transport>
            <div class="cap" style="color: {{ $look->capColor() }}">{{ $up('sales::field.carrier') }}</div>
            @if ($sh('transport'))
            <div style="font-size: 9.5pt; font-weight: bold; margin-top: 0.8mm">{{ $tr['carrier'] !== '' ? $tr['carrier'] : '—' }}</div>
            @if ($tr['vehicle'] !== '')<div style="font-size: 8.5pt">{{ $t('sales::field.vehicle_no') }}: {{ $tr['vehicle'] }}</div>@endif
            @if ($tr['driver'] !== '' || $tr['driver_phone'] !== '')<div style="font-size: 8.5pt">{{ $t('sales::field.driver_name') }}: {{ trim($tr['driver'].' '.$tr['driver_phone']) }}</div>@endif
            @endif
        </td>
        <td style="width: 2%"></td>
        <td style="vertical-align: top; {{ $cardCss }}">
            @if ($facts['order_no'] !== '' && $sh('order_no'))<div class="cap" style="color: {{ $look->capColor() }}">{{ $up('sales::print.classic.order_no') }}</div><div style="font-size: 9.5pt; font-weight: bold; margin-bottom: 1.2mm">{{ $facts['order_no'] }}</div>@endif
            @if ($facts['warehouse'] !== '')<div class="cap" style="color: {{ $look->capColor() }}">{{ $up('sales::field.warehouse') }}</div><div style="font-size: 9pt; margin-bottom: 1.2mm">{{ $facts['warehouse'] }}</div>@endif
            <div class="cap" style="color: {{ $look->capColor() }}">{{ $up('sales::field.ship_date') }}</div><div style="font-size: 9pt">{{ $facts['ship_date'] }}</div>
        </td>
    </tr>
</table>

{{-- ── পণ্য ─────────────────────────────────────────────────────────── --}}
<table class="lines">
    <tr>
        <th style="{{ $look->th() }} width: 8mm">#</th>
        <th style="{{ $look->th() }}">{{ $up('sales::print.classic.product') }}</th>
        <th class="num" style="{{ $look->th() }} width: 24mm">{{ $up('sales::print.classic.qty') }}</th>
        @if ($sh('free'))<th class="num" style="{{ $look->th() }} width: 16mm">{{ $up('sales::print.classic.free') }}</th>@endif
        @if ($sh('total_qty'))<th class="num" style="{{ $look->th() }} width: 22mm" data-total-qty>{{ $up('sales::print.classic.total_qty') }}</th>@endif
        @if ($money)
            <th class="num" style="{{ $look->th() }} width: 22mm">{{ $up('sales::print.classic.rate') }}</th>
            <th class="num" style="{{ $look->th() }} width: 28mm">{{ $up('core.print.amount') }}</th>
        @endif
    </tr>
    @foreach ($doc->lines as $i => $line)
        <tr>
            <td style="{{ $look->td($i) }}" class="muted">{{ $i + 1 }}</td>
            <td style="{{ $look->td($i) }}">
                <div style="font-weight: bold">{{ $line['name'] }}</div>
                @php $under = implode(' · ', array_filter([$line['code'] ?? '', $line['note'] ?? ''])); @endphp
                @if ($under !== '')<div style="font-size: 7.5pt" class="muted">{{ $under }}</div>@endif
            </td>
            <td class="num" style="{{ $look->td($i) }}">{{ $line['qty'] }} <span class="bn">{{ $line['unit'] ?? '' }}</span></td>
            @if ($sh('free'))<td class="num" style="{{ $look->td($i) }}">{{ $line['free'] ?? '' }}</td>@endif
            @if ($sh('total_qty'))<td class="num" style="{{ $look->td($i) }}">{{ $line['total_qty'] ?? '' }} <span class="bn">{{ $line['unit'] ?? '' }}</span></td>@endif
            @if ($money)
                <td class="num" style="{{ $look->td($i) }}">{{ $line['rate'] }}</td>
                <td class="num" style="{{ $look->td($i) }}">{{ $line['amount'] }}</td>
            @endif
        </tr>
    @endforeach
    <tr class="total">
        <td colspan="2" style="{{ $look->totalRow() }}">{{ $up('core.print.total') }} ({{ $facts['items'] }})</td>
        <td class="num bn" style="{{ $look->totalRow() }} font-family: hindsiliguri">{{ $facts['total_qty'] }}</td>
        @if ($sh('free'))<td style="{{ $look->totalRow() }}"></td>@endif
        @if ($sh('total_qty'))<td class="num bn" style="{{ $look->totalRow() }} font-family: hindsiliguri" data-total-qty-sum>{{ $facts['total_qty_with_free'] ?? '' }}</td>@endif
        @if ($money)
            <td style="{{ $look->totalRow() }}"></td>
            <td class="num" style="{{ $look->totalRow() }}">{{ $facts['lines_total'] ?? $facts['total'] }}</td>
        @endif
    </tr>
</table>

@if ($money && ! empty($facts['money_rows']))
    <table style="width: 100%; margin-top: 2mm" data-money-rows>
        @foreach ($facts['money_rows'] as $label => $value)
            <tr><td style="text-align: right; font-size: 9pt; padding: 0.6mm 2mm;">{{ $t($label) }}</td><td class="num" style="width: 34mm; font-size: 9pt; padding: 0.6mm 2mm;">{{ $value }}</td></tr>
        @endforeach
    </table>
@endif

@if ($money)
    <table style="width: 100%; margin-top: 4mm">
        <tr>
            <td style="vertical-align: top; padding-right: 6mm; font-size: 8.5pt">
                <span class="cap">{{ $up('core.print.in_words') }}</span><br>{{ $lang === 'bn' ? $facts['words_bn'] : $facts['words'] }}
                @if (filled($doc->narration))<div style="margin-top: 2mm"><span class="cap">{{ $up('core.table.narration') }}</span><br>{{ $doc->narration }}</div>@endif
            </td>
            <td style="width: 70mm; vertical-align: top">{!! $look->amountBox($up('core.print.total'), $facts['total']) !!}</td>
        </tr>
    </table>
@elseif (filled($doc->narration))
    <div style="margin-top: 4mm; font-size: 8.5pt"><span class="cap">{{ $up('core.table.narration') }}</span><br>{{ $doc->narration }}</div>
@endif

@if ($footnote !== '')<div style="margin-top: 4mm; text-align: center; font-weight: bold; font-family: hindsiliguri; font-size: 9pt; color: #b42318">{!! nl2br(e($footnote)) !!}</div>@endif

@php
    $roles = array_map(fn (string $k) => $t($k), $doc->signatures);
    $qrUrl = $look_->shows('qr') ? $facts['scan_url'] : '';
    // ⭐ পট্টি-মাথার নকশায় QR সইয়ের সারিতে, "Received by"-এর ডানে ([[PaperLook::qrAtSignatures()]])
    $qrHere = $look->qrAtSignatures() && $qrUrl !== '';
    $share = (int) round(($qrHere ? 80 : 100) / max(count($roles), 1));
@endphp
@if (($look->look['seal'] ?? false) === true)
    <table style="width: 100%; margin-top: 8mm" data-signatures>
        <tr>
            @foreach ($roles as $role)
                <td style="width: {{ $share }}%; border: 0.3mm solid {{ $look->ink() }}; height: 26mm; vertical-align: bottom; text-align: center; font-size: 8pt; padding: 1.5mm; font-family: hindsiliguri">{{ $role }}</td>
            @endforeach
            @if ($qrHere)
                <td style="width: 20%; text-align: center; vertical-align: bottom; border: 0; height: auto" data-scan-qr>
                    <img src="data:image/svg+xml;base64,{{ base64_encode(\App\Core\Support\QrCode::svg($qrUrl, scale: 4, quiet: 2)) }}" style="width: 22mm; height: 22mm;" alt="">
                    <div style="font-size: 6.5pt; color: #667085">{{ $t('sales::print.classic.scan_hint') }}</div>
                </td>
            @endif
        </tr>
    </table>
@else
    <table class="signatures" data-signatures>
        <tr>
            @foreach ($roles as $role)
                <td style="width: {{ $share }}%"><table style="width: 100%"><tr><td class="sig-line" style="text-align: center">{{ $role }}</td></tr></table></td>
            @endforeach
            @if ($qrHere)
                <td style="width: 20%; text-align: center; vertical-align: bottom; border: 0; height: auto" data-scan-qr>
                    <img src="data:image/svg+xml;base64,{{ base64_encode(\App\Core\Support\QrCode::svg($qrUrl, scale: 4, quiet: 2)) }}" style="width: 22mm; height: 22mm;" alt="">
                    <div style="font-size: 6.5pt; color: #667085">{{ $t('sales::print.classic.scan_hint') }}</div>
                </td>
            @endif
        </tr>
    </table>
@endif
