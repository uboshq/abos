{{-- ⓘ A5 সংস্করণ — A4-এর bw_ledger থেকে মাপ ছোট করে (mm ×০.৭২, অক্ষর ×০.৮৫, সীমা ৬.৫pt), চেহারা একই। মালিক, ৩০ সেপ্টেম্বর ২০২৬: "A5 er jonno 21 desine koro" --}}
{{--
    ১১ · সাদা-কালো খতিয়ান — মালিকের নির্দেশ, ৩০ সেপ্টেম্বর ২০২৬: *"Black & White template ro 3 ti koro"*।

    ⓘ মোটা কালো ঘর, হিসাবের খাতার মতো: মাথার ডান ঘর আর পণ্যের ছকের মাথা কালো ভরাট, বাকি সব
    সাদা — রঙ নেই, তাই যেকোনো প্রিন্টারে একই রকম। নমুনা: Design canvas "আধুনিক বিলের নমুনা", ১১।

    ── ⚠️ তথ্য আর সুইচ, দুইটাই অন্যের ─────────────────────────────────
    সব লেখা আর অঙ্ক [[SalesPrintController::classicFacts()]]-এর `$facts` থেকে; কী ছাপা হবে তা কেবল
    [[InvoicePrintLook]] বলে (header · shows · signatures · footnote)। ⛔ চাবির নাম এখানে লেখা হয় না,
    আর কোনো অঙ্ক এখানে গোনা হয় না। ঘরের নাম `sales::print.classic.*` থেকে, ইংরেজিতে — ক্লাসিকের সাথে এক।

    ⓘ mPDF flex/grid চেনে না, তাই সব table; টাকার ঘর DejaVu 7.6pt (ক্লাসিকের মাপ, ১২ কোটিও ধরে)।
    `data-*` চিহ্নগুলো ক্লাসিকের নামেই — সুইচের দাবি সব ছাঁচে একই ভাবে খোঁজে।
--}}
@php
    $en = fn (string $key, array $replace = []) => __('sales::print.classic.'.$key, $replace, 'en');

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

    $contact = implode(' · ', array_filter([
        $head['phone'] !== '' ? $en('phone_head').' '.$head['phone'] : null,
        $head['email'] !== '' ? $head['email'] : null,
        $head['website'] !== '' ? $head['website'] : null,
    ]));
    $taxIds = $show('bin') ? trim(implode('   ', array_filter([
        filled($company->bin) ? $en('bin').' '.$company->bin : null,
        filled($company->tin) ? $en('tin').' '.$company->tin : null,
    ]))) : '';

    $meta = [[$en('bill_date'), $facts['bill']['bill_date'] ?? '', null]];
    if (filled($facts['bill']['due_date'] ?? '')) {
        $meta[] = ['DUE:', $facts['bill']['due_date'], null];
    }
    if ($show('order_no')) {
        $meta[] = [$en('order_no'), $facts['bill']['order_no'] ?? '', 'data-order-no'];
    }
    $meta[] = [$en('created_by'), $facts['bill']['created_by'] ?? '', null];
    $metaWidth = round(100 / count($meta), 2);

    $logo = $profile->shows('logo') ? $company->logoData() : null;
@endphp

<style @nonce>
    body { font-family: hindsiliguri, sans-serif; font-size: 7.6pt; color: #000; }
    table { border-collapse: collapse; }

    table.head { width: 100%; border: 0.6mm solid #000; }
    table.head td { vertical-align: middle; padding: 1.3mm 1.7mm; }
    .co-name { font-size: 14.4pt; font-weight: bold; }
    .co-meta { font-size: 6.8pt; }
    td.title-cell { width: 41.8mm; background: #000; color: #fff; }
    .title { font-size: 15.3pt; font-weight: bold; letter-spacing: 0.9mm; }
    .no { font-size: 8.5pt; }
    .dup { font-size: 6.5pt; font-weight: bold; }

    .notice { text-align: center; font-weight: bold; border: 0.4mm solid #000; padding: 0.8mm; margin-top: 1.3mm; font-size: 9.3pt; }

    table.box { width: 100%; margin-top: 1.3mm; border: 0.3mm solid #000; }
    table.box td { border-right: 0.3mm solid #000; padding: 0.8mm 1.3mm; vertical-align: top; font-size: 7.2pt; }
    .cap { font-size: 6.5pt; font-weight: bold; }
    .party { font-weight: bold; font-size: 8.5pt; }

    table.lines { width: 100%; margin-top: 1.7mm; border: 0.3mm solid #000; }
    table.lines th { background: #000; color: #fff; font-size: 6.5pt; font-weight: bold; padding: 0.8mm 0.7mm; text-align: left; border-left: 0.2mm solid #fff; }
    table.lines td { border: 0.3mm solid #000; padding: 0.7mm 0.7mm; font-size: 7.6pt; vertical-align: top; }
    table.lines tr.grand td { font-weight: bold; border-bottom: 0.6mm solid #000; }
    table.lines th.num, table.pay th.num { text-align: right; }

    .num { text-align: right; white-space: nowrap; }
    td.num { font-family: dejavusans; }

    .bottom { width: 100%; margin-top: 1.7mm; }
    .bottom td.side { vertical-align: top; }
    table.pane { width: 100%; border: 0.3mm solid #000; margin-bottom: 0.8mm; }
    table.pane > tr > td, table.pane td.pane-cell { padding: 0.8mm 1.3mm; font-size: 7.2pt; }
    table.pay { width: 100%; margin-top: 0.4mm; }
    table.pay th, table.pay td { border: 0.2mm solid #000; padding: 0.4mm 0.5mm; font-size: 6.5pt; text-align: left; }
    table.pay td.num { font-size: 6.8pt; }

    table.sums { width: 100%; border: 0.3mm solid #000; }
    table.sums td { padding: 0.5mm 1.3mm; font-size: 7.6pt; border-bottom: 0.2mm solid #000; }
    table.sums tr.net td { font-weight: bold; }
    table.sums tr.owed td { background: #000; color: #fff; font-weight: bold; font-size: 8.5pt; padding: 1mm 1.3mm; }

    .footnote { margin-top: 1.7mm; border: 0.3mm dashed #000; padding: 0.8mm 1.3mm; text-align: center; font-weight: bold; font-size: 7.6pt; }

    .signatures { width: 100%; margin-top: 6.9mm; }
    .signatures td { text-align: center; font-size: 7.6pt; padding: 0 2.2mm; }
    .sig-line { border-top: 0.3mm solid #000; padding-top: 0.4mm; }

    .printed { margin-top: 1.7mm; font-size: 6.5pt; }
</style>

<table class="head">
    <tr>
        <td>
            <table>
                <tr>
                    @if ($logo)
                        <td style="padding: 0 1.3mm 0 0"><img src="{{ $logo }}" style="height: 9.5mm;" alt=""></td>
                    @endif
                    <td style="padding: 0">
                        <div class="co-name">{{ mb_strtoupper($head['name']) }}</div>
                        @if ($head['address'] !== '')
                            <div class="co-meta" data-head-address>{{ $head['address'] }}</div>
                        @endif
                        @if ($contact !== '')
                            <div class="co-meta">{{ $contact }}</div>
                        @endif
                        @if ($taxIds !== '')
                            <div class="co-meta" data-tax-ids>{{ $taxIds }}</div>
                        @endif
                    </td>
                </tr>
            </table>
        </td>
        <td class="title-cell">
            <div class="title">{{ $en('heading') }}</div>
            <div class="no">
                {{ $facts['bill']['bill_no'] }}
                @if ($show('invoice_type') && filled($facts['bill']['type'] ?? ''))
                    <span data-invoice-type>· {{ $facts['bill']['type'] }}</span>
                @endif
            </div>
            @if ($isDuplicate && $show('duplicate'))
                <div class="dup" data-duplicate>{{ $duplicate }}</div>
            @endif
        </td>
    </tr>
</table>

@if ($loud !== [])
    <div class="notice">{{ implode(' · ', $loud) }}</div>
@endif

<table class="box">
    <tr>
        @foreach ($meta as [$label, $value, $mark])
            <td style="width: {{ $metaWidth }}%" @if ($mark) {{ $mark }} @endif>
                <div class="cap">{{ rtrim($label, ':') }}</div>
                <div>{{ $value }}</div>
            </td>
        @endforeach
    </tr>
</table>

<table class="box">
    <tr>
        <td style="width: 50%">
            <div class="cap">{{ rtrim($en('bill_to'), ',') }}</div>
            <div class="party">{{ $en('ms') }} {{ $facts['bill_to']['name'] }}</div>
            @if (filled($facts['bill_to']['point']))<div>{{ $en('point') }} {{ $facts['bill_to']['point'] }}</div>@endif
            @if (filled($facts['bill_to']['address']))<div>{{ $facts['bill_to']['address'] }}</div>@endif
            @if (filled($facts['bill_to']['phone']))<div>{{ $en('phone') }} {{ $facts['bill_to']['phone'] }}</div>@endif
        </td>
        <td>
            @if ($show('transport'))
                <div data-transport>
                    <div class="cap">{{ rtrim($en('transport'), ':') }}</div>
                    <div class="party">{{ filled($facts['transport']['carrier']) ? $facts['transport']['carrier'] : '—' }}</div>
                    @if (filled($facts['transport']['vehicle']))<div>{{ $en('vehicle') }} {{ $facts['transport']['vehicle'] }}</div>@endif
                    @if (filled($facts['transport']['driver_phone']))<div>{{ $en('driver_phone') }} {{ $facts['transport']['driver_phone'] }}</div>@endif
                    @if (filled($facts['transport']['delivery_date']))<div>{{ $en('delivery_date') }} {{ $facts['transport']['delivery_date'] }}</div>@endif
                </div>
            @endif
        </td>
    </tr>
</table>

<table class="lines">
    <thead>
        <tr>
            <th style="width: 6.5mm">{{ $en('sl') }}</th>
            <th>{{ $en('product') }}</th>
            <th class="num" style="width: 18.7mm">{{ $en('rate') }}</th>
            <th class="num" style="width: 13.7mm">{{ $en('qty') }}</th>
            @if ($showFree)<th class="num" style="width: 10.8mm" data-col-free>{{ $en('free') }}</th>@endif
            @if ($showTotalQty)<th class="num" style="width: 14.4mm" data-col-total-qty>{{ $en('total_qty') }}</th>@endif
            <th class="num" style="width: 21.6mm">{{ $en('amount') }}</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($facts['items']['rows'] as $index => $item)
            <tr>
                <td>{{ $index + 1 }}</td>
                <td>
                    {{ $item['name'] }}
                    @php($under = implode(' · ', array_filter([$item['code'] ?? '', $item['lot'] ?? ''])))
                    @if ($under !== '')<div style="font-size: 6.5pt">{{ $under }}</div>@endif
                </td>
                <td class="num">{{ $paper->money($item['rate']) }}</td>
                <td class="num">{{ $item['qty'] }}</td>
                @if ($showFree)<td class="num">{{ $item['free'] }}</td>@endif
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

<table class="bottom">
    <tr>
        <td class="side" style="width: 60%; padding-right: 2.9mm">
            <table class="pane"><tr><td class="pane-cell">{{ $en('total_items') }} {{ $facts['total_items'] }} &nbsp;·&nbsp; {{ $en('total_delivery') }} {{ $facts['total_delivery'] }}</td></tr></table>

            @if ($show('amount_words'))
                <table class="pane" data-words><tr><td class="pane-cell"><strong>{{ $en('in_words') }}</strong> {{ $facts['words'] }}</td></tr></table>
            @endif

            @if ($show('deposits'))
                <table class="pane" data-deposits><tr><td class="pane-cell">
                    <strong>{{ $en('payments_title') }}</strong>
                    <table class="pay">
                        <tr>
                            <th>{{ $en('txn_id') }}</th>
                            <th>{{ $en('txn_date') }}</th>
                            <th>{{ $en('method') }}</th>
                            <th class="num">{{ $en('amount') }}</th>
                        </tr>
                        @foreach ($doc->payments as $row)
                            <tr>
                                <td>{{ $row['ref'] }}</td>
                                <td>{{ $row['date'] }}</td>
                                <td data-method>{{ $row['method'] }}</td>
                                <td class="num">{{ $row['amount'] }}</td>
                            </tr>
                        @endforeach
                    </table>
                </td></tr></table>
            @endif
        </td>
        <td class="side">
            <table class="sums">
                <tr><td>{{ $en('grand_total') }}</td><td class="num">{{ $paper->money($sums['grand_total']) }}</td></tr>
                <tr><td>{{ $en('discount') }}</td><td class="num">{{ $paper->money($sums['discount']) }}</td></tr>
                @if ($showVat)
                    <tr><td>{{ $en('vat') }}</td><td class="num">{{ $paper->money($sums['vat']) }}</td></tr>
                @endif
                <tr><td>{{ $en('rounding') }}</td><td class="num">{{ $paper->money($sums['rounding']) }}</td></tr>
                <tr class="net"><td>{{ $en('net_payable') }}</td><td class="num">{{ $paper->money($sums['net_payable']) }}</td></tr>
                <tr><td>{{ $en('paid') }}</td><td class="num">{{ $paper->money($sums['paid']) }}</td></tr>
                <tr><td>{{ $en('invoice_due') }}</td><td class="num">{{ $paper->money($sums['invoice_due']) }}</td></tr>
                @if ($show('previous_due'))
                    <tr data-previous-due><td>{{ $en('previous_due') }}</td><td class="num">{{ $paper->money($sums['previous_due']) }}</td></tr>
                    <tr class="owed"><td>{{ mb_strtoupper($en('total_due')) }}</td><td class="num">{{ $paper->money($sums['outstanding']) }}</td></tr>
                @endif
            </table>
        </td>
    </tr>
</table>

<div class="footnote"><div style="text-align: left; font-size: 50%; line-height: 1.35">{!! nl2br(e($footnote)) !!}</div></div>

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
    {{ $en('printed_at') }} {{ \App\Core\Support\DateFormat::formatWithTime(now()) }}
    @if (auth()->check()) · {{ auth()->user()->name }} @endif
</div>
