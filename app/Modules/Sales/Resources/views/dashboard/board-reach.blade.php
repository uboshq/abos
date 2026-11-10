{{--
    ⭐ বিক্রয়ের ড্যাশবোর্ড, সারি 7 — শাখা ধরে (স্তূপ বার, 5 মাস + এ মাস), শীর্ষ গ্রাহক (পারেটো), এলাকা ধরে (টাইল)
    (মালিকের চূড়ান্ত নমুনা H8JXFPzh8m7zpsQSedmPDT)। অঙ্ক [[SalesBoard::spread()]] থেকে।
--}}
@php
    $card = 'background: var(--color-dash-ffffff); border-radius: 1.125rem; padding: 1.125rem 1.25rem; box-shadow: 0 1px 3px var(--color-dash-17-24-39-a06); display: flex; flex-direction: column; color: var(--color-dash-111827)';
    $short = fn (string $v) => \App\Modules\Sales\Dashboard\SalesBoard::short($v);
    $monthShort = __('sales::board.months_short');
    $branchPaint = ['var(--color-dash-3b82f6)', 'var(--color-dash-10b981)', 'var(--color-dash-f59e0b)', 'var(--color-dash-8b5cf6)', 'var(--color-dash-ec4899)', 'var(--color-dash-64748b)'];
    $names = $spread['names'];
    $peak = max(1.0, ...array_map(fn ($m) => array_sum(array_map('floatval', $m['parts'])), $spread['branches']));
    $pareto = $spread['pareto'];
    $pMax = max(1.0, ...array_map(fn ($p) => (float) $p['value'], $pareto ?: [['value' => '1']]));
    $areas = $spread['areas'];
    $aMax = max(1.0, ...array_map(fn ($a) => (float) $a['value'], $areas ?: [['value' => '1']]));
    $tile = fn (float $v) => $v <= 0 ? ['var(--color-dash-f1f2f7)', 'var(--color-dash-14532d)'] : ($v / $aMax < 0.2 ? ['var(--color-dash-dcfce7)', 'var(--color-dash-14532d)'] : ($v / $aMax < 0.4 ? ['var(--color-dash-86efac)', 'var(--color-dash-14532d)'] : ($v / $aMax < 0.7 ? ['var(--color-dash-4ade80)', 'var(--color-dash-14532d)'] : ($v / $aMax < 0.9 ? ['var(--color-dash-16a34a)', 'var(--color-dash-ffffff)'] : ['var(--color-dash-14532d)', 'var(--color-dash-ffffff)']))));
@endphp
<div data-sales-board-reach style="display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1rem; width: 100%">
    <section data-sales-branches style="{{ $card }} gap: 0.5rem; border-top: 0.25rem solid var(--color-dash-3b82f6)">
        <h2 style="margin: 0; font-size: 1rem; font-weight: 700">{{ __('sales::board.r7.branches') }}</h2>
        <div style="display: flex; align-items: flex-end; gap: 0.875rem; height: 13.75rem; border-bottom: 1px solid var(--color-dash-e5e7f0); padding: 0 0.375rem">
            @foreach ($spread['branches'] as $m)
                <div data-branch-month title="{{ $short((string) array_sum(array_map('floatval', $m['parts']))) }}" style="flex: 1 1 0; display: flex; flex-direction: column-reverse; gap: 1px">
                    @foreach ($names as $i => $n)
                        @php $h = round((float) ($m['parts'][$n] ?? 0) / $peak * 200, 1); @endphp
                        @if ($h > 0)<span style="height: {{ $h / 16 }}rem; background: {{ $branchPaint[$i % count($branchPaint)] }}; border-radius: 3px"></span>@endif
                    @endforeach
                </div>
            @endforeach
        </div>
        <div style="display: flex; gap: 0.875rem; padding: 0 0.375rem; font-size: 0.6875rem; color: var(--color-dash-6b7280)">@foreach ($spread['branches'] as $i => $m)<span style="flex: 1 1 0; text-align: center">{{ $i === count($spread['branches']) - 1 ? __('sales::board.r7.this_month') : $monthShort[$m['month']] }}</span>@endforeach</div>
        <span style="font-size: 0.75rem; color: var(--color-dash-4b5563)">@foreach ($names as $i => $n)<b style="color: {{ $branchPaint[$i % count($branchPaint)] }}">■</b> {{ $n }} @endforeach</span>
    </section>

    <section data-sales-pareto style="{{ $card }} gap: 0.5rem; border-top: 0.25rem solid var(--color-dash-f97316)">
        <h2 style="margin: 0; font-size: 1rem; font-weight: 700">{{ __('sales::board.r7.pareto') }}</h2>
        @if ($pareto === [])
            <span style="font-size: 0.8125rem; color: var(--color-dash-6b7280)">{{ __('sales::board.r7.none') }}</span>
        @else
            <svg role="img" aria-label="{{ __('sales::board.r7.pareto_label') }}" viewBox="0 0 360 220" style="width: 100%; height: 13.75rem">
                <line x1="0" y1="200" x2="360" y2="200" stroke="var(--color-dash-e5e7f0)"></line>
                @foreach ($pareto as $i => $p)
                    @php $h = round((float) $p['value'] / $pMax * 170, 1); @endphp
                    <rect x="{{ 8 + $i * 29 }}" y="{{ 200 - $h }}" width="22" height="{{ max(2, $h) }}" rx="4" fill="{{ $i < 3 ? 'var(--color-dash-f97316)' : 'var(--color-dash-fdba74)' }}"><title>{{ $p['name'] }} · {{ $short($p['value']) }}</title></rect>
                @endforeach
                <polyline points="{{ implode(' ', array_map(fn ($p, $i) => (19 + $i * 29).','.(200 - $p['cum'] * 1.8), $pareto, array_keys($pareto))) }}" fill="none" stroke="var(--color-dash-7c3aed)" stroke-width="2.5"></polyline>
            </svg>
            <span style="font-size: 0.75rem; color: var(--color-dash-4b5563)">{{ __('sales::board.r7.pareto_note', ['n' => count($pareto), 'pct' => end($pareto)['cum'], 'one' => $pareto[0]['cum']]) }}</span>
        @endif
    </section>

    <section data-sales-areas style="{{ $card }} gap: 0.5rem; border-top: 0.25rem solid var(--color-dash-16a34a)">
        <h2 style="margin: 0; font-size: 1rem; font-weight: 700">{{ __('sales::board.r7.areas') }}</h2>
        @if ($areas === [])
            <span style="font-size: 0.8125rem; color: var(--color-dash-6b7280)">{{ __('sales::board.r7.none') }}</span>
        @else
            <div style="display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 0.375rem">
                @foreach ($areas as $a)
                    @php [$bg, $ink] = $tile((float) $a['value']); @endphp
                    <span data-area-tile style="min-height: 3.5rem; border-radius: 0.625rem; background: {{ $bg }}; color: {{ $ink }}; display: flex; flex-direction: column; justify-content: center; padding: 0.375rem 0.5rem; font-size: 0.75rem; overflow: hidden"><b style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap">{{ $a['name'] }}</b><span>{{ $short($a['value']) }}</span></span>
                @endforeach
            </div>
            <span style="font-size: 0.75rem; color: var(--color-dash-4b5563)">{{ __('sales::board.r7.areas_note') }}</span>
        @endif
    </section>
</div>
