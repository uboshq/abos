{{--
    ⭐ অর্থের ড্যাশবোর্ড, সারি 2 — তারল্য আগে: 13 সপ্তাহের নগদের পূর্বাভাস (দুই ভাগ চওড়া) আর নগদ প্রবাহ বিবরণী · এ মাস (IAS 7)
    (মালিকের চূড়ান্ত নমুনা 4h63xhNiy4m9KaJkZZQaEL)। ⓘ অঙ্ক ইংরেজিতে; লাখের নিচে হাজারে।
    ⓘ পূর্বাভাসের ছক নমুনার মাপে: x = 30 + সপ্তাহ × 54, তহবিল 15–205-এর মধ্যে, নিট বার 262-র দাগ থেকে (সবচেয়ে বড় 50)।
--}}
@php
    $card = 'background: var(--color-dash-ffffff); border-radius: 1.125rem; padding: 1.125rem 1.25rem; display: flex; flex-direction: column; gap: 0.5rem; color: var(--color-dash-111827)';
    $short = function (string $v, bool $sign = false): string {
        $neg = bccomp($v, '0', 4) < 0;
        $a = ltrim($v, '-');
        $s = $neg ? '−' : ($sign && bccomp($a, '0', 4) > 0 ? '+' : '');
        if (bccomp($a, '10000000', 4) >= 0) { return $s.'৳'.bcdiv($a, '10000000', 2).' '.__('finance::board.crore'); }
        if (bccomp($a, '100000', 4) >= 0) { return $s.'৳'.bcdiv($a, '100000', 1).' '.__('finance::board.lakh'); }
        if (bccomp($a, '1000', 4) >= 0) { return $s.'৳'.bcdiv($a, '1000', 0).'k'; }
        return $s.'৳'.bcadd($a, '0', 0);
    };

    $pts = array_map('floatval', $forecast['points']);
    $floor = (float) $forecast['floor'];
    $hi = max(max($pts), $floor) * 1.1 ?: 1.0;
    $lo = min(0.0, min($pts));
    $fx = fn (int $i) => 30 + $i * 54;
    $fy = fn (float $v) => round(205 - ($v - $lo) / ($hi - $lo) * 190, 1);
    $line = implode(' ', array_map(fn ($v, $i) => $fx($i).','.$fy($v), $pts, array_keys($pts)));
    $area = 'M'.$fx(0).',205 '.implode(' ', array_map(fn ($v, $i) => 'L'.$fx($i).','.$fy($v), $pts, array_keys($pts))).' L'.$fx(count($pts) - 1).',205 Z';
    $floorY = $fy($floor);
    $nets = array_map(fn ($w) => (float) $w['net'], $forecast['weeks']);
    $peak = max(1.0, ...array_map('abs', $nets));
    $low = $forecast['weeks'][$forecast['low']] ?? null;
    $lowValue = $low === null ? null : (float) $low['closing'];
    $date = fn (string $d) => \Illuminate\Support\Carbon::parse($d)->format('j').' '.__('finance::board.months_short')[(int) \Illuminate\Support\Carbon::parse($d)->format('n')];
    $moved = array_filter($nets, fn ($n) => $n != 0.0) !== [];
    $dips = $low !== null && $lowValue < $pts[0];

    $cf = $cashFlow;
    if ($cf !== null) {
        $o = (float) $cf['opening'];
        $steps = array_map(fn ($s) => (float) $s['net'], $cf['sections']);
        $c = (float) $cf['closing'];
        $run = [$o];
        foreach ($steps as $s) { $run[] = end($run) + $s; }
        $top = max(1.0, $o, $c, ...$run);
        $px = fn (float $v) => max(0, round($v / $top * 200));
        $paint = [
            ['var(--color-dash-cbd5e1)', 'var(--color-dash-64748b)', 'var(--color-dash-475569)'],
            ['var(--color-dash-6ee7b7)', 'var(--color-dash-059669)', 'var(--color-dash-047857)'],
            ['var(--color-dash-fdba74)', 'var(--color-dash-ea580c)', 'var(--color-dash-c2410c)'],
            ['var(--color-dash-c4b5fd)', 'var(--color-dash-7c3aed)', 'var(--color-dash-6d28d9)'],
            ['var(--color-dash-818cf8)', 'var(--color-dash-4338ca)', 'var(--color-dash-3730a3)'],
        ];
        $bars = [['t' => __('finance::board.cf.opening'), 'v' => $short($cf['opening']), 'h' => $px($o), 'mb' => 0]];
        foreach (['operating', 'investing', 'financing'] as $k => $key) {
            $before = $run[$k];
            $after = $run[$k + 1];
            $bars[] = ['t' => __('finance::board.cf.'.$key), 'v' => $short($cf['sections'][$k]['net'], true), 'h' => max(2, $px(abs($after - $before))), 'mb' => $px(min($before, $after))];
        }
        $bars[] = ['t' => __('finance::board.cf.closing'), 'v' => $short($cf['closing']), 'h' => $px($c), 'mb' => 0];
        $change = bcsub($cf['closing'], $cf['opening'], 4);
        $soft = [['var(--color-dash-ecfdf5)', 'var(--color-dash-047857)'], ['var(--color-dash-fff7ed)', 'var(--color-dash-c2410c)'], ['var(--color-dash-f5f3ff)', 'var(--color-dash-6d28d9)']];
        $lakh = fn (string $v) => (bccomp($v, '0', 4) >= 0 ? '+' : '−').number_format(abs((float) $v) / 100000, 2, '.', '');
    }
@endphp
<div data-fin-board-liquidity style="display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 1rem; width: 100%">
    <section data-fin-forecast style="grid-column: span 2; {{ $card }} box-shadow: 0 0.5rem 1.5rem var(--color-dash-239-68-68-a10); border-top: 0.3125rem solid var(--color-dash-ef4444)">
        <div style="display: flex; justify-content: space-between; align-items: baseline; gap: 0.5rem; flex-wrap: wrap">
            <h2 style="margin: 0; font-size: 1.0625rem; font-weight: 800">{{ __('finance::board.fc.title') }}</h2>
            <span style="font-size: 0.75rem; color: var(--color-dash-4b5563)"><b style="color: var(--color-dash-4f46e5)">—</b> {{ __('finance::board.fc.line') }} &nbsp; <b style="color: var(--color-dash-16a34a)">■</b> {{ __('finance::board.fc.up') }} &nbsp; <b style="color: var(--color-dash-ef4444)">■</b> {{ __('finance::board.fc.down') }}</span>
        </div>
        <svg role="img" aria-label="{{ __('finance::board.fc.label') }}" viewBox="0 0 760 330" style="width: 100%; height: 20.625rem">
            <defs><linearGradient id="finFcFill" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="var(--color-dash-6366f1)" stop-opacity="0.45"></stop><stop offset="1" stop-color="var(--color-dash-6366f1)" stop-opacity="0.02"></stop></linearGradient></defs>
            @if ($floor > 0)
                <rect x="0" y="{{ $floorY }}" width="760" height="{{ max(0, 205 - $floorY) }}" fill="var(--color-dash-fef2f2)"></rect>
                <line x1="0" y1="{{ $floorY }}" x2="760" y2="{{ $floorY }}" stroke="var(--color-dash-ef4444)" stroke-dasharray="6 4"></line>
                <text x="755" y="{{ $floorY - 5 }}" text-anchor="end" font-size="11" fill="var(--color-dash-dc2626)">{{ __('finance::board.fc.floor', ['value' => $short($forecast['floor'])]) }}</text>
            @endif
            <path d="{{ $area }}" fill="url(#finFcFill)"></path>
            <polyline points="{{ $line }}" fill="none" stroke="var(--color-dash-4f46e5)" stroke-width="3.5" stroke-linejoin="round"></polyline>
            @foreach ($pts as $i => $v)
                @php $danger = $floor > 0 && $v < $floor; @endphp
                <circle data-fc-dot cx="{{ $fx($i) }}" cy="{{ $fy($v) }}" r="{{ $danger ? 8 : 4 }}" fill="{{ $danger ? 'var(--color-dash-ef4444)' : 'var(--color-dash-4f46e5)' }}" stroke="var(--color-dash-ffffff)" stroke-width="2"><title>{{ $i === 0 ? __('finance::board.fc.today') : $date($forecast['weeks'][$i - 1]['to']) }} · {{ $short($forecast['points'][$i]) }}</title></circle>
            @endforeach
            @if ($dips && $moved)
                <text x="{{ min(700, max(60, $fx($forecast['low'] + 1))) }}" y="{{ min(200, $fy($lowValue) + 25) }}" text-anchor="middle" font-size="12" font-weight="800" fill="{{ $lowValue < $floor ? 'var(--color-dash-b91c1c)' : 'var(--color-dash-4f46e5)' }}">{{ $date($low['to']) }}: {{ $short($low['closing']) }}</text>
            @endif
            <line x1="0" y1="262" x2="760" y2="262" stroke="var(--color-dash-cbd5e1)"></line>
            @foreach ($nets as $i => $n)
                @php $h = max(2, round(abs($n) / $peak * 50, 1)); @endphp
                <rect data-fc-bar x="{{ $fx($i + 1) - 15 }}" y="{{ $n >= 0 ? 262 - $h : 262 }}" width="30" height="{{ $h }}" rx="4" fill="{{ $n >= 0 ? 'var(--color-dash-22c55e)' : 'var(--color-dash-ef4444)' }}"><title>{{ $short($forecast['weeks'][$i]['net'], true) }}</title></rect>
            @endforeach
            @foreach ($pts as $i => $v)
                <text x="{{ $fx($i) }}" y="324" text-anchor="middle" font-size="10" fill="var(--color-dash-6b7280)">{{ $i === 0 ? __('finance::board.fc.today') : __('finance::board.fc.week', ['n' => $i]) }}</text>
            @endforeach
        </svg>
        <p style="margin: 0; font-size: 0.8125rem; line-height: 1.6; background: linear-gradient(135deg, var(--color-dash-fef2f2), var(--color-dash-ffe4e6)); border-radius: 0.75rem; padding: 0.5rem 0.625rem">
            @if (! $moved || $low === null)
                {{ __('finance::board.fc.quiet') }}
            @elseif (! $dips)
                {{ __('finance::board.fc.steady', ['value' => $short($forecast['points'][0])]) }}
            @else
                <b>{{ __('finance::board.fc.tight', ['n' => $forecast['low'] + 1, 'range' => $date($low['from']).'–'.$date($low['to'])]) }}</b>@if ($low['biggest'] !== null) — {{ __('finance::board.fc.tight_who', ['who' => $low['biggest']['who'], 'amount' => $short($low['biggest']['amount'])]) }}@endif.
                {{ __($lowValue < $floor ? 'finance::board.fc.below' : 'finance::board.fc.above', ['value' => $short($low['closing'])]) }}
            @endif
        </p>
    </section>

    @if ($cf !== null)
        <section data-fin-cash-flow style="grid-column: span 2; {{ $card }} box-shadow: 0 0.5rem 1.5rem var(--color-dash-16-185-129-a10); border-top: 0.3125rem solid var(--color-dash-10b981)">
            <div style="display: flex; justify-content: space-between; align-items: baseline; gap: 0.5rem; flex-wrap: wrap">
                <h2 style="margin: 0; font-size: 1.0625rem; font-weight: 800">{{ __('finance::board.cf.title') }}</h2>
                <span style="font-size: 0.8125rem; font-weight: 800; color: {{ bccomp($change, '0', 4) >= 0 ? 'var(--color-dash-059669)' : 'var(--color-dash-dc2626)' }}">{{ __('finance::board.cf.change', ['value' => $short($change, true)]) }}</span>
            </div>
            <div style="display: flex; align-items: flex-end; gap: 1.125rem; height: 14.375rem; border-bottom: 1px solid var(--color-dash-e5e7f0); padding: 0 0.5rem">
                @foreach ($bars as $i => $b)
                    <div data-cf-bar style="flex: 1 1 0; height: 14.375rem; display: flex; flex-direction: column; justify-content: flex-end; align-items: center; gap: 3px">
                        <span style="font-size: 0.8125rem; font-weight: 800; color: {{ $paint[$i][2] }}">{{ $b['v'] }}</span>
                        <span style="width: 100%; height: {{ $b['h'] / 16 }}rem; margin-bottom: {{ $b['mb'] / 16 }}rem; background: linear-gradient(180deg, {{ $paint[$i][0] }}, {{ $paint[$i][1] }}); border-radius: 0.625rem"></span>
                    </div>
                @endforeach
            </div>
            <div style="display: flex; gap: 1.125rem; padding: 0 0.5rem; font-size: 0.75rem; font-weight: 600; color: var(--color-dash-374151)">
                @foreach ($bars as $b)<span style="flex: 1 1 0; text-align: center">{{ $b['t'] }}</span>@endforeach
            </div>
            <div style="display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 0.5rem; font-size: 0.75rem">
                @foreach ($cf['sections'] as $k => $s)
                    <span style="background: {{ $soft[$k][0] }}; border-radius: 0.625rem; padding: 0.375rem 0.5rem"><b style="color: {{ $soft[$k][1] }}">{{ __('finance::board.cf.'.$s['key'].'_short') }} {{ $lakh($s['net']) }}</b><br>@if ($s['lines'] === []){{ __('finance::board.cf.nothing') }}@else{{ collect($s['lines'])->map(fn ($l) => $l['name'].' '.$lakh($l['net']))->implode(' · ') }}@endif</span>
                @endforeach
            </div>
        </section>
    @endif
</div>
