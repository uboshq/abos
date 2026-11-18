{{--
    ⭐ নগদ প্রবাহের বিবরণী — পরোক্ষ পদ্ধতি (IAS 7.18(b)): নিট মুনাফা থেকে পরিচালন নগদ, তারপর বিনিয়োগ আর অর্থায়ন; শেষে শুরু + পরিবর্তন = শেষ।
    ⓘ অঙ্ক রিপোর্ট-ইঞ্জিনের নগদ প্রবাহ আর তার পরোক্ষ মিলন থেকে ([[FinancialStatements::cashFlow()]]); প্রত্যক্ষ পদ্ধতির অঙ্কের সাথে মেলানো লেখা থাকে।
--}}
@php
    $paren ??= fn ($v) => bccomp((string) $v, '0', 4) < 0 ? '('.\App\Core\Support\Money::format(bcmul((string) $v, '-1', 4)).')' : \App\Core\Support\Money::format((string) $v);
    $day ??= fn (?string $d) => $d === null ? '' : \Illuminate\Support\Carbon::parse($d)->format('d-m-Y');
@endphp

<div class="sec-title">{{ __('accounts::fs.cf.title') }} @if ($locale === 'bn')<span class="sec-en">· {{ __('accounts::fs.cf.title_en') }}</span>@endif</div>
<div class="note">{{ __('core.listing.range', ['from' => $day($cf['from']), 'to' => $day($cf['to'])]) }}</div>
<table class="st" width="100%" data-cf>
    <thead><tr><th class="l">{{ __('accounts::fs.bs.particulars') }}</th><th width="26%">{{ __('accounts::fs.notes.amount') }}</th></tr></thead>
    <tbody>
        <tr class="sec"><td colspan="2">{{ __('accounts::fs.cf.operating') }}</td></tr>
        @foreach ($cf['indirect'] as $l)
            <tr class="ln"><td class="name">{{ $l['label'] }}</td><td class="num">{{ $paren($l['value']) }}</td></tr>
        @endforeach
        <tr class="sub" data-cf-line="operating"><td>{{ __('accounts::fs.cf.net_operating') }}</td><td class="num">{{ $paren($cf['operating']) }}</td></tr>

        <tr class="sec"><td colspan="2">{{ __('accounts::fs.cf.investing') }}</td></tr>
        @foreach ($cf['investing'] as $l)
            <tr class="ln"><td class="name">{{ $l['label'] }}</td><td class="num">{{ $paren($l['value']) }}</td></tr>
        @endforeach
        <tr class="sub" data-cf-line="investing"><td>{{ __('accounts::fs.cf.net_investing') }}</td><td class="num">{{ $paren($cf['investing_total']) }}</td></tr>

        <tr class="sec"><td colspan="2">{{ __('accounts::fs.cf.financing') }}</td></tr>
        @foreach ($cf['financing'] as $l)
            <tr class="ln"><td class="name">{{ $l['label'] }}</td><td class="num">{{ $paren($l['value']) }}</td></tr>
        @endforeach
        <tr class="sub" data-cf-line="financing"><td>{{ __('accounts::fs.cf.net_financing') }}</td><td class="num">{{ $paren($cf['financing_total']) }}</td></tr>

        <tr class="grand" data-cf-line="change"><td>{{ __('accounts::fs.cf.net_change') }}</td><td class="num">{{ $paren($cf['change']) }}</td></tr>
        <tr class="ln"><td>{{ __('accounts::fs.cf.opening') }}</td><td class="num">{{ $paren($cf['opening']) }}</td></tr>
        <tr class="grand" data-cf-line="closing"><td>{{ __('accounts::fs.cf.closing') }}</td><td class="num">{{ $paren($cf['closing']) }}</td></tr>
    </tbody>
</table>
<div class="note" data-cf-check="{{ bccomp($cf['gap'], '0', 2) === 0 ? 'agrees' : 'differs' }}">
    @if (bccomp($cf['gap'], '0', 2) === 0)
        {!! str_replace('✓', '<span class="sym">✓</span>', e(__('accounts::fs.cf.check'))) !!}
    @else
        <span class="bad">{{ __('accounts::fs.cf.check_bad', ['gap' => $paren($cf['gap'])]) }}</span>
    @endif
    · {!! str_replace('✓', '<span class="sym">✓</span>', e($cf['direct_agrees'] ? __('accounts::fs.cf.direct_check', ['amount' => $paren($cf['direct'])]) : __('accounts::fs.cf.direct_bad', ['amount' => $paren($cf['direct'])]))) !!}
</div>
