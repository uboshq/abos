{{--
    ⭐ বিক্রয়ের ড্যাশবোর্ড, সারি 5 — ব্র্যান্ড ধরে বিক্রি (ট্রিম্যাপ), বিক্রির পথ ধরে (ডোনাট + নগদ/বাকি), কখন অর্ডার আসে (বার × সময়)
    (মালিকের চূড়ান্ত নমুনা H8JXFPzh8m7zpsQSedmPDT)। অঙ্ক [[SalesBoard]] থেকে।
--}}
@php
    $card = 'background: var(--color-dash-ffffff); border-radius: 1.125rem; padding: 1.125rem 1.25rem; box-shadow: 0 1px 3px var(--color-dash-17-24-39-a06); display: flex; flex-direction: column; color: var(--color-dash-111827)';
    $short = fn (string $v) => \App\Modules\Sales\Dashboard\SalesBoard::short($v);
    $brandPaint = [['var(--color-dash-10b981)', 'var(--color-dash-047857)'], ['var(--color-dash-6366f1)', 'var(--color-dash-4338ca)'], ['var(--color-dash-f59e0b)', 'var(--color-dash-d97706)'], ['var(--color-dash-0ea5e9)', 'var(--color-dash-0284c7)'], ['var(--color-dash-ec4899)', 'var(--color-dash-db2777)'], ['var(--color-dash-8b5cf6)', 'var(--color-dash-7c3aed)'], ['var(--color-dash-64748b)', 'var(--color-dash-94a3b8)']];
    $chPaint = ['var(--color-dash-10b981)', 'var(--color-dash-0ea5e9)', 'var(--color-dash-f59e0b)', 'var(--color-dash-8b5cf6)', 'var(--color-dash-ec4899)', 'var(--color-dash-64748b)'];
    $C = 2 * M_PI * 62; $acc = 0.0; $ring = [];
    foreach (array_slice($channels['parts'], 0, 6) as $i => $p) {
        if ((float) $channels['total'] <= 0 || (float) $p['value'] <= 0) { continue; }
        $len = (float) $p['value'] / (float) $channels['total'] * $C;
        $ring[] = $p + ['c' => $chPaint[$i % count($chPaint)], 'pct' => (int) round((float) $p['value'] / (float) $channels['total'] * 100), 'dash' => round(max($len - 2, 0), 1).' '.round($C - $len + 2, 1), 'off' => round(-$acc, 1)];
        $acc += $len;
    }
    $grid = $heat['grid'];
    $maxN = $heat['max'];
    $best = [0, 0, -1];
    foreach ($grid as $d => $row) { foreach ($row as $h => $n) { if ($n > $best[2]) { $best = [$d, $h, $n]; } } }
@endphp
<div data-sales-board-mix style="display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1rem; width: 100%">
    <section data-sales-brands style="{{ $card }} gap: 0.5rem; border-top: 0.25rem solid var(--color-dash-ec4899)">
        <div style="display: flex; justify-content: space-between; align-items: baseline; gap: 0.5rem">
            <h2 style="margin: 0; font-size: 1rem; font-weight: 700">{{ __('sales::board.r5.brands') }}</h2>
            <span style="font-size: 0.75rem; color: var(--color-dash-4b5563)">{{ __('sales::board.r5.brands_note') }}</span>
        </div>
        @if ($brands === [])
            <span style="font-size: 0.8125rem; color: var(--color-dash-6b7280)">{{ __('sales::board.r5.brands_none') }}</span>
        @else
            <div style="position: relative; height: 16.25rem; border-radius: 0.75rem; overflow: hidden">
                @foreach ($brands as $i => $b)
                    <div data-brand-box title="{{ $b['name'] }} · {{ $short($b['value']) }}" style="position: absolute; left: {{ round($b['x'], 2) }}%; top: {{ round($b['y'], 2) }}%; width: {{ round($b['w'], 2) }}%; height: {{ round($b['h'], 2) }}%; background: linear-gradient(135deg, {{ $brandPaint[$i % count($brandPaint)][0] }}, {{ $brandPaint[$i % count($brandPaint)][1] }}); color: var(--color-dash-ffffff); box-sizing: border-box; border: 2px solid var(--color-dash-ffffff); border-radius: 0.625rem; padding: 0.5rem 0.625rem; display: flex; flex-direction: column; justify-content: space-between; overflow: hidden">
                        <span style="font-size: 0.8125rem; font-weight: 700">{{ $b['name'] }}</span><span style="font-size: 0.75rem; font-weight: 600">{{ $short($b['value']) }} · {{ $b['pct'] }}%</span>
                    </div>
                @endforeach
            </div>
        @endif
    </section>

    <section data-sales-channels style="{{ $card }} gap: 0.5rem; border-top: 0.25rem solid var(--color-dash-f59e0b)">
        <h2 style="margin: 0; font-size: 1rem; font-weight: 700">{{ __('sales::board.r5.channels') }}</h2>
        @if ($ring === [])
            <span style="font-size: 0.8125rem; color: var(--color-dash-6b7280)">{{ __('sales::board.r5.channels_none') }}</span>
        @else
            <div style="display: flex; align-items: center; gap: 0.875rem">
                <svg role="img" aria-label="{{ __('sales::board.r5.channels_label') }}" viewBox="0 0 180 180" style="width: 10.9375rem; height: 10.9375rem; flex-shrink: 0">
                    <g transform="rotate(-90 90 90)">@foreach ($ring as $d)<circle cx="90" cy="90" r="62" fill="none" stroke="{{ $d['c'] }}" stroke-width="28" stroke-dasharray="{{ $d['dash'] }}" stroke-dashoffset="{{ $d['off'] }}"></circle>@endforeach</g>
                    <text x="90" y="86" text-anchor="middle" font-size="19" font-weight="800" fill="var(--color-dash-111827)">{{ $short($channels['total']) }}</text>
                    <text x="90" y="104" text-anchor="middle" font-size="11" fill="var(--color-dash-6b7280)">{{ __('sales::board.r5.this_month') }}</text>
                </svg>
                <div style="display: flex; flex-direction: column; gap: 0.4375rem; font-size: 0.75rem; flex-grow: 1; min-width: 0">
                    @foreach ($ring as $d)<span style="display: flex; justify-content: space-between; gap: 0.375rem"><span style="display: flex; align-items: center; gap: 0.375rem; min-width: 0"><span style="flex: none; width: 0.625rem; height: 0.625rem; border-radius: 3px; background: {{ $d['c'] }}"></span><span style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap">{{ $d['name'] }}</span></span><b>{{ $d['pct'] }}%</b></span>@endforeach
                </div>
            </div>
            @if ($channels['cash_pct'] !== null)
                <div style="display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.5rem; text-align: center">
                    <div style="background: var(--color-dash-ecfdf5); border-radius: 0.75rem; padding: 0.5rem 0.25rem"><span style="font-size: 0.6875rem; color: var(--color-dash-4b5563)">{{ __('sales::board.r5.cash') }}</span><b style="display: block; font-size: 1.125rem; color: var(--color-dash-10b981)">{{ $channels['cash_pct'] }}%</b></div>
                    <div style="background: var(--color-dash-fff7ed); border-radius: 0.75rem; padding: 0.5rem 0.25rem"><span style="font-size: 0.6875rem; color: var(--color-dash-4b5563)">{{ __('sales::board.r5.credit') }}</span><b style="display: block; font-size: 1.125rem; color: var(--color-dash-f97316)">{{ 100 - $channels['cash_pct'] }}%</b></div>
                </div>
            @endif
        @endif
    </section>

    <section data-sales-heat style="{{ $card }} gap: 0.5rem; border-top: 0.25rem solid var(--color-dash-8b5cf6)">
        <h2 style="margin: 0; font-size: 1rem; font-weight: 700">{{ __('sales::board.r5.heat') }}</h2>
        @if ($best[2] <= 0)
            <span style="font-size: 0.8125rem; color: var(--color-dash-6b7280)">{{ __('sales::board.r5.heat_none') }}</span>
        @else
            <div style="display: grid; grid-template-columns: 3.375rem repeat(7, minmax(0, 1fr)); gap: 0.25rem; font-size: 0.6875rem; text-align: center">
                <span></span>@foreach (__('sales::board.r5.hours') as $h)<span style="color: var(--color-dash-6b7280)">{{ $h }}</span>@endforeach
                @foreach ($grid as $d => $row)
                    <span style="height: 1.625rem; display: flex; align-items: center; justify-content: flex-start; color: var(--color-dash-374151)">{{ __('sales::board.r5.weekdays')[$d] }}</span>
                    @foreach ($row as $n)
                        @php $s = $n / $maxN; @endphp
                        <span data-heat-cell title="{{ $n }}" style="height: 1.625rem; border-radius: 0.375rem; background: {{ $n === 0 ? 'var(--color-dash-f1f5f9)' : ($s < 0.34 ? 'var(--color-dash-c4b5fd)' : ($s < 0.67 ? 'var(--color-dash-a78bfa)' : 'var(--color-dash-7c3aed)')) }}; color: {{ $s >= 0.34 ? 'var(--color-dash-ffffff)' : 'var(--color-dash-475569)' }}; display: flex; align-items: center; justify-content: center; font-size: 0.6875rem">{{ $n > 0 ? $n : '' }}</span>
                    @endforeach
                @endforeach
            </div>
            <span style="font-size: 0.75rem; color: var(--color-dash-4b5563)">{{ __('sales::board.r5.heat_note', ['day' => __('sales::board.r5.weekdays')[$best[0]], 'hour' => __('sales::board.r5.hours')[$best[1]]]) }}</span>
        @endif
    </section>
</div>
