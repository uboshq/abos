{{--
    ⭐ বিক্রয়ের ড্যাশবোর্ড, সারি 4 — গ্রাহক ধরে রাখা, আদায়ের দক্ষতা · SR ধরে, অর্ডার থেকে টাকা (ফানেল), ফেরত ও ছাড় · কারণ ধরে
    (মালিকের চূড়ান্ত নমুনা H8JXFPzh8m7zpsQSedmPDT)। অঙ্ক [[SalesBoard]] থেকে।
--}}
@php
    $card = 'background: var(--color-dash-ffffff); border-radius: 1.125rem; padding: 1.125rem 1.25rem; box-shadow: 0 1px 3px var(--color-dash-17-24-39-a06); display: flex; flex-direction: column; color: var(--color-dash-111827)';
    $short = fn (string $v) => \App\Modules\Sales\Dashboard\SalesBoard::short($v);
    $monthShort = __('sales::board.months_short');
    $r = $retention;
    $peak = max(1, ...array_column($r['months'], 'shops'));
    $f = $o2c;
    $funnel = [
        ['k' => 'ordered', 'w' => '100%', 'c' => 'linear-gradient(90deg, var(--color-dash-a5b4fc), var(--color-dash-6366f1))'],
        ['k' => 'approved', 'w' => '90%', 'c' => 'linear-gradient(90deg, var(--color-dash-7dd3fc), var(--color-dash-0ea5e9))'],
        ['k' => 'dispatched', 'w' => '80%', 'c' => 'linear-gradient(90deg, var(--color-dash-5eead4), var(--color-dash-14b8a6))'],
        ['k' => 'invoiced', 'w' => '70%', 'c' => 'linear-gradient(90deg, var(--color-dash-6ee7b7), var(--color-dash-10b981))'],
        ['k' => 'collected', 'w' => '60%', 'c' => 'linear-gradient(90deg, var(--color-dash-bef264), var(--color-dash-65a30d))'],
    ];
    $retPaint = ['linear-gradient(90deg, var(--color-dash-f43f5e), var(--color-dash-fb7185))', 'linear-gradient(90deg, var(--color-dash-f97316), var(--color-dash-fdba74))', 'linear-gradient(90deg, var(--color-dash-f59e0b), var(--color-dash-fde68a))'];
    $discPaint = ['linear-gradient(90deg, var(--color-dash-8b5cf6), var(--color-dash-c4b5fd))', 'linear-gradient(90deg, var(--color-dash-0ea5e9), var(--color-dash-7dd3fc))', 'linear-gradient(90deg, var(--color-dash-10b981), var(--color-dash-6ee7b7))'];
    $rdMax = max(1.0, ...array_map(fn ($x) => (float) $x['value'], $rd['rows'] ?: [['value' => '1']]));
@endphp
<div data-sales-board-customers style="display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 1rem; width: 100%">
    <section data-sales-retention style="{{ $card }} gap: 0.5rem; border-top: 0.3125rem solid var(--color-dash-10b981)">
        <div style="display: flex; justify-content: space-between; align-items: baseline; gap: 0.5rem">
            <h2 style="margin: 0; font-size: 1rem; font-weight: 700">{{ __('sales::board.r4.retention') }}</h2>
            @if ($r['kept'] !== null)<span style="font-size: 0.8125rem; font-weight: 800; color: var(--color-dash-059669)">{{ __('sales::board.r4.kept', ['n' => $r['kept']]) }}</span>@endif
        </div>
        <div style="display: flex; align-items: flex-end; gap: 0.5rem; height: 9.375rem; border-bottom: 1px solid var(--color-dash-e5e7f0)">
            @foreach ($r['months'] as $i => $m)
                <div style="flex: 1 1 0; display: flex; flex-direction: column; align-items: center; gap: 2px"><span style="font-size: 0.625rem; font-weight: 700; color: var(--color-dash-065f46)">{{ number_format($m['shops']) }}</span><span style="width: 70%; height: {{ max(2, round($m['shops'] / $peak * 120)) / 16 }}rem; background: {{ $i === count($r['months']) - 1 ? 'linear-gradient(180deg, var(--color-dash-a7f3d0), var(--color-dash-34d399))' : 'linear-gradient(180deg, var(--color-dash-34d399), var(--color-dash-059669))' }}; border-radius: 0.375rem 0.375rem 0 0"></span></div>
            @endforeach
        </div>
        <div style="display: flex; gap: 0.5rem; font-size: 0.625rem; color: var(--color-dash-6b7280)">@foreach ($r['months'] as $i => $m)<span style="flex: 1 1 0; text-align: center">{{ $i === count($r['months']) - 1 ? __('sales::board.r4.this_month') : $monthShort[$m['month']] }}</span>@endforeach</div>
        <span style="font-size: 0.6875rem; color: var(--color-dash-4b5563)">{{ __('sales::board.r4.retention_note') }}</span>
        <div style="display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 0.375rem; text-align: center; font-size: 0.6875rem">
            <span style="background: var(--color-dash-ecfdf5); border-radius: 0.625rem; padding: 0.375rem 0.25rem">{{ __('sales::board.r4.new') }}<b style="display: block; font-size: 1rem; color: var(--color-dash-047857)">{{ $r['new'] }}</b></span>
            <span style="background: var(--color-dash-eff6ff); border-radius: 0.625rem; padding: 0.375rem 0.25rem">{{ __('sales::board.r4.back') }}<b style="display: block; font-size: 1rem; color: var(--color-dash-1d4ed8)">{{ $r['back'] }}</b></span>
            <span style="background: var(--color-dash-fef2f2); border-radius: 0.625rem; padding: 0.375rem 0.25rem">{{ __('sales::board.r4.lost') }}<b style="display: block; font-size: 1rem; color: var(--color-dash-b91c1c)">{{ $r['lost'] }}</b></span>
        </div>
    </section>

    <section data-sales-cei style="{{ $card }} gap: 0.4375rem; border-top: 0.3125rem solid var(--color-dash-6366f1)">
        <div style="display: flex; justify-content: space-between; align-items: baseline; gap: 0.5rem">
            <h2 style="margin: 0; font-size: 1rem; font-weight: 700">{{ __('sales::board.r4.cei') }}</h2>
            @if ($cei['total'] !== null)<span style="font-size: 0.8125rem; font-weight: 800; color: var(--color-dash-4f46e5)">{{ __('sales::board.r4.cei_total', ['n' => $cei['total']]) }}</span>@endif
        </div>
        @if ($cei['rows'] === [])
            <span style="font-size: 0.8125rem; color: var(--color-dash-6b7280)">{{ __('sales::board.r4.cei_none') }}</span>
        @else
            <div style="display: grid; grid-template-columns: 4rem minmax(0, 1fr) 2.5rem 3.375rem; gap: 0.5rem; font-size: 0.625rem; color: var(--color-dash-6b7280)"><span>{{ __('sales::board.r4.sr') }}</span><span>{{ __('sales::board.r4.effectiveness') }}</span><span></span><span style="text-align: right">{{ __('sales::board.r4.dso') }}</span></div>
            @foreach ($cei['rows'] as $c)
                @php $ink = $c['pct'] >= 85 ? 'var(--color-dash-16a34a)' : ($c['pct'] >= 70 ? 'var(--color-dash-d97706)' : 'var(--color-dash-dc2626)'); @endphp
                <div data-cei-row style="display: grid; grid-template-columns: 4rem minmax(0, 1fr) 2.5rem 3.375rem; gap: 0.5rem; align-items: center; font-size: 0.75rem">
                    <span style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap">{{ $c['name'] }}</span>
                    <span style="position: relative; display: block; height: 0.75rem; border-radius: 999px; background: var(--color-dash-f1f2f7)"><span style="position: absolute; left: 0; top: 0; height: 0.75rem; width: {{ $c['pct'] }}%; background: {{ $ink }}; border-radius: 999px"></span><span style="position: absolute; left: 85%; top: -2px; width: 2px; height: 1rem; background: var(--color-dash-111827)"></span></span>
                    <b style="text-align: right; color: {{ $ink }}">{{ $c['pct'] }}%</b><span style="text-align: right; color: var(--color-dash-4b5563)">{{ $c['dso'] === null ? '—' : __('sales::board.r4.days', ['n' => $c['dso']]) }}</span>
                </div>
            @endforeach
            <span style="font-size: 0.6875rem; color: var(--color-dash-6b7280)">{{ __('sales::board.r4.cei_note') }}</span>
        @endif
    </section>

    <section data-sales-o2c style="{{ $card }} gap: 0.4375rem; border-top: 0.25rem solid var(--color-dash-0ea5e9)">
        <h2 style="margin: 0; font-size: 1rem; font-weight: 700">{{ __('sales::board.r4.funnel') }}</h2>
        @foreach ($funnel as $u)
            <div style="display: flex; justify-content: center"><div style="width: {{ $u['w'] }}; background: {{ $u['c'] }}; color: var(--color-dash-ffffff); border-radius: 0.625rem; padding: 0.5rem 0.75rem; display: flex; justify-content: space-between; font-size: 0.8125rem; font-weight: 600"><span>{{ __('sales::board.r4.'.$u['k']) }}</span><b>{{ $short($f[$u['k']]) }}</b></div></div>
        @endforeach
        <div style="display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.375rem; margin-top: 0.25rem">
            @php $credit = collect($problems)->firstWhere('key', 'credit'); $late = collect($problems)->firstWhere('key', 'late'); @endphp
            <a href="{{ $credit['href'] ?? '#' }}" style="background: linear-gradient(135deg, var(--color-dash-fef2f2), var(--color-dash-fee2e2)); border-radius: 0.625rem; padding: 0.375rem 0.5rem; color: var(--color-dash-111827); font-size: 0.75rem; text-decoration: none"><span style="color: var(--color-dash-b91c1c)">{{ __('sales::board.r4.held') }}</span><b style="display: block; font-size: 0.875rem">{{ $credit['n'] ?? 0 }} · {{ $credit['detail'] ?? '' }}</b></a>
            <a href="{{ $late['href'] ?? '#' }}" style="background: linear-gradient(135deg, var(--color-dash-fff7ed), var(--color-dash-ffedd5)); border-radius: 0.625rem; padding: 0.375rem 0.5rem; color: var(--color-dash-111827); font-size: 0.75rem; text-decoration: none"><span style="color: var(--color-dash-c2410c)">{{ __('sales::board.r4.late', ['n' => \App\Modules\Sales\Dashboard\SalesBoard::LATE_DAYS]) }}</span><b style="display: block; font-size: 0.875rem">{{ $late['n'] ?? 0 }}</b></a>
        </div>
    </section>

    <section data-sales-returns style="{{ $card }} gap: 0.5625rem; border-top: 0.25rem solid var(--color-dash-f43f5e)">
        <h2 style="margin: 0; font-size: 1rem; font-weight: 700">{{ __('sales::board.r4.returns') }}</h2>
        @php $ri = 0; $di = 0; @endphp
        @foreach ($rd['rows'] as $x)
            @php $paint = $x['kind'] === 'return' ? $retPaint[$ri++ % 3] : $discPaint[$di++ % 3]; @endphp
            <div data-return-row="{{ $x['kind'] }}" style="display: grid; grid-template-columns: 8.125rem minmax(0, 1fr) 4.375rem; gap: 0.625rem; align-items: center; font-size: 0.8125rem">
                <span style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap">{{ $x['name'] }}</span>
                <span style="display: block; height: 0.875rem; border-radius: 999px; background: var(--color-dash-f1f2f7)"><span style="display: block; height: 0.875rem; width: {{ round((float) $x['value'] / $rdMax * 100) }}%; background: {{ $paint }}; border-radius: 999px"></span></span>
                <b style="text-align: right">{{ $short($x['value']) }}</b>
            </div>
        @endforeach
        @if ($rd['return_pct'] !== null)<span style="font-size: 0.75rem; color: var(--color-dash-4b5563)">{{ __('sales::board.r4.returns_note', ['r' => $rd['return_pct'], 'd' => $rd['discount_pct']]) }}</span>@endif
    </section>
</div>
