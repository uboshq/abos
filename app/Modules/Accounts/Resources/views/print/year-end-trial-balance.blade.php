{{--
    ⭐ বছর-শেষের রেওয়ামিলের কাগজ — A4 আড়াআড়ি, লেজারের প্যাডে (মালিক, ১৭ নভেম্বর ২০২৬ "ok"; [[YearEndTrialBalance]]; সাধারণ
    রেওয়ামিলের কাগজের ছাঁচ, [[accounts::print.trial-balance]])।

    ⓘ কলাম: কোড · খাত · সমন্বয়ের আগে (ডে/ক্রে) · সমন্বয় (ডে/ক্রে/নং) · সমন্বয়ের পরে (ডে/ক্রে) · আগের বছর-শেষ (ডে/ক্রে)।
    ⓘ দল ধরে উপমোট, উল্টো জেরে ⚠; নিচে নম্বর দেওয়া সমন্বয় জাবেদার তালিকা, "মিলেছে" সনদ আর তিন সই। সংখ্যা ইংরেজি অঙ্কে;
      অঙ্ক এখানে কষা হয় না। বাংলা বা ইংরেজি (`?lang=en`)।
--}}
@php
    $ink = '#000000';
    $muted = '#444444';
    $line = '#bdbdbd';
    $green = '#1b6e2a';
    $red = '#b00020';
    $money = fn ($v) => bccomp((string) $v, '0', 4) === 0 ? '' : \App\Core\Support\Money::format((string) $v);
    $day = fn (string $d) => \Illuminate\Support\Carbon::parse($d)->format('d-m-Y');
    $cells = \App\Modules\Accounts\Services\YearEndTrialBalance::CELLS;
    $y = $ytb;
    $logo = $company->logoData();
@endphp
<html>
<head>
<style @nonce>
    @page { size: 297mm 210mm; margin: 9mm 9mm 13mm 9mm; margin-footer: 5mm; footer: html_ytbFoot; }
    body { font-family: hindsiliguri; font-size: 7.6pt; color: {{ $ink }}; }
    .num { font-family: dejavusans; font-size: 6.6pt; text-align: right; white-space: nowrap; }
    table { border-collapse: collapse; }
    .pad td { vertical-align: middle; }
    .co-name { font-size: 14pt; font-weight: bold; }
    .co-meta { font-size: 8pt; color: {{ $muted }}; }
    .title { font-size: 13pt; font-weight: bold; text-align: right; }
    .title-en { font-size: 8pt; color: {{ $muted }}; text-align: right; }
    .rule { border-bottom: 0.8mm solid {{ $ink }}; height: 2mm; }
    table.info { margin-top: 1.5mm; border-bottom: 0.25mm solid {{ $line }}; font-size: 8.2pt; }
    table.info td { padding: 0.8mm 4mm 1.4mm 0; white-space: nowrap; }
    .k { color: {{ $muted }}; }
    table.tb { width: 100%; border: 0.3mm solid {{ $ink }}; margin-top: 3mm; }
    .tb th { font-weight: bold; text-align: center; padding: 1.1mm 0.8mm; border-bottom: 0.3mm solid {{ $ink }}; }
    .tb td { padding: 0.5mm 0.9mm; border-bottom: 0.1mm solid {{ $line }}; }
    .tb td.code { font-family: dejavusans; font-size: 6.6pt; }
    .tb td.ref { font-family: dejavusans; font-size: 6pt; color: {{ $muted }}; }
    .tb tr.grp td { background-color: #dcdcdc; font-weight: bold; border-top: 0.3mm solid {{ $ink }}; }
    .tb .sub { font-size: 6.4pt; color: {{ $muted }}; }
    .tb .flag { font-weight: bold; color: {{ $red }}; }
    .tb tr.total td { font-weight: bold; border-top: 0.5mm solid {{ $ink }}; border-bottom: 0.6mm double {{ $ink }}; background-color: #e2e2e2; }
    .tb .sep { border-left: 0.3mm solid {{ $ink }}; }
    table.sched { width: 100%; border: 0.3mm solid {{ $ink }}; margin-top: 3mm; }
    .sched th { font-weight: bold; padding: 1mm; border-bottom: 0.3mm solid {{ $ink }}; text-align: left; }
    .sched td { padding: 0.5mm 1mm; border-bottom: 0.1mm solid {{ $line }}; }
    .cert { margin-top: 2mm; border: 0.3mm solid {{ $green }}; padding: 1.4mm 2.4mm; font-size: 7.4pt; line-height: 1.5; }
    .cert.bad { border-color: {{ $red }}; }
    .ok { color: {{ $green }}; font-weight: bold; }
    .bad-ink { color: {{ $red }}; font-weight: bold; }
    .sym { font-family: dejavusans; }
    .sign { border-top: 0.25mm solid {{ $ink }}; text-align: center; font-size: 9pt; padding-top: 1.2mm; }
</style>
</head>
<body>

<htmlpagefooter name="ytbFoot">
    <table width="100%"><tr>
        <td style="font-size: 8pt; color: {{ $muted }};">{{ __('core.listing.printed_at') }}: {{ $printedAt }} · ABOS @if (! $signatures) · {{ __('core.listing.computer_made') }}@endif</td>
        <td style="text-align: right; font-size: 9pt; font-weight: bold;">{{ __('core.listing.page') }} {PAGENO} / {nbpg}</td>
    </tr></table>
</htmlpagefooter>

<table class="pad" width="100%"><tr>
    @if ($logo)
        <td width="17mm"><img src="{{ $logo }}" style="height: 13mm;" alt=""></td>
    @endif
    <td>
        <div class="co-name">{{ $company->name() }}</div>
        @if ($company->address())<div class="co-meta">{{ $company->address() }}@if ($company->phone) · {{ __('core.listing.phone') }} {{ $company->phone }}@endif @if ($company->bin) · BIN {{ $company->bin }}@endif</div>@endif
    </td>
    <td width="90mm" style="text-align: right;">
        <div class="title">{{ __('accounts::fs.ytb.title') }}</div>
        @if ($locale === 'bn')<div class="title-en">{{ __('accounts::fs.ytb.title_en') }}</div>@endif
    </td>
</tr></table>
<div class="rule"></div>

{{-- ⛔ মাথায় বাক্স নয়, এক লাইনের তথ্য (মালিক, ১৬ নভেম্বর ২০২৬) --}}
<table class="info" width="100%" data-info-line><tr>
    <td><span class="k">{{ __('accounts::fs.ytb.year') }}:</span> <b>{{ __('accounts::fs.ytb.year_range', ['name' => $y['year']->name, 'from' => $day($y['from']), 'to' => $day($y['to'])]) }}</b></td>
    <td><span class="k">{{ __('core.listing.branch') }}:</span> <b>{{ $branch }}</b></td>
    <td><span class="k">{{ __('accounts::fs.basis') }}:</span> <b>{{ __('accounts::fs.accrual_taka') }}</b></td>
    <td>{{ __('accounts::fs.ytb.pre_closing') }}</td>
    <td style="text-align: right;"><span class="k">{{ __('core.listing.printed_by') }}:</span> <b>{{ $printedBy }}</b></td>
</tr></table>

<table class="tb" width="100%" repeat_header="1">
    <thead>
        <tr>
            <th rowspan="2" width="5%">{{ __('accounts::fs.tb.code') }}</th>
            <th rowspan="2" style="text-align: left;">{{ __('core.print.account') }}</th>
            <th colspan="2" class="sep">{{ __('accounts::fs.ytb.before') }}</th>
            <th colspan="3" class="sep">{{ __('accounts::fs.ytb.adjustments') }}</th>
            <th colspan="2" class="sep">{{ __('accounts::fs.ytb.after', ['date' => $day($y['to'])]) }}</th>
            <th colspan="2" class="sep">{{ __('accounts::fs.ytb.prev', ['date' => $day($y['prev_end'])]) }}</th>
        </tr>
        <tr>
            @foreach ($cells as $i => $cell)
                <th width="8.4%" @class(['sep' => $i % 2 === 0]) style="text-align: right;">{{ $i % 2 === 0 ? __('core.table.debit') : __('core.table.credit') }}</th>
                @if ($cell === 'adj_credit')<th width="4%">{{ __('accounts::fs.ytb.refs') }}</th>@endif
            @endforeach
        </tr>
    </thead>
    <tbody>
        @foreach ($y['groups'] as $group)
            <tr class="grp" data-tb-class="{{ $group['type'] }}">
                <td colspan="2">{{ __('accounts::fs.class.'.$group['type']) }}@if ($locale === 'bn') <span style="font-weight: normal; color: {{ $muted }};">· {{ __('accounts::fs.class_en.'.$group['type']) }}</span>@endif</td>
                @foreach ($cells as $i => $cell)
                    <td @class(['num', 'sep' => $i % 2 === 0])>{{ $money($group['sum'][$cell]) }}</td>
                    @if ($cell === 'adj_credit')<td></td>@endif
                @endforeach
            </tr>
            @foreach ($group['rows'] as $row)
                <tr>
                    <td class="code">{{ $row['code'] }}</td>
                    <td>{{ $row['name'] }}@if ($row['wrong_side']) <span class="flag" data-tb-flag><span class="sym">&#9888;</span> {{ __('accounts::fs.tb.wrong_flag') }}</span>@endif @if ($row['group'] !== '')<span class="sub">· {{ $row['group'] }}</span>@endif</td>
                    @foreach ($cells as $i => $cell)
                        <td @class(['num', 'sep' => $i % 2 === 0])>{{ $money($row['values'][$cell]) }}</td>
                        @if ($cell === 'adj_credit')<td class="ref">{{ $row['refs'] === [] ? '' : implode(',', $row['refs']) }}</td>@endif
                    @endforeach
                </tr>
            @endforeach
        @endforeach
        <tr class="total">
            <td colspan="2">{{ __('accounts::fs.grand_total') }}</td>
            @foreach ($cells as $i => $cell)
                <td @class(['num', 'sep' => $i % 2 === 0])>{{ \App\Core\Support\Money::format($y['total'][$cell]) }}</td>
                @if ($cell === 'adj_credit')<td></td>@endif
            @endforeach
        </tr>
        <tr>
            <td colspan="2">{{ __('accounts::fs.ytb.difference') }}</td>
            @foreach (\App\Modules\Accounts\Services\YearEndTrialBalance::PAIRS as $pair)
                <td colspan="{{ $pair === 'adj' ? 3 : 2 }}" class="num sep" style="text-align: center; font-weight: bold;">{{ \App\Core\Support\Money::format($y['gaps'][$pair]) }}{{ bccomp($y['gaps'][$pair], '0', 2) === 0 ? ' ✓' : '' }}</td>
            @endforeach
        </tr>
    </tbody>
</table>

{{-- ── নম্বর দেওয়া সমন্বয় জাবেদা ── --}}
@if ($y['adjusting'] !== [])
    <table class="sched" width="100%" repeat_header="1" data-ytb-schedule>
        <thead>
            <tr><th colspan="5">{{ __('accounts::fs.ytb.schedule') }}</th></tr>
            <tr>
                <th width="5%">{{ __('accounts::fs.ytb.no') }}</th>
                <th width="10%">{{ __('accounts::fs.ytb.date') }}</th>
                <th width="13%">{{ __('accounts::fs.ytb.voucher') }}</th>
                <th>{{ __('accounts::fs.ytb.narration') }}</th>
                <th width="12%" style="text-align: right;">{{ __('accounts::fs.ytb.amount') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($y['adjusting'] as $entry)
                <tr>
                    <td class="num" style="text-align: left;">{{ $entry['no'] }}</td>
                    <td class="num" style="text-align: left;">{{ $day($entry['date']) }}</td>
                    <td class="num" style="text-align: left;">{{ $entry['document_no'] }}</td>
                    <td>{{ $entry['narration'] }}@if ($entry['reversal']) · {{ __('accounts::fs.ytb.reversal') }}@endif</td>
                    <td class="num">{{ \App\Core\Support\Money::format($entry['amount']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

{{-- ⭐ "মিলেছে" সনদ — সবুজ; না মিললে লাল, কত তা লেখা --}}
<div @class(['cert', 'bad' => ! $y['agrees']]) data-cert="{{ $y['agrees'] ? 'agrees' : 'differs' }}">
    @if ($y['agrees'])
        <span class="ok"><span class="sym">✓</span> {{ __('accounts::fs.ytb.cert_ok') }}</span>
    @else
        <span class="bad-ink">✗ {{ __('accounts::fs.ytb.does_not_agree', ['gap' => \App\Core\Support\Money::format($y['gaps']['after'])]) }}</span>
    @endif
    <br>
    {{ $y['adjusting'] === []
        ? __('accounts::fs.ytb.no_adjusting')
        : __('accounts::fs.ytb.adjusting_count', ['count' => count($y['adjusting']), 'amount' => \App\Core\Support\Money::format($y['total']['adj_debit'])]) }}
    · {{ $y['closed'] ? __('accounts::fs.ytb.closed') : __('accounts::fs.ytb.open') }}
    <br>
    {{ $y['held']['count'] === 0
        ? __('accounts::fs.tb.cert_held_none')
        : __('accounts::fs.tb.cert_held', ['count' => $y['held']['count'], 'awaiting' => $y['held']['awaiting'], 'amount' => \App\Core\Support\Money::format($y['held']['amount'])]) }}
    <br>
    @if ($y['wrong_side'] === [])
        {{ __('accounts::fs.tb.wrong_none') }}
    @else
        <span class="bad-ink sym">&#9888;</span> {{ __('accounts::fs.tb.wrong_some', ['list' => collect($y['wrong_side'])->map(fn ($w) => $w['code'].' '.$w['name'].' ('.__('accounts::fs.side.'.$w['side']).' '.\App\Core\Support\Money::format($w['amount']).')')->implode(', ')]) }}
    @endif
    <br>
    {{ $y['locked'] === [] ? __('accounts::fs.tb.locked_none') : __('accounts::fs.tb.locked', ['months' => implode(', ', $y['locked'])]) }}
</div>

@if ($signatures)
    <table width="100%" style="margin-top: 14mm;" data-signs="3"><tr>
        <td width="28%" class="sign">{{ __('accounts::fs.sign.prepared') }}</td>
        <td width="8%"></td>
        <td width="28%" class="sign">{{ __('accounts::fs.sign.checked') }}</td>
        <td width="8%"></td>
        <td width="28%" class="sign">{{ __('accounts::fs.sign.approved') }}</td>
    </tr></table>
@endif

</body>
</html>
