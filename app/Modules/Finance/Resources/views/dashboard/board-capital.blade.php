{{--
    ⭐ অর্থের ড্যাশবোর্ড, সারি 5 — চলতি মূলধন (কেবল মালিকের চাবিতে) আর ঋণ, ঋণসীমা ও শর্ত (মালিকের চূড়ান্ত নমুনা 4h63xhNiy4m9KaJkZZQaEL)।
    ⓘ চলতি মূলধনের সেতু: আগের মাস-শেষ → প্রতিটা ভাগের বদল (দেনা বাড়লে মূলধন কমে) → আজ; বার একটা ভিত্তি থেকে মাপা (সবচেয়ে ছোট ধাপের 90%)।
    ⓘ শর্তের সীমা প্রচলিত ব্যাংক-শর্তে: DSCR 1.25×+, ঋণসীমা ব্যবহার 80%-এর নিচে, D/E 2.0-এর নিচে, চলতি অনুপাত 1.2+।
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

    if ($wc !== null) {
        $p = $wc['previous'];
        $owesNow = bcadd($wc['payable'], $wc['other'], 4);
        $owesThen = bcadd($p['payable'], $p['other'], 4);
        $big = max(abs((float) $wc['capital']), abs((float) $p['capital']), 1.0);
        $unit = $big >= 10000000 ? 10000000 : 100000;
        $fmt = fn (string $v, bool $sign = false) => ($sign ? (bccomp($v, '0', 4) > 0 ? '+' : (bccomp($v, '0', 4) < 0 ? '−' : '')) : (bccomp($v, '0', 4) < 0 ? '−' : '')).number_format(abs((float) $v) / $unit, 2, '.', '');
        $deltas = [
            ['what' => __('finance::board.cap.receivable'), 'd' => bcsub($wc['receivable'], $p['receivable'], 4)],
            ['what' => __('finance::board.cap.stock'), 'd' => bcsub($wc['stock'], $p['stock'], 4)],
            ['what' => __('finance::board.cap.money'), 'd' => bcsub($wc['money'], $p['money'], 4), 'plain' => true],
            ['what' => __('finance::board.cap.advances'), 'd' => bcsub($wc['advances'], $p['advances'], 4), 'plain' => true, 'amber' => true],
            // ⓘ দেনা বাড়লে মূলধন কমে — সেতুতে উল্টো চিহ্ন
            ['what' => __('finance::board.cap.owes'), 'd' => bcsub($owesThen, $owesNow, 4), 'owes' => true, 'raw' => bcsub($owesNow, $owesThen, 4)],
        ];
        $run = [(float) $p['capital']];
        foreach ($deltas as $d) { $run[] = end($run) + (float) $d['d']; }
        $lo = min($run); $hi = max($run);
        $base = $lo > 0 ? $lo * 0.9 : $lo;
        $span = max(1.0, $hi - $base);
        $px = fn (float $v) => max(0, round(($v - $base) / $span * 150));
        $bars = [['t' => \Illuminate\Support\Carbon::parse($p['date'])->day.' '.$monthShort[\Illuminate\Support\Carbon::parse($p['date'])->month], 'v' => $fmt($p['capital']), 'h' => $px($run[0]), 'mb' => 0, 'c' => 'var(--color-dash-94a3b8)']];
        foreach ($deltas as $i => $d) {
            $from = $run[$i]; $to = $run[$i + 1];
            $up = (float) $d['d'] >= 0;
            $raw = $d['raw'] ?? $d['d'];
            $label = ($d['plain'] ?? false) || bccomp($raw, '0', 4) === 0 ? $d['what'] : __(bccomp($raw, '0', 4) >= 0 ? 'finance::board.cap.rose' : 'finance::board.cap.fell', ['what' => $d['what']]);
            $bars[] = ['t' => $label, 'v' => $fmt($d['d'], true), 'h' => max(2, abs($px($to) - $px($from))), 'mb' => min($px($from), $px($to)),
                'c' => ($d['amber'] ?? false) ? 'var(--color-dash-fbbf24)' : ($up ? 'var(--color-dash-34d399)' : 'var(--color-dash-fb7185)')];
        }
        $bars[] = ['t' => __('finance::board.cap.today'), 'v' => $fmt($wc['capital']), 'h' => $px((float) $wc['capital']), 'mb' => 0, 'c' => 'linear-gradient(180deg, var(--color-dash-c4b5fd), var(--color-dash-8b5cf6))'];
        $assets = bcadd(bcadd(bcadd($wc['receivable'], $wc['stock'], 4), $wc['money'], 4), $wc['advances'], 4);
    }

    $lp = ['var(--color-dash-e11d48)', 'var(--color-dash-f97316)', 'var(--color-dash-8b5cf6)', 'var(--color-dash-0ea5e9)', 'var(--color-dash-64748b)'];
    $rows = collect($loans['rows'])->filter(fn ($r) => bccomp($r['left'], '0', 4) > 0)->sortByDesc(fn ($r) => (float) $r['left'])->values();
    $parts = $rows->take(4)->map(fn ($r) => ['n' => $r['name'].' · '.$r['kind'], 'v' => $r['left']])->all();
    $rest = $rows->slice(4)->reduce(fn (string $c, $r) => bcadd($c, $r['left'], 4), '0');
    if (bccomp($rest, '0', 4) > 0) { $parts[] = ['n' => __('finance::charts.others'), 'v' => $rest]; }
    $debt = array_reduce($parts, fn (string $c, array $x) => bcadd($c, $x['v'], 4), '0');
    $C = 2 * M_PI * 62; $acc = 0.0; $ring = [];
    foreach ($parts as $i => $x) {
        $len = bccomp($debt, '0', 4) > 0 ? (float) $x['v'] / (float) $debt * $C : 0;
        $ring[] = $x + ['c' => $lp[$i % count($lp)], 'dash' => round(max($len - 2, 0), 1).' '.round($C - $len + 2, 1), 'off' => round(-$acc, 1)];
        $acc += $len;
    }
    $num = fn (?string $v, int $d) => $v === null ? null : (float) $v;
    $cov = [
        ['t' => __('finance::board.cap.dscr'), 'val' => $num($loans['dscr'], 1), 'show' => $loans['dscr'] === null ? '—' : $loans['dscr'].'×', 'rule' => __('finance::board.cap.dscr_rule'), 'ok' => fn ($v) => $v >= 1.25, 'w' => fn ($v) => $v / 2.5 * 100, 'lim' => 50],
        ['t' => __('finance::board.cap.od'), 'val' => $num($loans['od_pct'], 0), 'show' => $loans['od_pct'] === null ? '—' : $loans['od_pct'].'%', 'rule' => __('finance::board.cap.od_rule'), 'ok' => fn ($v) => $v <= 80, 'w' => fn ($v) => $v, 'lim' => 80],
        ['t' => __('finance::board.cap.de'), 'val' => $num($loans['debt_equity'], 2), 'show' => $loans['debt_equity'] ?? '—', 'rule' => __('finance::board.cap.de_rule'), 'ok' => fn ($v) => $v <= 2.0, 'w' => fn ($v) => $v / 2 * 100, 'lim' => 100],
        ['t' => __('finance::board.cap.current'), 'val' => $num($ratio, 2), 'show' => $ratio ?? '—', 'rule' => __('finance::board.cap.current_rule'), 'ok' => fn ($v) => $v >= 1.2, 'w' => fn ($v) => $v / 3.33 * 100, 'lim' => 36],
    ];
    $peakInst = max(1.0, ...array_map(fn ($m) => (float) $m['instalments'], $instalments ?: [['instalments' => '0']]));
@endphp
<div data-fin-board-capital style="display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 1rem; width: 100%">
    @if ($wc !== null)
        <section data-fin-wc style="grid-column: span 2; border-radius: 1.125rem; padding: 1.125rem 1.25rem; background: linear-gradient(135deg, var(--color-dash-1e1b4b), var(--color-dash-312e81) 55%, var(--color-dash-6d28d9)); color: var(--color-dash-ffffff); display: flex; flex-direction: column; gap: 0.75rem; box-shadow: 0 0.875rem 1.875rem var(--color-dash-67-56-202-a30)">
            <div style="display: flex; justify-content: space-between; align-items: center; gap: 0.5rem; flex-wrap: wrap">
                <div style="display: flex; align-items: center; gap: 0.625rem; flex-wrap: wrap">
                    <h2 style="margin: 0; font-size: 1.0625rem; font-weight: 700; color: var(--color-dash-ffffff)">{{ __('finance::board.cap.wc', ['value' => $short($wc['capital'])]) }}</h2>
                    <span style="font-size: 0.75rem; font-weight: 700; color: var(--color-dash-1e1b4b); background: var(--color-dash-fde68a); border-radius: 999px; padding: 2px 0.625rem">{{ __('finance::board.cap.lock') }}</span>
                </div>
                <a href="{{ route('finance.working_capital') }}" style="font-size: 0.8125rem; font-weight: 700; color: var(--color-dash-c7d2fe)">{{ __('finance::board.cap.full') }}</a>
            </div>
            <div style="display: flex; align-items: flex-end; gap: 0.625rem; height: 11.875rem; background: var(--color-dash-255-255-255-a06); border-radius: 0.875rem; padding: 0.625rem">
                @foreach ($bars as $b)
                    <div data-wc-step style="flex: 1 1 0; height: 10.625rem; display: flex; flex-direction: column; justify-content: flex-end; align-items: center; gap: 0.25rem">
                        <span style="font-size: 0.6875rem; font-weight: 700">{{ $b['v'] }}</span>
                        <span style="width: 100%; height: {{ $b['h'] / 16 }}rem; margin-bottom: {{ $b['mb'] / 16 }}rem; background: {{ $b['c'] }}; border-radius: 0.5rem"></span>
                    </div>
                @endforeach
            </div>
            <div style="display: flex; gap: 0.625rem; font-size: 0.6875rem; opacity: 0.9">@foreach ($bars as $b)<span style="flex: 1 1 0; text-align: center">{{ $b['t'] }}</span>@endforeach</div>
            <span style="font-size: 0.75rem; opacity: 0.85">{{ __('finance::board.cap.parts', ['assets' => $short($assets), 'money' => $fmt($wc['money']), 'receivable' => $fmt($wc['receivable']), 'stock' => $fmt($wc['stock']), 'advances' => $fmt($wc['advances']), 'owes' => $short($owesNow), 'unit' => __($unit === 10000000 ? 'finance::board.cap.unit_crore' : 'finance::board.cap.unit_lakh')]) }}</span>
        </section>
    @endif

    <section data-fin-loans style="grid-column: span {{ $wc !== null ? 2 : 4 }}; {{ $card }} gap: 0.625rem; border-top: 0.3125rem solid var(--color-dash-e11d48)">
        <div style="display: flex; justify-content: space-between; align-items: baseline; gap: 0.5rem">
            <h2 style="margin: 0; font-size: 1rem; font-weight: 700">{{ __('finance::board.cap.loans') }}</h2>
            @if (\Illuminate\Support\Facades\Route::has('accounts.loan.index'))<a href="{{ route('accounts.loan.index') }}" style="font-size: 0.8125rem; font-weight: 600">{{ __('finance::board.cap.all_loans') }}</a>@endif
        </div>
        <div style="display: grid; grid-template-columns: 11.875rem minmax(0, 1fr) minmax(0, 1fr); gap: 1rem; align-items: center">
            <svg role="img" aria-label="{{ __('finance::board.cap.lenders') }}" viewBox="0 0 180 180" style="width: 11.25rem; height: 11.25rem">
                <circle cx="90" cy="90" r="62" fill="none" stroke="var(--color-dash-f1f2f7)" stroke-width="26"></circle>
                <g transform="rotate(-90 90 90)">@foreach ($ring as $d)<circle cx="90" cy="90" r="62" fill="none" stroke="{{ $d['c'] }}" stroke-width="26" stroke-dasharray="{{ $d['dash'] }}" stroke-dashoffset="{{ $d['off'] }}"><title>{{ $d['n'] }} · {{ $short($d['v']) }}</title></circle>@endforeach</g>
                <text x="90" y="86" text-anchor="middle" font-size="20" font-weight="800" fill="var(--color-dash-111827)">{{ $short($debt) }}</text>
                <text x="90" y="106" text-anchor="middle" font-size="11" fill="var(--color-dash-6b7280)">{{ __('finance::board.cap.total') }}</text>
            </svg>
            <div style="display: flex; flex-direction: column; gap: 0.375rem; font-size: 0.75rem; min-width: 0">
                @forelse ($ring as $d)
                    <span style="display: flex; justify-content: space-between; gap: 0.5rem"><span style="display: flex; align-items: center; gap: 0.375rem; min-width: 0"><span style="flex: none; width: 0.625rem; height: 0.625rem; border-radius: 3px; background: {{ $d['c'] }}"></span><span style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap">{{ $d['n'] }}</span></span><b style="white-space: nowrap">{{ $short($d['v']) }}</b></span>
                @empty
                    <span style="color: var(--color-dash-6b7280)">{{ __('finance::board.cap.no_loans') }}</span>
                @endforelse
                <span style="margin-top: 0.25rem; color: var(--color-dash-4b5563)">{{ __('finance::board.cap.interest', ['value' => $short($loans['interest_month'])]) }}@if ($loans['avg_rate'] !== null) · {{ __('finance::board.cap.rate', ['n' => $loans['avg_rate']]) }}@endif</span>
                @if ($loans['next'] !== null)
                    <span style="color: var(--color-dash-4b5563)">{{ __('finance::board.cap.next', ['date' => \Illuminate\Support\Carbon::parse($loans['next']['date'])->day.' '.$monthShort[\Illuminate\Support\Carbon::parse($loans['next']['date'])->month], 'value' => $short($loans['next']['amount'])]) }}</span>
                @endif
            </div>
            <div style="display: flex; flex-direction: column; gap: 0.5rem">
                @foreach ($cov as $c)
                    @php
                        $v = $c['val'];
                        $ink = $v === null ? 'var(--color-dash-64748b)' : (($c['ok'])($v) ? 'var(--color-dash-16a34a)' : 'var(--color-dash-dc2626)');
                        $w = $v === null ? 0 : max(2, min(100, round(($c['w'])($v))));
                    @endphp
                    <div data-covenant style="display: flex; flex-direction: column; gap: 2px; font-size: 0.75rem">
                        <span style="display: flex; justify-content: space-between; gap: 0.375rem"><span>{{ $c['t'] }}</span><b style="color: {{ $ink }}">{{ $c['show'] }}</b></span>
                        <span style="position: relative; height: 0.625rem; border-radius: 999px; background: var(--color-dash-f1f2f7)"><span style="position: absolute; left: 0; top: 0; height: 0.625rem; width: {{ $w }}%; background: {{ $ink }}; border-radius: 999px"></span><span style="position: absolute; left: {{ $c['lim'] }}%; top: -3px; width: 2px; height: 1rem; background: var(--color-dash-111827)"></span></span>
                        <span style="font-size: 0.625rem; color: var(--color-dash-6b7280)">{{ $c['rule'] }}</span>
                    </div>
                @endforeach
            </div>
        </div>
        <div style="display: flex; align-items: flex-end; gap: 0.625rem; height: 4.375rem; border-bottom: 1px solid var(--color-dash-e5e7f0)">
            @foreach ($instalments as $m)
                @php $h = (float) $m['instalments'] > 0 ? max(4, round((float) $m['instalments'] / $peakInst * 52)) : 2; @endphp
                <div style="flex: 1 1 0; display: flex; flex-direction: column; align-items: center; gap: 2px"><span style="font-size: 0.625rem; font-weight: 700">{{ (float) $m['instalments'] > 0 ? $short($m['instalments']) : '' }}</span><span style="width: 70%; height: {{ $h / 16 }}rem; background: {{ $h === max(4, round($peakInst / $peakInst * 52)) && (float) $m['instalments'] > 0 ? 'var(--color-dash-e11d48)' : 'var(--color-dash-fb7185)' }}; border-radius: 0.375rem 0.375rem 0 0"></span></div>
            @endforeach
        </div>
        <div style="display: flex; gap: 0.625rem; font-size: 0.6875rem; color: var(--color-dash-6b7280)">@foreach ($instalments as $m)<span style="flex: 1 1 0; text-align: center">{{ $monthShort[(int) substr($m['month'], 5, 2)] }}</span>@endforeach</div>
    </section>
</div>
