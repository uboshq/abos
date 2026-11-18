{{--
    ⭐ দৈনিক খতিয়ানের কাগজ — মালিকের অনুমোদিত নমুনা হুবহু (১৬ নভেম্বর ২০২৬): মাথায় কোম্পানি আর "দৈনিক খতিয়ান · DAY BOOK — JOURNAL ENTRIES",
    এক লাইনের তথ্য, তারপর জার্নাল ধরে এন্ট্রি; পাতার নিচে "পরের পৃষ্ঠায় জের →", পরের পাতার মাথায় "← পূর্ব পৃষ্ঠার জের"।

    ⓘ পাতা ভাগ [[DayBookPaper::pages()]]-এ — প্রতিটা পাতা নিজের টেবিল, মাঝে <pagebreak>। কোনো এন্ট্রি দুই পাতায় ভাঙে না।
    ⓘ অঙ্ক ইংরেজিতে, কাগজের ভাষা যা-ই হোক (মালিক, ২৪ অক্টোবর ২০২৬)।
--}}
@php
    $ink = '#000000';
    $muted = '#444444';
    $line = '#bdbdbd';
    $money = fn ($v) => bccomp((string) $v, '0', 4) === 0 ? '' : \App\Core\Support\Money::format((string) $v);
    $sum = fn ($v) => \App\Core\Support\Money::format((string) $v);
    $day = \Illuminate\Support\Carbon::parse($book['date']);
    $logo = $company->logoData();
@endphp
<html>
<head>
<style @nonce>
    @page { size: 210mm 297mm; margin: 10mm 10mm 14mm 10mm; margin-footer: 6mm; footer: html_dbFoot; }
    body { font-family: hindsiliguri; font-size: 8.4pt; color: {{ $ink }}; }
    .num { font-family: dejavusans; font-size: 7.6pt; text-align: right; white-space: nowrap; }
    .mono { font-family: dejavusansmono; font-size: 7pt; white-space: nowrap; }
    table { border-collapse: collapse; }
    .pad td { vertical-align: middle; }
    .co-name { font-size: 15pt; font-weight: bold; }
    .co-meta { font-size: 8pt; color: {{ $muted }}; }
    .title { font-size: 15pt; font-weight: bold; text-align: right; }
    .title-en { font-size: 8pt; color: {{ $muted }}; text-align: right; letter-spacing: 0.3mm; }
    .rule { border-bottom: 0.8mm solid {{ $ink }}; height: 2mm; }
    .running { font-size: 9pt; border-bottom: 0.25mm solid {{ $line }}; padding-bottom: 1.5mm; }
    table.info { margin-top: 1.5mm; font-size: 8.6pt; }
    table.info td { padding: 0.8mm 4mm 1.4mm 0; white-space: nowrap; }
    .k { color: {{ $muted }}; }
    table.db { width: 100%; border: 0.3mm solid {{ $ink }}; margin-top: 2.5mm; }
    .db th { font-weight: bold; text-align: center; padding: 1.4mm 1mm; border-bottom: 0.4mm solid {{ $ink }}; }
    .db td { padding: 1mm 1.2mm; border-bottom: 0.1mm solid {{ $line }}; vertical-align: top; }
    .db tr.jh td { background-color: #dcdcdc; font-weight: bold; }
    .db tr.jt td, .db tr.carry td, .db tr.grand td { background-color: #e8e8e8; font-weight: bold; }
    .db tr.carry td { font-style: italic; }
    .db tr.grand td { border-top: 0.4mm solid {{ $ink }}; }
    .db td.cr { padding-left: 5mm; }
    .sub { font-size: 7.2pt; color: {{ $muted }}; }
    .rev { font-size: 7pt; color: #b00000; }
</style>
</head>
<body>

<htmlpagefooter name="dbFoot">
    <table width="100%"><tr>
        <td style="font-size: 8pt; color: {{ $muted }};">{{ __('core.listing.printed_at') }}: {{ $printedAt }} · ABOS</td>
        <td style="text-align: right; font-size: 9pt; font-weight: bold;">{{ __('core.listing.page') }} {PAGENO} / {nbpg}</td>
    </tr></table>
</htmlpagefooter>

@foreach ($pages as $p => $page)
    @if ($p === 0)
        <table class="pad" width="100%"><tr>
            @if ($logo)
                <td width="17mm"><img src="{{ $logo }}" style="height: 14mm;" alt=""></td>
            @endif
            <td>
                <div class="co-name">{{ $company->name() }}</div>
                @if ($company->address())<div class="co-meta">{{ $company->address() }}@if ($company->phone) · {{ __('core.listing.phone') }} {{ $company->phone }}@endif @if ($company->bin) · BIN {{ $company->bin }}@endif</div>@endif
            </td>
            <td width="75mm">
                <div class="title">{{ __('accounts::daybook.title') }}</div>
                <div class="title-en">{{ __('accounts::daybook.caption') }}</div>
            </td>
        </tr></table>
        <div class="rule"></div>

        {{-- ⛔ মাথায় বাক্স নয়, এক লাইনের তথ্য --}}
        <table class="info" width="100%" data-info-line><tr>
            <td><span class="k">{{ __('accounts::daybook.date') }}:</span> <b>{{ $day->format('d-m-Y') }} ({{ $day->locale($locale)->translatedFormat('l') }})</b></td>
            <td><span class="k">{{ __('accounts::daybook.journals') }}:</span> <b>{{ __('accounts::daybook.journals_value', ['count' => count($book['journals'])]) }}</b></td>
            <td><span class="k">{{ __('accounts::daybook.status') }}:</span> <b>{{ __('accounts::daybook.status_value') }}</b></td>
            <td><span class="k">{{ __('accounts::daybook.branch') }}:</span> <b>{{ $branch }}</b></td>
            <td><span class="k">{{ __('accounts::daybook.entries') }}:</span> <b>{{ __('accounts::daybook.entries_value', ['count' => $book['entries']]) }}</b></td>
            <td style="text-align: right;"><span class="k">{{ __('accounts::daybook.printed_by') }}:</span> <b>{{ $printedBy }}</b></td>
        </tr></table>
    @else
        <pagebreak />
        <table width="100%" class="running"><tr>
            <td><b>{{ __('accounts::daybook.title') }}</b> · {{ $company->name() }} · {{ $day->format('d-m-Y') }}</td>
            <td style="text-align: right;">{{ __('core.listing.page') }} {{ $p + 1 }} / {{ count($pages) }}</td>
        </tr></table>
    @endif

    <table class="db" width="100%" data-daybook-page="{{ $p + 1 }}">
        <thead><tr>
            <th width="9mm">{{ __('accounts::daybook.col.time') }}</th>
            <th width="27mm">{{ __('accounts::daybook.col.number') }}</th>
            <th width="17mm">{{ __('accounts::daybook.col.reference') }}</th>
            <th>{{ __('accounts::daybook.col.party') }}</th>
            <th width="11mm">{{ __('accounts::daybook.col.code') }}</th>
            <th width="36mm">{{ __('accounts::daybook.col.account') }}</th>
            <th width="22mm">{{ __('accounts::daybook.col.debit') }}</th>
            <th width="22mm">{{ __('accounts::daybook.col.credit') }}</th>
            <th width="13mm">{{ __('accounts::daybook.col.who') }}</th>
        </tr></thead>
        <tbody>
            @if ($page['carry_in'])
                <tr class="carry" data-carry-in><td colspan="6" style="text-align: right;">{{ __('accounts::daybook.carry_in') }}</td>
                    <td class="num">{{ $sum($page['carry_in']['debit']) }}</td><td class="num">{{ $sum($page['carry_in']['credit']) }}</td><td></td></tr>
            @endif

            @foreach ($page['rows'] as $row)
                @if ($row['kind'] === 'journal')
                    <tr class="jh"><td colspan="9">{{ __('accounts::daybook.journal.'.$row['journal']) }}</td></tr>
                @elseif ($row['kind'] === 'total')
                    <tr class="jt" data-journal-total="{{ $row['journal'] }}"><td colspan="6" style="text-align: right;">{{ __('accounts::daybook.journal_total', ['journal' => __('accounts::daybook.journal_short.'.$row['journal']), 'count' => $row['count']]) }}</td>
                        <td class="num">{{ $sum($row['debit']) }}</td><td class="num">{{ $sum($row['credit']) }}</td><td></td></tr>
                @else
                    @php($entry = $row['entry'])
                    @foreach ($entry['lines'] as $i => $ln)
                        <tr>
                            @if ($i === 0)
                                <td>{{ $entry['time'] }}</td>
                                <td class="mono">{{ $entry['number'] }}</td>
                                <td class="mono">{{ $entry['document_no'] }}</td>
                                <td>@if ($entry['party']){{ $entry['party'] }}@endif @if ($entry['reversal'])<span class="rev">({{ __('accounts::daybook.reversal') }})</span>@endif
                                    @if ($entry['narration'])<div class="sub">{{ $entry['narration'] }}</div>@endif</td>
                            @else
                                <td colspan="4"></td>
                            @endif
                            <td class="mono">{{ $ln['code'] }}</td>
                            <td @class(['cr' => bccomp($ln['credit'], '0', 4) > 0])>{{ $ln['account'] }}</td>
                            <td class="num">{{ $money($ln['debit']) }}</td>
                            <td class="num">{{ $money($ln['credit']) }}</td>
                            <td>@if ($i === 0){{ $entry['who'] }}@endif</td>
                        </tr>
                    @endforeach
                @endif
            @endforeach

            @if ($page['carry_out'])
                <tr class="carry" data-carry-out><td colspan="6" style="text-align: right;">{{ __('accounts::daybook.carry_out') }}</td>
                    <td class="num">{{ $sum($page['carry_out']['debit']) }}</td><td class="num">{{ $sum($page['carry_out']['credit']) }}</td><td></td></tr>
            @else
                <tr class="grand" data-day-total><td colspan="6" style="text-align: right;">{{ __('accounts::daybook.day_total', ['count' => $book['entries']]) }}</td>
                    <td class="num">{{ $sum($book['debit']) }}</td><td class="num">{{ $sum($book['credit']) }}</td><td></td></tr>
            @endif
        </tbody>
    </table>

    @if ($book['entries'] === 0)
        <p style="margin-top: 6mm; text-align: center;">{{ __('accounts::daybook.empty') }}</p>
    @endif
@endforeach

</body>
</html>
