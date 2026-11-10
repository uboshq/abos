{{--
    ⭐ বিক্রয়ের ড্যাশবোর্ড, সারি 2 — বিক্রি থেকে নিট লাভ · এ মাস (সিঁড়ি, দুই ভাগ চওড়া), মাসের লক্ষ্য (অর্ধবৃত্ত), সেরা 5 ও দুর্বল 5 পণ্য
    (মালিকের চূড়ান্ত নমুনা H8JXFPzh8m7zpsQSedmPDT)। ⓘ সিঁড়ি গ্রস লাভ থেকে শুরু (200 পর্যন্ত), প্রতিটা ধাপ সেই মাপে; শতাংশ বিক্রির।
--}}
@php
    $card = 'background: var(--color-dash-ffffff); border-radius: 1.125rem; padding: 1.125rem 1.25rem; box-shadow: 0 1px 3px var(--color-dash-17-24-39-a06); display: flex; flex-direction: column; color: var(--color-dash-111827)';
    $short = fn (string $v) => \App\Modules\Sales\Dashboard\SalesBoard::short($v);
    $l = $ladder;
    $sales = (float) $l['sales'];
    $pc = fn (string $v) => $sales > 0 ? number_format(abs((float) $v) / $sales * 100, 1, '.', '').'%' : '';
    $gross = (float) $l['gross'];
    $r1 = $gross - (float) $l['direct'];
    $r2 = $r1 - (float) $l['commission'];
    $r3 = $r2 - (float) $l['other_cost'];
    $r4 = $r3 + (float) $l['other_income'];
    $top = max(1.0, $gross, $r1, $r2, abs($r4));
    $px = fn (float $v) => max(0, round($v / $top * 200));
    $step = fn (float $a, float $b) => ['h' => max(2, abs($px($b) - $px($a))), 'mb' => min($px($a), $px($b))];
    $bars = [
        ['t' => __('sales::board.r2.gross'), 'v' => $short($l['gross']), 'pc' => $pc($l['gross']), 'h' => $px(max(0, $gross)), 'mb' => 0, 'bg' => ['var(--color-dash-60a5fa)', 'var(--color-dash-2563eb)'], 'c' => 'var(--color-dash-1d4ed8)'],
        ['t' => __('sales::board.r2.direct'), 'v' => '−'.$short($l['direct']), 'pc' => $pc($l['direct'])] + $step($gross, $r1) + ['bg' => ['var(--color-dash-fdba74)', 'var(--color-dash-ea580c)'], 'c' => 'var(--color-dash-c2410c)'],
        ['t' => __('sales::board.r2.before_commission'), 'v' => $short((string) $r1), 'pc' => $pc((string) $r1), 'h' => $px(max(0, $r1)), 'mb' => 0, 'bg' => ['var(--color-dash-5eead4)', 'var(--color-dash-0d9488)'], 'c' => 'var(--color-dash-0f766e)'],
        ['t' => __('sales::board.r2.commission'), 'v' => '−'.$short($l['commission']), 'pc' => $pc($l['commission'])] + $step($r1, $r2) + ['bg' => ['var(--color-dash-f9a8d4)', 'var(--color-dash-db2777)'], 'c' => 'var(--color-dash-be185d)'],
        ['t' => __('sales::board.r2.contribution'), 'v' => $short((string) $r2), 'pc' => $pc((string) $r2), 'h' => $px(max(0, $r2)), 'mb' => 0, 'bg' => ['var(--color-dash-6ee7b7)', 'var(--color-dash-059669)'], 'c' => 'var(--color-dash-047857)'],
        ['t' => __('sales::board.r2.other_cost'), 'v' => '−'.$short($l['other_cost']), 'pc' => $pc($l['other_cost'])] + $step($r2, $r3) + ['bg' => ['var(--color-dash-fca5a5)', 'var(--color-dash-dc2626)'], 'c' => 'var(--color-dash-b91c1c)'],
        ['t' => __('sales::board.r2.other_income'), 'v' => (bccomp($l['other_income'], '0', 4) >= 0 ? '+' : '').$short($l['other_income']), 'pc' => $pc($l['other_income'])] + $step($r3, $r4) + ['bg' => ['var(--color-dash-67e8f9)', 'var(--color-dash-0891b2)'], 'c' => 'var(--color-dash-0e7490)'],
        ['t' => __('sales::board.r2.net'), 'v' => $short($l['net']), 'pc' => $pc($l['net']), 'h' => max(2, $px(abs($r4))), 'mb' => 0, 'bg' => ['var(--color-dash-c4b5fd)', 'var(--color-dash-7c3aed)'], 'c' => bccomp($l['net'], '0', 4) < 0 ? 'var(--color-dash-dc2626)' : 'var(--color-dash-6d28d9)'],
    ];

    $t = $target;
    $tp = $t['pct'] ?? 0;
    $arc = round(min(100, $tp) / 100 * 314);
    $angle = M_PI - min(1, $t['days_pct'] / 100) * M_PI;
    $handX = round(120 + cos($angle) * 96, 1);
    $handY = round(120 - sin($angle) * 96, 1);
    $topMax = max(1.0, ...array_map(fn ($p) => (float) $p['value'], $products['top'] ?: [['value' => '1']]));
@endphp
<div data-sales-board-profit style="display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 1rem; width: 100%">
    <section data-sales-ladder style="grid-column: span 2; {{ $card }} gap: 0.625rem; border-top: 0.25rem solid var(--color-dash-7c3aed)">
        <div style="display: flex; justify-content: space-between; align-items: baseline; gap: 0.5rem; flex-wrap: wrap">
            <h2 style="margin: 0; font-size: 1.0625rem; font-weight: 800">{{ __('sales::board.r2.ladder') }}</h2>
            <span style="font-size: 0.875rem; font-weight: 800; color: {{ bccomp($l['net'], '0', 4) < 0 ? 'var(--color-dash-dc2626)' : 'var(--color-dash-7c3aed)' }}">@if (bccomp($l['net'], '0', 4) < 0){{ __('sales::board.r2.loss_head', ['value' => $short(ltrim($l['net'], '-'))]) }}@else{{ __('sales::board.r2.net_head', ['value' => $short($l['net']), 'pct' => trim($pc($l['net']), '%') ?: '0']) }}@endif</span>
        </div>
        <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; font-size: 0.8125rem">
            <span style="background: var(--color-dash-ecfdf5); color: var(--color-dash-065f46); border-radius: 0.625rem; padding: 0.375rem 0.625rem">{{ __('sales::board.r2.sales') }} <b>{{ $short($l['sales']) }}</b></span>
            <b style="color: var(--color-dash-6b7280)">−</b>
            <span style="background: var(--color-dash-fff1f2); color: var(--color-dash-9f1239); border-radius: 0.625rem; padding: 0.375rem 0.625rem">{{ __('sales::board.r2.cogs') }} <b>{{ $short($l['cogs']) }}</b></span>
            <b style="color: var(--color-dash-6b7280)">=</b>
            <span style="background: linear-gradient(135deg, var(--color-dash-dbeafe), var(--color-dash-e0e7ff)); color: var(--color-dash-1e3a8a); border-radius: 0.625rem; padding: 0.375rem 0.625rem">{{ __('sales::board.r2.gross') }} <b>{{ $short($l['gross']) }} · {{ $pc($l['gross']) ?: '—' }}</b></span>
        </div>
        <div style="display: flex; align-items: flex-end; gap: 0.75rem; height: 14.375rem; border-bottom: 1px solid var(--color-dash-e5e7f0); padding: 0 0.25rem">
            @foreach ($bars as $b)
                <div data-ladder-step style="flex: 1 1 0; height: 14.375rem; display: flex; flex-direction: column; justify-content: flex-end; align-items: center; gap: 2px">
                    <span style="font-size: 0.75rem; font-weight: 800; color: {{ $b['c'] }}; white-space: nowrap">{{ $b['v'] }}</span>
                    <span style="font-size: 0.625rem; color: var(--color-dash-6b7280)">{{ $b['pc'] }}</span>
                    <span style="width: 100%; height: {{ $b['h'] / 16 }}rem; margin-bottom: {{ $b['mb'] / 16 }}rem; background: linear-gradient(180deg, {{ $b['bg'][0] }}, {{ $b['bg'][1] }}); border-radius: 0.5rem"></span>
                </div>
            @endforeach
        </div>
        <div style="display: flex; gap: 0.75rem; padding: 0 0.25rem; font-size: 0.6875rem; color: var(--color-dash-374151); font-weight: 600">@foreach ($bars as $b)<span style="flex: 1 1 0; text-align: center">{{ $b['t'] }}</span>@endforeach</div>
        <div style="display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 0.5rem; font-size: 0.75rem">
            <span style="background: var(--color-dash-fff7ed); border-radius: 0.625rem; padding: 0.375rem 0.5rem">{{ __('sales::board.r2.vehicle') }}<b style="display: block; font-size: 0.875rem; color: var(--color-dash-c2410c)">{{ $short($l['vehicle']) }}</b></span>
            <span style="background: var(--color-dash-fff7ed); border-radius: 0.625rem; padding: 0.375rem 0.5rem">{{ __('sales::board.r2.labour') }}<b style="display: block; font-size: 0.875rem; color: var(--color-dash-c2410c)">{{ $short($l['labour']) }}</b></span>
            <span style="background: linear-gradient(135deg, var(--color-dash-fed7aa), var(--color-dash-fdba74)); border-radius: 0.625rem; padding: 0.375rem 0.5rem">{{ __('sales::board.r2.direct') }}<b style="display: block; font-size: 0.875rem; color: var(--color-dash-7c2d12)">{{ $short($l['direct']) }}</b></span>
        </div>
    </section>

    <section data-sales-target style="border-radius: 1.125rem; padding: 1.125rem 1.25rem; background: linear-gradient(160deg, var(--color-dash-052e2b), var(--color-dash-065f46) 55%, var(--color-dash-0e7490)); color: var(--color-dash-ffffff); display: flex; flex-direction: column; gap: 0.5rem; align-items: center">
        <h2 style="margin: 0; font-size: 1rem; font-weight: 700; align-self: flex-start; color: var(--color-dash-ffffff)">{{ __('sales::board.r2.target') }}</h2>
        @if ($t['target'] === null)
            <span style="font-size: 0.8125rem; opacity: 0.85; align-self: flex-start">{{ __('sales::board.r2.no_target') }}</span>
        @else
            <svg role="img" aria-label="{{ __('sales::board.r2.target_label', ['pct' => $tp]) }}" viewBox="0 0 240 140" style="width: 100%; max-width: 18.75rem; height: 10.625rem">
                <path d="M 20 120 A 100 100 0 0 1 220 120" fill="none" stroke="var(--color-dash-255-255-255-a15)" stroke-width="24" stroke-linecap="round"></path>
                @if ($arc > 0)<path d="M 20 120 A 100 100 0 0 1 220 120" fill="none" stroke="var(--color-dash-34d399)" stroke-width="24" stroke-linecap="round" stroke-dasharray="{{ $arc }} 400"></path>@endif
                <line x1="120" y1="120" x2="{{ $handX }}" y2="{{ $handY }}" stroke="var(--color-dash-fde68a)" stroke-width="3"></line>
                <text x="120" y="106" text-anchor="middle" font-size="38" font-weight="800" fill="var(--color-dash-ffffff)">{{ $tp }}%</text>
                <text x="120" y="132" text-anchor="middle" font-size="12" fill="var(--color-dash-a7f3d0)">{{ __('sales::board.r2.of_target', ['done' => $short($t['achieved']), 'target' => $short($t['target'])]) }}</text>
            </svg>
        @endif
        <div style="display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.5rem; width: 100%; font-size: 0.75rem">
            <div style="background: var(--color-dash-255-255-255-a10); border-radius: 0.75rem; padding: 0.5rem 0.625rem"><span style="opacity: 0.85">{{ __('sales::board.r2.days_passed') }}</span><b style="display: block; font-size: 1.125rem">{{ $t['days_pct'] }}%</b></div>
            <div style="background: var(--color-dash-255-255-255-a10); border-radius: 0.75rem; padding: 0.5rem 0.625rem"><span style="opacity: 0.85">{{ __('sales::board.r2.projected') }}</span><b style="display: block; font-size: 1.125rem; color: {{ $t['target'] !== null && bccomp($t['projected'], $t['target'], 4) >= 0 ? 'var(--color-dash-86efac)' : 'var(--color-dash-fca5a5)' }}">{{ $short($t['projected']) }}</b></div>
            <div style="background: var(--color-dash-255-255-255-a10); border-radius: 0.75rem; padding: 0.5rem 0.625rem"><span style="opacity: 0.85">{{ __('sales::board.r2.need') }}</span><b style="display: block; font-size: 1.125rem">{{ $t['need'] === null ? '—' : $short($t['need']) }}</b></div>
            <div style="background: var(--color-dash-255-255-255-a10); border-radius: 0.75rem; padding: 0.5rem 0.625rem"><span style="opacity: 0.85">{{ __('sales::board.r2.pace') }}</span><b style="display: block; font-size: 1.125rem">{{ $short($t['pace']) }}</b></div>
        </div>
    </section>

    <section data-sales-products style="{{ $card }} gap: 0.375rem; border-top: 0.3125rem solid var(--color-dash-f59e0b)">
        <h2 style="margin: 0; font-size: 1rem; font-weight: 700">{{ __('sales::board.r2.products') }}</h2>
        <span style="font-size: 0.6875rem; font-weight: 700; color: var(--color-dash-047857)">{{ __('sales::board.r2.best') }}</span>
        @forelse ($products['top'] as $p)
            <div data-product-best style="display: grid; grid-template-columns: minmax(0, 1fr) 5.625rem 3.25rem; gap: 0.5rem; align-items: center; font-size: 0.75rem"><span style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap">{{ $p['name'] }}</span><span style="display: block; height: 0.625rem; border-radius: 999px; background: var(--color-dash-f1f2f7)"><span style="display: block; height: 0.625rem; width: {{ round((float) $p['value'] / $topMax * 100) }}%; background: linear-gradient(90deg, var(--color-dash-34d399), var(--color-dash-059669)); border-radius: 999px"></span></span><b style="text-align: right">{{ $short($p['value']) }}</b></div>
        @empty
            <span style="font-size: 0.75rem; color: var(--color-dash-6b7280)">{{ __('sales::board.r2.no_products') }}</span>
        @endforelse
        <span style="font-size: 0.6875rem; font-weight: 700; color: var(--color-dash-b91c1c); margin-top: 0.25rem">{{ __('sales::board.r2.worst') }}</span>
        @forelse ($products['falling'] as $p)
            <div data-product-worst style="display: grid; grid-template-columns: minmax(0, 1fr) 5.625rem 3.25rem; gap: 0.5rem; align-items: center; font-size: 0.75rem"><span style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap">{{ $p['name'] }}</span><span style="display: flex; justify-content: flex-end; height: 0.625rem; border-radius: 999px; background: var(--color-dash-f1f2f7)"><span style="display: block; height: 0.625rem; width: {{ min(100, abs($p['pct'])) }}%; background: linear-gradient(90deg, var(--color-dash-fca5a5), var(--color-dash-dc2626)); border-radius: 999px"></span></span><b style="text-align: right; color: var(--color-dash-dc2626)">{{ $p['pct'] }}%</b></div>
        @empty
            <span style="font-size: 0.75rem; color: var(--color-dash-6b7280)">{{ __('sales::board.r2.none_falling') }}</span>
        @endforelse
    </section>
</div>
