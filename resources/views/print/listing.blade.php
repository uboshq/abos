{{--
    ⭐ প্রতিটা তালিকা আর রিপোর্টের ছাপা — লেজারের ছাঁচে (মালিক, ১৬ নভেম্বর ২০২৬: *"লেজার যেভাবে বানাইছে এরকম প্রিন্টেড ডকুমেন্ট লাগবে
    আমার সব রিপোর্টারেই … প্রিন্ট ভিউ এ দেখতে পারবো, আবার প্রিন্টও করতে পারবো"*; নমুনা claude.ai/artifact/3fXn8fRrTxnR5joCVffJU6)।

    ⓘ A4 (৮টার বেশি কলামে আড়াআড়ি), কোম্পানির প্যাড, এক লাইনের তথ্য (বাক্স নয়), কলামের মাথা প্রতি পাতায়, একটা-সাদা-একটা-ধূসর সারি, পুরো বাক্স, ভাগের
      মাথা/উপমোট/সর্বমোট মোটা, শেষে প্রস্তুতকারী ও অনুমোদনকারী, নিচে ছাপার সময় আর "পৃষ্ঠা ক / মোট"। সংখ্যা ইংরেজি অঙ্কে।
    ⓘ সংখ্যা এখানে কষা হয় না — রপ্তানির একই ধরা টেবিল ([[ListExport]]); তাই পর্দা, Excel আর কাগজে একই অঙ্ক।
    ⓘ mPDF: flex/grid নেই, তাই সাজানো টেবিল; ⛔ page-break-inside: avoid নয় (লেজারের শিক্ষা — mPDF টেবিল ছোট করে ফেলে)।
--}}
@php
    $ink = '#000000';
    $muted = '#444444';
    $line = '#bdbdbd';
    $zebra = '#ececec';
    $groupBg = '#dcdcdc';
    $logo = $company?->logoData();
    $count = count($columns);
    // ⓘ সংখ্যার ফন্ট (dejavusans) কেবল সংখ্যার ঘরে — তাতে বাংলা অক্ষর নেই; "সর্বমোট" ঐ ফন্টে চৌকো বাক্স হত (১৬ নভেম্বর ২০২৬)
    $isNum = fn ($cell) => \App\Core\Services\ListPaper::looksNumeric([(string) $cell]);
@endphp
<html>
<head>
<style @nonce>
    @page { margin: 16mm 10mm 14mm 10mm; margin-header: 7mm; margin-footer: 6mm; header: html_listRun; footer: html_listFoot; }
    @page :first { header: none; margin-top: 10mm; }
    body { font-family: hindsiliguri; font-size: {{ $count > 8 ? '7.6pt' : '8.2pt' }}; color: {{ $ink }}; }
    .num { font-family: dejavusans; font-size: {{ $count > 8 ? '6.9pt' : '7.4pt' }}; text-align: right; white-space: nowrap; }
    table { border-collapse: collapse; }
    .pad td { vertical-align: middle; }
    .co-name { font-size: 15pt; font-weight: bold; }
    .co-meta { font-size: 8pt; color: {{ $muted }}; }
    .title { font-size: 14pt; font-weight: bold; text-align: right; }
    .title-en { font-size: 8pt; color: {{ $muted }}; text-align: right; }
    .rule { border-bottom: 0.8mm solid {{ $ink }}; height: 2mm; }
    table.info { margin-top: 1.5mm; border-bottom: 0.25mm solid {{ $line }}; font-size: 8.4pt; }
    table.info td { padding: 0.8mm 4mm 1.4mm 0; white-space: nowrap; }
    table.info td.facts { white-space: normal; padding-top: 0; }
    .k { color: {{ $muted }}; }
    table.lst { width: 100%; border: 0.3mm solid {{ $ink }}; margin-top: 2mm; }
    .lst th { font-weight: bold; text-align: center; padding: 1.5mm 1mm; border-top: 0.6mm solid {{ $ink }}; border-bottom: 0.4mm solid {{ $ink }}; }
    .lst td { padding: 1.1mm 1.2mm; border-bottom: 0.1mm solid {{ $line }}; vertical-align: top; }
    .lst tr.z td { background-color: {{ $zebra }}; }
    .lst tr.head td { background-color: {{ $groupBg }}; font-weight: bold; border-top: 0.3mm solid #777777; }
    .lst tr.sub td { font-weight: bold; border-top: 0.3mm solid #777777; background-color: #f3f3f3; }
    .lst tr.grand td { font-weight: bold; border-top: 0.5mm solid {{ $ink }}; border-bottom: 0.5mm double {{ $ink }}; background-color: #e2e2e2; }
    .lst td.empty { text-align: center; color: {{ $muted }}; padding: 6mm; }
    .note { font-size: 7.5pt; color: {{ $muted }}; margin-top: 2mm; }
    .sign { border-top: 0.25mm solid {{ $ink }}; text-align: center; font-size: 9pt; padding-top: 1.2mm; }
</style>
</head>
<body>

<htmlpageheader name="listRun">
    <table width="100%" style="border-bottom: 0.25mm solid {{ $line }};"><tr>
        <td style="font-size: 8pt; color: {{ $muted }}; padding-bottom: 1.5mm;"><b style="color: {{ $ink }};">{{ $title }}</b>@if ($period) · {{ $period }}@endif</td>
        <td style="font-size: 8pt; color: {{ $muted }}; text-align: right; padding-bottom: 1.5mm;">{{ $company?->name() }}</td>
    </tr></table>
</htmlpageheader>

<htmlpagefooter name="listFoot">
    <table width="100%"><tr>
        <td style="font-size: 8pt; color: {{ $muted }};">{{ __('core.listing.printed_at') }}: {{ $printedAt }}@if (! $signatures) · {{ __('core.listing.computer_made') }}@endif</td>
        <td style="text-align: right; font-size: 9pt; font-weight: bold;">{{ __('core.listing.page') }} {PAGENO} / {nbpg}</td>
    </tr></table>
</htmlpagefooter>

{{-- প্যাড: লোগো, নাম, ঠিকানা, ফোন, BIN/TIN — খালি ঘর দেখায় না --}}
<table class="pad" width="100%"><tr>
    @if ($logo)
        <td width="17mm"><img src="{{ $logo }}" style="height: 14mm;" alt=""></td>
    @endif
    <td>
        <div class="co-name">{{ $company?->name() }}</div>
        @if ($company?->address())<div class="co-meta">{{ $company->address() }}@if ($company->phone) · {{ __('core.listing.phone') }} {{ $company->phone }}@endif</div>@endif
        @if ($company?->bin || $company?->tin)
            <div class="co-meta">@if ($company->bin)BIN {{ $company->bin }}@endif @if ($company->bin && $company->tin)·@endif @if ($company->tin)TIN {{ $company->tin }}@endif</div>
        @endif
    </td>
    <td width="75mm" style="text-align: right;">
        <div class="title">{{ $title }}</div>
        @if ($titleEn)<div class="title-en">{{ $titleEn }}</div>@endif
    </td>
</tr></table>
<div class="rule"></div>

{{--
    ⛔ কাগজের মাথায় বাক্স নয়, এক লাইনের তথ্য (মালিক, ১৬ নভেম্বর ২০২৬: *"উপরদের বক্সগুলা… এটা বক্স দেওয়ার দরকারটা কি?"*) —
       সময় · শাখা · সারি · ছাপলেন এক লাইনে; বাছাই করা ছাঁকনি থাকলে তার নিচে আরেক লাইন।
--}}
<table class="info" width="100%" data-info-line><tr>
    @if ($period)<td><span class="k">{{ __('core.listing.period') }}:</span> <b>{{ $period }}</b></td>@endif
    <td><span class="k">{{ __('core.listing.branch') }}:</span> <b>{{ $branch }}</b></td>
    <td><span class="k">{{ __('core.listing.rows') }}:</span> <b>{{ number_format(count(array_filter($kinds, fn ($k) => $k === 'row')) ?: count($rows)) }}</b></td>
    <td style="text-align: right;"><span class="k">{{ __('core.listing.printed_by') }}:</span> <b>{{ $printedBy }}</b></td>
</tr>
@if ($facts !== [])
    <tr><td colspan="4" class="facts"><span class="k">{{ __('core.listing.showing') }}:</span> {{ implode(' · ', $facts) }}</td></tr>
@endif
</table>

<table class="lst" width="100%" repeat_header="1">
    <thead>
        <tr>
            @foreach ($columns as $column)
                <th>{{ $column['label'] }}</th>
            @endforeach
        </tr>
    </thead>
    <tbody>
        {{--
            ⭐ একই কাগজের লাইনগুলো এক ব্লকে — দৈনিক খতিয়ান, খতিয়ানের মতো তালিকায় (মালিক, ১৬ নভেম্বর ২০২৬: *"ডে বুকটাকে আরও কীভাবে
               ইন্টারন্যাশনাল মানের"*; Tally/SAP-এর জার্নাল ছাপার ধারা): প্রথম দুই ঘর (তারিখ, ভাউচার নং) আগের সারির হুবহু হলে আবার লেখা হয় না,
               আর ব্লকের সব লাইন একই ছায়ায় — পরের কাগজে ছায়া বদলায়। ⓘ যেখানে প্রতিটা সারি আলাদা কাগজ (বিলের তালিকা), সেখানে কিছুই বদলায় না।
        --}}
        @php $z = -1; $prevKey = null; @endphp
        @forelse ($rows as $i => $row)
            @php
                $kind = $kinds[$i] ?? 'row';
                $key = $kind === 'row' && $count >= 4 ? ($row[0] ?? '').'|'.($row[1] ?? '') : null;
                $same = $key !== null && $key === $prevKey && trim((string) ($row[1] ?? '')) !== '';
                if ($kind === 'row' && ! $same) { $z++; }
                $prevKey = $kind === 'row' ? $key : null;
                $prevRow = $prevRowNow ?? [];
                $prevRowNow = $row;
            @endphp
            <tr @class(['head' => $kind === 'head', 'sub' => $kind === 'total', 'grand' => $kind === 'grand', 'z' => $kind === 'row' && $z % 2 === 0])>
                @foreach ($row as $c => $cell)
                    {{-- ⓘ সারিতে শূন্য টাকার ঘর খালি — লেজার আর আন্তর্জাতিক জার্নালের মতো (মোট/উপমোটে শূন্য থাকে) --}}
                    <td @class(['num' => ($columns[$c]['numeric'] ?? false) && $isNum($cell)])>{{ ($same && ($c < 2 || ($c === 2 && $cell === ($prevRow[2] ?? null)))) || ($kind === 'row' && ($columns[$c]['numeric'] ?? false) && $cell === '0.00') ? '' : $cell }}</td>
                @endforeach
            </tr>
        @empty
            <tr><td class="empty" colspan="{{ $count }}">{{ __('core.empty.nothing_here') }}</td></tr>
        @endforelse

        @if (! empty($footer))
            <tr class="grand">
                @foreach ($footer as $c => $cell)
                    <td @class(['num' => ($columns[$c]['numeric'] ?? false) && $isNum($cell)])>{{ $cell }}</td>
                @endforeach
            </tr>
        @endif
    </tbody>
</table>

@if ($truncated)
    <div class="note">{{ __('core.listing.truncated', ['n' => number_format(count($rows))]) }}</div>
@endif

@if ($signatures)
    <table width="100%" style="margin-top: 18mm;" data-signs><tr>
        <td width="35%" class="sign">{{ __('core.listing.sign_prepared') }}</td>
        <td width="30%"></td>
        <td width="35%" class="sign">{{ __('core.listing.sign_approved') }}</td>
    </tr></table>
@endif

</body>
</html>
