{{--
    ⭐ মালিকানা স্বত্বের পরিবর্তনের বিবরণী — IAS 1.106; কলাম স্থিতিপত্রের মালিকানার মাথাগুলো আর বন্ধ-না-হওয়া লাভ
    ([[BalanceSheetService::equityStatement()]]; শুরু আর শেষ স্থিতিপত্রের একই হিসাব থেকে, তাই পয়সা পর্যন্ত মেলে)।
--}}
@php
    $paren ??= fn ($v) => bccomp((string) $v, '0', 4) < 0 ? '('.\App\Core\Support\Money::format(bcmul((string) $v, '-1', 4)).')' : \App\Core\Support\Money::format((string) $v);
    $day ??= fn (?string $d) => $d === null ? '' : \Illuminate\Support\Carbon::parse($d)->format('d-m-Y');
    $rows = [
        ['opening', __('accounts::fs.soce.opening', ['date' => $day(\Illuminate\Support\Carbon::parse($soce['from'])->subDay()->toDateString())]), 'ln'],
        ['profit', __('accounts::fs.soce.profit'), 'ln'],
        ['transfer', __('accounts::fs.soce.transfer'), 'ln'],
        ['other', __('accounts::fs.soce.other'), 'ln'],
        ['closing', __('accounts::fs.soce.closing', ['date' => $day($soce['to'])]), 'grand'],
    ];
@endphp

<div class="sec-title">{{ __('accounts::fs.soce.title') }} @if ($locale === 'bn')<span class="sec-en">· {{ __('accounts::fs.soce.title_en') }}</span>@endif</div>
<table class="st" width="100%" data-soce>
    <thead><tr>
        <th class="l"></th>
        @foreach ($soce['columns'] as $c)<th>{{ $c['label'] }}</th>@endforeach
        <th>{{ __('accounts::fs.soce.total') }}</th>
    </tr></thead>
    <tbody>
        @foreach ($rows as [$key, $label, $class])
            <tr class="{{ $class }}" data-soce-row="{{ $key }}">
                <td>{{ $label }}</td>
                @foreach ($soce['columns'] as $c)<td class="num">{{ $paren($c[$key]) }}</td>@endforeach
                <td class="num">{{ $paren($soce['total'][$key]) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
@isset($fs)
    <div class="note" data-soce-agrees="{{ bccomp($soce['total']['closing'], $fs['totals']['equity'][0], 2) === 0 ? 'yes' : 'no' }}">
        @if (bccomp($soce['total']['closing'], $fs['totals']['equity'][0], 2) === 0)
            {!! str_replace('✓', '<span class="sym">✓</span>', e(__('accounts::fs.soce.agrees', ['amount' => $paren($fs['totals']['equity'][0])]))) !!}
        @endif
    </div>
@endisset
