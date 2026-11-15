{{--
    ⭐ হিসাব বিবরণী — A4, সব পাতা, ব্যাংকের মতো (মালিক, ১৫ নভেম্বর ২০২৬; নমুনা সংস্করণ ১৪ অনুমোদিত; [[PartyStatement]], [[PartyStatementPages]])।

    ⓘ mPDF-এর ছাঁচ: flex বা grid নেই, তাই সাজানো টেবিল দিয়ে। ⓘ সাদা-কালো মূল; রঙিন কেবল সেটিংসে চালু থাকলে ($colour)।
    ⓘ মাপগুলো [[PartyStatementPages]]-এর ধ্রুবকের সাথে মেলানো — একটা বদলালে অন্যটাও।
--}}
@php
    $ink = '#000000';
    $muted = $colour ? '#5b6476' : '#444444';
    $line = $colour ? '#d9dee8' : '#bdbdbd';
    $brand = $colour ? '#0f5c8c' : '#000000';
    $zebra = $colour ? '#eef3f8' : '#ececec';
    $carryBg = $colour ? '#fff7e0' : '#f2f2f2';
    $dr = $colour ? '#9b2c2c' : '#000000';
    $cr = $colour ? '#0b6b3a' : '#000000';
    $fmt = fn ($v) => $v === null ? '' : \App\Core\Support\Money::format($v);
    $bal = fn ($v) => \App\Core\Support\Money::drCr($v);
    $day = fn (?string $d) => $d === null ? '' : \Illuminate\Support\Carbon::parse($d)->format('d-m-Y');
    $lastPage = count($pages) - 1;
    $logo = $company->logoData();
    // ⓘ তীর চিহ্ন বাংলা ফন্টে নেই — সেটুকু dejavusans-এ
    $arrows = fn (string $text) => str_replace(['←', '→'], ['<span style="font-family: dejavusans;">←</span>', '<span style="font-family: dejavusans;">→</span>'], e($text));
    $signGap = $signGap ?? 0.0;
@endphp
<html>
<head>
<style @nonce>
    @page { size: 210mm 297mm; margin: 10mm 10mm 14mm 10mm; margin-footer: 6mm; footer: html_statementFoot; }
    body { font-family: hindsiliguri; font-size: 8.5pt; color: {{ $ink }}; }
    .num { font-family: dejavusans; font-size: 7.6pt; text-align: right; white-space: nowrap; }
    table { border-collapse: collapse; }
    .pad td { vertical-align: middle; }
    .co-name { font-size: 15pt; font-weight: bold; }
    .co-meta { font-size: 8pt; color: {{ $muted }}; }
    .title { font-size: 14pt; font-weight: bold; color: {{ $brand }}; text-align: right; }
    .title-en { font-size: 8pt; color: {{ $muted }}; text-align: right; }
    .rule { border-bottom: 0.8mm solid {{ $brand }}; height: 2mm; }
    .box { border: 0.25mm solid {{ $line }}; padding: 2mm 3mm; vertical-align: top; }
    .box-h { font-size: 7.5pt; color: {{ $muted }}; }
    .party-name { font-size: 11pt; font-weight: bold; }
    .k { color: {{ $muted }}; padding-right: 3mm; white-space: nowrap; }
    .run td { font-size: 8pt; color: {{ $muted }}; border-bottom: 0.25mm solid {{ $line }}; padding-bottom: 1.5mm; }
    table.led { width: 100%; border: 0.3mm solid {{ $ink }}; margin-top: 2mm; page-break-inside: avoid; }
    .led th { font-weight: bold; text-align: center; padding: 1.6mm 1.2mm;
        @if ($colour) background-color: {{ $brand }}; color: #ffffff; @else border-top: 0.6mm solid #000000; border-bottom: 0.4mm solid #000000; @endif }
    .led td { padding: 1.2mm 1.2mm; border-bottom: 0.1mm solid {{ $line }}; vertical-align: top; }
    .led tr.z td { background-color: {{ $zebra }}; }
    .led td.sl { color: {{ $muted }}; text-align: right; }
    .led td.no { font-family: dejavusansmono; font-size: 7pt; white-space: nowrap; @if ($colour) color: {{ $brand }}; @endif }
    .led tr.carry td { background-color: {{ $carryBg }}; font-weight: bold; }
    .led tr.total td { font-weight: bold; border-top: 0.5mm solid {{ $ink }}; border-bottom: 0.5mm double {{ $ink }}; background-color: {{ $carryBg }}; }
    .dr { color: {{ $dr }}; } .cr { color: {{ $cr }}; }
    .recon td { padding: 0.8mm 2mm; font-size: 9pt; }
    .recon tr.last td { font-weight: bold; border-top: 0.3mm solid {{ $ink }}; border-bottom: 0.8mm double {{ $ink }}; }
    .sign { border-top: 0.25mm solid {{ $ink }}; text-align: center; font-size: 9pt; padding-top: 1.2mm; }
</style>
</head>
<body>

<htmlpagefooter name="statementFoot">
    <table width="100%"><tr>
        {{-- ⭐ ছাপার সময় প্রতিটা পাতার নিচে (মালিক, ১৫ নভেম্বর ২০২৬); সই বন্ধ থাকলে "কম্পিউটারে তৈরি" লাইনও --}}
        <td style="font-size: 8pt; color: {{ $muted }};">{{ __('accounts::statement.printed_at') }}: {{ $printedAt }}@if (! $signatures) · {{ __('accounts::statement.computer_made') }}@endif</td>
        <td style="text-align: right; font-size: 9pt; font-weight: bold;">{{ __('accounts::statement.page') }} {PAGENO} / {nbpg}</td>
    </tr></table>
</htmlpagefooter>

@foreach ($pages as $p => $rows)
    @if ($p > 0)
        <pagebreak />
    @endif

    @if ($p === 0)
        {{-- প্যাড: লোগো, নাম, ঠিকানা, ফোন, BIN/TIN — খালি ঘর দেখায় না --}}
        <table class="pad" width="100%"><tr>
            @if ($logo)
                <td width="17mm"><img src="{{ $logo }}" style="height: 14mm;" alt=""></td>
            @endif
            <td>
                <div class="co-name">{{ $company->name() }}</div>
                @if ($company->address())<div class="co-meta">{{ $company->address() }}@if ($company->phone) · {{ __('accounts::statement.fact.phone') }} {{ $company->phone }}@endif</div>@endif
                @if ($company->bin || $company->tin)
                    <div class="co-meta">@if ($company->bin)BIN {{ $company->bin }}@endif @if ($company->bin && $company->tin)·@endif @if ($company->tin)TIN {{ $company->tin }}@endif</div>
                @endif
            </td>
            <td width="55mm">
                <div class="title">{{ $docTitle }}</div>
                @if ($locale === 'bn')<div class="title-en">{{ $docTitleEn }}</div>@endif
            </td>
        </tr></table>
        <div class="rule"></div>

        <table width="100%" style="margin-top: 3mm;"><tr>
            <td class="box" width="56%">
                <div class="box-h">{{ __('accounts::statement.party') }}</div>
                <div class="party-name">{{ $party['name'] }}</div>
                {{-- ⭐ নামের নিচে ঠিকানা, তারপর ফোন — কেবল এই তিনটা (মালিক, ১৫ নভেম্বর ২০২৬); খালি লাইন দেখায় না --}}
                @foreach ($party['facts'] as $line)
                    <div>{{ $line }}</div>
                @endforeach
            </td>
            <td width="3%"></td>
            <td class="box" width="41%">
                <div class="box-h">{{ __('accounts::statement.info') }}</div>
                <table>
                    <tr><td class="k">{{ __('accounts::statement.period') }}</td><td><b>{{ $period }}</b></td></tr>
                    <tr><td class="k">{{ __('accounts::statement.branch') }}</td><td>{{ $branch }}</td></tr>
                    <tr><td class="k">{{ __('accounts::statement.printed_by') }}</td><td>{{ $printedBy }}</td></tr>
                </table>
            </td>
        </tr></table>
    @else
        <table class="run" width="100%"><tr>
            <td><b style="color: {{ $ink }};">{{ $party['name'] }}</b>@if ($party['code'] !== '') · {{ $party['code'] }}@endif · {{ $docTitle }} {{ $period }}</td>
            <td style="text-align: right;">{{ $company->name() }}</td>
        </tr></table>
    @endif

    <table class="led" width="100%">
        <thead>
            <tr>
                <th width="10mm">{{ __('accounts::statement.col.sl') }}</th>
                <th width="25mm">{{ __('accounts::statement.col.no') }}</th>
                <th width="18mm">{{ __('accounts::statement.col.date') }}</th>
                <th width="54mm">{{ __('accounts::statement.col.particulars') }}</th>
                <th width="15mm">{{ __('accounts::statement.col.kind') }}</th>
                <th width="21mm">{{ __('accounts::statement.col.debit') }}</th>
                <th width="21mm">{{ __('accounts::statement.col.credit') }}</th>
                <th width="26mm">{{ __('accounts::statement.col.balance') }}</th>
            </tr>
        </thead>
        <tbody>
            @if ($p === 0)
                <tr class="carry" data-carry="opening">
                    <td colspan="7">{{ $from !== null ? __('accounts::statement.opening_row', ['date' => $day($from)]) : __('accounts::statement.recon.opening_start') }}</td>
                    <td class="num">{{ $bal($statement['opening']) }}</td>
                </tr>
            @else
                <tr class="carry" data-carry="brought">
                    <td colspan="7">{!! $arrows(__('accounts::statement.brought')) !!}</td>
                    <td class="num">{{ $bal($pages[$p - 1] === [] ? $statement['opening'] : end($pages[$p - 1])['balance']) }}</td>
                </tr>
            @endif

            @foreach ($rows as $i => $row)
                <tr @class(['z' => $i % 2 === 0])>
                    <td class="sl num">{{ number_format($row['sl']) }}</td>
                    <td class="no">{{ $row['no'] }}</td>
                    <td>{{ $day($row['date']) }}</td>
                    <td>{{ $row['particulars'] }}</td>
                    <td>{{ $row['kind'] }}</td>
                    <td class="num dr">{{ $fmt($row['debit']) }}</td>
                    <td class="num cr">{{ $fmt($row['credit']) }}</td>
                    <td class="num">{{ $bal($row['balance']) }}</td>
                </tr>
            @endforeach

            @if ($p < $lastPage)
                <tr class="carry" data-carry="carried">
                    <td colspan="7">{!! $arrows(__('accounts::statement.carried')) !!}</td>
                    <td class="num">{{ $bal($rows === [] ? $statement['opening'] : end($rows)['balance']) }}</td>
                </tr>
            @else
                <tr class="total">
                    <td colspan="5">{{ __('accounts::statement.total') }}</td>
                    <td class="num dr">{{ \App\Core\Support\Money::format($statement['debit']) }}</td>
                    <td class="num cr">{{ \App\Core\Support\Money::format($statement['credit']) }}</td>
                    <td></td>
                </tr>
            @endif
        </tbody>
    </table>

    @if ($p === $lastPage)
        @php
            $drNormal = $statement['normal'] === 'Dr';
            $add = $drNormal ? $statement['debit'] : $statement['credit'];
            $less = $drNormal ? $statement['credit'] : $statement['debit'];
        @endphp
        {{-- ⭐ বাংলাদেশের ব্যাংক আর খাতার নিয়মে সারাংশ শেষে, ডান পাশে — মিলিয়ে (মালিক, ১৫ নভেম্বর ২০২৬) --}}
        <table class="recon" align="right" style="margin-top: 3mm;" data-recon>
            <tr><td>{{ $from !== null ? __('accounts::statement.recon.opening', ['date' => $day($from)]) : __('accounts::statement.recon.opening_start') }}</td><td class="num">{{ $bal($statement['opening']) }}</td></tr>
            <tr><td>{{ __('accounts::statement.recon.add', ['side' => __('accounts::statement.'.($drNormal ? 'debit' : 'credit'))]) }}</td><td class="num">{{ \App\Core\Support\Money::format($add) }}</td></tr>
            <tr><td>{{ __('accounts::statement.recon.less', ['side' => __('accounts::statement.'.($drNormal ? 'credit' : 'debit'))]) }}</td><td class="num">({{ \App\Core\Support\Money::format($less) }})</td></tr>
            <tr class="last"><td>{{ __('accounts::statement.recon.closing', ['date' => $day($to)]) }}</td><td class="num">{{ $bal($statement['closing']) }}</td></tr>
        </table>

        @if ($signatures)
            {{-- ⭐ সইয়ের ঘর পাতার একদম নিচে, পৃষ্ঠা নম্বরের ঠিক উপরে — মাঝের ফাঁকা মাপা ([[PartyStatementPages::spare()]]) --}}
            <div style="height: {{ number_format($signGap, 1, '.', '') }}mm;"></div>
            <table width="100%" style="margin-top: 16mm;" data-signs><tr>
                <td width="35%" class="sign">{{ __('accounts::statement.sign.prepared') }}</td>
                <td width="30%"></td>
                <td width="35%" class="sign">{{ __('accounts::statement.sign.approved') }}</td>
            </tr></table>
        @endif
    @endif
@endforeach

</body>
</html>
