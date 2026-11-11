{{--
    ⭐ মজুদের ড্যাশবোর্ড, সারি 6 — মজুদের বয়স · গুদাম ধরে, সরবরাহকারী OTIF (ছড়ানো বিন্দু), ঢোকা ও বেরোনো · 30 দিন, গুদামের জায়গা কতটা ভরা
    (মালিকের চূড়ান্ত নমুনা HmzeE5CN2wvtyLPE5exjvV)।
    ⓘ OTIF-এর ছক নমুনার মাপে: x = 200 + (OTIF − 85) × 10 (85% দাগে), y = লিড দিন × 22, বৃত্ত সবচেয়ে বড় কেনার তুলনায় 8–20।
--}}
@php
    $card = 'background: var(--color-dash-ffffff); border-radius: 1.125rem; padding: 1.125rem 1.25rem; box-shadow: 0 1px 3px var(--color-dash-17-24-39-a06); display: flex; flex-direction: column; color: var(--color-dash-111827)';
    $short = fn (string $v) => \App\Modules\Inventory\Dashboard\InventoryBoard::short($v);
    $ageKey = explode('|', (string) __('inventory::board.y.ageing_key'));
    $agePaint = ['var(--color-dash-22c55e)', 'var(--color-dash-eab308)', 'var(--color-dash-f97316)', 'var(--color-dash-ef4444)'];

    $dotPaint = ['var(--color-dash-10b981)', 'var(--color-dash-6366f1)', 'var(--color-dash-f59e0b)', 'var(--color-dash-0ea5e9)', 'var(--color-dash-ef4444)', 'var(--color-dash-a855f7)'];
    $maxValue = array_reduce($suppliers, fn (float $c, array $s) => max($c, (float) $s['value']), 0.0);
    $dots = [];
    $worst = null;
    foreach ($suppliers as $i => $s) {
        if ($s['otif'] === null) { continue; }
        $r = $maxValue > 0 ? (int) round(8 + 12 * (float) $s['value'] / $maxValue) : 8;
        $x = max($r, min(340 - $r, 200 + ($s['otif'] - 85) * 10));
        $y = max($r, min(200 - $r - 12, ($s['lead'] ?? 0) * 22));
        $dots[] = ['x' => $x, 'y' => $y, 'r' => $r, 'ty' => $y + $r + 11, 'c' => $dotPaint[$i % count($dotPaint)], 'n' => $s['name'], 'otif' => $s['otif']];
        if ($s['otif'] < 85 && ($worst === null || $s['otif'] < $worst['otif'])) { $worst = $s; }
    }

    $peak = array_reduce($flow['days'], fn (float $c, array $d) => max($c, (float) $d['in'], (float) $d['out']), 0.0);
    $bar = fn (string $v) => $peak > 0 ? round((float) $v / $peak * 11.25, 3) : 0;
@endphp
<div data-inv-board-yard style="display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 1rem; width: 100%">
    <section data-inv-ageing style="{{ $card }} gap: 0.625rem; border-top: 0.3125rem solid var(--color-dash-f59e0b)">
        <h2 style="margin: 0; font-size: 1rem; font-weight: 700">{{ __('inventory::board.y.ageing') }}</h2>
        @forelse ($ageing as $g)
            <div style="display: grid; grid-template-columns: 5.125rem minmax(0, 1fr); gap: 0.625rem; align-items: center; font-size: 0.8125rem">
                <span>{{ $g['name'] }}</span>
                <span style="display: flex; height: 1.125rem; border-radius: 0.5rem; overflow: hidden; gap: 2px" title="{{ collect($g['parts'])->map(fn ($p, $k) => $ageKey[$k].' '.$p.'%')->implode(' · ') }}">
                    @foreach ($g['parts'] as $k => $part)
                        @if ($part > 0)<span style="flex: {{ $part }}; background: {{ $agePaint[$k] }}"></span>@endif
                    @endforeach
                </span>
            </div>
        @empty
            <span style="font-size: 0.8125rem; color: var(--color-dash-6b7280)">{{ __('inventory::board.y.ageing_none') }}</span>
        @endforelse
        <span style="font-size: 0.6875rem; color: var(--color-dash-4b5563)">@foreach ($ageKey as $k => $label)<b style="color: {{ $agePaint[$k] }}">■</b> {{ $label }} @endforeach</span>
    </section>

    <section data-inv-otif style="{{ $card }} gap: 0.375rem; border-top: 0.3125rem solid var(--color-dash-d946ef)">
        <h2 style="margin: 0; font-size: 1rem; font-weight: 700">{{ __('inventory::board.y.otif') }}</h2>
        @if ($dots === [])
            <span style="font-size: 0.8125rem; color: var(--color-dash-6b7280)">{{ __('inventory::board.y.otif_none') }}</span>
        @else
            <svg role="img" aria-label="{{ __('inventory::board.y.otif_label') }}" viewBox="0 0 340 220" style="width: 100%; height: 13.75rem">
                <rect x="200" y="0" width="140" height="100" fill="var(--color-dash-ecfdf5)"></rect><rect x="0" y="100" width="200" height="100" fill="var(--color-dash-fef2f2)"></rect>
                <line x1="200" y1="0" x2="200" y2="200" stroke="var(--color-dash-94a3b8)" stroke-dasharray="4 4"></line><line x1="0" y1="100" x2="340" y2="100" stroke="var(--color-dash-94a3b8)" stroke-dasharray="4 4"></line>
                <text x="334" y="14" text-anchor="end" font-size="10" font-weight="700" fill="var(--color-dash-047857)">{{ __('inventory::board.y.trusted') }}</text><text x="6" y="194" font-size="10" font-weight="700" fill="var(--color-dash-b91c1c)">{{ __('inventory::board.y.watch') }}</text>
                @foreach ($dots as $d)
                    <circle data-otif-dot cx="{{ $d['x'] }}" cy="{{ $d['y'] }}" r="{{ $d['r'] }}" fill="{{ $d['c'] }}" fill-opacity="0.8" stroke="var(--color-dash-ffffff)" stroke-width="2"><title>{{ $d['n'] }} · OTIF {{ $d['otif'] }}%</title></circle><text x="{{ $d['x'] }}" y="{{ $d['ty'] }}" text-anchor="middle" font-size="10" fill="var(--color-dash-111827)">{{ $d['n'] }}</text>
                @endforeach
                <text x="336" y="214" text-anchor="end" font-size="10" fill="var(--color-dash-6b7280)">{{ __('inventory::board.y.otif_axis') }}</text>
            </svg>
        @endif
        <span style="font-size: 0.6875rem; color: var(--color-dash-4b5563)">{{ __('inventory::board.y.otif_note') }}@if ($worst !== null) · {{ __('inventory::board.y.otif_worst', ['name' => $worst['name'], 'pct' => $worst['otif']]) }}@endif</span>
    </section>

    <section data-inv-daily-flow style="{{ $card }} gap: 0.5rem; border-top: 0.3125rem solid var(--color-dash-22c55e)">
        <h2 style="margin: 0; font-size: 1rem; font-weight: 700">{{ __('inventory::board.y.flow') }}</h2>
        <div style="display: flex; align-items: flex-end; gap: 3px; height: 11.875rem; border-bottom: 1px solid var(--color-dash-e5e7f0)">
            @foreach ($flow['days'] as $d)
                <div title="{{ \Illuminate\Support\Carbon::parse($d['date'])->format('j M') }} · {{ __('inventory::board.y.in') }} {{ $short($d['in']) }} · {{ __('inventory::board.y.out') }} {{ $short($d['out']) }}" style="flex: 1 1 0; display: flex; gap: 1px; align-items: flex-end; height: 100%"><span style="flex: 1 1 0; height: {{ $bar($d['in']) }}rem; background: linear-gradient(180deg, var(--color-dash-4ade80), var(--color-dash-16a34a)); border-radius: 3px 3px 0 0"></span><span style="flex: 1 1 0; height: {{ $bar($d['out']) }}rem; background: linear-gradient(180deg, var(--color-dash-fdba74), var(--color-dash-ea580c)); border-radius: 3px 3px 0 0"></span></div>
            @endforeach
        </div>
        <span style="font-size: 0.75rem; color: var(--color-dash-4b5563)"><b style="color: var(--color-dash-16a34a)">■</b> {{ __('inventory::board.y.in') }} {{ $short($flow['in']) }} &nbsp; <b style="color: var(--color-dash-ea580c)">■</b> {{ __('inventory::board.y.out') }} {{ $short($flow['out']) }}@if ($flow['quiet'] !== null) · {{ __('inventory::board.y.quiet', ['day' => __('inventory::board.y.weekdays')[$flow['quiet']]]) }}@endif</span>
    </section>

    <section data-inv-space style="{{ $card }} gap: 0.5rem; border-top: 0.3125rem solid var(--color-dash-0ea5e9)">
        <h2 style="margin: 0; font-size: 1rem; font-weight: 700">{{ __('inventory::board.y.space') }}</h2>
        @foreach ($space as $s)
            @php
                $tone = $s['pct'] === null ? null : ($s['pct'] > 85 ? ['var(--color-dash-dc2626)', 'var(--color-dash-fca5a5)', 'var(--color-dash-dc2626)'] : ($s['pct'] >= 75 ? ['var(--color-dash-ca8a04)', 'var(--color-dash-fde047)', 'var(--color-dash-eab308)'] : ['var(--color-dash-16a34a)', 'var(--color-dash-86efac)', 'var(--color-dash-16a34a)']));
            @endphp
            <div style="display: flex; flex-direction: column; gap: 3px; font-size: 0.75rem">
                <span style="display: flex; justify-content: space-between"><span>{{ $s['name'] }}</span>
                    @if ($tone === null)
                        <a href="{{ $s['edit'] }}" style="color: var(--color-dash-6b7280); text-decoration: underline">{{ __('inventory::board.y.space_unset') }}</a>
                    @else
                        <b style="color: {{ $tone[0] }}">{{ $s['pct'] }}%</b>
                    @endif
                </span>
                <span style="position: relative; height: 0.875rem; border-radius: 999px; background: var(--color-dash-f1f2f7)">@if ($tone !== null)<span style="position: absolute; left: 0; top: 0; height: 0.875rem; width: {{ min(100, $s['pct']) }}%; background: linear-gradient(90deg, {{ $tone[1] }}, {{ $tone[2] }}); border-radius: 999px"></span>@endif<span style="position: absolute; left: 85%; top: -3px; width: 2px; height: 1.25rem; background: var(--color-dash-111827)"></span></span>
            </div>
        @endforeach
        <span style="font-size: 0.6875rem; color: var(--color-dash-4b5563)">{{ __('inventory::board.y.space_note') }}</span>
    </section>
</div>
