{{--
    ⭐ বিক্রয়ের ড্যাশবোর্ড, সারি 3 — লক্ষ্যের পথে (দিন ধরে জমা বিক্রি, লক্ষ্যের পথ, গত বছর, পূর্বাভাস) আর PVM সেতু
    (মালিকের চূড়ান্ত নমুনা H8JXFPzh8m7zpsQSedmPDT)। ⓘ রেখার এক মাপ — সবচেয়ে বড়টা (লক্ষ্য, গত বছর, পূর্বাভাস) 220 পিক্সেল উঁচু।
--}}
@php
    $card = 'background: var(--color-dash-ffffff); border-radius: 1.125rem; padding: 1.125rem 1.25rem; box-shadow: 0 1px 3px var(--color-dash-17-24-39-a06); display: flex; flex-direction: column; color: var(--color-dash-111827)';
    $short = fn (string $v) => \App\Modules\Sales\Dashboard\SalesBoard::short($v);
    $c = $cumulative;
    $top = max(1.0, (float) ($c['target'] ?? 0), (float) $c['forecast'], (float) (end($c['last_year']) ?: 0), (float) (end($c['now']) ?: 0));
    $x = fn (float $day) => round(($day) / max(1, $c['days']) * 760, 1);
    $y = fn (float $v) => round(250 - $v / $top * 220, 1);
    $line = fn (array $vals) => '0,250 '.implode(' ', array_map(fn ($v, $i) => $x($i + 1).','.$y($v), $vals, array_keys($vals)));
    $nowLine = $line($c['now']);
    $lyLine = $line($c['last_year']);
    $doneNow = (float) (end($c['now']) ?: 0);
    $area = 'M0,250 '.implode(' ', array_map(fn ($v, $i) => 'L'.$x($i + 1).','.$y($v), $c['now'], array_keys($c['now']))).' L'.$x($c['today']).',250 Z';
    $monthShort = __('sales::board.months_short');
    $mon = $monthShort[\Illuminate\Support\Carbon::today()->month];

    $p = $pvm;
    $steps = [
        ['t' => $p['from_label'], 'v' => (float) $p['before'], 'total' => true, 'bg' => ['var(--color-dash-cbd5e1)', 'var(--color-dash-64748b)'], 'c' => 'var(--color-dash-475569)'],
        ['t' => __('sales::board.r3.volume'), 'v' => (float) $p['volume'], 'bg' => ['var(--color-dash-86efac)', 'var(--color-dash-16a34a)'], 'c' => 'var(--color-dash-15803d)'],
        ['t' => __('sales::board.r3.price'), 'v' => (float) $p['price'], 'bg' => ['var(--color-dash-7dd3fc)', 'var(--color-dash-0284c7)'], 'c' => 'var(--color-dash-0369a1)'],
        ['t' => __('sales::board.r3.mix'), 'v' => (float) $p['mix'], 'bg' => ['var(--color-dash-c4b5fd)', 'var(--color-dash-7c3aed)'], 'c' => 'var(--color-dash-6d28d9)'],
        ['t' => __('sales::board.r3.new'), 'v' => (float) $p['new'], 'bg' => ['var(--color-dash-f9a8d4)', 'var(--color-dash-db2777)'], 'c' => 'var(--color-dash-be185d)'],
        ['t' => __('sales::board.r3.dropped'), 'v' => (float) $p['dropped'], 'bg' => ['var(--color-dash-fca5a5)', 'var(--color-dash-dc2626)'], 'c' => 'var(--color-dash-b91c1c)'],
        ['t' => $p['to_label'], 'v' => (float) $p['after'], 'total' => true, 'bg' => ['var(--color-dash-6ee7b7)', 'var(--color-dash-059669)'], 'c' => 'var(--color-dash-047857)'],
    ];
    $run = [(float) $p['before']];
    foreach (array_slice($steps, 1, 5) as $s) { $run[] = end($run) + $s['v']; }
    $lo = min($run + [(float) $p['after']]); $hi = max($run + [(float) $p['after']]);
    $base = $lo > 0 ? $lo * 0.85 : 0.0;
    $span = max(1.0, $hi - $base);
    $px = fn (float $v) => max(0, round(($v - $base) / $span * 250));
    $bars = [];
    foreach ($steps as $i => $s) {
        if ($s['total'] ?? false) { $bars[] = $s + ['h' => max(2, $px($s['v'])), 'mb' => 0]; continue; }
        $a = $run[$i - 1]; $b = $run[$i];
        $bars[] = $s + ['h' => max(2, abs($px($b) - $px($a))), 'mb' => min($px($a), $px($b))];
    }
@endphp
<div data-sales-board-path style="display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 1rem; width: 100%">
    <section data-sales-path style="grid-column: span 2; {{ $card }} gap: 0.5rem; border-top: 0.25rem solid var(--color-dash-10b981)">
        <div style="display: flex; justify-content: space-between; align-items: baseline; gap: 0.5rem; flex-wrap: wrap">
            <h2 style="margin: 0; font-size: 1rem; font-weight: 700">{{ __('sales::board.r3.path') }}</h2>
            <span style="font-size: 0.75rem; color: var(--color-dash-4b5563)"><b style="color: var(--color-dash-10b981)">—</b> {{ __('sales::board.r3.this_month') }} &#160; @if ($c['target'] !== null)<b style="color: var(--color-dash-94a3b8)">- -</b> {{ __('sales::board.r3.target_path') }} &#160; @endif<b style="color: var(--color-dash-f59e0b)">—</b> {{ __('sales::board.r3.last_year') }} &#160; <b style="color: var(--color-dash-8b5cf6)">· ·</b> {{ __('sales::board.r3.forecast') }}</span>
        </div>
        <svg role="img" aria-label="{{ __('sales::board.r3.path_label') }}" viewBox="0 0 760 270" style="width: 100%; height: 16.875rem">
            <defs><linearGradient id="salesPathFill" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="var(--color-dash-10b981)" stop-opacity="0.4"></stop><stop offset="1" stop-color="var(--color-dash-10b981)" stop-opacity="0"></stop></linearGradient></defs>
            <line x1="0" y1="250" x2="760" y2="250" stroke="var(--color-dash-e5e7f0)"></line>
            @if ($c['target'] !== null)<polyline points="0,250 760,{{ $y((float) $c['target']) }}" fill="none" stroke="var(--color-dash-94a3b8)" stroke-width="2" stroke-dasharray="7 5"></polyline>@endif
            @if (count($c['last_year']) > 1)<polyline points="{{ $lyLine }}" fill="none" stroke="var(--color-dash-f59e0b)" stroke-width="2.5"></polyline>@endif
            <path d="{{ $area }}" fill="url(#salesPathFill)"></path>
            <polyline points="{{ $nowLine }}" fill="none" stroke="var(--color-dash-10b981)" stroke-width="4"></polyline>
            <polyline points="{{ $x($c['today']) }},{{ $y($doneNow) }} 760,{{ $y((float) $c['forecast']) }}" fill="none" stroke="var(--color-dash-8b5cf6)" stroke-width="2.5" stroke-dasharray="2 5"></polyline>
            <circle cx="{{ $x($c['today']) }}" cy="{{ $y($doneNow) }}" r="7" fill="var(--color-dash-10b981)" stroke="var(--color-dash-ffffff)" stroke-width="2"></circle>
            <text x="{{ min(600, $x($c['today']) + 11) }}" y="{{ min(240, $y($doneNow) + 16) }}" font-size="13" font-weight="800" fill="var(--color-dash-065f46)">{{ __('sales::board.r3.today_at', ['value' => $short((string) $doneNow)]) }}</text>
            <text x="750" y="14" font-size="12" fill="var(--color-dash-6d28d9)" text-anchor="end">{{ __('sales::board.r3.forecast_at', ['value' => $short($c['forecast'])]) }}</text>
            @if ($c['target'] !== null)<text x="750" y="{{ max(30, $y((float) $c['target']) + 16) }}" font-size="11" fill="var(--color-dash-64748b)" text-anchor="end">{{ __('sales::board.r3.target_at', ['value' => $short($c['target'])]) }}</text>@endif
            <text x="4" y="266" font-size="11" fill="var(--color-dash-6b7280)">1 {{ $mon }}</text>
            <text x="{{ $x($c['today']) }}" y="266" font-size="11" fill="var(--color-dash-6b7280)" text-anchor="middle">{{ $c['today'] }} {{ $mon }}</text>
            <text x="756" y="266" font-size="11" fill="var(--color-dash-6b7280)" text-anchor="end">{{ $c['days'] }} {{ $mon }}</text>
        </svg>
    </section>

    <section data-sales-pvm style="grid-column: span 2; {{ $card }} gap: 0.5rem; border-top: 0.3125rem solid var(--color-dash-0ea5e9)">
        <div style="display: flex; justify-content: space-between; align-items: baseline; gap: 0.5rem; flex-wrap: wrap">
            <h2 style="margin: 0; font-size: 1.0625rem; font-weight: 800">{{ __('sales::board.r3.pvm') }}</h2>
            <span style="font-size: 0.75rem; color: var(--color-dash-6b7280)">{{ __('sales::board.r3.pvm_note', ['from' => $p['from_label'], 'to' => $p['to_label']]) }}</span>
        </div>
        @if ((float) $p['before'] === 0.0 && (float) $p['after'] === 0.0)
            <span style="font-size: 0.8125rem; color: var(--color-dash-6b7280)">{{ __('sales::board.r3.pvm_none') }}</span>
        @else
        <div style="display: flex; align-items: flex-end; gap: 0.875rem; height: 17.5rem; border-bottom: 1px solid var(--color-dash-e5e7f0); padding: 0 0.375rem">
            @foreach ($bars as $b)
                <div data-pvm-step style="flex: 1 1 0; height: 17.5rem; display: flex; flex-direction: column; justify-content: flex-end; align-items: center; gap: 3px">
                    <span style="font-size: 0.8125rem; font-weight: 800; color: {{ $b['c'] }}; white-space: nowrap">{{ ($b['total'] ?? false) ? $short((string) $b['v']) : (($b['v'] >= 0 ? '+' : '').$short((string) $b['v'])) }}</span>
                    <span style="width: 100%; height: {{ $b['h'] / 16 }}rem; margin-bottom: {{ $b['mb'] / 16 }}rem; background: linear-gradient(180deg, {{ $b['bg'][0] }}, {{ $b['bg'][1] }}); border-radius: 0.625rem"></span>
                </div>
            @endforeach
        </div>
        <div style="display: flex; gap: 0.875rem; padding: 0 0.375rem; font-size: 0.75rem; font-weight: 600; color: var(--color-dash-374151)">@foreach ($bars as $b)<span style="flex: 1 1 0; text-align: center">{{ $b['t'] }}</span>@endforeach</div>
        @endif
    </section>
</div>
