{{--
    থার্মাল রোলের (৮০মিমি) ভাগের মাথা — ভাউচার · চালান · আদেশ · রসিদ; A4-এর [[look-head]]-এর সাদা-কালো রূপ।
    মালিক, ৩০ সেপ্টেম্বর ২০২৬: *"A4 A5 tharmal tintiroi"* — একই ২০ নকশা, একই ক্রমে।

    ── ⚠️ রোলে রং নেই ─────────────────────────────────────────────────────
    থার্মাল তাপে ছাপে: কালো আর সাদা, মাঝখানে কিছু নেই — ধূসর ভরাট ঝাপসা দাগ হয়। তাই রঙিন পট্টি এখানে
    কালো পট্টি (সাদা লেখা), রঙিন জমিন কেবল দাগ। নকশার চেনা ভাব থাকে মাথার ধরন, অক্ষর আর মোটের ঘরে।

    চাই: $L, $head, $title, $no, $date, $labels, $notices, আর ঐচ্ছিক $qrTop (QR মাথায়, নাহলে কাগজ নিজে বসায়)।
--}}
@php
    $lk = new \App\Core\Engines\Print\PaperLook($L);
    $font = $lk->thermalFont();
    $rule = $lk->thermalRule();
    $logoImg = $head['logo'] ? '<img src="'.e($head['logo']).'" style="height: 11mm;" alt="">' : '';
    $all = array_values(array_filter(array_map('strval', (array) ($notices ?? [])), fn ($n) => $n !== ''));
    // ⭐ নম্বরসহ লেখা, চেনা শুরুর শব্দে — [[PrintableDocument::isDuplicateNotice()]]
    $dupText = (string) (collect($all)->first(fn ($n) => \App\Core\Engines\Print\PrintableDocument::isDuplicateNotice($n)) ?? "\0");
    $isDup = in_array($dupText, $all, true);
    $loud = array_values(array_filter($all, fn ($n) => $n !== $dupText));
    $meta = implode(' · ', array_filter([$head['address'], $head['contact']]));
    $inverse = in_array($L['head'] ?? '', ['band', 'dark', 'brutal'], true);
@endphp
<style @nonce>
    body { font-family: hindsiliguri, sans-serif; font-size: 8pt; color: #000; }
    table { border-collapse: collapse; }
    .lf { font-family: {{ $font }}, hindsiliguri, sans-serif; }
    .bn { font-family: hindsiliguri, sans-serif; }
    .c { text-align: center; }
    .num { text-align: right; white-space: nowrap; }
    td.num { font-family: {{ $font === 'dejavusansmono' ? 'dejavusansmono' : 'dejavusans' }}; }
    .small { font-size: 7pt; }
    .rule { border-top: {{ $rule }}; margin: 1.5mm 0; height: 0; }
    .cap { font-size: 6.8pt; font-weight: bold; letter-spacing: 0.3mm; }
    table.kv { width: 100%; }
    table.kv td { padding: 0.3mm 0; font-size: 8pt; vertical-align: top; }
    table.it { width: 100%; }
    table.it th { font-size: 7.3pt; text-align: left; padding: 0.6mm 0; border-bottom: {{ $rule }}; }
    table.it th.num { text-align: right; }
    table.it td { font-size: 8pt; padding: 0.5mm 0; vertical-align: top; }
    table.it tr.sub td { font-size: 6.8pt; padding-top: 0; padding-bottom: 0.9mm; }
    table.it tr.tot td { font-weight: bold; border-top: {{ $rule }}; padding-top: 1mm; }
    @if (($L['table'] ?? '') === 'grid')
    table.it td, table.it th { border: 0.25mm solid #000; padding: 0.6mm 0.8mm; }
    @endif
    .notice { border: 0.5mm solid #000; text-align: center; font-weight: bold; padding: 1.2mm; margin-top: 1.5mm; font-size: 9pt; }
    table.signatures { width: 100%; margin-top: 9mm; }
    table.signatures td { text-align: center; font-size: 6.8pt; padding: 0 1mm; font-family: hindsiliguri; }
    .sig-line { border-top: 0.25mm solid #000; padding-top: 0.5mm; }
</style>

@if ($qrTop ?? '')
    <div class="c" style="margin-bottom: 1.5mm">{!! $qrTop !!}</div>
@endif

@if ($inverse)
    {{-- পট্টি-মাথা: কালো জমিনে সাদা নাম --}}
    <table style="width: 100%"><tr><td style="background: #000; color: #fff; padding: 2mm; text-align: center">
        @if ($logoImg !== '')<div style="background: #fff; padding: 0.8mm; display: inline">{!! $logoImg !!}</div>@endif
        <div class="lf" style="font-size: 12pt; font-weight: bold; color: #fff">{{ $head['name'] }}</div>
    </td></tr></table>
    <div class="c small" style="margin-top: 0.8mm">{{ $meta }}</div>
    @if ($head['tax'] !== '')<div class="c small">{{ $head['tax'] }}</div>@endif
@elseif (in_array($L['head'] ?? '', ['left', 'split', 'sidebar'], true))
    <table style="width: 100%; {{ ($L['head'] ?? '') === 'sidebar' ? 'border-left: 1.8mm solid #000;' : '' }}"><tr>
        @if ($logoImg !== '')<td style="width: 14mm; vertical-align: middle; {{ ($L['head'] ?? '') === 'sidebar' ? 'padding-left: 2mm;' : '' }}">{!! $logoImg !!}</td>@endif
        <td style="vertical-align: middle; padding-left: 1mm">
            <div class="lf" style="font-size: 11pt; font-weight: bold">{{ $head['name'] }}</div>
            <div class="small">{{ $meta }}</div>
            @if ($head['tax'] !== '')<div class="small">{{ $head['tax'] }}</div>@endif
        </td>
    </tr></table>
@elseif (($L['head'] ?? '') === 'form')
    <div class="c" style="border: 0.4mm solid #000; padding: 1.5mm">
        @if ($logoImg !== '')<div>{!! $logoImg !!}</div>@endif
        <div class="lf" style="font-size: 11pt; font-weight: bold">{{ $head['name'] }}</div>
        <div class="small">{{ $meta }}</div>
        @if ($head['tax'] !== '')<div class="small">{{ $head['tax'] }}</div>@endif
    </div>
@else
    <div class="c">
        @if ($logoImg !== '' && ($L['head'] ?? '') !== 'minimal')<div>{!! $logoImg !!}</div>@endif
        <div class="lf" style="font-size: {{ ($L['head'] ?? '') === 'minimal' ? 9 : 12 }}pt; font-weight: bold">{{ $head['name'] }}</div>
        <div class="small">{{ $meta }}</div>
        @if ($head['tax'] !== '')<div class="small">{{ $head['tax'] }}</div>@endif
    </div>
@endif

<div class="rule"></div>
<div class="c lf" style="font-size: {{ ($L['head'] ?? '') === 'minimal' ? 15 : 10.5 }}pt; font-weight: bold; letter-spacing: 0.5mm">{{ $title }}</div>
{{-- ⭐ "আগেও ছাপা হয়েছে" শিরোনামের ঠিক নিচে — মালিক, ৩০ সেপ্টেম্বর ২০২৬ --}}
@if ($isDup)<div class="c" data-duplicate><span style="background: #000; color: #fff; font-weight: bold; font-size: 7pt; padding: 0.4mm 1.5mm">{{ $dupText }}</span></div>@endif
@if ($loud !== [])<div class="notice">{{ implode(' · ', $loud) }}</div>@endif

<table class="kv" style="margin-top: 1.2mm">
    <tr><td>{{ $labels['no'] }}</td><td class="num"><strong>{{ $no }}</strong></td></tr>
    <tr><td>{{ $labels['date'] }}</td><td class="num">{{ $date }}</td></tr>
</table>
