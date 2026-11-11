{{--
    ⭐ মজুদের ড্যাশবোর্ড, সারি 2 — আবার অর্ডার দিতে হবে (তিন ভাগ চওড়া) আর ধীর ও অচল মজুদ (15/30/60/90 বাছাই)
    (মালিকের চূড়ান্ত নমুনা HmzeE5CN2wvtyLPE5exjvV; মালিক, 10 নভেম্বর 2026 রাত: অচল মজুদ পুনঃঅর্ডারের পাশে, দিনের বাছাইসহ)।
--}}
@php
    $grid = 'display: grid; grid-template-columns: minmax(0, 2.2fr) 3.75rem 4rem minmax(0, 1.6fr) 4rem 4.375rem 4.625rem 6rem 5.125rem; gap: 0.625rem';
    $card = 'background: var(--color-dash-ffffff); border-radius: 1.125rem; padding: 1.125rem 1.25rem; box-shadow: 0 1px 3px var(--color-dash-17-24-39-a06); display: flex; flex-direction: column; color: var(--color-dash-111827)';
@endphp
<div data-inv-board-replenish style="display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 1rem; width: 100%">
    <section data-inv-reorder style="grid-column: span 3; {{ $card }} gap: 0.375rem; border-top: 0.3125rem solid var(--color-dash-f97316)">
        <div style="display: flex; justify-content: space-between; align-items: baseline; gap: 1rem">
            <h2 style="margin: 0; font-size: 1.0625rem; font-weight: 800">{{ __('inventory::board.r.title', ['n' => $reorder['count']]) }}</h2>
            <span style="font-size: 0.75rem; color: var(--color-dash-6b7280)">{{ __('inventory::board.r.formula') }}</span>
            @if ($reorderLink)
                <a href="{{ $reorderLink }}" style="font-size: 0.8125rem; font-weight: 700; color: var(--color-dash-ffffff); background: linear-gradient(135deg, var(--color-dash-ef4444), var(--color-dash-f97316)); border-radius: 0.625rem; padding: 0.375rem 0.75rem; text-decoration: none">{{ __('inventory::board.r.draft') }}</a>
            @endif
        </div>
        <div style="{{ $grid }}; font-size: 0.6875rem; font-weight: 700; color: var(--color-dash-6b7280); padding: 0.25rem">
            <span>{{ __('inventory::board.r.product') }}</span><span style="text-align: right">{{ __('inventory::board.r.have') }}</span><span style="text-align: right">{{ __('inventory::board.r.rate') }}</span><span>{{ __('inventory::board.r.cover') }}</span><span style="text-align: right">{{ __('inventory::board.r.lead') }}</span><span style="text-align: right">{{ __('inventory::board.r.ss') }}</span><span style="text-align: right">{{ __('inventory::board.r.rop') }}</span><span style="text-align: right">{{ __('inventory::board.r.po') }}</span><span style="text-align: right">{{ __('inventory::board.r.sug') }}</span>
        </div>
        @forelse ($reorder['rows'] as $r)
            <div data-inv-reorder-row="{{ $r['id'] }}" style="{{ $grid }}; font-size: 0.8125rem; padding: 0.5rem 0.25rem; border-top: 1px solid var(--color-dash-f1f2f7); align-items: center; background: {{ $r['row'] }}; border-radius: 0.5rem">
                <span><b>{{ $r['name'] }}</b>@if ($r['brand'] !== '') <span style="color: var(--color-dash-6b7280)">· {{ $r['brand'] }}</span>@endif</span>
                <b style="text-align: right; color: {{ $r['tone'] }}">{{ $r['have'] }}</b>
                <span style="text-align: right; color: var(--color-dash-4b5563)">{{ $r['rate'] }}</span>
                <span style="display: flex; align-items: center; gap: 0.5rem"><span style="flex-grow: 1; height: 0.625rem; border-radius: 999px; background: var(--color-dash-f1f2f7)"><span style="display: block; height: 0.625rem; width: {{ $r['width'] }}; background: {{ $r['tone'] }}; border-radius: 999px"></span></span><b style="color: {{ $r['tone'] }}; width: 3.5rem; text-align: right">{{ $r['days'] }}</b></span>
                <span style="text-align: right; color: var(--color-dash-4b5563)">{{ $r['lead'] }}</span>
                <span style="text-align: right; color: var(--color-dash-4b5563)">{{ $r['ss'] }}</span>
                <span style="text-align: right; color: var(--color-dash-4b5563)">{{ $r['rop'] }}</span>
                <span style="text-align: right; color: var(--color-dash-0369a1); font-size: 0.75rem">{{ $r['po'] }}</span>
                <b style="text-align: right; color: var(--color-dash-4f46e5)">{{ $r['sug'] }}</b>
            </div>
        @empty
            <p style="margin: 0; padding: 0.75rem 0.25rem; font-size: 0.8125rem; color: var(--color-dash-047857)">{{ __('inventory::board.r.none') }}</p>
        @endforelse
        @if (bccomp($reorder['lost'], '0', 4) > 0 || bccomp($reorder['blocked'], '0', 4) > 0)
            <span style="font-size: 0.75rem; color: var(--color-dash-b91c1c); background: var(--color-dash-fef2f2); border-radius: 0.625rem; padding: 0.375rem 0.625rem">{{ __('inventory::board.r.lost', ['amount' => \App\Modules\Inventory\Dashboard\InventoryBoard::short($reorder['lost']), 'n' => $reorder['lost_count'], 'blocked' => \App\Modules\Inventory\Dashboard\InventoryBoard::short($reorder['blocked'])]) }}</span>
        @endif
    </section>

    <section data-inv-slow style="{{ $card }} gap: 0.5rem; border-top: 0.3125rem solid var(--color-dash-64748b)">
        <div style="display: flex; justify-content: space-between; align-items: baseline">
            <h2 style="margin: 0; font-size: 1rem; font-weight: 700">{{ __('inventory::board.s.title') }}</h2>
            <span style="font-size: 0.8125rem; font-weight: 800; color: var(--color-dash-475569)">{{ $slow['value'] }}</span>
        </div>
        <nav aria-label="{{ __('inventory::board.s.title') }}" style="display: flex; gap: 0.25rem; background: var(--color-dash-f1f5f9); border-radius: 0.625rem; padding: 0.1875rem">
            @foreach ($slow['picks'] as $f)
                <a href="{{ $f['href'] }}" data-inv-idle-pick @if ($f['on']) aria-current="true" @endif
                   style="flex: 1 1 0; height: 1.875rem; border-radius: 0.5rem; background: {{ $f['on'] ? 'var(--color-dash-ffffff)' : 'transparent' }}; color: {{ $f['on'] ? 'var(--color-dash-0f172a)' : 'var(--color-dash-64748b)' }}; font-size: 0.75rem; font-weight: 700; display: flex; align-items: center; justify-content: center; text-decoration: none">{{ $f['label'] }}</a>
            @endforeach
        </nav>
        <div style="display: flex; height: 1rem; border-radius: 999px; overflow: hidden; gap: 2px">
            <span style="flex: {{ $slow['bands']['0'] }}; background: var(--color-dash-22c55e)"></span><span style="flex: {{ $slow['bands']['15'] }}; background: var(--color-dash-facc15)"></span><span style="flex: {{ $slow['bands']['30'] }}; background: var(--color-dash-f97316)"></span><span style="flex: {{ $slow['bands']['60'] }}; background: var(--color-dash-ef4444)"></span><span style="flex: {{ $slow['bands']['90'] }}; background: var(--color-dash-475569)"></span>
        </div>
        <span style="font-size: 0.625rem; color: var(--color-dash-4b5563)"><b style="color: var(--color-dash-16a34a)">■</b> {{ __('inventory::board.s.b0') }} <b style="color: var(--color-dash-ca8a04)">■</b> 15–30 <b style="color: var(--color-dash-ea580c)">■</b> 31–60 <b style="color: var(--color-dash-dc2626)">■</b> 61–90 <b style="color: var(--color-dash-475569)">■</b> {{ __('inventory::board.s.b90') }}</span>
        <span style="font-size: 0.8125rem; font-weight: 700">{{ $slow['summary'] }}</span>
        @foreach ($slow['rows'] as $d)
            <div style="display: grid; grid-template-columns: minmax(0, 1fr) 3.375rem 4rem; gap: 0.375rem; font-size: 0.75rem; padding: 0.25rem 0; border-top: 1px solid var(--color-dash-f1f2f7); align-items: center">
                <span style="min-width: 0">{{ $d['name'] }}@if ($d['warehouse'] !== '') <span style="color: var(--color-dash-6b7280); font-size: 0.625rem">· {{ $d['warehouse'] }}</span>@endif
                    @if ($d['short']) <span style="font-size: 0.5625rem; font-weight: 700; color: var(--color-dash-ffffff); background: var(--color-dash-e11d48); border-radius: 0.3125rem; padding: 0 0.3125rem">{{ __('inventory::board.s.short') }}</span>@endif</span>
                <b style="text-align: right; color: {{ $d['tone'] }}">{{ $d['days'] }}</b><b style="text-align: right">{{ $d['value'] }}</b>
            </div>
        @endforeach
    </section>
</div>
