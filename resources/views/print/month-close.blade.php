{{--
    ⭐ মাস শেষের হিসাব — A4 খাড়া, পাতা লেখা অনুযায়ী, কোনো টেবিল দুই পাতায় ভাঙে না (মালিক, ১৭ নভেম্বর ২০২৬: নমুনা "Ok", "Sob ok";
    [[MonthClosePack]], [[MonthCloseController]])। দিন শেষের কাগজের একই ঘরানা: কালো বৃত্তে অংশের নম্বর, বাংলা শিরোনাম + ছোট ইংরেজি,
    উপরে এক লাইনের তথ্য (বাক্স নয়), নিচে তিনটা সই।

    ⓘ mPDF: flex/grid নেই — পাশাপাশি অংশ সাজানো টেবিলে; প্রতিটা অংশ একটা বাইরের টেবিলে `page-break-inside: avoid`, ভেতরের টেবিল
    mPDF কখনো ভাঙে না। ⓘ অঙ্ক ইংরেজি, লাখের কমা ([[Money::format()]]); বাদের অঙ্ক বন্ধনীতে।
--}}
@php
    $t = fn (string $k, array $r = []) => __('executive::month_close.'.$k, $r);
    $mf = fn ($v) => $v === null ? '' : \App\Core\Support\Money::format((string) $v);
    $less = fn ($v) => $v === null ? '' : (bccomp((string) $v, '0', 4) === 0 ? \App\Core\Support\Money::format('0') : '('.\App\Core\Support\Money::format(ltrim((string) $v, '-')).')');
    $signed = fn ($v) => $v === null ? '' : (str_starts_with((string) $v, '-') ? '('.\App\Core\Support\Money::format(ltrim((string) $v, '-')).')' : \App\Core\Support\Money::format((string) $v));
    $dot = fn (int $n) => '<span class="dot">'.mb_chr(0x2775 + $n).'</span>';
    $p = $m['period'];
    $logo = $company->logoData();
    $plCols = \App\Modules\Executive\Services\MonthClose\MonthClosePack::COLUMNS;
    $nameAt = fn (string $name, ?string $point) => e($name).($point ? ' · <span class="mut">'.e($point).'</span>' : '');
@endphp
<html>
<head>
<style @nonce>
    @page { size: 210mm 297mm; margin: 17mm 10mm 14mm 10mm; margin-header: 6mm; margin-footer: 6mm; header: html_runHead; footer: html_monthFoot; }
    @page :first { margin-top: 10mm; header: html_noHead; }
    body { font-family: hindsiliguri; font-size: 8pt; color: #000000; }
    .num, td.n { font-family: dejavusans; font-size: 7pt; text-align: right; white-space: nowrap; }
    {{-- ⓘ অঙ্কের পাশে বাংলা শব্দ (টা, জন, একক) — dejavusans-এ বাংলা নেই, তাই এই ঘর বাংলা ফন্টে --}}
    td.nb { text-align: right; white-space: nowrap; }
    .mut { color: #555555; }
    .dot { font-family: dejavusans; font-size: 12pt; }
    table { border-collapse: collapse; }
    table.blk { width: 100%; page-break-inside: avoid; margin-top: 3mm; }
    table.blk > tr > td, table.blk td.cell { vertical-align: top; padding: 0; }
    .h td { padding: 0 0 0.8mm 0; }
    .h .ttl { font-size: 11pt; font-weight: bold; }
    .h .cap { font-size: 6.5pt; color: #555555; text-align: right; letter-spacing: 0.3pt; vertical-align: bottom; }
    table.t { width: 100%; border: 0.3mm solid #000000; }
    .t th { font-weight: bold; text-align: center; padding: 0.9mm 1mm; border-top: 0.5mm solid #000000; border-bottom: 0.4mm solid #000000; font-size: 7.6pt; }
    .t td { padding: 0.7mm 1.2mm; border-bottom: 0.1mm solid #bdbdbd; font-size: 7.4pt; vertical-align: top; }
    .t td.in { padding-left: 4mm; }
    .t td.c { text-align: center; }
    .t tr.sub td { background-color: #ececec; font-weight: bold; }
    .t tr.tot td { background-color: #e2e2e2; font-weight: bold; border-top: 0.4mm solid #000000; }
    .t td.note { font-size: 6.8pt; color: #333333; }
    .co-name { font-size: 15pt; font-weight: bold; }
    .co-meta { font-size: 7.5pt; color: #444444; }
    .title { font-size: 15pt; font-weight: bold; text-align: right; }
    .title-en { font-size: 7.5pt; color: #444444; text-align: right; letter-spacing: 0.3pt; }
    .rule { border-bottom: 0.8mm solid #000000; height: 2mm; }
    .info td { font-size: 8pt; padding: 1.4mm 0; border-bottom: 0.2mm solid #999999; }
    .news { border: 0.3mm solid #999999; background-color: #f6f6f6; padding: 1.5mm 2.4mm; margin-top: 2mm; }
    .news ul { margin: 0; padding-left: 4mm; }
    .news li { font-size: 7.5pt; line-height: 1.45; }
    .lock { border: 0.4mm solid #000000; background-color: #f4f4f4; padding: 2.4mm 3mm; font-size: 8.4pt; line-height: 1.6; }
    .sign { border-top: 0.25mm solid #000000; text-align: center; font-size: 8.4pt; padding-top: 1.2mm; }
</style>
</head>
<body>

<htmlpageheader name="noHead"><div></div></htmlpageheader>
<htmlpageheader name="runHead">
    <table width="100%" style="border-bottom: 0.2mm solid #999999;"><tr>
        <td style="font-size: 8.5pt; padding-bottom: 1.2mm;"><b>{{ $t('title') }}</b> · {{ $company->name() }} · {{ $p['label'] }}</td>
        <td style="text-align: right; font-size: 8.5pt; padding-bottom: 1.2mm;">{{ $t('page') }} {PAGENO} / {nbpg}</td>
    </tr></table>
</htmlpageheader>
<htmlpagefooter name="monthFoot">
    <table width="100%" style="border-top: 0.2mm solid #999999;"><tr>
        <td style="font-size: 7.5pt; color: #444444; padding-top: 1.2mm;">{{ $t('printed_at') }}: {{ $p['printed_at'] }} · ABOS</td>
        <td style="text-align: right; font-size: 9pt; font-weight: bold; padding-top: 1.2mm;">{{ $t('page') }} {PAGENO} / {nbpg}</td>
    </tr></table>
</htmlpagefooter>

{{-- ── প্যাড ── --}}
<table width="100%"><tr>
    @if ($logo)
        <td width="17mm"><img src="{{ $logo }}" style="height: 13mm;" alt=""></td>
    @endif
    <td>
        <div class="co-name">{{ $company->name() }}</div>
        <div class="co-meta">{{ $company->address() }}@if ($company->phone) · {{ $t('phone') }} {{ $company->phone }}@endif @if ($company->bin) · BIN {{ $company->bin }}@endif</div>
    </td>
    <td width="70mm">
        <div class="title">{{ $t('title') }}</div>
        <div class="title-en">{{ $t('title_en') }} · {{ $p['label_en'] }}</div>
    </td>
</tr></table>
<div class="rule"></div>

{{-- ⭐ এক লাইনের তথ্য — বাক্স নয় (মালিকের কাগজের নিয়ম) --}}
<table width="100%" class="info" data-info-line><tr><td>
    {{ $t('info.month') }}: <b>{{ $p['label'] }} ({{ $p['range'] }})</b> &nbsp;&nbsp;
    {{ $t('info.working_days') }}: <b>{{ $p['working_days'] }} ({{ $t('info.fridays_off') }})</b> &nbsp;&nbsp;
    {{ $t('info.branch') }}: <b>{{ $p['branch'] }}</b> &nbsp;&nbsp;
    @if ($p['closed_by']){{ $t('info.closed_by') }}: <b>{{ $p['closed_by'] }}</b> &nbsp;&nbsp;@endif
    {{ $t('info.printed_by') }}: <b>{{ $p['printed_by'] }}</b>
</td></tr></table>

{{-- ── মাসের খবর ── --}}
<div class="news" data-news>
    <b style="font-size: 8.6pt;">{{ $t('news_title') }}</b>
    <table width="100%"><tr>
        <td width="50%" style="vertical-align: top;"><ul>@foreach ($m['news']['left'] as $line)<li>{!! $line !!}</li>@endforeach</ul></td>
        <td width="50%" style="vertical-align: top;"><ul>@foreach ($m['news']['right'] as $line)<li>{!! $line !!}</li>@endforeach</ul></td>
    </tr></table>
</div>

{{-- ── ১ লাভ-ক্ষতি ── --}}
<table class="blk"><tr><td>
    <table width="100%" class="h"><tr><td>{!! $dot(1) !!} <span style="font-size: 11.5pt; font-weight: bold;">{{ $t('s.pl') }}</span></td><td class="cap">{{ $t('cap.pl') }}</td></tr></table>
    <table class="t" data-section="pl">
        <thead><tr><th width="31%">{{ $t('h.particulars') }}</th>@foreach ($plCols as $c)<th>{{ $p['columns'][$c] }}</th>@endforeach</tr></thead>
        <tbody>
        @foreach ($m['pl']['rows'] as $row)
            <tr @class(['sub' => $row['style'] === 'sub']) data-pl="{{ $row['key'] }}">
                <td @class(['in' => in_array($row['style'], ['less', 'add'], true)])>{{ $t('pl.'.$row['key']) }}</td>
                @foreach ($plCols as $c)
                    <td class="n">{{ $row['style'] === 'less' ? $less($row['values'][$c]) : $signed($row['values'][$c]) }}</td>
                @endforeach
            </tr>
        @endforeach
            <tr class="tot" data-pl="net_profit">
                <td>{{ $t('pl.net_profit') }}</td>
                @foreach ($plCols as $c)<td class="n">{{ $signed($m['pl']['figures'][$c]['net_profit'] ?? null) }}</td>@endforeach
            </tr>
            <tr><td class="in">{{ $t('pl.margin') }}</td>@foreach ($plCols as $c)<td class="n">{{ $m['pl']['margin'][$c] }}</td>@endforeach</tr>
        </tbody>
    </table>
</td></tr></table>

{{-- ── ২ বিক্রি — শাখা | পণ্যের দল ── --}}
<table class="blk"><tr>
    <td width="49%" class="cell">
        <table width="100%" class="h"><tr><td>{!! $dot(2) !!} <span style="font-size: 11.5pt; font-weight: bold;">{{ $t('s.branches') }}</span></td><td class="cap">{{ $t('cap.branches') }}</td></tr></table>
        <table class="t" data-section="branches">
            <thead><tr><th>{{ $t('h.branch') }}</th><th>{{ $t('h.bill') }}</th><th>{{ $t('h.margin') }}</th><th>{{ $t('h.vs_prior') }}</th></tr></thead>
            <tbody>
            @forelse ($m['sales']['branches'] as $r)
                <tr><td>{{ $r['name'] }}</td><td class="n">{{ $mf($r['bill']) }}</td><td class="n">{{ $r['margin'] ?? '—' }}</td><td class="n">{{ $r['change'] ?? '—' }}</td></tr>
            @empty
                <tr><td colspan="4" class="note">{{ $t('empty.sales') }}</td></tr>
            @endforelse
                <tr class="tot"><td>{{ $t('h.total') }}</td><td class="n">{{ $mf($m['sales']['parts']['gross']) }}</td><td class="n">{{ $m['sales']['margin'] ?? '—' }}</td>
                    <td class="n">{{ $m['sales']['change'] ?? '—' }}</td></tr>
            </tbody>
        </table>
    </td>
    <td width="2%"></td>
    <td width="49%" class="cell">
        <table width="100%" class="h"><tr><td><span style="font-size: 11.5pt; font-weight: bold;">{{ $t('s.groups') }}</span></td><td class="cap">{{ $t('cap.groups') }}</td></tr></table>
        <table class="t" data-section="groups">
            <thead><tr><th>{{ $t('h.group') }}</th><th>{{ $t('h.qty') }}</th><th>{{ $t('h.net_sales') }}</th><th>{{ $t('h.rate') }}</th></tr></thead>
            <tbody>
            @forelse ($m['sales']['groups'] as $r)
                <tr><td>{{ $r['name'] }}</td><td class="nb">{{ $r['qty'] ?? '—' }}</td><td class="n">{{ $mf($r['amount']) }}</td><td class="n">{{ $r['margin'] ?? '—' }}</td></tr>
            @empty
                <tr><td colspan="4" class="note">{{ $t('empty.sales') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </td>
</tr></table>

{{-- ── এসআর ধরে ── --}}
<table class="blk"><tr><td>
    <table width="100%" class="h"><tr><td><span style="font-size: 11.5pt; font-weight: bold;">{{ $t('s.sellers') }}</span></td><td class="cap">{{ $t('cap.sellers') }}</td></tr></table>
    <table class="t" data-section="sellers">
        <thead><tr><th width="16%">{{ $t('h.seller') }}</th><th width="24%">{{ $t('h.area') }}</th><th>{{ $t('h.target') }}</th><th>{{ $t('h.net_sales') }}</th><th>{{ $t('h.achieved') }}</th><th>{{ $t('h.collected') }}</th></tr></thead>
        <tbody>
        @forelse ($m['sales']['sellers']['rows'] as $r)
            <tr><td>{{ $r['name'] }}</td><td>{{ $r['area'] ?: '—' }}</td><td class="n">{{ $r['target'] === null ? '—' : $mf($r['target']) }}</td><td class="n">{{ $mf($r['net']) }}</td><td class="n">{{ $r['achieved'] ?? '—' }}</td><td class="n">{{ $mf($r['collected']) }}</td></tr>
        @empty
            <tr><td colspan="6" class="note">{{ $t('empty.sales') }}</td></tr>
        @endforelse
            <tr class="tot"><td colspan="2">{{ $t('h.total') }}</td><td class="n">{{ $mf($m['sales']['sellers']['target']) }}</td><td class="n">{{ $mf($m['sales']['sellers']['net']) }}</td><td class="n">{{ $m['sales']['sellers']['achieved'] ?? '—' }}</td><td class="n">{{ $mf($m['sales']['sellers']['collected']) }}</td></tr>
        </tbody>
    </table>
</td></tr></table>

{{-- ── শীর্ষ ১০ | নগদ, বাকি, ফেরত ── --}}
@php $k = $m['sales']['kinds']; @endphp
<table class="blk"><tr>
    <td width="56%" class="cell">
        <table width="100%" class="h"><tr><td><span style="font-size: 11.5pt; font-weight: bold;">{{ $t('s.top') }}</span></td><td class="cap">{{ $t('cap.top') }}</td></tr></table>
        <table class="t" data-section="top">
            <thead><tr><th width="8%">{{ $t('h.sl') }}</th><th>{{ $t('h.party') }}</th><th>{{ $t('h.bill') }}</th><th>{{ $t('h.share') }}</th></tr></thead>
            <tbody>
            @forelse ($m['sales']['top'] as $i => $r)
                <tr><td class="c">{{ $i + 1 }}</td><td>{!! $nameAt($r['name'], $r['point']) !!}</td><td class="n">{{ $mf($r['bill']) }}</td><td class="n">{{ $r['share'] ?? '—' }}</td></tr>
            @empty
                <tr><td colspan="4" class="note">{{ $t('empty.sales') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </td>
    <td width="2%"></td>
    <td width="42%" class="cell">
        <table width="100%" class="h"><tr><td><span style="font-size: 11.5pt; font-weight: bold;">{{ $t('s.kinds') }}</span></td><td class="cap">{{ $t('cap.kinds') }}</td></tr></table>
        <table class="t" data-section="kinds">
            <thead><tr><th>{{ $t('h.kind') }}</th><th>{{ $t('h.amount_count') }}</th></tr></thead>
            <tbody>
                <tr class="sub"><td>{{ $t('k.billed') }}</td><td class="n">{{ $mf($k['billed']) }}</td></tr>
                <tr><td>{{ $t('k.returned', ['n' => $k['return_count']]) }}@if ($k['return_nos'] !== []) ({{ implode(', ', $k['return_nos']) }})@endif</td><td class="n">{{ $less($k['returned']) }}</td></tr>
                <tr class="sub"><td>{{ $t('k.after_returns') }}</td><td class="n">{{ $mf($k['after_returns']) }}</td></tr>
                <tr><td>{{ $t('k.collected') }}</td><td class="n">{{ $mf($k['collected']) }}</td></tr>
                <tr><td>{{ $t(bccomp($k['due_change'], '0', 4) >= 0 ? 'k.due_up' : 'k.due_down') }}</td><td class="n">{{ $mf(ltrim($k['due_change'], '-')) }}</td></tr>
                <tr><td>{{ $t('k.bills') }}</td><td class="nb">{{ $t('count', ['n' => $k['bills']]) }}</td></tr>
                <tr><td>{{ $t('k.buyers') }}</td><td class="nb">{{ $t('people', ['n' => $k['buyers']]) }}</td></tr>
            </tbody>
        </table>
    </td>
</tr></table>

{{-- ── ৩ টাকা এল-গেল ── --}}
<table class="blk"><tr><td>
    <table width="100%" class="h"><tr><td>{!! $dot(3) !!} <span style="font-size: 11.5pt; font-weight: bold;">{{ $t('s.money') }}</span></td><td class="cap">{{ $t('cap.money') }}</td></tr></table>
    <table class="t" data-section="money">
        <thead><tr><th width="32%">{{ $t('h.account') }}</th><th>{{ $t('h.opening') }}</th><th>{{ $t('h.in') }}</th><th>{{ $t('h.out') }}</th><th>{{ $t('h.closing') }}</th></tr></thead>
        <tbody>
        @foreach ($m['money']['rows'] as $r)
            <tr><td>{{ $r['name'] }}</td><td class="n">{{ $signed($r['opening']) }}</td><td class="n">{{ $mf($r['in']) }}</td><td class="n">{{ $mf($r['out']) }}</td><td class="n">{{ $signed($r['closing']) }}</td></tr>
        @endforeach
            <tr class="tot"><td>{{ $t('h.total') }}</td><td class="n">{{ $signed($m['money']['total']['opening']) }}</td><td class="n">{{ $mf($m['money']['total']['in']) }}</td><td class="n">{{ $mf($m['money']['total']['out']) }}</td><td class="n">{{ $signed($m['money']['total']['closing']) }}</td></tr>
        </tbody>
    </table>
</td></tr></table>

{{-- ── মিল | ৪ স্থির খরচ ── --}}
@php $fx = $m['fixed']; @endphp
<table class="blk"><tr>
    <td width="49%" class="cell">
        <table width="100%" class="h"><tr><td><span style="font-size: 11.5pt; font-weight: bold;">{{ $t('s.recon') }}</span></td><td class="cap">{{ $t('cap.recon') }}</td></tr></table>
        <table class="t" data-section="recon">
            <thead><tr><th>{{ $t('h.account') }}</th><th>{{ $t('h.books') }}</th><th>{{ $t('h.statement') }}</th><th>{{ $t('h.match') }}</th></tr></thead>
            <tbody>
            @forelse ($m['money']['rows'] as $r)
                <tr data-recon="{{ $r['state'] }}"><td>{{ $r['name'] }}</td><td class="n">{{ $signed($r['closing']) }}</td><td class="n">{{ $r['counted'] === null ? '—' : $signed($r['counted']) }}</td><td class="note">{{ $r['note'] }}</td></tr>
            @empty
                <tr><td colspan="4" class="note">{{ $t('empty.money') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </td>
    <td width="2%"></td>
    <td width="49%" class="cell">
        <table width="100%" class="h"><tr><td>{!! $dot(4) !!} <span style="font-size: 11.5pt; font-weight: bold;">{{ $t('s.fixed') }}</span></td><td class="cap">{{ $t('cap.fixed') }}</td></tr></table>
        <table class="t" data-section="fixed">
            <thead><tr><th>{{ $t('h.fixed_head') }}</th><th>{{ $t('h.actual') }}</th><th>{{ $t('h.budget') }}</th><th>{{ $t('h.gap') }}</th><th></th></tr></thead>
            <tbody>
            @forelse ($fx['rows'] as $r)
                <tr><td>{{ $t('fixed.'.$r['key']) }}</td><td class="n">{{ $mf($r['actual']) }}</td><td class="n">{{ $r['budget'] === null ? '—' : $mf($r['budget']) }}</td><td class="n">{{ $r['gap'] === null ? '—' : $signed($r['gap']) }}</td>
                    <td>{{ $r['over'] === null || $r['over'] === 0 ? '' : $t($r['over'] > 0 ? 'over' : 'under') }}</td></tr>
            @empty
                <tr><td colspan="5" class="note">{{ $t('empty.fixed') }}</td></tr>
            @endforelse
                <tr class="tot"><td>{{ $t('h.total') }}</td><td class="n">{{ $mf($fx['total']['actual']) }}</td><td class="n">{{ $fx['has_budget'] ? $mf($fx['total']['budget']) : '—' }}</td><td class="n">{{ $fx['total']['gap'] === null ? '—' : $signed($fx['total']['gap']) }}</td><td></td></tr>
            </tbody>
        </table>
        @unless ($fx['has_budget'])<div class="mut" style="font-size: 6.8pt;">{{ $p['one_branch'] ? $t('budget_one_branch') : $t('budget_none') }}</div>@endunless
    </td>
</tr></table>

{{-- ── ৫ পাওনা | বয়স ও সম্ভাব্য ক্ষতি ── --}}
@php $ar = $m['receivables']; @endphp
<table class="blk"><tr>
    <td width="42%" class="cell">
        <table width="100%" class="h"><tr><td>{!! $dot(5) !!} <span style="font-size: 11.5pt; font-weight: bold;">{{ $t('s.receivables') }}</span></td><td class="cap">{{ $t('cap.receivables') }}</td></tr></table>
        <table class="t" data-section="receivables">
            <thead><tr><th>{{ $t('h.customer_due') }}</th><th>{{ $t('h.amount') }}</th></tr></thead>
            <tbody>
                <tr data-roll="opening"><td>{{ $t('roll.opening', ['date' => \Illuminate\Support\Carbon::parse($p['from'])->format('d-m')]) }}</td><td class="n">{{ $signed($ar['opening']) }}</td></tr>
                <tr data-roll="sales"><td>{{ $t('roll.credit_sales') }}</td><td class="n">{{ $signed($ar['sales']) }}</td></tr>
                <tr data-roll="collections"><td>{{ $t('roll.collections') }}</td><td class="n">{{ $less($ar['collections']) }}</td></tr>
                <tr data-roll="returns"><td>{{ $t('roll.returns') }}</td><td class="n">{{ $less($ar['returns']) }}</td></tr>
                @if (bccomp($ar['other'], '0', 4) !== 0)<tr data-roll="other"><td>{{ $t('roll.other') }}</td><td class="n">{{ $signed($ar['other']) }}</td></tr>@endif
                <tr class="tot" data-roll="closing"><td>{{ $t('roll.closing', ['date' => \Illuminate\Support\Carbon::parse($p['to'])->format('d-m')]) }}</td><td class="n">{{ $signed($ar['closing']) }}</td></tr>
                <tr><td>{{ $t('roll.rate') }}</td><td class="n">{{ $ar['rate'] ?? '—' }}</td></tr>
            </tbody>
        </table>
    </td>
    <td width="2%"></td>
    <td width="56%" class="cell">
        <table width="100%" class="h"><tr><td><span style="font-size: 11.5pt; font-weight: bold;">{{ $t('s.ageing') }}</span></td><td class="cap">{{ $t('cap.ageing') }}</td></tr></table>
        <table class="t" data-section="ageing">
            <thead><tr><th>{{ $t('h.age') }}</th><th>{{ $t('h.due') }}</th><th>{{ $t('h.share') }}</th><th>{{ $t('h.rate') }}</th><th>{{ $t('h.ecl') }}</th></tr></thead>
            <tbody>
            @foreach ($ar['ageing']['buckets'] as $b)
                <tr data-bucket="{{ $b['key'] }}"><td>{{ $t('age.'.$b['key']) }}</td><td class="n">{{ $mf($b['due']) }}</td><td class="n">{{ $b['share'] ?? '—' }}</td><td class="n">{{ \App\Core\Support\Money::quantity($b['rate']) }}%</td><td class="n">{{ $mf($b['loss']) }}</td></tr>
            @endforeach
                <tr class="tot"><td>{{ $t('h.total') }}</td><td class="n">{{ $mf($ar['ageing']['total']) }}</td><td class="n">{{ bccomp($ar['ageing']['total'], '0', 4) === 0 ? '—' : '100.0%' }}</td><td></td><td class="n" data-ecl>{{ $mf($ar['ageing']['ecl']) }}</td></tr>
                <tr><td colspan="4">{{ $t('ecl_before', ['amount' => $mf($ar['ecl_before'])]) }}</td><td class="n">{{ $signed($ar['ecl_change']) }}</td></tr>
                @if (bccomp($ar['ageing']['advance'], '0', 4) !== 0)<tr><td colspan="4" class="note">{{ $t('advance_apart') }}</td><td class="n">{{ $mf($ar['ageing']['advance']) }}</td></tr>@endif
            </tbody>
        </table>
    </td>
</tr></table>

<table class="blk"><tr><td>
    <table width="100%" class="h"><tr><td><span style="font-size: 11.5pt; font-weight: bold;">{{ $t('s.oldest') }}</span></td><td class="cap">{{ $t('cap.oldest') }}</td></tr></table>
    <table class="t" data-section="oldest">
        <thead><tr><th width="46%">{{ $t('h.party') }}</th><th>{{ $t('h.seller') }}</th><th>{{ $t('h.amount') }}</th><th>{{ $t('h.age') }}</th></tr></thead>
        <tbody>
        @forelse ($ar['oldest'] as $r)
            <tr><td>{!! $nameAt($r['name'], $r['point']) !!}</td><td>{{ $r['seller'] ?: '—' }}</td><td class="n">{{ $mf($r['amount']) }}</td><td class="c">{{ $r['days'] === null ? '—' : $t('days', ['n' => $r['days']]) }}</td></tr>
        @empty
            <tr><td colspan="4" class="note">{{ $t('empty.dues') }}</td></tr>
        @endforelse
        </tbody>
    </table>
</td></tr></table>

{{-- ── ৬ দেনা | ৭ মজুদ ── --}}
@php $ap = $m['payables']; $st = $m['stock']; @endphp
<table class="blk"><tr>
    <td width="56%" class="cell">
        <table width="100%" class="h"><tr><td>{!! $dot(6) !!} <span style="font-size: 11.5pt; font-weight: bold;">{{ $t('s.payables') }}</span></td><td class="cap">{{ $t('cap.payables') }}</td></tr></table>
        <table class="t" data-section="payables">
            <thead><tr><th>{{ $t('h.supplier') }}</th><th>{{ $t('h.owed') }}</th><th>{{ $t('h.due_next', ['month' => $ap['next_month']]) }}</th><th>{{ $t('h.due_on') }}</th></tr></thead>
            <tbody>
            @forelse ($ap['rows'] as $r)
                <tr><td>{{ $r['name'] }}</td><td class="n">{{ $mf($r['amount']) }}</td><td class="n">{{ $mf($r['due_next']) }}</td><td class="c">{{ $r['due_on'] ? \App\Core\Support\DateFormat::format($r['due_on']) : '—' }}</td></tr>
            @empty
                <tr><td colspan="4" class="note">{{ $t('empty.payables') }}</td></tr>
            @endforelse
                <tr class="tot"><td>{{ $t('h.total') }}@if ($ap['others'] > 0) <span class="mut">({{ $t('top_five', ['n' => $ap['others']]) }})</span>@endif</td><td class="n">{{ $signed($ap['closing']) }}</td><td class="n">{{ $mf($ap['due_total']) }}</td><td></td></tr>
                <tr><td colspan="4" class="note">{{ $t('ap_line', ['opening' => $signed($ap['opening']), 'purchases' => $mf($ap['purchases']), 'paid' => $mf($ap['paid']), 'closing' => $signed($ap['closing'])]) }}@if (bccomp($ap['cheques_out'], '0', 4) > 0) · {{ $t('cheques_out', ['amount' => $mf($ap['cheques_out'])]) }}@endif</td></tr>
            </tbody>
        </table>
    </td>
    <td width="2%"></td>
    <td width="42%" class="cell">
        <table width="100%" class="h"><tr><td>{!! $dot(7) !!} <span style="font-size: 11.5pt; font-weight: bold;">{{ $t('s.stock') }}</span></td><td class="cap">{{ $t('cap.stock') }}</td></tr></table>
        <table class="t" data-section="stock">
            <thead><tr><th>{{ $t('h.stock_cost') }}</th><th>{{ $t('h.amount') }}</th></tr></thead>
            <tbody>
                <tr><td>{{ $t('roll.opening', ['date' => \Illuminate\Support\Carbon::parse($p['from'])->format('d-m')]) }}</td><td class="n">{{ $signed($st['opening']) }}</td></tr>
                <tr><td>{{ $t('roll.purchases') }}</td><td class="n">{{ $signed($st['in']) }}</td></tr>
                <tr><td>{{ $t('roll.cogs') }}</td><td class="n">{{ $less($st['cogs']) }}</td></tr>
                <tr><td>{{ $t('roll.shortage') }}</td><td class="n">{{ $less($st['shortage']) }}</td></tr>
                <tr class="tot"><td>{{ $t('roll.closing', ['date' => \Illuminate\Support\Carbon::parse($p['to'])->format('d-m')]) }}</td><td class="n">{{ $signed($st['closing']) }}</td></tr>
            </tbody>
        </table>
    </td>
</tr></table>

<table class="blk"><tr><td>
    <table width="100%" class="h"><tr><td><span style="font-size: 11.5pt; font-weight: bold;">{{ $t('s.slow') }}</span></td><td class="cap">{{ $t('cap.slow') }}</td></tr></table>
    <table class="t" data-section="slow">
        <thead><tr><th width="40%">{{ $t('h.item') }}</th><th>{{ $t('h.qty') }}</th><th>{{ $t('h.amount') }}</th><th>{{ $t('h.reason') }}</th></tr></thead>
        <tbody>
        @forelse ($st['slow'] as $r)
            <tr><td>{{ $r['name'] }}</td><td class="nb">{{ $r['qty'] }}</td><td class="n">{{ $mf($r['value']) }}</td><td>{{ $t('slow_reason') }}</td></tr>
        @empty
            <tr><td colspan="4" class="note">{{ $t('empty.slow') }}</td></tr>
        @endforelse
        </tbody>
    </table>
</td></tr></table>

{{-- ── ৮ মূসক ও কর | ৯ বেতন ── --}}
@php $v = $m['vat']; $pay = $m['payroll']; @endphp
<table class="blk"><tr>
    <td width="49%" class="cell">
        <table width="100%" class="h"><tr><td>{!! $dot(8) !!} <span style="font-size: 11.5pt; font-weight: bold;">{{ $t('s.vat') }}</span></td><td class="cap">{{ $t('cap.vat') }}</td></tr></table>
        <table class="t" data-section="vat">
            <thead><tr><th>{{ $t('h.vat_tax') }}</th><th>{{ $t('h.amount') }}</th></tr></thead>
            <tbody>
                <tr><td>{{ $t('vat.output') }}</td><td class="n">{{ $signed($v['output']) }}</td></tr>
                <tr><td>{{ $t('vat.input') }}</td><td class="n">{{ $less($v['input']) }}</td></tr>
                <tr class="sub" data-vat-due><td>{{ $t(bccomp($v['due'], '0', 4) >= 0 ? 'vat.due' : 'vat.credit', ['date' => $v['due_on']]) }}</td><td class="n">{{ $mf(ltrim($v['due'], '-')) }}</td></tr>
                <tr><td>{{ $t('vat.vds_customers') }}</td><td class="n">{{ $mf($v['vds_by_customers']) }}</td></tr>
                @if (bccomp($v['vds_on_suppliers'], '0', 4) !== 0)<tr><td>{{ $t('vat.vds_suppliers', ['date' => $v['due_on']]) }}</td><td class="n">{{ $mf($v['vds_on_suppliers']) }}</td></tr>@endif
                <tr><td>{{ $t('vat.tds', ['date' => $v['due_on']]) }}</td><td class="n">{{ $mf($v['tds_on_suppliers']) }}</td></tr>
            </tbody>
        </table>
    </td>
    <td width="2%"></td>
    <td width="49%" class="cell">
        <table width="100%" class="h"><tr><td>{!! $dot(9) !!} <span style="font-size: 11.5pt; font-weight: bold;">{{ $t('s.payroll') }}</span></td><td class="cap">{{ $t('cap.payroll') }}</td></tr></table>
        <table class="t" data-section="payroll">
            <thead><tr><th>{{ $t('h.payroll', ['month' => $t('months.'.\Illuminate\Support\Carbon::parse($p['from'])->month)]) }}</th><th>{{ $t('h.amount') }}</th></tr></thead>
            <tbody>
            @if ($pay === null)
                <tr><td colspan="2" class="note">{{ $t('empty.payroll') }}</td></tr>
            @else
                <tr><td>{{ $t('pay.staff') }}</td><td class="nb">{{ $t('people', ['n' => $pay['staff']]) }}</td></tr>
                <tr><td>{{ $t('pay.gross') }}</td><td class="n">{{ $mf($pay['gross']) }}</td></tr>
                @foreach ($pay['deductions'] as $d)
                    <tr><td>{{ $t('pay.less', ['name' => $d['name']]) }}</td><td class="n">{{ $less($d['amount']) }}</td></tr>
                @endforeach
                <tr class="tot"><td>{{ $t('pay.net', ['date' => $pay['pay_by']]) }}</td><td class="n">{{ $mf($pay['net']) }}</td></tr>
            @endif
            </tbody>
        </table>
    </td>
</tr></table>

{{-- ── ১০ চেকলিস্ট ── --}}
<table class="blk"><tr><td>
    <table width="100%" class="h"><tr><td>{!! $dot(10) !!} <span style="font-size: 11.5pt; font-weight: bold;">{{ $t('s.checks') }}</span></td><td class="cap">{{ $t('cap.checks') }}</td></tr></table>
    <table class="t" data-section="checks">
        <thead><tr><th width="7%">{{ $t('h.sl') }}</th><th width="52%">{{ $t('h.task') }}</th><th>{{ $t('h.result') }}</th><th width="6%"></th></tr></thead>
        <tbody>
        @foreach ($m['checks'] as $i => $c)
            <tr data-check="{{ $c['key'] }}" data-state="{{ $c['state'] }}">
                <td class="c">{{ $i + 1 }}</td><td>{{ $c['label'] }}</td><td>{{ $c['result'] }}</td>
                <td class="c" style="font-family: dejavusans; font-weight: bold;">{{ $c['state'] === 'ok' ? '✓' : ($c['state'] === 'na' ? '—' : '✗') }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</td></tr></table>

{{-- ── ১১ মাস তালা ও সই ── --}}
@php $lock = $m['lock']; $pending = collect($m['checks'])->where('state', 'pending')->reject(fn ($c) => $c['key'] === 'locked')->count(); @endphp
<table class="blk"><tr><td>
    <table width="100%"><tr><td class="lock" data-lock="{{ $lock['locked'] ? 'locked' : 'open' }}">
        @if ($lock['locked'])
            <b>{{ $t('lock.closed_title') }}</b> {{ $t('lock.closed', ['month' => $p['label'], 'by' => $lock['by'] ?? '—', 'at' => $lock['at'] ?? '—']) }}
            @if (! empty($lock['left']) || ! empty($lock['reason']))
                <br><span data-lock-left>{{ $t('lock.left', ['items' => $lock['left'] ?? '—', 'reason' => $lock['reason'] ?? '—']) }}</span>
            @endif
        @else
            <b>{{ $t('lock.open_title') }}</b> {{ $pending > 0 ? $t('lock.open_pending', ['month' => $p['label'], 'n' => $pending]) : $t('lock.open_ready', ['month' => $p['label']]) }}
        @endif
    </td></tr></table>
    <table width="100%" style="margin-top: 16mm;" data-signs><tr>
        <td width="30%" class="sign">{{ $t('sign.prepared') }}</td><td width="5%"></td>
        <td width="30%" class="sign">{{ $t('sign.approved') }}</td><td width="5%"></td>
        <td width="30%" class="sign">{{ $t('sign.owner') }}</td>
    </tr></table>
</td></tr></table>

</body>
</html>
