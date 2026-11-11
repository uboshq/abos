{{--
    ⭐ হিসাবের ড্যাশবোর্ড, সারি 3 — এক নজরে · আজকের হিসাব (দুই ভাগ চওড়া) আর খরচের খাত · ট্রিম্যাপ (দুই ভাগ চওড়া)
    (মালিকের চূড়ান্ত নমুনা TErMgXrtgVBkuyuoCZq8NS; মালিক এই সারি সমস্যা কেন্দ্রের পরে আনতে বলেছিলেন)।
--}}
@php
    $card = 'background: var(--color-dash-ffffff); border-radius: 1.125rem; padding: 1.125rem 1.25rem; box-shadow: 0 1px 3px var(--color-dash-17-24-39-a06); display: flex; flex-direction: column; color: var(--color-dash-111827)';
    $short = fn (string $v) => \App\Modules\Accounts\Dashboard\AccountsBoard::short($v);
    $dot = ['today' => 'var(--color-dash-4f46e5)', 'awaiting' => 'var(--color-dash-f59e0b)', 'signed' => 'var(--color-dash-ef4444)', 'bank' => 'var(--color-dash-0ea5e9)', 'profit' => 'var(--color-dash-10b981)', 'money' => 'var(--color-dash-8b5cf6)'];
    $spark = function (array $vals): string {
        if (count($vals) < 2) { return ''; }
        $hi = max($vals); $lo = min($vals); $span = max(0.0001, $hi - $lo); $n = count($vals) - 1;
        return implode(' ', array_map(fn ($v, $i) => round($i / $n * 200).','.round(40 - ($v - $lo) / $span * 36), $vals, array_keys($vals)));
    };
    $treePaint = [['var(--color-dash-6366f1)', 'var(--color-dash-8b5cf6)'], ['var(--color-dash-f97316)', 'var(--color-dash-fb923c)'], ['var(--color-dash-0ea5e9)', 'var(--color-dash-06b6d4)'], ['var(--color-dash-ec4899)', 'var(--color-dash-f43f5e)'], ['var(--color-dash-10b981)', 'var(--color-dash-14b8a6)'], ['var(--color-dash-f59e0b)', 'var(--color-dash-eab308)'], ['var(--color-dash-a855f7)', 'var(--color-dash-d946ef)'], ['var(--color-dash-64748b)', 'var(--color-dash-94a3b8)']];
@endphp
<div data-acc-board-glance style="display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 1rem; width: 100%">
    <section data-acc-glance style="grid-column: span 2; {{ $card }} gap: 0.5rem; border-top: 0.25rem solid var(--color-dash-6366f1)">
        <div style="display: flex; justify-content: space-between; align-items: baseline; gap: 0.5rem">
            <h2 style="margin: 0; font-size: 1rem; font-weight: 700">{{ __('accounts::board.g.title') }}</h2>
            <span style="font-size: 0.75rem; color: var(--color-dash-6b7280)">{{ __('accounts::board.g.hint') }}</span>
        </div>
        <div style="display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); column-gap: 1.5rem">
            @foreach ($glance as $k)
                @php $c = $dot[$k['key']] ?? 'var(--color-dash-64748b)'; $line = $spark($k['line']); @endphp
                <a href="{{ $k['href'] }}" data-glance="{{ $k['key'] }}" style="display: grid; grid-template-columns: 0.625rem minmax(0, 1fr) 6rem auto; gap: 0.75rem; align-items: center; padding: 0.625rem 0.25rem; border-top: 1px solid var(--color-dash-f1f2f7); color: var(--color-dash-111827); text-decoration: none">
                    <span style="width: 0.625rem; height: 0.625rem; border-radius: 999px; background: {{ $c }}"></span>
                    <span style="display: flex; flex-direction: column; min-width: 0"><span style="font-size: 0.8125rem; font-weight: 600">{{ __('accounts::board.g.'.$k['key']) }}</span><span style="font-size: 0.6875rem; color: var(--color-dash-6b7280); overflow: hidden; text-overflow: ellipsis; white-space: nowrap">{{ $k['sub'] }}</span></span>
                    @if ($line !== '')
                        <svg aria-hidden="true" viewBox="0 0 200 44" preserveAspectRatio="none" style="width: 6rem; height: 1.625rem"><polyline points="{{ $line }}" fill="none" stroke="{{ $c }}" stroke-width="4"></polyline></svg>
                    @else
                        <span></span>
                    @endif
                    <span style="display: flex; flex-direction: column; align-items: flex-end"><b style="font-size: 1.0625rem">{{ $k['value'] }}</b>@if ($k['delta'] !== '')<span style="font-size: 0.6875rem; font-weight: 700; color: {{ $c }}; white-space: nowrap">{{ $k['up'] === null ? '' : ($k['up'] ? '▲ ' : '▼ ') }}{{ $k['delta'] }}</span>@endif</span>
                </a>
            @endforeach
        </div>
    </section>

    <section data-acc-tree style="grid-column: span 2; {{ $card }} gap: 0.625rem; border-top: 0.25rem solid var(--color-dash-f59e0b)">
        <div style="display: flex; justify-content: space-between; align-items: baseline; gap: 0.5rem; flex-wrap: wrap">
            <h2 style="margin: 0; font-size: 1rem; font-weight: 700">{{ __('accounts::board.g.tree') }}</h2>
            @if ($tree['boxes'] !== [])<span style="font-size: 0.8125rem; color: var(--color-dash-4b5563)">{{ __('accounts::board.g.tree_note', ['value' => $short($tree['total'])]) }}</span>@endif
        </div>
        @if ($tree['boxes'] === [])
            <span style="font-size: 0.8125rem; color: var(--color-dash-6b7280)">{{ __('accounts::board.g.tree_none') }}</span>
        @else
            <div style="position: relative; height: 16.25rem; border-radius: 0.75rem; overflow: hidden">
                @foreach ($tree['boxes'] as $i => $b)
                    <div data-tree-box title="{{ $b['name'] }} · {{ $short($b['value']) }}" style="position: absolute; left: {{ round($b['x'], 2) }}%; top: {{ round($b['y'], 2) }}%; width: {{ round($b['w'], 2) }}%; height: {{ round($b['h'], 2) }}%; background: linear-gradient(135deg, {{ $treePaint[$i % count($treePaint)][0] }}, {{ $treePaint[$i % count($treePaint)][1] }}); color: var(--color-dash-ffffff); box-sizing: border-box; border: 2px solid var(--color-dash-ffffff); border-radius: 0.625rem; padding: 0.5rem 0.625rem; display: flex; flex-direction: column; justify-content: space-between; overflow: hidden">
                        <span style="font-size: 0.8125rem; font-weight: 700; overflow: hidden; text-overflow: ellipsis">{{ $b['name'] }}</span>
                        <span style="font-size: 0.75rem; font-weight: 600; opacity: 0.95">{{ $short($b['value']) }} · {{ $b['pct'] }}%</span>
                    </div>
                @endforeach
            </div>
        @endif
    </section>
</div>
