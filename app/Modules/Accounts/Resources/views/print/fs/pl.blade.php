{{--
    ⭐ লাভ-ক্ষতির বিবরণী — কার্যভিত্তিক (IAS 1.103), পাশে আগের বছরের একই সময় (মালিক, ১৭ নভেম্বর ২০২৬ "ok")।
    ⓘ আয় ধনাত্মক, খরচ বন্ধনীতে; অঙ্ক [[FinancialStatements::profitOrLoss()]] থেকে, বছর বন্ধের দাখিলা বাদ।
--}}
@php
    $paren ??= fn ($v) => bccomp((string) $v, '0', 4) < 0 ? '('.\App\Core\Support\Money::format(bcmul((string) $v, '-1', 4)).')' : \App\Core\Support\Money::format((string) $v);
    $day ??= fn (?string $d) => $d === null ? '' : \Illuminate\Support\Carbon::parse($d)->format('d-m-Y');
    $pctOf = fn (?string $p) => $p === null ? '<span class="word">'.e(__('accounts::fs.bs.new')).'</span>' : $p.'%';
    $tl = fn (string $key, string $class = 'sub') => '<tr class="'.$class.'" data-pl="'.$key.'"><td>'.e(__('accounts::fs.pl.'.$key)).'</td><td class="num">'.$paren($pl['totals'][$key]['now']).'</td><td class="num">'.$paren($pl['totals'][$key]['then']).'</td><td class="num">'.$paren($pl['totals'][$key]['change']).'</td><td class="num">'.($pctOf($pl['totals'][$key]['pct'])).'</td></tr>';
    $lines = fn (string $key) => collect($pl['sections'][$key])->map(fn ($l) => '<tr class="ln"><td class="name">'.e($l['label']).'</td><td class="num">'.$paren($l['now']).'</td><td class="num">'.$paren($l['then']).'</td><td class="num">'.$paren($l['change']).'</td><td class="num">'.($pctOf($l['pct'])).'</td></tr>')->implode('');
@endphp

@include('accounts::print.fs.head', ['title' => __('accounts::fs.pl.title'), 'titleEn' => __('accounts::fs.pl.title_en')])

<table class="info" width="100%" data-info-line><tr>
    <td><span class="k">{{ __('accounts::fs.pl.period') }}:</span> <b>{{ __('core.listing.range', ['from' => $day($pl['from']), 'to' => $day($pl['to'])]) }}</b></td>
    <td><span class="k">{{ __('accounts::fs.pl.compared') }}:</span> <b>{{ __('core.listing.range', ['from' => $day($pl['prior_from']), 'to' => $day($pl['prior_to'])]) }}</b></td>
    <td><span class="k">{{ __('core.listing.branch') }}:</span> <b>{{ $branch }}</b></td>
    <td><b>{{ __('accounts::fs.bs.interim') }}</b></td>
</tr></table>

<table class="st" width="100%" data-pl-table>
    <thead><tr>
        <th class="l" width="44%">{{ __('accounts::fs.bs.particulars') }}</th>
        <th width="17%">{{ $day($pl['from']) }} – {{ $day($pl['to']) }}</th>
        <th width="17%">{{ $day($pl['prior_from']) }} – {{ $day($pl['prior_to']) }}</th>
        <th width="13%">{{ __('accounts::fs.bs.change') }}</th>
        <th width="9%">%</th>
    </tr></thead>
    <tbody>
        {!! $tl('revenue', 'sec') !!}{!! $lines('revenue') !!}
        {!! $tl('cost_of_sales', 'sec') !!}{!! $lines('cost_of_sales') !!}
        {!! $tl('gross_profit') !!}
        {!! $tl('other_income', 'sec') !!}{!! $lines('other_income') !!}
        {!! $tl('operating_expenses', 'sec') !!}{!! $lines('operating_expenses') !!}
        {!! $tl('operating_profit') !!}
        {!! $tl('finance_costs', 'sec') !!}{!! $lines('finance_costs') !!}
        @if ($pl['sections']['workers_fund'] !== [])
            {!! $tl('workers_fund', 'sec') !!}{!! $lines('workers_fund') !!}
        @endif
        {!! $tl('profit_before_tax') !!}
        {!! $tl('income_tax', 'sec') !!}{!! $lines('income_tax') !!}
        {!! $tl('profit', 'grand') !!}
        {!! $tl('oci', 'ln') !!}
        {!! $tl('total_ci', 'grand') !!}
    </tbody>
</table>
