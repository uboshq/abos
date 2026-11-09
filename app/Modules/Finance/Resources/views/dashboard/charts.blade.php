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
    // ⓘ মাপও bcmath-এ — বড়টা, ছোটটা, আর অঙ্ক থেকে পিক্সেল (এক ঘর দশমিক)
    $big = fn (string $a, string $b): string => bccomp($a, $b, 4) >= 0 ? $a : $b;
    $small = fn (string $a, string $b): string => bccomp($a, $b, 4) <= 0 ? $a : $b;
    $scale = fn (string $v, string $max, string $height): string => bccomp($max, '0', 4) > 0 ? bcdiv(bcmul($v, $height, 4), $max, 1) : '0';
    $months = __('finance::fund.months');
    $card = 'background: var(--color-fin-ffffff); color: var(--color-fin-0f172a); border-radius: 16px; padding: 14px 16px; display: flex; flex-direction: column; gap: 8px; box-shadow: 0 1px 2px var(--color-fin-15-23-42-a8); min-width: 0';
    $badge = fn (string $fg, string $bg): string => "font-size: 12px; font-weight: 700; color: {$fg}; background: {$bg}; border-radius: 999px; padding: 1px 8px; white-space: nowrap";
    $head = 'display: flex; justify-content: space-between; align-items: center; gap: 8px';
    $h3 = 'margin: 0; font-size: 14px; font-weight: 700; color: var(--color-fin-334155)';
    $foot = 'margin: 0; font-size: 12px; color: var(--color-fin-475569)';
    $c = $charts;
@endphp

<section data-finance-charts style="flex: 1 1 100%; min-width: 0; display: grid; grid-template-columns: repeat(auto-fill, minmax(max(260px, calc((100% - 42px) / 4)), 1fr)); gap: 14px">

    {{-- ১. নগদ প্রবাহ · ৬ মাস --}}
    @php
        $cf = $c['cashflow'];
        $cfMax = array_reduce($cf['months'], fn (string $c, array $m) => $big($c, $big($m['in'], $m['out'])), '1');
        $netUp = bccomp($cf['net'], '0', 4) >= 0;
    @endphp
    <section data-chart="cashflow" style="{{ $card }}">
        <div style="{{ $head }}"><h3 style="{{ $h3 }}">{{ __('finance::charts.cashflow_title') }}</h3>
            <span style="{{ $badge($netUp ? 'var(--color-fin-047857)' : 'var(--color-fin-b91c1c)', $netUp ? 'var(--color-fin-d1fae5)' : 'var(--color-fin-fee2e2)') }}">{{ __('finance::charts.net', ['amount' => ($netUp ? '+' : '').$lakh($cf['net'])]) }}</span></div>
        <svg viewBox="0 0 300 118" width="100%" role="img" aria-label="{{ __('finance::charts.aria_cashflow') }}">
            <line x1="0" y1="100" x2="300" y2="100" style="stroke: var(--color-fin-cbd5e1)"></line>
            @foreach ($cf['months'] as $i => $m)
                @php $hi = $scale($m['in'], $cfMax, '90'); $ho = $scale($m['out'], $cfMax, '90'); $x = 8 + $i * 50; @endphp
                <rect x="{{ $x }}" y="{{ bcsub('100', $hi, 1) }}" width="18" height="{{ $big($hi, '0.5') }}" rx="3" style="fill: var(--color-fin-10b981)"><title>{{ $m['month'] }} · {{ __('finance::charts.in') }} {{ $taka($m['in']) }}</title></rect>
                <rect x="{{ $x + 20 }}" y="{{ bcsub('100', $ho, 1) }}" width="18" height="{{ $big($ho, '0.5') }}" rx="3" style="fill: var(--color-fin-f97316)"><title>{{ $m['month'] }} · {{ __('finance::charts.out') }} {{ $taka($m['out']) }}</title></rect>
                <text x="{{ $x + 19 }}" y="114" font-size="10" text-anchor="middle" style="fill: var(--color-fin-475569)">{{ $m['month'] }}</text>
            @endforeach
        </svg>
        <p style="{{ $foot }}"><span style="color: var(--color-fin-047857); font-weight: 700">■ {{ __('finance::charts.in') }}</span> · <span style="color: var(--color-fin-c2410c); font-weight: 700">■ {{ __('finance::charts.out') }}</span> ·
            {{ $cf['out_heavy'] === [] ? __('finance::charts.never_out_heavy') : (count($cf['out_heavy']) <= 2 ? __('finance::charts.out_heavy', ['months' => implode(', ', $cf['out_heavy'])]) : __('finance::charts.out_heavy_many', ['n' => $digits((string) count($cf['out_heavy']))])) }}</p>
    </section>

    {{-- ২. তহবিলের ধারা · ১২ মাস --}}
    @php
        $tr = $c['trend'];
        $vals = array_column($tr['points'], 'total');
        $lo = array_reduce($vals, $small, $vals[0]);
        $hiV = array_reduce($vals, $big, $vals[0]);
        $span = bccomp($hiV, $lo, 4) > 0 ? bcsub($hiV, $lo, 4) : '1';
        $steps = (string) max(1, count($vals) - 1);
        $pts = [];
        foreach ($vals as $i => $v) {
            $pts[] = bcdiv((string) ($i * 300), $steps, 1).','.bcsub('96', $scale(bcsub($v, $lo, 4), $span, '84'), 1);
        }
        $lastPt = explode(',', end($pts));
        $trUp = bccomp($tr['change'], '0', 4) >= 0;
    @endphp
    <section data-chart="trend" style="{{ $card }}">
        <div style="{{ $head }}"><h3 style="{{ $h3 }}">{{ __('finance::charts.trend_title') }}</h3>
            @if ($tr['peak_now'])<span style="{{ $badge('var(--color-fin-1d4ed8)', 'var(--color-fin-dbeafe)') }}">{{ __('finance::charts.peak_now') }}</span>@endif</div>
        <svg viewBox="0 0 300 110" width="100%" role="img" aria-label="{{ __('finance::charts.aria_trend') }}">
            <path d="M{{ implode(' L', $pts) }} L300,104 L0,104 Z" fill-opacity="0.15" style="fill: var(--color-fin-3b82f6)"></path>
            <polyline points="{{ implode(' ', $pts) }}" fill="none" stroke-width="2" style="stroke: var(--color-fin-2563eb)"></polyline>
            @foreach ($tr['points'] as $i => $p)
                @php [$px, $py] = explode(',', $pts[$i]); @endphp
                <circle cx="{{ $px }}" cy="{{ $py }}" r="6" fill="transparent"><title>{{ $digits(\App\Core\Support\DateFormat::format($p['date'])) }} · {{ $taka($p['total']) }}</title></circle>
            @endforeach
            <circle cx="{{ $small($lastPt[0], '296') }}" cy="{{ $lastPt[1] }}" r="4" stroke-width="2" style="fill: var(--color-fin-2563eb); stroke: var(--color-fin-ffffff)"></circle>
        </svg>
        <p style="{{ $foot }}">{{ __('finance::charts.trend_foot', ['amount' => ($trUp ? '+' : '').$lakh($tr['change']), 'n' => $digits((string) $tr['up_months'])]) }}</p>
    </section>

    {{-- ৩. পাওনা --}}
    @if ($c['receivable'] !== null)
        @php
            $rc = $c['receivable'];
            $rColours = ['var(--color-fin-10b981)', 'var(--color-fin-eab308)', 'var(--color-fin-f97316)', 'var(--color-fin-ef4444)'];
            $rLabels = ['r_current', 'r_30', 'r_60', 'r_90'];
        @endphp
        <section data-chart="receivable" style="{{ $card }}">
            <div style="{{ $head }}"><h3 style="{{ $h3 }}">{{ __('finance::charts.receivable_title', ['amount' => $lakh($rc['total'])]) }}</h3>
                @if ($rc['old_pct'] !== null && $rc['old_pct'] !== '0')<span style="{{ $badge('var(--color-fin-b91c1c)', 'var(--color-fin-fee2e2)') }}">{{ __('finance::charts.old_badge', ['pct' => $digits($rc['old_pct'])]) }}</span>@endif</div>
            @foreach ($rc['buckets'] as $i => $b)
                @php $w = bccomp($rc['total'], '0', 4) > 0 ? bcdiv(bcmul($b['amount'], '100', 4), $rc['total'], 1) : '0'; @endphp
                <div style="display: flex; align-items: center; gap: 8px; font-size: 12px"><span style="width: 56px; color: var(--color-fin-475569)">{{ __('finance::charts.'.$rLabels[$i]) }}</span>
                    <div style="flex: 1 1 auto; height: 10px; background: var(--color-fin-f1f5f9); border-radius: 999px"><div style="height: 10px; border-radius: 999px; width: {{ $w }}%; background: {{ $rColours[$i] }}"></div></div>
                    <span style="width: 64px; text-align: right; font-weight: 700; font-variant-numeric: tabular-nums">{{ $lakh($b['amount']) }}</span></div>
            @endforeach
            <p style="{{ $foot }}">{{ $rc['dso'] === null ? __('finance::charts.no_days') : __('finance::charts.dso_foot', ['n' => $digits((string) $rc['dso'])]) }}</p>
        </section>
    @endif

    {{-- ৪. দেনা --}}
    @php
        $pc = $c['payable'];
        $pColours = ['var(--color-fin-6366f1)', 'var(--color-fin-8b5cf6)', 'var(--color-fin-ec4899)', 'var(--color-fin-ef4444)'];
    @endphp
    <section data-chart="payable" style="{{ $card }}">
        <div style="{{ $head }}"><h3 style="{{ $h3 }}">{{ __('finance::charts.payable_title', ['amount' => $lakh($pc['total'])]) }}</h3>
            @if (bccomp($pc['due7'], '0', 4) > 0)<span style="{{ $badge('var(--color-fin-6d28d9)', 'var(--color-fin-ede9fe)') }}">{{ __('finance::charts.due7_badge', ['amount' => $lakh($pc['due7'])]) }}</span>@endif</div>
        @foreach ($pc['buckets'] as $i => $b)
            @php $w = bccomp($pc['total'], '0', 4) > 0 ? bcdiv(bcmul($b['amount'], '100', 4), $pc['total'], 1) : '0'; @endphp
            <div style="display: flex; align-items: center; gap: 8px; font-size: 12px"><span style="width: 56px; color: var(--color-fin-475569)">{{ __('finance::charts.p_'.$b['key']) }}</span>
                <div style="flex: 1 1 auto; height: 10px; background: var(--color-fin-f1f5f9); border-radius: 999px"><div style="height: 10px; border-radius: 999px; width: {{ $w }}%; background: {{ $pColours[$i] }}"></div></div>
                <span style="width: 64px; text-align: right; font-weight: 700; font-variant-numeric: tabular-nums">{{ $lakh($b['amount']) }}</span></div>
        @endforeach
        <p style="{{ $foot }}">{{ $pc['dpo'] === null ? __('finance::charts.no_days') : __('finance::charts.dpo_foot', ['n' => $digits((string) $pc['dpo'])]) }}</p>
    </section>

    {{-- ৫. খরচ --}}
    @php
        $ex = $c['expense'];
        $eColours = ['var(--color-fin-6366f1)', 'var(--color-fin-f97316)', 'var(--color-fin-10b981)', 'var(--color-fin-eab308)', 'var(--color-fin-ec4899)'];
        $eInk = ['var(--color-fin-4f46e5)', 'var(--color-fin-ea580c)', 'var(--color-fin-059669)', 'var(--color-fin-ca8a04)', 'var(--color-fin-db2777)'];
        $circ = '226.2';
        $offset = '0';
        $exUp = $ex['change_pct'] !== null && bccomp($ex['change_pct'], '0', 0) > 0;
    @endphp
    <section data-chart="expense" style="{{ $card }}">
        <div style="{{ $head }}"><h3 style="{{ $h3 }}">{{ __('finance::charts.expense_title', ['amount' => $lakh($ex['total'])]) }}</h3>
            @if ($ex['change_pct'] !== null)<span style="{{ $exUp ? $badge('var(--color-fin-b45309)', 'var(--color-fin-fef3c7)') : $badge('var(--color-fin-047857)', 'var(--color-fin-d1fae5)') }}">{{ $exUp ? '▲' : '▼' }} {{ $digits(ltrim($ex['change_pct'], '-')) }}%</span>@endif</div>
        @if (bccomp($ex['total'], '0', 4) > 0)
            <div style="display: flex; gap: 12px; align-items: center">
                <svg viewBox="0 0 100 100" width="96" height="96" role="img" aria-label="{{ __('finance::charts.aria_expense') }}" style="flex: none">
                    @foreach ($ex['parts'] as $i => $p)
                        @php $len = $scale($p['amount'], $ex['total'], $circ); @endphp
                        <circle cx="50" cy="50" r="36" fill="none" stroke-width="16" stroke-dasharray="{{ $len }} {{ $circ }}" stroke-dashoffset="{{ bcmul($offset, '-1', 1) }}" transform="rotate(-90 50 50)" style="stroke: {{ $eColours[$i % 5] }}"><title>{{ $p['label'] }} · {{ $taka($p['amount']) }}</title></circle>
                        @php $offset = bcadd($offset, $len, 1); @endphp
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
            $bMax = bcmul(array_reduce($bd['rows'], fn (string $c, array $r) => $big($c, $big($r['budget'], $r['actual'])), '1'), '1.1', 4);
        @endphp
        <section data-chart="budget" style="{{ $card }}">
            <div style="{{ $head }}"><h3 style="{{ $h3 }}">{{ __('finance::charts.budget_title') }}</h3>
                <span style="{{ $bd['over'] > 0 ? $badge('var(--color-fin-b91c1c)', 'var(--color-fin-fee2e2)') : $badge('var(--color-fin-047857)', 'var(--color-fin-d1fae5)') }}">{{ $bd['over'] > 0 ? __('finance::charts.over_badge', ['n' => $digits((string) $bd['over'])]) : __('finance::charts.none_over') }}</span></div>
            @foreach ($bd['rows'] as $r)
                <div style="display: flex; align-items: center; gap: 8px; font-size: 12px"><span style="width: 64px; color: var(--color-fin-475569); overflow: hidden; text-overflow: ellipsis; white-space: nowrap" title="{{ $r['name'] }}">{{ $r['name'] }}</span>
                    <div style="position: relative; flex: 1 1 auto; height: 10px; background: var(--color-fin-f1f5f9); border-radius: 999px">
                        <div style="position: absolute; left: 0; top: 0; bottom: 0; border-radius: 999px; width: {{ $scale($r['actual'], $bMax, '100') }}%; background: {{ $r['over'] ? 'var(--color-fin-ef4444)' : 'var(--color-fin-6366f1)' }}"></div>
                        <div style="position: absolute; top: -3px; bottom: -3px; width: 2px; background: var(--color-fin-0f172a); left: {{ $scale($r['budget'], $bMax, '100') }}%"></div>
                    </div>
                    <span style="width: 44px; text-align: right; font-weight: 700; color: {{ $r['over'] ? 'var(--color-fin-b91c1c)' : 'var(--color-fin-0f172a)' }}">{{ $r['pct'] === null ? '—' : $digits($r['pct']).'%' }}</span></div>
            @endforeach
            <p style="{{ $foot }}">{{ __('finance::charts.budget_foot') }}</p>
        </section>
    @endif

    {{-- ৭. মূল অনুপাত --}}
    @php
        $ra = $c['ratios'];
        $tone = fn (string $t): string => 'border-radius: 10px; padding: 6px 8px; min-width: 0; background: '.['good' => 'var(--color-fin-ecfdf5)', 'warn' => 'var(--color-fin-fffbeb)', 'info' => 'var(--color-fin-eef2ff)', 'bad' => 'var(--color-fin-fef2f2)'][$t].'; border-left: 3px solid '.['good' => 'var(--color-fin-10b981)', 'warn' => 'var(--color-fin-f59e0b)', 'info' => 'var(--color-fin-6366f1)', 'bad' => 'var(--color-fin-ef4444)'][$t];
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
                <div data-ratio="{{ $key }}" style="{{ $tone($t) }}"><p style="margin: 0; font-size: 11px; font-weight: 600; color: var(--color-fin-475569)">{{ __('finance::charts.'.$key) }}</p>
                    <p style="margin: 0; font-size: 18px; font-weight: 700">{{ $value }}</p><p style="margin: 0; font-size: 11px; color: var(--color-fin-475569)">{{ $note }}</p></div>
            @endforeach
        </div>
    </section>

    {{-- ৮. ঋণ ও আমানত · ৬ মাস --}}
    @php
        $ld = $c['loans'];
        $lMax = array_reduce($ld['months'], fn (string $c, array $m) => $big($c, $big($m['instalments'], $m['deposits'])), '1');
        $monthName = fn (string $ym): string => $months[(int) substr($ym, 5, 2)] ?? $ym;
    @endphp
    <section data-chart="loans" style="{{ $card }}">
        <div style="{{ $head }}"><h3 style="{{ $h3 }}">{{ __('finance::charts.loans_title') }}</h3>
            @if ($ld['big'] !== null)<span style="{{ $badge('var(--color-fin-b91c1c)', 'var(--color-fin-fee2e2)') }}">{{ __('finance::charts.big_badge', ['month' => $monthName($ld['big'])]) }}</span>@endif</div>
        <svg viewBox="0 0 300 110" width="100%" role="img" aria-label="{{ __('finance::charts.aria_loans') }}">
            <line x1="0" y1="52" x2="300" y2="52" style="stroke: var(--color-fin-94a3b8)"></line>
            @foreach ($ld['months'] as $i => $m)
                @php $hi = $scale($m['instalments'], $lMax, '42'); $hd = $scale($m['deposits'], $lMax, '42'); $x = 14 + $i * 50; @endphp
                @if (bccomp($hi, '0', 1) > 0)<rect x="{{ $x }}" y="{{ bcsub('52', $hi, 1) }}" width="22" height="{{ $hi }}" rx="3" style="fill: var(--color-fin-ef4444)"><title>{{ $monthName($m['month']) }} · {{ __('finance::charts.instalment') }} {{ $taka($m['instalments']) }}</title></rect>@endif
                @if (bccomp($hd, '0', 1) > 0)<rect x="{{ $x }}" y="52" width="22" height="{{ $hd }}" rx="3" style="fill: var(--color-fin-10b981)"><title>{{ $monthName($m['month']) }} · {{ __('finance::charts.deposit_back') }} {{ $taka($m['deposits']) }}</title></rect>@endif
                <text x="{{ $x + 11 }}" y="106" font-size="10" text-anchor="middle" style="fill: var(--color-fin-475569)">{{ $monthName($m['month']) }}</text>
            @endforeach
        </svg>
        <p style="{{ $foot }}"><span style="color: var(--color-fin-dc2626); font-weight: 700">■ {{ __('finance::charts.instalment') }}</span> · <span style="color: var(--color-fin-059669); font-weight: 700">■ {{ __('finance::charts.deposit_back') }}</span></p>
    </section>
</section>
