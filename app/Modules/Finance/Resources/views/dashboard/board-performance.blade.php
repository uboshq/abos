{{--
    ⭐ অর্থের ড্যাশবোর্ড, সারি 4 — বিক্রি ও লাভের হার · 12 মাস (দুই ভাগ চওড়া), বাজেট থেকে ফারাক, পরিশোধের ক্যালেন্ডার · 5 সপ্তাহ
    (মালিকের চূড়ান্ত নমুনা 4h63xhNiy4m9KaJkZZQaEL)। ⓘ বার x = 22 + মাস × 62, 180-র দাগ থেকে; গ্রস রেখা 196–230, নিট 250–284 পট্টিতে।
    ⓘ ক্যালেন্ডার এই সপ্তাহের শনিবার থেকে 35 দিন; রং দিনের দেনা সবচেয়ে বড় দিনের তুলনায়।
--}}
@php
    $card = 'background: var(--color-dash-ffffff); border-radius: 1.125rem; padding: 1.125rem 1.25rem; box-shadow: 0 1px 3px var(--color-dash-17-24-39-a06); display: flex; flex-direction: column; color: var(--color-dash-111827)';
    $short = function (string $v): string {
        $a = ltrim($v, '-');
        $s = bccomp($v, '0', 4) < 0 ? '−' : '';
        if (bccomp($a, '10000000', 4) >= 0) { return $s.'৳'.bcdiv($a, '10000000', 2).' '.__('finance::board.crore'); }
        if (bccomp($a, '100000', 4) >= 0) { return $s.'৳'.bcdiv($a, '100000', 1).' '.__('finance::board.lakh'); }
        if (bccomp($a, '1000', 4) >= 0) { return $s.'৳'.bcdiv($a, '1000', 0).'k'; }
        return $s.'৳'.bcadd($a, '0', 0);
    };
    $monthShort = __('finance::board.months_short');
    $ms = $performance['months'];
    $top = max(1.0, ...array_map(fn ($m) => (float) $m['sales'], $ms));
    $crore = $top >= 10000000;
    $unit = $crore ? 10000000 : 100000;
    $rx = fn (int $i) => 22 + $i * 62;
    $band = function (array $values, float $y0, float $y1): string {
        $vals = array_filter($values, fn ($v) => $v !== null);
        if ($vals === []) { return ''; }
        $hi = max($vals); $lo = min($vals); $span = max(0.1, $hi - $lo);
        $pts = [];
        foreach ($values as $i => $v) { if ($v !== null) { $pts[] = (22 + $i * 62 + 19).','.round($y1 - ((float) $v - $lo) / $span * ($y1 - $y0), 1); } }
        return implode(' ', $pts);
    };
    $gross = array_map(fn ($m) => $m['gross'] === null ? null : (float) $m['gross'], $ms);
    $net = array_map(fn ($m) => $m['net'] === null ? null : (float) $m['net'], $ms);
    $last = end($ms);

    $budget = $performance['budget'];
    $adverse = $budget === null ? 0 : count(array_filter($budget, fn ($r) => ! $r['good']));

    $start = \Illuminate\Support\Carbon::today()->startOfWeek(\Illuminate\Support\Carbon::SATURDAY);
    $dues = $outgoing;
    $dayTotals = array_map(fn ($items) => array_reduce($items, fn (string $c, array $x) => bcadd($c, $x['amount'], 4), '0'), $dues);
    $peak = max(1.0, ...array_map('floatval', array_values($dayTotals) ?: ['0']));
    $cal = [];
    for ($i = 0; $i < 35; $i++) {
        $d = $start->copy()->addDays($i);
        $key = $d->toDateString();
        $amount = $dayTotals[$key] ?? null;
        $share = $amount === null ? null : (float) $amount / $peak;
        $cal[] = [
            'n' => $d->day, 'a' => $amount === null ? '' : $short($amount), 'past' => $d->lt(\Illuminate\Support\Carbon::today()),
            'bg' => $d->lt(\Illuminate\Support\Carbon::today()) ? 'var(--color-dash-f8fafc)' : ($share === null ? 'var(--color-dash-f1f5f9)' : ($share < 0.15 ? 'var(--color-dash-fecdd3)' : ($share < 0.45 ? 'var(--color-dash-fb7185)' : ($share < 0.85 ? 'var(--color-dash-e11d48)' : 'var(--color-dash-881337)')))),
            'ink' => $share !== null && $share >= 0.15 ? 'var(--color-dash-ffffff)' : 'var(--color-dash-475569)',
            'title' => $amount === null ? '' : collect($dues[$key])->map(fn ($x) => ($x['side'] === 'loans_out' ? __('finance::board.perf.loan').' · ' : '').$x['who'].' '.$short($x['amount']))->implode(' · '),
        ];
    }
    $biggest = collect($dues)->flatMap(fn ($items, $day) => array_map(fn ($x) => $x + ['day' => $day], $items))
        ->sortByDesc(fn ($x) => (float) $x['amount'])->take(3)->values();
    $date = fn (string $d) => \Illuminate\Support\Carbon::parse($d)->day.' '.$monthShort[\Illuminate\Support\Carbon::parse($d)->month];
@endphp
<div data-fin-board-performance style="display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 1rem; width: 100%">
    <section data-fin-margins style="grid-column: span 2; {{ $card }} gap: 0.375rem; border-top: 0.3125rem solid var(--color-dash-0ea5e9)">
        <div style="display: flex; justify-content: space-between; align-items: baseline; gap: 0.5rem; flex-wrap: wrap">
            <h2 style="margin: 0; font-size: 1rem; font-weight: 700">{{ __('finance::board.perf.title') }}</h2>
            <span style="font-size: 0.75rem; color: var(--color-dash-4b5563)"><b style="color: var(--color-dash-0ea5e9)">■</b> {{ __('finance::board.perf.sales', ['unit' => __($crore ? 'finance::board.perf.crore_word' : 'finance::board.perf.lakh_word')]) }} &nbsp; <b style="color: var(--color-dash-f59e0b)">—</b> {{ __('finance::board.perf.gross') }} &nbsp; <b style="color: var(--color-dash-7c3aed)">—</b> {{ __('finance::board.perf.net') }}</span>
        </div>
        <svg role="img" aria-label="{{ __('finance::board.perf.label') }}" viewBox="0 0 760 300" style="width: 100%; height: 18.75rem">
            <defs><linearGradient id="finRevBar" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="var(--color-dash-38bdf8)"></stop><stop offset="1" stop-color="var(--color-dash-0284c7)"></stop></linearGradient></defs>
            @foreach ($ms as $i => $m)
                @php $h = max(2, round((float) $m['sales'] / $top * 160)); @endphp
                <rect data-rev-bar x="{{ $rx($i) }}" y="{{ 180 - $h }}" width="38" height="{{ $h }}" rx="6" fill="url(#finRevBar)" fill-opacity="{{ $m['estimate'] ? '0.45' : '1' }}"><title>{{ $short($m['sales']) }}@if ($m['gross'] !== null) · {{ __('finance::board.perf.gross_now', ['n' => $m['gross']]) }}@endif @if ($m['net'] !== null) · {{ __('finance::board.perf.net_now', ['n' => $m['net']]) }}@endif</title></rect>
                <text x="{{ $rx($i) + 19 }}" y="{{ 174 - $h }}" text-anchor="middle" font-size="10" font-weight="700" fill="var(--color-dash-0c4a6e)">{{ number_format((float) $m['sales'] / $unit, 1, '.', '') }}</text>
                <text x="{{ $rx($i) + 19 }}" y="296" text-anchor="middle" font-size="10" fill="var(--color-dash-6b7280)">{{ $monthShort[$m['month']] }}</text>
            @endforeach
            <line x1="0" y1="190" x2="760" y2="190" stroke="var(--color-dash-e5e7f0)"></line>
            <text x="4" y="206" font-size="10" fill="var(--color-dash-6b7280)">{{ __('finance::board.perf.margin') }}</text>
            @if ($band($gross, 196, 230) !== '')<polyline points="{{ $band($gross, 196, 230) }}" fill="none" stroke="var(--color-dash-f59e0b)" stroke-width="3"></polyline>@endif
            @if ($band($net, 250, 284) !== '')<polyline points="{{ $band($net, 250, 284) }}" fill="none" stroke="var(--color-dash-7c3aed)" stroke-width="3"></polyline>@endif
            @if ($last['gross'] !== null)<text x="752" y="216" text-anchor="end" font-size="11" font-weight="700" fill="var(--color-dash-b45309)">{{ __('finance::board.perf.gross_now', ['n' => $last['gross']]) }}</text>@endif
            @if ($last['net'] !== null)<text x="752" y="262" text-anchor="end" font-size="11" font-weight="700" fill="var(--color-dash-6d28d9)">{{ __('finance::board.perf.net_now', ['n' => $last['net']]) }}</text>@endif
        </svg>
        @if ($last['estimate'])
            <span style="font-size: 0.75rem; color: var(--color-dash-4b5563)">{{ __('finance::board.perf.estimate', ['month' => __('finance::board.perf.months')[$last['month']], 'days' => $performance['day']]) }}</span>
        @endif
    </section>

    @if ($budget !== null)
        <section data-fin-budget style="{{ $card }} gap: 0.5rem; border-top: 0.3125rem solid var(--color-dash-f59e0b)">
            <div style="display: flex; justify-content: space-between; align-items: baseline; gap: 0.5rem">
                <h2 style="margin: 0; font-size: 1rem; font-weight: 700">{{ __('finance::board.perf.budget') }}</h2>
                @if ($adverse > 0)<span style="font-size: 0.75rem; font-weight: 700; color: var(--color-dash-ffffff); background: var(--color-dash-ef4444); border-radius: 999px; padding: 2px 0.5rem">{{ __('finance::board.perf.adverse', ['n' => $adverse]) }}</span>@endif
            </div>
            @if ($budget === [])
                <span style="font-size: 0.8125rem; color: var(--color-dash-6b7280)">{{ __('finance::board.perf.no_budget') }}</span>
            @else
                <span style="font-size: 0.6875rem; color: var(--color-dash-6b7280)">{{ __('finance::board.perf.budget_note', ['days' => $performance['day']]) }}</span>
                @foreach ($budget as $v)
                    @php
                        $p = $v['pct'] ?? 0;
                        $w = min(48, abs($p) * 2);
                        $c = $p === 0 ? 'var(--color-dash-64748b)' : ($v['good'] ? 'var(--color-dash-16a34a)' : 'var(--color-dash-dc2626)');
                    @endphp
                    <div data-variance style="display: grid; grid-template-columns: 5.75rem minmax(0, 1fr) 3rem; gap: 0.5rem; align-items: center; font-size: 0.75rem">
                        <span style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap" title="{{ $v['name'] }}">{{ $v['name'] }}</span>
                        <span style="position: relative; height: 1rem; background: var(--color-dash-f1f2f7); border-radius: 0.375rem">
                            <span style="position: absolute; left: 50%; top: -3px; width: 2px; height: 1.375rem; background: var(--color-dash-111827)"></span>
                            <span style="position: absolute; top: 2px; height: 0.75rem; left: {{ $p >= 0 ? 50 : 50 - $w }}%; width: {{ max($w, 1) }}%; background: {{ $c }}; border-radius: 0.25rem"></span>
                        </span>
                        <b style="text-align: right; color: {{ $c }}">{{ $v['pct'] === null ? '—' : ($p > 0 ? '+' : '').$p.'%' }}</b>
                    </div>
                @endforeach
                <span style="font-size: 0.6875rem; color: var(--color-dash-6b7280)"><b style="color: var(--color-dash-16a34a)">{{ __('finance::board.perf.budget_key') }}</b> {{ __('finance::board.perf.favourable') }} · <b style="color: var(--color-dash-dc2626)">{{ __('finance::board.perf.budget_key_bad') }}</b> {{ __('finance::board.perf.adverse_note') }}</span>
            @endif
        </section>
    @endif

    <section data-fin-calendar style="{{ $card }} gap: 0.5rem; border-top: 0.3125rem solid var(--color-dash-e11d48)">
        <h2 style="margin: 0; font-size: 1rem; font-weight: 700">{{ __('finance::board.perf.calendar') }}</h2>
        <div style="display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 0.25rem; font-size: 0.625rem; text-align: center; color: var(--color-dash-6b7280)">
            @foreach (__('finance::board.perf.weekdays') as $wd)<span>{{ $wd }}</span>@endforeach
            @foreach ($cal as $d)
                <span data-cal-day @if ($d['title'] !== '') title="{{ $d['title'] }}" @endif style="height: 2.125rem; border-radius: 0.4375rem; background: {{ $d['bg'] }}; color: {{ $d['ink'] }}; display: flex; flex-direction: column; align-items: center; justify-content: center; font-size: 0.5625rem; line-height: 1.1; overflow: hidden"><b style="font-size: 0.6875rem">{{ $d['n'] }}</b>{{ $d['a'] }}</span>
            @endforeach
        </div>
        <div style="display: flex; flex-direction: column; gap: 3px; font-size: 0.6875rem">
            @forelse ($biggest as $b)
                <span><b style="color: var(--color-dash-be123c)">{{ $date($b['day']) }}</b> {{ $b['side'] === 'loans_out' ? __('finance::board.perf.loan').' · ' : '' }}{{ $b['who'] }} {{ $short($b['amount']) }}</span>
            @empty
                <span style="color: var(--color-dash-6b7280)">{{ __('finance::board.perf.nothing_due') }}</span>
            @endforelse
        </div>
    </section>
</div>
