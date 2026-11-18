{{--
    ⭐ আর্থিক অবস্থার বিবরণী (স্থিতিপত্র) — IAS 1 ছকে, নমুনা `rs-bs-print` পাতা 1 (মালিক, ১৭ নভেম্বর ২০২৬ "ok")।
    ⓘ অ-চলতি/চলতি সম্পদ → মোট সম্পদ → মালিকানা স্বত্ব → অ-চলতি/চলতি দায় → মোট; প্রতি লাইনে নোট, আগের বছর-শেষ, পরিবর্তন, %।
    ⓘ নিচে অনুপাতের সারি, "সম্পদ = মালিকের অংশ + দেনা" যাচাই, তারপর সই। অঙ্ক [[FinancialStatements::position()]] থেকে।
--}}
@php
    $paren ??= fn ($v) => bccomp((string) $v, '0', 4) < 0 ? '('.\App\Core\Support\Money::format(bcmul((string) $v, '-1', 4)).')' : \App\Core\Support\Money::format((string) $v);
    $day ??= fn (?string $d) => $d === null ? '' : \Illuminate\Support\Carbon::parse($d)->format('d-m-Y');
    $pct = fn (?string $p) => $p === null ? '<span class="word">'.e(__('accounts::fs.bs.new')).'</span>' : $p.'%';
    $row = fn (array $l, string $class = 'ln') => '<tr class="'.$class.'"><td class="name">'.e($l['label']).'</td><td class="c">'.($l['note'] ?? '').'</td><td class="num">'.$paren($l['now']).'</td><td class="num">'.$paren($l['then']).'</td><td class="num">'.$paren($l['change']).'</td><td class="num">'.($pct($l['pct'])).'</td></tr>';
    $tot = function (string $label, array $pair, string $class = 'sub') use ($paren, $pct) {
        $change = bcsub($pair[0], $pair[1], 4);
        $p = \App\Modules\Accounts\Services\FinancialStatements::percent($pair[0], $pair[1]);

        return '<tr class="'.$class.'"><td>'.e($label).'</td><td></td><td class="num">'.$paren($pair[0]).'</td><td class="num">'.$paren($pair[1]).'</td><td class="num">'.$paren($change).'</td><td class="num">'.($pct($p)).'</td></tr>';
    };
    $side = fn (string $label) => '<tr class="side"><td colspan="6">'.e($label).'</td></tr>';
    $sec = fn (string $label) => '<tr class="sec"><td colspan="6">'.e($label).'</td></tr>';
    $ratio = fn (?string $r) => $r === null ? __('accounts::fs.bs.not_meaningful') : __('accounts::fs.bs.ratio_times', ['value' => $r]);
@endphp

@include('accounts::print.fs.head', ['title' => __('accounts::fs.bs.title'), 'titleEn' => 'STATEMENT OF FINANCIAL POSITION'])

{{-- ⛔ মাথায় বাক্স নয়, এক লাইনের তথ্য --}}
<table class="info" width="100%" data-info-line><tr>
    <td><span class="k">{{ __('accounts::fs.bs.as_at') }}:</span> <b>{{ $day($fs['as_of']) }}</b></td>
    <td><span class="k">{{ __('accounts::fs.bs.compared') }}:</span> <b>{{ __('accounts::fs.bs.prior_note', ['date' => $day($fs['prior_as_of'])]) }}</b></td>
    <td><span class="k">{{ __('core.listing.branch') }}:</span> <b>{{ $branch }}</b></td>
    <td><b data-interim>{{ __('accounts::fs.bs.interim') }}</b></td>
    <td style="text-align: right;"><span class="k">{{ __('core.listing.printed_by') }}:</span> <b>{{ $printedBy }}</b></td>
</tr></table>

<table class="st" width="100%" data-position>
    <thead><tr>
        <th class="l" width="40%">{{ __('accounts::fs.bs.particulars') }}</th>
        <th width="6%" style="text-align: center;">{{ __('accounts::fs.bs.note') }}</th>
        <th width="16%">{{ $day($fs['as_of']) }}</th>
        <th width="16%">{{ $day($fs['prior_as_of']) }}</th>
        <th width="13%">{{ __('accounts::fs.bs.change') }}</th>
        <th width="9%">%</th>
    </tr></thead>
    <tbody>
        {!! $side(__('accounts::fs.bs.assets')) !!}
        @foreach (['non_current', 'current', 'other'] as $part)
            @if ($fs['assets'][$part] !== [])
                {!! $sec(__('accounts::fs.bs.'.$part.'_assets')) !!}
                @foreach ($fs['assets'][$part] as $l) {!! $row($l) !!} @endforeach
                {!! $tot(__('accounts::fs.bs.total_'.$part.'_assets'), $fs['totals'][$part.'_assets']) !!}
            @endif
        @endforeach
        {!! $tot(__('accounts::fs.bs.total_assets'), $fs['totals']['assets'], 'grand') !!}

        {!! $side(__('accounts::fs.bs.equity_and_liabilities')) !!}
        {!! $sec(__('accounts::fs.bs.equity')) !!}
        @foreach ($fs['equity'] as $l) {!! $row($l) !!} @endforeach
        {!! $tot(__('accounts::fs.bs.total_equity'), $fs['totals']['equity']) !!}
        @foreach (['non_current', 'current', 'other'] as $part)
            @if ($fs['liabilities'][$part] !== [])
                {!! $sec(__('accounts::fs.bs.'.$part.'_liabilities')) !!}
                @foreach ($fs['liabilities'][$part] as $l) {!! $row($l) !!} @endforeach
                {!! $tot(__('accounts::fs.bs.total_'.$part.'_liabilities'), $fs['totals'][$part.'_liabilities']) !!}
            @endif
        @endforeach
        {!! $tot(__('accounts::fs.bs.total_liabilities'), $fs['totals']['liabilities']) !!}
        {!! $tot(__('accounts::fs.bs.total_equity_and_liabilities'), $fs['totals']['funding'], 'grand') !!}
    </tbody>
</table>

{{-- ⭐ অনুপাতের সারি — চলতি অনুপাত, চলতি মূলধন, দায় ÷ স্বত্ব --}}
<div class="strip" data-ratios>
    {{ __('accounts::fs.bs.ratio_current') }} <b data-ratio="current">{{ $ratio($fs['ratios']['current']) }}</b>
    {{ __('accounts::fs.bs.ratio_was', ['value' => $fs['ratios']['current_prior'] ?? __('accounts::fs.bs.not_meaningful')]) }}
    · {{ __('accounts::fs.bs.working_capital') }} <b data-ratio="working">{{ $paren($fs['ratios']['working_capital']) }}</b>
    · {{ __('accounts::fs.bs.debt_equity') }} <b data-ratio="debt_equity">{{ $ratio($fs['ratios']['debt_equity']) }}</b>
</div>

{{-- ⭐ সম্পদ = মালিকানা স্বত্ব + দায় — না মিললে লাল, কত তা লেখা --}}
<div class="ident" data-recon data-identity="{{ $fs['agrees'] ? 'agrees' : 'differs' }}">
    @if ($fs['agrees'])
        {{ __('accounts::fs.bs.identity', ['assets' => $paren($fs['totals']['assets'][0]), 'equity' => $paren($fs['totals']['equity'][0]), 'liabilities' => $paren($fs['totals']['liabilities'][0])]) }} <span class="sym">✓</span>
    @else
        <span class="bad">{{ __('accounts::fs.bs.identity_bad', ['assets' => $paren($fs['totals']['assets'][0]), 'funding' => $paren($fs['totals']['funding'][0]), 'gap' => $paren($fs['difference'])]) }}</span>
    @endif
</div>

@if ($branchNote)
    <div class="note">{{ __('accounts::fs.bs.branch_note') }}</div>
@endif

@include('accounts::print.fs.signs')
<div class="note">{{ __('accounts::fs.bs.notes_part') }}</div>
