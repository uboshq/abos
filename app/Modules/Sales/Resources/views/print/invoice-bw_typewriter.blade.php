{{--
    ১২ · সাদা-কালো টাইপরাইটার — মালিকের নির্দেশ, ৩০ সেপ্টেম্বর ২০২৬: *"Black & White template ro 3 ti koro"*।

    ⓘ সব অক্ষর সমান চওড়া (DejaVu Sans Mono), ড্যাশের দাগ, মাঝখানে "* INVOICE *" — পুরনো
    টাইপরাইটার বা ডট-ম্যাট্রিক্স বিলের চেহারা, কোনো রঙ বা ভরাট নেই। নমুনা: Design canvas, ১২।

    ── ⚠️ তথ্য আর সুইচ, দুইটাই অন্যের ─────────────────────────────────
    সব লেখা আর অঙ্ক [[SalesPrintController::classicFacts()]]-এর `$facts` থেকে; কী ছাপা হবে তা কেবল
    [[InvoicePrintLook]] বলে। ⛔ চাবির নাম এখানে লেখা হয় না, কোনো অঙ্ক এখানে গোনা হয় না।
    `data-*` চিহ্ন ক্লাসিকের নামেই।

    ⓘ বাংলা অক্ষর (সইয়ের নাম, ফুটনোট) monospace-এ নেই — ওগুলো hindsiliguri-তে থাকে।
--}}
@php
    $en = fn (string $key, array $replace = []) => __('sales::print.classic.'.$key, $replace, 'en');
    $label = fn (string $key) => mb_strtoupper(rtrim($en($key), ':,'));

    $look = app(\App\Modules\Sales\Support\InvoicePrintLook::class);
    $head = $look->header($company);
    $show = fn (string $what) => $look->shows($what);
    $signatures = $look->signatures();
    $footnote = $look->footnote();

    /*
     * ⭐ স্ক্যানের QR — মালিক, ৩০ সেপ্টেম্বর ২০২৬: কর্মী স্ক্যান করে ডেলিভারির পরের ধাপ দেন, ডিলার
     * স্ক্যান করে নিজের হিসাব-বিল দেখে মাল পাওয়া নিশ্চিত করেন ([[DeliveryScanController]])।
     * ⓘ সুইচ [[InvoicePrintLook]]-এর 'qr'; ঠিকানা `$facts['scan_url']` — খালি হলে কিছুই আঁকা হয় না।
     * ⚠️ `SHOWS`-এ 'qr' না থাকা পর্যন্ত (অন্য কমিটে আসছে) জিজ্ঞাসাই করা হয় না — অঘোষিত চাবি পড়লে
     * SettingsService ব্যতিক্রম ছোড়ে, আর তখন গোটা বিলের ছাপা ভাঙত।
     */
    $qr = in_array('qr', \App\Modules\Sales\Support\InvoicePrintLook::SHOWS, true) && $show('qr')
        ? trim((string) ($facts['scan_url'] ?? ''))
        : '';

    // ⭐ নম্বরসহ লেখা ("DUPLICATE — Print No. 3"), চেনা শুরুর শব্দে — [[PrintableDocument::duplicateNotice()]]
    $duplicate = $doc->duplicateNotice() ?? "\0";
    $notices = $doc->notices();
    $isDuplicate = in_array($duplicate, $notices, true);
    $loud = array_values(array_filter($notices, fn (string $n) => $n !== $duplicate));

    $sums = $facts['sums'];
    $showVat = bccomp(str_replace(',', '', $sums['vat']), '0', 4) !== 0;
    $showFree = $show('free');
    $showTotalQty = $show('total_qty');

    $logo = $profile->shows('logo') ? $company->logoData() : null;

    $taxIds = $show('bin') ? trim(implode('   ', array_filter([
        filled($company->bin) ? $en('bin').' '.$company->bin : null,
        filled($company->tin) ? $en('tin').' '.$company->tin : null,
    ]))) : '';
@endphp

<style @nonce>
    body { font-family: dejavusansmono, monospace; font-size: 8.5pt; color: #111; }
    table { border-collapse: collapse; }
    .bn { font-family: hindsiliguri, sans-serif; }

    .center { text-align: center; }
    .co-name { font-size: 16pt; font-weight: bold; letter-spacing: 1.5mm; }
    .co-meta { font-size: 8pt; }
    .banner { margin-top: 4mm; border-top: 0.5mm dashed #111; border-bottom: 0.5mm dashed #111; padding: 2mm 0; text-align: center; font-size: 12pt; font-weight: bold; letter-spacing: 2mm; }
    .dup { text-align: center; font-size: 8pt; font-weight: bold; margin-top: 1mm; }
    .notice { text-align: center; font-weight: bold; border: 0.5mm dashed #111; padding: 2mm; margin-top: 3mm; font-size: 10.5pt; }

    table.facts { width: 100%; margin-top: 4mm; }
    table.facts td { vertical-align: top; font-size: 8.5pt; line-height: 1.5; }

    table.lines { width: 100%; margin-top: 4mm; }
    table.lines th { border-top: 0.3mm solid #111; border-bottom: 0.3mm solid #111; font-size: 7.5pt; font-weight: bold; padding: 1.4mm 1mm; text-align: left; }
    table.lines td { padding: 1.3mm 1mm; font-size: 8.5pt; vertical-align: top; }
    table.lines tr.grand td { border-top: 0.3mm dashed #111; font-weight: bold; }
    table.lines th.num { text-align: right; }

    .num { text-align: right; white-space: nowrap; }

    table.sums { width: 88mm; margin-top: 4mm; margin-left: auto; }
    table.sums td { padding: 0.9mm 0; font-size: 9pt; }
    table.sums tr.owed td { border-top: 0.5mm solid #111; border-bottom: 0.5mm solid #111; font-weight: bold; font-size: 10pt; padding: 1.6mm 0; }

    .line { margin-top: 2mm; font-size: 8.5pt; }
    table.pay { width: 100%; margin-top: 1mm; }
    table.pay td { font-size: 8pt; padding: 0.6mm 1mm 0.6mm 0; }

    .footnote { margin-top: 4mm; text-align: center; font-weight: bold; font-size: 9pt; }

    .signatures { width: 100%; margin-top: 16mm; }
    .signatures td { text-align: center; font-size: 9pt; padding: 0 5mm; }
    .sig-line { border-top: 0.3mm dashed #111; padding-top: 1mm; }

    .printed { margin-top: 5mm; text-align: center; font-size: 7.5pt; }
</style>

{{-- ⭐ লোগো বাঁয়ে — মালিক, ৩০ সেপ্টেম্বর ২০২৬; নাম মাঝখানেই থাকে (ডানের ফাঁকা ঘর ভারসাম্য রাখে) --}}
<table style="width: 100%"><tr>
<td style="width: 30mm; vertical-align: middle">@if ($logo)<img src="{{ $logo }}" style="height: 14mm;" alt="">@endif</td>
<td style="vertical-align: middle">
<div class="center">
    <div class="co-name">{{ mb_strtoupper($head['name']) }}</div>
    @if ($head['address'] !== '')
        <div class="co-meta" data-head-address>{{ $head['address'] }}</div>
    @endif
    @if ($head['phone'] !== '' || $head['email'] !== '')
        <div class="co-meta">{{ implode(' · ', array_filter([$head['phone'], $head['email'], $head['website']])) }}</div>
    @endif
    @if ($taxIds !== '')
        <div class="co-meta" data-tax-ids>{{ $taxIds }}</div>
    @endif
</div>
</td>
<td style="width: 30mm"></td>
</tr></table>

<div class="banner">* {{ $en('heading') }} *</div>
@if ($isDuplicate && $show('duplicate'))
    <div class="dup" data-duplicate>-- {{ $duplicate }} --</div>
@endif

@if ($loud !== [])
    <div class="notice">{{ implode(' · ', $loud) }}</div>
@endif

<table class="facts">
    <tr>
        <td style="width: 50%">
            <div>{{ str_pad($label('bill_no'), 13, '.') }} {{ $facts['bill']['bill_no'] }}</div>
            <div>{{ str_pad($label('bill_date'), 13, '.') }} {{ $facts['bill']['bill_date'] }}</div>
            @if ($show('order_no'))
                <div data-order-no>{{ str_pad($label('order_no'), 13, '.') }} {{ $facts['bill']['order_no'] }}</div>
            @endif
            @if ($show('invoice_type'))
                <div data-invoice-type>{{ str_pad($label('type'), 13, '.') }} {{ $facts['bill']['type'] }}</div>
            @endif
            <div>{{ str_pad($label('created_by'), 13, '.') }} {{ $facts['bill']['created_by'] }}</div>
        </td>
        <td>
            <div>{{ $label('bill_to') }}: {{ $en('ms') }} {{ $facts['bill_to']['name'] }}</div>
            @if (filled($facts['bill_to']['point']))<div>{{ $label('point') }}: {{ $facts['bill_to']['point'] }}</div>@endif
            @if (filled($facts['bill_to']['phone']))<div>{{ $label('phone') }}: {{ $facts['bill_to']['phone'] }}</div>@endif
            @if (filled($facts['bill_to']['address']))<div>{{ $facts['bill_to']['address'] }}</div>@endif
            @if ($show('transport'))
                <div data-transport>
                    @if (filled($facts['transport']['carrier']))<div>{{ $label('carrier') }}: {{ $facts['transport']['carrier'] }}</div>@endif
                    @if (filled($facts['transport']['vehicle']))<div>{{ $label('vehicle') }}: {{ $facts['transport']['vehicle'] }}</div>@endif
                    @if (filled($facts['transport']['driver_phone']))<div>{{ $label('driver_phone') }}: {{ $facts['transport']['driver_phone'] }}</div>@endif
                </div>
            @endif
        </td>
    </tr>
</table>

<table class="lines">
    <thead>
        <tr>
            <th style="width: 8mm">{{ $label('sl') }}</th>
            <th>{{ $label('product') }}</th>
            <th class="num" style="width: 26mm">{{ $label('rate') }}</th>
            <th class="num" style="width: 18mm">{{ $label('qty') }}</th>
            @if ($showFree)<th class="num" style="width: 15mm" data-col-free>{{ $label('free') }}</th>@endif
            @if ($showTotalQty)<th class="num" style="width: 20mm" data-col-total-qty>{{ $label('total_qty') }}</th>@endif
            <th class="num" style="width: 29mm">{{ $label('amount') }}</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($facts['items']['rows'] as $index => $item)
            <tr>
                <td>{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</td>
                <td>
                    {{ $item['name'] }}
                    {{-- ⓘ কোড · লট — বিলের সুইচ মেনে (মালিক, ৩ অক্টোবর ২০২৬) --}}
                    @include('sales::print.partials.item-code-lot', ['item' => $item, 'style' => 'font-size: 7.5pt'])
                </td>
                <td class="num">{{ $paper->money($item['rate']) }}</td>
                <td class="num">{{ $item['qty'] }}</td>
                @if ($showFree)<td class="num">{{ filled($item['free']) ? $item['free'] : '-' }}</td>@endif
                @if ($showTotalQty)<td class="num">{{ $item['total_qty'] }}</td>@endif
                <td class="num">{{ $paper->money($item['amount']) }}</td>
            </tr>
        @endforeach

        @if ($show('grand_total_row'))
            <tr class="grand" data-grand-row>
                <td></td>
                <td>{{ mb_strtoupper($en('grand_total')) }}</td>
                <td></td>
                <td class="num">{{ $facts['items']['totals']['qty'] }}</td>
                @if ($showFree)<td class="num">{{ $facts['items']['totals']['free'] }}</td>@endif
                @if ($showTotalQty)<td class="num">{{ $facts['items']['totals']['total_qty'] }}</td>@endif
                <td class="num">{{ $paper->money($facts['items']['totals']['amount']) }}</td>
            </tr>
        @endif
    </tbody>
</table>

<div class="line">{{ $label('total_items') }}: {{ $facts['total_items'] }} &nbsp; {{ $label('total_delivery') }}: {{ $facts['total_delivery'] }}</div>

<table class="sums">
    <tr><td>{{ $label('grand_total') }}</td><td class="num">{{ $paper->money($sums['grand_total']) }}</td></tr>
    <tr><td>{{ $label('discount') }}</td><td class="num">{{ $paper->money($sums['discount']) }}</td></tr>
    @if ($showVat)
        <tr><td>{{ $label('vat') }}</td><td class="num">{{ $paper->money($sums['vat']) }}</td></tr>
    @endif
    <tr><td>{{ $label('rounding') }}</td><td class="num">{{ $paper->money($sums['rounding']) }}</td></tr>
    <tr><td><strong>{{ $label('net_payable') }}</strong></td><td class="num"><strong>{{ $paper->money($sums['net_payable']) }}</strong></td></tr>
    <tr><td>{{ $label('paid') }}</td><td class="num">{{ $paper->money($sums['paid']) }}</td></tr>
    <tr><td>{{ $label('invoice_due') }}</td><td class="num">{{ $paper->money($sums['invoice_due']) }}</td></tr>
    @if ($show('previous_due'))
        <tr data-previous-due><td>{{ $label('previous_due') }}</td><td class="num">{{ $paper->money($sums['previous_due']) }}</td></tr>
        <tr class="owed"><td>{{ $label('total_due') }}</td><td class="num">{{ $paper->money($sums['outstanding']) }}</td></tr>
    @endif
</table>

@if ($show('amount_words'))
    <div class="line" data-words>{{ $label('in_words') }}: {{ mb_strtoupper($facts['words']) }}</div>
@endif

@if ($show('deposits'))
    <div class="line" data-deposits>
        {{ $label('payments_title') }}:
        <table class="pay">
            @foreach ($doc->payments as $row)
                <tr>
                    <td style="width: 26mm">{{ $row['ref'] }}</td>
                    <td style="width: 22mm">{{ $row['date'] }}</td>
                    <td data-method class="bn">{{ $row['method'] }}</td>
                    <td class="num" style="width: 28mm">{{ $row['amount'] }}</td>
                </tr>
            @endforeach
        </table>
    </div>
@endif

<div class="footnote bn"><div style="text-align: left; font-size: 80%; line-height: 1.35">{!! nl2br(e($footnote)) !!}</div></div>

@if ($qr !== '')
    <table style="width: 100%; margin-top: 4mm">
        <tr>
            <td></td>
            <td style="width: 26mm; text-align: center; vertical-align: top" data-scan-qr>
                <img src="data:image/svg+xml;base64,{{ base64_encode(\App\Core\Support\QrCode::svg($qr, scale: 4, quiet: 2)) }}" style="width: 22mm; height: 22mm;" alt="">
                @if (\Illuminate\Support\Facades\Lang::has('sales::print.classic.scan_hint', 'bn'))
                    <div style="font-family: hindsiliguri, sans-serif; font-size: 7pt">{{ __('sales::print.classic.scan_hint', [], 'bn') }}</div>
                @endif
            </td>
        </tr>
    </table>
@endif

<table class="signatures">
    <tr>
        @foreach ($signatures as $sign)
            <td style="width: {{ round(100 / max(1, count($signatures)), 1) }}%"><div class="sig-line bn" data-signature>{{ $sign }}</div></td>
        @endforeach
    </tr>
</table>

<div class="printed">
    -- {{ $label('printed_at') }} {{ \App\Core\Support\DateFormat::formatWithTime(now()) }}@if (auth()->check()) · {{ auth()->user()->name }}@endif --
</div>
