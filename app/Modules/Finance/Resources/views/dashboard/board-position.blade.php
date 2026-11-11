{{--
    ⭐ অর্থের ড্যাশবোর্ড, সারি 3 — গ্রাহকের পাওনা, সরবরাহকারীর দেনা, তারল্য ও সুদের অনুপাত, নগদ চক্র (মালিকের চূড়ান্ত নমুনা 4h63xhNiy4m9KaJkZZQaEL)।
    ⓘ অনুপাতের লক্ষ্য প্রচলিত মাপে: চলতি 1.5+, দ্রুত 1.0+, নগদ 0.2+, সুদ শোধ 3×+; অর্ধবৃত্তের ভরাট নমুনার মাপে।
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
    $spark = function (array $values): string {
        $vals = array_filter($values, fn ($v) => $v !== null);
        if (count($vals) < 2) { return ''; }
        $hi = max($vals); $lo = min($vals); $span = max(1, $hi - $lo);
        $n = count($values) - 1;
        $pts = [];
        foreach ($values as $i => $v) { if ($v !== null) { $pts[] = round($i / $n * 200).','.round(4 + ($hi - $v) / $span * 32); } }
        return implode(' ', $pts);
    };

    $boxes = [];
    if ($position['receivable'] !== null) {
        $r = $position['receivable'];
        $dso = end($position['dso']);
        $boxes[] = ['mark' => 'data-fin-receivable', 'title' => __('finance::board.pos.receivable'), 'total' => $r['total'], 'edge' => 'var(--color-dash-10b981)',
            'rows' => array_map(fn ($b, $i) => ['b' => __('finance::board.pos.r')[$i], 'v' => $b['amount']], $r['buckets'], array_keys($r['buckets'])),
            'paint' => ['var(--color-dash-10b981)', 'var(--color-dash-eab308)', 'var(--color-dash-f97316)', 'var(--color-dash-ef4444)'],
            'trend' => $spark($position['dso']),
            'metric' => $dso === null ? __('finance::board.pos.no_days') : __('finance::board.pos.dso', ['n' => $dso]).($position['dso_before'] !== null ? ' · '.__('finance::board.pos.dso_before', ['n' => $position['dso_before']]) : '').' · '.__('finance::board.pos.dso_line')];
    }
    $p = $position['payable'];
    $dpo = end($position['dpo']);
    $boxes[] = ['mark' => 'data-fin-payable', 'title' => __('finance::board.pos.payable'), 'total' => $p['total'], 'edge' => 'var(--color-dash-f97316)',
        'rows' => array_map(fn ($b, $i) => ['b' => __('finance::board.pos.p')[$i], 'v' => $b['amount']], $p['buckets'], array_keys($p['buckets'])),
        'paint' => ['var(--color-dash-38bdf8)', 'var(--color-dash-eab308)', 'var(--color-dash-f97316)', 'var(--color-dash-ef4444)'],
        'trend' => $spark($position['dpo']),
        'metric' => ($dpo === null ? __('finance::board.pos.no_days') : __('finance::board.pos.dpo', ['n' => $dpo])).' · '.__('finance::board.pos.due7', ['value' => $short($p['due7'])])];

    $ratio = $position['ratios'];
    $gauge = function (?string $v, float $goal, float $full, string $okInk, string $okSoft, string $key, bool $times = false) {
        $ok = $v !== null && (float) $v >= $goal;
        return [
            'key' => $key,
            'v' => $v === null ? '—' : ($times ? number_format((float) $v, 0, '.', '').'×' : number_format((float) $v, 2, '.', '')),
            'dash' => $v === null ? '0 200' : round(min(1, max(0, (float) $v / $full)) * 151).' 200',
            'c' => $v === null ? 'var(--color-dash-64748b)' : ($ok ? $okInk : 'var(--color-dash-d97706)'),
            'soft' => $v === null ? 'var(--color-dash-f8fafc)' : ($ok ? $okSoft : 'var(--color-dash-fffbeb)'),
            's' => $v === null ? __($key === 'interest' ? 'finance::board.pos.no_interest' : 'finance::board.pos.no_debt')
                : ($times ? __('finance::board.pos.above', ['n' => '3']) : ($ok ? __('finance::board.pos.goal', ['n' => rtrim(rtrim(number_format($goal, 1, '.', ''), '0'), '.')]) : __('finance::board.pos.below_goal', ['n' => rtrim(rtrim(number_format($goal, 1, '.', ''), '0'), '.')]))),
        ];
    };
    $gauges = [
        $gauge($ratio['current'], 1.5, 3.33, 'var(--color-dash-10b981)', 'var(--color-dash-ecfdf5)', 'current'),
        $gauge($ratio['quick'], 1.0, 2.42, 'var(--color-dash-3b82f6)', 'var(--color-dash-eff6ff)', 'quick'),
        $gauge($ratio['cash'], 0.2, 1.0, 'var(--color-dash-10b981)', 'var(--color-dash-ecfdf5)', 'cash'),
        $gauge($ratio['interest'], 3.0, 58.0, 'var(--color-dash-8b5cf6)', 'var(--color-dash-f5f3ff)', 'interest', true),
    ];
    if ($ratio['interest'] !== null && (float) $ratio['interest'] < 3) {
        $gauges[3]['s'] = __('finance::board.pos.below_goal', ['n' => '3×']);
    }

    $cyc = $position['cycle'];
    $before = $position['cycle_before'];
    $biggest = max(1, (int) $cyc['dio'], (int) $cyc['dso'], (int) $cyc['dpo']);
    $cycRows = [
        ['t' => __('finance::board.pos.dio_row'), 'n' => $cyc['dio'], 'sign' => '+', 'bg' => 'linear-gradient(90deg, var(--color-dash-38bdf8), var(--color-dash-6366f1))'],
        ['t' => __('finance::board.pos.dso_row'), 'n' => $cyc['dso'], 'sign' => '+', 'bg' => 'linear-gradient(90deg, var(--color-dash-34d399), var(--color-dash-10b981))'],
        ['t' => __('finance::board.pos.dpo_row'), 'n' => $cyc['dpo'], 'sign' => '−', 'bg' => 'linear-gradient(90deg, var(--color-dash-fb7185), var(--color-dash-f43f5e))'],
    ];
    $delta = $cyc['ccc'] !== null && $before['ccc'] !== null ? $cyc['ccc'] - $before['ccc'] : null;
@endphp
<div data-fin-board-position style="display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 1rem; width: 100%">
    @foreach ($boxes as $box)
        @php $sum = array_reduce($box['rows'], fn (float $c, array $r) => $c + (float) $r['v'], 0.0); @endphp
        <section {{ $box['mark'] }} style="{{ $card }} gap: 0.625rem; border-top: 0.3125rem solid {{ $box['edge'] }}">
            <div style="display: flex; justify-content: space-between; align-items: baseline; gap: 0.5rem">
                <h2 style="margin: 0; font-size: 1rem; font-weight: 700">{{ $box['title'] }}</h2>
                <span style="font-size: 1.25rem; font-weight: 800">{{ $short($box['total']) }}</span>
            </div>
            <div style="display: flex; height: 1.5rem; border-radius: 999px; overflow: hidden; gap: 2px; background: var(--color-dash-f1f2f7)">
                @foreach ($box['rows'] as $i => $row)
                    @if ($sum > 0 && (float) $row['v'] > 0)<span style="flex: {{ round((float) $row['v'] / $sum * 100, 2) }}; background: {{ $box['paint'][$i] }}"></span>@endif
                @endforeach
            </div>
            @foreach ($box['rows'] as $i => $row)
                <div style="display: flex; justify-content: space-between; font-size: 0.8125rem; padding: 0.3125rem 0; border-top: 1px solid var(--color-dash-f1f2f7)"><span style="display: flex; align-items: center; gap: 0.5rem"><span style="width: 0.625rem; height: 0.625rem; border-radius: 3px; background: {{ $box['paint'][$i] }}"></span>{{ $row['b'] }}</span><b>{{ $short($row['v']) }}</b></div>
            @endforeach
            @if ($box['trend'] !== '')
                <svg aria-hidden="true" viewBox="0 0 200 40" preserveAspectRatio="none" style="width: 100%; height: 2.5rem"><polyline points="{{ $box['trend'] }}" fill="none" stroke="{{ $box['edge'] }}" stroke-width="3"></polyline></svg>
            @endif
            <span style="font-size: 0.8125rem; font-weight: 700; color: {{ $box['edge'] }}">{{ $box['metric'] }}</span>
        </section>
    @endforeach

    <section data-fin-ratios style="{{ $card }} gap: 0.375rem; border-top: 0.3125rem solid var(--color-dash-8b5cf6)">
        <h2 style="margin: 0; font-size: 1rem; font-weight: 700">{{ __('finance::board.pos.ratios') }}</h2>
        <div style="display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.5rem">
            @foreach ($gauges as $g)
                <div data-ratio="{{ $g['key'] }}" style="display: flex; flex-direction: column; align-items: center; background: {{ $g['soft'] }}; border-radius: 0.875rem; padding: 0.5rem 0.25rem">
                    <svg aria-hidden="true" viewBox="0 0 120 70" style="width: 6.875rem; height: 4rem">
                        <path d="M 12 62 A 48 48 0 0 1 108 62" fill="none" stroke="var(--color-dash-ffffff)" stroke-width="12" stroke-linecap="round"></path>
                        @if ($g['dash'] !== '0 200')<path d="M 12 62 A 48 48 0 0 1 108 62" fill="none" stroke="{{ $g['c'] }}" stroke-width="12" stroke-linecap="round" stroke-dasharray="{{ $g['dash'] }}"></path>@endif
                        <text x="60" y="58" text-anchor="middle" font-size="18" font-weight="800" fill="var(--color-dash-111827)">{{ $g['v'] }}</text>
                    </svg>
                    <span style="font-size: 0.75rem; font-weight: 600; text-align: center">{{ __('finance::board.pos.'.$g['key']) }}</span>
                    <span style="font-size: 0.6875rem; color: {{ $g['c'] }}; text-align: center">{{ $g['s'] }}</span>
                </div>
            @endforeach
        </div>
    </section>

    <section data-fin-cycle style="border-radius: 1.125rem; padding: 1.125rem 1.25rem; background: linear-gradient(160deg, var(--color-dash-0f172a), var(--color-dash-1e3a8a) 55%, var(--color-dash-0e7490)); color: var(--color-dash-ffffff); display: flex; flex-direction: column; gap: 0.75rem; box-shadow: 0 0.875rem 1.875rem var(--color-dash-14-116-144-a30)">
        <h2 style="margin: 0; font-size: 1rem; font-weight: 700; color: var(--color-dash-ffffff)">{{ __('finance::board.pos.ccc') }}</h2>
        <span style="font-size: 0.75rem; opacity: 0.85">{{ __('finance::board.pos.ccc_note') }}</span>
        @foreach ($cycRows as $c)
            <div style="display: grid; grid-template-columns: 7.5rem minmax(0, 1fr) 3.625rem; gap: 0.5rem; align-items: center; font-size: 0.8125rem"><span>{{ $c['t'] }}</span><span style="height: 1rem; border-radius: 999px; background: var(--color-dash-255-255-255-a12)"><span style="display: block; height: 1rem; width: {{ $c['n'] === null ? 0 : round($c['n'] / $biggest * 50) }}%; background: {{ $c['bg'] }}; border-radius: 999px"></span></span><b style="text-align: right">{{ $c['n'] === null ? '—' : $c['sign'].__('finance::board.pos.days', ['n' => $c['n']]) }}</b></div>
        @endforeach
        <div style="display: flex; align-items: baseline; gap: 0.625rem; flex-wrap: wrap; border-top: 1px solid var(--color-dash-255-255-255-a20); padding-top: 0.625rem">
            @if ($cyc['ccc'] === null)
                <span style="font-size: 0.875rem; font-weight: 700">{{ __('finance::board.pos.no_cycle') }}</span>
            @else
                <span data-ccc style="font-size: 2.25rem; font-weight: 800">{{ __('finance::board.pos.days', ['n' => $cyc['ccc']]) }}</span>
                @if ($delta !== null)
                    <span style="font-size: 0.75rem; font-weight: 700; background: {{ $delta <= 0 ? 'var(--color-dash-52-211-153-a25)' : 'var(--color-dash-239-68-68-a35)' }}; border-radius: 999px; padding: 2px 0.625rem">{{ $delta < 0 ? __('finance::board.pos.better', ['n' => -$delta]) : ($delta > 0 ? __('finance::board.pos.worse', ['n' => $delta]) : __('finance::board.pos.same')) }}</span>
                @endif
            @endif
        </div>
        @if (bccomp($position['daily_sales'], '0', 4) > 0)
            <span style="font-size: 0.75rem; opacity: 0.85">{{ __('finance::board.pos.per_day', ['value' => $short($position['daily_sales'])]) }}</span>
        @endif
    </section>
</div>
