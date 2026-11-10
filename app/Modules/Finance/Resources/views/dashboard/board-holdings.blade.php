{{--
    ⭐ অর্থের ড্যাশবোর্ড, সারি 6 — টাকা কোন ব্যাংকে, চেকের পাইপলাইন · 4 সপ্তাহ, সঞ্চয় ও FDR-এর মেয়াদ, মূলধন-উত্তোলন-লাভ বণ্টন
    (মালিকের চূড়ান্ত নমুনা 4h63xhNiy4m9KaJkZZQaEL)। ⓘ অঙ্ক [[FinanceBoard]] থেকে; মূলধনের ঘর মূলধনের চাবিতে।
--}}
@php
    $card = 'background: var(--color-dash-ffffff); border-radius: 1.125rem; padding: 1.125rem 1.25rem; box-shadow: 0 1px 3px var(--color-dash-17-24-39-a06); display: flex; flex-direction: column; color: var(--color-dash-111827)';
    $short = function (string $v): string {
        $a = ltrim($v, '-');
        $s = bccomp($v, '0', 4) < 0 ? '−' : '';
        if (bccomp($a, '10000000', 4) >= 0) { return $s.'৳'.bcdiv($a, '10000000', 2).' '.__('finance::board.crore'); }
        if (bccomp($a, '100000', 4) >= 0) { return $s.'৳'.bcdiv($a, '100000', 1).' '.__('finance::board.lakh'); }
        if (bccomp($a, '1000', 4) >= 0) { return $s.'৳'.bcdiv($a, '1000', 0).'k'; }
        return $s.'৳'.bcadd($a, '0', 0);
    };
    $monthShort = __('finance::board.months_short');

    $bp = ['var(--color-dash-0ea5e9)', 'var(--color-dash-6366f1)', 'var(--color-dash-10b981)', 'var(--color-dash-f59e0b)', 'var(--color-dash-ec4899)', 'var(--color-dash-8b5cf6)'];
    $C = 2 * M_PI * 52; $acc = 0.0; $ring = [];
    $shown = array_slice($banks['parts'], 0, 5);
    $rest = array_reduce(array_slice($banks['parts'], 5), fn (string $c, array $p) => bcadd($c, $p['value'], 4), '0');
    if (bccomp($rest, '0', 4) > 0) { $shown[] = ['name' => __('finance::charts.others'), 'value' => $rest]; }
    foreach ($shown as $i => $p) {
        $len = bccomp($banks['total'], '0', 4) > 0 ? (float) $p['value'] / (float) $banks['total'] * $C : 0;
        $ring[] = $p + ['c' => $bp[$i % count($bp)], 'dash' => round(max($len - 2, 0), 1).' '.round($C - $len + 2, 1), 'off' => round(-$acc, 1)];
        $acc += $len;
    }

    $peak = max(1.0, ...array_map(fn ($w) => max((float) $w['in'], (float) $w['out']), $cheques['weeks']));
    $chq = [];
    foreach ($cheques['weeks'] as $i => $w) {
        $hi = round((float) $w['in'] / $peak * 66); $ho = round((float) $w['out'] / $peak * 66);
        $chq[] = ['x' => 24 + $i * 76, 'xc' => 46 + $i * 76, 'hi' => $hi, 'ho' => $ho, 'yi' => 100 - $hi, 'tyi' => 94 - $hi, 'tyo' => 112 + $ho, 'vi' => (float) $w['in'] > 0 ? $short($w['in']) : '', 'vo' => (float) $w['out'] > 0 ? $short($w['out']) : ''];
    }

    $fdrPeak = max(1.0, ...array_map(fn ($r) => (float) $r['principal'], $deposits['rows'] ?: [['principal' => '0']]));
    $fdrPaint = ['var(--color-dash-14b8a6)', 'var(--color-dash-0ea5e9)', 'var(--color-dash-6366f1)', 'var(--color-dash-8b5cf6)', 'var(--color-dash-10b981)', 'var(--color-dash-f59e0b)'];

    if ($owners !== null) {
        $capPeak = max(1.0, ...array_map(fn ($m) => max((float) $m['in'], (float) $m['out']), $owners['months']));
    }
@endphp
<div data-fin-board-holdings style="display: grid; grid-template-columns: repeat({{ $owners === null ? 3 : 4 }}, minmax(0, 1fr)); gap: 1rem; width: 100%">
    <section data-fin-banks style="{{ $card }} gap: 0.5rem; border-top: 0.3125rem solid var(--color-dash-0ea5e9)">
        <h2 style="margin: 0; font-size: 1rem; font-weight: 700">{{ __('finance::board.r6.banks') }}</h2>
        @if ($ring === [])
            <span style="font-size: 0.8125rem; color: var(--color-dash-6b7280)">{{ __('finance::board.r6.no_bank') }}</span>
        @else
            <div style="display: flex; align-items: center; gap: 0.75rem">
                <svg role="img" aria-label="{{ __('finance::board.r6.banks_label') }}" viewBox="0 0 150 150" style="width: 8.75rem; height: 8.75rem; flex-shrink: 0">
                    <g transform="rotate(-90 75 75)">@foreach ($ring as $d)<circle cx="75" cy="75" r="52" fill="none" stroke="{{ $d['c'] }}" stroke-width="24" stroke-dasharray="{{ $d['dash'] }}" stroke-dashoffset="{{ $d['off'] }}"><title>{{ $d['name'] }} · {{ $short($d['value']) }}</title></circle>@endforeach</g>
                    <text x="75" y="72" text-anchor="middle" font-size="14" font-weight="800" fill="var(--color-dash-111827)">{{ $short($banks['total']) }}</text>
                    <text x="75" y="88" text-anchor="middle" font-size="10" fill="var(--color-dash-6b7280)">{{ __('finance::board.r6.banks_total') }}</text>
                </svg>
                <div style="display: flex; flex-direction: column; gap: 0.3125rem; font-size: 0.75rem; flex-grow: 1; min-width: 0">
                    @foreach ($ring as $d)
                        <span style="display: flex; justify-content: space-between; gap: 0.375rem"><span style="display: flex; align-items: center; gap: 0.375rem; min-width: 0"><span style="flex: none; width: 0.625rem; height: 0.625rem; border-radius: 3px; background: {{ $d['c'] }}"></span><span style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap">{{ $d['name'] }}</span></span><b style="white-space: nowrap">{{ $short($d['value']) }}</b></span>
                    @endforeach
                </div>
            </div>
            @if ($banks['top'] !== null)
                @php $tooMuch = $banks['top']['pct'] > 40 && count($banks['parts']) > 1; @endphp
                <span data-bank-concentration style="font-size: 0.75rem; color: {{ $tooMuch ? 'var(--color-dash-b45309)' : 'var(--color-dash-047857)' }}; background: {{ $tooMuch ? 'var(--color-dash-fffbeb)' : 'var(--color-dash-ecfdf5)' }}; border-radius: 0.625rem; padding: 0.375rem 0.5rem">{{ __($tooMuch ? 'finance::board.r6.too_much' : 'finance::board.r6.spread', ['name' => $banks['top']['name'], 'pct' => $banks['top']['pct']]) }}</span>
            @endif
        @endif
    </section>

    <section data-fin-cheques style="{{ $card }} gap: 0.5rem; border-top: 0.3125rem solid var(--color-dash-22c55e)">
        <h2 style="margin: 0; font-size: 1rem; font-weight: 700">{{ __('finance::board.r6.cheques') }}</h2>
        <svg role="img" aria-label="{{ __('finance::board.r6.cheques_label') }}" viewBox="0 0 320 200" style="width: 100%; height: 12.5rem">
            <line x1="0" y1="100" x2="320" y2="100" stroke="var(--color-dash-94a3b8)"></line>
            @foreach ($chq as $i => $c)
                @if ($c['hi'] > 0)<rect x="{{ $c['x'] }}" y="{{ $c['yi'] }}" width="44" height="{{ $c['hi'] }}" rx="6" fill="var(--color-dash-22c55e)"></rect>@endif
                @if ($c['ho'] > 0)<rect x="{{ $c['x'] }}" y="100" width="44" height="{{ $c['ho'] }}" rx="6" fill="var(--color-dash-f97316)"></rect>@endif
                <text x="{{ $c['xc'] }}" y="{{ $c['tyi'] }}" text-anchor="middle" font-size="10" font-weight="700" fill="var(--color-dash-15803d)">{{ $c['vi'] }}</text>
                <text x="{{ $c['xc'] }}" y="{{ $c['tyo'] }}" text-anchor="middle" font-size="10" font-weight="700" fill="var(--color-dash-c2410c)">{{ $c['vo'] }}</text>
                <text x="{{ $c['xc'] }}" y="196" text-anchor="middle" font-size="10" fill="var(--color-dash-6b7280)">{{ __('finance::board.r6.week', ['n' => $i + 1]) }}</text>
            @endforeach
        </svg>
        <span style="font-size: 0.75rem; color: var(--color-dash-4b5563)"><b style="color: var(--color-dash-16a34a)">■</b> {{ __('finance::board.r6.in', ['value' => $short($cheques['in'])]) }} · <b style="color: var(--color-dash-ea580c)">■</b> {{ __('finance::board.r6.out', ['value' => $short($cheques['out'])]) }}@if ($cheques['bounce'] !== null) · {{ __('finance::board.r6.bounce', ['n' => $cheques['bounce']]) }}@endif</span>
    </section>

    <section data-fin-fdr style="{{ $card }} gap: 0.5625rem; border-top: 0.3125rem solid var(--color-dash-14b8a6)">
        <div style="display: flex; justify-content: space-between; align-items: baseline; gap: 0.5rem">
            <h2 style="margin: 0; font-size: 1rem; font-weight: 700">{{ __('finance::board.r6.fdr') }}</h2>
            <span style="font-size: 0.8125rem; font-weight: 800">{{ $short($deposits['total']) }}</span>
        </div>
        @if ($deposits['rows'] === [])
            <span style="font-size: 0.8125rem; color: var(--color-dash-6b7280)">{{ __('finance::board.r6.no_fdr') }}</span>
        @else
            <div style="position: relative; height: 8.125rem; margin-top: 0.375rem">
                <span style="position: absolute; left: 0; right: 0; top: 3.625rem; height: 4px; background: linear-gradient(90deg, var(--color-dash-99f6e4), var(--color-dash-14b8a6), var(--color-dash-6366f1)); border-radius: 999px"></span>
                @foreach ($deposits['rows'] as $i => $f)
                    @php
                        $r = round(14 + 14 * (float) $f['principal'] / $fdrPeak);
                        $x = 8 + min(84, $f['days'] / 365 * 84);
                        $date = \Illuminate\Support\Carbon::parse($f['date']);
                    @endphp
                    <div data-fdr style="position: absolute; left: {{ round($x, 1) }}%; top: {{ $i % 2 === 0 ? round((62 - $r) / 16, 3) : 2.5 }}rem; transform: translateX(-50%); display: flex; flex-direction: column; align-items: center; gap: 2px; font-size: 0.6875rem; text-align: center" title="{{ $f['name'] }}">
                        <span style="width: {{ $r / 16 }}rem; height: {{ $r / 16 }}rem; border-radius: 999px; background: {{ $fdrPaint[$i % count($fdrPaint)] }}; border: 3px solid var(--color-dash-ffffff); box-shadow: 0 0 0 2px {{ $fdrPaint[$i % count($fdrPaint)] }}"></span>
                        <b>{{ $short($f['principal']) }}</b><span style="color: var(--color-dash-4b5563)">{{ $date->day }} {{ $monthShort[$date->month] }}</span>
                    </div>
                @endforeach
            </div>
        @endif
        <span style="font-size: 0.75rem; color: var(--color-dash-4b5563)">{{ __('finance::board.r6.fdr_note') }}@if ($deposits['rate'] !== null) · {{ __('finance::board.r6.fdr_rate', ['n' => $deposits['rate']]) }}@endif @if (bccomp($deposits['soon'], '0', 4) > 0) · {{ __('finance::board.r6.fdr_soon', ['value' => $short($deposits['soon']), 'name' => $deposits['soon_name']]) }}@endif</span>
    </section>

    @if ($owners !== null)
        <section data-fin-owners style="{{ $card }} gap: 0.625rem; border-top: 0.3125rem solid var(--color-dash-d946ef)">
            <h2 style="margin: 0; font-size: 1rem; font-weight: 700">{{ __('finance::board.r6.owners') }}</h2>
            <div style="display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 0.5rem">
                @foreach ([['capital', 'linear-gradient(135deg, var(--color-dash-8b5cf6), var(--color-dash-6d28d9))'], ['drawn', 'linear-gradient(135deg, var(--color-dash-f472b6), var(--color-dash-db2777))'], ['shared', 'linear-gradient(135deg, var(--color-dash-f59e0b), var(--color-dash-d97706))']] as [$k, $bg])
                    <div style="background: {{ $bg }}; color: var(--color-dash-ffffff); border-radius: 0.875rem; padding: 0.625rem 0.75rem; min-width: 0"><span style="font-size: 0.6875rem; opacity: 0.9">{{ __('finance::board.r6.'.$k) }}</span><div style="font-size: 1.0625rem; font-weight: 800; white-space: nowrap">{{ $short($owners[$k]) }}</div></div>
                @endforeach
            </div>
            <div style="display: flex; align-items: flex-end; gap: 0.5rem; height: 6.875rem; border-bottom: 1px solid var(--color-dash-e5e7f0)">
                @foreach ($owners['months'] as $m)
                    <div style="flex: 1 1 0; display: flex; gap: 3px; align-items: flex-end; justify-content: center" title="{{ $short($m['in']) }} · {{ $short($m['out']) }}"><span style="width: 0.75rem; height: {{ round((float) $m['in'] / $capPeak * 100) / 16 }}rem; background: var(--color-dash-8b5cf6); border-radius: 4px 4px 0 0"></span><span style="width: 0.75rem; height: {{ round((float) $m['out'] / $capPeak * 100) / 16 }}rem; background: var(--color-dash-f472b6); border-radius: 4px 4px 0 0"></span></div>
                @endforeach
            </div>
            <div style="display: flex; gap: 0.5rem; font-size: 0.6875rem; color: var(--color-dash-6b7280)">@foreach ($owners['months'] as $m)<span style="flex: 1 1 0; text-align: center">{{ $monthShort[$m['month']] }}</span>@endforeach</div>
            <span style="font-size: 0.75rem; color: var(--color-dash-4b5563)"><b style="color: var(--color-dash-8b5cf6)">■</b> {{ __('finance::board.r6.capital') }} &#160; <b style="color: var(--color-dash-f472b6)">■</b> {{ __('finance::board.r6.drawn') }}</span>
        </section>
    @endif
</div>
