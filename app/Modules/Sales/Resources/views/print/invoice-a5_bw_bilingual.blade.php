{{-- ⓘ A5 সংস্করণ — A4-এর bw_bilingual থেকে মাপ ছোট করে (mm ×০.৭২, অক্ষর ×০.৮৫, সীমা ৬.৫pt), চেহারা একই। মালিক, ৩০ সেপ্টেম্বর ২০২৬: "A5 er jonno 21 desine koro" --}}
{{--
    ১৩ · সাদা-কালো দুই ভাষা — মালিকের নির্দেশ, ৩০ সেপ্টেম্বর ২০২৬: *"Black & White template ro 3 ti koro"*।

    ⓘ প্রতিটা ঘরের নাম ইংরেজিতে, নিচে ছোট করে বাংলায় — গ্রাহক যে ভাষাই পড়ুন, কাগজ একটাই।
    মোটা কালো দাগ, রঙ নেই। নমুনা: Design canvas, ১৩।

    ── ⚠️ তথ্য আর সুইচ, দুইটাই অন্যের ─────────────────────────────────
    লেখা আর অঙ্ক [[SalesPrintController::classicFacts()]]-এর `$facts` থেকে; কী ছাপা হবে তা কেবল
    [[InvoicePrintLook]] বলে। ঘরের নাম `sales::print.classic.*` — ইংরেজি `en` থেকে, বাংলা `bn` থেকে,
    তাই নতুন কোনো অনুবাদ লাগেনি। `data-*` চিহ্ন ক্লাসিকের নামেই।
--}}
@php
    $en = fn (string $key, array $replace = []) => rtrim(__('sales::print.classic.'.$key, $replace, 'en'), ':,');
    $bn = fn (string $key) => rtrim(__('sales::print.classic.'.$key, [], 'bn'), ':,');

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

    $taxIds = $show('bin') ? trim(implode('   ', array_filter([
        filled($company->bin) ? __('sales::print.classic.bin', [], 'en').' '.$company->bin : null,
        filled($company->tin) ? __('sales::print.classic.tin', [], 'en').' '.$company->tin : null,
    ]))) : '';

    $meta = [['bill_no', $facts['bill']['bill_no'] ?? '', null], ['bill_date', $facts['bill']['bill_date'] ?? '', null]];
    if ($show('order_no')) {
        $meta[] = ['order_no', $facts['bill']['order_no'] ?? '', 'data-order-no'];
    }
    if ($show('invoice_type')) {
        $meta[] = ['type', $facts['bill']['type'] ?? '', 'data-invoice-type'];
    }
    $metaWidth = round(100 / count($meta), 2);

    $logo = $profile->shows('logo') ? $company->logoData() : null;
@endphp

<style @nonce>
    body { font-family: hindsiliguri, sans-serif; font-size: 7.6pt; color: #000; }
    table { border-collapse: collapse; }

    table.head { width: 100%; border-bottom: 1mm solid #000; }
    table.head td { vertical-align: bottom; padding-bottom: 1.1mm; }
    .co-name { font-size: 14.4pt; font-weight: bold; }
    .co-meta { font-size: 6.8pt; }
    .title { text-align: right; font-size: 14.4pt; font-weight: bold; }
    .title-bn { text-align: right; font-size: 10.2pt; font-weight: bold; }
    .dup { text-align: right; font-size: 6.5pt; font-weight: bold; }

    .notice { text-align: center; font-weight: bold; border: 0.4mm solid #000; padding: 0.8mm; margin-top: 1.3mm; font-size: 9.3pt; }

    .cap { font-size: 6.5pt; font-weight: bold; }
    .cap-bn { font-size: 6.5pt; color: #444; }

    table.meta { width: 100%; margin-top: 1.7mm; }
    table.meta td { vertical-align: top; font-size: 7.6pt; padding-right: 2.2mm; }

    table.parties { width: 100%; margin-top: 1.7mm; }
    table.parties td.party { width: 49%; border-left: 0.8mm solid #000; padding: 0 0 0 1.3mm; vertical-align: top; font-size: 7.2pt; }
    .party-name { font-weight: bold; font-size: 8.5pt; }

    table.lines { width: 100%; margin-top: 1.7mm; border-top: 0.4mm solid #000; }
    table.lines th { border-bottom: 0.4mm solid #000; font-size: 6.5pt; font-weight: bold; padding: 0.7mm 0.7mm; text-align: left; vertical-align: bottom; }
    table.lines td { border-bottom: 0.2mm solid #000; padding: 0.7mm 0.7mm; font-size: 7.6pt; vertical-align: top; }
    table.lines tr.grand td { font-weight: bold; border-bottom: 1mm solid #000; }
    table.lines th.num { text-align: right; }

    .num { text-align: right; white-space: nowrap; }
    td.num { font-family: dejavusans; }

    .bottom { width: 100%; margin-top: 1.7mm; }
    .bottom td.side { vertical-align: top; font-size: 7.2pt; }
    table.pay { width: 100%; margin-top: 0.4mm; }
    table.pay td { font-size: 6.8pt; padding: 0.4mm 0.4mm 0.4mm 0; border-bottom: 0.1mm solid #000; }

    table.sums { width: 100%; }
    table.sums td { padding: 0.5mm 0; font-size: 7.6pt; }
    table.sums tr.owed td { border-top: 1mm solid #000; padding-top: 0.8mm; font-weight: bold; font-size: 8.9pt; }

    .footnote { margin-top: 1.7mm; font-weight: bold; font-size: 7.6pt; }

    .signatures { width: 100%; margin-top: 6.9mm; }
    .signatures td { text-align: center; font-size: 7.6pt; padding: 0 2.2mm; }
    .sig-line { border-top: 0.4mm solid #000; padding-top: 0.4mm; }

    .printed { margin-top: 1.7mm; font-size: 6.5pt; }
</style>

<table class="head">
    <tr>
        <td>
            {{-- ⭐ লোগো নামের পাশে, উপরে নয় — মালিক, ৩০ সেপ্টেম্বর ২০২৬ --}}
            <table><tr>
                @if ($logo)<td style="padding-right: 2.5mm; vertical-align: middle"><img src="{{ $logo }}" style="height: 9.5mm;" alt=""></td>@endif
                <td style="vertical-align: middle"><div class="co-name">{{ $head['name'] }}</div></td>
            </tr></table>
            @if ($head['address'] !== '')
                <div class="co-meta" data-head-address>{{ $head['address'] }}</div>
            @endif
            @if ($head['phone'] !== '' || $head['email'] !== '')
                <div class="co-meta">{{ implode(' · ', array_filter([$head['phone'], $head['email'], $head['website']])) }}</div>
            @endif
            @if ($taxIds !== '')
                <div class="co-meta" data-tax-ids>{{ $taxIds }}</div>
            @endif
        </td>
        <td style="width: 39.6mm">
            <div class="title">{{ $en('heading') }}</div>
            <div class="title-bn">{{ $bn('heading') }}</div>
            @if ($isDuplicate && $show('duplicate'))
                <div class="dup" data-duplicate>{{ $duplicate }}</div>
            @endif
        </td>
    </tr>
</table>

@if ($loud !== [])
    <div class="notice">{{ implode(' · ', $loud) }}</div>
@endif

<table class="meta">
    <tr>
        @foreach ($meta as [$key, $value, $mark])
            <td style="width: {{ $metaWidth }}%" @if ($mark) {{ $mark }} @endif>
                <div class="cap">{{ mb_strtoupper($en($key)) }}</div>
                <div class="cap-bn">{{ $bn($key) }}</div>
                <div>{{ $value }}</div>
            </td>
        @endforeach
    </tr>
</table>

<table class="parties">
    <tr>
        <td class="party">
            <div class="cap">{{ mb_strtoupper($en('bill_to')) }} <span class="cap-bn">· {{ $bn('bill_to') }}</span></div>
            <div class="party-name">{{ $en('ms') }} {{ $facts['bill_to']['name'] }}</div>
            @if (filled($facts['bill_to']['point']))<div>{{ $en('point') }}: {{ $facts['bill_to']['point'] }}</div>@endif
            @if (filled($facts['bill_to']['address']))<div>{{ $facts['bill_to']['address'] }}</div>@endif
            @if (filled($facts['bill_to']['phone']))<div>{{ $facts['bill_to']['phone'] }}</div>@endif
        </td>
        <td style="width: 2%"></td>
        <td class="party">
            @if ($show('transport'))
                <div data-transport>
                    <div class="cap">{{ mb_strtoupper($en('transport')) }} <span class="cap-bn">· {{ $bn('transport') }}</span></div>
                    <div class="party-name">{{ filled($facts['transport']['carrier']) ? $facts['transport']['carrier'] : '—' }}</div>
                    @if (filled($facts['transport']['vehicle']))<div>{{ $facts['transport']['vehicle'] }}</div>@endif
                    @if (filled($facts['transport']['driver_phone']))<div>{{ $facts['transport']['driver_phone'] }}</div>@endif
                    @if (filled($facts['transport']['delivery_date']))<div>{{ $en('delivery_date') }}: {{ $facts['transport']['delivery_date'] }}</div>@endif
                </div>
            @endif
        </td>
    </tr>
</table>

<table class="lines">
    <thead>
        <tr>
            <th style="width: 6.5mm">{{ $en('sl') }}<br><span class="cap-bn">{{ $bn('sl') }}</span></th>
            <th>{{ mb_strtoupper($en('product')) }}<br><span class="cap-bn">{{ $bn('product') }}</span></th>
            <th class="num" style="width: 18mm">{{ mb_strtoupper($en('rate')) }}<br><span class="cap-bn">{{ $bn('rate') }}</span></th>
            <th class="num" style="width: 13.7mm">{{ mb_strtoupper($en('qty')) }}<br><span class="cap-bn">{{ $bn('qty') }}</span></th>
            @if ($showFree)<th class="num" style="width: 10.8mm" data-col-free>{{ mb_strtoupper($en('free')) }}<br><span class="cap-bn">{{ $bn('free') }}</span></th>@endif
            @if ($showTotalQty)<th class="num" style="width: 15.1mm" data-col-total-qty>{{ mb_strtoupper($en('total_qty')) }}<br><span class="cap-bn">{{ $bn('total_qty') }}</span></th>@endif
            <th class="num" style="width: 20.9mm">{{ mb_strtoupper($en('amount')) }}<br><span class="cap-bn">{{ $bn('amount') }}</span></th>
        </tr>
    </thead>
    <tbody>
        @foreach ($facts['items']['rows'] as $index => $item)
            <tr>
                <td>{{ $index + 1 }}</td>
                <td>{{ $item['name'] }}</td>
                <td class="num">{{ $paper->money($item['rate']) }}</td>
                <td class="num">{{ $item['qty'] }}</td>
                @if ($showFree)<td class="num">{{ filled($item['free']) ? $item['free'] : '—' }}</td>@endif
                @if ($showTotalQty)<td class="num">{{ $item['total_qty'] }}</td>@endif
                <td class="num">{{ $paper->money($item['amount']) }}</td>
            </tr>
        @endforeach

        @if ($show('grand_total_row'))
            <tr class="grand" data-grand-row>
                <td></td>
                <td>{{ mb_strtoupper($en('grand_total')) }} · {{ $bn('grand_total') }}</td>
                <td></td>
                <td class="num">{{ $facts['items']['totals']['qty'] }}</td>
                @if ($showFree)<td class="num">{{ $facts['items']['totals']['free'] }}</td>@endif
                @if ($showTotalQty)<td class="num">{{ $facts['items']['totals']['total_qty'] }}</td>@endif
                <td class="num">{{ $paper->money($facts['items']['totals']['amount']) }}</td>
            </tr>
        @endif
    </tbody>
</table>

<table class="bottom">
    <tr>
        <td class="side" style="width: 58%; padding-right: 3.6mm">
            <div>{{ $en('total_items') }}: {{ $facts['total_items'] }} &nbsp;·&nbsp; {{ $en('total_delivery') }}: {{ $facts['total_delivery'] }}</div>

            @if ($show('amount_words'))
                <div style="margin-top: 0.8mm" data-words><strong>{{ $en('in_words') }}:</strong> {{ $facts['words'] }}</div>
            @endif

            @if ($show('deposits'))
                <div style="margin-top: 1.3mm" data-deposits>
                    <strong>{{ $en('payments_title') }}</strong> <span class="cap-bn">· {{ $bn('payments_title') }}</span>
                    <table class="pay">
                        @foreach ($doc->payments as $row)
                            <tr>
                                <td style="width: 15.8mm">{{ $row['ref'] }}</td>
                                <td style="width: 14.4mm">{{ $row['date'] }}</td>
                                <td data-method>{{ $row['method'] }}</td>
                                <td class="num" style="width: 17.3mm">{{ $row['amount'] }}</td>
                            </tr>
                        @endforeach
                    </table>
                </div>
            @endif
        </td>
        <td class="side">
            <table class="sums">
                <tr><td>{{ $en('grand_total') }} <span class="cap-bn">{{ $bn('grand_total') }}</span></td><td class="num">{{ $paper->money($sums['grand_total']) }}</td></tr>
                <tr><td>{{ $en('discount') }} <span class="cap-bn">{{ $bn('discount') }}</span></td><td class="num">{{ $paper->money($sums['discount']) }}</td></tr>
                @if ($showVat)
                    <tr><td>{{ $en('vat') }} <span class="cap-bn">{{ $bn('vat') }}</span></td><td class="num">{{ $paper->money($sums['vat']) }}</td></tr>
                @endif
                <tr><td>{{ $en('rounding') }} <span class="cap-bn">{{ $bn('rounding') }}</span></td><td class="num">{{ $paper->money($sums['rounding']) }}</td></tr>
                <tr><td><strong>{{ $en('net_payable') }}</strong> <span class="cap-bn">{{ $bn('net_payable') }}</span></td><td class="num"><strong>{{ $paper->money($sums['net_payable']) }}</strong></td></tr>
                <tr><td>{{ $en('paid') }} <span class="cap-bn">{{ $bn('paid') }}</span></td><td class="num">{{ $paper->money($sums['paid']) }}</td></tr>
                <tr><td>{{ $en('invoice_due') }} <span class="cap-bn">{{ $bn('invoice_due') }}</span></td><td class="num">{{ $paper->money($sums['invoice_due']) }}</td></tr>
                @if ($show('previous_due'))
                    <tr data-previous-due><td>{{ $en('previous_due') }} <span class="cap-bn">{{ $bn('previous_due') }}</span></td><td class="num">{{ $paper->money($sums['previous_due']) }}</td></tr>
                    <tr class="owed"><td>{{ $en('total_due') }} <span class="cap-bn">{{ $bn('total_due') }}</span></td><td class="num">{{ $paper->money($sums['outstanding']) }}</td></tr>
                @endif
            </table>
        </td>
    </tr>
</table>

<div class="footnote">{{ $footnote }}</div>

@if ($qr !== '')
    <table style="width: 100%; margin-top: 1.7mm">
        <tr>
            <td></td>
            <td style="width: 18.7mm; text-align: center; vertical-align: top" data-scan-qr>
                <img src="data:image/svg+xml;base64,{{ base64_encode(\App\Core\Support\QrCode::svg($qr, scale: 4, quiet: 2)) }}" style="width: 15.8mm; height: 9.5mm;" alt="">
                @if (\Illuminate\Support\Facades\Lang::has('sales::print.classic.scan_hint', 'bn'))
                    <div style="font-family: hindsiliguri, sans-serif; font-size: 6.5pt">{{ __('sales::print.classic.scan_hint', [], 'bn') }}</div>
                @endif
            </td>
        </tr>
    </table>
@endif

<table class="signatures">
    <tr>
        @foreach ($signatures as $label)
            <td style="width: {{ round(100 / max(1, count($signatures)), 1) }}%"><table style="width: 100%"><tr><td class="sig-line" style="text-align: center" data-signature>{{ $label }}</td></tr></table></td>
        @endforeach
    </tr>
</table>

<div class="printed">
    {{ __('sales::print.classic.printed_at', [], 'en') }} {{ \App\Core\Support\DateFormat::formatWithTime(now()) }}
    @if (auth()->check()) · {{ auth()->user()->name }} @endif
</div>
