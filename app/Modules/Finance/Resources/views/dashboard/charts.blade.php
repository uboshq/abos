{{--
    ⭐ অর্থের ড্যাশবোর্ডের আট ছোট চার্ট — তহবিলের সারির নিচে, এক সারিতে চারটা (মালিকের অনুমোদিত নমুনা, ৯ নভেম্বর ২০২৬)।
    অঙ্ক [[FinanceCharts]] থেকে; এখানে কেবল আঁকার মাপ (পিক্সেল) — টাকার অঙ্ক bcmath-এ, পর্দায় লাখে।
    ⓘ প্রতিটা দাগে <title> — মাউস রাখলে পুরো অঙ্ক।
--}}
@php
    $bn = app()->getLocale() === 'bn';
    $digits = fn (string $s): string => $bn ? \App\Core\Support\BanglaDigits::of($s) : $s;
    $lakh = fn (string $v): string => (bccomp($v, '0', 4) < 0 ? '−' : '').'৳'.$digits(bcdiv(ltrim($v, '-'), '100000', 1)).' '.__('finance::fund.lakh');
    $taka = fn (string $v): string => '৳'.$digits(\App\Core\Support\Money::format($v, 0));
    $num = fn (string $v): float => (float) $v; // ⓘ কেবল পিক্সেলের মাপ
    $months = __('finance::fund.months');
    $card = 'background: #ffffff; color: #0f172a; border-radius: 16px; padding: 14px 16px; display: flex; flex-direction: column; gap: 8px; box-shadow: 0 1px 2px rgba(15,23,42,0.08); min-width: 0';
    $badge = fn (string $fg, string $bg): string => "font-size: 12px; font-weight: 700; color: {$fg}; background: {$bg}; border-radius: 999px; padding: 1px 8px; white-space: nowrap";
    $head = 'display: flex; justify-content: space-between; align-items: center; gap: 8px';
    $h3 = 'margin: 0; font-size: 14px; font-weight: 700; color: #334155';
    $foot = 'margin: 0; font-size: 12px; color: #475569';
    $c = $charts;
@endphp

<section data-finance-charts style="flex: 1 1 100%; min-width: 0; display: grid; grid-template-columns: repeat(auto-fill, minmax(max(260px, calc((100% - 42px) / 4)), 1fr)); gap: 14px">

    {{-- ১. নগদ প্রবাহ · ৬ মাস --}}
    @php
        $cf = $c['cashflow'];
        $cfMax = max(1.0, ...array_map(fn ($m) => max($num($m['in']), $num($m['out'])), $cf['months'] ?: [['in' => '0', 'out' => '0']]));
        $netUp = bccomp($cf['net'], '0', 4) >= 0;
    @endphp
    <section data-chart="cashflow" style="{{ $card }}">
        <div style="{{ $head }}"><h3 style="{{ $h3 }}">{{ __('finance::charts.cashflow_title') }}</h3>
            <span style="{{ $badge($netUp ? '#047857' : '#b91c1c', $netUp ? '#d1fae5' : '#fee2e2') }}">{{ __('finance::charts.net', ['amount' => ($netUp ? '+' : '').$lakh($cf['net'])]) }}</span></div>
        <svg viewBox="0 0 300 118" width="100%" role="img" aria-label="{{ __('finance::charts.aria_cashflow') }}">
            <line x1="0" y1="100" x2="300" y2="100" stroke="#cbd5e1"></line>
            @foreach ($cf['months'] as $i => $m)
                @php $hi = round($num($m['in']) / $cfMax * 90, 1); $ho = round($num($m['out']) / $cfMax * 90, 1); $x = 8 + $i * 50; @endphp
                <rect x="{{ $x }}" y="{{ 100 - $hi }}" width="18" height="{{ max($hi, 0.5) }}" rx="3" fill="#10b981"><title>{{ $m['month'] }} · {{ __('finance::charts.in') }} {{ $taka($m['in']) }}</title></rect>
                <rect x="{{ $x + 20 }}" y="{{ 100 - $ho }}" width="18" height="{{ max($ho, 0.5) }}" rx="3" fill="#f97316"><title>{{ $m['month'] }} · {{ __('finance::charts.out') }} {{ $taka($m['out']) }}</title></rect>
                <text x="{{ $x + 19 }}" y="114" font-size="10" fill="#475569" text-anchor="middle">{{ $m['month'] }}</text>
            @endforeach
        </svg>
        <p style="{{ $foot }}"><span style="color: #047857; font-weight: 700">■ {{ __('finance::charts.in') }}</span> · <span style="color: #c2410c; font-weight: 700">■ {{ __('finance::charts.out') }}</span> ·
            {{ $cf['out_heavy'] === [] ? __('finance::charts.never_out_heavy') : (count($cf['out_heavy']) <= 2 ? __('finance::charts.out_heavy', ['months' => implode(', ', $cf['out_heavy'])]) : __('finance::charts.out_heavy_many', ['n' => $digits((string) count($cf['out_heavy']))])) }}</p>
    </section>

    {{-- ২. তহবিলের ধারা · ১২ মাস --}}
    @php
        $tr = $c['trend'];
        $vals = array_map(fn ($p) => $num($p['total']), $tr['points']);
        $lo = min($vals); $hiV = max($vals); $span = $hiV - $lo ?: 1.0;
        $pts = [];
        foreach ($vals as $i => $v) {
            $pts[] = round($i * 300 / max(1, count($vals) - 1), 1).','.round(96 - ($v - $lo) / $span * 84, 1);
        }
        $lastPt = explode(',', end($pts));
        $trUp = bccomp($tr['change'], '0', 4) >= 0;
    @endphp
    <section data-chart="trend" style="{{ $card }}">
        <div style="{{ $head }}"><h3 style="{{ $h3 }}">{{ __('finance::charts.trend_title') }}</h3>
            @if ($tr['peak_now'])<span style="{{ $badge('#1d4ed8', '#dbeafe') }}">{{ __('finance::charts.peak_now') }}</span>@endif</div>
        <svg viewBox="0 0 300 110" width="100%" role="img" aria-label="{{ __('finance::charts.aria_trend') }}">
            <path d="M{{ implode(' L', $pts) }} L300,104 L0,104 Z" fill="#3b82f6" fill-opacity="0.15"></path>
            <polyline points="{{ implode(' ', $pts) }}" fill="none" stroke="#2563eb" stroke-width="2"></polyline>
            @foreach ($tr['points'] as $i => $p)
                @php [$px, $py] = explode(',', $pts[$i]); @endphp
                <circle cx="{{ $px }}" cy="{{ $py }}" r="6" fill="transparent"><title>{{ $digits(\App\Core\Support\DateFormat::format($p['date'])) }} · {{ $taka($p['total']) }}</title></circle>
            @endforeach
            <circle cx="{{ min(296, (float) $lastPt[0]) }}" cy="{{ $lastPt[1] }}" r="4" fill="#2563eb" stroke="#ffffff" stroke-width="2"></circle>
        </svg>
        <p style="{{ $foot }}">{{ __('finance::charts.trend_foot', ['amount' => ($trUp ? '+' : '').$lakh($tr['change']), 'n' => $digits((string) $tr['up_months'])]) }}</p>
    </section>

    {{-- ৩. পাওনা --}}
    @if ($c['receivable'] !== null)
        @php
            $rc = $c['receivable'];
            $rColours = ['#10b981', '#eab308', '#f97316', '#ef4444'];
            $rLabels = ['r_current', 'r_30', 'r_60', 'r_90'];
        @endphp
        <section data-chart="receivable" style="{{ $card }}">
            <div style="{{ $head }}"><h3 style="{{ $h3 }}">{{ __('finance::charts.receivable_title', ['amount' => $lakh($rc['total'])]) }}</h3>
                @if ($rc['old_pct'] !== null && $rc['old_pct'] !== '0')<span style="{{ $badge('#b91c1c', '#fee2e2') }}">{{ __('finance::charts.old_badge', ['pct' => $digits($rc['old_pct'])]) }}</span>@endif</div>
            @foreach ($rc['buckets'] as $i => $b)
                @php $w = bccomp($rc['total'], '0', 4) > 0 ? bcdiv(bcmul($b['amount'], '100', 4), $rc['total'], 1) : '0'; @endphp
                <div style="display: flex; align-items: center; gap: 8px; font-size: 12px"><span style="width: 56px; color: #475569">{{ __('finance::charts.'.$rLabels[$i]) }}</span>
                    <div style="flex: 1 1 auto; height: 10px; background: #f1f5f9; border-radius: 999px"><div style="height: 10px; border-radius: 999px; width: {{ $w }}%; background: {{ $rColours[$i] }}"></div></div>
                    <span style="width: 64px; text-align: right; font-weight: 700; font-variant-numeric: tabular-nums">{{ $lakh($b['amount']) }}</span></div>
            @endforeach
            <p style="{{ $foot }}">{{ $rc['dso'] === null ? __('finance::charts.no_days') : __('finance::charts.dso_foot', ['n' => $digits((string) $rc['dso'])]) }}</p>
        </section>
    @endif

    {{-- ৪. দেনা --}}
    @php
        $pc = $c['payable'];
        $pColours = ['#6366f1', '#8b5cf6', '#ec4899', '#ef4444'];
    @endphp
    <section data-chart="payable" style="{{ $card }}">
        <div style="{{ $head }}"><h3 style="{{ $h3 }}">{{ __('finance::charts.payable_title', ['amount' => $lakh($pc['total'])]) }}</h3>
            @if (bccomp($pc['due7'], '0', 4) > 0)<span style="{{ $badge('#6d28d9', '#ede9fe') }}">{{ __('finance::charts.due7_badge', ['amount' => $lakh($pc['due7'])]) }}</span>@endif</div>
        @foreach ($pc['buckets'] as $i => $b)
            @php $w = bccomp($pc['total'], '0', 4) > 0 ? bcdiv(bcmul($b['amount'], '100', 4), $pc['total'], 1) : '0'; @endphp
            <div style="display: flex; align-items: center; gap: 8px; font-size: 12px"><span style="width: 56px; color: #475569">{{ __('finance::charts.p_'.$b['key']) }}</span>
                <div style="flex: 1 1 auto; height: 10px; background: #f1f5f9; border-radius: 999px"><div style="height: 10px; border-radius: 999px; width: {{ $w }}%; background: {{ $pColours[$i] }}"></div></div>
                <span style="width: 64px; text-align: right; font-weight: 700; font-variant-numeric: tabular-nums">{{ $lakh($b['amount']) }}</span></div>
        @endforeach
        <p style="{{ $foot }}">{{ $pc['dpo'] === null ? __('finance::charts.no_days') : __('finance::charts.dpo_foot', ['n' => $digits((string) $pc['dpo'])]) }}</p>
    </section>

    {{-- ৫. খরচ --}}
    @php
        $ex = $c['expense'];
        $eColours = ['#6366f1', '#f97316', '#10b981', '#eab308', '#ec4899'];
        $eInk = ['#4f46e5', '#ea580c', '#059669', '#ca8a04', '#db2777'];
        $circ = 226.2;
        $offset = 0.0;
        $exUp = $ex['change_pct'] !== null && bccomp($ex['change_pct'], '0', 0) > 0;
    @endphp
    <section data-chart="expense" style="{{ $card }}">
        <div style="{{ $head }}"><h3 style="{{ $h3 }}">{{ __('finance::charts.expense_title', ['amount' => $lakh($ex['total'])]) }}</h3>
            @if ($ex['change_pct'] !== null)<span style="{{ $exUp ? $badge('#b45309', '#fef3c7') : $badge('#047857', '#d1fae5') }}">{{ $exUp ? '▲' : '▼' }} {{ $digits(ltrim($ex['change_pct'], '-')) }}%</span>@endif</div>
        @if (bccomp($ex['total'], '0', 4) > 0)
            <div style="display: flex; gap: 12px; align-items: center">
                <svg viewBox="0 0 100 100" width="96" height="96" role="img" aria-label="{{ __('finance::charts.aria_expense') }}" style="flex: none">
                    @foreach ($ex['parts'] as $i => $p)
                        @php $len = round($num($p['amount']) / $num($ex['total']) * $circ, 1); @endphp
                        <circle cx="50" cy="50" r="36" fill="none" stroke="{{ $eColours[$i % 5] }}" stroke-width="16" stroke-dasharray="{{ $len }} {{ $circ }}" stroke-dashoffset="{{ -$offset }}" transform="rotate(-90 50 50)"><title>{{ $p['label'] }} · {{ $taka($p['amount']) }}</title></circle>
                        @php $offset += $len; @endphp
                    @endforeach
                </svg>
                <div style="display: flex; flex-direction: column; gap: 2px; font-size: 12px; min-width: 0">
                    @foreach ($ex['parts'] as $i => $p)
                        <span><b style="color: {{ $eInk[$i % 5] }}">■</b> {{ $p['label'] }} {{ $digits(bcdiv(bcmul($p['amount'], '100', 4), $ex['total'], 0)) }}%</span>
                    @endforeach
                </div>
            </div>
        @else
            <p style="{{ $foot }}">{{ __('finance::charts.nothing') }}</p>
        @endif
    </section>

    {{-- ৬. বাজেট বনাম আসল --}}
    @if ($c['budget'] !== null)
        @php
            $bd = $c['budget'];
            $bMax = max(1.0, ...array_map(fn ($r) => max($num($r['budget']), $num($r['actual'])), $bd['rows'])) * 1.1;
        @endphp
        <section data-chart="budget" style="{{ $card }}">
            <div style="{{ $head }}"><h3 style="{{ $h3 }}">{{ __('finance::charts.budget_title') }}</h3>
                <span style="{{ $bd['over'] > 0 ? $badge('#b91c1c', '#fee2e2') : $badge('#047857', '#d1fae5') }}">{{ $bd['over'] > 0 ? __('finance::charts.over_badge', ['n' => $digits((string) $bd['over'])]) : __('finance::charts.none_over') }}</span></div>
            @foreach ($bd['rows'] as $r)
                <div style="display: flex; align-items: center; gap: 8px; font-size: 12px"><span style="width: 64px; color: #475569; overflow: hidden; text-overflow: ellipsis; white-space: nowrap" title="{{ $r['name'] }}">{{ $r['name'] }}</span>
                    <div style="position: relative; flex: 1 1 auto; height: 10px; background: #f1f5f9; border-radius: 999px">
                        <div style="position: absolute; left: 0; top: 0; bottom: 0; border-radius: 999px; width: {{ round($num($r['actual']) / $bMax * 100, 1) }}%; background: {{ $r['over'] ? '#ef4444' : '#6366f1' }}"></div>
                        <div style="position: absolute; top: -3px; bottom: -3px; width: 2px; background: #0f172a; left: {{ round($num($r['budget']) / $bMax * 100, 1) }}%"></div>
                    </div>
                    <span style="width: 44px; text-align: right; font-weight: 700; color: {{ $r['over'] ? '#b91c1c' : '#0f172a' }}">{{ $r['pct'] === null ? '—' : $digits($r['pct']).'%' }}</span></div>
            @endforeach
            <p style="{{ $foot }}">{{ __('finance::charts.budget_foot') }}</p>
        </section>
    @endif

    {{-- ৭. মূল অনুপাত --}}
    @php
        $ra = $c['ratios'];
        $tone = fn (string $t): string => 'border-radius: 10px; padding: 6px 8px; min-width: 0; background: '.['good' => '#ecfdf5', 'warn' => '#fffbeb', 'info' => '#eef2ff', 'bad' => '#fef2f2'][$t].'; border-left: 3px solid '.['good' => '#10b981', 'warn' => '#f59e0b', 'info' => '#6366f1', 'bad' => '#ef4444'][$t];
        $cccNote = $ra['ccc'] === null || $ra['ccc_before'] === null ? '' : ($ra['ccc'] < $ra['ccc_before'] ? __('finance::charts.faster', ['n' => $digits((string) ($ra['ccc_before'] - $ra['ccc']))])
            : ($ra['ccc'] > $ra['ccc_before'] ? __('finance::charts.slower', ['n' => $digits((string) ($ra['ccc'] - $ra['ccc_before']))]) : __('finance::charts.same')));
        $boxes = [
            ['current', $ra['current'] === null ? '—' : $digits($ra['current']), __('finance::charts.current_note'),
                $ra['current'] === null || bccomp($ra['current'], '1.5', 2) >= 0 ? 'good' : (bccomp($ra['current'], '1', 2) >= 0 ? 'warn' : 'bad')],
            ['quick', $ra['quick'] === null ? '—' : $digits($ra['quick']), $ra['quick'] !== null && bccomp($ra['quick'], '1', 2) < 0 ? __('finance::charts.quick_low') : __('finance::charts.quick_note'),
                $ra['quick'] === null || bccomp($ra['quick'], '1', 2) >= 0 ? 'good' : 'warn'],
            ['dio', $ra['dio'] === null ? '—' : __('finance::charts.days', ['n' => $digits((string) $ra['dio'])]),
                $ra['dio_before'] === null ? '' : __('finance::charts.before', ['n' => $digits((string) $ra['dio_before'])]), 'info'],
            ['ccc', $ra['ccc'] === null ? '—' : __('finance::charts.days', ['n' => $digits((string) $ra['ccc'])]), $cccNote,
                $ra['ccc'] !== null && $ra['ccc_before'] !== null && $ra['ccc'] > $ra['ccc_before'] ? 'warn' : 'good'],
        ];
    @endphp
    <section data-chart="ratios" style="{{ $card }}">
        <h3 style="{{ $h3 }}">{{ __('finance::charts.ratios_title') }}</h3>
        <div style="display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 6px">
            @foreach ($boxes as [$key, $value, $note, $t])
                <div data-ratio="{{ $key }}" style="{{ $tone($t) }}"><p style="margin: 0; font-size: 11px; font-weight: 600; color: #475569">{{ __('finance::charts.'.$key) }}</p>
                    <p style="margin: 0; font-size: 18px; font-weight: 700">{{ $value }}</p><p style="margin: 0; font-size: 11px; color: #475569">{{ $note }}</p></div>
            @endforeach
        </div>
    </section>

    {{-- ৮. ঋণ ও আমানত · ৬ মাস --}}
    @php
        $ld = $c['loans'];
        $lMax = max(1.0, ...array_map(fn ($m) => max($num($m['instalments']), $num($m['deposits'])), $ld['months']));
        $monthName = fn (string $ym): string => $months[(int) substr($ym, 5, 2)] ?? $ym;
    @endphp
    <section data-chart="loans" style="{{ $card }}">
        <div style="{{ $head }}"><h3 style="{{ $h3 }}">{{ __('finance::charts.loans_title') }}</h3>
            @if ($ld['big'] !== null)<span style="{{ $badge('#b91c1c', '#fee2e2') }}">{{ __('finance::charts.big_badge', ['month' => $monthName($ld['big'])]) }}</span>@endif</div>
        <svg viewBox="0 0 300 110" width="100%" role="img" aria-label="{{ __('finance::charts.aria_loans') }}">
            <line x1="0" y1="52" x2="300" y2="52" stroke="#94a3b8"></line>
            @foreach ($ld['months'] as $i => $m)
                @php $hi = round($num($m['instalments']) / $lMax * 42, 1); $hd = round($num($m['deposits']) / $lMax * 42, 1); $x = 14 + $i * 50; @endphp
                @if ($hi > 0)<rect x="{{ $x }}" y="{{ 52 - $hi }}" width="22" height="{{ $hi }}" rx="3" fill="#ef4444"><title>{{ $monthName($m['month']) }} · {{ __('finance::charts.instalment') }} {{ $taka($m['instalments']) }}</title></rect>@endif
                @if ($hd > 0)<rect x="{{ $x }}" y="52" width="22" height="{{ $hd }}" rx="3" fill="#10b981"><title>{{ $monthName($m['month']) }} · {{ __('finance::charts.deposit_back') }} {{ $taka($m['deposits']) }}</title></rect>@endif
                <text x="{{ $x + 11 }}" y="106" font-size="10" fill="#475569" text-anchor="middle">{{ $monthName($m['month']) }}</text>
            @endforeach
        </svg>
        <p style="{{ $foot }}"><span style="color: #dc2626; font-weight: 700">■ {{ __('finance::charts.instalment') }}</span> · <span style="color: #059669; font-weight: 700">■ {{ __('finance::charts.deposit_back') }}</span></p>
    </section>
</section>
