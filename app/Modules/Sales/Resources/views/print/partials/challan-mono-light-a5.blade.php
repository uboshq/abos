{{--
    ⓘ A5 রূপ — A4-এর থেকে তৈরি (mm ×০.৭২, অক্ষর ×০.৮৫, সীমা ৬.৫pt; make_a5_new)। A4-এ বদলালে এটাও নতুন করে বানাতে হয়।
    ডেলিভারি চালান · মোনো ক্লাসিক হালকা — বিলের নকশা ২৭-এর সেই একই ধাঁচ, দুই ভাষার একটাই কাঠামো ($lang 'en' | 'bn')।
    মালিক, ৩০ সেপ্টেম্বর ২০২৬: *"seim sTaile Challan koro"*।
    ⓘ কালো ভরাট নেই — ছকের মাথা আর মোট টাকা মোটা দাগে আলাদা; QR কর্মী আর ডিলারের স্ক্যানের ([[DeliveryScanController]])।

    চাই: $doc (পণ্যের সারি, সই, দাম দেখানো কি না — [[SalesPrintController::challan()]]), $facts ([[ChallanPaperFacts]]),
    $company, $paper, $profile, $lang; ঐচ্ছিক $titleFont (ইংরেজি শিরোনামের হরফ)।
    ⚠️ বাংলার শিরোনাম হিন্দ শিলিগুড়ি — লাতিন শিরোনাম-হরফে বাংলা অক্ষর নেই। ⚠️ দাম বন্ধ হলে দর-টাকা আর কথায় টাকা যায়।
--}}
@php
    $lang = $lang ?? 'en';
    $bn = $lang === 'bn';
    $t = fn (string $key) => (string) __($key, [], $lang);
    $up = fn (string $key) => $bn ? $t($key) : mb_strtoupper($t($key));
    $head = \App\Core\Engines\Print\PaperLook::head($company, $profile->shows('logo'));
    $look = app(\App\Modules\Sales\Support\InvoicePrintLook::class);
    $money = $doc->showMoney && $profile->shows('prices'); /* ⛔ নিয়ম আর মালিকের দামের সুইচ দুইটাই — সাধারণ কাগজের মতো ([[document-body]]); ৩০ সেপ্টেম্বর ২০২৬ */
    $to = $facts['to'];
    $tr = $facts['transport'];
    $all = $doc->notices();
    // ⓘ "আগেও ছাপা" — দুই ভাষার লেখাই "DUPLICATE" দিয়ে শুরু, আর শেষে কততম ছাপা আসতে পারে; তাই শুরুর শব্দে চেনা
    $dup = $doc->duplicateNotice();
    $loud = array_values(array_filter($all, fn ($n) => $n !== $dup));
    $qrUrl = $look->shows('qr') ? $facts['scan_url'] : '';
    $titleCss = $bn ? 'font-family: hindsiliguri; font-size: 23.8pt;' : 'font-family: '.($titleFont ?? 'playfair').', freeserif; font-size: 17.8pt; letter-spacing: 0.43mm;';
@endphp

<style @nonce>
    body { font-family: hindsiliguri, sans-serif; font-size: 7.6pt; color: #111; }
    table { border-collapse: collapse; }
    .co-name { font-size: 12.8pt; font-weight: bold; color: #000; }
    .co-meta { font-size: 6.5pt; color: #555; }
    .title { text-align: right; font-weight: bold; color: #000; line-height: 1.05; }
    .dup { text-align: right; font-size: 6.5pt; font-weight: bold; margin-top: 0.72mm; }
    .notice { text-align: center; font-weight: bold; border: 0.36mm solid #000; padding: 1.44mm; margin-top: 2.16mm; font-size: 9.3pt; }
    .cap { font-size: {{ $bn ? '6.5pt' : '6.5pt' }}; font-weight: bold; letter-spacing: {{ $bn ? '0' : '0.36mm' }}; color: #555; }
    table.three { width: 100%; margin-top: 4.32mm; border-top: 1.01mm solid #000; }
    table.three td { width: 33%; vertical-align: top; font-size: 7.2pt; line-height: 1.5; padding: 2.16mm 3.6mm 0 0; }
    .party { font-weight: bold; font-size: 8.5pt; }
    table.items { width: 100%; margin-top: 4.32mm; }
    table.items th { color: #000; font-size: {{ $bn ? '6.8pt' : '6.5pt' }}; font-weight: bold; letter-spacing: {{ $bn ? '0' : '0.22mm' }}; padding: 1.44mm; text-align: left; border-top: 0.72mm solid #000; border-bottom: 0.29mm solid #000; }
    table.items th.num { text-align: right; }
    table.items td { padding: 1.58mm 1.44mm; border-bottom: 0.14mm solid #c8c8c8; font-size: 7.6pt; vertical-align: top; }
    table.items tr.grand td { font-weight: bold; border-top: 0.43mm solid #000; border-bottom: 0.43mm solid #000; }
    .num { text-align: right; white-space: nowrap; }
    td.num { font-family: dejavusans; }
    .muted { color: #555; }
    table.owed { width: 100%; }
    table.owed td { font-weight: bold; font-size: 10.6pt; padding: 1.8mm 1.44mm; border-top: 0.72mm solid #000; border-bottom: 0.72mm solid #000; }
    .footnote { margin-top: 3.6mm; font-size: 7.6pt; font-weight: bold; text-align: center; }
    table.signatures { width: 100%; margin-top: 10.08mm; }
    table.signatures td { text-align: center; padding: 0 3.6mm; font-size: 7.2pt; }
    .sig-line { border-top: 0.22mm solid #000; padding-top: 0.72mm; }
    table.foot { width: 100%; margin-top: 3.6mm; border-top: 0.22mm solid #000; }
    table.foot td { padding-top: 1.08mm; font-size: 6.5pt; color: #555; }
</style>

<table style="width: 100%">
    <tr>
        <td style="vertical-align: middle">
            <table><tr>
                @if ($head['logo'])<td style="padding-right: 2.16mm; vertical-align: middle"><img src="{{ $head['logo'] }}" style="height: 10.08mm;" alt=""></td>@endif
                <td style="vertical-align: middle">
                    <div class="co-name">{{ $head['name'] }}</div>
                    @if ($head['address'] !== '')<div class="co-meta">{{ $head['address'] }}</div>@endif
                    @if ($head['contact'] !== '')<div class="co-meta">{{ $head['contact'] }}</div>@endif
                    @if ($head['tax'] !== '')<div class="co-meta">{{ $head['tax'] }}</div>@endif
                </td>
            </tr></table>
        </td>
        <td style="width: 63.36mm; vertical-align: middle">
            <div class="title" style="{{ $titleCss }}">{{ $up('sales::doc.challan') }}</div>
            @if ($dup !== null)<div class="dup" data-duplicate>{{ $dup }}</div>@endif
        </td>
    </tr>
</table>

@if ($loud !== [])<div class="notice">{{ implode(' · ', $loud) }}</div>@endif

<table class="three">
    <tr>
        <td data-ship-to>
            <div class="cap">{{ $up('sales::field.ship_to') }}</div>
            <div class="party">{{ $to['name'] }}</div>
            @if ($to['point'] !== '')<div>{{ rtrim($t('sales::print.classic.point'), ': ') }}: {{ $to['point'] }}</div>@endif
            @if ($to['address'] !== '')<div>{{ $to['address'] }}</div>@endif
            @if ($to['phone'] !== '')<div>{{ $to['phone'] }}</div>@endif
        </td>
        <td data-transport>
            <div class="cap">{{ $up('sales::field.carrier') }}</div>
            <div class="party">{{ $tr['carrier'] !== '' ? $tr['carrier'] : '—' }}</div>
            @if ($tr['vehicle'] !== '')<div>{{ $t('sales::field.vehicle_no') }}: {{ $tr['vehicle'] }}</div>@endif
            @if ($tr['driver'] !== '')<div>{{ $t('sales::field.driver_name') }}: {{ $tr['driver'] }}</div>@endif
            @if ($tr['driver_phone'] !== '')<div>{{ $t('sales::field.driver_phone') }}: {{ $tr['driver_phone'] }}</div>@endif
            {{-- ⭐ কবে যাবে — গাড়ি ছাড়ার তথ্য, তাই পরিবহনের ঘরে (মালিক, ৩০ সেপ্টেম্বর ২০২৬) --}}
            <div>{{ $t('sales::field.ship_date') }}: <strong>{{ $facts['ship_date'] }}</strong></div>
        </td>
        <td style="padding-right: 0">
            <div class="cap">{{ $up('sales::paper_design.challan_details') }}</div>
            <div>{{ $t('core.print.document_no') }} <strong>{{ $facts['no'] }}</strong></div>
            <div>{{ $t('core.print.date') }}: {{ $facts['date'] }}</div>
            @if ($facts['order_no'] !== '')<div>{{ rtrim($t('sales::print.classic.order_no'), ': ') }}: {{ $facts['order_no'] }}</div>@endif
            @if ($facts['warehouse'] !== '')<div>{{ $t('sales::field.warehouse') }}: <span style="font-family: hindsiliguri">{{ $facts['warehouse'] }}</span></div>@endif
            @if ($facts['created_by'] !== '')<div>{{ $t('sales::print.classic.created_by') }} {{ $facts['created_by'] }}</div>@endif
        </td>
    </tr>
</table>

<table class="items">
    <tr>
        <th style="width: 5.76mm">#</th>
        <th>{{ $up('sales::print.classic.product') }}</th>
        <th class="num" style="width: 17.28mm">{{ $up('sales::print.classic.qty') }}</th>
        <th class="num" style="width: 11.52mm">{{ $up('sales::print.classic.free') }}</th>
        @if ($money)
            <th class="num" style="width: 15.84mm">{{ $up('sales::print.classic.rate') }}</th>
            <th class="num" style="width: 20.16mm">{{ $up('core.print.amount') }}</th>
        @endif
    </tr>
    @foreach ($doc->lines as $i => $line)
        <tr>
            <td class="muted">{{ $i + 1 }}</td>
            <td>
                <div style="font-weight: bold">{{ $line['name'] }}</div>
                @php $under = implode(' · ', array_filter([$line['code'] ?? '', $line['note'] ?? ''])); @endphp
                @if ($under !== '')<div style="font-size: 6.5pt" class="muted">{{ $under }}</div>@endif
            </td>
            <td class="num">{{ $line['qty'] }} <span style="font-family: hindsiliguri">{{ $line['unit'] ?? '' }}</span></td>
            <td class="num">{{ filled($line['free'] ?? '') ? $line['free'] : '—' }}</td>
            @if ($money)
                <td class="num">{{ $line['rate'] }}</td>
                <td class="num">{{ $line['amount'] }}</td>
            @endif
        </tr>
    @endforeach
    <tr class="grand">
        <td></td>
        <td>{{ $up('core.print.total') }} ({{ $facts['items'] }})</td>
        <td class="num" style="font-family: hindsiliguri">{{ $facts['total_qty'] }}</td>
        <td></td>
        @if ($money)
            <td></td>
            <td class="num">{{ $facts['total'] }}</td>
        @endif
    </tr>
</table>

<table style="width: 100%; margin-top: 3.6mm">
    <tr>
        <td style="vertical-align: top; width: 52%; padding-right: 5.76mm; font-size: 7.2pt">
            @if ($money)<div><strong>{{ rtrim($t('core.print.in_words'), ': ') }}:</strong> {{ $bn ? $facts['words_bn'] : $facts['words'] }}</div>@endif
            @if (filled($doc->narration))<div style="margin-top: 1.44mm"><strong>{{ $t('core.table.narration') }}:</strong> {{ $doc->narration }}</div>@endif
            @if ($qrUrl !== '')
                <div style="margin-top: 2.88mm" data-scan-qr>
                    <img src="data:image/svg+xml;base64,{{ base64_encode(\App\Core\Support\QrCode::svg($qrUrl, scale: 4, quiet: 2)) }}" style="width: 14.4mm; height: 14.4mm;" alt="">
                    <div style="font-size: 6.5pt">{{ $t('sales::print.classic.scan_hint') }}</div>
                </div>
            @endif
        </td>
        <td style="vertical-align: top">
            @if ($money)
                <table class="owed"><tr>
                    <td>{{ $up('core.print.total') }}</td>
                    <td class="num">{{ $facts['total'] }}</td>
                </tr></table>
            @endif
        </td>
    </tr>
</table>

@if ($look->footnote() !== '')<div class="footnote">{{ $look->footnote() }}</div>@endif

<table class="signatures" data-signatures>
    <tr>
        @foreach ($doc->signatures as $k)
            <td style="width: {{ (int) round(100 / max(count($doc->signatures), 1)) }}%"><table style="width: 100%"><tr><td class="sig-line" style="text-align: center">{{ $t($k) }}</td></tr></table></td>
        @endforeach
    </tr>
</table>

<table class="foot">
    <tr>
        <td>{{ $head['name'] }}@if ($head['contact'] !== '') · {{ $head['contact'] }}@endif</td>
        <td style="text-align: right">{{ \App\Core\Support\DateFormat::format(now()) }}</td>
    </tr>
</table>
