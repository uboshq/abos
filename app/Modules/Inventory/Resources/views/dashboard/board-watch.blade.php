{{--
    ⭐ মজুদের ড্যাশবোর্ড, সারি 4 — মেয়াদ শেষের পথে (FEFO), গুদাম × অবস্থা, গণনার নির্ভুলতা ও ঘাটতি
    (মালিকের চূড়ান্ত নমুনা HmzeE5CN2wvtyLPE5exjvV; মালিক, 10 নভেম্বর 2026 রাত: গুদাম × অবস্থা অচল মজুদের পুরনো জায়গায়)।
--}}
@php
    $card = 'background: var(--color-dash-ffffff); border-radius: 1.125rem; padding: 1.125rem 1.25rem; box-shadow: 0 1px 3px var(--color-dash-17-24-39-a06); display: flex; flex-direction: column; color: var(--color-dash-111827)';
    $short = fn (string $v) => \App\Modules\Inventory\Dashboard\InventoryBoard::short($v);
    $hmCols = __('inventory::board.h.cols');
    $shade = function (int $col, int $n): array {
        if ($col === 0) { return ['var(--color-dash-dcfce7)', 'var(--color-dash-14532d)']; }
        if ($n === 0) { return ['var(--color-dash-f8fafc)', 'var(--color-dash-94a3b8)']; }
        $pal = [1 => ['var(--color-dash-fed7aa)', 'var(--color-dash-fb923c)', 'var(--color-dash-ea580c)'], 2 => ['var(--color-dash-fecaca)', 'var(--color-dash-f87171)', 'var(--color-dash-dc2626)'], 3 => ['var(--color-dash-e9d5ff)', 'var(--color-dash-c084fc)', 'var(--color-dash-9333ea)'], 4 => ['var(--color-dash-fecdd3)', 'var(--color-dash-fb7185)', 'var(--color-dash-e11d48)'], 5 => ['var(--color-dash-e2e8f0)', 'var(--color-dash-94a3b8)', 'var(--color-dash-475569)']][$col];
        $k = $n < 3 ? 0 : ($n < 6 ? 1 : 2);
        return [$pal[$k], $k === 0 ? 'var(--color-dash-111827)' : 'var(--color-dash-ffffff)'];
    };
    $maxGap = max(1.0, ...array_map(fn ($c) => abs((float) $c['value']), $counts['counts'] ?: [['value' => '0']]));
@endphp
<div data-inv-board-watch style="display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1rem; width: 100%">
    <section data-inv-expiry style="{{ $card }} gap: 0.5rem; border-top: 0.3125rem solid var(--color-dash-f43f5e)">
        <div style="display: flex; justify-content: space-between; align-items: baseline">
            <h2 style="margin: 0; font-size: 1rem; font-weight: 700">{{ __('inventory::board.e.title') }}</h2>
            <span style="font-size: 0.8125rem; font-weight: 800; color: var(--color-dash-dc2626)">{{ __('inventory::board.e.risk', ['amount' => $expiry['total']]) }}</span>
        </div>
        <svg role="img" aria-label="{{ __('inventory::board.e.aria') }}" viewBox="0 0 360 170" style="width: 100%; height: 10.625rem">
            <rect x="0" y="0" width="120" height="140" fill="var(--color-dash-fef2f2)"></rect><rect x="120" y="0" width="120" height="140" fill="var(--color-dash-fff7ed)"></rect><rect x="240" y="0" width="120" height="140" fill="var(--color-dash-fefce8)"></rect>
            <text x="60" y="160" text-anchor="middle" font-size="11" fill="var(--color-dash-b91c1c)">{{ __('inventory::board.e.b1') }}</text><text x="180" y="160" text-anchor="middle" font-size="11" fill="var(--color-dash-c2410c)">21–40</text><text x="300" y="160" text-anchor="middle" font-size="11" fill="var(--color-dash-a16207)">41–60</text>
            @foreach ($expiry['bubbles'] as $e)<circle cx="{{ $e['x'] }}" cy="{{ $e['y'] }}" r="{{ $e['r'] }}" fill="{{ $e['c'] }}" fill-opacity="0.82" stroke="var(--color-dash-ffffff)" stroke-width="2"></circle>@endforeach
        </svg>
        @forelse ($expiry['rows'] as $e)
            <div style="display: flex; justify-content: space-between; gap: 0.5rem; font-size: 0.8125rem; padding: 0.25rem 0; border-top: 1px solid var(--color-dash-f1f2f7)"><span>{{ $e['name'] }} <span style="color: var(--color-dash-6b7280)">· {{ $e['lot'] }} · {{ $e['warehouse'] }}</span></span><b style="color: {{ $e['tone'] }}; white-space: nowrap">{{ $e['when'] }}</b></div>
        @empty
            <p style="margin: 0; font-size: 0.8125rem; color: var(--color-dash-047857)">{{ __('inventory::board.e.none') }}</p>
        @endforelse
    </section>

    <section data-inv-health style="{{ $card }} gap: 0.5rem; border-top: 0.3125rem solid var(--color-dash-8b5cf6)">
        <h2 style="margin: 0; font-size: 1rem; font-weight: 700">{{ __('inventory::board.h.title') }}</h2>
        <div style="display: grid; grid-template-columns: 4.375rem repeat(6, minmax(0, 1fr)); gap: 0.1875rem; font-size: 0.625rem; text-align: center">
            <span></span>@foreach ($hmCols as $c)<span style="color: var(--color-dash-6b7280); font-weight: 600; line-height: 1.2">{{ $c }}</span>@endforeach
            @foreach ($health as $row)
                <span style="height: 2.125rem; display: flex; align-items: center; justify-content: flex-start; font-size: 0.75rem; font-weight: 700; color: var(--color-dash-374151); padding: 0 0.25rem">{{ $row['name'] }}</span>
                @foreach ($row['counts'] as $i => $n)
                    @php [$bg, $ink] = $shade($i, $n); @endphp
                    <span style="height: 2.125rem; border-radius: 0.4375rem; background: {{ $bg }}; color: {{ $ink }}; display: flex; align-items: center; justify-content: center; font-size: 0.75rem; font-weight: 700">{{ $n }}</span>
                @endforeach
            @endforeach
        </div>
        <span style="font-size: 0.6875rem; color: var(--color-dash-4b5563)">{{ __('inventory::board.h.note') }}</span>
    </section>

    <section data-inv-count style="{{ $card }} gap: 0.5rem; border-top: 0.3125rem solid var(--color-dash-e11d48)">
        <div style="display: flex; justify-content: space-between; align-items: baseline">
            <h2 style="margin: 0; font-size: 1rem; font-weight: 700">{{ __('inventory::board.k.title') }}</h2>
            <span style="font-size: 0.8125rem; font-weight: 800; color: var(--color-dash-059669)">IRA {{ $counts['ira'] === null ? '—' : $counts['ira'].'%' }}</span>
        </div>
        <div style="display: flex; align-items: center; height: 10.625rem; gap: 0.625rem; position: relative">
            <span style="position: absolute; left: 0; right: 0; top: 5.3125rem; height: 1px; background: var(--color-dash-d1d5db)"></span>
            @forelse ($counts['counts'] as $c)
                @php $neg = bccomp($c['value'], '0', 4) < 0; $h = max(0.25, round(abs((float) $c['value']) / $maxGap * 4.5, 3)); @endphp
                <div style="flex: 1 1 0; height: 10.625rem; display: flex; flex-direction: column; justify-content: {{ $neg ? 'flex-start' : 'flex-end' }}; align-items: center">
                    @unless ($neg)<span style="font-size: 0.625rem; font-weight: 700; color: var(--color-dash-22c55e)">+{{ $short($c['value']) }}</span>@endunless
                    <span style="width: 70%; height: {{ $h }}rem; background: {{ $neg ? 'var(--color-dash-ef4444)' : 'var(--color-dash-22c55e)' }}; border-radius: 0.375rem; margin-top: {{ $neg ? '5.3125rem' : '0' }}; margin-bottom: {{ $neg ? '0' : '5.3125rem' }}"></span>
                    @if ($neg)<span style="font-size: 0.625rem; font-weight: 700; color: var(--color-dash-ef4444)">{{ $short($c['value']) }}</span>@endif
                </div>
            @empty
                <p style="margin: 0; font-size: 0.8125rem; color: var(--color-dash-6b7280)">{{ __('inventory::board.k.none') }}</p>
            @endforelse
        </div>
        <div style="display: flex; gap: 0.625rem; font-size: 0.6875rem; color: var(--color-dash-6b7280)">@foreach ($counts['counts'] as $c)<span style="flex: 1 1 0; text-align: center">{{ $c['label'] }}</span>@endforeach</div>
        <span style="font-size: 0.75rem; color: var(--color-dash-4b5563)">{{ __('inventory::board.k.note', ['amount' => $short($counts['short']), 'share' => $counts['share']]) }}</span>
    </section>
</div>
