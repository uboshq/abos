{{--
    ⭐ রেওয়ামিলের কাগজ — A4 খাড়া, লেজারের প্যাডে (মালিক, ১৭ নভেম্বর ২০২৬ "ok"; নমুনা `rs-tb-print`; [[TrialBalancePaper]])।

    ⓘ হিসাবের ধরন ধরে দল (সম্পদ, দায়, মালিকানা স্বত্ব, আয়, খরচ), প্রতিটার উপমোট; কোড নিজের কলামে, নামের পাশে দল; উল্টো জেরে ⚠।
    ⓘ নিচে "মিলেছে" সনদ (সবুজ) — তিন জোড়ায় পার্থক্য, বাদ পড়া ভাউচার, উল্টো জের, তালা দেওয়া মাস; তারপর তিন সই।
    ⓘ মাথায় বাক্স নয়, এক লাইনের তথ্য; সংখ্যা ইংরেজি অঙ্কে; অঙ্ক এখানে কষা হয় না ([[TrialBalanceStatement]])।
--}}
@php
    $ink = '#000000';
    $muted = '#444444';
    $line = '#bdbdbd';
    $green = '#1b6e2a';
    $red = '#b00020';
    $money = fn ($v) => bccomp((string) $v, '0', 4) === 0 ? '' : \App\Core\Support\Money::format((string) $v);
    $day = fn (string $d) => \Illuminate\Support\Carbon::parse($d)->format('d-m-Y');
    $cells = \App\Modules\Accounts\Services\TrialBalanceStatement::CELLS;
    $logo = $company->logoData();
@endphp
<html>
<head>
<style @nonce>
    @page { size: 210mm 297mm; margin: 10mm 10mm 14mm 10mm; margin-footer: 6mm; footer: html_tbFoot; }
    body { font-family: hindsiliguri; font-size: 8pt; color: {{ $ink }}; }
    .num { font-family: dejavusans; font-size: 7pt; text-align: right; white-space: nowrap; }
    table { border-collapse: collapse; }
    .pad td { vertical-align: middle; }
    .co-name { font-size: 15pt; font-weight: bold; }
    .co-meta { font-size: 8pt; color: {{ $muted }}; }
    .title { font-size: 14pt; font-weight: bold; text-align: right; }
    .title-en { font-size: 8pt; color: {{ $muted }}; text-align: right; }
    .rule { border-bottom: 0.8mm solid {{ $ink }}; height: 2mm; }
    table.info { margin-top: 1.5mm; border-bottom: 0.25mm solid {{ $line }}; font-size: 8.6pt; }
    table.info td { padding: 0.8mm 4mm 1.4mm 0; white-space: nowrap; }
    .k { color: {{ $muted }}; }
    table.tb { width: 100%; border: 0.3mm solid {{ $ink }}; margin-top: 3mm; }
    .tb th { font-weight: bold; text-align: center; padding: 1.2mm 1mm; border-bottom: 0.3mm solid {{ $ink }}; }
    .tb td { padding: 0.6mm 1.1mm; border-bottom: 0.1mm solid {{ $line }}; }
    .tb td.code { font-family: dejavusans; font-size: 7pt; }
    .tb tr.grp td { background-color: #dcdcdc; font-weight: bold; border-top: 0.3mm solid {{ $ink }}; }
    .tb .sub { font-size: 6.6pt; color: {{ $muted }}; }
    .tb .flag { font-weight: bold; color: {{ $red }}; }
    .tb tr.total td { font-weight: bold; border-top: 0.5mm solid {{ $ink }}; border-bottom: 0.6mm double {{ $ink }}; background-color: #e2e2e2; }
    .cert { margin-top: 2mm; border: 0.3mm solid {{ $green }}; padding: 1.4mm 2.4mm; font-size: 7.6pt; line-height: 1.5; }
    .cert.bad { border-color: {{ $red }}; }
    .ok { color: {{ $green }}; font-weight: bold; }
    .bad-ink { color: {{ $red }}; font-weight: bold; }
    .sym { font-family: dejavusans; }
    .tb tr.grp td.num, .tb tr.total td.num { font-size: 6.6pt; }
    .sign { border-top: 0.25mm solid {{ $ink }}; text-align: center; font-size: 9pt; padding-top: 1.2mm; }
</style>
</head>
<body>

<htmlpagefooter name="tbFoot">
    <table width="100%"><tr>
        <td style="font-size: 8pt; color: {{ $muted }};">{{ __('core.listing.printed_at') }}: {{ $printedAt }} · ABOS @if (! $signatures) · {{ __('core.listing.computer_made') }}@endif</td>
        <td style="text-align: right; font-size: 9pt; font-weight: bold;">{{ __('core.listing.page') }} {PAGENO} / {nbpg}</td>
    </tr></table>
</htmlpagefooter>

<table class="pad" width="100%"><tr>
    @if ($logo)
        <td width="17mm"><img src="{{ $logo }}" style="height: 14mm;" alt=""></td>
    @endif
    <td>
        <div class="co-name">{{ $company->name() }}</div>
        @if ($company->address())<div class="co-meta">{{ $company->address() }}@if ($company->phone) · {{ __('core.listing.phone') }} {{ $company->phone }}@endif @if ($company->bin) · BIN {{ $company->bin }}@endif</div>@endif
    </td>
    <td width="70mm" style="text-align: right;">
        <div class="title">{{ __('accounts::menu.trial_balance') }}</div>
        @if ($locale === 'bn')<div class="title-en">TRIAL BALANCE</div>@endif
    </td>
</tr></table>
<div class="rule"></div>

{{-- ⛔ মাথায় বাক্স নয়, এক লাইনের তথ্য (মালিক, ১৬ নভেম্বর ২০২৬) --}}
<table class="info" width="100%" data-info-line><tr>
    <td><span class="k">{{ __('core.listing.period') }}:</span> <b>{{ $tb['all_time'] ? __('core.listing.since_start', ['to' => $day($tb['to'])]) : __('core.listing.range', ['from' => $day($tb['from']), 'to' => $day($tb['to'])]) }}</b></td>
    <td><span class="k">{{ __('core.listing.branch') }}:</span> <b>{{ $branch }}</b></td>
    <td><span class="k">{{ __('accounts::fs.basis') }}:</span> <b>{{ __('accounts::fs.accrual_taka') }}</b></td>
    <td style="text-align: right;"><span class="k">{{ __('core.listing.printed_by') }}:</span> <b>{{ $printedBy }}</b></td>
</tr></table>

<table class="tb" width="100%" repeat_header="1">
    <thead>
        <tr>
            <th rowspan="2" width="6.5%">{{ __('accounts::fs.tb.code') }}</th>
            <th rowspan="2" style="text-align: left;">{{ __('core.print.account') }}</th>
            <th colspan="2">{{ $tb['all_time'] ? __('accounts::fs.tb.opening_start') : __('accounts::fs.tb.opening_on', ['date' => $day($tb['from'])]) }}</th>
            <th colspan="2">{{ __('accounts::fs.tb.movement') }}</th>
            <th colspan="2">{{ __('accounts::fs.tb.closing_on', ['date' => $day($tb['to'])]) }}</th>
        </tr>
        <tr>
            @foreach ($cells as $i => $cell)
                <th width="11.5%" style="text-align: right;">{{ $i % 2 === 0 ? __('core.table.debit') : __('core.table.credit') }}</th>
            @endforeach
        </tr>
    </thead>
    <tbody>
        @foreach ($tb['groups'] as $group)
            <tr class="grp" data-tb-class="{{ $group['type'] }}">
                <td colspan="2">{{ __('accounts::fs.class.'.$group['type']) }}@if ($locale === 'bn') <span style="font-weight: normal; color: {{ $muted }};">· {{ __('accounts::fs.class_en.'.$group['type']) }}</span>@endif</td>
                @foreach ($cells as $cell)
                    <td class="num">{{ $money($group['sum'][$cell]) }}</td>
                @endforeach
            </tr>
            @foreach ($group['rows'] as $row)
                <tr>
                    <td class="code">{{ $row['code'] }}</td>
                    <td>{{ $row['name'] }}@if ($row['wrong_side']) <span class="flag" data-tb-flag><span class="sym">&#9888;</span> {{ __('accounts::fs.tb.wrong_flag') }}</span>@endif @if ($row['group'] !== '')<span class="sub">· {{ $row['group'] }}</span>@endif</td>
                    @foreach ($cells as $cell)
                        <td class="num">{{ $money($row['values'][$cell]) }}</td>
                    @endforeach
                </tr>
            @endforeach
        @endforeach
        <tr class="total">
            <td colspan="2">{{ __('accounts::fs.grand_total') }}</td>
            @foreach ($cells as $cell)
                <td class="num">{{ \App\Core\Support\Money::format($tb['total'][$cell]) }}</td>
            @endforeach
        </tr>
        <tr>
            <td colspan="2">{{ __('accounts::fs.tb.difference') }}</td>
            @foreach (['opening', 'period', 'closing'] as $pair)
                <td colspan="2" class="num" style="text-align: center; font-weight: bold;">{{ \App\Core\Support\Money::format($tb['gaps'][$pair]) }}{{ bccomp($tb['gaps'][$pair], '0', 2) === 0 ? ' ✓' : '' }}</td>
            @endforeach
        </tr>
    </tbody>
</table>

{{-- ⭐ "মিলেছে" সনদ — সবুজ; না মিললে লাল, কত তা লেখা --}}
<div @class(['cert', 'bad' => ! $tb['agrees']]) data-cert="{{ $tb['agrees'] ? 'agrees' : 'differs' }}">
    @if ($tb['agrees'])
        <span class="ok"><span class="sym">✓</span> {{ __('accounts::fs.tb.cert_ok') }}</span>
    @else
        <span class="bad-ink">✗ {{ __('accounts::fs.tb.does_not_agree', ['gap' => \App\Core\Support\Money::format($tb['gaps']['closing'])]) }}</span>
    @endif
    <br>
    {{ $tb['held']['count'] === 0
        ? __('accounts::fs.tb.cert_held_none')
        : __('accounts::fs.tb.cert_held', ['count' => $tb['held']['count'], 'awaiting' => $tb['held']['awaiting'], 'amount' => \App\Core\Support\Money::format($tb['held']['amount'])]) }}
    <br>
    @if ($tb['wrong_side'] === [])
        {{ __('accounts::fs.tb.wrong_none') }}
    @else
        <span class="bad-ink sym">&#9888;</span> {{ __('accounts::fs.tb.wrong_some', ['list' => collect($tb['wrong_side'])->map(fn ($w) => $w['code'].' '.$w['name'].' ('.__('accounts::fs.side.'.$w['side']).' '.\App\Core\Support\Money::format($w['amount']).')')->implode(', ')]) }}
    @endif
    <br>
    {{ $tb['locked'] === [] ? __('accounts::fs.tb.locked_none') : __('accounts::fs.tb.locked', ['months' => implode(', ', $tb['locked'])]) }}
</div>

@if ($signatures)
    <table width="100%" style="margin-top: 16mm;" data-signs="3"><tr>
        <td width="30%" class="sign">{{ __('accounts::fs.sign.prepared') }}</td>
        <td width="5%"></td>
        <td width="30%" class="sign">{{ __('accounts::fs.sign.checked') }}</td>
        <td width="5%"></td>
        <td width="30%" class="sign">{{ __('accounts::fs.sign.approved') }}</td>
    </tr></table>
@endif

</body>
</html>
