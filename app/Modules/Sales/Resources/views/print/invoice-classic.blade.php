{{--
    ক্লাসিক টেবিল ইনভয়েস — মালিকের নমুনার হুবহু (২৮ সেপ্টেম্বর ২০২৬, আর ২৯ সেপ্টেম্বরে "100% same")।

    ⓘ ২৯ সেপ্টেম্বর মালিক লাইভের ছাপা মিলিয়ে বললেন নমুনার মতো হয়নি: বাক্সে ঘেরা তিন
    কলাম, কোড-লট জোড়া পণ্যের নাম, "Discount" আর "Rounding" সারি নেই, "Payment Method"
    ফাঁকা, আর DUPLICATE একটা বড় বাক্স। ⭐ এই ফাইল এখন নমুনার ধাঁচ: ঘরের নাম **ইংরেজিতে**,
    হুবহু নমুনার লেখা (`sales::print.classic.*`, সবসময় `en` থেকে পড়া) — ব্যবহারকারীর ভাষা
    যা-ই হোক।

    ── ⚠️ তথ্য একটাই, ছাঁচ দুইটা ─────────────────────────────────────────
    সারির মোট, আদায়ের ছক, কথায় অঙ্ক, আর বকেয়ার হিসাব আসে [[SalesPrintController]]-এর
    সেই একই হিসাব থেকে যা চলতি নকশা ছাপে ([[earlierDue()]], `collectedAmount()`)। ⛔ এখানে
    কোনো অঙ্ক গোনা হয় না — দুই নকশায় দুই রকম বকেয়ার পথ বন্ধ।

    ── ⚠️ টাকার ঘরের মাপ — ছাপার আকারে মাপা ─────────────────────────────
    ঘরের লেখা ৯pt, DejaVu-তে (অঙ্কগুলো সমান চওড়া)। ১২ কোটি (`12,31,87,500.00`) ৯pt-এ
    ২৬.২৫মিমি; দু'পাশে ১.৫মিমি প্যাডিং বাদে মোটের ঘর ৩২ → ২৯মিমি, দরের ঘর ২৯ → ২৬মিমি,
    ডানের টাকার সারি ৩৪ → ৩১মিমি। [[AClassicTableInvoiceCanBeChosenTest]] মাপটা পাতা থেকে
    পড়ে আবার মাপে।
--}}
@php
    $en = fn (string $key, array $replace = []) => __('sales::print.classic.'.$key, $replace, 'en');

    $cell = 9;
    $pad = 1.5;

    /* ⭐ DUPLICATE ছোট ছাপ কোণে; বাকি সতর্কবার্তা (যেমন বাতিল) আগের মতো বড় বাক্সে */
    $duplicate = __('core.print.duplicate_notice');
    $notices = $doc->notices();
    $isDuplicate = in_array($duplicate, $notices, true);

    /*
     * ⭐ "Set Invoice Information" (মালিক, ২৯ সেপ্টেম্বর ২০২৬) — কী ছাপা হবে তা ঐ ভাগের সুইচে।
     * ⓘ চাবির নাম এখানে লেখা হয় না; সব [[InvoicePrintLook]]-এর পদ্ধতি দিয়ে।
     */
    $look = app(\App\Modules\Sales\Support\InvoicePrintLook::class);
    $head = $look->header($company);
    $show = fn (string $what) => $look->shows($what);
    $signatures = $look->signatures();
    $loud = array_values(array_filter($notices, fn (string $n) => $n !== $duplicate));

    $sums = $facts['sums'];
    $showVat = bccomp(str_replace(',', '', $sums['vat']), '0', 4) !== 0;

    $footnote = $look->footnote();

    $logo = $profile->shows('logo') ? $company->logoData() : null;
    $contact = trim(implode(', ', array_filter([$head['email'], $head['website']])));

    /* ⓘ BIN/TIN কোম্পানির প্রোফাইল থেকে — ফাঁকা থাকলে লাইনটাই নেই */
    $taxIds = $show('bin') ? trim(implode('   ', array_filter([
        filled($company->bin) ? $en('bin').' '.$company->bin : null,
        filled($company->tin) ? $en('tin').' '.$company->tin : null,
    ]))) : '';

    /* ⓘ বন্ধ কলাম মাথা, সারি আর যোগফলের সারি — তিন জায়গা থেকেই যায় */
    $showFree = $show('free');

    /* ⭐ QR — কেবল চালান থেকে আসা বিলে (কাউন্টারের বিলে লিংক খালি) */
    $scanUrl = $show('qr') ? (string) ($facts['scan_url'] ?? '') : '';
    $showTotalQty = $show('total_qty');
@endphp

<style @nonce>
    * { box-sizing: border-box; }

    body { font-family: hindsiliguri, sans-serif; font-size: 9pt; line-height: 1.25; color: #000; }

    table { border-collapse: collapse; }

    .head { width: 100%; margin-bottom: 3mm; }
    .head td { vertical-align: top; }
    .logo-space { height: 14mm; width: 14mm; }
    table.brand td { vertical-align: middle; padding: 0; }
    table.brand td.brand-logo { padding-right: 3mm; }
    table.brand td.brand-name { vertical-align: middle; }
    .company-name { font-size: 16pt; font-weight: bold; }
    .company-meta { font-size: 8.5pt; }
    .big-title { text-align: right; font-size: 26pt; font-weight: bold; letter-spacing: 1mm; }
    td.scan-cell { width: 28%; text-align: center; vertical-align: middle; }
    .scan { text-align: center; }
    .scan-hint { font-size: 6.5pt; color: #444; }
    .dup-mark { text-align: right; font-size: 7.5pt; font-weight: bold; color: #444; }

    .notice { text-align: center; font-weight: bold; border: 0.4mm solid #000; padding: 2mm; margin-bottom: 3mm; font-size: 11pt; }

    .facts { width: 100%; margin-bottom: 3mm; }
    .facts td.block { width: 33.3%; vertical-align: top; padding-right: 3mm; font-size: 8.5pt; }
    .facts .block-head { font-weight: bold; margin-bottom: 0.5mm; }
    .facts .party { font-weight: bold; }

    table.lines { width: 100%; }
    table.lines th { border: 0.25mm solid #000; padding: 0.8mm {{ $pad }}mm; font-size: 8.5pt; text-align: left; }
    table.lines td { border: 0.25mm solid #000; padding: 0.8mm {{ $pad }}mm; font-size: {{ $cell }}pt; vertical-align: top; }

    .num { text-align: right; white-space: nowrap; }
    td.num { font-family: dejavusans; font-size: {{ $cell }}pt; }
    th.num { font-family: hindsiliguri; }

    .counts { width: 100%; margin-top: 1.5mm; font-size: 9pt; }
    .words { margin-top: 2mm; font-size: 9pt; }

    .bottom { width: 100%; margin-top: 3mm; }
    .bottom td.side { vertical-align: top; }

    table.payments { width: 100%; font-size: 7.5pt; }
    table.payments th, table.payments td { border: 0.2mm solid #000; padding: 0.8mm 1mm; text-align: left; }
    table.payments td.num { font-size: 8pt; }
    .payments-head { font-weight: bold; font-size: 8.5pt; margin-bottom: 1mm; }

    table.lines tr.grand td { font-weight: bold; }
    .grand-label { text-align: right; }

    {{-- ⓘ নমুনার মতো ঘেরাহীন — নাম বাঁয়ে, অঙ্ক ডানে; নিচে দাগ কেবল "Net Payable"-এর অঙ্কে --}}
    table.sums { width: 100%; }
    table.sums td { padding: 0.8mm {{ $pad }}mm; font-size: 9pt; border: 0; }
    table.sums tr.net td.num { font-weight: bold; text-decoration: underline; }

    .signatures { width: 100%; margin-top: 18mm; }
    .signatures td { text-align: center; font-size: 9pt; padding: 0 4mm; }
    .sig-line { border-top: 0.25mm solid #000; padding-top: 1mm; }

    .footnote { margin-top: 5mm; text-align: center; color: #c00000; font-weight: bold; font-size: 9pt; }
    .printed { margin-top: 2mm; text-align: right; font-size: 7.5pt; color: #444; }
</style>

<table class="head">
    <tr>
        <td style="width: 36%">
            {{-- ⓘ নমুনার মতো লোগো আর নাম এক সারিতে, নাম লোগোর ডানে; লোগো না থাকলে জায়গাটা ফাঁকা --}}
            <table class="brand">
                <tr>
                    <td class="brand-logo">
                        @if ($logo)
                            <img src="{{ $logo }}" style="height: 14mm;" alt="">
                        @else
                            <div class="logo-space"></div>
                        @endif
                    </td>
                    <td class="brand-name"><div class="company-name">{{ $head['name'] }}</div></td>
                </tr>
            </table>

            @if ($head['address'] !== '')
                <div class="company-meta" data-head-address>{{ $head['address'] }}</div>
            @endif

            @if ($contact !== '' || $head['phone'] !== '')
                <div class="company-meta">
                    @if ($contact !== ''){{ $en('email') }} {{ $contact }}@endif
                    @if ($contact !== '' && $head['phone'] !== '') &nbsp;&nbsp; @endif
                    @if ($head['phone'] !== ''){{ $en('phone_head') }} {{ $head['phone'] }}@endif
                </div>
            @endif

            @if ($taxIds !== '')
                <div class="company-meta" data-tax-ids>{{ $taxIds }}</div>
            @endif
        </td>

        {{-- ⭐ QR মাথার মাঝখানে — মালিক, ৩০ সেপ্টেম্বর ২০২৬: *"14 number e qr majkhane daw"*।
             ⛔ `<barcode type="QR">` নয় — ওটা mpdf/qrcode চায়, যেটা vendor-এ নেই আর লাইভের ডিপ্লয়
             composer চালায় না: প্রতিটা বিলের ছাপা ৫০০ হত (abos-3c ধরেছেন)। ⓘ ঘরের [[QrCode]] থেকে SVG,
             ছবি হয়ে। QR না থাকলে (কাউন্টারের বিল, বা সুইচ বন্ধ) ঘরটা ফাঁকা থাকে — INVOICE সরে না। --}}
        <td class="scan-cell">
            @if ($scanUrl !== '')
                <div class="scan" data-scan-qr>
                    <img src="data:image/svg+xml;base64,{{ base64_encode(\App\Core\Support\QrCode::svg($scanUrl, scale: 4, quiet: 2)) }}"
                         style="width: 20mm; height: 20mm;" alt="">
                    <div class="scan-hint">{{ $en('scan_hint') }}</div>
                </div>
            @endif
        </td>

        <td>
            <div class="big-title">{{ $en('heading') }}</div>

            @if ($isDuplicate && $show('duplicate'))
                <div class="dup-mark" data-duplicate>{{ $en('duplicate') }}</div>
            @endif
        </td>
    </tr>
</table>

{{-- ⛔ বাতিলের মতো সতর্কবার্তা এখনো বড় বাক্সে — কাগজটা দেখেই বোঝা যেতে হবে --}}
@if ($loud !== [])
    <div class="notice">{{ implode(' · ', $loud) }}</div>
@endif

<table class="facts">
    <tr>
        <td class="block">
            <div class="block-head">{{ $en('bill_to') }}</div>
            <div class="party">{{ $en('ms') }} {{ $facts['bill_to']['name'] }}</div>
            <div>{{ $en('point') }} {{ $facts['bill_to']['point'] }}</div>
            <div>{{ $en('phone') }} {{ $facts['bill_to']['phone'] }}</div>
            <div>{{ $facts['bill_to']['address'] }}</div>
        </td>
        {{-- ⓘ বন্ধ থাকলে ঘরটা ফাঁকা থাকে, সরে না — তিন কলামের মাথা নমুনার মাপেই থাকে --}}
        <td class="block">
            @if ($show('transport'))
                <div data-transport>
                    <div class="block-head">{{ $en('transport') }}</div>
                    <div>{{ $en('carrier') }} {{ $facts['transport']['carrier'] }}</div>
                    <div>{{ $en('driver_phone') }} {{ $facts['transport']['driver_phone'] }}</div>
                    <div>{{ $en('vehicle') }} {{ $facts['transport']['vehicle'] }}</div>
                    <div>{{ $en('delivery_date') }} {{ $facts['transport']['delivery_date'] }}</div>
                </div>
            @endif
        </td>
        <td class="block">
            <div>{{ $en('bill_date') }} {{ $facts['bill']['bill_date'] }}</div>
            <div>{{ $en('bill_no') }} {{ $facts['bill']['bill_no'] }}</div>
            @if ($show('order_no'))
                <div data-order-no>{{ $en('order_no') }} {{ $facts['bill']['order_no'] }}</div>
            @endif
            @if ($show('invoice_type'))
                <div data-invoice-type>{{ $en('type') }} {{ $facts['bill']['type'] }}</div>
            @endif
            <div>{{ $en('created_by') }} {{ $facts['bill']['created_by'] }}</div>
        </td>
    </tr>
</table>

{{-- ⭐ মালিকের দাগানো ক্রম (২৯ সেপ্টেম্বর ২০২৬): SL# | Item Name | Rate | QTY | Free | Total QTY | Amount --}}
<table class="lines">
    <thead>
        <tr>
            <th class="num" style="width: 9mm">{{ $en('sl') }}</th>
            <th>{{ $en('product') }}</th>
            <th class="num" style="width: 28mm">{{ $en('rate') }}</th>
            <th style="width: 20mm">{{ $en('qty') }}</th>
            @if ($showFree)<th style="width: 17mm" data-col-free>{{ $en('free') }}</th>@endif
            @if ($showTotalQty)<th style="width: 21mm" data-col-total-qty>{{ $en('total_qty') }}</th>@endif
            <th class="num" style="width: 30mm">{{ $en('amount') }}</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($facts['items']['rows'] as $index => $item)
            <tr>
                <td class="num">{{ $index + 1 }}</td>
                <td>{{ $item['name'] }}</td>
                <td class="num">{{ $paper->money($item['rate']) }}</td>
                <td>{{ $item['qty'] }}</td>
                @if ($showFree)<td>{{ $item['free'] }}</td>@endif
                @if ($showTotalQty)<td>{{ $item['total_qty'] }}</td>@endif
                <td class="num">{{ $paper->money($item['amount']) }}</td>
            </tr>
        @endforeach

        {{-- ⭐ কলামের যোগফল — এককভেদে আলাদা ("12 Ctn, 7 Pcs"), কারণ [[classicItems()]]-এ --}}
        @if ($show('grand_total_row'))
            <tr class="grand" data-grand-row>
                <td></td>
                <td class="grand-label">{{ $en('grand_total') }}</td>
                <td></td>
                <td>{{ $facts['items']['totals']['qty'] }}</td>
                @if ($showFree)<td>{{ $facts['items']['totals']['free'] }}</td>@endif
                @if ($showTotalQty)<td>{{ $facts['items']['totals']['total_qty'] }}</td>@endif
                <td class="num">{{ $paper->money($facts['items']['totals']['amount']) }}</td>
            </tr>
        @endif
    </tbody>
</table>

<table class="counts">
    <tr>
        <td style="width: 33.3%">{{ $en('total_items') }} {{ $facts['total_items'] }}</td>
        <td style="width: 33.3%; text-align: center">{{ $en('total_delivery') }} {{ $facts['total_delivery'] }}</td>
        <td></td>
    </tr>
</table>

@if ($show('amount_words'))
    <div class="words" data-words><strong>{{ $en('in_words') }}</strong> {{ $facts['words'] }}</div>
@endif

<table class="bottom">
    <tr>
        <td class="side" style="width: 104mm; padding-right: 4mm;">
            @if ($show('deposits'))
            <div class="payments-head" data-deposits>{{ $en('payments_title') }}</div>
            <table class="payments">
                <thead>
                    <tr>
                        <th class="num" style="width: 7mm">{{ $en('sl') }}</th>
                        <th style="width: 22mm">{{ $en('txn_id') }}</th>
                        <th style="width: 18mm">{{ $en('txn_date') }}</th>
                        <th style="width: 18mm">{{ $en('method') }}</th>
                        <th>{{ $en('narration') }}</th>
                        <th class="num" style="width: 24mm">{{ $en('amount') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($doc->payments as $row)
                        <tr>
                            <td class="num">{{ $row['no'] }}</td>
                            <td>{{ $row['ref'] }}</td>
                            <td>{{ $row['date'] }}</td>
                            <td data-method>{{ $row['method'] }}</td>
                            <td>{{ $row['narration'] }}</td>
                            <td class="num">{{ $row['amount'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            @endif
        </td>
        <td class="side">
            <table class="sums">
                <tr><td>{{ $en('grand_total') }}</td><td class="num" style="width: 34mm">{{ $paper->money($sums['grand_total']) }}</td></tr>
                <tr><td>{{ $en('discount') }}</td><td class="num" style="width: 34mm">{{ $paper->money($sums['discount']) }}</td></tr>
                @if ($showVat)
                    <tr><td>{{ $en('vat') }}</td><td class="num" style="width: 34mm">{{ $paper->money($sums['vat']) }}</td></tr>
                @endif
                <tr><td>{{ $en('rounding') }}</td><td class="num" style="width: 34mm">{{ $paper->money($sums['rounding']) }}</td></tr>
                <tr class="net"><td>{{ $en('net_payable') }}</td><td class="num" style="width: 34mm">{{ $paper->money($sums['net_payable']) }}</td></tr>
                <tr><td>{{ $en('paid') }}</td><td class="num" style="width: 34mm">{{ $paper->money($sums['paid']) }}</td></tr>
                <tr><td>{{ $en('invoice_due') }}</td><td class="num" style="width: 34mm">{{ $paper->money($sums['invoice_due']) }}</td></tr>
                @if ($show('previous_due'))
                    <tr data-previous-due><td>{{ $en('previous_due') }}</td><td class="num" style="width: 34mm">{{ $paper->money($sums['previous_due']) }}</td></tr>
                    <tr><td>{{ $en('total_due') }}</td><td class="num" style="width: 34mm">{{ $paper->money($sums['outstanding']) }}</td></tr>
                @endif
            </table>
        </td>
    </tr>
</table>

<table class="signatures">
    <tr>
        {{-- ⓘ কয়টা ঘর আর কী নাম — "Set Invoice Information"-এ; খালি নাম = নমুনার বাংলা নাম --}}
        @foreach ($signatures as $label)
            <td style="width: {{ round(100 / max(1, count($signatures)), 1) }}%"><table style="width: 100%"><tr><td class="sig-line" style="text-align: center" data-signature>{{ $label }}</td></tr></table></td>
        @endforeach
    </tr>
</table>

<div class="footnote">{{ $footnote }}</div>

<div class="printed">
    {{ $en('printed_at') }} {{ \App\Core\Support\DateFormat::formatWithTime(now()) }}
    @if (auth()->check()) · {{ auth()->user()->name }} @endif
</div>
