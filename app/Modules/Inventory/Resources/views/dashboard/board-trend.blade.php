{{--
    ⭐ মজুদের ড্যাশবোর্ড, সারি 5 — মজুদের দাম ও মজুদে কত দিন · 12 মাস (দুই ভাগ চওড়া), গুদাম ধরে মজুদ (ডোনাট), ব্র্যান্ড ধরে মজুদ (ট্রিম্যাপ)
    (মালিকের চূড়ান্ত নমুনা HmzeE5CN2wvtyLPE5exjvV)। ⓘ বার এক ভিত্তি থেকে মাপা (সবচেয়ে ছোট মাসের 60%) — নমুনার মতো ওঠানামা চোখে পড়ে।
--}}
@php
    $card = 'background: var(--color-dash-ffffff); border-radius: 1.125rem; padding: 1.125rem 1.25rem; box-shadow: 0 1px 3px var(--color-dash-17-24-39-a06); display: flex; flex-direction: column; color: var(--color-dash-111827)';
    $short = fn (string $v) => \App\Modules\Inventory\Dashboard\InventoryBoard::short($v);
    $bars = [];
    $dioLine = '';
    $lastDio = null;
    if ($trend !== null && $trend !== []) {
        $vals = array_map(fn ($t) => (float) $t['value'], $trend);
        $lo = max(0.0, min($vals) * 0.6);
        $hi = max(max($vals), $lo + 1);
        $dios = array_values(array_filter(array_map(fn ($t) => $t['dio'], $trend), fn ($d) => $d !== null));
        $dlo = $dios === [] ? 0 : min($dios);
        $dhi = $dios === [] ? 1 : max(max($dios), $dlo + 1);
        $pts = [];
        foreach ($trend as $i => $t) {
            $x = 22 + $i * 62;
            $h = round(((float) $t['value'] - $lo) / ($hi - $lo) * 150);
            $bars[] = ['x' => $x, 'xc' => $x + 19, 'h' => max(2, $h), 'y' => 176 - max(2, $h), 'ty' => 170 - max(2, $h), 'v' => bcdiv($t['value'], '10000000', 2), 'm' => $t['month']];
            if ($t['dio'] !== null) { $pts[] = ($x + 19).','.round(266 - ($t['dio'] - $dlo) / ($dhi - $dlo) * 56); $lastDio = $t['dio']; }
        }
        $dioLine = implode(' ', $pts);
    }
    $whPaint = ['var(--color-dash-6366f1)', 'var(--color-dash-0ea5e9)', 'var(--color-dash-10b981)', 'var(--color-dash-f59e0b)', 'var(--color-dash-8b5cf6)', 'var(--color-dash-14b8a6)', 'var(--color-dash-ec4899)'];
    $whTotal = array_reduce($warehouses, fn (string $c, array $w) => bcadd($c, $w['value'], 4), '0');
    $ring = [];
    $acc = 0.0;
    $C = 2 * M_PI * 60;
    foreach ($warehouses as $i => $w) {
        $len = bccomp($whTotal, '0', 4) > 0 ? (float) $w['value'] / (float) $whTotal * $C : 0;
        $ring[] = ['dash' => round(max($len - 2, 0), 1).' '.round($C - $len + 2, 1), 'off' => round(-$acc, 1), 'c' => $whPaint[$i % count($whPaint)], 'name' => $w['name'], 'value' => $short($w['value'])];
        $acc += $len;
    }
    $brandPaint = [['var(--color-dash-10b981)', 'var(--color-dash-059669)'], ['var(--color-dash-6366f1)', 'var(--color-dash-4f46e5)'], ['var(--color-dash-f59e0b)', 'var(--color-dash-d97706)'], ['var(--color-dash-0ea5e9)', 'var(--color-dash-0284c7)'], ['var(--color-dash-ec4899)', 'var(--color-dash-db2777)'], ['var(--color-dash-8b5cf6)', 'var(--color-dash-7c3aed)'], ['var(--color-dash-64748b)', 'var(--color-dash-94a3b8)']];
@endphp
<div data-inv-board-trend style="display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 1rem; width: 100%">
    <section data-inv-trend style="grid-column: span 2; {{ $card }} gap: 0.375rem; border-top: 0.3125rem solid var(--color-dash-3b82f6)">
        <div style="display: flex; justify-content: space-between; align-items: baseline">
            <h2 style="margin: 0; font-size: 1rem; font-weight: 700">{{ __('inventory::board.g.title') }}</h2>
            <span style="font-size: 0.75rem; color: var(--color-dash-4b5563)"><b style="color: var(--color-dash-2563eb)">■</b> {{ __('inventory::board.g.value') }} &nbsp; <b style="color: var(--color-dash-db2777)">—</b> {{ __('inventory::board.g.dio') }}</span>
        </div>
        @if ($bars === [])
            <p style="margin: 0; font-size: 0.8125rem; color: var(--color-dash-6b7280)">{{ __('inventory::board.v.no_cost') }}</p>
        @else
            <svg role="img" aria-label="{{ __('inventory::board.g.aria') }}" viewBox="0 0 760 290" style="width: 100%; height: 18.125rem">
                <defs><linearGradient id="invStockBar" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="var(--color-dash-60a5fa)"></stop><stop offset="1" stop-color="var(--color-dash-1d4ed8)"></stop></linearGradient></defs>
                @foreach ($bars as $b)
                    <rect x="{{ $b['x'] }}" y="{{ $b['y'] }}" width="38" height="{{ $b['h'] }}" rx="6" fill="url(#invStockBar)"></rect>
                    <text x="{{ $b['xc'] }}" y="{{ $b['ty'] }}" text-anchor="middle" font-size="10" font-weight="700" fill="var(--color-dash-1e3a8a)">{{ $b['v'] }}</text>
                    <text x="{{ $b['xc'] }}" y="286" text-anchor="middle" font-size="10" fill="var(--color-dash-6b7280)">{{ $b['m'] }}</text>
                @endforeach
                <line x1="0" y1="186" x2="760" y2="186" stroke="var(--color-dash-e5e7f0)"></line>
                <text x="4" y="202" font-size="10" fill="var(--color-dash-6b7280)">{{ __('inventory::board.g.dio_short') }}</text>
                @if ($dioLine !== '')<polyline points="{{ $dioLine }}" fill="none" stroke="var(--color-dash-db2777)" stroke-width="3"></polyline>@endif
                @if ($lastDio !== null)<text x="752" y="250" text-anchor="end" font-size="11" font-weight="700" fill="var(--color-dash-be185d)">{{ __('inventory::board.days', ['n' => $lastDio]) }}</text>@endif
            </svg>
        @endif
    </section>

    <section data-inv-wh style="{{ $card }} gap: 0.5rem; border-top: 0.3125rem solid var(--color-dash-6366f1)">
        <h2 style="margin: 0; font-size: 1rem; font-weight: 700">{{ __('inventory::board.w.title') }}</h2>
        <div style="display: flex; align-items: center; gap: 0.75rem">
            <svg role="img" aria-label="{{ __('inventory::board.w.title') }}" viewBox="0 0 170 170" style="width: 10rem; height: 10rem; flex-shrink: 0">
                <g transform="rotate(-90 85 85)">@foreach ($ring as $d)<circle cx="85" cy="85" r="60" fill="none" stroke="{{ $d['c'] }}" stroke-width="26" stroke-dasharray="{{ $d['dash'] }}" stroke-dashoffset="{{ $d['off'] }}"></circle>@endforeach</g>
                <text x="85" y="82" text-anchor="middle" font-size="18" font-weight="800" fill="var(--color-dash-111827)">{{ $short($whTotal) }}</text>
                <text x="85" y="100" text-anchor="middle" font-size="11" fill="var(--color-dash-6b7280)">{{ __('inventory::board.w.places', ['n' => count($warehouses)]) }}</text>
            </svg>
            <div style="display: flex; flex-direction: column; gap: 0.375rem; font-size: 0.75rem; flex-grow: 1">
                @foreach ($ring as $d)<span style="display: flex; justify-content: space-between; gap: 0.375rem"><span style="display: flex; align-items: center; gap: 0.375rem"><span style="width: 0.625rem; height: 0.625rem; border-radius: 0.1875rem; background: {{ $d['c'] }}"></span>{{ $d['name'] }}</span><b>{{ $d['value'] }}</b></span>@endforeach
            </div>
        </div>
    </section>

    <section data-inv-brand style="{{ $card }} gap: 0.5rem; border-top: 0.3125rem solid var(--color-dash-ec4899)">
        <h2 style="margin: 0; font-size: 1rem; font-weight: 700">{{ __('inventory::board.b.title') }}</h2>
        <div style="position: relative; height: 15.625rem; border-radius: 0.75rem; overflow: hidden">
            @forelse ($brands as $i => $b)
                <div style="position: absolute; left: {{ round($b['x'], 2) }}%; top: {{ round($b['y'], 2) }}%; width: {{ round($b['w'], 2) }}%; height: {{ round($b['h'], 2) }}%; background: linear-gradient(135deg, {{ $brandPaint[$i % 7][0] }}, {{ $brandPaint[$i % 7][1] }}); color: var(--color-dash-ffffff); box-sizing: border-box; border: 2px solid var(--color-dash-ffffff); border-radius: 0.625rem; padding: 0.5rem 0.625rem; display: flex; flex-direction: column; justify-content: space-between; overflow: hidden">
                    <span style="font-size: 0.8125rem; font-weight: 700">{{ $b['name'] }}</span><span style="font-size: 0.75rem; font-weight: 600">{{ $short($b['value']) }}</span>
                </div>
            @empty
                <p style="margin: 0; font-size: 0.8125rem; color: var(--color-dash-6b7280)">{{ __('inventory::board.b.none') }}</p>
            @endforelse
        </div>
    </section>
</div>
