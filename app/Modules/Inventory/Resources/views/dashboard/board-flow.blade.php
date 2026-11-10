{{--
    ⭐ মজুদের ড্যাশবোর্ড, সারি 3 — মজুদের হিসাব-মিলান (সেতু, দুই ভাগ চওড়া), কত দিনের মজুদ (হিস্টোগ্রাম), ABC-XYZ ছক
    (মালিকের চূড়ান্ত নমুনা HmzeE5CN2wvtyLPE5exjvV)।
    ⓘ সেতুর বার একটা ভিত্তি থেকে মাপা (নমুনার "বারগুলো 5 কোটি থেকে মাপা") — ভিত্তি = শুরু আর আজের ছোটটার 60%, তাই ছোট ধাপও চোখে পড়ে।
--}}
@php

    $card = 'background: var(--color-dash-ffffff); border-radius: 1.125rem; padding: 1.125rem 1.25rem; box-shadow: 0 1px 3px var(--color-dash-17-24-39-a06); display: flex; flex-direction: column; color: var(--color-dash-111827)';
    $steps = collect($roll['steps'])->keyBy('key');
    $open = $steps['open']['value'];
    $close = $steps['close']['value'];
    $base = bcmul(bccomp($open, $close, 4) < 0 ? $open : $close, '0.6', 4);
    $base = bccomp($base, '0', 4) > 0 ? $base : '0';
    $top = '0';
    $run = $open;
    foreach ($roll['steps'] as $s) { if ($s['key'] !== 'open' && $s['key'] !== 'close') { $run = bcadd($run, $s['value'], 4); } $top = bccomp($run, $top, 4) > 0 ? $run : $top; }
    $top = bccomp($open, $top, 4) > 0 ? $open : $top;
    $span = bcsub($top, $base, 4);
    $rem = fn (string $v) => bccomp($span, '0', 4) > 0 ? max(0.1875, round((float) bcdiv(bcmul($v, '16', 4), $span, 4), 3)) : 0.1875;
    $paint = [
        'open' => ['linear-gradient(180deg, var(--color-dash-cbd5e1), var(--color-dash-64748b))', 'var(--color-dash-475569)'],
        'in' => ['linear-gradient(180deg, var(--color-dash-86efac), var(--color-dash-16a34a))', 'var(--color-dash-15803d)'],
        'returned' => ['var(--color-dash-14b8a6)', 'var(--color-dash-0f766e)'],
        'sold' => ['linear-gradient(180deg, var(--color-dash-fdba74), var(--color-dash-ea580c))', 'var(--color-dash-c2410c)'],
        'damage' => ['var(--color-dash-e11d48)', 'var(--color-dash-be123c)'],
        'counted' => ['var(--color-dash-a855f7)', 'var(--color-dash-7e22ce)'],
        'close' => ['linear-gradient(180deg, var(--color-dash-7dd3fc), var(--color-dash-0369a1))', 'var(--color-dash-075985)'],
    ];
    $bars = [];
    $level = $open;
    foreach ($roll['steps'] as $s) {
        $v = $s['value'];
        if ($s['key'] === 'open' || $s['key'] === 'close') {
            $bars[] = ['key' => $s['key'], 'label' => bcdiv($v, '10000000', 2), 'h' => $rem(bcsub($v, $base, 4)), 'mb' => 0];
            continue;
        }
        $neg = str_starts_with($v, '-');
        $abs = ltrim($v, '-');
        $low = $neg ? bcsub($level, $abs, 4) : $level;
        $bars[] = ['key' => $s['key'], 'label' => bccomp($abs, '0', 4) === 0 ? '0' : ($neg ? '−' : '+').(bccomp($abs, '100000', 4) < 0 ? bcdiv($abs, '10000000', 3) : bcdiv($abs, '10000000', 2)), 'h' => $rem($abs), 'mb' => $rem(bcsub($low, $base, 4))];
        $level = $neg ? $low : bcadd($level, $abs, 4);
    }
    $maxN = max(1, max(array_column($cover['buckets'], 'n')));
    $coverPaint = ['var(--color-dash-dc2626)', 'var(--color-dash-f97316)', 'var(--color-dash-facc15)', 'var(--color-dash-22c55e)', 'var(--color-dash-16a34a)', 'var(--color-dash-c084fc)', 'var(--color-dash-7c3aed)'];
    $abcPaint = ['A' => ['var(--color-dash-7c3aed)', 'var(--color-dash-8b5cf6)', 'var(--color-dash-a78bfa)'], 'B' => ['var(--color-dash-0284c7)', 'var(--color-dash-38bdf8)', 'var(--color-dash-7dd3fc)'], 'C' => ['var(--color-dash-94a3b8)', 'var(--color-dash-cbd5e1)', 'var(--color-dash-e2e8f0)']];
@endphp
<div data-inv-board-flow style="display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 1rem; width: 100%">
    <section data-inv-roll style="grid-column: span 2; {{ $card }} gap: 0.5rem; border-top: 0.3125rem solid var(--color-dash-0ea5e9)">
        <div style="display: flex; justify-content: space-between; align-items: baseline">
            <h2 style="margin: 0; font-size: 1.0625rem; font-weight: 800">{{ __('inventory::board.f.title') }}</h2>
            <span style="font-size: 0.75rem; color: var(--color-dash-6b7280)">{{ __('inventory::board.f.base', ['base' => bcdiv($base, '10000000', 2)]) }}</span>
        </div>
        <div style="display: flex; align-items: flex-end; gap: 0.875rem; height: 18.75rem; border-bottom: 1px solid var(--color-dash-e5e7f0); padding: 0 0.375rem">
            @foreach ($bars as $b)
                <div data-inv-roll-step="{{ $b['key'] }}" style="flex: 1 1 0; height: 18.75rem; display: flex; flex-direction: column; justify-content: flex-end; align-items: center; gap: 0.1875rem">
                    <span style="font-size: 0.8125rem; font-weight: 800; color: {{ $paint[$b['key']][1] }}">{{ $b['label'] }}</span>
                    <span style="width: 100%; height: {{ $b['h'] }}rem; margin-bottom: {{ $b['mb'] }}rem; background: {{ $paint[$b['key']][0] }}; border-radius: 0.625rem"></span>
                </div>
            @endforeach
        </div>
        <div style="display: flex; gap: 0.875rem; padding: 0 0.375rem; font-size: 0.75rem; font-weight: 600; color: var(--color-dash-374151)">
            @foreach ($bars as $b)<span style="flex: 1 1 0; text-align: center">{{ __('inventory::board.f.'.$b['key']) }}</span>@endforeach
        </div>
        <span style="font-size: 0.75rem; color: var(--color-dash-4b5563)">{{ __('inventory::board.f.rule') }} · @if ($roll['matches'] === true){{ __('inventory::board.f.matches') }}@elseif ($roll['matches'] === false)<b style="color: var(--color-dash-dc2626)">{{ __('inventory::board.f.gap', ['amount' => AppModulesInventoryDashboardInventoryBoard::short($roll['gap'])]) }}</b>@else{{ __('inventory::board.f.filtered') }}@endif</span>
    </section>

    <section data-inv-cover style="{{ $card }} gap: 0.5rem; border-top: 0.3125rem solid var(--color-dash-14b8a6)">
        <h2 style="margin: 0; font-size: 1rem; font-weight: 700">{{ __('inventory::board.c.title') }}</h2>
        <div style="display: flex; align-items: flex-end; gap: 0.5rem; height: 14.375rem; border-bottom: 1px solid var(--color-dash-e5e7f0); padding: 0 0.25rem">
            @foreach ($cover['buckets'] as $i => $c)
                <div style="flex: 1 1 0; display: flex; flex-direction: column; align-items: center; gap: 0.1875rem"><span style="font-size: 0.75rem; font-weight: 800; color: {{ $coverPaint[$i] }}">{{ $c['n'] }}</span><span style="width: 100%; height: {{ max(0.25, round($c['n'] / $maxN * 12.5, 3)) }}rem; background: {{ $coverPaint[$i] }}; border-radius: 0.5rem 0.5rem 0 0"></span></div>
            @endforeach
        </div>
        <div style="display: flex; gap: 0.5rem; padding: 0 0.25rem; font-size: 0.625rem; color: var(--color-dash-6b7280)">
            @foreach ($cover['buckets'] as $c)<span style="flex: 1 1 0; text-align: center">{{ __('inventory::board.days', ['n' => $c['label']]) }}</span>@endforeach
        </div>
        <span style="font-size: 0.75rem; color: var(--color-dash-4b5563)">{{ __('inventory::board.c.note', ['total' => $cover['total'], 'short' => $cover['short'], 'long' => $cover['long']]) }}</span>
    </section>

    <section data-inv-abc style="{{ $card }} gap: 0.5rem; border-top: 0.3125rem solid var(--color-dash-7c3aed)">
        <h2 style="margin: 0; font-size: 1rem; font-weight: 700">{{ __('inventory::board.x.title') }}</h2>
        <span style="font-size: 0.6875rem; color: var(--color-dash-6b7280)">{{ __('inventory::board.x.sub') }}</span>
        <div style="display: grid; grid-template-columns: 3.125rem repeat(3, minmax(0, 1fr)); gap: 0.3125rem; text-align: center; font-size: 0.6875rem">
            <span></span><span style="color: var(--color-dash-6b7280); font-weight: 700">{{ __('inventory::board.x.x') }}</span><span style="color: var(--color-dash-6b7280); font-weight: 700">{{ __('inventory::board.x.y') }}</span><span style="color: var(--color-dash-6b7280); font-weight: 700">{{ __('inventory::board.x.z') }}</span>
            @foreach (['A', 'B', 'C'] as $a)
                <span style="height: 3.375rem; display: flex; align-items: center; justify-content: center; font-size: 1.125rem; font-weight: 700">{{ $a }}</span>
                @foreach (['X', 'Y', 'Z'] as $i => $x)
                    @php $cell = $abc[$a.$x]; @endphp
                    <span data-inv-abc-cell="{{ $a.$x }}" style="height: 3.375rem; border-radius: 0.625rem; background: {{ $abcPaint[$a][$i] }}; color: {{ $a === 'C' && $i > 0 ? 'var(--color-dash-111827)' : 'var(--color-dash-ffffff)' }}; display: flex; flex-direction: column; align-items: center; justify-content: center; font-weight: 700"><b style="font-size: 0.8125rem">{{ $a.$x }} · {{ $cell['n'] }}</b><span style="font-size: 0.625rem">{{ __('inventory::board.x.share', ['p' => $cell['share']]) }}</span></span>
                @endforeach
            @endforeach
        </div>
        <span style="font-size: 0.6875rem; color: var(--color-dash-4b5563)">{{ __('inventory::board.x.rule') }}</span>
    </section>
</div>
