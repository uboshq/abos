{{--
    কাগজের নকশার ভাগের অংশ — রং-অক্ষরের CSS আর মাথা (কোম্পানি · শিরোনাম · নম্বর · QR)।
    মালিক, ৩০ সেপ্টেম্বর ২০২৬: ভাউচার · চালান · আদেশ · রসিদ — প্রতিটায় ২০ নকশা, "sobgulor khetrei ekoi stayle"।

    ── ⚠️ কেন এক জায়গায় ────────────────────────────────────────────────
    একই নম্বরের নকশা সব কাগজে একই চেহারার — কোম্পানি একটা বাছলে বিল, ভাউচার, চালান মিলে যায়।
    মাথা আর রং প্রতিটা কাগজে আলাদা লেখা থাকলে একদিন এক কাগজের নীল আরেকটার নীল থাকত না।

    চাই: $L (নকশার সাজ), $head ['name','address','contact','tax','logo'], $title, $no, $date,
         $qr (ঠিকানা বা ''), $qrHint, $notices (লেখার তালিকা; পুরনো $notice-ও চলে)।
    $L: accent · tint · ink · font(sans|serif|mono) · head(left|center|band|split|sidebar|minimal|dark|brutal|form)
        title(text|pill|outline|huge) · table(dark|rows|grid|zebra|clean|underline|brutal) · amount(box|fill|line|big|card|brutal)
        grad (দুই রঙের পট্টি, বা '') · lang(en|bn) · radius (mm)
    ⚠️ mPDF-এ flex/grid নেই, আর বংশধর-বাছাই সব জায়গায় খাটে না — তাই রং বেশির ভাগ inline।
--}}
@php
    $ac = $L['accent'];
    $tint = $L['tint'] ?? '#f4f6f8';
    $ink = $L['ink'] ?? '#1b1f24';
    $muted = $L['muted'] ?? '#667085';
    $font = match ($L['font'] ?? 'sans') { 'serif' => 'dejavuserif', 'mono' => 'dejavusansmono', default => 'hindsiliguri' };
    $r = ($L['radius'] ?? 0).'mm';
    $bandBg = ($L['grad'] ?? '') !== '' ? 'background: linear-gradient(90deg, '.$ac.' 0%, '.$L['grad'].' 100%);' : 'background: '.$ac.';';
    $logoImg = $head['logo'] ? '<img src="'.e($head['logo']).'" style="height: 15mm;" alt="">' : '';
    $qrImg = $qr !== ''
        ? '<img src="data:image/svg+xml;base64,'.base64_encode(\App\Core\Support\QrCode::svg($qr, scale: 4, quiet: 2)).'" style="width: 21mm; height: 21mm;" alt="">'
          .($qrHint !== '' ? '<div style="font-family: hindsiliguri; font-size: 6.5pt; color: '.$muted.'; text-align: center">'.e($qrHint).'</div>' : '')
        : '';
    /*
     * ⭐ "আগেও ছাপা হয়েছে" — শিরোনামের ঠিক নিচে, লাল পট্টিতে (মালিক, ৩০ সেপ্টেম্বর ২০২৬: *"sob somoy ...
     * kagojer hedline er niche"*)। ⓘ খসড়া-বাতিলের মতো অন্য সতর্কবার্তা আগের মতোই বড় বাক্সে — ওগুলো কাগজের
     * বৈধতার প্রশ্ন, আর বাক্সটা চোখে পড়ার জন্যই।
     */
    $all = array_values(array_filter(array_map('strval', (array) ($notices ?? ($notice ?? []))), fn ($n) => $n !== ''));
    $dupText = (string) __('core.print.duplicate_notice');
    $dupHtml = in_array($dupText, $all, true)
        ? '<div data-duplicate style="margin-top: 1mm"><span style="background: #b42318; color: #fff; font-weight: bold; font-size: 8pt; padding: 0.6mm 2mm; font-family: hindsiliguri">'.e($dupText).'</span></div>'
        : '';
    $loud = array_values(array_filter($all, fn ($n) => $n !== $dupText));
    $titleCss = match ($L['title'] ?? 'text') {
        'pill' => 'background: '.$ac.'; color: #fff; padding: 1.5mm 4mm; border-radius: 3mm; font-size: 12pt;',
        'outline' => 'border: 0.5mm solid '.$ac.'; color: '.$ac.'; padding: 1.2mm 3mm; font-size: 12pt;',
        'huge' => 'font-size: 24pt; color: '.$ac.'; letter-spacing: -0.3mm;',
        default => 'font-size: 15pt; color: '.$ac.'; letter-spacing: 0.8mm;',
    };
@endphp
<style @nonce>
    /* ⚠️ গায়ের লেখা সবসময় বাংলা অক্ষরে — মোনো/সেরিফে বাংলা নেই, আর পণ্য-একক-গুদামের নাম বাংলাও হয়;
       নকশার অক্ষর কেবল নাম, শিরোনাম, ছকের মাথা আর অঙ্কে (.lf) */
    body { font-family: hindsiliguri, sans-serif; font-size: 9pt; color: {{ $ink }}; }
    .lf, .co-name, table.lines th { font-family: {{ $font }}, hindsiliguri, sans-serif; }
    table { border-collapse: collapse; }
    .bn { font-family: hindsiliguri, sans-serif; }
    .num { text-align: right; white-space: nowrap; }
    td.num { font-family: {{ ($L['font'] ?? '') === 'mono' ? 'dejavusansmono' : 'dejavusans' }}; }
    .muted { color: {{ $muted }}; }
    .co-name { font-size: 16pt; font-weight: bold; }
    .co-meta { font-size: 7.5pt; color: {{ $muted }}; line-height: 1.4; }
    .cap { font-size: 7pt; font-weight: bold; letter-spacing: 0.4mm; color: {{ $muted }}; }
    .notice { text-align: center; font-weight: bold; border: 0.5mm solid #b42318; color: #b42318; padding: 2mm; margin-top: 3mm; font-size: 11pt; }
    table.lines { width: 100%; margin-top: 4mm; }
    table.lines th { font-size: 7.5pt; font-weight: bold; text-align: left; padding: 1.8mm 2mm; }
    table.lines th.num { text-align: right; }
    table.lines td { font-size: 9pt; padding: 1.8mm 2mm; vertical-align: top; }
    table.lines tr.total td { font-weight: bold; }
    table.signatures { width: 100%; margin-top: 16mm; }
    table.signatures td { text-align: center; font-size: 8.5pt; padding: 0 4mm; font-family: hindsiliguri, sans-serif; }
    .sig-line { border-top: 0.3mm solid {{ $ink }}; padding-top: 1mm; }
</style>

@php
    $coBlock = '<div class="co-name">'.e($head['name']).'</div>'
        .($head['address'] !== '' ? '<div class="co-meta">'.e($head['address']).'</div>' : '')
        .($head['contact'] !== '' ? '<div class="co-meta">'.e($head['contact']).'</div>' : '')
        .($head['tax'] !== '' ? '<div class="co-meta">'.e($head['tax']).'</div>' : '');
    $noBlock = '<div style="font-size: 9pt; margin-top: 1.5mm"><span class="muted">'.e($labels['no']).'</span> <strong style="font-family: dejavusans">'.e($no).'</strong></div>'
        .'<div style="font-size: 9pt"><span class="muted">'.e($labels['date']).'</span> '.e($date).'</div>';
@endphp

@switch ($L['head'])
    @case('band')
    @case('dark')
        {{-- পুরো চওড়া রঙের পট্টি, সাদা লেখা --}}
        <table style="width: 100%; {{ $L['head'] === 'dark' ? 'background: '.$ink.';' : $bandBg }} border-radius: {{ $r }};">
            <tr>
                <td style="padding: 5mm 6mm; vertical-align: middle; color: #fff;">
                    <table><tr>
                        @if ($logoImg !== '')<td style="padding-right: 3mm; vertical-align: middle"><div style="background: #fff; padding: 1.2mm; border-radius: 1.5mm">{!! $logoImg !!}</div></td>@endif
                        <td style="vertical-align: middle; color: #fff">
                            <div style="font-size: 16pt; font-weight: bold; color: #fff">{{ $head['name'] }}</div>
                            <div style="font-size: 7.5pt; color: #e6e9ee">{{ implode(' · ', array_filter([$head['address'], $head['contact']])) }}</div>
                            @if ($head['tax'] !== '')<div style="font-size: 7.5pt; color: #e6e9ee">{{ $head['tax'] }}</div>@endif
                        </td>
                    </tr></table>
                </td>
                <td style="padding: 5mm 6mm; text-align: right; vertical-align: middle; width: 80mm; color: #fff">
                    <div style="font-size: 15pt; font-weight: bold; letter-spacing: 0.8mm; color: {{ $L['head'] === 'dark' ? $ac : '#fff' }}">{{ $title }}</div>{!! $dupHtml !!}
                    <div style="font-size: 9pt; color: #fff; font-family: dejavusans">{{ $no }}</div>
                    <div style="font-size: 8.5pt; color: #e6e9ee">{{ $date }}</div>
                </td>
            </tr>
        </table>
        {{-- ⭐ এই মাথায় QR নেই — সইয়ের সারিতে "Received by"-এর ডানে বসে (মালিক, ৩০ সেপ্টেম্বর ২০২৬); কাগজের partial বসায় ([[PaperLook::qrAtSignatures()]]) --}}
        @break

    @case('center')
        <div style="text-align: center">
            @if ($logoImg !== ''){!! $logoImg !!}@endif
            {!! $coBlock !!}
        </div>
        <table style="width: 100%; margin-top: 4mm; border-top: 0.4mm solid {{ $ac }}; border-bottom: 0.4mm solid {{ $ac }};">
            <tr>
                <td style="padding: 2mm 0; width: 35%">{!! $noBlock !!}</td>
                <td style="padding: 2mm 0; text-align: center"><span class="lf" style="{{ $titleCss }} font-weight: bold">{{ $title }}</span>{!! $dupHtml !!}</td>
                <td style="padding: 2mm 0; width: 35%; text-align: right">{!! $qrImg !!}</td>
            </tr>
        </table>
        @break

    @case('split')
        {{-- বাঁয়ে কোম্পানি, ডানে রঙিন ব্লকে শিরোনাম-নম্বর --}}
        <table style="width: 100%">
            <tr>
                <td style="vertical-align: middle">
                    <table><tr>
                        @if ($logoImg !== '')<td style="padding-right: 3mm; vertical-align: middle">{!! $logoImg !!}</td>@endif
                        <td style="vertical-align: middle">{!! $coBlock !!}</td>
                    </tr></table>
                </td>
                @if ($qrImg !== '')<td style="width: 32mm; text-align: center; vertical-align: middle">{!! $qrImg !!}</td>@endif
                <td style="width: 60mm; background: {{ $tint }}; border-left: 1.5mm solid {{ $ac }}; padding: 4mm; vertical-align: middle; border-radius: {{ $r }}">
                    <div style="font-size: 14pt; font-weight: bold; color: {{ $ac }}">{{ $title }}</div>{!! $dupHtml !!}
                    {!! $noBlock !!}
                </td>
            </tr>
        </table>
        @break

    @case('sidebar')
        <table style="width: 100%">
            <tr>
                <td style="width: 3mm; background: {{ $ac }}"></td>
                <td style="padding-left: 5mm; vertical-align: middle">
                    <table><tr>
                        @if ($logoImg !== '')<td style="padding-right: 3mm; vertical-align: middle">{!! $logoImg !!}</td>@endif
                        <td style="vertical-align: middle">{!! $coBlock !!}</td>
                    </tr></table>
                </td>
                @if ($qrImg !== '')<td style="width: 32mm; text-align: center; vertical-align: middle">{!! $qrImg !!}</td>@endif
                <td style="width: 58mm; text-align: right; vertical-align: middle">
                    <div class="lf" style="{{ $titleCss }} font-weight: bold">{{ $title }}</div>{!! $dupHtml !!}
                    {!! $noBlock !!}
                </td>
            </tr>
        </table>
        @break

    @case('minimal')
        {{-- ছোট নাম, বিশাল শিরোনাম — এখনকার সাদামাটা ধাঁচ --}}
        <table style="width: 100%">
            <tr>
                <td style="vertical-align: top">
                    <table><tr>
                        @if ($logoImg !== '')<td style="padding-right: 2.5mm; vertical-align: middle"><img src="{{ $head['logo'] }}" style="height: 9mm;" alt=""></td>@endif
                        <td style="vertical-align: middle"><div style="font-size: 10pt; font-weight: bold">{{ $head['name'] }}</div>
                            <div class="co-meta">{{ implode(' · ', array_filter([$head['address'], $head['contact'], $head['tax']])) }}</div></td>
                    </tr></table>
                    <div style="font-size: 26pt; font-weight: bold; color: {{ $ac }}; margin-top: 7mm; letter-spacing: -0.4mm">{{ $title }}</div>{!! $dupHtml !!}
                    {!! $noBlock !!}
                </td>
                @if ($qrImg !== '')<td style="width: 32mm; text-align: center; vertical-align: bottom">{!! $qrImg !!}</td>@endif
            </tr>
        </table>
        @break

    @case('brutal')
        {{-- নিও-ব্রুটালিজম: মোটা কালো দাগ, সমতল উজ্জ্বল রং --}}
        <table style="width: 100%; border: 0.9mm solid #000;">
            <tr>
                <td style="padding: 4mm; vertical-align: middle; border-right: 0.9mm solid #000">
                    <table><tr>
                        @if ($logoImg !== '')<td style="padding-right: 3mm; vertical-align: middle">{!! $logoImg !!}</td>@endif
                        <td style="vertical-align: middle">{!! $coBlock !!}</td>
                    </tr></table>
                </td>
                <td style="width: 80mm; padding: 4mm; background: {{ $ac }}; vertical-align: middle">
                    <div style="font-size: 16pt; font-weight: bold; color: #000">{{ $title }}</div>{!! $dupHtml !!}
                    {!! $noBlock !!}
                </td>
            </tr>
        </table>
        {{-- ⭐ এই মাথায় QR নেই — সইয়ের সারিতে "Received by"-এর ডানে বসে (মালিক, ৩০ সেপ্টেম্বর ২০২৬); কাগজের partial বসায় ([[PaperLook::qrAtSignatures()]]) --}}
        @break

    @case('form')
        {{-- সরকারি-ব্যাংক ফর্মের ধাঁচ: ঘেরা বাক্সে সব --}}
        <table style="width: 100%; border: 0.4mm solid {{ $ink }};">
            <tr>
                <td style="padding: 3mm; text-align: center; border-bottom: 0.4mm solid {{ $ink }}" colspan="3">
                    @if ($logoImg !== ''){!! $logoImg !!}@endif
                    {!! $coBlock !!}
                </td>
            </tr>
            <tr>
                <td style="padding: 2mm 3mm; width: 36%; border-right: 0.3mm solid {{ $ink }}">{!! $noBlock !!}</td>
                {{-- ⓘ QR না থাকলে (ভাউচার, আদেশ, রসিদ) ডানের ঘর নেই — শিরোনামই চওড়া; ফাঁকা বাক্স প্রশ্ন তোলে "এখানে কী থাকার কথা" --}}
                <td @if ($qrImg === '') colspan="2" @endif style="padding: 2mm 3mm; text-align: center; {{ $qrImg !== '' ? 'border-right: 0.3mm solid '.$ink.';' : '' }} background: {{ $tint }}"><div style="font-size: 13pt; font-weight: bold; letter-spacing: 1mm">{{ $title }}</div>{!! $dupHtml !!}</td>
                @if ($qrImg !== '')<td style="padding: 2mm 3mm; width: 28%; text-align: center">{!! $qrImg !!}</td>@endif
            </tr>
        </table>
        @break

    @default
        {{-- left: লোগো-নাম বাঁয়ে, শিরোনাম ডানে, নিচে রঙের দাগ --}}
        <table style="width: 100%; border-bottom: 0.8mm solid {{ $ac }};">
            <tr>
                <td style="vertical-align: middle; padding-bottom: 3mm">
                    <table><tr>
                        @if ($logoImg !== '')<td style="padding-right: 3mm; vertical-align: middle">{!! $logoImg !!}</td>@endif
                        <td style="vertical-align: middle">{!! $coBlock !!}</td>
                    </tr></table>
                </td>
                @if ($qrImg !== '')<td style="width: 32mm; text-align: center; vertical-align: middle; padding-bottom: 3mm">{!! $qrImg !!}</td>@endif
                <td style="width: 58mm; text-align: right; vertical-align: middle; padding-bottom: 3mm">
                    <div class="lf" style="{{ $titleCss }} font-weight: bold">{{ $title }}</div>{!! $dupHtml !!}
                    {!! $noBlock !!}
                </td>
            </tr>
        </table>
@endswitch

@if ($loud !== [])<div class="notice">{{ implode(' · ', $loud) }}</div>@endif
