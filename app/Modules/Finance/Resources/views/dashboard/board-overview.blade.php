{{--
    ⭐ অর্থের ড্যাশবোর্ড, সারি 7 — আর্থিক স্বাস্থ্য (রাডার), টাকা কোথা থেকে এল কোথায় গেল (দুই ভাগ চওড়া), তহবিলের ধারা · 12 মাস
    (মালিকের চূড়ান্ত নমুনা 4h63xhNiy4m9KaJkZZQaEL)। ⓘ রাডারের কেন্দ্র (160, 120), ব্যাসার্ধ 66 (দুই পাশের লেখার জায়গা রেখে), ছয় কোণ ওপর থেকে ঘড়ির কাঁটায়।
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

    $pt = function (int $i, float $f): array { $a = -M_PI / 2 + $i * 2 * M_PI / 6; return [round(160 + cos($a) * 66 * $f, 1), round(120 + sin($a) * 66 * $f, 1)]; };
    $poly = fn (array $fs) => implode(' ', array_map(fn ($f, $i) => implode(',', $pt($i, $f)), $fs, array_keys($fs)));
    $toF = fn (array $vals) => array_map(fn ($v) => max(0.04, ($v ?? 0) / 100), $vals);
    $axes = __('finance::board.r7.axes');
    $labels = [];
    foreach ($axes as $i => $t) {
        [$x, $y] = $pt($i, 1.16);
        $labels[] = ['t' => $t.($health['now'][$i] === null ? '' : ' '.$health['now'][$i]), 'x' => $x, 'y' => $y + ($i === 0 ? -2 : ($i === 3 ? 10 : 4)), 'a' => abs($x - 160) < 5 ? 'middle' : ($x > 160 ? 'start' : 'end')];
    }

    $srcPaint = ['var(--color-dash-10b981)', 'var(--color-dash-6366f1)', 'var(--color-dash-0ea5e9)', 'var(--color-dash-94a3b8)'];
    $usePaint = ['var(--color-dash-f97316)', 'var(--color-dash-ef4444)', 'var(--color-dash-8b5cf6)', 'var(--color-dash-ec4899)', 'var(--color-dash-f59e0b)', 'var(--color-dash-0ea5e9)', 'var(--color-dash-94a3b8)'];

    $points = array_map(fn ($p) => (float) $p['total'], $trend['points']);
    $hi = max($points ?: [1.0]); $lo = min($points ?: [0.0]);
    $span = max(1.0, $hi - $lo);
    $gx = fn (int $i) => round(21 + $i * 28.9, 1);
    $gy = fn (float $v) => round(205 - ($v - $lo) / $span * 175, 1);
    $line = implode(' ', array_map(fn ($v, $i) => $gx($i).','.$gy($v), $points, array_keys($points)));
    $area = $points === [] ? '' : 'M'.$gx(0).',205 '.implode(' ', array_map(fn ($v, $i) => 'L'.$gx($i).','.$gy($v), $points, array_keys($points))).' L'.$gx(count($points) - 1).',205 Z';
@endphp
<div data-fin-board-overview style="display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 1rem; width: 100%">
    <section data-fin-health style="{{ $card }} gap: 0.375rem; border-top: 0.3125rem solid var(--color-dash-6366f1)">
        <div style="display: flex; justify-content: space-between; align-items: baseline; gap: 0.5rem">
            <h2 style="margin: 0; font-size: 1rem; font-weight: 700">{{ __('finance::board.r7.health') }}</h2>
            @if ($health['score'] !== null)<span data-health-score style="font-size: 0.8125rem; font-weight: 800; color: var(--color-dash-4f46e5)">{{ __('finance::board.r7.score', ['n' => $health['score']]) }}</span>@endif
        </div>
        <svg role="img" aria-label="{{ __('finance::board.r7.health_label') }}" viewBox="0 0 320 250" style="width: 100%; height: 15.625rem">
            @foreach ([1 / 3, 2 / 3, 1] as $f)<polygon points="{{ $poly(array_fill(0, 6, $f)) }}" fill="none" stroke="var(--color-dash-e5e7f0)"></polygon>@endforeach
            <polygon points="{{ $poly($toF($health['last'])) }}" fill="var(--color-dash-148-163-184-a18)" stroke="var(--color-dash-94a3b8)" stroke-width="2" stroke-dasharray="4 3"></polygon>
            <polygon points="{{ $poly($toF($health['now'])) }}" fill="var(--color-dash-99-102-241-a28)" stroke="var(--color-dash-4f46e5)" stroke-width="3"></polygon>
            @foreach ($labels as $l)<text x="{{ $l['x'] }}" y="{{ $l['y'] }}" text-anchor="{{ $l['a'] }}" font-size="10" fill="var(--color-dash-374151)">{{ $l['t'] }}</text>@endforeach
        </svg>
        <span style="font-size: 0.75rem; color: var(--color-dash-4b5563)"><b style="color: var(--color-dash-4f46e5)">{{ __('finance::board.r7.blue') }}</b> {{ __('finance::board.r7.this_month') }} · <b style="color: var(--color-dash-94a3b8)">{{ __('finance::board.r7.grey') }}</b> {{ __('finance::board.r7.last_month') }}</span>
    </section>

    @if ($flows !== null)
        <section data-fin-flows style="grid-column: span 2; {{ $card }} gap: 0.75rem; border-top: 0.3125rem solid var(--color-dash-f97316)">
            <div style="display: flex; justify-content: space-between; align-items: baseline; gap: 0.5rem">
                <h2 style="margin: 0; font-size: 1rem; font-weight: 700">{{ __('finance::board.r7.flows') }}</h2>
                <span style="font-size: 0.8125rem; font-weight: 800">{{ __('finance::board.r7.flows_total', ['value' => $short($flows['in'])]) }}</span>
            </div>
            @if ($flows['sources'] === [] && $flows['uses'] === [])
                <span style="font-size: 0.8125rem; color: var(--color-dash-6b7280)">{{ __('finance::board.r7.no_flows') }}</span>
            @else
                <span style="font-size: 0.75rem; font-weight: 700; color: var(--color-dash-047857)">{{ __('finance::board.r7.sources') }}</span>
                <div style="display: flex; height: 2.75rem; border-radius: 0.75rem; overflow: hidden; gap: 2px; background: var(--color-dash-f1f2f7)">
                    @foreach ($flows['sources'] as $i => $s)<span title="{{ $s['name'] }} · {{ $short($s['value']) }}" style="flex: {{ round((float) $s['value'] / max(1, (float) $flows['in']) * 100, 2) }}; min-width: 0; background: {{ $srcPaint[$i % count($srcPaint)] }}; color: var(--color-dash-ffffff); font-size: 0.75rem; font-weight: 700; display: flex; align-items: center; padding: 0 0.625rem; white-space: nowrap; overflow: hidden">{{ $s['name'] }} {{ $short($s['value']) }}</span>@endforeach
                </div>
                <svg aria-hidden="true" viewBox="0 0 800 50" preserveAspectRatio="none" style="width: 100%; height: 3.125rem">
                    <path d="M0,0 L800,0 C800,30 0,20 0,50 L800,50 C800,30 0,20 0,0 Z" fill="var(--color-dash-16-185-129-a12)"></path>
                </svg>
                <span style="font-size: 0.75rem; font-weight: 700; color: var(--color-dash-b45309)">{{ __('finance::board.r7.uses') }}</span>
                <div style="display: flex; height: 2.75rem; border-radius: 0.75rem; overflow: hidden; gap: 2px; background: var(--color-dash-f1f2f7)">
                    @foreach ($flows['uses'] as $i => $u)<span title="{{ $u['name'] }} · {{ $short($u['value']) }}" style="flex: {{ round((float) $u['value'] / max(1, (float) $flows['out']) * 100, 2) }}; min-width: 0; background: {{ $usePaint[$i % count($usePaint)] }}; color: var(--color-dash-ffffff); font-size: 0.6875rem; font-weight: 700; display: flex; align-items: center; justify-content: center; white-space: nowrap; overflow: hidden">{{ $short($u['value']) }}</span>@endforeach
                </div>
                <div style="display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 0.375rem 0.75rem; font-size: 0.75rem">
                    @foreach ($flows['uses'] as $i => $u)<span style="display: flex; justify-content: space-between; gap: 0.375rem; min-width: 0"><span style="display: flex; align-items: center; gap: 0.375rem; min-width: 0"><span style="flex: none; width: 0.625rem; height: 0.625rem; border-radius: 3px; background: {{ $usePaint[$i % count($usePaint)] }}"></span><span style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap">{{ $u['name'] }}</span></span><b style="white-space: nowrap">{{ $short($u['value']) }}</b></span>@endforeach
                </div>
            @endif
        </section>
    @endif

    <section data-fin-fund-trend style="grid-column: span {{ $flows === null ? 3 : 1 }}; {{ $card }} gap: 0.5rem; border-top: 0.3125rem solid var(--color-dash-3b82f6)">
        <div style="display: flex; justify-content: space-between; align-items: baseline; gap: 0.5rem">
            <h2 style="margin: 0; font-size: 1rem; font-weight: 700">{{ __('finance::board.r7.trend') }}</h2>
            @if ($trend['peak_now'] && count($points) > 1 && $hi > $lo)<span style="font-size: 0.6875rem; font-weight: 700; color: var(--color-dash-ffffff); background: var(--color-dash-3b82f6); border-radius: 999px; padding: 2px 0.5rem">{{ __('finance::board.r7.peak') }}</span>@endif
        </div>
        <svg role="img" aria-label="{{ __('finance::board.r7.trend_label') }}" viewBox="0 0 360 230" style="width: 100%; height: 14.375rem">
            <defs><linearGradient id="finFundFill" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="var(--color-dash-3b82f6)" stop-opacity="0.5"></stop><stop offset="1" stop-color="var(--color-dash-3b82f6)" stop-opacity="0"></stop></linearGradient></defs>
            @if ($area !== '')<path d="{{ $area }}" fill="url(#finFundFill)"></path>@endif
            <polyline points="{{ $line }}" fill="none" stroke="var(--color-dash-2563eb)" stroke-width="3"></polyline>
            @if ($points !== [])<circle cx="{{ $gx(count($points) - 1) }}" cy="{{ $gy(end($points)) }}" r="6" fill="var(--color-dash-2563eb)" stroke="var(--color-dash-ffffff)" stroke-width="2"><title>{{ $short($trend['points'][count($points) - 1]['total']) }}</title></circle>@endif
            @foreach ($trend['points'] as $i => $p)<text x="{{ $gx($i) }}" y="226" text-anchor="middle" font-size="9" fill="var(--color-dash-6b7280)">{{ $monthShort[\Illuminate\Support\Carbon::parse($p['date'])->month] }}</text>@endforeach
        </svg>
        <span style="font-size: 0.75rem; color: var(--color-dash-4b5563)">{{ __('finance::board.r7.year', ['value' => (bccomp($trend['change'], '0', 4) > 0 ? '+' : '').$short($trend['change']), 'n' => $trend['up_months']]) }}</span>
    </section>
</div>
