{{--
    ⭐ বিক্রয়ের ড্যাশবোর্ড, সারি 6 — SR-দের লক্ষ্য (বুলেট), SR কমিশন, সেরা দুই SR (রাডার), ডিস্ট্রিবিউটর — আকার বনাম বৃদ্ধি (বাবল)
    (মালিকের চূড়ান্ত নমুনা H8JXFPzh8m7zpsQSedmPDT)। অঙ্ক [[SalesBoard]] থেকে।
--}}
@php
    $card = 'background: var(--color-dash-ffffff); border-radius: 1.125rem; padding: 1.125rem 1.25rem; box-shadow: 0 1px 3px var(--color-dash-17-24-39-a06); display: flex; flex-direction: column; color: var(--color-dash-111827)';
    $short = fn (string $v) => \App\Modules\Sales\Dashboard\SalesBoard::short($v);
    $tone = fn (int $p, int $due) => $p >= $due ? 'var(--color-dash-10b981)' : ($p >= $due * 0.8 ? 'var(--color-dash-0ea5e9)' : ($p >= $due * 0.6 ? 'var(--color-dash-f59e0b)' : 'var(--color-dash-ef4444)'));
    $medal = ['var(--color-dash-f59e0b)', 'var(--color-dash-94a3b8)', 'var(--color-dash-b45309)'];

    $pt = function (int $i, float $f): array { $a = -M_PI / 2 + $i * 2 * M_PI / 6; return [round(160 + cos($a) * 66 * $f, 1), round(118 + sin($a) * 66 * $f, 1)]; };
    $poly = fn (array $fs) => implode(' ', array_map(fn ($f, $i) => implode(',', $pt($i, $f)), $fs, array_keys($fs)));
    $radarPaint = [['var(--color-dash-0ea5e9)', 'var(--color-dash-14-165-233-a25)'], ['var(--color-dash-d946ef)', 'var(--color-dash-217-70-239-a20)']];
    $labels = [];
    foreach (__('sales::board.r6.axes') as $i => $t) { [$x, $y] = $pt($i, 1.18); $labels[] = ['t' => $t, 'x' => $x, 'y' => $y + ($i === 0 ? -2 : ($i === 3 ? 10 : 4)), 'a' => abs($x - 160) < 5 ? 'middle' : ($x > 160 ? 'start' : 'end')]; }

    $maxSales = max(1.0, ...array_map(fn ($d) => (float) $d['sales'], $dealers ?: [['sales' => '1']]));
    $maxDue = max(1.0, ...array_map(fn ($d) => (float) $d['due'], $dealers ?: [['due' => '1']]));
    $gr = array_filter(array_map(fn ($d) => $d['growth'], $dealers), fn ($g) => $g !== null);
    $gMax = max(20, ...array_map('abs', $gr ?: [0]));
    $dots = array_map(fn ($d) => [
        'x' => round(30 + (float) $d['sales'] / $maxSales * 300, 1),
        'y' => round(100 - max(-1, min(1, ($d['growth'] ?? 0) / $gMax)) * 82, 1),
        'r' => round(6 + 12 * sqrt((float) $d['due'] / $maxDue), 1),
        'c' => $d['growth'] === null ? 'var(--color-dash-94a3b8)' : ($d['growth'] >= 0 ? 'var(--color-dash-10b981)' : 'var(--color-dash-ef4444)'),
        'n' => $d['name'],
    ], $dealers);
@endphp
<div data-sales-board-team style="display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 1rem; width: 100%">
    <section data-sales-sr-targets style="{{ $card }} gap: 0.5rem; border-top: 0.25rem solid var(--color-dash-10b981)">
        <h2 style="margin: 0; font-size: 1rem; font-weight: 700">{{ __('sales::board.r6.targets') }}</h2>
        @forelse ($srTargets['rows'] as $i => $s)
            @php $c = $tone($s['pct'], $srTargets['due']); @endphp
            <div data-sr-target style="display: grid; grid-template-columns: 1.375rem minmax(0, 1fr) 2.75rem; gap: 0.5rem; align-items: center; font-size: 0.75rem">
                <span style="width: 1.25rem; height: 1.25rem; border-radius: 999px; background: {{ $medal[$i] ?? 'var(--color-dash-64748b)' }}; color: var(--color-dash-ffffff); font-size: 0.625rem; font-weight: 800; display: flex; align-items: center; justify-content: center">{{ $i + 1 }}</span>
                <span style="display: flex; flex-direction: column; gap: 2px; min-width: 0"><span style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap">{{ $s['name'] }}</span><span style="position: relative; display: block; height: 0.5rem; border-radius: 999px; background: var(--color-dash-f1f2f7)"><span style="position: absolute; left: 0; top: 0; height: 0.5rem; width: {{ min(100, $s['pct']) }}%; background: {{ $c }}; border-radius: 999px"></span><span style="position: absolute; left: {{ $srTargets['due'] }}%; top: -2px; width: 2px; height: 0.75rem; background: var(--color-dash-111827)"></span></span></span>
                <b style="text-align: right; color: {{ $c }}">{{ $s['pct'] }}%</b>
            </div>
        @empty
            <span style="font-size: 0.8125rem; color: var(--color-dash-6b7280)">{{ __('sales::board.r6.targets_none') }}</span>
        @endforelse
        @if ($srTargets['rows'] !== [])<span style="font-size: 0.6875rem; color: var(--color-dash-6b7280)">{{ __('sales::board.r6.targets_note', ['n' => $srTargets['due']]) }}</span>@endif
    </section>

    <section data-sales-sr-commission style="{{ $card }} gap: 0.375rem; border-top: 0.25rem solid var(--color-dash-ec4899)">
        <h2 style="margin: 0; font-size: 1rem; font-weight: 700">{{ __('sales::board.r6.commission') }}</h2>
        @if ($commission['rows'] === [])
            <span style="font-size: 0.8125rem; color: var(--color-dash-6b7280)">{{ __('sales::board.r6.commission_none') }}</span>
        @else
            <div style="display: grid; grid-template-columns: minmax(0, 1fr) auto auto auto; gap: 0.25rem 0.625rem; font-size: 0.75rem; text-align: right">
                <span style="text-align: left; font-size: 0.6875rem; color: var(--color-dash-6b7280)">{{ __('sales::board.r6.sr') }}</span><span style="font-size: 0.6875rem; color: var(--color-dash-6b7280)">{{ __('sales::board.r6.sales') }}</span><span style="font-size: 0.6875rem; color: var(--color-dash-6b7280)">{{ __('sales::board.r6.rate') }}</span><span style="font-size: 0.6875rem; color: var(--color-dash-6b7280)">{{ __('sales::board.r6.amount') }}</span>
                @foreach ($commission['rows'] as $r)
                    <span style="text-align: left; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; padding: 0.25rem 0; border-top: 1px solid var(--color-dash-f1f2f7)">{{ $r['name'] }}</span><span style="padding: 0.25rem 0; border-top: 1px solid var(--color-dash-f1f2f7)">{{ $short($r['sales']) }}</span><span style="padding: 0.25rem 0; border-top: 1px solid var(--color-dash-f1f2f7)">{{ $r['rate'] === null ? '—' : $r['rate'].'%' }}</span><b style="padding: 0.25rem 0; border-top: 1px solid var(--color-dash-f1f2f7)">{{ $short($r['commission']) }}</b>
                @endforeach
                <b style="text-align: left; color: var(--color-dash-be185d); padding-top: 0.25rem; border-top: 2px solid var(--color-dash-f1f2f7)">{{ __('sales::board.r6.total') }}</b><b style="color: var(--color-dash-be185d); padding-top: 0.25rem; border-top: 2px solid var(--color-dash-f1f2f7)">{{ $short($commission['sales']) }}</b><span style="border-top: 2px solid var(--color-dash-f1f2f7)"></span><b style="color: var(--color-dash-be185d); padding-top: 0.25rem; border-top: 2px solid var(--color-dash-f1f2f7)">{{ $short($commission['commission']) }}</b>
            </div>
            <span style="font-size: 0.6875rem; color: var(--color-dash-4b5563)">{{ __('sales::board.r6.commission_note') }}</span>
        @endif
    </section>

    <section data-sales-top-two style="{{ $card }} gap: 0.375rem; border-top: 0.25rem solid var(--color-dash-0ea5e9)">
        <h2 style="margin: 0; font-size: 1rem; font-weight: 700">{{ __('sales::board.r6.radar') }}</h2>
        @if ($topTwo === [])
            <span style="font-size: 0.8125rem; color: var(--color-dash-6b7280)">{{ __('sales::board.r6.radar_none') }}</span>
        @else
            <svg role="img" aria-label="{{ __('sales::board.r6.radar_label') }}" viewBox="0 0 320 240" style="width: 100%; height: 15rem">
                @foreach ([1 / 3, 2 / 3, 1] as $fr)<polygon points="{{ $poly(array_fill(0, 6, $fr)) }}" fill="none" stroke="var(--color-dash-e5e7f0)"></polygon>@endforeach
                @foreach ($topTwo as $i => $sr)<polygon points="{{ $poly(array_map(fn ($v) => max(0.04, $v / 100), $sr['values'])) }}" fill="{{ $radarPaint[$i][1] }}" stroke="{{ $radarPaint[$i][0] }}" stroke-width="3"></polygon>@endforeach
                @foreach ($labels as $l)<text x="{{ $l['x'] }}" y="{{ $l['y'] }}" text-anchor="{{ $l['a'] }}" font-size="10" fill="var(--color-dash-374151)">{{ $l['t'] }}</text>@endforeach
            </svg>
            <span style="font-size: 0.75rem; color: var(--color-dash-4b5563)">@foreach ($topTwo as $i => $sr)<b style="color: {{ $radarPaint[$i][0] }}">■</b> {{ $sr['name'] }} &nbsp; @endforeach</span>
        @endif
    </section>

    <section data-sales-dealers style="{{ $card }} gap: 0.375rem; border-top: 0.25rem solid var(--color-dash-8b5cf6)">
        <h2 style="margin: 0; font-size: 1rem; font-weight: 700">{{ __('sales::board.r6.dealers') }}</h2>
        @if ($dots === [])
            <span style="font-size: 0.8125rem; color: var(--color-dash-6b7280)">{{ __('sales::board.r6.dealers_none') }}</span>
        @else
            <svg role="img" aria-label="{{ __('sales::board.r6.dealers_label') }}" viewBox="0 0 360 220" style="width: 100%; height: 13.75rem">
                <line x1="24" y1="100" x2="356" y2="100" stroke="var(--color-dash-94a3b8)" stroke-dasharray="4 4"></line>
                <line x1="24" y1="10" x2="24" y2="200" stroke="var(--color-dash-e5e7f0)"></line>
                @foreach ($dots as $d)<circle cx="{{ $d['x'] }}" cy="{{ $d['y'] }}" r="{{ $d['r'] }}" fill="{{ $d['c'] }}" fill-opacity="0.75" stroke="var(--color-dash-ffffff)" stroke-width="2"><title>{{ $d['n'] }}</title></circle>@endforeach
                <text x="352" y="214" text-anchor="end" font-size="10" fill="var(--color-dash-6b7280)">{{ __('sales::board.r6.size') }}</text>
                <text x="12" y="105" font-size="10" fill="var(--color-dash-6b7280)" transform="rotate(-90 12 105)">{{ __('sales::board.r6.growth') }}</text>
            </svg>
            <span style="font-size: 0.6875rem; color: var(--color-dash-4b5563)">{{ __('sales::board.r6.dealers_note') }}</span>
        @endif
    </section>
</div>
