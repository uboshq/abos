{{--
    ⭐ দিন শেষের হিসাবের কাগজ — মালিকের অনুমোদিত নমুনা v7 হুবহু (১৬ নভেম্বর ২০২৬): A4 খাড়া, লেখা যতটুকু পাতা ততটা, কোনো টেবিল দুই পাতায় ভাঙে না।
    মাথায় কোম্পানি আর "দিন শেষের হিসাব · DAY CLOSING · DAILY FLASH REPORT", এক লাইনের তথ্য, "আজকের খবর", তারপর ১–১১ অংশ, শেষে "দিন বন্ধ" আর তিন সই।

    ⓘ সংখ্যা এখানে কষা হয় না — [[DayClosingService::build()]]; পর্দাও একই।
    ⓘ অঙ্ক ইংরেজিতে, কাগজের ভাষা যা-ই হোক (মালিক, ২৪ অক্টোবর ২০২৬)।
--}}
@php
    $ink = '#000000';
    $muted = '#555555';
    $line = '#bdbdbd';
    $m = fn ($v) => $v === null ? '' : \App\Core\Support\Money::format((string) $v);
    $z = fn ($v) => ($v === null || bccomp((string) $v, '0', 4) === 0) ? '' : \App\Core\Support\Money::format((string) $v);
    $neg = fn ($v) => $v === null ? '' : (bccomp((string) $v, '0', 4) < 0 ? '('.\App\Core\Support\Money::format(ltrim((string) $v, '-')).')' : \App\Core\Support\Money::format((string) $v));
    $less = fn ($v) => '('.\App\Core\Support\Money::format(ltrim((string) $v, '-')).')';
    $day = $r['date'];
    $cal = $r['calendar'];
    $logo = $company->logoData();
    $sec = fn (int $n, string $key) => '<table width="100%" class="sec"><tr><td><span class="no">'.$n.'</span> <b>'.e(__('accounts::dayclose.sec.'.$key)[0]).'</b></td><td class="en">'.e(__('accounts::dayclose.sec.'.$key)[1]).'</td></tr></table>';
    $news = $r['news'];
    $half = (int) ceil(count($news) / 2);
    $fmtNews = function (array $n) use ($m) {
        $p = $n['params'];
        foreach (['amount', 'delivered', 'total', 'cash', 'bank', 'mfs', 'gap', 'per_day'] as $k) {
            if (isset($p[$k]) && is_numeric($p[$k])) { $p[$k] = $m($p[$k]); }
        }
        return __('accounts::dayclose.news.'.$n['key'], $p);
    };
@endphp
<html>
<head>
<style @nonce>
    @page { size: 210mm 297mm; margin: 16mm 9mm 13mm 9mm; margin-header: 6mm; margin-footer: 5mm; header: html_dcRun; footer: html_dcFoot; }
    /* ⓘ প্রথম পাতায় চলমান মাথা নয় — সেখানে কোম্পানির পুরো মাথা */
    @page :first { margin-top: 9mm; header: _blank; }
    body { font-family: hindsiliguri; font-size: 7.8pt; color: {{ $ink }}; }
    .num { font-family: dejavusans; font-size: 7pt; text-align: right; white-space: nowrap; }
    .mono { font-family: dejavusansmono; font-size: 6.8pt; white-space: nowrap; }
    table { border-collapse: collapse; }
    .co-name { font-size: 15pt; font-weight: bold; }
    .co-meta { font-size: 7.5pt; color: {{ $muted }}; }
    .title { font-size: 15pt; font-weight: bold; text-align: right; }
    .title-en { font-size: 7.5pt; color: {{ $muted }}; text-align: right; letter-spacing: 0.3mm; }
    .rule { border-bottom: 0.8mm solid {{ $ink }}; height: 1.5mm; }
    table.info td { padding: 1mm 3mm 1mm 0; white-space: nowrap; font-size: 8pt; }
    .k { color: {{ $muted }}; }
    .news { border: 0.3mm solid #999999; background-color: #f2f2f2; padding: 1.5mm 2.5mm; margin-top: 1.5mm; }
    .news td { vertical-align: top; font-size: 7.6pt; padding: 0.4mm 2mm 0.4mm 0; }
    table.sec { margin-top: 2.6mm; }
    table.sec td { font-size: 10pt; padding: 0; }
    table.sec td.en { text-align: right; font-size: 7pt; color: {{ $muted }}; letter-spacing: 0.2mm; }
    .no { background-color: #000000; color: #ffffff; font-size: 7.5pt; font-weight: bold; padding: 0.3mm 1.4mm; }
    table.t { width: 100%; border: 0.3mm solid {{ $ink }}; margin-top: 1mm; page-break-inside: avoid; }
    .t th { font-weight: bold; text-align: center; padding: 1mm 0.8mm; border-bottom: 0.35mm solid {{ $ink }}; font-size: 7.6pt; }
    .t td { padding: 0.7mm 1mm; border-bottom: 0.1mm solid {{ $line }}; }
    .t tr.g td { background-color: #e6e6e6; font-weight: bold; }
    .t tr.s td { background-color: #eeeeee; font-weight: bold; }
    .t tr.tot td { background-color: #dcdcdc; font-weight: bold; border-top: 0.35mm solid {{ $ink }}; }
    .t td.ind { padding-left: 4mm; }
    .sub { color: {{ $muted }}; }
    .closebox { border: 0.35mm solid {{ $ink }}; background-color: #f2f2f2; padding: 2mm 3mm; margin-top: 3mm; font-size: 8pt; page-break-inside: avoid; }
    .sign { border-top: 0.25mm solid {{ $ink }}; text-align: center; font-size: 8.5pt; padding-top: 1.2mm; }
    .running { font-size: 8.5pt; border-bottom: 0.25mm solid {{ $line }}; }
</style>
</head>
<body>

<htmlpageheader name="dcRun">
    <table width="100%" class="running"><tr>
        <td><b>{{ __('accounts::dayclose.title') }}</b> · {{ $company->name() }} · {{ $day->format('d-m-Y') }}</td>
        <td style="text-align: right;">{{ __('core.listing.page') }} {PAGENO} / {nbpg}</td>
    </tr></table>
</htmlpageheader>
<htmlpagefooter name="dcFoot">
    <table width="100%"><tr>
        <td style="font-size: 7.5pt; color: {{ $muted }};">{{ __('core.listing.printed_at') }}: {{ $printedAt }} · ABOS</td>
        <td style="text-align: right; font-size: 9pt; font-weight: bold;">{{ __('core.listing.page') }} {PAGENO} / {nbpg}</td>
    </tr></table>
</htmlpagefooter>

{{-- ── মাথা ── --}}
<table width="100%"><tr>
    @if ($logo)<td width="17mm"><img src="{{ $logo }}" style="height: 14mm;" alt=""></td>@endif
    <td>
        <div class="co-name">{{ $company->name() }}</div>
        @if ($company->address())<div class="co-meta">{{ $company->address() }}@if ($company->phone) · {{ __('core.listing.phone') }} {{ $company->phone }}@endif @if ($company->bin) · BIN {{ $company->bin }}@endif</div>@endif
    </td>
    <td width="72mm"><div class="title">{{ __('accounts::dayclose.title') }}</div><div class="title-en">{{ __('accounts::dayclose.caption') }}</div></td>
</tr></table>
<div class="rule"></div>

{{-- ⛔ মাথায় বাক্স নয়, এক লাইনের তথ্য --}}
<table class="info" width="100%" data-info-line><tr>
    <td><span class="k">{{ __('accounts::dayclose.date') }}:</span> <b>{{ $day->format('d-m-Y') }} ({{ $day->copy()->locale($locale)->translatedFormat('l') }})</b></td>
    <td><span class="k">{{ __('accounts::dayclose.month') }}:</span> <b>{{ __('accounts::dayclose.month_value', ['month' => $day->copy()->locale($locale)->translatedFormat('F Y'), 'day' => $cal['day_of_month'], 'done' => $cal['done']]) }}</b></td>
    <td><span class="k">{{ __('accounts::dayclose.branch') }}:</span> <b>{{ $branch }}</b></td>
    <td><span class="k">{{ __('accounts::dayclose.closed_by') }}:</span> <b>{{ $r['closed']?->closer?->name ?? __('accounts::dayclose.not_closed') }}</b></td>
    <td style="text-align: right;"><span class="k">{{ __('accounts::dayclose.printed_by') }}:</span> <b>{{ $printedBy }}</b></td>
</tr></table>

{{-- ── আজকের খবর ── --}}
<div class="news" data-news>
    <b style="font-size: 9pt;">{{ __('accounts::dayclose.news_title') }}</b>
    <table width="100%"><tr>
        <td width="50%">@foreach (array_slice($news, 0, $half) as $n)• {{ $fmtNews($n) }}<br>@endforeach</td>
        <td width="50%">@foreach (array_slice($news, $half) as $n)• {{ $fmtNews($n) }}<br>@endforeach</td>
    </tr></table>
</div>

{{-- ⭐ কর্মীর হাতে টাকা — হাইলাইট করে (মালিক, ১৮ নভেম্বর ২০২৬) --}}
@if ($r['staff_cash'] !== [])
    <div class="news" style="background-color: #fff3cd; border-color: #b8860b; margin-top: 1.5mm;" data-staff-cash>
        <b style="font-size: 9pt;">{{ __('accounts::dayclose.staff_cash.title') }}</b><br>
        @foreach ($r['staff_cash'] as $s)• <b>{{ __('accounts::dayclose.staff_cash.row', ['who' => $s['who'] ?? __('accounts::dayclose.staff_cash.nobody'), 'amount' => $m($s['amount']), 'account' => $s['account']]) }}</b><br>@endforeach
        <span class="sub">{{ __('accounts::dayclose.staff_cash.note') }}</span>
    </div>
@endif

{{-- ── ১ · আজ টাকা এল ── --}}
{!! $sec(1, 'inflow') !!}
<table class="t" data-sec="inflow">
    <thead><tr><th width="6%">{{ __('accounts::dayclose.col.sl') }}</th><th>{{ __('accounts::dayclose.col.party') }}</th><th width="20%">{{ __('accounts::dayclose.col.account') }}</th>
        <th width="22%">{{ __('accounts::dayclose.col.how') }}</th><th width="12%">{{ __('accounts::dayclose.col.amount') }}</th><th width="7%">{{ __('accounts::dayclose.col.charge') }}</th><th width="11%">{{ __('accounts::dayclose.col.note') }}</th></tr></thead>
    <tbody>
        @php($sl = 0)
        @forelse ($r['inflow']['groups'] as $g)
            <tr class="g"><td colspan="7">{{ __('accounts::dayclose.kind.'.$g['kind']) }}</td></tr>
            @foreach ($g['rows'] as $row)
                <tr><td style="text-align: center;">{{ ++$sl }}</td><td>{{ $row['party'] ?? '—' }}</td><td>{{ $row['account'] }}</td><td class="sub">{{ $row['how'] }}</td>
                    <td class="num">{{ $m($row['amount']) }}</td><td class="num">{{ $z($row['charge']) }}</td><td class="sub">{{ \Illuminate\Support\Str::limit($row['note'], 28) }}</td></tr>
            @endforeach
            <tr class="s"><td colspan="4" style="text-align: right;">{{ __('accounts::dayclose.subtotal', ['kind' => __('accounts::dayclose.kind.'.$g['kind'])]) }}</td><td class="num">{{ $m($g['total']) }}</td><td class="num">{{ $z($g['charge']) }}</td><td></td></tr>
        @empty
            <tr><td colspan="7" class="sub" style="text-align: center;">{{ __('accounts::dayclose.empty') }}</td></tr>
        @endforelse
        <tr class="tot"><td colspan="4" style="text-align: right;">{{ __('accounts::dayclose.inflow_total') }}</td><td class="num">{{ $m($r['inflow']['total']) }}</td><td class="num">{{ $z($r['inflow']['charge']) }}</td><td></td></tr>
    </tbody>
</table>

{{-- ── ২ · আজকের ডেলিভারি ── --}}
{!! $sec(2, 'delivery') !!}
<table class="t" data-sec="delivery">
    <thead><tr><th width="6%">{{ __('accounts::dayclose.col.sl') }}</th><th width="12%">{{ __('accounts::dayclose.col.challan') }}</th><th>{{ __('accounts::dayclose.col.party') }}</th>
        <th width="14%">{{ __('accounts::dayclose.col.value') }}</th><th width="27%">{{ __('accounts::dayclose.col.vehicle') }}</th><th width="10%">{{ __('accounts::dayclose.col.fare') }}</th><th width="6%">{{ __('accounts::dayclose.col.pct') }}</th></tr></thead>
    <tbody>
        @forelse ($r['delivery']['rows'] as $i => $row)
            <tr><td style="text-align: center;">{{ $i + 1 }}</td><td class="mono">{{ $row['no'] }}</td><td>{{ $row['party'] }}</td><td class="num">{{ $m($row['value']) }}</td>
                <td class="sub">{{ $row['vehicle'] }}</td><td class="num">{{ $z($row['fare']) }}</td><td class="num">{{ $row['pct'] !== null ? $row['pct'].'%' : '' }}</td></tr>
        @empty
            <tr><td colspan="7" class="sub" style="text-align: center;">{{ __('accounts::dayclose.empty') }}</td></tr>
        @endforelse
        <tr class="tot"><td colspan="3" style="text-align: right;">{{ __('accounts::dayclose.delivery_total', ['count' => count($r['delivery']['rows'])]) }}</td><td class="num">{{ $m($r['delivery']['value']) }}</td><td></td>
            <td class="num">{{ $m($r['delivery']['fare']) }}</td><td class="num">{{ $r['delivery']['pct'] !== null ? $r['delivery']['pct'].'%' : '' }}</td></tr>
    </tbody>
</table>

{{-- ── ৩ · পার্টি ধরে বিক্রয় ও লাভ ── --}}
{!! $sec(3, 'sales') !!}
<table class="t" data-sec="sales">
    <thead><tr><th width="9%">{{ __('accounts::dayclose.col.bill') }}</th><th>{{ __('accounts::dayclose.col.party') }}</th><th width="10%">{{ __('accounts::dayclose.col.net') }}</th><th width="8%">{{ __('accounts::dayclose.col.vat') }}</th>
        <th width="10%">{{ __('accounts::dayclose.col.cogs') }}</th><th width="9%">{{ __('accounts::dayclose.col.margin') }}</th><th width="5%">{{ __('accounts::dayclose.col.rate') }}</th><th width="8%">{{ __('accounts::dayclose.col.delivery') }}</th>
        <th width="9%">{{ __('accounts::dayclose.col.after') }}</th><th width="6%">{{ __('accounts::dayclose.col.terms') }}</th></tr></thead>
    <tbody>
        @forelse ($r['sales']['rows'] as $row)
            <tr><td class="mono">{{ $row['no'] }}</td><td>{{ $row['party'] }}</td><td class="num">{{ $m($row['net']) }}</td><td class="num">{{ $m($row['vat']) }}</td><td class="num">{{ $m($row['cogs']) }}</td>
                <td class="num">{{ $neg($row['margin']) }}</td><td class="num">{{ $row['rate'] !== null ? $row['rate'].'%' : '' }}</td><td class="num">{{ $z($row['delivery']) }}</td><td class="num">{{ $neg($row['after']) }}</td>
                <td>{{ __('accounts::dayclose.terms.'.$row['terms']) }}</td></tr>
        @empty
            <tr><td colspan="10" class="sub" style="text-align: center;">{{ __('accounts::dayclose.empty') }}</td></tr>
        @endforelse
        @php($t = $r['sales']['totals'])
        <tr class="tot"><td colspan="2" style="text-align: right;">{{ __('accounts::dayclose.sales_total', ['count' => count($r['sales']['rows'])]) }}</td><td class="num">{{ $m($t['net']) }}</td><td class="num">{{ $m($t['vat']) }}</td>
            <td class="num">{{ $m($t['cogs']) }}</td><td class="num">{{ $neg($t['margin']) }}</td><td class="num">{{ $t['rate'] !== null ? $t['rate'].'%' : '' }}</td><td class="num">{{ $z($t['delivery']) }}</td><td class="num">{{ $neg($t['after']) }}</td><td></td></tr>
    </tbody>
</table>

{{-- ── ৪ · আজকের খরচ  |  ৫ · নগদ গণনা ── --}}
<table width="100%" style="page-break-inside: avoid;"><tr>
    <td width="61%" style="vertical-align: top; padding-right: 2mm;">
        {!! $sec(4, 'expense') !!}
        <table class="t" data-sec="expense">
            <thead><tr><th width="11%">{{ __('accounts::dayclose.col.code') }}</th><th>{{ __('accounts::dayclose.col.head') }}</th><th width="22%">{{ __('accounts::dayclose.col.from') }}</th><th width="18%">{{ __('accounts::dayclose.col.amount') }}</th></tr></thead>
            <tbody>
                @forelse ($r['expenses']['groups'] as $g)
                    <tr class="g"><td colspan="4">{{ __('accounts::dayclose.expense_group.'.$g['key']) }}</td></tr>
                    @foreach ($g['rows'] as $row)
                        <tr><td class="mono">{{ $row['code'] }}</td><td>{{ $row['head'] }}@if ($row['note']) <span class="sub">— {{ \Illuminate\Support\Str::limit($row['note'], 34) }}</span>@endif</td>
                            <td class="sub">{{ $row['from'] }}</td><td class="num">{{ $neg($row['amount']) }}</td></tr>
                    @endforeach
                @empty
                    <tr><td colspan="4" class="sub" style="text-align: center;">{{ __('accounts::dayclose.empty') }}</td></tr>
                @endforelse
                <tr class="tot"><td colspan="3" style="text-align: right;">{{ __('accounts::dayclose.expense_total') }}</td><td class="num">{{ $neg($r['expenses']['total']) }}</td></tr>
            </tbody>
        </table>
    </td>
    <td width="39%" style="vertical-align: top;">
        {!! $sec(5, 'count') !!}
        @if ($r['count'] === null)
            <table class="t" data-sec="count"><tr><td class="sub" style="text-align: center; padding: 3mm;">{{ __('accounts::dayclose.not_counted') }}</td></tr></table>
        @else
            <table class="t" data-sec="count">
                <thead><tr><th>{{ __('accounts::dayclose.col.note_col') }}</th><th>{{ __('accounts::dayclose.col.qty') }}</th><th>{{ __('accounts::dayclose.col.amount') }}</th></tr></thead>
                <tbody>
                    @foreach ($r['count']['notes'] as $n)
                        <tr><td class="num" style="text-align: center;">{{ number_format($n['note']) }}</td><td class="num" style="text-align: center;">{{ $n['qty'] }}</td><td class="num">{{ $m($n['amount']) }}</td></tr>
                    @endforeach
                    <tr class="s"><td colspan="2">{{ __('accounts::dayclose.counted') }}</td><td class="num">{{ $m($r['count']['counted']) }}</td></tr>
                    <tr><td colspan="2">{{ __('accounts::dayclose.book_cash') }}</td><td class="num">{{ $m($r['count']['book']) }}</td></tr>
                    <tr class="tot"><td colspan="2">{{ __('accounts::dayclose.gap') }}</td><td class="num">{{ $neg($r['count']['gap']) }}</td></tr>
                    @if ($r['count']['reason'])<tr><td colspan="3" class="sub">{{ __('accounts::dayclose.reason') }}: {{ $r['count']['reason'] }}</td></tr>@endif
                </tbody>
            </table>
        @endif
    </td>
</tr></table>

{{-- ── ৬ · আজ টাকা গেছে, কিন্তু খরচ নয় ── --}}
{!! $sec(6, 'paid_out') !!}
<table class="t" data-sec="paid_out">
    <thead><tr><th width="12%">{{ __('accounts::dayclose.col.voucher') }}</th><th>{{ __('accounts::dayclose.col.description') }}</th><th width="26%">{{ __('accounts::dayclose.col.from') }}</th><th width="15%">{{ __('accounts::dayclose.col.amount') }}</th></tr></thead>
    <tbody>
        @forelse ($r['paid_out']['rows'] as $row)
            <tr><td class="mono">{{ $row['no'] }}</td><td>{{ \Illuminate\Support\Str::limit($row['note'], 70) }}</td><td class="sub">{{ $row['from'] }}</td><td class="num">{{ $m($row['amount']) }}</td></tr>
        @empty
            <tr><td colspan="4" class="sub" style="text-align: center;">{{ __('accounts::dayclose.empty') }}</td></tr>
        @endforelse
        <tr class="tot"><td colspan="3" style="text-align: right;">{{ __('accounts::dayclose.paid_out_total') }}</td><td class="num">{{ $m($r['paid_out']['total']) }}</td></tr>
    </tbody>
</table>

{{-- ── ৭ · হিসাবের জের ── --}}
{!! $sec(7, 'balances') !!}
<table class="t" data-sec="balances">
    <thead><tr><th>{{ __('accounts::dayclose.col.acct') }}</th><th width="14%">{{ __('accounts::dayclose.col.opening') }}</th><th width="13%">{{ __('accounts::dayclose.col.in') }}</th>
        <th width="13%">{{ __('accounts::dayclose.col.out') }}</th><th width="14%">{{ __('accounts::dayclose.col.closing') }}</th><th width="18%">{{ __('accounts::dayclose.col.remark') }}</th></tr></thead>
    <tbody>
        @foreach ($r['balances']['rows'] as $row)
            <tr><td>{{ $row['name'] }}@if ($row['holder']) <span class="sub">· {{ $row['holder'] }}</span>@endif</td><td class="num">{{ $neg($row['opening']) }}</td><td class="num">{{ $z($row['in']) }}</td>
                <td class="num">{{ $z($row['out']) }}</td><td class="num">{{ $neg($row['closing']) }}</td>
                <td class="sub">@if ($row['kind'] === 'cash' && $r['count'] !== null && bccomp($r['count']['gap'], '0', 2) !== 0){{ __('accounts::dayclose.gap') }} {{ $neg($r['count']['gap']) }}@endif</td></tr>
        @endforeach
        <tr class="tot"><td>{{ __('accounts::dayclose.balances_total') }}</td><td class="num">{{ $neg(collect($r['balances']['rows'])->reduce(fn ($s, $x) => bcadd($s, (string) $x['opening'], 4), '0')) }}</td>
            <td class="num">{{ $m($r['balances']['in_total']) }}</td><td class="num">{{ $m($r['balances']['out_total']) }}</td><td class="num">{{ $neg($r['balances']['closing_total']) }}</td><td></td></tr>
    </tbody>
</table>

{{-- ── ৮ · লাভ-ক্ষতির সারাংশ ── --}}
{!! $sec(8, 'pl') !!}
@php($pl = $r['pl'])
<table class="t" data-sec="pl">
    <thead><tr><th>{{ __('accounts::dayclose.col.particular') }}</th><th width="19%">{{ __('accounts::dayclose.col.today') }}</th><th width="19%">{{ __('accounts::dayclose.col.mtd') }}</th><th width="19%">{{ __('accounts::dayclose.col.avg') }}</th></tr></thead>
    <tbody>
        @foreach ([
            ['gross', 'gross', false, false], ['vat', 'vat', true, true], ['returned', 'returned', true, true], ['net', 'net', false, false, true], ['cogs', 'cogs', true, true],
            ['margin', 'margin', false, false, true], ['delivery', 'delivery', true, true], ['after', 'after', false, false, true], ['office', 'office', true, true], ['fixed', 'fixed', true, true],
        ] as $line)
            @php([$key, $field, $indent, $isLess] = $line)
            @continue($key === 'returned' && bccomp((string) $pl['month']['returned'], '0', 4) === 0)
            <tr @class(['s' => ($line[4] ?? false)])>
                <td @class(['ind' => $indent])>{{ __('accounts::dayclose.pl.'.$key, ['rate' => $pl['month']['margin_rate'] ?? '0', 'monthly' => $m($r['fixed']['monthly']), 'days' => $r['fixed']['workdays']]) }}</td>
                @foreach (['today', 'month', 'avg'] as $col)
                    <td class="num">{{ $isLess ? $less($pl[$col][$field] ?? '0') : $neg($pl[$col][$field] ?? '0') }}</td>
                @endforeach
            </tr>
        @endforeach
        <tr class="tot" data-net-profit><td style="font-size: 9pt;">{{ __('accounts::dayclose.pl.profit', ['rate' => $pl['month']['net_rate'] ?? '0']) }}</td>
            @foreach (['today', 'month', 'avg'] as $col)<td class="num" style="font-size: 8pt;">{{ $neg($pl[$col]['profit']) }}</td>@endforeach</tr>
    </tbody>
</table>

{{-- ── ৯ · মজুদ ও বকেয়া ── --}}
{!! $sec(9, 'stock') !!}
<table width="100%" style="page-break-inside: avoid;"><tr>
    <td width="52%" style="vertical-align: top; padding-right: 2mm;">
        <table class="t" data-sec="stock">
            <thead><tr><th></th><th>{{ __('accounts::dayclose.col.stock') }}</th><th>{{ __('accounts::dayclose.col.dues') }}</th></tr></thead>
            <tbody>
                <tr><td>{{ __('accounts::dayclose.roll.opening') }}</td><td class="num">{{ $neg($r['stock']['stock']['opening']) }}</td><td class="num">{{ $neg($r['stock']['dues']['opening']) }}</td></tr>
                <tr><td>{{ __('accounts::dayclose.roll.in') }}</td><td class="num">{{ $z($r['stock']['stock']['in']) }}</td><td class="num">{{ $z($r['stock']['dues']['in']) }}</td></tr>
                <tr><td>{{ __('accounts::dayclose.roll.out') }}</td><td class="num">{{ bccomp($r['stock']['stock']['out'], '0', 4) ? $less($r['stock']['stock']['out']) : '' }}</td><td class="num">{{ bccomp($r['stock']['dues']['out'], '0', 4) ? $less($r['stock']['dues']['out']) : '' }}</td></tr>
                <tr class="tot"><td>{{ __('accounts::dayclose.roll.closing') }}</td><td class="num">{{ $neg($r['stock']['stock']['closing']) }}</td><td class="num">{{ $neg($r['stock']['dues']['closing']) }}</td></tr>
            </tbody>
        </table>
    </td>
    <td width="48%" style="vertical-align: top;">
        <table class="t" data-sec="oldest">
            <thead><tr><th>{{ __('accounts::dayclose.col.oldest') }}</th><th width="28%">{{ __('accounts::dayclose.col.amount') }}</th><th width="15%">{{ __('accounts::dayclose.col.age') }}</th></tr></thead>
            <tbody>
                @forelse ($r['stock']['oldest'] as $o)
                    <tr><td>{{ $o['party'] }}</td><td class="num">{{ $m($o['amount']) }}</td><td class="num">{{ __('accounts::dayclose.days', ['count' => $o['days']]) }}</td></tr>
                @empty
                    <tr><td colspan="3" class="sub" style="text-align: center;">{{ __('accounts::dayclose.empty') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </td>
</tr></table>

{{-- ── ১০ · স্থির খরচ  |  ১১ · মূসক ও মাসের লক্ষ্য ── --}}
@php($v = $r['vat'])
<table width="100%" style="page-break-inside: avoid;"><tr>
    <td width="50%" style="vertical-align: top; padding-right: 2mm;">
        {!! $sec(10, 'fixed') !!}
        <table class="t" data-sec="fixed">
            <thead><tr><th>{{ __('accounts::dayclose.col.fixed_head') }}</th><th width="30%">{{ __('accounts::dayclose.col.monthly') }}</th><th width="25%">{{ __('accounts::dayclose.col.daily') }}</th></tr></thead>
            <tbody>
                @forelse ($r['fixed']['rows'] as $row)
                    <tr><td>{{ __('accounts::dayclose.fixed_heads.'.$row['key']) }}</td><td class="num">{{ $m($row['monthly']) }}</td><td class="num">{{ $m($row['daily']) }}</td></tr>
                @empty
                    <tr><td colspan="3" class="sub">{{ __('accounts::dayclose.fixed_none') }}</td></tr>
                @endforelse
                <tr class="tot"><td>{{ __('accounts::dayclose.fixed_total') }}</td><td class="num">{{ $m($r['fixed']['monthly']) }}</td><td class="num">{{ $m($r['fixed']['daily']) }}</td></tr>
            </tbody>
        </table>
    </td>
    <td width="50%" style="vertical-align: top;">
        {!! $sec(11, 'vat') !!}
        <table class="t" data-sec="vat">
            <thead><tr><th>{{ __('accounts::dayclose.col.vat_head') }}</th><th width="34%">{{ __('accounts::dayclose.col.amount') }}</th></tr></thead>
            <tbody>
                <tr><td>{{ __('accounts::dayclose.vat_63') }}</td><td class="num">{{ __('accounts::dayclose.count_unit', ['count' => $v['challans_63']]) }} · {{ $m($v['vat_today']) }}</td></tr>
                <tr><td>{{ __('accounts::dayclose.vat_67') }}</td><td class="num">{{ __('accounts::dayclose.count_unit', ['count' => $v['notes_67']]) }}</td></tr>
                <tr class="s"><td>{{ __('accounts::dayclose.vat_month', ['month' => $day->copy()->locale($locale)->translatedFormat('F')]) }}</td><td class="num">{{ $m($v['vat_month']) }}</td></tr>
                <tr><td colspan="2" class="sub">{{ __('accounts::dayclose.vat_due', ['date' => \Illuminate\Support\Carbon::parse($v['vat_due_on'])->format('d-m-Y')]) }}</td></tr>
            </tbody>
        </table>
        <table class="t" data-sec="target" style="margin-top: 2mm;">
            <thead><tr><th>{{ __('accounts::dayclose.col.target_head') }}</th><th width="34%">{{ __('accounts::dayclose.col.amount') }}</th></tr></thead>
            <tbody>
                @if ($v['target'] === null)
                    <tr><td colspan="2" class="sub">{{ __('accounts::dayclose.target_none') }}</td></tr>
                    <tr><td>{{ __('accounts::dayclose.achieved') }}</td><td class="num">{{ $m($v['achieved']) }}</td></tr>
                @else
                    <tr><td>{{ __('accounts::dayclose.target') }}</td><td class="num">{{ $m($v['target']) }}</td></tr>
                    <tr><td>{{ __('accounts::dayclose.achieved') }}</td><td class="num">{{ $m($v['achieved']) }}</td></tr>
                    <tr class="s"><td>{{ __('accounts::dayclose.elapsed', ['pct' => $v['elapsed_pct']]) }}</td><td class="num">{{ $v['achieved_pct'] }}%</td></tr>
                    @if ($v['per_day'] !== null)<tr><td>{{ __('accounts::dayclose.per_day', ['days' => $v['left_days']]) }}</td><td class="num">{{ $m($v['per_day']) }}</td></tr>@endif
                @endif
            </tbody>
        </table>
    </td>
</tr></table>

{{-- ── দিন বন্ধের পরে যোগ হয়েছে (মালিক, ১৮ নভেম্বর ২০২৬) ── --}}
@if ($r['late'] !== [])
    <table width="100%" class="sec"><tr><td><b>{{ __('accounts::dayclose.late.title') }}</b></td><td class="en">{{ __('accounts::dayclose.late.caption') }}</td></tr></table>
    <table class="t" data-sec="late">
        <thead><tr><th width="16%">{{ __('accounts::dayclose.late.paper') }}</th><th>{{ __('accounts::dayclose.late.kind') }}</th><th width="16%">{{ __('accounts::dayclose.col.amount') }}</th>
            <th width="22%">{{ __('accounts::dayclose.late.who') }}</th><th width="18%">{{ __('accounts::dayclose.late.when') }}</th></tr></thead>
        <tbody>
            @foreach ($r['late'] as $row)
                <tr><td class="mono">{{ $row['no'] }}</td><td>{{ __('core.source.'.$row['type']) }}</td><td class="num">{{ $m($row['amount']) }}</td>
                    <td>{{ $row['who'] }}</td><td class="num" style="text-align: left;">{{ $row['when']->format('d-m-Y h:i A') }}</td></tr>
            @endforeach
            <tr><td colspan="5" class="sub">{{ __('accounts::dayclose.late.note') }}</td></tr>
        </tbody>
    </table>
@endif

{{-- ── দিন বন্ধ ── --}}
<div class="closebox" data-close-line>
    @if ($r['closed'])
        @php($c = $r['closed'])
        <b>{!! e(__('accounts::dayclose.close_line', [
            'count' => $c->cash_counted === null ? __('accounts::dayclose.close_line_uncounted')
                : (bccomp((string) $c->cash_gap, '0', 2) === 0 ? __('accounts::dayclose.close_line_count_ok') : __('accounts::dayclose.close_line_count_gap', ['gap' => $m(ltrim((string) $c->cash_gap, '-'))])),
            'date' => $day->format('d-m-Y'),
            'who' => $c->closer?->name ?? '—',
            'time' => $c->closed_at?->format('h:i A') ?? '',
        ])) !!}</b>
    @else
        @if ($r['record']?->reopened_at)
            <b>{{ __('accounts::dayclose.reopen.line', ['who' => $r['record']->reopener?->name ?? '—', 'time' => $r['record']->reopened_at->format('d-m-Y h:i A'), 'reason' => $r['record']->reopen_reason]) }}</b><br>
        @endif
        {{ __('accounts::dayclose.open_line') }}
    @endif
</div>

<table width="100%" style="margin-top: 14mm; page-break-inside: avoid;" data-signs><tr>
    <td width="28%" class="sign">{{ __('accounts::dayclose.sign.cashier') }}</td><td width="8%"></td>
    <td width="28%" class="sign">{{ __('accounts::dayclose.sign.accountant') }}</td><td width="8%"></td>
    <td width="28%" class="sign">{{ __('accounts::dayclose.sign.owner') }}</td>
</tr></table>

</body>
</html>
